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

    function showForm($ID, array $options = []) {
        $lines  = $this->getLines();
        $checks = $this->checkLines($lines);

        $this->showHeaderCard();
        $this->showLinesCard($lines, $checks);
        return true;
    }

    private function showHeaderCard(): void {
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
        $field(__('Mode de livraison', 'printgestion'), $esc($mode));
        $field(__('Contact de livraison', 'printgestion'), trim((string) $f['contact']) !== '' ? $esc($f['contact']) : $dash);
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
            trim((string) $f['delivery_comment']) !== '' ? nl2br($esc($f['delivery_comment'])) : $dash,
            'col-12'
        );
        if (trim((string) $f['cancel_reason']) !== '') {
            $field(__('Motif d\'annulation', 'printgestion'), nl2br($esc($f['cancel_reason'])), 'col-12');
        }

        echo "</div></div></div>";
    }

    private function showLinesCard(array $lines, array $checks): void {
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
                . "<td class='text-end'>" . (int) $line['quantity'] . "</td>"
                . "<td class='text-end'>{$price}</td>"
                . "<td>" . self::getStatusBadge((string) $line['statut']) . "</td>"
                . "<td style='min-width:16rem'>{$controls}</td>"
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
