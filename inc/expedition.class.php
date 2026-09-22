<?php
/**
 * PluginPrintgestionExpedition — gestion du cycle d'expédition des cartouches (Point 4).
 *
 * Statuts : pending → shipped → transit → delivered → installed
 *           ou cancelled (annulée, jamais supprimée).
 *
 * Un envoi reste EN COURS (ACTIVE_STATUSES) tant que la pose n'est pas détectée ou
 * confirmée : « livrée » ne le clôt pas. Seuls installed et cancelled le clôturent.
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
    /** Pose détectée (hausse de niveau) ou confirmée manuellement : clôt l'envoi. */
    const STATUS_INSTALLED   = 'installed';
    /** Annulée (jamais supprimée) : clôt l'envoi sans pose. */
    const STATUS_CANCELLED   = 'cancelled';

    /**
     * Statuts d'un envoi EN COURS, sans borne de temps : commandé (pending), expédié,
     * en transit ET livré tant que la pose n'est pas constatée.
     */
    const ACTIVE_STATUSES = ['pending', 'shipped', 'transit', 'delivered'];
    /** Transporteurs saisis à la main sur une expédition (annotation, pas une intégration) : aucun par défaut. */
    /** Valeurs enregistrées (l'historique se lit, jamais réécrit). */
    const CARRIERS = ['ups', 'gls', 'chronopost', 'other'];
    /** Choix proposés pour une expédition : Chronopost n'est plus utilisé, il ne s'offre plus au clic. */
    const CARRIERS_OFFERED = ['gls', 'ups', 'other'];

    /** Libellés des transporteurs proposés (ou de tous, pour lire l'existant). */
    public static function getCarrierLabels(bool $offered_only = true): array {
        $labels = ['gls' => 'GLS', 'ups' => 'UPS', 'other' => __('Autre', 'printgestion')];
        return $offered_only ? $labels : $labels + ['chronopost' => 'Chronopost'];
    }

    /** Envois partis : seule leur cartouche peut avoir été posée (ailleurs que prévu). */
    const DEPARTED_STATUSES = ['shipped', 'transit', 'delivered'];

    /** Nombre max de lignes listées dans le corps d'un mail groupé/digest ;
     *  au-delà : « … et N autres » (le détail complet reste dans l'Excel joint). */
    const MAIL_LIST_MAX = 20;

    /**
     * Icône de l'itemtype, reprise par GLPI dans les listes, les en-têtes et les onglets. Expédition d'une cartouche : le colis qui part chez le client.
     *
     * Sans elle, GLPI retombe sur l'icône par défaut de CommonDBTM, qui ne montre rien.
     */
    static function getIcon() {
        return 'ti ti-truck-delivery';
    }

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
            'massiveaction' => false, // entité de l'envoi, figée à sa création
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
            'datatype' => 'specific',   // saisie brute + ligne de suivi GLS (getSpecificValueToDisplay), rien sans clés
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
            'id'       => '14',
            'table'    => self::getTable(),
            'field'    => 'date_installed',
            'name'     => __('Date de pose', 'printgestion'),
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
                    'delivered'   => ['bg-success',   __('Livrée (non posée)', 'printgestion')],
                    'installed'   => ['bg-teal',      __('Posée', 'printgestion')],
                    'cancelled'   => ['bg-light text-muted border', __('Annulée', 'printgestion')],
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

            case 'transport_number':
                // La saisie brute, jamais réécrite ; dessous, la ligne de suivi GLS quand il y en a une (rien sans clés).
                $v   = trim((string) ($values[$field] ?? ''));
                $out = $v === '' ? "<span class='text-muted'>—</span>" : htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
                $rawid = (int) ($options['raw_data']['id'] ?? 0);
                if ($v !== '' && $rawid > 0) {
                    $out .= PluginPrintgestionGlstracking::renderLineFor($rawid);
                }
                return $out;
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Retourne l'expédition en cours (ni posée ni annulée) pour une imprimante + propriété.
     */
    public static function getActiveForPrinterProperty(int $printers_id, string $property): ?array {
        global $DB;

        $row = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'printers_id'    => $printers_id,
                'toner_property' => $property,
                'statut'         => self::ACTIVE_STATUSES,
            ],
            'ORDER' => ['id DESC'],
            'LIMIT' => 1,
        ])->current();

        return is_array($row) ? $row : null;
    }

    /**
     * Enregistre une expédition « en attente », sans aucun mail : les notifications d'une
     * commande sont envoyées par createPurchaseOrder(), seul parcours d'envoi. Aucun stock
     * n'est consulté : le stock est dans Sage.
     * @param string|null $group_id UUID partagé par les expéditions d'une même commande.
     */
    public static function createFromAlert(
        int $printers_id,
        string $property,
        int $level,
        ?int $estimated_days,
        ?string $group_id = null
    ): int {
        global $DB;

        // Pas de vérification applicative ici (elle renvoyait silencieusement l'envoi
        // existant et perdait la course sur un double clic) : l'unicité d'un envoi en
        // cours par (imprimante, toner) est garantie par la clé unique uniq_active_slot.
        // Un doublon lève une exception (voir isDuplicateActiveError()).

        $mapping = PluginPrintgestionSnmpmapping::resolveForPrinter($printers_id, $property);
        $color   = is_array($mapping) ? (string)($mapping['toner_color'] ?? 'other') : 'other';

        $DB->insert(self::getTable(), PluginPrintgestionEntityscope::forPrinter($printers_id) + [
            'printers_id'    => $printers_id,
            'toner_property' => $property,
            'toner_color'    => $color,
            'statut'         => self::STATUS_PENDING,
            'level_at_alert' => $level,
            'estimated_days' => $estimated_days,
            'date_alert'     => date('Y-m-d H:i:s'),
            'users_id_tech'  => (int)(Session::getLoginUserID() ?: 0),
            'group_id'       => $group_id,
        ]);

        return (int)$DB->insertId();
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  COMMANDE DE CARTOUCHES (mail achats + Excel) — mono / multi-imprimantes
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Données d'UNE cartouche pour les mails de commande (Achats, planification,
     * courtoisie) et pour sa ligne du fichier Gesconso : client (nom d'entité, pour les
     * mails), imprimante, cartouche, commentaire du lieu (Complément livraison) et
     * couverture contrat. Le contenu du fichier est construit par
     * PluginPrintgestionGesconso::prepare().
     */
    /**
     * Aperçu d'une commande directe, sans écriture ni verrou : ce qui empêcherait d'écrire le fichier Gesconso
     * (référence non résolue, ligne en erreur) et ce qui mérite d'être vu avant l'envoi (notices). Affiché dans le
     * sous-formulaire « Commander » avant le clic.
     *
     * @param array $items [['printers_id', 'property']]
     * @return array ['errors' => string[], 'notices' => notices de Gesconso::prepare()]
     */
    public static function previewPurchaseOrder(array $items): array {
        global $DB;

        $errors = [];
        $lines  = [];
        $names  = [];
        $ids    = array_values(array_unique(array_map(static fn(array $i) => (int) $i['printers_id'], $items)));
        if (!empty($ids)) {
            foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_printers', 'WHERE' => ['id' => $ids]]) as $printer) {
                $names[(int) $printer['id']] = (string) $printer['name'];
            }
        }
        foreach ($items as $item) {
            $printers_id = (int) $item['printers_id'];
            $property    = (string) $item['property'];
            $label       = sprintf('%s — %s', $names[$printers_id] ?? ('#' . $printers_id), $property);
            $ref         = PluginPrintgestionSnmpmapping::resolveCartridge($printers_id, $property);
            if ($ref['cartridgeitems_id'] <= 0) {
                $errors[] = $label . ' : ' . $ref['message'];
                continue;
            }
            $row     = self::buildPurchaseRowData($printers_id, $property, (int) $ref['cartridgeitems_id']);
            $lines[] = [
                'key'               => $printers_id . '|' . $property,
                'label'             => $label,
                'printers_id'       => $printers_id,
                'cartridgeitems_id' => (int) $ref['cartridgeitems_id'],
                'quantity'          => 1,
                'unit_price'        => null,
                'under_contract'    => $row['under_contract'],
                'date'              => date('Y-m-d'),
                'complement'        => $row['complement'],
            ];
        }
        $gesconso = empty($lines) ? ['errors' => [], 'notices' => PluginPrintgestionGesconso::emptyNotices()] : PluginPrintgestionGesconso::prepare($lines);
        foreach ($gesconso['errors'] as $messages) {
            $errors = array_merge($errors, $messages);
        }
        return ['errors' => $errors, 'notices' => $gesconso['notices']];
    }

    public static function buildPurchaseRowData(int $printers_id, string $property, int $cartridgeitems_id): array {
        global $DB;

        $entity_name = $loc_comment = $printer_name = '';

        $printer = new Printer();
        if ($printer->getFromDB($printers_id)) {
            $printer_name = (string)($printer->fields['name'] ?? '');

            $entity = new Entity();
            if ($entity->getFromDB((int)$printer->fields['entities_id'])) {
                $entity_name = (string)($entity->fields['name'] ?? '');
            }

            $loc_id = (int)($printer->fields['locations_id'] ?? 0);
            if ($loc_id > 0) {
                $location = new Location();
                if ($location->getFromDB($loc_id)) {
                    $loc_comment = (string)($location->fields['comment'] ?? '');
                }
            }
        }

        $cart_name = '';
        if ($cartridgeitems_id > 0) {
            $ci = $DB->request([
                'SELECT' => ['name'],
                'FROM'   => 'glpi_cartridgeitems',
                'WHERE'  => ['id' => $cartridgeitems_id],
                'LIMIT'  => 1,
            ])->current();
            if (is_array($ci)) {
                $cart_name = (string)($ci['name'] ?? '');
            }
        }
        if ($cart_name === '') {
            $cart_name = PluginPrintgestionSnmpmapping::getCartridgeLabelForProperty($printers_id, $property);
        }

        return [
            'client'         => $entity_name,
            'complement'     => $loc_comment,
            // Sous contrat (type « consommables inclus », contrat en cours) : prix 0 dans le fichier.
            'under_contract' => PluginPrintgestionContractrate::getConsumablesCoverage($printers_id)['under_contract'],
            'printers_id'    => $printers_id,
            'printer_name'   => $printer_name,
            'cartridge_name' => $cart_name,
            'property'       => $property,
        ];
    }

    /**
     * Crée une COMMANDE de cartouches (mono OU multi-imprimantes) :
     *   - 1 expédition par cartouche (statut pending, group_id commun)
     *   - 1 fichier Excel (1 cartouche/ligne) envoyé aux ACHATS, demandeur en copie,
     *     corps de mail générique (tout le détail est dans l'Excel).
     *
     * Le mail aux Achats EST la commande. Les expéditions et ce mail sont traités
     * dans une même transaction : si l'envoi échoue (ou si aucun destinataire Achats
     * n'est configuré), la transaction est annulée, rien n'est enregistré et la cause
     * est renvoyée. Sans cela, l'anti-doublon bloquait ensuite la re-commande d'une
     * cartouche que les Achats n'avaient jamais reçue.
     *
     * @param array $items [['printers_id'=>int,'property'=>string,'level'=>int,'days'=>?int], ...]
     * Anti-double-envoi : les verrous (envoi en cours, garde après pose, ticket récent)
     * sont réévalués côté serveur avant toute écriture ; une seule ligne sous verrou
     * bloquant fait refuser la commande entière (écran périmé, à recharger). Une ligne
     * en double dans la commande est écartée, une commande sous contournement passe
     * avec un avertissement.
     * Référence : la cartouche est résolue strictement (Snmpmapping::resolveCartridge) ;
     * une seule ligne sans référence résolue fait aussi refuser la commande entière —
     * jamais de ligne sans référence dans le fichier des Achats.
     *
     * @return array ['ok'=>bool,'created'=>int,'skipped'=>int (toujours 0, conservé pour
     *                compatibilité),'mail'=>bool,'rows'=>int,'error'=>string (si ok = false),
     *                'warnings'=>string[] (contournements, lignes en double, mails complémentaires)]
     */
    public static function createPurchaseOrder(array $items, bool $send_planif = false, bool $send_courtesy = false): array {
        global $DB;

        $result = [
            'ok'       => false,
            'created'  => 0,
            'skipped'  => 0,
            'mail'     => false,
            'rows'     => 0,
            'error'    => '',
            'warnings' => [],
        ];
        if (empty($items)) {
            $result['error'] = __('Aucune cartouche à commander.', 'printgestion');
            return $result;
        }

        // ── 1. Lignes valides, une seule fois chacune ──
        $clean = [];
        foreach ($items as $item) {
            $printers_id = (int)($item['printers_id'] ?? 0);
            $property    = trim((string)($item['property'] ?? ''));
            if ($printers_id <= 0 || $property === '') {
                continue;
            }
            $key = $printers_id . '|' . $property;
            if (isset($clean[$key])) {
                // La même cartouche deux fois dans une commande serait déjà un double envoi.
                $result['warnings'][] = sprintf(
                    __('Ligne en double ignorée : toner %1$s de l\'imprimante #%2$d.', 'printgestion'),
                    $property,
                    $printers_id
                );
                continue;
            }
            $days = (isset($item['days']) && $item['days'] !== null) ? (int)$item['days'] : null;
            $clean[$key] = [
                'printers_id' => $printers_id,
                'property'    => $property,
                'level'       => (int)($item['level'] ?? 0),
                'days'        => ($days !== null && $days > 0) ? $days : null,
            ];
        }
        if (empty($clean)) {
            $result['error'] = __('Aucune cartouche valide à commander.', 'printgestion');
            return $result;
        }

        // ── 2. Verrous anti-double-envoi, évalués côté serveur (niveau relu dans l'inventaire) ──
        // Une seule ligne verrouillée fait refuser la commande entière : l'écran était
        // périmé (autre commande, pose, ticket…) et doit être rechargé avant de recommander.
        $printer_names = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => 'glpi_printers',
            'WHERE'  => ['id' => array_values(array_unique(array_column($clean, 'printers_id')))],
        ]) as $printer_row) {
            $printer_names[(int)$printer_row['id']] = (string)$printer_row['name'];
        }

        $locks   = PluginPrintgestionGuard::evaluateLive(array_values($clean));
        $refused = [];
        foreach ($clean as $key => $c) {
            $label = sprintf(
                '%s — %s',
                $printer_names[$c['printers_id']] ?? ('#' . $c['printers_id']),
                $c['property']
            );

            // Référence de cartouche : résolution stricte, pas de ligne sans référence.
            $ref = PluginPrintgestionSnmpmapping::resolveCartridge($c['printers_id'], $c['property']);
            if ($ref['cartridgeitems_id'] <= 0) {
                $refused[] = $label . ' : ' . $ref['message'];
                continue;
            }
            $clean[$key]['cartridgeitems_id'] = (int)$ref['cartridgeitems_id'];

            $lock = $locks[$key] ?? null;
            if ($lock === null) {
                continue;
            }
            if ($lock['blocking']) {
                $refused[] = $label . ' : ' . $lock['message'];
            } else {
                $result['warnings'][] = __('Commandé sous contournement', 'printgestion') . ' — ' . $label . ' : ' . $lock['message'];
            }
        }
        if (!empty($refused)) {
            $shown = array_slice($refused, 0, self::MAIL_LIST_MAX);
            if (count($refused) > self::MAIL_LIST_MAX) {
                $shown[] = sprintf(__('… et %d autre(s)', 'printgestion'), count($refused) - self::MAIL_LIST_MAX);
            }
            $result['warnings'] = [];
            $result['error']    = sprintf(
                __('Commande non passée : %d cartouche(s) non commandable(s) (verrou anti-double-envoi ou référence de cartouche non résolue). Rechargez l\'écran. Aucune expédition n\'a été enregistrée.', 'printgestion'),
                count($refused)
            ) . "\n- " . implode("\n- ", $shown);
            return $result;
        }

        // ── 3. Préparation, sans écriture : lignes du fichier Gesconso et expéditions à créer ──
        $rows      = [];
        $to_create = [];
        $lines     = [];
        foreach ($clean as $key => $c) {
            $cartridgeitems_id = $c['cartridgeitems_id'];
            $to_create[] = [
                'printers_id' => $c['printers_id'],
                'property'    => $c['property'],
                'level'       => $c['level'],
                'days'        => $c['days'],
            ];

            $row = self::buildPurchaseRowData($c['printers_id'], $c['property'], $cartridgeitems_id);
            // Niveau / jours restants : utilisés par le mail planif (envoi simple)
            $row['level'] = $c['level'];
            $row['days']  = $c['days'];
            $rows[] = $row;

            $lines[] = [
                'key'               => $key,
                'label'             => sprintf('%s — %s', $printer_names[$c['printers_id']] ?? ('#' . $c['printers_id']), $c['property']),
                'printers_id'       => $c['printers_id'],
                'cartridgeitems_id' => $cartridgeitems_id,
                'quantity'          => 1,
                'unit_price'        => null, // hors contrat : prix laissé aux Achats
                'under_contract'    => $row['under_contract'],
                'date'              => date('Y-m-d'),
                'complement'        => $row['complement'],
            ];
        }
        $result['rows'] = count($rows);

        // Contrôles avant export : une seule ligne impossible à écrire dans le fichier
        // Gesconso (code client, adresse de livraison ou référence article absents) fait
        // refuser la commande entière, avec la liste des lignes en défaut.
        $gesconso = PluginPrintgestionGesconso::prepare($lines);
        if (!empty($gesconso['errors'])) {
            $messages = array_merge(...array_values($gesconso['errors']));
            $shown    = array_slice($messages, 0, self::MAIL_LIST_MAX);
            if (count($messages) > self::MAIL_LIST_MAX) {
                $shown[] = sprintf(__('… et %d autre(s)', 'printgestion'), count($messages) - self::MAIL_LIST_MAX);
            }
            $result['warnings'] = [];
            $result['error']    = sprintf(
                __('Commande non passée : %d cartouche(s) ne peuvent pas être écrites dans le fichier Gesconso. Aucune expédition n\'a été enregistrée.', 'printgestion'),
                count($gesconso['errors'])
            ) . "\n- " . implode("\n- ", $shown);
            return $result;
        }
        $result['warnings'] = array_merge($result['warnings'], $gesconso['warnings']);

        try {
            $file = PluginPrintgestionGesconso::write($gesconso['rows']);
        } catch (Throwable $e) {
            PluginPrintgestionLogger::error('commande', 'Fichier Gesconso non généré.', $e);
            $result['error'] = __('Commande non passée : le fichier Gesconso n\'a pas pu être généré (détail dans le journal printgestion). Aucune expédition n\'a été enregistrée.', 'printgestion');
            return $result;
        }

        try {
            // ── 4. Enregistrement (expéditions, fichier archivé, transmission en attente), puis mail aux Achats ──
            // Enregistrer d'abord : un mail parti sur une commande non enregistrée ferait recommander la cartouche.
            $group_id = self::generateUuid();
            $DB->beginTransaction();
            // DBmysql n'expose pas l'état de la transaction : suivi local, pour ne jamais
            // appeler rollBack() hors transaction (qui lèverait une nouvelle exception).
            $in_transaction = true;
            $archive        = null; // Document créé, pour nettoyer sa copie si la transaction est annulée
            try {
                $expedition_ids = [];
                foreach ($to_create as $c) {
                    $expeditions_id = self::createFromAlert($c['printers_id'], $c['property'], $c['level'], $c['days'], $group_id);
                    if ($expeditions_id > 0) {
                        $result['created']++;
                        $expedition_ids[] = $expeditions_id;
                    }
                }

                // Archivage du fichier transmis, rattaché aux expéditions : dans la transaction,
                // une commande n'est jamais passée sans sa preuve.
                $documents_id = PluginPrintgestionGesconso::archive(
                    $file,
                    sprintf(__('Commande directe envoyée aux Achats le %1$s par %2$s (%3$d ligne(s)).', 'printgestion'), Html::convDateTime(date('Y-m-d H:i:s')), getUserName((int) Session::getLoginUserID()), count($rows)),
                    array_map(static fn(int $id) => [self::class, $id], $expedition_ids)
                );
                $archive = new Document();
                $archive->getFromDB($documents_id);

                $order_id = PluginPrintgestionPurchaseorder::record($group_id, PluginPrintgestionPurchaseorder::SOURCE_DIRECT, $documents_id, $rows);
                $DB->commit();
                $in_transaction = false;
            } catch (Throwable $e) {
                \Glpi\Error\ErrorHandler::logCaughtException($e);
                if ($in_transaction) {
                    try {
                        $DB->rollBack();
                    } catch (Throwable $rollback_error) {
                        // Annulation impossible : tracée ; la réponse reste un échec explicite.
                        \Glpi\Error\ErrorHandler::logCaughtException($rollback_error);
                    }
                    if ($archive !== null) {
                        PluginPrintgestionGesconso::removeOrphanArchive($archive->fields);
                    }
                }
                $result['created'] = 0;
                $result['error']   = $e->getCode() === PluginPrintgestionGesconso::ARCHIVE_FAILURE
                    ? $e->getMessage()
                    : (self::isDuplicateActiveError($e)
                    ? __('Commande non passée : un autre envoi vient d\'être enregistré pour une de ces cartouches (commande simultanée). Rechargez l\'écran. Aucune expédition n\'a été enregistrée.', 'printgestion')
                    : __('Commande non passée : erreur technique pendant l\'enregistrement (détail dans le journal d\'erreurs GLPI). Aucune expédition n\'a été enregistrée, rien n\'a été envoyé aux Achats.', 'printgestion'));
                return $result;
            }
            $result['ok'] = true;

            // Commande enregistrée : mail aux Achats. En cas d'échec, elle reste enregistrée et verrouillée,
            // « non transmise », à renvoyer (jamais annulée ni renvoyée automatiquement).
            $sent = PluginPrintgestionPurchaseorder::send($order_id);
            $result['mail'] = $sent['ok'];
            if (!$sent['ok']) {
                $result['not_sent'] = PluginPrintgestionPurchaseorder::getNotSentMessage($sent['error']);
            }

            // ── 5. Mails complémentaires, seulement si la commande est transmise : non bloquants, mais jamais passés sous silence ──
            if ($sent['ok'] && $send_planif && !self::sendOrderPlanifMail($rows, $file['path'])) {
                $result['warnings'][] = __('Commande envoyée aux Achats, mais le mail à la planification n\'est pas parti (destinataires ou modèle non configurés, ou erreur d\'envoi).', 'printgestion');
            }
            if ($sent['ok'] && $send_courtesy) {
                $courtesy = self::sendOrderCourtesyMails($rows);
                if ($courtesy['no_template']) {
                    $result['warnings'][] = __('Mail de courtoisie non envoyé : aucun modèle de notification configuré.', 'printgestion');
                }
                if ($courtesy['no_contact'] > 0) {
                    $result['warnings'][] = sprintf(
                        __('Mail de courtoisie non envoyé pour %d imprimante(s) sans usager renseigné.', 'printgestion'),
                        $courtesy['no_contact']
                    );
                }
                if ($courtesy['failed'] > 0) {
                    $result['warnings'][] = sprintf(
                        __('Échec d\'envoi de %d mail(s) de courtoisie.', 'printgestion'),
                        $courtesy['failed']
                    );
                }
            }
        } finally {
            // Fichier temporaire supprimé dans tous les cas : succès, échec ou exception.
            if (is_file($file['path'])) {
                @unlink($file['path']);
            }
        }

        PluginPrintgestionAlert::invalidateCache();

        return $result;
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
        ];
    }

    /**
     * Envoie le mail de commande aux achats (demandeur en copie) avec le fichier Gesconso
     * joint. Corps synthétique via gabarit_achat (fallback : mail brut) : le détail est
     * dans le fichier. Le fichier appartient à l'appelant, qui le supprime.
     *
     * @param string $xlsx Chemin du fichier Gesconso à joindre.
     * @param ?int $requester_user_id User GLPI à mettre en copie (défaut : user connecté).
     * @return array ['ok' => bool, 'error' => string] — error renseigné quand ok = false.
     */
    public static function sendPurchaseOrderMail(array $rows, string $xlsx, ?int $requester_user_id = null, ?string $xlsx_name = null): array {
        if (empty($rows)) {
            return ['ok' => false, 'error' => __('Aucune ligne à commander.', 'printgestion')];
        }

        $normalize = static function (array $emails): array {
            $out = [];
            foreach ($emails as $e) {
                $e = trim((string)$e);
                if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                    $out[strtolower($e)] = $e;
                }
            }
            return $out;
        };

        // Au moins un destinataire Achats valide est OBLIGATOIRE : le demandeur seul
        // en destinataire ne vaut pas commande (les Achats ne la recevraient jamais).
        $achats = $normalize(PluginPrintgestionAlert::resolveRecipientsForRole('achat'));
        if (empty($achats)) {
            return [
                'ok'    => false,
                'error' => __('Commande non envoyée : aucun destinataire Achats avec une adresse email valide n\'est configuré (Configuration → Print Gestion → Rôles & notifications).', 'printgestion'),
            ];
        }

        // TO = 1er achats ; CC = autres achats + demandeur (dédoublonnés, achats en tête)
        $uid   = $requester_user_id ?? (int)(Session::getLoginUserID() ?: 0);
        $valid = array_values($achats + $normalize(PluginPrintgestionAlert::resolveEmailsForUsers([$uid])));

        $count = count($rows);

        $config  = PluginPrintgestionConfig::getInstance();
        $gabarit = (int)($config->fields['gabarit_achat'] ?? 0);
        $ok      = false;
        $error   = '';

        if ($gabarit > 0) {
            $ok = PluginPrintgestionConfig::sendMail($valid, $gabarit, self::buildPurchaseBalises($rows), $xlsx, $xlsx_name);
            if (!$ok) {
                $error = PluginPrintgestionConfig::getLastMailError();
            }
        } else {
            // Fallback sans gabarit : corps générique
            $to = array_shift($valid);
            $cc = $valid;
            $subject  = sprintf(__('[GLPI] Commande cartouches — %d référence(s)', 'printgestion'), $count);
            $bodyHtml = '<p>' . __('Bonjour,', 'printgestion') . '</p>'
                . '<p>' . sprintf(
                    __('Veuillez trouver ci-joint le fichier Gesconso des cartouches à commander (%d ligne(s)) : code client, adresse de livraison, référence article, quantité et prix.', 'printgestion'),
                    $count
                ) . '</p>'
                . '<p>' . __('Merci.', 'printgestion') . '</p>';
            $raw_error = null;
            $ok = self::sendRawMail($to, $cc, $subject, $bodyHtml, $xlsx, $raw_error, $xlsx_name);
            if (!$ok) {
                $error = (string)$raw_error;
            }
        }

        if (!$ok) {
            return [
                'ok'    => false,
                'error' => sprintf(
                    __('Commande non envoyée : échec de l\'envoi du mail aux Achats (%s).', 'printgestion'),
                    $error !== '' ? $error : __('cause inconnue', 'printgestion')
                ),
            ];
        }
        return ['ok' => true, 'error' => ''];
    }

    /**
     * Envoi direct d'un mail (GLPIMailer/Symfony) sans gabarit : sujet + corps HTML
     * + pièce jointe optionnelle. $to = destinataire principal, $cc = copies.
     */
    protected static function sendRawMail(string $to, array $cc, string $subject, string $bodyHtml, ?string $attachment = null, ?string &$error = null, ?string $attachment_name = null): bool {
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
        // Pièce jointe attendue (fichier Gesconso) : jamais de mail sans elle.
        if ($attachment !== null && $attachment !== '') {
            if (!is_file($attachment) || !is_readable($attachment) || filesize($attachment) === 0) {
                $error = __('pièce jointe introuvable ou vide, mail non envoyé', 'printgestion');
                PluginPrintgestionLogger::error('Expedition::sendRawMail', sprintf('Mail « %s » non envoyé : pièce jointe %s introuvable ou vide.', $subject, basename($attachment)));
                return false;
            }
            $emailObj->attachFromPath($attachment, $attachment_name);
            if (count($emailObj->getAttachments()) === 0) {
                $error = __('pièce jointe non attachée au message, mail non envoyé', 'printgestion');
                PluginPrintgestionLogger::error('Expedition::sendRawMail', sprintf('Mail « %s » non envoyé : pièce jointe %s non attachée.', $subject, basename($attachment)));
                return false;
            }
        }

        $mmail->Subject = $subject;
        $mmail->Body    = $bodyHtml;
        $mmail->AltBody = strip_tags($bodyHtml);

        // Cause de l'échec renvoyée à l'appelant ($error), null si succès.
        $ok    = (bool)$mmail->send();
        $error = $ok ? null : (string)$mmail->getError();
        return $ok;
    }

    /**
     * Emails du CLIENT à prévenir pour une imprimante (mail de courtoisie) :
     * uniquement l'usager renseigné sur la fiche imprimante (champ « Utilisateur »).
     * Sans usager identifié, ou sans email, AUCUN destinataire : plus de repli sur
     * les utilisateurs ayant un profil sur l'entité (techniciens internes compris).
     */
    public static function resolveClientEmailsForPrinter(int $printers_id): array {
        $printer = new Printer();
        if (!$printer->getFromDB($printers_id)) {
            return [];
        }

        $uid = (int)($printer->fields['users_id'] ?? 0);
        if ($uid <= 0) {
            return [];
        }
        return PluginPrintgestionAlert::resolveEmailsForUsers([$uid]);
    }

    /**
     * Mail de planification (logistique) pour une commande — simple ou multi :
     *   - 1 cartouche  → gabarit_planif (détail unitaire client/imprimante/cartouche)
     *   - N cartouches → gabarit_planif_group, liste avec CLIENT par ligne
     *     (une commande peut couvrir plusieurs imprimantes / plusieurs clients).
     * Demandeur en copie. Fallback mail brut si aucun gabarit configuré.
     *
     * @param ?string $xlsx Fichier Gesconso joint à l'envoi groupé (supprimé par l'appelant).
     */
    protected static function sendOrderPlanifMail(array $rows, ?string $xlsx = null): bool {
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
                $list .= '<li>'
                    . '<strong>' . htmlspecialchars((string)($r['cartridge_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</strong>'
                    . ' — ' . htmlspecialchars((string)($r['client'] ?? ''), ENT_QUOTES, 'UTF-8')
                    . ' — ' . htmlspecialchars((string)($r['printer_name'] ?? ''), ENT_QUOTES, 'UTF-8')
                    . '</li>';
            }
            if (count($rows) > self::MAIL_LIST_MAX) {
                $list .= '<li>' . htmlspecialchars(sprintf(__('… et %d autres — détail complet dans le fichier Excel joint', 'printgestion'), count($rows) - self::MAIL_LIST_MAX), ENT_QUOTES, 'UTF-8') . '</li>';
            }
            $list .= '</ul>';

            $balises = self::buildPurchaseBalises($rows);
            $balises['##printgestion.cartridges_list##'] = $list;
            $balises['##printgestion.contract##']        = '';
            $balises['##printgestion.days##']            = 'N/A';

            return PluginPrintgestionConfig::sendMail($valid, $gabarit, $balises, $xlsx);
        }

        // ── Fallback sans gabarit : mail brut (ancien comportement) ──
        $to = array_shift($valid);
        $cc = $valid;

        $list = '<ul>';
        foreach ($rows as $r) {
            $list .= '<li><strong>' . htmlspecialchars((string)($r['printer_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</strong> — '
                . htmlspecialchars((string)($r['client'] ?? ''), ENT_QUOTES, 'UTF-8') . ' — '
                . htmlspecialchars((string)($r['cartridge_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</li>';
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
    protected static function sendOrderCourtesyMails(array $rows): array {
        // Bilan renvoyé à l'appelant : rien ne doit rester non signalé.
        $stats  = ['sent' => 0, 'failed' => 0, 'no_contact' => 0, 'no_template' => false];
        $config = PluginPrintgestionConfig::getInstance();
        $gab    = (int)($config->fields['gabarit_courtoisie'] ?? 0);
        if ($gab <= 0) {
            $stats['no_template'] = true;
            return $stats;
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
            $norm = [];
            foreach ($emails as $e) {
                $e = strtolower(trim((string)$e));
                if ($e !== '') {
                    $norm[$e] = $e;
                }
            }
            if (empty($norm)) {
                $stats['no_contact']++;
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

            $sent = PluginPrintgestionConfig::sendMail($g['emails'], $gab, [
                '##printgestion.printer##'       => implode(', ', $printers),
                '##printgestion.client##'        => implode(', ', array_values($clients)),
                '##printgestion.printers_list##' => $list,
                '##printgestion.count##'         => (string)count($g['printers']),
            ]);
            $stats[$sent ? 'sent' : 'failed']++;
        }

        return $stats;
    }

    /**
     * Vrai si l'exception est la violation de la clé unique « un seul envoi en cours
     * par imprimante et toner » (erreur MySQL/MariaDB 1062 sur uniq_active_slot).
     */
    public static function isDuplicateActiveError(Throwable $e): bool {
        $message = $e->getMessage();
        return str_contains($message, 'uniq_active_slot')
            || (str_contains($message, '(1062)') && str_contains($message, 'glpi_plugin_printgestion_expeditions'));
    }

    /**
     * Génère un UUID v4 (RFC 4122) sans dépendance externe : 36 caractères, la taille de
     * la colonne group_id (commande directe et export des demandes d'envoi).
     */
    public static function generateUuid(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Passe une expédition au statut "shipped" après saisie par la planif.
     * Seul un envoi commandé (en attente) peut être marqué expédié : un envoi déjà
     * expédié, livré, posé ou annulé n'est ni modifié ni rouvert ici.
     */
    public static function markShipped(int $expedition_id, string $carrier, string $tracking, ?int $bl_surveys_id = null): bool {
        global $DB;

        // Transporteur inconnu ou non choisi : refus, jamais « Autre » en silence.
        if (!in_array($carrier, self::CARRIERS_OFFERED, true)) {
            return false;
        }

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

        $ok = $DB->update(self::getTable(), $data, [
            'id'     => $expedition_id,
            'statut' => self::STATUS_PENDING,
        ]) && $DB->affectedRows() > 0;
        if ($ok) {
            self::notifyShippedToCommercial($expedition_id);
        }
        return $ok;
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
     * Appelée depuis cartridgehistory quand une pose est détectée (hausse de niveau) :
     * clôt l'envoi en cours de cette imprimante et de ce toner. Avec la confirmation
     * manuelle, c'est le seul événement qui clôt un envoi (« livrée » ne le fait pas).
     */
    public static function markInstalledOnDetection(int $printers_id, string $property, string $detected_at): void {
        global $DB;

        $active = self::getActiveForPrinterProperty($printers_id, $property);
        if ($active === null) {
            return;
        }

        $DB->update(self::getTable(), [
            'statut'         => self::STATUS_INSTALLED,
            'date_installed' => $detected_at,
            'date_delivered' => !empty($active['date_delivered']) ? $active['date_delivered'] : $detected_at,
        ], ['id' => (int)$active['id']]);
    }

    /**
     * Cron : rappel si une cartouche expédiée (expédiée, en transit ou livrée) n'est
     * toujours pas posée plus de reminder_days après l'expédition.
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
                // « Livrée » n'est pas « posée » : le rappel continue jusqu'à la pose.
                'statut'       => ['shipped', 'transit', 'delivered'],
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

            // Donnée commerciale : le client de l'envoi est l'entité figée à sa création.
            $entity_name = '';
            $entity = new Entity();
            if ($entity->getFromDB((int)$exp['entities_id'])) {
                $entity_name = (string)$entity->fields['completename'];
            }

            $items[] = [
                'scope'       => ['entities_id' => (int)$exp['entities_id'], 'is_recursive' => (int)$exp['is_recursive']],
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
                . ' — ' . htmlspecialchars(sprintf(__('expédiée il y a %d j', 'printgestion'), (int)$it['days']), ENT_QUOTES, 'UTF-8')
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
                'no_install_reminder',
                $it['scope']
            );
        }

        return count($items);
    }

    /**
     * Motif de refus d'une réattribution, chaîne vide si elle est acceptée. Réattribution
     * manuelle uniquement, d'un envoi déjà parti, vers l'imprimante où une alerte « mauvaise
     * imprimante » encore ouverte a détecté la pose : jamais vers une autre machine, jamais
     * un envoi en attente, posé ou annulé.
     */
    public static function getReassignRefusal(int $expedition_id, int $new_printers_id): string {
        global $DB;

        $exp = $DB->request([
            'SELECT' => ['id', 'statut'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['id' => $expedition_id],
            'LIMIT'  => 1,
        ])->current();
        if (!is_array($exp)) {
            return sprintf(__('Réattribution refusée : envoi #%d introuvable.', 'printgestion'), $expedition_id);
        }
        $statut = (string) $exp['statut'];
        if (in_array($statut, [self::STATUS_INSTALLED, 'cancelled'], true)) {
            return sprintf(__('Réattribution refusée : l\'envoi #%d est déjà clos (posé ou annulé).', 'printgestion'), $expedition_id);
        }
        if (!in_array($statut, self::DEPARTED_STATUSES, true)) {
            return sprintf(
                __('Réattribution refusée : l\'envoi #%d n\'est pas encore parti ; seul un envoi expédié, en transit ou livré peut avoir été posé ailleurs.', 'printgestion'),
                $expedition_id
            );
        }

        $alert = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_printgestion_alerts',
            'WHERE'  => [
                'alert_type'           => 'wrong_printer',
                'expeditions_id'       => $expedition_id,
                'detected_printers_id' => $new_printers_id,
                'is_resolved'          => 0,
            ],
            'LIMIT'  => 1,
        ])->current();
        if (!is_array($alert)) {
            return sprintf(
                __('Réattribution refusée : aucune alerte « mauvaise imprimante » en cours n\'a détecté la pose de l\'envoi #%d sur cette imprimante.', 'printgestion'),
                $expedition_id
            );
        }
        return '';
    }

    /** Résout les alertes « mauvaise imprimante » ouvertes d'un envoi (réattribué, annulé ou posé). */
    public static function resolveWrongPrinterAlerts(int $expedition_id): void {
        global $DB;

        $DB->update('glpi_plugin_printgestion_alerts', [
            'is_resolved' => 1,
        ], [
            'alert_type'     => 'wrong_printer',
            'expeditions_id' => $expedition_id,
            'is_resolved'    => 0,
        ]);
    }

    /**
     * Réassigne une expédition à une autre imprimante, depuis une alerte wrong_printer
     * où l'utilisateur, après vérification, accepte le nouveau destinataire. Refusée si
     * getReassignRefusal() donne un motif.
     *
     * Effets :
     *   - Met à jour expedition.printers_id = $new_printers_id
     *   - Crée rétroactivement une entrée glpi_cartridges sur la nouvelle imprimante
     *     (puisque le flux normal avait été skippé lors de la détection wrong_printer)
     *   - Clôture l'alerte wrong_printer associée
     *   - Passe l'expédition en « posée » (la cartouche a été constatée posée sur la
     *     nouvelle imprimante) : l'envoi est clos
     */
    public static function reassignToPrinter(int $expedition_id, int $new_printers_id): bool {
        global $DB;

        if ($expedition_id <= 0 || $new_printers_id <= 0
            || self::getReassignRefusal($expedition_id, $new_printers_id) !== '') {
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

        // 1. Met à jour l'expédition : constatée posée sur la nouvelle imprimante.
        $now_date = date('Y-m-d H:i:s');
        // Donnée commerciale : l'envoi et ses liaisons BL gardent l'entité figée à la création.
        $DB->update(self::getTable(), [
            'printers_id'    => $new_printers_id,
            'statut'         => self::STATUS_INSTALLED,
            'date_installed' => $now_date,
            'date_delivered' => !empty($exp['date_delivered']) ? $exp['date_delivered'] : $now_date,
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
        self::resolveWrongPrinterAlerts($expedition_id);

        return true;
    }

    static function install(Migration $migration) { return true; }
    static function uninstall(Migration $migration) { return true; }
}
