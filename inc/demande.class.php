<?php
/**
 * PluginPrintgestionDemande — demande d'envoi de consommables (en-tête).
 *
 * Une demande regroupe, pour UN client (entité GLPI de l'imprimante) et UN site de
 * livraison (lieu racine de l'imprimante), les cartouches à envoyer : une ligne par
 * imprimante et toner (PluginPrintgestionDemandeline).
 *
 * Statuts : proposée → validée → exportée → expédiée → livrée → posée, plus annulée.
 * Aucune suppression définitive : l'annulation est un statut. Les écritures passent par
 * CommonDBTM::add() / update() : historique GLPI natif (onglet Historique), écritures
 * des lignes comprises (journalisées sur la demande).
 *
 * Anti-double-envoi : une ligne proposée ou validée bloque son emplacement comme un
 * envoi en cours (PluginPrintgestionGuard). La base garantit au plus une ligne ouverte
 * par imprimante et toner, et au plus une demande proposée par client et site
 * (schéma 1.3.1).
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionDemande extends CommonDBTM implements \Glpi\Search\DefaultSearchRequestInterface {

    static $rightname = 'plugin_printgestion_validation';

    public $dohistory = true;

    const STATUS_PROPOSED  = 'proposed';
    const STATUS_VALIDATED = 'validated';
    const STATUS_EXPORTED  = 'exported';
    const STATUS_SHIPPED   = 'shipped';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_INSTALLED = 'installed';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Statuts OUVERTS d'une ligne : proposée ou validée, pas encore exportée. Une ligne
     * ouverte porte le verrou anti-double-envoi de son emplacement ; à l'export,
     * l'expédition créée prend le relais.
     */
    const OPEN_STATUSES = ['proposed', 'validated'];

    const DELIVERY_DIRECT     = 'direct';
    const DELIVERY_TECHNICIAN = 'technician';

    /** Quantité maximale d'une ligne (saisie de validation). */
    const MAX_QUANTITY = 99;

    /** Parents des lieux déjà lus : id => locations_id du parent, null si lieu introuvable. */
    private static array $location_parents = [];

    static function getTypeName($nb = 0) {
        return _n('Demande d\'envoi', 'Demandes d\'envoi', $nb, 'printgestion');
    }

    static function getIcon() {
        return 'ti ti-clipboard-check';
    }

    // ── Droits ────────────────────────────────────────────────────────────────

    /** Consultation : droit de validation, ou lecture des alertes toner (file visible sans pouvoir valider). */
    public static function canView(): bool {
        return Session::haveRight(self::$rightname, READ)
            || Session::haveRight('plugin_printgestion_dashboard', READ);
    }

    /** Pas de création manuelle : les demandes sont proposées par la tâche automatique. */
    public static function canCreate(): bool {
        return false;
    }

    /** Modifier, valider, annuler. */
    public static function canUpdate(): bool {
        return Session::haveRight(self::$rightname, UPDATE);
    }

    /** Aucune suppression : une demande s'annule. */
    public static function canDelete(): bool {
        return false;
    }

    public static function canPurge(): bool {
        return false;
    }

    public function pre_deleteItem() {
        Session::addMessageAfterRedirect(
            __('Une demande d\'envoi ne se supprime pas : annulez-la.', 'printgestion'),
            false,
            ERROR
        );
        return false;
    }

    /**
     * Actions de masse génériques interdites : un changement de statut ou de champ ne
     * passe jamais par la modification en masse, qui contournerait les contrôles.
     */
    public function getForbiddenStandardMassiveAction() {
        return array_merge(parent::getForbiddenStandardMassiveAction(), [
            'update', 'clone', 'delete', 'purge', 'restore', 'add_transfer_list', 'amend_comment', 'add_note',
        ]);
    }

    /** Action de masse « Valider » : mêmes contrôles que la fiche, demande par demande. */
    function getSpecificMassiveActions($checkitem = null) {
        $actions = parent::getSpecificMassiveActions($checkitem);
        if (self::canUpdate()) {
            $actions[self::class . MassiveAction::CLASS_ACTION_SEPARATOR . 'pg_validate']
                = "<i class='ti ti-check me-1'></i>" . __('Valider', 'printgestion');
        }
        return $actions;
    }

    static function showMassiveActionsSubForm(MassiveAction $ma) {
        if ($ma->getAction() === 'pg_validate') {
            echo "<p class='text-muted small'>"
                . htmlspecialchars(__('Chaque demande proposée est contrôlée à l\'instant (référence, contrat, prix, verrous) : une demande avec une ligne bloquante n\'est pas validée et le motif est affiché.', 'printgestion'), ENT_QUOTES, 'UTF-8')
                . "</p>";
            echo Html::submit(__('Valider', 'printgestion'), ['name' => 'massiveaction', 'class' => 'btn btn-success']);
            return true;
        }
        return parent::showMassiveActionsSubForm($ma);
    }

    static function processMassiveActionsForOneItemtype(MassiveAction $ma, CommonDBTM $item, array $ids) {
        foreach ($ids as $id) {
            if ($ma->getAction() !== 'pg_validate' || !$item->getFromDB($id)) {
                $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
                continue;
            }
            // Droit de validation et entité de la demande, à chaque ligne.
            if (!$item->can($id, UPDATE)) {
                $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_NORIGHT);
                continue;
            }
            $result = $item->validateDemande();
            if ($result['ok']) {
                $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_OK);
            } else {
                $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
            }
            foreach (array_merge($result['errors'], $result['warnings']) as $message) {
                $ma->addMessage(htmlspecialchars(sprintf(__('Demande #%1$d — %2$s', 'printgestion'), $id, $message), ENT_QUOTES, 'UTF-8'));
            }
        }
    }

    // ── URLs, onglets ─────────────────────────────────────────────────────────

    static function getFormURL($full = true) {
        return ($full ? PLUGIN_PRINTGESTION_WEBDIR : PLUGIN_PRINTGESTION_NOTFULL_WEBDIR) . '/front/demande.form.php';
    }

    static function getSearchURL($full = true) {
        return ($full ? PLUGIN_PRINTGESTION_WEBDIR : PLUGIN_PRINTGESTION_NOTFULL_WEBDIR) . '/front/demande.php';
    }

    function defineTabs($options = []) {
        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addStandardTab(Document_Item::class, $ong, $options); // fichiers Gesconso archivés
        $this->addStandardTab(Log::class, $ong, $options);
        return $ong;
    }

    // ── Libellés ──────────────────────────────────────────────────────────────

    public static function getStatusLabels(): array {
        return [
            self::STATUS_PROPOSED  => __('Proposée', 'printgestion'),
            self::STATUS_VALIDATED => __('Validée', 'printgestion'),
            self::STATUS_EXPORTED  => __('Exportée', 'printgestion'),
            self::STATUS_SHIPPED   => __('Expédiée', 'printgestion'),
            self::STATUS_DELIVERED => __('Livrée', 'printgestion'),
            self::STATUS_INSTALLED => __('Posée', 'printgestion'),
            self::STATUS_CANCELLED => __('Annulée', 'printgestion'),
        ];
    }

    public static function getDeliveryModeLabels(): array {
        return [
            self::DELIVERY_DIRECT     => __('Envoi direct au client', 'printgestion'),
            self::DELIVERY_TECHNICIAN => __('Remise par un technicien', 'printgestion'),
        ];
    }

    public static function getStatusBadge(string $statut): string {
        $classes = [
            self::STATUS_PROPOSED  => 'bg-orange text-orange-fg',
            self::STATUS_VALIDATED => 'bg-blue text-blue-fg',
            self::STATUS_EXPORTED  => 'bg-azure text-azure-fg',
            self::STATUS_SHIPPED   => 'bg-primary text-primary-fg',
            self::STATUS_DELIVERED => 'bg-green text-green-fg',
            self::STATUS_INSTALLED => 'bg-teal text-teal-fg',
            self::STATUS_CANCELLED => 'bg-light text-muted border',
        ];
        $label = self::getStatusLabels()[$statut] ?? $statut;
        return "<span class='badge " . ($classes[$statut] ?? 'bg-secondary text-secondary-fg') . "'>"
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    // ── Moteur de recherche natif ─────────────────────────────────────────────

    /** Liste par défaut : les demandes proposées, à valider, les plus anciennes d'abord. */
    public static function getDefaultSearchRequest(): array {
        return [
            'criteria' => [
                ['field' => 3, 'searchtype' => 'equals', 'value' => self::STATUS_PROPOSED],
            ],
            'sort'  => 121,
            'order' => 'ASC',
        ];
    }

    /**
     * Options de recherche. Aucune n'est modifiable en masse : les changements passent
     * par la fiche ou l'action « Valider », qui appliquent les contrôles. Les champs
     * modifiables ont tous une option : c'est ce qui les rend visibles dans l'historique.
     */
    public function rawSearchOptions() {
        $table = self::getTable();
        $tab   = [];

        $tab[] = ['id' => 'common', 'name' => self::getTypeName(1)];

        $tab[] = [
            'id'            => '1',
            'table'         => $table,
            'field'         => 'name',
            'name'          => __('Nom', 'printgestion'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '2',
            'table'         => $table,
            'field'         => 'id',
            'name'          => __('ID'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '3',
            'table'         => $table,
            'field'         => 'statut',
            'name'          => __('Statut', 'printgestion'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '4',
            'table'         => $table,
            'field'         => 'delivery_mode',
            'name'          => __('Mode de livraison', 'printgestion'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '5',
            'table'         => $table,
            'field'         => 'contact',
            'name'          => __('Contact de livraison', 'printgestion'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '6',
            'table'         => $table,
            'field'         => 'delivery_comment',
            'name'          => __('Commentaire de livraison', 'printgestion'),
            'datatype'      => 'text',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '7',
            'table'         => 'glpi_locations',
            'field'         => 'completename',
            'name'          => __('Site de livraison', 'printgestion'),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '8',
            'table'         => 'glpi_users',
            'field'         => 'name',
            'linkfield'     => 'users_id_validate',
            'name'          => __('Validée par', 'printgestion'),
            'datatype'      => 'dropdown',
            'right'         => 'all',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '9',
            'table'         => $table,
            'field'         => 'date_validate',
            'name'          => __('Date de validation', 'printgestion'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '10',
            'table'         => 'glpi_users',
            'field'         => 'name',
            'linkfield'     => 'users_id_cancel',
            'name'          => __('Annulée par', 'printgestion'),
            'datatype'      => 'dropdown',
            'right'         => 'all',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '11',
            'table'         => $table,
            'field'         => 'date_cancel',
            'name'          => __('Date d\'annulation', 'printgestion'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '12',
            'table'         => $table,
            'field'         => 'cancel_reason',
            'name'          => __('Motif d\'annulation', 'printgestion'),
            'datatype'      => 'text',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '13',
            'table'         => PluginPrintgestionDemandeline::getTable(),
            'field'         => 'id',
            'name'          => __('Nombre de lignes', 'printgestion'),
            'datatype'      => 'count',
            'forcegroupby'  => true,
            'usehaving'     => true,
            'massiveaction' => false,
            'joinparams'    => ['jointype' => 'child'],
        ];
        $tab[] = [
            'id'            => '19',
            'table'         => $table,
            'field'         => 'date_mod',
            'name'          => __('Last update'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '121',
            'table'         => $table,
            'field'         => 'date_creation',
            'name'          => __('Creation date'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '80',
            'table'         => 'glpi_entities',
            'field'         => 'completename',
            'name'          => Entity::getTypeName(1),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
        ];

        return $tab;
    }

    static function getSpecificValueToDisplay($field, $values, array $options = []) {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        switch ($field) {
            case 'statut':
                return self::getStatusBadge((string) ($values[$field] ?? ''));
            case 'delivery_mode':
                $mode = (string) ($values[$field] ?? '');
                return htmlspecialchars(self::getDeliveryModeLabels()[$mode] ?? $mode, ENT_QUOTES, 'UTF-8');
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = []) {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $options['display'] = false;
        switch ($field) {
            case 'statut':
                $options['value'] = $values[$field] ?? '';
                return Dropdown::showFromArray($name, self::getStatusLabels(), $options);
            case 'delivery_mode':
                $options['value'] = $values[$field] ?? '';
                return Dropdown::showFromArray($name, self::getDeliveryModeLabels(), $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    // ── Données ───────────────────────────────────────────────────────────────

    /**
     * Site de livraison d'un lieu : son ancêtre racine, lui-même s'il n'a pas de parent.
     * 0 si le lieu est absent ou introuvable. Même règle que l'« Intitulé Livraison » du
     * fichier de commande (racine du lieu hiérarchique).
     */
    public static function getSiteLocationId(int $locations_id): int {
        global $DB;

        $site    = 0;
        $current = $locations_id;
        $seen    = [];
        while ($current > 0 && !isset($seen[$current])) {
            $seen[$current] = true;
            if (!array_key_exists($current, self::$location_parents)) {
                $row = $DB->request([
                    'SELECT' => ['locations_id'],
                    'FROM'   => 'glpi_locations',
                    'WHERE'  => ['id' => $current],
                    'LIMIT'  => 1,
                ])->current();
                self::$location_parents[$current] = is_array($row) ? (int) $row['locations_id'] : null;
            }
            if (self::$location_parents[$current] === null) {
                break;
            }
            $site    = $current;
            $current = self::$location_parents[$current];
        }
        return $site;
    }

    /**
     * Lignes de la demande, avec l'imprimante (nom, n° de série, entité, lieu), la
     * cartouche retenue et le contrat. Clé = id de ligne ; tri : imprimante puis toner.
     */
    public function getLines(): array {
        global $DB;

        $lines = [];
        foreach ($DB->request([
            'SELECT'    => [
                'l.*',
                'p.name AS printer_name',
                'p.serial AS printer_serial',
                'p.entities_id AS printer_entities_id',
                'p.is_deleted AS printer_is_deleted',
                'loc.completename AS printer_location',
                'ci.name AS cartridge_name',
                'ci.ref AS cartridge_ref',
                'c.name AS contract_name',
            ],
            'FROM'      => PluginPrintgestionDemandeline::getTable() . ' AS l',
            'LEFT JOIN' => [
                'glpi_printers AS p'        => ['ON' => ['l' => 'printers_id', 'p' => 'id']],
                'glpi_locations AS loc'     => ['ON' => ['p' => 'locations_id', 'loc' => 'id']],
                'glpi_cartridgeitems AS ci' => ['ON' => ['l' => 'cartridgeitems_id', 'ci' => 'id']],
                'glpi_contracts AS c'       => ['ON' => ['l' => 'contracts_id', 'c' => 'id']],
            ],
            'WHERE'     => ['l.' . PluginPrintgestionDemandeline::$items_id => (int) $this->getID()],
            'ORDER'     => ['p.name', 'l.toner_property'],
        ]) as $row) {
            $lines[(int) $row['id']] = $row;
        }
        return $lines;
    }

    /**
     * Contrôles des lignes OUVERTES, recalculés à l'instant — jamais repris de l'écran :
     * imprimante présente et toujours dans l'entité de la demande, référence de
     * cartouche (résolution stricte), sous contrat / hors contrat, prix, quantité,
     * verrous anti-double-envoi (hors lignes de cette demande).
     *
     * @param array $lines Lignes de getLines().
     * @return array id de ligne => ['errors' => string[] (bloquants), 'warnings' => string[],
     *               'cartridgeitems_id' => int (référence résolue maintenant, 0 sinon),
     *               'coverage' => array (Contractrate::getConsumablesCoverage())]
     */
    public function checkLines(array $lines): array {
        $open = array_filter(
            $lines,
            static fn(array $line) => in_array((string) $line['statut'], self::OPEN_STATUSES, true)
        );
        if (empty($open)) {
            return [];
        }

        $locks = PluginPrintgestionGuard::evaluateLive(
            array_map(static fn(array $line) => [
                'printers_id' => (int) $line['printers_id'],
                'property'    => (string) $line['toner_property'],
            ], array_values($open)),
            ['exclude_demandes_id' => (int) $this->getID()]
        );

        $out = [];
        foreach ($open as $line_id => $line) {
            $errors      = [];
            $warnings    = [];
            $printers_id = (int) $line['printers_id'];
            $property    = (string) $line['toner_property'];

            if ($line['printer_name'] === null || (int) $line['printer_is_deleted'] === 1) {
                $errors[] = __('Imprimante supprimée ou introuvable.', 'printgestion');
            } elseif ((int) $line['printer_entities_id'] !== (int) $this->fields['entities_id']) {
                $errors[] = __('L\'imprimante a changé d\'entité depuis la proposition : annulez la ligne.', 'printgestion');
            }

            $ref = PluginPrintgestionSnmpmapping::resolveCartridge($printers_id, $property);
            if ($ref['cartridgeitems_id'] <= 0) {
                $errors[] = __('Référence non résolue', 'printgestion') . ' : ' . $ref['message'];
            } elseif ((int) $line['cartridgeitems_id'] !== (int) $ref['cartridgeitems_id']) {
                $warnings[] = __('La cartouche résolue a changé depuis la proposition : c\'est la cartouche actuelle qui sera retenue.', 'printgestion');
            }

            $coverage       = PluginPrintgestionContractrate::getConsumablesCoverage($printers_id);
            $was_under      = (int) $line['is_under_contract'] === 1;
            $price          = $line['unit_price'];
            if ($coverage['under_contract'] !== $was_under) {
                $warnings[] = $coverage['under_contract']
                    ? __('Passée sous contrat depuis la proposition : prix 0 à la validation.', 'printgestion')
                    : __('Passée hors contrat depuis la proposition : le prix 0 sera retiré (vide, à renseigner par les Achats).', 'printgestion');
            } elseif (!$coverage['under_contract'] && $price !== null && (float) $price == 0.0) {
                $errors[] = __('Prix 0 interdit hors contrat : laissez le prix vide pour que les Achats le renseignent.', 'printgestion');
            }

            if ((int) $line['quantity'] < 1) {
                $errors[] = __('Quantité invalide (minimum 1).', 'printgestion');
            }

            $lock = $locks[$printers_id . '|' . $property] ?? null;
            if ($lock !== null) {
                if ($lock['blocking']) {
                    $errors[] = $lock['message'];
                } else {
                    $warnings[] = $lock['message'];
                }
            }

            $out[$line_id] = [
                'errors'            => $errors,
                'warnings'          => $warnings,
                'cartridgeitems_id' => (int) $ref['cartridgeitems_id'],
                'coverage'          => $coverage,
            ];
        }
        return $out;
    }

    // ── Modification, validation, annulation ──────────────────────────────────

    /** « Imprimante — Toner » d'une ligne de getLines(), pour les messages. */
    private static function lineLabel(array $line): string {
        return sprintf(
            '%s — %s',
            $line['printer_name'] ?? ('#' . (int) $line['printers_id']),
            (string) $line['toner_property']
        );
    }

    /**
     * Enregistre les modifications d'une demande PROPOSÉE : mode, contact et commentaire
     * de livraison ; quantité, prix unitaire (hors contrat uniquement) et annulation de
     * chaque ligne proposée. Tout ou rien : à la moindre saisie invalide, rien n'est
     * enregistré et chaque erreur est renvoyée.
     *
     * @param array $input Saisie du formulaire (delivery_mode, contact, delivery_comment,
     *                     quantity[id], unit_price[id], cancel_line[id]).
     * @return array ['errors' => string[]]
     */
    public function saveProposal(array $input): array {
        $id = (int) $this->getID();
        if ((string) $this->fields['statut'] !== self::STATUS_PROPOSED) {
            return ['errors' => [sprintf(__('La demande #%d n\'est plus proposée : modification impossible.', 'printgestion'), $id)]];
        }

        $errors = [];
        $header = ['id' => $id];

        $mode = (string) ($input['delivery_mode'] ?? $this->fields['delivery_mode']);
        if (isset(self::getDeliveryModeLabels()[$mode])) {
            $header['delivery_mode'] = $mode;
        } else {
            $errors[] = __('Mode de livraison invalide.', 'printgestion');
        }
        $header['contact']          = mb_substr(trim((string) ($input['contact'] ?? '')), 0, 255);
        $header['delivery_comment'] = trim((string) ($input['delivery_comment'] ?? ''));

        $quantities = (array) ($input['quantity'] ?? []);
        $prices     = (array) ($input['unit_price'] ?? []);
        $cancels    = (array) ($input['cancel_line'] ?? []);

        $line_updates = [];
        $has_cancel   = false;
        foreach ($this->getLines() as $line_id => $line) {
            if ((string) $line['statut'] !== self::STATUS_PROPOSED) {
                continue;
            }
            $label = self::lineLabel($line);

            if (!empty($cancels[$line_id])) {
                $line_updates[$line_id] = ['statut' => self::STATUS_CANCELLED];
                $has_cancel             = true;
                continue;
            }

            $update = [];
            if (array_key_exists($line_id, $quantities)) {
                $raw = trim((string) $quantities[$line_id]);
                if (!ctype_digit($raw) || (int) $raw < 1 || (int) $raw > self::MAX_QUANTITY) {
                    $errors[] = sprintf(
                        __('%1$s : quantité invalide (nombre entier de 1 à %2$d).', 'printgestion'),
                        $label,
                        self::MAX_QUANTITY
                    );
                } else {
                    $update['quantity'] = (int) $raw;
                }
            }

            // Prix : saisissable hors contrat seulement. Sous contrat, le champ n'est pas
            // envoyé et une valeur forcée est ignorée (prix 0 fixé à la validation).
            if (array_key_exists($line_id, $prices)
                && !PluginPrintgestionContractrate::getConsumablesCoverage((int) $line['printers_id'])['under_contract']) {
                $raw = str_replace([' ', "\u{00A0}", "\u{202F}", '€'], '', trim((string) $prices[$line_id]));
                $raw = str_replace(',', '.', $raw);
                if ($raw === '') {
                    $update['unit_price'] = null;
                } elseif (!preg_match('/^\d{1,16}(\.\d{1,4})?$/', $raw)) {
                    $errors[] = sprintf(__('%s : prix unitaire invalide (nombre positif, 4 décimales au plus).', 'printgestion'), $label);
                } elseif ((float) $raw == 0.0) {
                    $errors[] = sprintf(__('%s : prix 0 interdit hors contrat — laissez le prix vide pour que les Achats le renseignent.', 'printgestion'), $label);
                } else {
                    $update['unit_price'] = $raw;
                }
            }

            if (!empty($update)) {
                $line_updates[$line_id] = $update;
            }
        }

        if (!empty($errors)) {
            return ['errors' => $errors];
        }

        try {
            self::transactional(function () use ($header, $line_updates) {
                if (!$this->update($header)) {
                    throw new RuntimeException('Mise à jour de la demande refusée par GLPI.');
                }
                $line = new PluginPrintgestionDemandeline();
                foreach ($line_updates as $line_id => $update) {
                    if (!$line->update(['id' => (int) $line_id] + $update)) {
                        throw new RuntimeException(sprintf('Mise à jour de la ligne #%d refusée par GLPI.', $line_id));
                    }
                }
                $this->cancelIfNoLineLeft();
            });
        } catch (Throwable $e) {
            PluginPrintgestionLogger::error('demandes', sprintf('Enregistrement de la demande #%d annulé.', $id), $e);
            return ['errors' => [__('Enregistrement impossible (erreur technique, détail dans le journal printgestion) : aucune modification n\'a été enregistrée.', 'printgestion')]];
        }

        if ($has_cancel) {
            PluginPrintgestionAlert::invalidateCache(); // cartouches libérées
        }
        return ['errors' => []];
    }

    /**
     * Valide une demande PROPOSÉE, tout ou rien. Contrôles recalculés (checkLines()) sur
     * chaque ligne proposée : au moindre contrôle bloquant, rien n'est validé et les
     * motifs sont renvoyés. Sinon, en transaction : cartouche résolue, contrat et prix de
     * chaque ligne mis à jour (0 sous contrat ; un prix 0 hérité d'une ligne passée hors
     * contrat est retiré), lignes et demande « validée », valideur et date.
     *
     * @return array ['ok' => bool, 'errors' => string[], 'warnings' => string[]]
     */
    public function validateDemande(): array {
        $out = ['ok' => false, 'errors' => [], 'warnings' => []];
        $id  = (int) $this->getID();

        // Relue : l'état affiché à l'écran n'est jamais pris pour acquis.
        if (!$this->getFromDB($id)) {
            $out['errors'][] = __('Demande introuvable.', 'printgestion');
            return $out;
        }
        $statut = (string) $this->fields['statut'];
        if ($statut !== self::STATUS_PROPOSED) {
            $out['errors'][] = sprintf(
                __('La demande #%1$d n\'est plus proposée (statut : %2$s) : validation impossible.', 'printgestion'),
                $id,
                self::getStatusLabels()[$statut] ?? $statut
            );
            return $out;
        }

        $lines    = $this->getLines();
        $proposed = array_filter(
            $lines,
            static fn(array $line) => (string) $line['statut'] === self::STATUS_PROPOSED
        );
        if (empty($proposed)) {
            $out['errors'][] = __('Aucune ligne à valider.', 'printgestion');
            return $out;
        }

        $checks = $this->checkLines($lines);
        foreach ($proposed as $line_id => $line) {
            $label = self::lineLabel($line);
            foreach ($checks[$line_id]['errors'] ?? [] as $message) {
                $out['errors'][] = $label . ' : ' . $message;
            }
            foreach ($checks[$line_id]['warnings'] ?? [] as $message) {
                $out['warnings'][] = $label . ' : ' . $message;
            }
        }
        if (!empty($out['errors'])) {
            return $out;
        }

        try {
            self::transactional(function () use ($proposed, $checks) {
                $line = new PluginPrintgestionDemandeline();
                foreach ($proposed as $line_id => $data) {
                    $coverage = $checks[$line_id]['coverage'];
                    if ($coverage['under_contract']) {
                        // Chaîne : update() compare sans typage, null == 0 ne serait pas écrit.
                        $price = '0';
                    } elseif ($data['unit_price'] !== null && (float) $data['unit_price'] == 0.0) {
                        $price = null; // passée hors contrat : le prix 0 d'origine est retiré
                    } else {
                        $price = $data['unit_price'];
                    }
                    if (!$line->update([
                        'id'                => (int) $line_id,
                        'cartridgeitems_id' => (int) $checks[$line_id]['cartridgeitems_id'],
                        'is_under_contract' => $coverage['under_contract'] ? 1 : 0,
                        'contracts_id'      => (int) $coverage['contracts_id'],
                        'unit_price'        => $price,
                        'statut'            => self::STATUS_VALIDATED,
                    ])) {
                        throw new RuntimeException(sprintf('Validation de la ligne #%d refusée par GLPI.', $line_id));
                    }
                }
                if (!$this->update([
                    'id'                => (int) $this->getID(),
                    'statut'            => self::STATUS_VALIDATED,
                    'users_id_validate' => (int) Session::getLoginUserID(),
                    'date_validate'     => $_SESSION['glpi_currenttime'],
                ])) {
                    throw new RuntimeException('Validation de la demande refusée par GLPI.');
                }
            });
        } catch (Throwable $e) {
            PluginPrintgestionLogger::error('demandes', sprintf('Validation de la demande #%d annulée.', $id), $e);
            $out['errors'][] = __('Validation impossible (erreur technique, détail dans le journal printgestion) : la demande reste proposée.', 'printgestion');
            return $out;
        }

        $out['ok'] = true;
        return $out;
    }

    /**
     * Annule une demande proposée ou validée (pas encore exportée) et ses lignes
     * ouvertes, qui libèrent leurs cartouches. Motif obligatoire ; rien n'est supprimé.
     *
     * @return array ['ok' => bool, 'errors' => string[]]
     */
    public function cancelDemande(string $reason): array {
        $id = (int) $this->getID();
        if (!$this->getFromDB($id)) {
            return ['ok' => false, 'errors' => [__('Demande introuvable.', 'printgestion')]];
        }
        $statut = (string) $this->fields['statut'];
        if (!in_array($statut, self::OPEN_STATUSES, true)) {
            return ['ok' => false, 'errors' => [sprintf(
                __('La demande #%1$d est au statut « %2$s » : seule une demande proposée ou validée s\'annule ici.', 'printgestion'),
                $id,
                self::getStatusLabels()[$statut] ?? $statut
            )]];
        }
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'errors' => [__('Motif d\'annulation obligatoire.', 'printgestion')]];
        }

        try {
            self::transactional(function () use ($reason) {
                $line = new PluginPrintgestionDemandeline();
                foreach ($this->getLines() as $line_id => $data) {
                    if (in_array((string) $data['statut'], self::OPEN_STATUSES, true)
                        && !$line->update(['id' => (int) $line_id, 'statut' => self::STATUS_CANCELLED])) {
                        throw new RuntimeException(sprintf('Annulation de la ligne #%d refusée par GLPI.', $line_id));
                    }
                }
                if (!$this->update([
                    'id'              => (int) $this->getID(),
                    'statut'          => self::STATUS_CANCELLED,
                    'cancel_reason'   => $reason,
                    'users_id_cancel' => (int) Session::getLoginUserID(),
                    'date_cancel'     => $_SESSION['glpi_currenttime'],
                ])) {
                    throw new RuntimeException('Annulation de la demande refusée par GLPI.');
                }
            });
        } catch (Throwable $e) {
            PluginPrintgestionLogger::error('demandes', sprintf('Annulation de la demande #%d abandonnée.', $id), $e);
            return ['ok' => false, 'errors' => [__('Annulation impossible (erreur technique, détail dans le journal printgestion) : la demande est inchangée.', 'printgestion')]];
        }

        PluginPrintgestionAlert::invalidateCache(); // cartouches libérées
        return ['ok' => true, 'errors' => []];
    }

    /**
     * Après annulation de lignes : une demande dont toutes les lignes sont annulées
     * passe « annulée », avec un motif automatique. À exécuter en transaction.
     */
    private function cancelIfNoLineLeft(): void {
        $remaining = countElementsInTable(PluginPrintgestionDemandeline::getTable(), [
            PluginPrintgestionDemandeline::$items_id => (int) $this->getID(),
            'NOT'                                    => ['statut' => self::STATUS_CANCELLED],
        ]);
        if ($remaining > 0) {
            return;
        }
        if (!$this->update([
            'id'              => (int) $this->getID(),
            'statut'          => self::STATUS_CANCELLED,
            'cancel_reason'   => __('Toutes les lignes ont été annulées.', 'printgestion'),
            'users_id_cancel' => (int) Session::getLoginUserID(),
            'date_cancel'     => $_SESSION['glpi_currenttime'],
        ])) {
            throw new RuntimeException('Annulation de la demande sans ligne refusée par GLPI.');
        }
    }

    // ── Export Gesconso ───────────────────────────────────────────────────────

    /**
     * Lignes au format de PluginPrintgestionGesconso::prepare() pour les lignes de la
     * demande aux statuts donnés. Devis = date de validation (à défaut : de proposition) ;
     * Complément livraison = contact et commentaire de livraison, sur une ligne.
     */
    public function buildExportLines(array $lines, array $statuses): array {
        $complement = implode(' — ', array_filter([
            trim((string) $this->fields['contact']),
            trim((string) preg_replace('/\s+/u', ' ', (string) $this->fields['delivery_comment'])),
        ], static fn(string $part) => $part !== ''));
        $date = (string) ($this->fields['date_validate'] ?: $this->fields['date_creation'] ?: date('Y-m-d H:i:s'));

        $out = [];
        foreach ($lines as $line_id => $line) {
            if (!in_array((string) $line['statut'], $statuses, true)) {
                continue;
            }
            $out[] = [
                'key'               => (string) $line_id,
                'label'             => sprintf(__('Demande #%1$d — %2$s', 'printgestion'), $this->getID(), self::lineLabel($line)),
                'printers_id'       => (int) $line['printers_id'],
                'cartridgeitems_id' => (int) $line['cartridgeitems_id'],
                'quantity'          => (int) $line['quantity'],
                'unit_price'        => $line['unit_price'],
                'under_contract'    => (int) $line['is_under_contract'] === 1,
                'date'              => $date,
                'complement'        => $complement,
            ];
        }
        return $out;
    }

    /**
     * Contrôles avant export des lignes VALIDÉES : Gesconso::prepare() — code client Sage,
     * adresse de livraison, référence article, prix. Une seule ligne en défaut empêche
     * d'exporter la demande.
     *
     * @return array ['lines' => lignes de buildExportLines(), 'gesconso' => résultat de prepare()]
     */
    public function prepareExport(): array {
        $lines = $this->buildExportLines($this->getLines(), [self::STATUS_VALIDATED]);
        return ['lines' => $lines, 'gesconso' => PluginPrintgestionGesconso::prepare($lines)];
    }

    // ── Suivi après export ────────────────────────────────────────────────────

    /** Ordre du cycle (annulée à part) : sert à retenir le statut le moins avancé. */
    const STATUS_FLOW = ['proposed', 'validated', 'exported', 'shipped', 'delivered', 'installed'];

    /** Statut d'une ligne exportée d'après le statut de son expédition. */
    const EXPEDITION_TO_LINE_STATUS = [
        'pending'     => 'exported',
        'shipped'     => 'shipped',
        'transit'     => 'shipped',
        'delivered'   => 'delivered',
        'installed'   => 'installed',
        'cancelled'   => 'cancelled',
    ];

    /**
     * Répercute l'avancement des expéditions sur les lignes exportées (exportée →
     * expédiée → livrée → posée, ou annulée), puis sur l'en-tête des demandes concernées :
     * statut le moins avancé des lignes non annulées, annulée si toutes le sont.
     * Écritures par update() : historique natif. Appelée par les tâches automatiques, après une
     * action sur une expédition (syncForExpedition()) et depuis la fiche, par un POST : jamais en GET.
     *
     * @param ?array $demandes_ids Restreindre à ces demandes (null : toutes).
     * @return int Nombre de lignes mises à jour.
     */
    public static function syncFromExpeditions(?array $demandes_ids = null): int {
        $updated  = 0;
        $touched  = [];
        $line     = new PluginPrintgestionDemandeline();
        foreach (self::getLinesBehindExpeditions($demandes_ids) as [$row, $target]) {
            if ($line->update(['id' => (int) $row['id'], 'statut' => $target])) {
                $updated++;
                $touched[(int) $row['plugin_printgestion_demandes_id']] = true;
            } else {
                PluginPrintgestionLogger::warning('demandes', sprintf('Ligne #%d : passage au statut %s refusé.', $row['id'], $target));
            }
        }

        foreach (array_keys($touched) as $demandes_id) {
            $demande = new self();
            if ($demande->getFromDB($demandes_id)) {
                $demande->refreshStatusFromLines();
            }
        }
        return $updated;
    }

    /** Répercute l'avancement d'une expédition sur les demandes dont une ligne lui est rattachée. */
    public static function syncForExpedition(int $expeditions_id): int {
        global $DB;

        $ids = [];
        foreach ($DB->request([
            'SELECT'   => ['plugin_printgestion_demandes_id'],
            'DISTINCT' => true,
            'FROM'     => PluginPrintgestionDemandeline::getTable(),
            'WHERE'    => ['expeditions_id' => $expeditions_id],
        ]) as $row) {
            $ids[] = (int) $row['plugin_printgestion_demandes_id'];
        }
        return empty($ids) ? 0 : self::syncFromExpeditions($ids);
    }

    /** Nombre de lignes de ces demandes en retard sur leur expédition (lecture seule, pour l'affichage). */
    public static function countLinesBehindExpeditions(array $demandes_ids): int {
        return count(self::getLinesBehindExpeditions($demandes_ids));
    }

    /**
     * Lignes exportées ou plus avancées dont le statut ne reflète pas encore celui de leur expédition.
     *
     * @return array<int, array{0: array, 1: string}> [ligne, statut cible]
     */
    private static function getLinesBehindExpeditions(?array $demandes_ids): array {
        global $DB;

        $criteria = [
            'SELECT'     => ['l.id', 'l.plugin_printgestion_demandes_id', 'l.statut', 'e.statut AS expedition_statut'],
            'FROM'       => PluginPrintgestionDemandeline::getTable() . ' AS l',
            'INNER JOIN' => [
                PluginPrintgestionExpedition::getTable() . ' AS e' => ['ON' => ['l' => 'expeditions_id', 'e' => 'id']],
            ],
            'WHERE'      => ['l.statut' => [self::STATUS_EXPORTED, self::STATUS_SHIPPED, self::STATUS_DELIVERED]],
        ];
        if ($demandes_ids !== null) {
            $ids = array_values(array_filter(array_map('intval', $demandes_ids)));
            if (empty($ids)) {
                return 0;
            }
            $criteria['WHERE']['l.plugin_printgestion_demandes_id'] = $ids;
        }

        $behind = [];
        foreach ($DB->request($criteria) as $row) {
            $target = self::EXPEDITION_TO_LINE_STATUS[(string) $row['expedition_statut']] ?? null;
            if ($target !== null && $target !== (string) $row['statut']) {
                $behind[] = [$row, $target];
            }
        }
        return $behind;
    }

    /**
     * Statut de l'en-tête d'une demande exportée ou plus avancée, d'après ses lignes. Une
     * demande proposée ou validée n'est jamais modifiée ici (ses transitions passent par la
     * validation et l'annulation).
     */
    private function refreshStatusFromLines(): void {
        $current = (string) $this->fields['statut'];
        if (in_array($current, self::OPEN_STATUSES, true) || $current === self::STATUS_CANCELLED) {
            return;
        }

        $active = array_values(array_filter(
            array_column($this->getLines(), 'statut'),
            static fn($statut) => $statut !== self::STATUS_CANCELLED
        ));
        if (empty($active)) {
            $input = [
                'statut'          => self::STATUS_CANCELLED,
                'cancel_reason'   => __('Toutes les expéditions de la demande ont été annulées.', 'printgestion'),
                'users_id_cancel' => (int) Session::getLoginUserID(),
                'date_cancel'     => $_SESSION['glpi_currenttime'],
            ];
        } else {
            $ranks  = array_map(static fn($statut) => (int) array_search($statut, self::STATUS_FLOW, true), $active);
            $target = self::STATUS_FLOW[min($ranks)];
            if ($target === $current) {
                return;
            }
            $input = ['statut' => $target];
        }

        if (!$this->update(['id' => (int) $this->getID()] + $input)) {
            PluginPrintgestionLogger::warning('demandes', sprintf('Demande #%d : mise à jour du statut refusée.', $this->getID()));
        }
    }

    // ── Notifications natives ─────────────────────────────────────────────────

    /**
     * Déclenche une notification native (PluginPrintgestionNotificationTargetDemande) : mise
     * en file d'attente GLPI si une notification active existe pour l'événement. Un échec
     * est journalisé et n'interrompt jamais le traitement appelant.
     */
    public static function raiseEventFor(string $event, int $demandes_id): void {
        try {
            $demande = new self();
            if ($demandes_id > 0 && $demande->getFromDB($demandes_id)) {
                NotificationEvent::raiseEvent($event, $demande);
            }
        } catch (Throwable $e) {
            PluginPrintgestionLogger::error('notifications', sprintf('Notification %s de la demande #%d non émise.', $event, $demandes_id), $e);
        }
    }

    /**
     * Relance des demandes qui traînent (événement demande_stale) : proposée sans validation,
     * ou validée sans export, depuis plus de demande_reminder_days jours ; au plus une relance
     * par période. 0 jour = relance désactivée.
     *
     * @return int Relances émises.
     */
    public static function sendReminders(): int {
        global $DB;

        $days = (int) (PluginPrintgestionConfig::getInstance()->fields['demande_reminder_days'] ?? 0);
        if ($days <= 0) {
            return 0;
        }
        $cutoff = date('Y-m-d H:i:s', time() - $days * DAY_TIMESTAMP);

        $sent = 0;
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'OR' => [
                    ['statut' => self::STATUS_PROPOSED, 'date_creation' => ['<=', $cutoff]],
                    ['statut' => self::STATUS_VALIDATED, 'date_validate' => ['<=', $cutoff]],
                ],
                ['OR' => [
                    ['date_last_reminder' => null],
                    ['date_last_reminder' => ['<=', $cutoff]],
                ]],
            ],
        ]) as $row) {
            self::raiseEventFor('demande_stale', (int) $row['id']);
            $DB->update(self::getTable(), ['date_last_reminder' => $_SESSION['glpi_currenttime']], ['id' => (int) $row['id']]);
            $sent++;
        }
        return $sent;
    }

    /**
     * Export Gesconso de demandes VALIDÉES, en un seul fichier (une ligne par cartouche).
     *
     * $send = true  : envoi aux Achats. En transaction : une expédition par ligne (le verrou
     *                 anti-double-envoi passe de la ligne à l'expédition), lignes et demandes
     *                 « exportée », fichier archivé et rattaché aux demandes et aux
     *                 expéditions, mail aux Achats. Échec de l'archivage ou du mail : rien
     *                 n'est enregistré, les demandes restent validées.
     * $send = false : téléchargement de test, sans mail. Le fichier est archivé (rattaché aux
     *                 demandes, commentaire « non transmis ») et noté dans leur historique ;
     *                 aucun statut ne change, aucune expédition n'est créée.
     *
     * Refus de l'export entier : demande non validée ou hors droits, ligne en défaut
     * (contrôles avant export), et à l'envoi, verrou anti-double-envoi bloquant.
     *
     * @return array ['ok' => bool, 'errors' => string[], 'warnings' => string[],
     *                'documents_id' => int, 'lines' => int]
     */
    public static function exportDemandes(array $demandes_ids, bool $send): array {
        $out = ['ok' => false, 'errors' => [], 'warnings' => [], 'documents_id' => 0, 'lines' => 0];

        $demandes = [];
        $lines    = [];
        $data     = []; // clé de ligne => ['demande' => self, 'line' => ligne de getLines()]
        foreach (array_unique(array_map('intval', $demandes_ids)) as $id) {
            $demande = new self();
            if ($id <= 0 || !$demande->getFromDB($id) || !$demande->can($id, UPDATE)) {
                $out['errors'][] = sprintf(__('Demande #%d introuvable ou hors de vos droits.', 'printgestion'), $id);
                continue;
            }
            $statut = (string) $demande->fields['statut'];
            if ($statut !== self::STATUS_VALIDATED) {
                $out['errors'][] = sprintf(
                    __('Demande #%1$d au statut « %2$s » : seules les demandes validées s\'exportent.', 'printgestion'),
                    $id,
                    self::getStatusLabels()[$statut] ?? $statut
                );
                continue;
            }
            $rows   = $demande->getLines();
            $export = $demande->buildExportLines($rows, [self::STATUS_VALIDATED]);
            if (empty($export)) {
                $out['errors'][] = sprintf(__('Demande #%d : aucune ligne validée.', 'printgestion'), $id);
                continue;
            }

            if ($send) {
                // Verrous revérifiés à l'envoi (hors lignes de la demande elle-même) : une pose,
                // un ticket ou un envoi a pu survenir depuis la validation.
                $locks = PluginPrintgestionGuard::evaluateLive(array_map(static fn(array $l) => [
                    'printers_id' => (int) $rows[(int) $l['key']]['printers_id'],
                    'property'    => (string) $rows[(int) $l['key']]['toner_property'],
                ], $export), ['exclude_demandes_id' => $id]);
                foreach ($export as $l) {
                    $line = $rows[(int) $l['key']];
                    $lock = $locks[(int) $line['printers_id'] . '|' . $line['toner_property']] ?? null;
                    if ($lock !== null && $lock['blocking']) {
                        $out['errors'][] = $l['label'] . ' : ' . $lock['message'];
                    }
                }
            }

            foreach ($export as $l) {
                $lines[]           = $l;
                $data[$l['key']]   = ['demande' => $demande, 'line' => $rows[(int) $l['key']]];
            }
            $demandes[$id] = $demande;
        }
        if (empty($demandes) && empty($out['errors'])) {
            $out['errors'][] = __('Aucune demande sélectionnée.', 'printgestion');
        }
        if (!empty($out['errors'])) {
            return $out;
        }

        $gesconso = PluginPrintgestionGesconso::prepare($lines);
        $out['warnings'] = $gesconso['warnings'];
        if (!empty($gesconso['errors'])) {
            $out['errors'] = array_merge(...array_values($gesconso['errors']));
            return $out;
        }
        $out['lines'] = count($lines);

        try {
            $file = PluginPrintgestionGesconso::write($gesconso['rows']);
        } catch (Throwable $e) {
            PluginPrintgestionLogger::error('export', 'Fichier Gesconso des demandes non généré.', $e);
            $out['errors'][] = __('Le fichier Gesconso n\'a pas pu être généré (détail dans le journal printgestion).', 'printgestion');
            return $out;
        }

        $numbers = implode(', ', array_map(static fn(int $id) => '#' . $id, array_keys($demandes)));
        $author  = getUserName((int) Session::getLoginUserID());
        $now     = Html::convDateTime(date('Y-m-d H:i:s'));
        $archive = null;

        try {
            if (!$send) {
                $out['documents_id'] = self::transactional(function () use ($file, $demandes, $numbers, $author, $now, &$archive) {
                    $documents_id = PluginPrintgestionGesconso::archive(
                        $file,
                        sprintf(__('Téléchargement de test du %1$s par %2$s, NON transmis aux Achats — demandes %3$s non exportées.', 'printgestion'), $now, $author, $numbers),
                        array_map(static fn(int $id) => [self::class, $id], array_keys($demandes))
                    );
                    $archive = new Document();
                    $archive->getFromDB($documents_id);
                    foreach (array_keys($demandes) as $id) {
                        Log::history($id, self::class, [0, '', sprintf(
                            __('Fichier Gesconso de test téléchargé (document #%d), non transmis aux Achats.', 'printgestion'),
                            $documents_id
                        )], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
                    }
                    return $documents_id;
                });
            } else {
                $order_id = 0;
                $out['documents_id'] = self::transactional(function () use ($file, $demandes, $data, $numbers, $author, $now, &$archive, &$order_id) {
                    // UUID de 36 caractères (colonne group_id) : Rule::getUuid() en produit 41.
                    $group_id    = PluginPrintgestionExpedition::generateUuid();
                    $line_object = new PluginPrintgestionDemandeline();
                    $expeditions = [];
                    $mail_rows   = [];

                    foreach ($data as $key => $item) {
                        $line = $item['line'];
                        // La ligne cède son verrou avant la création de l'expédition qui le reprend.
                        if (!$line_object->update(['id' => (int) $key, 'statut' => self::STATUS_EXPORTED])) {
                            throw new RuntimeException(sprintf('Export de la ligne #%d refusé par GLPI.', $key));
                        }
                        $expeditions_id = PluginPrintgestionExpedition::createFromAlert(
                            (int) $line['printers_id'],
                            (string) $line['toner_property'],
                            (int) ($line['level_at_proposal'] ?? 0),
                            $line['estimated_days'] !== null ? (int) $line['estimated_days'] : null,
                            $group_id
                        );
                        if ($expeditions_id <= 0
                            || !$line_object->update(['id' => (int) $key, 'expeditions_id' => $expeditions_id])) {
                            throw new RuntimeException(sprintf('Expédition de la ligne #%d non enregistrée.', $key));
                        }
                        $expeditions[] = $expeditions_id;

                        $row          = PluginPrintgestionExpedition::buildPurchaseRowData(
                            (int) $line['printers_id'],
                            (string) $line['toner_property'],
                            (int) $line['cartridgeitems_id']
                        );
                        $row['level'] = (int) ($line['level_at_proposal'] ?? 0);
                        $row['days']  = $line['estimated_days'] !== null ? (int) $line['estimated_days'] : null;
                        $mail_rows[]  = $row;
                    }

                    foreach ($demandes as $id => $demande) {
                        if (!$demande->update(['id' => $id, 'statut' => self::STATUS_EXPORTED])) {
                            throw new RuntimeException(sprintf('Export de la demande #%d refusé par GLPI.', $id));
                        }
                    }

                    $documents_id = PluginPrintgestionGesconso::archive(
                        $file,
                        sprintf(__('Export des demandes %1$s envoyé aux Achats le %2$s par %3$s (%4$d ligne(s)).', 'printgestion'), $numbers, $now, $author, count($data)),
                        array_merge(
                            array_map(static fn(int $id) => [self::class, $id], array_keys($demandes)),
                            array_map(static fn(int $id) => [PluginPrintgestionExpedition::class, $id], $expeditions)
                        )
                    );
                    $archive = new Document();
                    $archive->getFromDB($documents_id);

                    // Enregistrer d'abord, envoyer ensuite : le mail part après la transaction.
                    $order_id = PluginPrintgestionPurchaseorder::record($group_id, PluginPrintgestionPurchaseorder::SOURCE_EXPORT, $documents_id, $mail_rows);
                    return $documents_id;
                });
                $archive = null; // archive validée en base : plus jamais retirée par le traitement d'erreur ci-dessous
                PluginPrintgestionAlert::invalidateCache();

                // Export enregistré : mail aux Achats (demande_exported émis par send() une fois transmis). En cas
                // d'échec, l'export reste enregistré et verrouillé, « non transmis », à renvoyer.
                $sent = PluginPrintgestionPurchaseorder::send($order_id);
                if (!$sent['ok']) {
                    $out['not_sent'] = PluginPrintgestionPurchaseorder::getNotSentMessage($sent['error']);
                }
            }
        } catch (Throwable $e) {
            if ($archive !== null) {
                PluginPrintgestionGesconso::removeOrphanArchive($archive->fields);
            }
            if ($e->getCode() === PluginPrintgestionGesconso::ARCHIVE_FAILURE) {
                PluginPrintgestionLogger::error('export', sprintf('Export Gesconso des demandes %s annulé : fichier non archivé.', $numbers), $e);
                $out['errors'][] = $e->getMessage();
            } elseif (PluginPrintgestionExpedition::isDuplicateActiveError($e)) {
                $out['errors'][] = __('Un envoi vient d\'être enregistré pour une de ces cartouches (commande simultanée) : rechargez l\'écran.', 'printgestion');
            } else {
                PluginPrintgestionLogger::error('export', sprintf('Export Gesconso des demandes %s annulé.', $numbers), $e);
                $out['errors'][] = __('Erreur technique pendant l\'export (détail dans le journal printgestion).', 'printgestion');
            }
            $out['documents_id'] = 0;
            return $out;
        } finally {
            // Fichier temporaire supprimé dans tous les cas (la copie archivée reste).
            if (is_file($file['path'])) {
                @unlink($file['path']);
            }
        }

        $out['ok'] = true;
        return $out;
    }

    // ── Proposition automatique ───────────────────────────────────────────────

    /**
     * Jours pendant lesquels un emplacement dont une ligne de demande a été annulée n'est
     * plus proposé automatiquement, sauf pose détectée ou confirmée depuis l'annulation.
     */
    const RECENT_CANCEL_DAYS = 30;

    /**
     * Crée ou complète les demandes PROPOSÉES à partir des alertes toner (tâche
     * automatique), regroupées par client (entité de l'imprimante) et site de livraison
     * (lieu racine).
     *
     * Retenus : toners critiques ou à surveiller (alertes snoozées exclues) sans verrou
     * bloquant — pas d'envoi en cours ni de ligne de demande ouverte, pas de garde après
     * pose ni de ticket récent, sauf contournement (consommation anormale). Écartés aussi :
     * les emplacements dont une ligne a été annulée il y a moins de RECENT_CANCEL_DAYS
     * jours, sans pose détectée ou confirmée depuis. L'annulation est une décision : elle
     * n'est pas défaite au passage suivant, et la commande directe depuis l'écran des
     * alertes reste possible.
     * Regroupement par site : un toner à surveiller ne justifie pas un envoi à lui seul. Un
     * site n'est proposé que s'il compte un toner critique, ou pour compléter la demande déjà
     * proposée pour ce client et ce site ; un toner à surveiller n'y est ajouté qu'avec sa
     * cartouche résolue.
     * Pour un client et un site, la demande proposée existante est complétée, sinon une
     * demande est créée. Chaque ligne porte la cartouche résolue (0 si non résolue : la
     * ligne est créée mais bloquée à la validation, jamais exportée sans référence), la
     * couverture contrat et le prix (0 sous contrat, vide hors contrat).
     * Chaque groupe est une transaction : un groupe en échec est annulé en entier,
     * journalisé, et n'empêche pas les autres.
     *
     * @return array ['demandes_created' => int, 'lines_added' => int, 'unresolved' => int,
     *                'recently_cancelled' => int, 'deferred' => int (à surveiller non proposés),
     *                'failed_groups' => int]
     */
    public static function proposeFromAlerts(): array {
        global $DB;

        $stats = [
            'demandes_created'   => 0,
            'lines_added'        => 0,
            'unresolved'         => 0,
            'recently_cancelled' => 0,
            'deferred'           => 0,
            'failed_groups'      => 0,
        ];

        // Tâche automatique : toutes les entités.
        $candidates = [];
        foreach (PluginPrintgestionAlert::listAll(null, false) as $row) {
            if ($row['status'] === PluginPrintgestionAlert::STATUS_OK) {
                continue; // niveau correct, ou alerte snoozée
            }
            if (!empty($row['lock']) && $row['lock']['blocking']) {
                continue; // envoi ou demande en cours, garde après pose, ticket récent
            }
            $candidates[] = $row;
        }

        $recently_cancelled = self::getRecentlyCancelledSlots($candidates);
        if (!empty($recently_cancelled)) {
            $kept = [];
            foreach ($candidates as $row) {
                if (isset($recently_cancelled[(int) $row['printers_id'] . '|' . (string) $row['property']])) {
                    $stats['recently_cancelled']++;
                    continue;
                }
                $kept[] = $row;
            }
            $candidates = $kept;
        }
        if (empty($candidates)) {
            return $stats;
        }

        $printers = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'entities_id', 'locations_id', 'contact', 'contact_num'],
            'FROM'   => 'glpi_printers',
            'WHERE'  => ['id' => array_values(array_unique(array_column($candidates, 'printers_id')))],
        ]) as $printer) {
            $printers[(int) $printer['id']] = $printer;
        }

        $groups = [];
        foreach ($candidates as $row) {
            $printer = $printers[(int) $row['printers_id']] ?? null;
            if ($printer === null) {
                continue;
            }
            $entities_id  = (int) $printer['entities_id'];
            $locations_id = self::getSiteLocationId((int) $printer['locations_id']);
            $key          = $entities_id . '|' . $locations_id;

            $groups[$key]['entities_id']  = $entities_id;
            $groups[$key]['locations_id'] = $locations_id;
            $groups[$key]['rows'][]       = $row + ['_printer' => $printer];
        }

        foreach ($groups as $group) {
            try {
                $result = self::transactional(static fn() => self::proposeGroup($group));
                $stats['demandes_created'] += $result['created'];
                $stats['lines_added']      += $result['lines'];
                $stats['unresolved']       += $result['unresolved'];
                $stats['deferred']         += $result['deferred'];
                if ($result['lines'] > 0) {
                    self::raiseEventFor('demande_proposed', (int) $result['demandes_id']);
                }
            } catch (Throwable $e) {
                $stats['failed_groups']++;
                PluginPrintgestionLogger::error(
                    'demandes',
                    sprintf(
                        'Proposition automatique annulée pour l\'entité #%d, site #%d : aucune ligne créée pour ce groupe.',
                        $group['entities_id'],
                        $group['locations_id']
                    ),
                    $e
                );
            }
        }

        if ($stats['lines_added'] > 0) {
            PluginPrintgestionAlert::invalidateCache();
        }
        return $stats;
    }

    /**
     * Emplacements à ne pas reproposer, en clé « imprimante|toner » : la machine
     * (l'imprimante et ses doublons de numéro de série, comme pour les verrous) a une ligne
     * de demande annulée depuis moins de RECENT_CANCEL_DAYS jours, sans pose détectée
     * (hausse de niveau) ni confirmée depuis. Date d'annulation : dernière modification de
     * la ligne, qui n'est plus modifiable une fois annulée.
     */
    private static function getRecentlyCancelledSlots(array $rows): array {
        global $DB;

        $printer_ids = array_values(array_unique(array_map(static fn(array $row) => (int) $row['printers_id'], $rows)));
        if (empty($printer_ids)) {
            return [];
        }
        $machines = PluginPrintgestionGuard::resolveMachines($printer_ids);
        $all_ids  = array_values(array_unique(array_merge(...array_values($machines))));
        $cutoff   = date('Y-m-d H:i:s', time() - self::RECENT_CANCEL_DAYS * DAY_TIMESTAMP);

        $cancelled = [];
        foreach ($DB->request([
            'SELECT'  => ['printers_id', 'toner_property', new \QueryExpression('MAX(`date_mod`) AS `last_date`')],
            'FROM'    => PluginPrintgestionDemandeline::getTable(),
            'WHERE'   => [
                'printers_id' => $all_ids,
                'statut'      => self::STATUS_CANCELLED,
                'date_mod'    => ['>=', $cutoff],
            ],
            'GROUPBY' => ['printers_id', 'toner_property'],
        ]) as $line) {
            $cancelled[$line['printers_id'] . '|' . $line['toner_property']] = (string) $line['last_date'];
        }
        if (empty($cancelled)) {
            return [];
        }

        // Poses depuis le début de la fenêtre : détectées (hors lignes d'amorçage) ou
        // confirmées (manuellement ou à la réattribution).
        $installed = [];
        foreach ([
            ['glpi_plugin_printgestion_cartridge_history', 'date_install', ['is_detected' => 1]],
            [PluginPrintgestionExpedition::getTable(), 'date_installed', ['statut' => PluginPrintgestionExpedition::STATUS_INSTALLED]],
        ] as [$table, $field, $criteria]) {
            foreach ($DB->request([
                'SELECT'  => ['printers_id', 'toner_property', new \QueryExpression("MAX(`{$field}`) AS `last_date`")],
                'FROM'    => $table,
                'WHERE'   => $criteria + ['printers_id' => $all_ids, $field => ['>=', $cutoff]],
                'GROUPBY' => ['printers_id', 'toner_property'],
            ]) as $install) {
                $key             = $install['printers_id'] . '|' . $install['toner_property'];
                $installed[$key] = max($installed[$key] ?? '', (string) $install['last_date']);
            }
        }

        $skip = [];
        foreach ($rows as $row) {
            $pid          = (int) $row['printers_id'];
            $property     = (string) $row['property'];
            $last_cancel  = '';
            $last_install = '';
            foreach ($machines[$pid] ?? [$pid] as $id) {
                $last_cancel  = max($last_cancel, $cancelled[$id . '|' . $property] ?? '');
                $last_install = max($last_install, $installed[$id . '|' . $property] ?? '');
            }
            if ($last_cancel !== '' && $last_install <= $last_cancel) {
                $skip[$pid . '|' . $property] = true;
            }
        }
        return $skip;
    }

    /**
     * Un groupe (client, site) : complète la demande proposée existante ou en crée une,
     * puis ajoute une ligne par emplacement encore libre. À exécuter en transaction.
     *
     * Les verrous sont réévalués juste avant l'écriture : le calcul des alertes peut être
     * long et une commande a pu être passée entre-temps depuis l'écran des alertes.
     * Sans toner critique ni demande déjà proposée, rien n'est écrit ; un toner à surveiller
     * sans cartouche résolue n'est pas ajouté. Les deux sont comptés dans « deferred ».
     */
    private static function proposeGroup(array $group): array {
        global $DB;

        $result = ['created' => 0, 'lines' => 0, 'unresolved' => 0, 'deferred' => 0, 'demandes_id' => 0];

        $locks = PluginPrintgestionGuard::evaluate(array_map(static fn(array $row) => [
            'printers_id' => (int) $row['printers_id'],
            'property'    => (string) $row['property'],
            'level'       => $row['level'] !== null ? (int) $row['level'] : null,
        ], $group['rows']));
        $rows = array_values(array_filter($group['rows'], static function (array $row) use ($locks) {
            $lock = $locks[(int) $row['printers_id'] . '|' . (string) $row['property']] ?? null;
            return $lock === null || !$lock['blocking'];
        }));
        if (empty($rows)) {
            return $result;
        }

        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'entities_id'  => $group['entities_id'],
                'locations_id' => $group['locations_id'],
                'statut'       => self::STATUS_PROPOSED,
            ],
            'LIMIT'  => 1,
        ])->current();

        $has_critical = false;
        foreach ($rows as $row) {
            if ($row['status'] === PluginPrintgestionAlert::STATUS_CRITICAL) {
                $has_critical = true;
                break;
            }
        }
        if (!$has_critical && !is_array($existing)) {
            // Seulement des toners à surveiller : ils attendent un envoi critique sur le site.
            $result['deferred'] = count($rows);
            return $result;
        }

        if (is_array($existing)) {
            $demandes_id = (int) $existing['id'];
        } else {
            // Contact prérempli depuis la fiche de la première imprimante (champs Contact
            // et Numéro de contact), modifiable avant validation.
            $printer = $rows[0]['_printer'];
            $contact = implode(' — ', array_filter([
                trim((string) $printer['contact']),
                trim((string) $printer['contact_num']),
            ], static fn(string $part) => $part !== ''));

            $demande     = new self();
            $demandes_id = (int) $demande->add([
                'name'          => self::buildName($group['entities_id'], $group['locations_id']),
                'entities_id'   => $group['entities_id'],
                'locations_id'  => $group['locations_id'],
                'statut'        => self::STATUS_PROPOSED,
                'delivery_mode' => self::DELIVERY_DIRECT,
                'contact'       => $contact !== '' ? mb_substr($contact, 0, 255) : null,
            ]);
            if ($demandes_id <= 0) {
                throw new RuntimeException('Création de la demande d\'envoi refusée par GLPI.');
            }
            $result['created'] = 1;
        }
        $result['demandes_id'] = $demandes_id;

        foreach ($rows as $row) {
            $printers_id = (int) $row['printers_id'];
            $property    = (string) $row['property'];
            $ref         = PluginPrintgestionSnmpmapping::resolveCartridge($printers_id, $property);
            if ($row['status'] !== PluginPrintgestionAlert::STATUS_CRITICAL && $ref['cartridgeitems_id'] <= 0) {
                // À surveiller sans référence : pas de ligne bloquante ajoutée à l'envoi du site.
                $result['deferred']++;
                continue;
            }
            $coverage    = PluginPrintgestionContractrate::getConsumablesCoverage($printers_id);

            $line    = new PluginPrintgestionDemandeline();
            $line_id = $line->add([
                PluginPrintgestionDemandeline::$items_id => $demandes_id,
                'printers_id'       => $printers_id,
                'toner_property'    => $property,
                'cartridgeitems_id' => (int) $ref['cartridgeitems_id'],
                'quantity'          => 1,
                'unit_price'        => $coverage['under_contract'] ? 0 : null,
                'is_under_contract' => $coverage['under_contract'] ? 1 : 0,
                'contracts_id'      => (int) $coverage['contracts_id'],
                'level_at_proposal' => $row['level'] !== null ? (int) $row['level'] : null,
                'estimated_days'    => $row['days_remaining'] !== null ? (int) $row['days_remaining'] : null,
                'statut'            => self::STATUS_PROPOSED,
            ]);
            if (!$line_id) {
                throw new RuntimeException(sprintf(
                    'Ligne de demande refusée par GLPI : imprimante #%d, toner %s.',
                    $printers_id,
                    $property
                ));
            }
            $result['lines']++;
            if ($ref['cartridgeitems_id'] <= 0) {
                $result['unresolved']++;
            }
        }

        return $result;
    }

    /** Nom d'une demande : « Entité — Site ». */
    private static function buildName(int $entities_id, int $locations_id): string {
        $entity = Dropdown::getDropdownName('glpi_entities', $entities_id);
        $site   = $locations_id > 0
            ? Dropdown::getDropdownName('glpi_locations', $locations_id)
            : __('sans lieu', 'printgestion');
        return mb_substr($entity . ' — ' . $site, 0, 255);
    }

    /**
     * Exécute $work dans une transaction : validée si $work se termine, annulée si une
     * exception est levée. L'exception est relancée à l'appelant, qui la traite.
     */
    public static function transactional(callable $work) {
        global $DB;

        $DB->beginTransaction();
        try {
            $result = $work();
            $DB->commit();
            return $result;
        } catch (Throwable $e) {
            try {
                $DB->rollBack();
            } catch (Throwable $rollback_error) {
                // Annulation impossible : tracée ; l'erreur d'origine est relancée.
                \Glpi\Error\ErrorHandler::logCaughtException($rollback_error);
            }
            throw $e;
        }
    }

    // ── Fiche ─────────────────────────────────────────────────────────────────

    /**
     * Fiche : en-tête, lignes et contrôles. Avec le droit de validation (et l'entité) :
     * demande proposée modifiable (mode, contact, commentaire ; quantité, prix hors
     * contrat, annulation de ligne), « Enregistrer » et « Valider » ; demande proposée ou
     * validée annulable avec motif.
     */
    function showForm($ID, array $options = []) {
        $lines    = $this->getLines();
        $checks   = $this->checkLines($lines);
        $statut   = (string) $this->fields['statut'];
        $can_act  = self::canUpdate() && $this->canUpdateItem();
        $editable = $can_act && $statut === self::STATUS_PROPOSED;

        if ($editable) {
            echo "<form method='post' action='" . htmlspecialchars(self::getFormURL(), ENT_QUOTES, 'UTF-8') . "'>";
            echo Html::hidden('id', ['value' => (int) $this->getID()]);
        }

        $this->showHeaderCard($editable);
        $this->showLinesCard($lines, $checks, $editable);
        $this->showExportChecksCard($lines);

        if ($editable) {
            $this->showValidationButtons($lines, $checks);
            Html::closeForm();
        }
        if ($can_act && in_array($statut, self::OPEN_STATUSES, true)) {
            $this->showCancelCard();
        }
        return true;
    }

    /**
     * Contrôles avant export Gesconso, dès la proposition : lignes qui empêcheraient
     * d'écrire le fichier (code client, adresse de livraison, référence article, prix).
     * Demande proposée : indicatif, sur les données du moment ; demande validée : ce sont
     * les contrôles appliqués à l'export.
     */
    private function showExportChecksCard(array $lines): void {
        $esc    = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $statut = (string) $this->fields['statut'];
        if (!in_array($statut, self::OPEN_STATUSES, true)) {
            return;
        }
        $export_lines = $this->buildExportLines($lines, [$statut]);
        if (empty($export_lines)) {
            return;
        }
        $check = PluginPrintgestionGesconso::prepare($export_lines);

        echo "<div class='card mt-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . $esc(__('Contrôles avant export Gesconso', 'printgestion')) . "</h3></div><div class='card-body'>";
        if (!empty($check['errors'])) {
            echo "<div class='alert alert-danger mb-2'><strong>" . $esc(sprintf(
                _n(
                    '%d ligne en défaut : la demande ne peut pas être exportée.',
                    '%d lignes en défaut : la demande ne peut pas être exportée.',
                    count($check['errors']),
                    'printgestion'
                ),
                count($check['errors'])
            )) . "</strong><ul class='mb-0'>";
            foreach (array_merge(...array_values($check['errors'])) as $message) {
                echo "<li>" . $esc($message) . "</li>";
            }
            echo "</ul></div>";
        } else {
            echo "<div class='text-success mb-2'><i class='ti ti-check me-1'></i>"
                . $esc(__('Toutes les lignes peuvent être écrites dans le fichier Gesconso.', 'printgestion')) . "</div>";
            if ($statut === self::STATUS_VALIDATED && self::canUpdate() && $this->canUpdateItem()) {
                echo "<a class='btn btn-sm btn-primary mb-2' href='"
                    . $esc(PLUGIN_PRINTGESTION_WEBDIR . '/front/demande.export.php?' . http_build_query(['demandes' => [(int) $this->getID()]])) . "'>"
                    . "<i class='ti ti-file-export me-1'></i>" . $esc(__('Exporter vers les Achats…', 'printgestion')) . "</a>";
            }
        }
        foreach ($check['warnings'] as $message) {
            echo "<div class='text-warning small'><i class='ti ti-alert-triangle me-1'></i>" . $esc($message) . "</div>";
        }
        echo "</div></div>";
    }

    /** « Enregistrer » et « Valider » (enregistre puis valide, en un clic). */
    private function showValidationButtons(array $lines, array $checks): void {
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        $blocking = 0;
        foreach ($checks as $line_id => $check) {
            if ((string) $lines[$line_id]['statut'] === self::STATUS_PROPOSED && !empty($check['errors'])) {
                $blocking++;
            }
        }

        echo "<div class='card mt-3'><div class='card-body d-flex flex-wrap align-items-center gap-2'>";
        if ($blocking > 0) {
            echo "<div class='text-danger me-auto'><i class='ti ti-ban me-1'></i>" . $esc(sprintf(
                _n(
                    '%d ligne bloquante : corrigez-la ou annulez-la avant de valider.',
                    '%d lignes bloquantes : corrigez-les ou annulez-les avant de valider.',
                    $blocking,
                    'printgestion'
                ),
                $blocking
            )) . "</div>";
        } else {
            echo "<div class='text-muted small me-auto'>"
                . $esc(__('« Valider » enregistre les modifications puis valide la demande ; les contrôles sont refaits au moment de la validation.', 'printgestion'))
                . "</div>";
        }
        echo "<button type='submit' name='update' value='1' class='btn btn-outline-primary'>"
            . "<i class='ti ti-device-floppy me-1'></i>" . $esc(__('Enregistrer', 'printgestion')) . "</button>";
        echo "<button type='submit' name='validate' value='1' class='btn btn-success'>"
            . "<i class='ti ti-check me-1'></i>" . $esc(__('Valider la demande', 'printgestion')) . "</button>";
        echo "</div></div>";
    }

    /** Annulation d'une demande proposée ou validée : motif obligatoire, rien n'est supprimé. */
    private function showCancelCard(): void {
        $esc     = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $confirm = json_encode(
            __('Annuler cette demande ? Ses lignes ouvertes seront annulées et leurs cartouches libérées.', 'printgestion'),
            JSON_UNESCAPED_UNICODE
        );

        echo "<form method='post' action='" . $esc(self::getFormURL()) . "' onsubmit=\"return window.confirm(" . $esc($confirm) . ");\">";
        echo Html::hidden('id', ['value' => (int) $this->getID()]);
        echo "<div class='card mt-3'><div class='card-body'>";
        echo "<h4 class='mb-2'>" . $esc(__('Annuler la demande', 'printgestion')) . "</h4>";
        echo "<p class='text-muted small mb-2'>"
            . $esc(__('Les lignes proposées ou validées sont annulées et libèrent leurs cartouches. Rien n\'est supprimé : la demande reste consultable avec son motif et son historique.', 'printgestion'))
            . "</p>";
        echo "<textarea class='form-control mb-2' name='cancel_reason' rows='2' required maxlength='1000' placeholder='"
            . $esc(__('Motif d\'annulation (obligatoire)', 'printgestion')) . "'></textarea>";
        echo "<button type='submit' name='cancel' value='1' class='btn btn-outline-danger'>"
            . "<i class='ti ti-x me-1'></i>" . $esc(__('Annuler la demande', 'printgestion')) . "</button>";
        echo "</div></div>";
        Html::closeForm();
    }

    private function showHeaderCard(bool $editable): void {
        $esc  = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $dash = "<span class='text-muted'>—</span>";
        $f    = $this->fields;

        $entity = Dropdown::getDropdownName('glpi_entities', (int) $f['entities_id']);
        $site   = (int) $f['locations_id'] > 0 ? Dropdown::getDropdownName('glpi_locations', (int) $f['locations_id']) : '';
        $mode   = self::getDeliveryModeLabels()[(string) $f['delivery_mode']] ?? (string) $f['delivery_mode'];
        $who    = static fn(int $users_id) => $users_id > 0 ? getUserName($users_id) : __('tâche automatique', 'printgestion');

        echo "<div class='card mb-3'>";
        echo "<div class='card-header d-flex align-items-center gap-2'>";
        echo "<h3 class='card-title mb-0'>" . $esc(sprintf(__('Demande d\'envoi #%d', 'printgestion'), $this->getID())) . "</h3>";
        echo self::getStatusBadge((string) $f['statut']);
        echo "</div><div class='card-body'><div class='row g-3'>";

        $field = static function (string $label, string $html, string $col = 'col-md-6 col-xl-4'): void {
            echo "<div class='{$col}'><div class='text-muted small'>"
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</div><div>{$html}</div></div>";
        };

        $field(__('Client', 'printgestion'), $esc($entity));
        $field(
            __('Site de livraison', 'printgestion'),
            $site !== '' ? $esc($site) : "<span class='text-warning'>" . $esc(__('Imprimantes sans lieu', 'printgestion')) . '</span>'
        );
        // Règle Gesconso appliquée à l'entité : d'où viennent le code client et l'intitulé de livraison, et ce qui bloque.
        $field(__('Export Gesconso (Sage)', 'printgestion'), PluginPrintgestionSage::renderRule((int) $f['entities_id']), 'col-12');
        if ($editable) {
            $field(__('Mode de livraison', 'printgestion'), Dropdown::showFromArray(
                'delivery_mode',
                self::getDeliveryModeLabels(),
                ['value' => (string) $f['delivery_mode'], 'display' => false]
            ));
            $field(
                __('Contact de livraison', 'printgestion'),
                "<input type='text' class='form-control' name='contact' maxlength='255' value='" . $esc($f['contact']) . "'>"
            );
        } else {
            $field(__('Mode de livraison', 'printgestion'), $esc($mode));
            $field(__('Contact de livraison', 'printgestion'), trim((string) $f['contact']) !== '' ? $esc($f['contact']) : $dash);
        }
        $field(__('Proposée le', 'printgestion'), $esc(Html::convDateTime((string) $f['date_creation'])));
        if (!empty($f['date_validate'])) {
            $field(__('Validée', 'printgestion'), $esc(sprintf(
                __('le %1$s par %2$s', 'printgestion'),
                Html::convDateTime((string) $f['date_validate']),
                $who((int) $f['users_id_validate'])
            )));
        }
        if (!empty($f['date_cancel'])) {
            $field(__('Annulée', 'printgestion'), $esc(sprintf(
                __('le %1$s par %2$s', 'printgestion'),
                Html::convDateTime((string) $f['date_cancel']),
                $who((int) $f['users_id_cancel'])
            )));
        }
        $field(
            __('Commentaire de livraison', 'printgestion'),
            $editable
                ? "<textarea class='form-control' name='delivery_comment' rows='2'>" . $esc($f['delivery_comment']) . "</textarea>"
                : (trim((string) $f['delivery_comment']) !== '' ? nl2br($esc($f['delivery_comment'])) : $dash),
            'col-12'
        );
        if (trim((string) $f['cancel_reason']) !== '') {
            $field(__('Motif d\'annulation', 'printgestion'), nl2br($esc($f['cancel_reason'])), 'col-12');
        }

        echo "</div></div></div>";
    }

    private function showLinesCard(array $lines, array $checks, bool $editable): void {
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        echo "<div class='card'>";
        echo "<div class='card-header'><h3 class='card-title mb-0'>"
            . $esc(PluginPrintgestionDemandeline::getTypeName(Session::getPluralNumber()))
            . " <span class='badge bg-secondary text-secondary-fg ms-1'>" . count($lines) . "</span></h3></div>";

        if (empty($lines)) {
            echo "<div class='card-body text-muted'>" . $esc(__('Aucune ligne.', 'printgestion')) . "</div></div>";
            return;
        }

        echo "<div class='table-responsive'><table class='table table-sm table-vcenter card-table'>";
        echo "<thead><tr>"
            . "<th>" . $esc(_n('Imprimante', 'Imprimantes', 1, 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Toner', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Cartouche', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('À la proposition', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Contrat', 'printgestion')) . "</th>"
            . "<th class='text-end'>" . $esc(__('Quantité', 'printgestion')) . "</th>"
            . "<th class='text-end'>" . $esc(__('Prix unitaire', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Statut', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Contrôles', 'printgestion')) . "</th>"
            . ($editable ? "<th class='text-center'>" . $esc(__('Annuler', 'printgestion')) . "</th>" : '')
            . "</tr></thead><tbody>";

        foreach ($lines as $line_id => $line) {
            $check = $checks[$line_id] ?? null;

            $printer = $line['printer_name'] !== null
                ? "<a href='" . $esc(Printer::getFormURLWithID((int) $line['printers_id'])) . "'>" . $esc($line['printer_name']) . '</a>'
                : "<span class='text-danger'>#" . (int) $line['printers_id'] . '</span>';
            $printer .= "<div class='small text-muted'>" . $esc(trim((string) $line['printer_serial']))
                . (trim((string) $line['printer_location']) !== '' ? ' · ' . $esc($line['printer_location']) : '') . '</div>';

            $cartridge = (int) $line['cartridgeitems_id'] > 0
                ? $esc(trim((string) $line['cartridge_ref']) . ' — ' . (string) $line['cartridge_name'])
                : "<span class='text-danger'>" . $esc(__('Non résolue', 'printgestion')) . '</span>';

            $proposal = $line['level_at_proposal'] !== null ? (int) $line['level_at_proposal'] . ' %' : '—';
            if ($line['estimated_days'] !== null) {
                $proposal .= ' · ' . sprintf(_n('%d jour', '%d jours', (int) $line['estimated_days'], 'printgestion'), (int) $line['estimated_days']);
            }

            $contract = (int) $line['is_under_contract'] === 1
                ? "<span class='badge bg-green-lt'>" . $esc(__('Sous contrat', 'printgestion')) . '</span>'
                    . ($line['contract_name'] !== null ? "<div class='small text-muted'>" . $esc($line['contract_name']) . '</div>' : '')
                : "<span class='badge bg-yellow-lt'>" . $esc(__('Hors contrat', 'printgestion')) . '</span>';
            if ($check !== null) {
                $contract = "<span title='" . $esc($check['coverage']['message']) . "'>" . $contract . '</span>';
            }

            $price = $line['unit_price'] === null
                ? "<span class='text-muted'>" . $esc(__('vide (Achats)', 'printgestion')) . '</span>'
                : $esc(PluginPrintgestionDemandeline::formatPrice((float) $line['unit_price']));
            $quantity = (string) (int) $line['quantity'];
            $cancel   = '';

            // Ligne proposée modifiable : quantité, prix hors contrat (d'après le contrat en
            // cours maintenant, celui qui s'appliquera à la validation), annulation.
            if ($editable && (string) $line['statut'] === self::STATUS_PROPOSED) {
                $quantity = "<input type='number' class='form-control form-control-sm text-end' style='width:5rem'"
                    . " name='quantity[{$line_id}]' min='1' max='" . self::MAX_QUANTITY . "' step='1' required"
                    . " value='" . (int) $line['quantity'] . "'>";

                $under_now = $check !== null
                    ? (bool) $check['coverage']['under_contract']
                    : (int) $line['is_under_contract'] === 1;
                if ($under_now) {
                    $price = "<input type='text' class='form-control form-control-sm text-end' style='width:7rem' value='0' disabled"
                        . " title='" . $esc(__('Sous contrat : prix 0 imposé', 'printgestion')) . "'>";
                } else {
                    $value = ($line['unit_price'] === null || (float) $line['unit_price'] == 0.0)
                        ? ''
                        : rtrim(rtrim(number_format((float) $line['unit_price'], 4, ',', ''), '0'), ',');
                    $price = "<input type='text' inputmode='decimal' class='form-control form-control-sm text-end' style='width:7rem'"
                        . " name='unit_price[{$line_id}]' value='" . $esc($value) . "'"
                        . " placeholder='" . $esc(__('vide = Achats', 'printgestion')) . "'>";
                }

                $cancel = "<input type='checkbox' class='form-check-input' name='cancel_line[{$line_id}]' value='1'"
                    . " title='" . $esc(__('Annuler cette ligne', 'printgestion')) . "'>";
            }

            if ($check === null) {
                $controls = "<span class='text-muted'>—</span>";
            } elseif (empty($check['errors']) && empty($check['warnings'])) {
                $controls = "<span class='text-success'><i class='ti ti-check me-1'></i>" . $esc(__('OK', 'printgestion')) . '</span>';
            } else {
                $controls = '';
                foreach ($check['errors'] as $message) {
                    $controls .= "<div class='text-danger small'><i class='ti ti-ban me-1'></i>" . $esc($message) . '</div>';
                }
                foreach ($check['warnings'] as $message) {
                    $controls .= "<div class='text-warning small'><i class='ti ti-alert-triangle me-1'></i>" . $esc($message) . '</div>';
                }
            }

            echo "<tr>"
                . "<td>{$printer}</td>"
                . "<td>" . $esc($line['toner_property']) . "</td>"
                . "<td>{$cartridge}</td>"
                . "<td>{$proposal}</td>"
                . "<td>{$contract}</td>"
                . "<td class='text-end'>{$quantity}</td>"
                . "<td class='text-end'>{$price}</td>"
                . "<td>" . self::getStatusBadge((string) $line['statut']) . "</td>"
                . "<td style='min-width:16rem'>{$controls}</td>"
                . ($editable ? "<td class='text-center'>{$cancel}</td>" : '')
                . "</tr>";
        }

        echo "</tbody></table></div></div>";
    }

    // ── Installation ──────────────────────────────────────────────────────────

    // Tables créées par le schéma versionné (PluginPrintgestionSchema, étape 1.3.1).

    static function uninstall(Migration $migration) {
        global $DB;
        $DB->doQuery('DROP TABLE IF EXISTS `glpi_plugin_printgestion_demandelines`');
        $DB->doQuery('DROP TABLE IF EXISTS `glpi_plugin_printgestion_demandes`');
        return true;
    }
}
