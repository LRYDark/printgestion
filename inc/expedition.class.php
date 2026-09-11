<?php
/**
 * PluginPrintgestionExpedition — gestion du cycle d'expédition des cartouches (Point 4).
 *
 * Statuts : pending → shipped → transit → delivered
 *           (ou stock_empty si aucun stock au déclenchement)
 *
 * Empêche le double envoi : tant qu'une expédition est active
 * (pending/shipped/transit), aucune nouvelle alerte d'expédition n'est créée.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionExpedition extends CommonDBTM {

    static $rightname = 'plugin_printgestion_expedition';

    const STATUS_PENDING     = 'pending';
    const STATUS_SHIPPED     = 'shipped';
    const STATUS_TRANSIT     = 'transit';
    const STATUS_DELIVERED   = 'delivered';
    const STATUS_STOCK_EMPTY = 'stock_empty';

    /** Nombre max de lignes listées dans le corps d'un mail groupé/digest ;
     *  au-delà : « … et N autres » (le détail complet reste dans l'Excel joint). */
    const MAIL_LIST_MAX = 20;

    static function getTypeName($nb = 0) {
        return _n('Expédition cartouche', 'Expéditions cartouches', $nb, 'printgestion');
    }

    public static function getTable($classname = null) {
        if ($classname === null || $classname === static::class) {
            return 'glpi_plugin_printgestion_expeditions';
        }
        return parent::getTable($classname);
    }

    // ── Intégration moteur Search natif (Phase 2) ─────────────────────────────

    public static function canView(): bool {
        return Session::haveRight('plugin_printgestion_expedition', READ);
    }

    public static function canCreate(): bool {
        return Session::haveRight('plugin_printgestion_expedition', CREATE);
    }

    static function getFormURL($full = true) {
        $dir = $full ? PLUGIN_PRINTGESTION_WEBDIR : PLUGIN_PRINTGESTION_NOTFULL_WEBDIR;
        return $dir . '/front/expedition.form.php';
    }

    static function getFormURLWithID($id = 0, $full = true) {
        return self::getFormURL($full) . '?id=' . (int) $id;
    }

    /**
     * URL de la liste : indispensable pour que la pagination / le tri / le
     * formulaire de recherche natifs pointent vers le dashboard (sinon GLPI
     * génère front/expedition.php, inexistant → 404 en page 2 ou au tri).
     */
    static function getSearchURL($full = true) {
        $dir = $full ? PLUGIN_PRINTGESTION_WEBDIR : PLUGIN_PRINTGESTION_NOTFULL_WEBDIR;
        return $dir . '/front/dashboard_expeditions.php';
    }

    /**
     * Options de recherche pour le moteur Search natif. Permet recherche / tri /
     * filtres / choix de colonnes / export sur les expéditions.
     */
    public function rawSearchOptions() {
        $tab = [];

        $tab[] = ['id' => 'common', 'name' => __('Expédition', 'printgestion')];

        $tab[] = [
            'id'            => '1',
            'table'         => self::getTable(),
            'field'         => 'id',
            'name'          => __('ID'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '2',
            'table'         => 'glpi_printers',
            'field'         => 'name',
            'name'          => _n('Imprimante', 'Imprimantes', 1, 'printgestion'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '80',
            'table'         => 'glpi_entities',
            'field'         => 'completename',
            'name'          => Entity::getTypeName(1),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
            'joinparams'    => [
                'beforejoin' => ['table' => 'glpi_printers', 'joinparams' => ['jointype' => '']],
            ],
        ];
        $tab[] = [
            'id'       => '3',
            'table'    => self::getTable(),
            'field'    => 'toner_property',
            'name'     => __('Toner', 'printgestion'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => '4',
            'table'    => self::getTable(),
            'field'    => 'toner_color',
            'name'     => __('Couleur', 'printgestion'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'            => '5',
            'table'         => self::getTable(),
            'field'         => 'statut',
            'name'          => __('Statut', 'printgestion'),
            'datatype'      => 'specific',   // badge coloré (getSpecificValueToDisplay)
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '6',
            'table'         => self::getTable(),
            'field'         => 'transport_carrier',
            'name'          => __('Transporteur', 'printgestion'),
            'datatype'      => 'specific',   // libellé transporteur (getSpecificValueToDisplay)
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'       => '7',
            'table'    => self::getTable(),
            'field'    => 'transport_number',
            'name'     => __('N° de suivi', 'printgestion'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => '8',
            'table'    => self::getTable(),
            'field'    => 'level_at_alert',
            'name'     => __('Niveau à l\'alerte (%)', 'printgestion'),
            'datatype' => 'number',
        ];
        $tab[] = [
            'id'       => '9',
            'table'    => self::getTable(),
            'field'    => 'estimated_days',
            'name'     => __('Jours estimés', 'printgestion'),
            'datatype' => 'number',
        ];
        $tab[] = [
            'id'       => '10',
            'table'    => self::getTable(),
            'field'    => 'date_alert',
            'name'     => __('Date alerte', 'printgestion'),
            'datatype' => 'datetime',
        ];
        $tab[] = [
            'id'       => '11',
            'table'    => self::getTable(),
            'field'    => 'date_shipped',
            'name'     => __('Date expédition', 'printgestion'),
            'datatype' => 'datetime',
        ];
        $tab[] = [
            'id'       => '12',
            'table'    => self::getTable(),
            'field'    => 'date_delivered',
            'name'     => __('Date livraison', 'printgestion'),
            'datatype' => 'datetime',
        ];
        $tab[] = [
            'id'       => '13',
            'table'    => self::getTable(),
            'field'    => 'notes',
            'name'     => _n('Note', 'Notes', Session::getPluralNumber(), 'printgestion'),
            'datatype' => 'text',
        ];

        return $tab;
    }

    /**
     * Rendu HTML spécifique des colonnes du tableau natif (badges « comme avant »).
     * - statut          → badge coloré + pont caché (data-expid) pour le menu clic droit.
     * - transport_carrier → libellé transporteur en pastille.
     */
    static function getSpecificValueToDisplay($field, $values, array $options = []) {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        switch ($field) {
            case 'statut':
                $v   = (string) ($values[$field] ?? '');
                $map = [
                    'pending'     => ['bg-secondary', __('En attente', 'printgestion')],
                    'shipped'     => ['bg-primary',   __('Expédiée', 'printgestion')],
                    'transit'     => ['bg-info',      __('En transit', 'printgestion')],
                    'delivered'   => ['bg-success',   __('Livrée', 'printgestion')],
                    'stock_empty' => ['text-bg-dark', __('Stock vide', 'printgestion')],
                ];
                [$cls, $label] = $map[$v] ?? ['bg-light text-dark border', ($v !== '' ? $v : '—')];
                $out = "<span class='badge {$cls}'>" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</span>";

                // Pont pour le menu clic droit : expose l'id de l'expédition sur la
                // ligne native (le JS du dashboard recopie ensuite les data-pc-* sur le <tr>).
                $rawid = (int) ($options['raw_data']['id'] ?? 0);
                if ($rawid > 0) {
                    $out .= "<span class='pg-exp-bridge' data-expid='{$rawid}' style='display:none'></span>";
                }
                return $out;

            case 'transport_carrier':
                $v = trim((string) ($values[$field] ?? ''));
                if ($v === '') {
                    return "<span class='text-muted'>—</span>";
                }
                return "<span class='badge bg-light text-dark border'>"
                    . htmlspecialchars(strtoupper($v), ENT_QUOTES, 'UTF-8') . "</span>";
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Liste paginée + filtrée + triée pour le dashboard.
     * Retourne ['rows' => [...], 'total' => N].
     */
    public static function listPaged(array $params): array {
        global $DB;

        $page     = max(1, (int)($params['page'] ?? 1));
        $per_page = max(1, min(500, (int)($params['per_page'] ?? 25)));
        $search   = trim((string)($params['search'] ?? ''));
        $sort_col = (string)($params['sort_col'] ?? 'date_alert');
        $sort_dir = strtolower((string)($params['sort_dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $entities_id = (isset($params['entities_id']) && $params['entities_id'] !== '' && (int)$params['entities_id'] >= 0)
            ? (int)$params['entities_id'] : null;
        $statut   = (string)($params['statut'] ?? 'all');
        $start    = (string)($params['start'] ?? '');
        $end      = (string)($params['end']   ?? '');

        $where = [];
        if ($entities_id !== null) {
            $where['p.entities_id'] = $entities_id;
        }
        if (in_array($statut, ['pending','shipped','transit','delivered','stock_empty'], true)) {
            $where['e.statut'] = $statut;
        } elseif ($statut === 'active') {
            $where['e.statut'] = ['pending','shipped','transit','stock_empty'];
        }
        if ($start !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $where[] = ['e.date_alert' => ['>=', $start . ' 00:00:00']];
        }
        if ($end !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            $where[] = ['e.date_alert' => ['<=', $end . ' 23:59:59']];
        }
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = ['OR' => [
                ['p.name'             => ['LIKE', $like]],
                ['ent.completename'   => ['LIKE', $like]],
                ['e.toner_property'   => ['LIKE', $like]],
                ['e.transport_number' => ['LIKE', $like]],
                ['e.transport_carrier'=> ['LIKE', $like]],
            ]];
        }

        $sort_map = [
            'printer_name'  => 'p.name',
            'entity_name'   => 'ent.completename',
            'property'      => 'e.toner_property',
            'statut'        => 'e.statut',
            'carrier'       => 'e.transport_carrier',
            'tracking'      => 'e.transport_number',
            'date_alert'    => 'e.date_alert',
            'date_shipped'  => 'e.date_shipped',
            'date_delivered'=> 'e.date_delivered',
        ];
        $order_col = $sort_map[$sort_col] ?? 'e.date_alert';

        $base = [
            'FROM'      => self::getTable() . ' AS e',
            'LEFT JOIN' => [
                'glpi_printers AS p' => [
                    'ON' => ['e' => 'printers_id', 'p' => 'id'],
                ],
                'glpi_entities AS ent' => [
                    'ON' => ['p' => 'entities_id', 'ent' => 'id'],
                ],
            ],
            'WHERE' => $where,
        ];

        $total_row = $DB->request(array_merge($base, [
            'COUNT' => 'cnt',
        ]))->current();
        $total = is_array($total_row) ? (int)($total_row['cnt'] ?? 0) : 0;

        $rows = [];
        $exp_ids = [];
        foreach ($DB->request(array_merge($base, [
            'SELECT' => [
                'e.*',
                'p.name AS printer_name',
                'p.entities_id',
                'ent.completename AS entity_name',
            ],
            'ORDER' => [$order_col . ' ' . $sort_dir],
            'START' => ($page - 1) * $per_page,
            'LIMIT' => $per_page,
        ])) as $r) {
            $rows[] = [
                'id'               => (int)$r['id'],
                'printers_id'      => (int)$r['printers_id'],
                'printer_name'     => (string)($r['printer_name'] ?? ''),
                'entity_name'      => (string)($r['entity_name'] ?? ''),
                'toner_property'   => (string)($r['toner_property'] ?? ''),
                'statut'           => (string)$r['statut'],
                'transport_carrier'=> (string)($r['transport_carrier'] ?? ''),
                'transport_number' => (string)($r['transport_number'] ?? ''),
                'date_alert'       => (string)($r['date_alert'] ?? ''),
                'date_shipped'     => (string)($r['date_shipped'] ?? ''),
                'date_delivered'   => (string)($r['date_delivered'] ?? ''),
                'level_at_alert'   => (int)($r['level_at_alert'] ?? 0),
                'bl_surveys_id'    => (int)($r['bl_surveys_id'] ?? 0),
                'bls'              => [],
            ];
            $exp_ids[] = (int)$r['id'];
        }

        // Résolution des BL associés (N:N + rétro-compat bl_surveys_id).
        // Un seul aller-retour DB : batch query sur toutes les expéditions
        // de la page, puis dispatch dans les rows.
        if (!empty($exp_ids) && $DB->tableExists('glpi_plugin_gestion_surveys')) {
            $by_exp = [];
            // 1. Liens N:N
            if ($DB->tableExists('glpi_plugin_printgestion_expedition_bls')) {
                foreach ($DB->request([
                    'SELECT'    => ['eb.expeditions_id', 's.id', 's.bl', 's.signed'],
                    'FROM'      => 'glpi_plugin_printgestion_expedition_bls AS eb',
                    'INNER JOIN'=> [
                        'glpi_plugin_gestion_surveys AS s' => [
                            'ON' => ['eb' => 'bl_surveys_id', 's' => 'id'],
                        ],
                    ],
                    'WHERE'     => ['eb.expeditions_id' => $exp_ids],
                ]) as $b) {
                    $eid = (int)$b['expeditions_id'];
                    if (!isset($by_exp[$eid])) $by_exp[$eid] = [];
                    $by_exp[$eid][(int)$b['id']] = [
                        'id'     => (int)$b['id'],
                        'bl'     => (string)$b['bl'],
                        'signed' => (int)$b['signed'],
                    ];
                }
            }
            // 2. Rétro-compat bl_surveys_id (principal)
            foreach ($rows as $idx => $r) {
                $principal = (int)$r['bl_surveys_id'];
                if ($principal <= 0) continue;
                if (isset($by_exp[$r['id']][$principal])) continue; // déjà dans N:N
                $bl_row = $DB->request([
                    'SELECT' => ['id', 'bl', 'signed'],
                    'FROM'   => 'glpi_plugin_gestion_surveys',
                    'WHERE'  => ['id' => $principal],
                    'LIMIT'  => 1,
                ])->current();
                if (is_array($bl_row)) {
                    if (!isset($by_exp[$r['id']])) $by_exp[$r['id']] = [];
                    $by_exp[$r['id']][$principal] = [
                        'id'     => (int)$bl_row['id'],
                        'bl'     => (string)$bl_row['bl'],
                        'signed' => (int)$bl_row['signed'],
                    ];
                }
            }
            // Injecte dans les rows
            foreach ($rows as &$r) {
                $r['bls'] = isset($by_exp[$r['id']]) ? array_values($by_exp[$r['id']]) : [];
            }
            unset($r);
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Retourne l'expédition active (non livrée) pour une imprimante + propriété.
     */
    public static function getActiveForPrinterProperty(int $printers_id, string $property): ?array {
        global $DB;

        $row = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'printers_id'    => $printers_id,
                'toner_property' => $property,
                'statut'         => ['pending', 'shipped', 'transit', 'stock_empty'],
            ],
            'ORDER' => ['id DESC'],
            'LIMIT' => 1,
        ])->current();

        return is_array($row) ? $row : null;
    }

    /**
     * Crée une expédition + envoie les mails associés.
     * @param string $reason 'normal' (stock ok) ou 'stock_empty' (achats prévenus).
     * @param bool $send_mail Si false, skip l'envoi mail (utilisé par createGroup).
     * @param string|null $group_id UUID partagé quand l'expédition appartient à un envoi groupé.
     */
    public static function createFromAlert(
        int $printers_id,
        string $property,
        int $level,
        ?int $estimated_days,
        string $reason = 'normal',
        bool $send_mail = true,
        ?string $group_id = null
    ): int {
        global $DB;

        // Empêcher les doublons
        $existing = self::getActiveForPrinterProperty($printers_id, $property);
        if ($existing !== null) {
            return (int)$existing['id'];
        }

        $mapping = PluginPrintgestionSnmpmapping::resolveForPrinter($printers_id, $property);
        $color   = is_array($mapping) ? (string)($mapping['toner_color'] ?? 'other') : 'other';

        $statut = ($reason === 'stock_empty')
            ? self::STATUS_STOCK_EMPTY
            : self::STATUS_PENDING;

        $DB->insert(self::getTable(), [
            'printers_id'    => $printers_id,
            'toner_property' => $property,
            'toner_color'    => $color,
            'statut'         => $statut,
            'level_at_alert' => $level,
            'estimated_days' => $estimated_days,
            'date_alert'     => date('Y-m-d H:i:s'),
            'users_id_tech'  => (int)(Session::getLoginUserID() ?: 0),
            'group_id'       => $group_id,
        ]);

        $expedition_id = (int)$DB->insertId();

        if ($send_mail) {
            self::dispatchMailsForNew($expedition_id, $reason);
        }

        return $expedition_id;
    }

    /**
     * Crée un envoi groupé multi-cartouches pour une même imprimante.
     *
     * Items attendus : [['property' => 'tonerblack', 'level' => 5, 'days' => 3], ...]
     * - Génère un group_id (UUID) partagé par toutes les expéditions créées.
     * - Pour chaque item : résout le stock → crée expédition 'pending' (stock OK)
     *   ou 'stock_empty' (sinon). Pas de mail par expédition.
     * - À la fin : envoie 1 mail planif groupé (cartouches avec stock) + 1 mail
     *   achat groupé si au moins une cartouche a stock vide.
     *
     * @return array ['group_id'=>string, 'expeditions'=>array, 'ok_count'=>int, 'empty_count'=>int, 'skipped_count'=>int]
     */
    public static function createGroup(int $printers_id, array $items): array {
        if ($printers_id <= 0 || empty($items)) {
            return ['group_id' => '', 'expeditions' => [], 'ok_count' => 0, 'empty_count' => 0, 'skipped_count' => 0];
        }

        $group_id = self::generateUuid();

        $expeditions  = [];
        $ok_count     = 0;
        $empty_count  = 0;
        $skipped      = 0;

        foreach ($items as $item) {
            $property = trim((string)($item['property'] ?? ''));
            if ($property === '') {
                continue;
            }
            $level = (int)($item['level'] ?? 0);
            $days  = isset($item['days']) ? (int)$item['days'] : null;
            if ($days !== null && $days <= 0) {
                $days = null;
            }

            // Anti-doublon : expé active existante ? on skip (pas de mail, pas de création)
            if (self::getActiveForPrinterProperty($printers_id, $property) !== null) {
                $skipped++;
                continue;
            }

            $cartridgeitems_id = PluginPrintgestionSnmpmapping::resolveCartridgeItemForSnmp($printers_id, $property);
            $stock  = self::getCartridgeStock($cartridgeitems_id);
            $reason = $stock > 0 ? 'normal' : 'stock_empty';

            $exp_id = self::createFromAlert(
                $printers_id,
                $property,
                $level,
                $days,
                $reason,
                false,     // send_mail = false : on enverra 1 mail groupé à la fin
                $group_id
            );

            if ($exp_id > 0) {
                $expeditions[] = ['id' => $exp_id, 'property' => $property, 'reason' => $reason, 'stock' => $stock];
                if ($reason === 'stock_empty') {
                    $empty_count++;
                } else {
                    $ok_count++;
                }
            }
        }

        if (!empty($expeditions)) {
            self::dispatchGroupMails($group_id);
        }

        return [
            'group_id'      => $group_id,
            'expeditions'   => $expeditions,
            'ok_count'      => $ok_count,
            'empty_count'   => $empty_count,
            'skipped_count' => $skipped,
        ];
    }

    /**
     * Envoie les mails d'un envoi groupé :
     *   - 1 mail planif avec la liste des cartouches à stock OK (si au moins 1)
     *   - 1 mail achat avec la liste des cartouches à stock vide (si au moins 1)
     *   - 1 mail commercial informatif (idem planif : liste des cartouches en cours d'envoi)
     *
     * Fallback : si gabarit_planif_group n'est pas défini, utilise gabarit_planif.
     */
    protected static function dispatchGroupMails(string $group_id): void {
        global $DB;

        if ($group_id === '') {
            return;
        }

        $rows = [];
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['group_id' => $group_id],
            'ORDER' => ['id ASC'],
        ]) as $r) {
            $rows[] = $r;
        }
        if (empty($rows)) {
            return;
        }

        $printers_id = (int)$rows[0]['printers_id'];
        $printer = new Printer();
        if (!$printer->getFromDB($printers_id)) {
            return;
        }

        $entity_name = '';
        $entity = new Entity();
        if ($entity->getFromDB((int)$printer->fields['entities_id'])) {
            $entity_name = (string)$entity->fields['completename'];
        }

        $contracts_id  = PluginPrintgestionContractrate::getContractIdForPrinter($printers_id);
        $contract_name = '';
        if ($contracts_id > 0) {
            $c = new Contract();
            if ($c->getFromDB($contracts_id)) {
                $contract_name = (string)$c->fields['name'];
            }
        }

        // Classe par reason (stock OK vs stock vide) + enrichit chaque entrée
        $ok_items    = [];
        $empty_items = [];
        $toners_csv_ok = [];

        foreach ($rows as $r) {
            $property = (string)$r['toner_property'];
            $cartridgeitems_id = PluginPrintgestionSnmpmapping::resolveCartridgeItemForSnmp($printers_id, $property);
            $cartridge_label   = PluginPrintgestionSnmpmapping::getCartridgeLabelForProperty($printers_id, $property);
            $stock             = self::getCartridgeStock($cartridgeitems_id);

            $entry = [
                'property'  => $property,
                'level'     => (int)($r['level_at_alert'] ?? 0),
                'days'      => $r['estimated_days'] !== null ? (int)$r['estimated_days'] : null,
                'cartridge' => $cartridge_label,
                'stock'     => $stock,
            ];

            if ((string)$r['statut'] === self::STATUS_STOCK_EMPTY) {
                $empty_items[] = $entry;
            } else {
                $ok_items[] = $entry;
                $toners_csv_ok[] = $property;
            }
        }

        $config = PluginPrintgestionConfig::getInstance();

        // Email du demandeur (users_id_tech identique pour toutes les expés d'un groupe)
        // → CC de tous les mails déclenchés par l'envoi groupé
        $requester_emails = PluginPrintgestionAlert::resolveEmailsForUsers([(int)($rows[0]['users_id_tech'] ?? 0)]);

        // ── Mail planif (stock OK) : simple (1 cartouche) ou multi ──
        if (!empty($ok_items)) {
            $planif_xlsx = null;
            if (count($ok_items) === 1) {
                // Envoi SIMPLE : gabarit unitaire avec le détail de la cartouche
                $it = $ok_items[0];
                $balises = [
                    '##printgestion.printer##'   => (string)$printer->fields['name'],
                    '##printgestion.client##'    => $entity_name,
                    '##printgestion.contract##'  => $contract_name,
                    '##printgestion.toner##'     => (string)$it['property'],
                    '##printgestion.level##'     => (string)(int)$it['level'],
                    '##printgestion.days##'      => $it['days'] !== null ? (string)(int)$it['days'] : 'N/A',
                    '##printgestion.cartridge##' => (string)$it['cartridge'],
                    '##printgestion.stock##'     => (string)(int)$it['stock'],
                    '##printgestion.count##'     => '1',
                ];
                $gabarit = (int)($config->fields['gabarit_planif'] ?? 0);
                if ($gabarit <= 0) {
                    $gabarit = (int)($config->fields['gabarit_planif_group'] ?? 0);
                }
            } else {
                // Envoi MULTI : gabarit groupé avec la liste des cartouches
                $balises = self::buildGroupBalises(
                    (string)$printer->fields['name'],
                    $entity_name,
                    $contract_name,
                    $ok_items,
                    $toners_csv_ok
                );
                $gabarit = (int)($config->fields['gabarit_planif_group'] ?? 0);
                if ($gabarit <= 0) {
                    $gabarit = (int)($config->fields['gabarit_planif'] ?? 0); // fallback ancien gabarit
                }

                // Détail complet en pièce jointe Excel (la liste du corps est plafonnée)
                $planif_rows = [];
                foreach ($ok_items as $it) {
                    $planif_rows[] = self::buildPurchaseRowData($printers_id, (string)$it['property']);
                }
                $planif_xlsx = self::buildPurchaseExcel($planif_rows);
            }

            $planif_mail = PluginPrintgestionAlert::resolveRecipientsForRole('planif');
            if (!empty($planif_mail) && $gabarit > 0) {
                PluginPrintgestionConfig::sendMail(
                    array_merge($planif_mail, $requester_emails),
                    $gabarit,
                    $balises,
                    $planif_xlsx
                );
            }
            if ($planif_xlsx !== null && is_file($planif_xlsx)) {
                @unlink($planif_xlsx);
            }

            // Mail commercial informatif (même contenu que planif)
            $commercial_mail = PluginPrintgestionAlert::resolveRecipientsForRole('commercial');
            $gab_com = (int)($config->fields['gabarit_commercial'] ?? 0);
            if (!empty($commercial_mail) && $gab_com > 0) {
                PluginPrintgestionConfig::sendMail(
                    array_merge($commercial_mail, $requester_emails),
                    $gab_com,
                    $balises
                );
            }
        }

        // ── Mail achat (stock vide) : commande avec fichier Excel joint ──
        if (!empty($empty_items)) {
            $purchase_rows = [];
            foreach ($empty_items as $it) {
                $purchase_rows[] = self::buildPurchaseRowData($printers_id, (string)$it['property']);
            }
            self::sendPurchaseOrderMail($purchase_rows, (int)($rows[0]['users_id_tech'] ?? 0));
        }
    }

    /**
     * Construit les balises mail pour un envoi groupé.
     * - `##printgestion.cartridges_list##` : liste HTML détaillée (ul/li)
     * - Balises singulières (`toner`, `level`, `cartridge`) : CSV / agrégat pour compat gabarit legacy
     */
    protected static function buildGroupBalises(
        string $printer_name,
        string $entity_name,
        string $contract_name,
        array $items,
        array $toners_csv
    ): array {
        // Agrégats calculés sur TOUS les items ; liste plafonnée à MAIL_LIST_MAX
        $level_min  = PHP_INT_MAX;
        $days_min   = null;
        $cartridges = [];
        foreach ($items as $it) {
            $lvl = (int)$it['level'];
            if ($lvl < $level_min) $level_min = $lvl;
            if ($it['days'] !== null) {
                $days_min = ($days_min === null) ? (int)$it['days'] : min($days_min, (int)$it['days']);
            }
            $cartridges[] = (string)$it['cartridge'];
        }

        $list_html = '<ul>';
        foreach (array_slice($items, 0, self::MAIL_LIST_MAX) as $it) {
            $days_txt = $it['days'] !== null ? ($it['days'] . ' j') : 'N/A';
            $stock_txt = ((int)$it['stock'] > 0)
                ? ('stock: ' . (int)$it['stock'])
                : ('<strong style="color:#c00">stock vide</strong>');
            $list_html .= '<li>'
                . '<strong>' . htmlspecialchars((string)$it['cartridge'], ENT_QUOTES, 'UTF-8') . '</strong>'
                . ' — ' . htmlspecialchars((string)$it['property'], ENT_QUOTES, 'UTF-8')
                . ' — ' . (int)$it['level'] . '% — ' . htmlspecialchars($days_txt, ENT_QUOTES, 'UTF-8')
                . ' — ' . $stock_txt
                . '</li>';
        }
        if (count($items) > self::MAIL_LIST_MAX) {
            $list_html .= '<li>… et ' . (count($items) - self::MAIL_LIST_MAX)
                . ' autres — détail complet dans le fichier Excel joint</li>';
        }
        $list_html .= '</ul>';

        if ($level_min === PHP_INT_MAX) {
            $level_min = 0;
        }

        return [
            '##printgestion.printer##'         => $printer_name,
            '##printgestion.client##'          => $entity_name,
            '##printgestion.contract##'        => $contract_name,
            '##printgestion.toner##'           => implode(', ', $toners_csv),
            '##printgestion.level##'           => (string)$level_min,
            '##printgestion.days##'            => $days_min !== null ? (string)$days_min : 'N/A',
            '##printgestion.cartridge##'       => implode(', ', $cartridges),
            '##printgestion.cartridges_list##' => $list_html,
            '##printgestion.count##'           => (string)count($items),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  COMMANDE DE CARTOUCHES (mail achats + Excel) — mono / multi-imprimantes
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Assemble les données d'UNE cartouche pour une ligne du fichier Excel achats.
     * Mapping (format Gesconso) :
     *   - Intitulé Client     = nom de l'entité de l'imprimante
     *   - Intitulé Livraison  = site (racine du lieu hiérarchique de l'imprimante)
     *   - Consommable         = référence (ref) du modèle de cartouche GLPI
     *   - Designation         = "n° série imprimante # lieu (pièce) # nom du modèle"
     *   - Complément livraison= commentaire du lieu de l'imprimante
     *   - Stock GLPI          = nb de cartouches non utilisées en stock
     */
    protected static function buildPurchaseRowData(int $printers_id, string $property): array {
        global $DB;

        $serial = $entity_name = $loc_site = $loc_leaf = $loc_comment = $printer_name = '';

        $printer = new Printer();
        if ($printer->getFromDB($printers_id)) {
            $serial       = (string)($printer->fields['serial'] ?? '');
            $printer_name = (string)($printer->fields['name'] ?? '');

            $entity = new Entity();
            if ($entity->getFromDB((int)$printer->fields['entities_id'])) {
                $entity_name = (string)($entity->fields['name'] ?? '');
            }

            $loc_id = (int)($printer->fields['locations_id'] ?? 0);
            if ($loc_id > 0) {
                $location = new Location();
                if ($location->getFromDB($loc_id)) {
                    $loc_leaf    = (string)($location->fields['name'] ?? '');
                    $loc_comment = (string)($location->fields['comment'] ?? '');
                    $completename = (string)($location->fields['completename'] ?? '');
                    // completename GLPI = "Site > … > Pièce" → racine = site de livraison
                    $loc_site = $completename !== '' ? trim(explode(' > ', $completename)[0]) : $loc_leaf;
                }
            }
        }

        // Cartouche : ref (Consommable) + nom (description) + stock
        $cartridgeitems_id = PluginPrintgestionSnmpmapping::resolveCartridgeItemForSnmp($printers_id, $property);
        $cart_ref = $cart_name = '';
        if ($cartridgeitems_id > 0) {
            $ci = $DB->request([
                'SELECT' => ['ref', 'name'],
                'FROM'   => 'glpi_cartridgeitems',
                'WHERE'  => ['id' => $cartridgeitems_id],
                'LIMIT'  => 1,
            ])->current();
            if (is_array($ci)) {
                $cart_ref  = (string)($ci['ref'] ?? '');
                $cart_name = (string)($ci['name'] ?? '');
            }
        }
        if ($cart_name === '') {
            $cart_name = PluginPrintgestionSnmpmapping::getCartridgeLabelForProperty($printers_id, $property);
        }
        $stock = self::getCartridgeStock($cartridgeitems_id);

        return [
            'devis'        => date('d/m/Y'),
            'client'       => $entity_name,
            'livraison'    => $loc_site,
            'consommable'  => $cart_ref,
            'designation'  => trim($serial) . ' # ' . trim($loc_leaf) . ' # ' . trim($cart_name),
            'quantite'     => 1,
            'prix'         => 0,
            'fournisseur'  => '',
            'complement'   => $loc_comment,
            'stock'        => $stock,
            // Champs annexes (non écrits dans l'Excel) réutilisés pour les mails planif/courtoisie.
            'printers_id'   => $printers_id,
            'printer_name'  => $printer_name,
            'cartridge_name'=> $cart_name,
            'property'      => $property,
        ];
    }

    /**
     * Génère le fichier Excel de commande (1 cartouche par ligne, format Gesconso
     * + colonne « Stock GLPI »). Retourne le chemin du fichier temporaire créé.
     */
    public static function buildPurchaseExcel(array $rows): string {
        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Export');

        $headers = [
            'A' => 'Devis', 'B' => 'Intitule Client', 'C' => 'Intitule Livraison',
            'D' => 'Consommable', 'E' => 'Designation', 'F' => 'Quantite',
            'G' => 'Prix', 'H' => 'Fournisseur', 'I' => 'Complement livraison', 'J' => 'Stock GLPI',
        ];
        foreach ($headers as $col => $label) {
            $sheet->setCellValue($col . '1', $label);
        }

        $rownum = 2;
        foreach ($rows as $row) {
            $sheet->setCellValue('A' . $rownum, (string)$row['devis']);
            $sheet->setCellValue('B' . $rownum, (string)$row['client']);
            $sheet->setCellValue('C' . $rownum, (string)$row['livraison']);
            $sheet->setCellValueExplicit('D' . $rownum, (string)$row['consommable'],
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('E' . $rownum, (string)$row['designation'],
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('F' . $rownum, (int)$row['quantite']);
            $sheet->setCellValue('G' . $rownum, (int)$row['prix']);
            $sheet->setCellValue('H' . $rownum, (string)$row['fournisseur']);
            $sheet->setCellValue('I' . $rownum, (string)$row['complement']);
            $sheet->setCellValue('J' . $rownum, (int)$row['stock']);
            $rownum++;
        }

        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $dir   = defined('GLPI_TMP_DIR') ? GLPI_TMP_DIR : sys_get_temp_dir();
        $fname = 'Commande_cartouches_' . date('dmY_Hi') . '_' . bin2hex(random_bytes(4)) . '.xlsx';
        $path  = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $fname;

        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save($path);

        return $path;
    }

    /**
     * Crée une COMMANDE de cartouches (mono OU multi-imprimantes) :
     *   - 1 expédition par cartouche (statut pending/stock_empty, group_id commun)
     *   - 1 fichier Excel (1 cartouche/ligne) envoyé aux ACHATS, demandeur en copie,
     *     corps de mail générique (tout le détail est dans l'Excel).
     *
     * @param array $items [['printers_id'=>int,'property'=>string,'level'=>int,'days'=>?int], ...]
     * @return array ['ok'=>bool,'created'=>int,'skipped'=>int,'mail'=>bool,'rows'=>int]
     */
    public static function createPurchaseOrder(array $items, bool $send_planif = false, bool $send_courtesy = true): array {
        if (empty($items)) {
            return ['ok' => false, 'created' => 0, 'skipped' => 0, 'mail' => false, 'rows' => 0];
        }

        $group_id = self::generateUuid();
        $created  = 0;
        $skipped  = 0;
        $rows     = [];

        foreach ($items as $item) {
            $printers_id = (int)($item['printers_id'] ?? 0);
            $property    = trim((string)($item['property'] ?? ''));
            if ($printers_id <= 0 || $property === '') {
                continue;
            }
            $level = (int)($item['level'] ?? 0);
            $days  = (isset($item['days']) && $item['days'] !== null) ? (int)$item['days'] : null;
            if ($days !== null && $days <= 0) {
                $days = null;
            }

            // Anti-doublon : si une expédition active existe déjà, on ne recrée pas
            // d'expédition mais on liste quand même la cartouche dans la commande.
            if (self::getActiveForPrinterProperty($printers_id, $property) !== null) {
                $skipped++;
            } else {
                $cartridgeitems_id = PluginPrintgestionSnmpmapping::resolveCartridgeItemForSnmp($printers_id, $property);
                $reason = self::getCartridgeStock($cartridgeitems_id) > 0 ? 'normal' : 'stock_empty';
                if (self::createFromAlert($printers_id, $property, $level, $days, $reason, false, $group_id) > 0) {
                    $created++;
                }
            }

            $row = self::buildPurchaseRowData($printers_id, $property);
            // Niveau / jours restants : utilisés par le mail planif (envoi simple)
            $row['level'] = $level;
            $row['days']  = $days;
            $rows[] = $row;
        }

        if (empty($rows)) {
            return ['ok' => false, 'created' => $created, 'skipped' => $skipped, 'mail' => false, 'rows' => 0];
        }

        $mail_ok = self::sendPurchaseOrderMail($rows);

        // Mail Planif (logistique) — uniquement si demandé (case cochée côté UI).
        if ($send_planif) {
            self::sendOrderPlanifMail($rows);
        }
        // Mail de courtoisie client — uniquement si demandé (case cochée côté UI).
        if ($send_courtesy) {
            self::sendOrderCourtesyMails($rows);
        }

        if (class_exists('PluginPrintgestionAlert')) {
            PluginPrintgestionAlert::invalidateCache();
        }

        return ['ok' => true, 'created' => $created, 'skipped' => $skipped, 'mail' => $mail_ok, 'rows' => count($rows)];
    }

    /**
     * Balises agrégées pour le mail de commande achats (gabarit_achat).
     * Le détail ligne par ligne est dans l'Excel joint ; le corps reste synthétique.
     */
    protected static function buildPurchaseBalises(array $rows): array {
        $uniq = function (string $key) use ($rows): array {
            $vals = [];
            foreach ($rows as $r) {
                $v = trim((string)($r[$key] ?? ''));
                if ($v !== '') {
                    $vals[$v] = $v;
                }
            }
            return array_values($vals);
        };
        $clients  = $uniq('client');
        $printers = $uniq('printer_name');
        $carts    = $uniq('cartridge_name');

        return [
            '##printgestion.count##'     => (string)count($rows),
            '##printgestion.client##'    => count($clients) > 1
                ? sprintf(__('%d clients', 'printgestion'), count($clients))
                : (string)($clients[0] ?? ''),
            '##printgestion.printer##'   => count($printers) > 1
                ? sprintf(__('%d imprimantes', 'printgestion'), count($printers))
                : (string)($printers[0] ?? ''),
            '##printgestion.cartridge##' => implode(', ', $carts),
            '##printgestion.stock##'     => count($rows) === 1 ? (string)(int)($rows[0]['stock'] ?? 0) : '',
        ];
    }

    /**
     * Envoie le mail de commande aux achats (demandeur en copie) avec l'Excel joint.
     * Corps synthétique via gabarit_achat (fallback : mail brut) : le détail
     * (quoi envoyer / quoi commander) est dans l'Excel.
     *
     * @param ?int $requester_user_id User GLPI à mettre en copie (défaut : user connecté).
     */
    protected static function sendPurchaseOrderMail(array $rows, ?int $requester_user_id = null): bool {
        if (empty($rows)) {
            return false;
        }

        $achats_mail      = PluginPrintgestionAlert::resolveRecipientsForRole('achat');
        $uid              = $requester_user_id ?? (int)(Session::getLoginUserID() ?: 0);
        $requester_emails = PluginPrintgestionAlert::resolveEmailsForUsers([$uid]);

        // TO = 1er achats valide ; CC = autres achats + demandeur
        $valid = [];
        foreach (array_merge($achats_mail, $requester_emails) as $e) {
            $e = trim((string)$e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $valid[strtolower($e)] = $e;
            }
        }
        if (empty($valid)) {
            return false;
        }
        $valid = array_values($valid);

        $xlsx  = self::buildPurchaseExcel($rows);
        $count = count($rows);

        $config  = PluginPrintgestionConfig::getInstance();
        $gabarit = (int)($config->fields['gabarit_achat'] ?? 0);

        if ($gabarit > 0) {
            $ok = PluginPrintgestionConfig::sendMail($valid, $gabarit, self::buildPurchaseBalises($rows), $xlsx);
        } else {
            // Fallback sans gabarit : corps générique
            $to = array_shift($valid);
            $cc = $valid;
            $subject  = sprintf(__('[GLPI] Commande cartouches — %d référence(s)', 'printgestion'), $count);
            $bodyHtml = '<p>' . __('Bonjour,', 'printgestion') . '</p>'
                . '<p>' . sprintf(
                    __('Veuillez trouver ci-joint le fichier des cartouches à traiter (%d ligne(s)). Le détail (référence, client, livraison, stock GLPI…) figure dans le fichier Excel joint.', 'printgestion'),
                    $count
                ) . '</p>'
                . '<p>' . __('Merci.', 'printgestion') . '</p>';
            $ok = self::sendRawMail($to, $cc, $subject, $bodyHtml, $xlsx);
        }

        if (is_file($xlsx)) {
            @unlink($xlsx);
        }

        return $ok;
    }

    /**
     * Envoi direct d'un mail (GLPIMailer/Symfony) sans gabarit : sujet + corps HTML
     * + pièce jointe optionnelle. $to = destinataire principal, $cc = copies.
     */
    protected static function sendRawMail(string $to, array $cc, string $subject, string $bodyHtml, ?string $attachment = null): bool {
        global $CFG_GLPI;

        $mmail = new GLPIMailer();
        $mmail->addCustomHeader("X-Auto-Response-Suppress: OOF, DR, NDR, RN, NRN");

        $fromEmail = !empty($CFG_GLPI['from_email'])
            ? (string)$CFG_GLPI['from_email']
            : (string)($CFG_GLPI['admin_email'] ?? 'no-reply@localhost');
        $fromName  = $CFG_GLPI['from_email_name'] ?? $CFG_GLPI['admin_email_name'] ?? 'GLPI';
        $fromName  = (is_string($fromName) && $fromName !== '') ? $fromName : 'GLPI';

        $emailObj = $mmail->getEmail();
        $emailObj->from(new \Symfony\Component\Mime\Address($fromEmail, $fromName));
        $emailObj->to($to);
        if (!empty($cc)) {
            $emailObj->cc(...$cc);
        }
        if ($attachment && is_file($attachment)) {
            $emailObj->attachFromPath($attachment);
        }

        $mmail->Subject = $subject;
        $mmail->Body    = $bodyHtml;
        $mmail->AltBody = strip_tags($bodyHtml);

        return (bool)$mmail->send();
    }

    /**
     * Emails du CLIENT à prévenir pour une imprimante (mail de courtoisie) :
     *   1. l'usager lié à l'imprimante (champ « Utilisateur ») ;
     *   2. à défaut : les utilisateurs rattachés à l'entité de l'imprimante.
     */
    public static function resolveClientEmailsForPrinter(int $printers_id): array {
        global $DB;

        $printer = new Printer();
        if (!$printer->getFromDB($printers_id)) {
            return [];
        }

        $uid = (int)($printer->fields['users_id'] ?? 0);
        if ($uid > 0) {
            $emails = PluginPrintgestionAlert::resolveEmailsForUsers([$uid]);
            if (!empty($emails)) {
                return $emails;
            }
        }

        // Fallback : utilisateurs habilités dans l'entité de l'imprimante.
        $entities_id = (int)($printer->fields['entities_id'] ?? 0);
        $uids = [];
        foreach ($DB->request([
            'SELECT'   => ['users_id'],
            'DISTINCT' => true,
            'FROM'     => 'glpi_profiles_users',
            'WHERE'    => ['entities_id' => $entities_id],
        ]) as $r) {
            $uids[] = (int)$r['users_id'];
        }
        return PluginPrintgestionAlert::resolveEmailsForUsers($uids);
    }

    /**
     * Mail de planification (logistique) pour une commande — simple ou multi :
     *   - 1 cartouche  → gabarit_planif (détail unitaire client/imprimante/cartouche)
     *   - N cartouches → gabarit_planif_group, liste avec CLIENT par ligne
     *     (une commande peut couvrir plusieurs imprimantes / plusieurs clients).
     * Demandeur en copie. Fallback mail brut si aucun gabarit configuré.
     */
    protected static function sendOrderPlanifMail(array $rows): bool {
        if (empty($rows)) {
            return false;
        }
        $planif_mail = PluginPrintgestionAlert::resolveRecipientsForRole('planif');
        if (empty($planif_mail)) {
            return false;
        }
        $requester = PluginPrintgestionAlert::resolveEmailsForUsers([(int)(Session::getLoginUserID() ?: 0)]);

        $valid = [];
        foreach (array_merge($planif_mail, $requester) as $e) {
            $e = trim((string)$e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $valid[strtolower($e)] = $e;
            }
        }
        if (empty($valid)) {
            return false;
        }
        $valid = array_values($valid);

        $config = PluginPrintgestionConfig::getInstance();

        // ── Envoi SIMPLE : 1 cartouche → gabarit unitaire ──
        if (count($rows) === 1) {
            $gabarit = (int)($config->fields['gabarit_planif'] ?? 0);
            if ($gabarit > 0) {
                $r = $rows[0];
                $contract_name = '';
                $contracts_id  = PluginPrintgestionContractrate::getContractIdForPrinter((int)($r['printers_id'] ?? 0));
                if ($contracts_id > 0) {
                    $c = new Contract();
                    if ($c->getFromDB($contracts_id)) {
                        $contract_name = (string)$c->fields['name'];
                    }
                }
                return PluginPrintgestionConfig::sendMail($valid, $gabarit, [
                    '##printgestion.printer##'   => (string)($r['printer_name'] ?? ''),
                    '##printgestion.client##'    => (string)($r['client'] ?? ''),
                    '##printgestion.toner##'     => (string)($r['property'] ?? ''),
                    '##printgestion.level##'     => (string)(int)($r['level'] ?? 0),
                    '##printgestion.days##'      => isset($r['days']) && $r['days'] !== null ? (string)(int)$r['days'] : 'N/A',
                    '##printgestion.cartridge##' => (string)($r['cartridge_name'] ?? ''),
                    '##printgestion.stock##'     => (string)(int)($r['stock'] ?? 0),
                    '##printgestion.contract##'  => $contract_name,
                    '##printgestion.count##'     => '1',
                ]);
            }
        }

        // ── Envoi MULTI : gabarit groupé, client précisé sur chaque ligne.
        //    Liste plafonnée à MAIL_LIST_MAX ; le détail complet part dans
        //    le fichier Excel joint (évite un corps de mail à 100 lignes). ──
        $gabarit = (int)($config->fields['gabarit_planif_group'] ?? 0);
        if ($gabarit <= 0) {
            $gabarit = (int)($config->fields['gabarit_planif'] ?? 0);
        }
        if ($gabarit > 0) {
            $list = '<ul>';
            foreach (array_slice($rows, 0, self::MAIL_LIST_MAX) as $r) {
                $stock_txt = ((int)($r['stock'] ?? 0) > 0)
                    ? ('stock: ' . (int)$r['stock'])
                    : ('<strong style="color:#c00">stock vide</strong>');
                $list .= '<li>'
                    . '<strong>' . htmlspecialchars((string)($r['cartridge_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</strong>'
                    . ' — ' . htmlspecialchars((string)($r['client'] ?? ''), ENT_QUOTES, 'UTF-8')
                    . ' — ' . htmlspecialchars((string)($r['printer_name'] ?? ''), ENT_QUOTES, 'UTF-8')
                    . ' — ' . $stock_txt
                    . '</li>';
            }
            if (count($rows) > self::MAIL_LIST_MAX) {
                $list .= '<li>… et ' . (count($rows) - self::MAIL_LIST_MAX)
                    . ' autres — détail complet dans le fichier Excel joint</li>';
            }
            $list .= '</ul>';

            $balises = self::buildPurchaseBalises($rows);
            $balises['##printgestion.cartridges_list##'] = $list;
            $balises['##printgestion.contract##']        = '';
            $balises['##printgestion.days##']            = 'N/A';

            $xlsx = self::buildPurchaseExcel($rows);
            $ok   = PluginPrintgestionConfig::sendMail($valid, $gabarit, $balises, $xlsx);
            if (is_file($xlsx)) {
                @unlink($xlsx);
            }
            return $ok;
        }

        // ── Fallback sans gabarit : mail brut (ancien comportement) ──
        $to = array_shift($valid);
        $cc = $valid;

        $list = '<ul>';
        foreach ($rows as $r) {
            $list .= '<li><strong>' . htmlspecialchars((string)($r['printer_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</strong> — '
                . htmlspecialchars((string)($r['client'] ?? ''), ENT_QUOTES, 'UTF-8') . ' — '
                . htmlspecialchars((string)($r['cartridge_name'] ?? ''), ENT_QUOTES, 'UTF-8')
                . ' <span style="color:#666">(stock: ' . (int)($r['stock'] ?? 0) . ')</span></li>';
        }
        $list .= '</ul>';

        $subject  = sprintf(__('[GLPI] Cartouches à expédier — %d', 'printgestion'), count($rows));
        $bodyHtml = '<p>' . __('Bonjour,', 'printgestion') . '</p>'
            . '<p>' . __('Cartouches à préparer / expédier :', 'printgestion') . '</p>'
            . $list
            . '<p>' . __('Merci.', 'printgestion') . '</p>';

        return self::sendRawMail($to, $cc, $subject, $bodyHtml, null);
    }

    /**
     * Mail de courtoisie au client — regroupé par DESTINATAIRE :
     * si le même contact couvre plusieurs imprimantes (ex. 3 cartouches pour
     * 3 imprimantes du même client), il reçoit UN seul mail listant toutes
     * ses imprimantes au lieu d'un mail par imprimante. Gabarit dédié
     * (balise ##printgestion.printers_list##).
     */
    protected static function sendOrderCourtesyMails(array $rows): void {
        $config = PluginPrintgestionConfig::getInstance();
        $gab    = (int)($config->fields['gabarit_courtoisie'] ?? 0);
        if ($gab <= 0) {
            return;
        }

        // 1 entrée par imprimante (plusieurs cartouches possibles par imprimante)
        $byPrinter = [];
        foreach ($rows as $r) {
            $pid = (int)($r['printers_id'] ?? 0);
            if ($pid > 0 && !isset($byPrinter[$pid])) {
                $byPrinter[$pid] = $r;
            }
        }

        // Regroupe les imprimantes partageant exactement les mêmes destinataires
        $groups = [];
        foreach ($byPrinter as $pid => $r) {
            $emails = self::resolveClientEmailsForPrinter($pid);
            if (empty($emails)) {
                continue;
            }
            $norm = [];
            foreach ($emails as $e) {
                $e = strtolower(trim((string)$e));
                if ($e !== '') {
                    $norm[$e] = $e;
                }
            }
            if (empty($norm)) {
                continue;
            }
            ksort($norm);
            $key = implode(',', array_keys($norm));
            if (!isset($groups[$key])) {
                $groups[$key] = ['emails' => array_values($norm), 'printers' => []];
            }
            $groups[$key]['printers'][] = $r;
        }

        foreach ($groups as $g) {
            $list     = '<ul>';
            $printers = [];
            $clients  = [];
            foreach ($g['printers'] as $r) {
                $pname = (string)($r['printer_name'] ?? '');
                $printers[] = $pname;
                $client = trim((string)($r['client'] ?? ''));
                if ($client !== '') {
                    $clients[$client] = $client;
                }
                $list .= '<li><strong>' . htmlspecialchars($pname, ENT_QUOTES, 'UTF-8') . '</strong></li>';
            }
            $list .= '</ul>';

            PluginPrintgestionConfig::sendMail($g['emails'], $gab, [
                '##printgestion.printer##'       => implode(', ', $printers),
                '##printgestion.client##'        => implode(', ', array_values($clients)),
                '##printgestion.printers_list##' => $list,
                '##printgestion.count##'         => (string)count($g['printers']),
            ]);
        }
    }

    /**
     * Génère un UUID v4 (RFC 4122) sans dépendance externe.
     */
    protected static function generateUuid(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Envoie les mails correspondant à une nouvelle expédition (planif / achats / commercial).
     */
    protected static function dispatchMailsForNew(int $expedition_id, string $reason): void {
        global $DB;

        $exp = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['id' => $expedition_id],
            'LIMIT' => 1,
        ])->current();
        if (!is_array($exp)) {
            return;
        }

        $printer = new Printer();
        if (!$printer->getFromDB((int)$exp['printers_id'])) {
            return;
        }

        $entity_name = '';
        $entity = new Entity();
        if ($entity->getFromDB((int)$printer->fields['entities_id'])) {
            $entity_name = (string)$entity->fields['completename'];
        }

        $contracts_id = PluginPrintgestionContractrate::getContractIdForPrinter((int)$printer->fields['id']);
        $contract_name = '';
        if ($contracts_id > 0) {
            $c = new Contract();
            if ($c->getFromDB($contracts_id)) {
                $contract_name = (string)$c->fields['name'];
            }
        }

        // Label lisible pour l'affichage + résolution cartouche pour le stock
        $cartridge_label   = PluginPrintgestionSnmpmapping::getCartridgeLabelForProperty(
            (int)$printer->fields['id'],
            (string)$exp['toner_property']
        );
        $cartridgeitems_id = PluginPrintgestionSnmpmapping::resolveCartridgeItemForSnmp(
            (int)$printer->fields['id'],
            (string)$exp['toner_property']
        );
        $stock = self::getCartridgeStock($cartridgeitems_id);

        $balises = [
            '##printgestion.printer##'   => (string)$printer->fields['name'],
            '##printgestion.client##'    => $entity_name,
            '##printgestion.toner##'     => (string)$exp['toner_property'],
            '##printgestion.level##'     => (string)(int)$exp['level_at_alert'],
            '##printgestion.days##'      => $exp['estimated_days'] !== null ? (string)(int)$exp['estimated_days'] : 'N/A',
            '##printgestion.cartridge##' => $cartridge_label,
            '##printgestion.stock##'     => (string)$stock,
            '##printgestion.contract##'  => $contract_name,
        ];

        $config = PluginPrintgestionConfig::getInstance();

        // Email du user GLPI qui a déclenché la demande → CC de tous les mails
        $requester_emails = PluginPrintgestionAlert::resolveEmailsForUsers([(int)($exp['users_id_tech'] ?? 0)]);

        if ($reason === 'stock_empty') {
            // Stock vide : commande achats (Excel joint) + info commercial
            self::sendPurchaseOrderMail(
                [self::buildPurchaseRowData((int)$printer->fields['id'], (string)$exp['toner_property'])],
                (int)($exp['users_id_tech'] ?? 0)
            );
            $commercial_mail = PluginPrintgestionAlert::resolveRecipientsForRole('commercial');
            if (!empty($commercial_mail) && (int)($config->fields['gabarit_commercial'] ?? 0) > 0) {
                PluginPrintgestionConfig::sendMail(
                    array_merge($commercial_mail, $requester_emails),
                    (int)$config->fields['gabarit_commercial'],
                    $balises
                );
            }
            return;
        }

        // Cas standard (stock OK) : planif + commercial informé
        $planif_mail = PluginPrintgestionAlert::resolveRecipientsForRole('planif');
        if (!empty($planif_mail) && (int)($config->fields['gabarit_planif'] ?? 0) > 0) {
            PluginPrintgestionConfig::sendMail(
                array_merge($planif_mail, $requester_emails),
                (int)$config->fields['gabarit_planif'],
                $balises
            );
        }

        $commercial_mail = PluginPrintgestionAlert::resolveRecipientsForRole('commercial');
        if (!empty($commercial_mail) && (int)($config->fields['gabarit_commercial'] ?? 0) > 0) {
            PluginPrintgestionConfig::sendMail(
                array_merge($commercial_mail, $requester_emails),
                (int)$config->fields['gabarit_commercial'],
                $balises
            );
        }
    }

    /**
     * Calcule le stock disponible d'un cartridgeitem (par ID GLPI).
     * Stock = cartouches déclarées non installées (date_use IS NULL) et non sorties.
     *
     * Accepte aussi un nom (rétrocompat legacy) pour les vieux call-sites.
     */
    public static function getCartridgeStock($cartridge): int {
        global $DB;

        // Résout vers un ou plusieurs cartridgeitems_id
        $ids = [];
        if (is_int($cartridge) || ctype_digit((string)$cartridge)) {
            $id = (int)$cartridge;
            if ($id > 0) {
                $ids[] = $id;
            }
        } elseif (is_string($cartridge) && $cartridge !== '') {
            // Legacy : recherche par nom LIKE
            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_cartridgeitems',
                'WHERE'  => [
                    'is_deleted' => 0,
                    'name'       => ['LIKE', '%' . $cartridge . '%'],
                ],
            ]) as $it) {
                $ids[] = (int)$it['id'];
            }
        }

        if (empty($ids)) {
            return 0;
        }

        $total = 0;
        foreach ($ids as $item_id) {
            $count = $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => 'glpi_cartridges',
                'WHERE' => [
                    'cartridgeitems_id' => $item_id,
                    'date_use'          => null,
                    'date_out'          => null,
                ],
            ])->current();
            if (is_array($count)) {
                $total += (int)$count['cpt'];
            }
        }

        return $total;
    }

    /**
     * Passe une expédition au statut "shipped" après saisie par la planif.
     */
    public static function markShipped(int $expedition_id, string $carrier, string $tracking, ?int $bl_surveys_id = null): bool {
        global $DB;

        $allowed = ['ups', 'gls', 'chronopost', 'other'];
        $carrier = in_array($carrier, $allowed, true) ? $carrier : 'other';

        $data = [
            'statut'            => self::STATUS_SHIPPED,
            'transport_carrier' => $carrier,
            'transport_number'  => trim($tracking),
            'date_shipped'      => date('Y-m-d H:i:s'),
            'users_id_planif'   => (int)(Session::getLoginUserID() ?: 0),
        ];
        if ($bl_surveys_id !== null && $bl_surveys_id > 0) {
            $data['bl_surveys_id'] = $bl_surveys_id;
        }

        $ok = $DB->update(self::getTable(), $data, ['id' => $expedition_id]);
        if ($ok) {
            self::notifyShippedToCommercial($expedition_id);
        }
        return (bool)$ok;
    }

    protected static function notifyShippedToCommercial(int $expedition_id): void {
        global $DB;

        $exp = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['id' => $expedition_id],
            'LIMIT' => 1,
        ])->current();
        if (!is_array($exp)) {
            return;
        }

        $config = PluginPrintgestionConfig::getInstance();
        if ((int)($config->fields['gabarit_commercial'] ?? 0) <= 0) {
            return;
        }

        $mail = PluginPrintgestionAlert::resolveRecipientsForRole('commercial');
        if (empty($mail)) {
            return;
        }

        $printer = new Printer();
        if (!$printer->getFromDB((int)$exp['printers_id'])) {
            return;
        }

        $entity_name = '';
        $entity = new Entity();
        if ($entity->getFromDB((int)$printer->fields['entities_id'])) {
            $entity_name = (string)$entity->fields['completename'];
        }

        $balises = [
            '##printgestion.printer##'  => (string)$printer->fields['name'],
            '##printgestion.client##'   => $entity_name,
            '##printgestion.toner##'    => (string)$exp['toner_property'],
            '##printgestion.carrier##'  => strtoupper((string)$exp['transport_carrier']),
            '##printgestion.tracking##' => (string)$exp['transport_number'],
        ];

        PluginPrintgestionConfig::sendMail($mail, (int)$config->fields['gabarit_commercial'], $balises);
    }

    /**
     * Appelée depuis cartridgehistory quand un changement de cartouche est détecté.
     * Passe l'expédition active (si existe) au statut delivered.
     */
    public static function markDeliveredOnInstall(int $printers_id, string $property): void {
        global $DB;

        $active = self::getActiveForPrinterProperty($printers_id, $property);
        if ($active === null) {
            return;
        }

        $DB->update(self::getTable(), [
            'statut'         => self::STATUS_DELIVERED,
            'date_delivered' => date('Y-m-d H:i:s'),
        ], ['id' => (int)$active['id']]);
    }

    /**
     * Cron : envoi du rappel si expédition "shipped" depuis plus de reminder_days.
     */
    public static function sendInstallReminders(): int {
        global $DB;

        $config = PluginPrintgestionConfig::getInstance();
        $reminder_days = max(1, (int)($config->fields['reminder_days'] ?? 7));
        $gabarit       = (int)($config->fields['gabarit_rappel'] ?? 0);
        if ($gabarit <= 0) {
            return 0;
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$reminder_days} days"));

        $expeditions = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'statut'       => ['shipped', 'transit'],
                'date_shipped' => ['<=', $cutoff],
            ],
        ]);

        // Collecte des expéditions en retard (anti-doublon 24h conservé par item)
        $items = [];
        foreach ($expeditions as $exp) {
            $recent = $DB->request([
                'FROM'  => 'glpi_plugin_printgestion_alerts',
                'WHERE' => [
                    'printers_id'    => (int)$exp['printers_id'],
                    'toner_property' => (string)$exp['toner_property'],
                    'alert_type'     => 'no_install_reminder',
                    'date_alert'     => ['>=', date('Y-m-d H:i:s', strtotime('-24 hours'))],
                ],
                'LIMIT' => 1,
            ])->current();
            if (is_array($recent)) {
                continue;
            }

            $printer = new Printer();
            if (!$printer->getFromDB((int)$exp['printers_id'])) {
                continue;
            }

            $entity_name = '';
            $entity = new Entity();
            if ($entity->getFromDB((int)$printer->fields['entities_id'])) {
                $entity_name = (string)$entity->fields['completename'];
            }

            $items[] = [
                'printers_id' => (int)$exp['printers_id'],
                'property'    => (string)$exp['toner_property'],
                'level'       => (int)($exp['level_at_alert'] ?? 0),
                'printer'     => (string)$printer->fields['name'],
                'client'      => $entity_name,
                'days'        => max(0, (int)((time() - strtotime((string)$exp['date_shipped'])) / 86400)),
            ];
        }

        if (empty($items)) {
            return 0;
        }

        // Destinataires selon config : planif / commercial / les deux (défaut).
        $mode = (string)($config->fields['reminder_recipients'] ?? 'both');
        $mail = [];
        if ($mode === 'planif' || $mode === 'both') {
            $mail = array_merge($mail, PluginPrintgestionAlert::resolveRecipientsForRole('planif'));
        }
        if ($mode === 'commercial' || $mode === 'both') {
            $mail = array_merge($mail, PluginPrintgestionAlert::resolveRecipientsForRole('commercial'));
        }
        $mail = array_values(array_unique($mail));
        if (empty($mail)) {
            return 0;
        }

        // DIGEST : un seul mail listant toutes les cartouches en retard
        // (au lieu d'un mail par expédition).
        $list = '<ul>';
        foreach (array_slice($items, 0, self::MAIL_LIST_MAX) as $it) {
            $list .= '<li>'
                . '<strong>' . htmlspecialchars($it['printer'], ENT_QUOTES, 'UTF-8') . '</strong>'
                . ' — ' . htmlspecialchars($it['client'], ENT_QUOTES, 'UTF-8')
                . ' — ' . htmlspecialchars($it['property'], ENT_QUOTES, 'UTF-8')
                . ' — expédiée il y a ' . (int)$it['days'] . ' j'
                . '</li>';
        }
        if (count($items) > self::MAIL_LIST_MAX) {
            $list .= '<li>… et ' . (count($items) - self::MAIL_LIST_MAX) . ' autres</li>';
        }
        $list .= '</ul>';

        $clients  = array_values(array_unique(array_map(fn($it) => $it['client'], $items)));
        $printers = array_values(array_unique(array_map(fn($it) => $it['printer'], $items)));
        $days_max = max(array_map(fn($it) => (int)$it['days'], $items));

        $balises = [
            '##printgestion.cartridges_list##' => $list,
            '##printgestion.count##'           => (string)count($items),
            '##printgestion.printer##'         => count($printers) > 1
                ? sprintf(__('%d imprimantes', 'printgestion'), count($printers))
                : (string)($printers[0] ?? ''),
            '##printgestion.client##'          => count($clients) > 1
                ? sprintf(__('%d clients', 'printgestion'), count($clients))
                : (string)($clients[0] ?? ''),
            '##printgestion.days##'            => (string)$days_max,
        ];

        if (!PluginPrintgestionConfig::sendMail($mail, $gabarit, $balises)) {
            return 0;
        }

        foreach ($items as $it) {
            PluginPrintgestionAlert::logAlert(
                $it['printers_id'],
                $it['property'],
                $it['level'],
                null,
                'no_install_reminder'
            );
        }

        return count($items);
    }

    /**
     * Réassigne une expédition à une autre imprimante, typiquement depuis
     * une alerte wrong_printer où l'utilisateur accepte le nouveau destinataire.
     *
     * Effets :
     *   - Met à jour expedition.printers_id = $new_printers_id
     *   - Crée rétroactivement une entrée glpi_cartridges sur la nouvelle imprimante
     *     (puisque le flux normal avait été skippé lors de la détection wrong_printer)
     *   - Clôture l'alerte wrong_printer associée
     *   - Passe l'expédition en delivered si elle ne l'est pas déjà
     */
    public static function reassignToPrinter(int $expedition_id, int $new_printers_id): bool {
        global $DB;

        if ($expedition_id <= 0 || $new_printers_id <= 0) {
            return false;
        }

        $exp = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['id' => $expedition_id],
            'LIMIT' => 1,
        ])->current();
        if (!is_array($exp)) {
            return false;
        }

        // Vérifie que la nouvelle imprimante existe bien
        $printer = new Printer();
        if (!$printer->getFromDB($new_printers_id)) {
            return false;
        }

        $property = (string)$exp['toner_property'];

        // 1. Met à jour l'expédition
        $DB->update(self::getTable(), [
            'printers_id'    => $new_printers_id,
            'statut'         => self::STATUS_DELIVERED,
            'date_delivered' => date('Y-m-d H:i:s'),
            'notes'          => trim(((string)($exp['notes'] ?? ''))
                . "\n[" . date('Y-m-d H:i') . "] Réassignée depuis l'imprimante #"
                . (int)$exp['printers_id'] . " vers #{$new_printers_id}"),
        ], ['id' => $expedition_id]);

        // 2. Crée rétroactivement la ligne glpi_cartridges sur la nouvelle imprimante
        //    et ferme la précédente si active pour ce couple.
        $now = date('Y-m-d H:i:s');
        $counter = PluginPrintgestionCartridgehistory::getPrinterPagesCounter($new_printers_id);

        // Appelle la sync comme si on venait de détecter un changement maintenant
        PluginPrintgestionCartridgehistory::syncNativeCartridges(
            $new_printers_id,
            $property,
            $now,
            $counter
        );

        // 3. Marque toutes les alertes wrong_printer liées comme résolues
        $DB->update('glpi_plugin_printgestion_alerts', [
            'is_resolved' => 1,
        ], [
            'alert_type'     => 'wrong_printer',
            'expeditions_id' => $expedition_id,
            'is_resolved'    => 0,
        ]);

        return true;
    }

    /**
     * Cron : pour chaque alerte wrong_printer non résolue dont l'âge dépasse
     * wrong_printer_auto_reassign_days, réassigne automatiquement l'expédition
     * vers l'imprimante où la cartouche a été détectée physiquement.
     */
    public static function autoReassignStaleWrongPrinterAlerts(): int {
        global $DB;

        $config = PluginPrintgestionConfig::getInstance();
        $days   = max(1, (int)($config->fields['wrong_printer_auto_reassign_days'] ?? 7));
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $stale = $DB->request([
            'FROM'  => 'glpi_plugin_printgestion_alerts',
            'WHERE' => [
                'alert_type'  => 'wrong_printer',
                'is_resolved' => 0,
                'date_alert'  => ['<=', $cutoff],
            ],
        ]);

        $count = 0;
        foreach ($stale as $alert) {
            $exp_id  = (int)($alert['expeditions_id'] ?? 0);
            $new_pid = (int)($alert['detected_printers_id'] ?? 0);
            if ($exp_id <= 0 || $new_pid <= 0) {
                continue;
            }
            if (self::reassignToPrinter($exp_id, $new_pid)) {
                $count++;
            }
        }

        return $count;
    }

    static function install(Migration $migration) { return true; }
    static function uninstall(Migration $migration) { return true; }
}
