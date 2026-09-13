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

    // ── Proposition automatique ───────────────────────────────────────────────

    /**
     * Crée ou complète les demandes PROPOSÉES à partir des alertes toner (tâche
     * automatique), regroupées par client (entité de l'imprimante) et site de livraison
     * (lieu racine).
     *
     * Retenus : toners critiques ou à surveiller (alertes snoozées exclues) sans verrou
     * bloquant — pas d'envoi en cours ni de ligne de demande ouverte, pas de garde après
     * pose ni de ticket récent, sauf contournement (consommation anormale).
     * Pour un client et un site, la demande proposée existante est complétée, sinon une
     * demande est créée. Chaque ligne porte la cartouche résolue (0 si non résolue : la
     * ligne est créée mais bloquée à la validation, jamais exportée sans référence), la
     * couverture contrat et le prix (0 sous contrat, vide hors contrat).
     * Chaque groupe est une transaction : un groupe en échec est annulé en entier,
     * journalisé, et n'empêche pas les autres.
     *
     * @return array ['demandes_created' => int, 'lines_added' => int,
     *                'unresolved' => int, 'failed_groups' => int]
     */
    public static function proposeFromAlerts(): array {
        global $DB;

        $stats = ['demandes_created' => 0, 'lines_added' => 0, 'unresolved' => 0, 'failed_groups' => 0];

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
     * Un groupe (client, site) : complète la demande proposée existante ou en crée une,
     * puis ajoute une ligne par emplacement encore libre. À exécuter en transaction.
     *
     * Les verrous sont réévalués juste avant l'écriture : le calcul des alertes peut être
     * long et une commande a pu être passée entre-temps depuis l'écran des alertes.
     */
    private static function proposeGroup(array $group): array {
        global $DB;

        $result = ['created' => 0, 'lines' => 0, 'unresolved' => 0];

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

        foreach ($rows as $row) {
            $printers_id = (int) $row['printers_id'];
            $property    = (string) $row['property'];
            $ref         = PluginPrintgestionSnmpmapping::resolveCartridge($printers_id, $property);
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
