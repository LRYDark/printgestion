<?php
/**
 * Relevé manuel d'une imprimante sans sonde : niveaux et compteurs saisis par un technicien, d'après ce que le
 * client a envoyé ou dit au téléphone.
 *
 * Le relevé est écrit là où l'inventaire GLPI l'aurait écrit — les niveaux dans `glpi_printers_cartridgeinfos`, les
 * compteurs dans `glpi_printerlogs` et `last_pages_counter` de l'imprimante —, par les classes natives
 * (Printer_CartridgeInfo, PrinterLog, Printer : historique de GLPI compris). Tout l'aval du plugin (alertes, jours
 * restants, cartouche changée, commande, expédition, coût à la page) lit ces tables : il ne fait aucune
 * différence. Le plugin garde en plus la trace de chaque relevé (qui, quand, quoi, commentaire) et pose le relevé
 * du jour dans sa table de relevés, à la date dite par le client, marqué « manuel ».
 *
 * La sonde a toujours raison : un inventaire réseau reçu après un relevé manuel rend ce relevé caduc
 * (supersedeByInventory) — les valeurs natives sont déjà celles de l'inventaire, le relevé du plugin est retiré,
 * la trace reste marquée « dépassée ».
 *
 * Le bouton vit dans les onglets natifs « Cartouches » et « Compteurs de pages » de l'imprimante (hook
 * POST_SHOW_TAB de GLPI), dans la carte « Informations d'inventaire manuel » sous la fiche (hook POST_ITEM_FORM)
 * et par le clic droit des alertes : rien dans le code de GLPI.
 */
class PluginPrintgestionManualreading extends CommonDBTM {

    static $rightname = 'plugin_printgestion_dashboard';

    /** Emplacements proposés à toute imprimante, même jamais inventoriée. */
    const STANDARD_SLOTS = ['tonerblack', 'tonercyan', 'tonermagenta', 'toneryellow'];

    const SOURCE_MANUAL = 'manual';

    /** Les compteurs du journal natif des imprimantes (glpi_printerlogs), dans l'ordre de la fenêtre. */
    const COUNTERS = [
        'total_pages', 'bw_pages', 'color_pages', 'rv_pages',
        'prints', 'bw_prints', 'color_prints',
        'copies', 'bw_copies', 'color_copies',
        'scanned', 'faxed',
    ];

    private static bool $table_checked = false;

    public static function getTable($classname = null) {
        return 'glpi_plugin_printgestion_manualreadings';
    }

    static function getTypeName($nb = 0) {
        return _n('Relevé manuel', 'Relevés manuels', $nb, 'printgestion');
    }

    /** Tables et colonnes que ce relevé suppose, créées si elles manquent (installation antérieure). */
    public static function ensureTable(): void {
        if (self::$table_checked) {
            return;
        }
        self::$table_checked = true;
        PluginPrintgestionSchema::createIfMissing(self::getTable());
        PluginPrintgestionSchema::ensureColumn(self::getTable(), 'superseded', 'tinyint NOT NULL DEFAULT 0');
        PluginPrintgestionSchema::ensureColumn(self::getTable(), 'superseded_date', 'timestamp NULL DEFAULT NULL');
        PluginPrintgestionSchema::ensureColumn(self::getTable(), 'counters', 'text NULL DEFAULT NULL');
        // L'origine d'un relevé du plugin : « snmp » (photographie de l'inventaire) ou « manual ».
        PluginPrintgestionSchema::ensureColumn(PluginPrintgestionTonerreading::getTable(), 'source', "varchar(10) NOT NULL DEFAULT 'snmp'");
    }

    // ── Droits ─────────────────────────────────────────────────────────────

    /** Saisir : module toner, droit Alertes toner en modification, imprimante du périmètre. */
    public static function canRecord(Printer $printer): bool {
        return PluginPrintgestionConfig::isFeatureEnabled('toner')
            && Session::haveRight(self::$rightname, UPDATE)
            && $printer->canViewItem();
    }

    /** Voir les relevés : module toner, lecture des alertes toner, imprimante du périmètre. */
    public static function canSee(Printer $printer): bool {
        return PluginPrintgestionConfig::isFeatureEnabled('toner')
            && Session::haveRight(self::$rightname, READ)
            && $printer->canViewItem();
    }

    // ── Emplacements et libellés ───────────────────────────────────────────

    /** Libellé lisible d'une propriété SNMP : « Toner noir », « Tambour cyan », « Four »… */
    public static function labelFor(string $property): string {
        $lower  = mb_strtolower($property);
        $colors = [
            'black'   => __('noir', 'printgestion'),
            'cyan'    => __('cyan', 'printgestion'),
            'magenta' => __('magenta', 'printgestion'),
            'yellow'  => __('jaune', 'printgestion'),
        ];
        $kinds = [
            'toner'          => __('Toner', 'printgestion'),
            'drum'           => __('Tambour', 'printgestion'),
            'cartridge'      => __('Cartouche', 'printgestion'),
            'developer'      => __('Développeur', 'printgestion'),
            'fuserkit'       => __('Four', 'printgestion'),
            'transferkit'    => __('Courroie de transfert', 'printgestion'),
            'wastetoner'     => __('Bac de récupération', 'printgestion'),
            'maintenancekit' => __('Kit de maintenance', 'printgestion'),
        ];
        foreach ($kinds as $prefix => $kind) {
            if (str_starts_with($lower, $prefix)) {
                $color = PluginPrintgestionSnmpmapping::detectColor($lower);
                return isset($colors[$color]) ? $kind . ' ' . $colors[$color] : $kind;
            }
        }
        return $property;
    }

    /** Famille d'un emplacement (toner, tambour, autre) et sa couleur, pour ranger la fenêtre. */
    public static function classify(string $property): array {
        $lower = mb_strtolower($property);
        $kind  = str_starts_with($lower, 'toner') || str_starts_with($lower, 'cartridge') ? 'toner'
            : (str_starts_with($lower, 'drum') ? 'drum' : 'other');
        return ['kind' => $kind, 'color' => $kind === 'other' ? 'other' : PluginPrintgestionSnmpmapping::detectColor($lower)];
    }

    /**
     * Emplacements à proposer dans la fenêtre : ceux que l'imprimante connaît déjà (niveaux lisibles de GLPI),
     * plus les quatre toners standard — une imprimante jamais inventoriée n'en connaît aucun. Rangés par famille
     * (toners, tambours, autres) puis par couleur (noir, cyan, magenta, jaune).
     *
     * @return array property => ['label' => string, 'current' => ?int, 'kind' => string, 'color' => string]
     */
    public static function getSlots(Printer $printer): array {
        $printers_id = (int) $printer->getID();
        $slots       = [];
        foreach (PluginPrintgestionSnmpadapter::getLevels([$printers_id])[$printers_id] ?? [] as $property => $parsed) {
            $slots[(string) $property] = [
                'label'   => self::labelFor((string) $property),
                'current' => !empty($parsed['usable']) ? (int) $parsed['value'] : null,
            ] + self::classify((string) $property);
        }
        foreach (self::STANDARD_SLOTS as $property) {
            $slots[$property] = $slots[$property] ?? (['label' => self::labelFor($property), 'current' => null] + self::classify($property));
        }
        $kinds  = ['toner' => 0, 'drum' => 1, 'other' => 2];
        $colors = ['black' => 0, 'cyan' => 1, 'magenta' => 2, 'yellow' => 3, 'other' => 4];
        uasort($slots, static fn(array $a, array $b): int => [$kinds[$a['kind']], $colors[$a['color']] ?? 4, $a['label']] <=> [$kinds[$b['kind']], $colors[$b['color']] ?? 4, $b['label']]);
        return $slots;
    }

    /**
     * Dernier relevé manuel non dépassé de chaque imprimante : printers_id => ['date' (datetime), 'users_id',
     * 'count']. Un relevé dépassé par un inventaire ne compte plus : la sonde a repris la main.
     */
    public static function getLastForPrinters(array $printer_ids): array {
        global $DB;

        $ids = array_values(array_unique(array_filter(array_map('intval', $printer_ids))));
        if (empty($ids)) {
            return [];
        }
        self::ensureTable();
        $out = [];
        foreach ($DB->request([
            'SELECT'  => ['printers_id', new QueryExpression('MAX(`reading_date`) AS `last`'), 'COUNT' => 'id AS n'],
            'FROM'    => self::getTable(),
            'WHERE'   => ['printers_id' => $ids, 'superseded' => 0],
            'GROUPBY' => ['printers_id'],
        ]) as $row) {
            $out[(int) $row['printers_id']] = ['date' => (string) $row['last'] . ' 00:00:00', 'users_id' => 0, 'count' => (int) $row['n']];
        }
        if (!empty($out)) {
            // L'auteur du dernier relevé : une lecture pour toutes les imprimantes concernées.
            foreach ($DB->request([
                'SELECT' => ['printers_id', 'reading_date', 'users_id'],
                'FROM'   => self::getTable(),
                'WHERE'  => ['printers_id' => array_keys($out), 'superseded' => 0],
                'ORDER'  => ['id DESC'],
            ]) as $row) {
                $pid = (int) $row['printers_id'];
                if ($out[$pid]['users_id'] === 0 && (string) $row['reading_date'] . ' 00:00:00' === $out[$pid]['date']) {
                    $out[$pid]['users_id'] = (int) $row['users_id'];
                }
            }
        }
        return $out;
    }

    /** Les derniers relevés d'une imprimante, dépassés compris, du plus récent au plus ancien. */
    public static function getHistory(int $printers_id, int $limit = 10): array {
        global $DB;

        self::ensureTable();
        return iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['printers_id' => $printers_id],
            'ORDER' => ['reading_date DESC', 'id DESC'],
            'LIMIT' => $limit,
        ]), false);
    }

    /** Derniers compteurs connus du journal natif (la ligne la plus récente), pour les valeurs actuelles de la fenêtre. */
    public static function getCurrentCounters(Printer $printer): array {
        global $DB;

        $row = $DB->request([
            'SELECT' => self::COUNTERS,
            'FROM'   => PrinterLog::getTable(),
            'WHERE'  => ['itemtype' => Printer::class, 'items_id' => (int) $printer->getID()],
            'ORDER'  => ['date DESC', 'id DESC'],
            'LIMIT'  => 1,
        ])->current();
        $out = [];
        foreach (self::COUNTERS as $field) {
            $out[$field] = is_array($row) ? (int) $row[$field] : 0;
        }
        if ($out['total_pages'] === 0) {
            $out['total_pages'] = (int) ($printer->fields['last_pages_counter'] ?? 0);
        }
        return $out;
    }

    /** L'imprimante est-elle relevée par une sonde (un inventaire réseau connu, ou une date d'inventaire sur la fiche) ? */
    public static function hasInventory(Printer $printer): bool {
        $printers_id = (int) $printer->getID();
        return !empty($printer->fields['last_inventory_update'])
            || !empty(PluginPrintgestionCollect::getImportDates([$printers_id])[$printers_id]['snmp'] ?? null);
    }

    /** La carte native « Informations d'inventaire » est-elle affichée pour cet utilisateur ? (même règle que la sonde) */
    private static function nativeCardShown(Printer $printer): bool {
        return Session::haveRight('inventory', READ) && $printer->isDynamic();
    }

    /**
     * Hook AUTOINVENTORY_INFORMATION : le bloc « Informations d'inventaire manuel » dans la carte native, après
     * « Sonde responsable », avec la même présentation — un bloc de plus de la carte, pas une carte dans la carte.
     */
    public static function showInInventoryCard($item): void {
        if (!$item instanceof Printer || (int) $item->getID() <= 0 || !self::canSee($item) || !self::nativeCardShown($item)) {
            return;
        }
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        // Exactement le balisage des blocs natifs de cette carte (« card-body row », sans bordure explicite) : le
        // trait entre deux blocs vient du style de GLPI, à la même largeur que les autres.
        echo "<div class='card-body row' id='pg-manual-card'>";
        echo "<div class='col-12 mb-2 fw-bold'><i class='ti ti-pencil me-1'></i>" . $esc(__('Informations d\'inventaire manuel (Print Gestion)', 'printgestion')) . "</div>";
        self::renderFields($item);
        echo "</div>";
    }

    // ── Enregistrement ─────────────────────────────────────────────────────

    /**
     * Enregistre un relevé : contrôles, tables natives, trace du plugin, relevé du jour, alertes de l'imprimante.
     * Le relevé est daté du jour, à l'heure de l'enregistrement — comme un passage de sonde, rien à saisir.
     *
     * @param array $input levels (property => '' | 0-100), counters (compteur du journal natif => '' | entier), comment
     * @return array ['ok' => bool, 'errors' => string[], 'warnings' => string[]]
     */
    public static function record(Printer $printer, array $input): array {
        global $DB;

        self::ensureTable();
        $printers_id = (int) $printer->getID();
        $errors      = [];
        $warnings    = [];
        $date        = date('Y-m-d');

        // Niveaux : propriétés connues ou standard, 0 à 100, vide = inchangé.
        $levels = [];
        $slots  = self::getSlots($printer);
        foreach ((array) ($input['levels'] ?? []) as $property => $value) {
            $property = (string) $property;
            $value    = trim((string) $value);
            if ($value === '') {
                continue;
            }
            if (!isset($slots[$property]) || !preg_match('/^[a-z0-9_]{1,64}$/', $property)) {
                $errors[] = sprintf(__('Emplacement inconnu : %s.', 'printgestion'), $property);
                continue;
            }
            if (!preg_match('/^\d{1,3}$/', $value) || (int) $value > 100) {
                $errors[] = sprintf(__('Niveau de « %s » : un nombre entre 0 et 100.', 'printgestion'), $slots[$property]['label']);
                continue;
            }
            $levels[$property] = (int) $value;
        }

        // Compteurs : ceux du journal natif, tous facultatifs, vide = pas écrit. Le total est déduit de noir + couleur
        // s'il manque, et noir de total − couleur : les mêmes règles que le coût à la page.
        $counters = [];
        foreach (self::COUNTERS as $field) {
            $value = trim((string) ($input['counters'][$field] ?? ''));
            if ($value === '') {
                continue;
            }
            if (!preg_match('/^\d{1,9}$/', $value)) {
                $errors[] = sprintf(__('« %s » : un nombre entier de pages.', 'printgestion'), PrinterLog::getLabelFor($field));
                continue;
            }
            $counters[$field] = (int) $value;
        }
        if (!isset($counters['total_pages']) && isset($counters['bw_pages'], $counters['color_pages'])) {
            $counters['total_pages'] = $counters['bw_pages'] + $counters['color_pages'];
        }
        if (isset($counters['total_pages'], $counters['color_pages']) && !isset($counters['bw_pages'])) {
            $counters['bw_pages'] = max(0, $counters['total_pages'] - $counters['color_pages']);
        }
        foreach ([['color_pages', 'total_pages'], ['bw_pages', 'total_pages'], ['color_prints', 'prints'], ['bw_prints', 'prints'], ['color_copies', 'copies'], ['bw_copies', 'copies']] as [$part, $whole]) {
            if (isset($counters[$part], $counters[$whole]) && $counters[$part] > $counters[$whole]) {
                $errors[] = sprintf(__('« %1$s » supérieur à « %2$s ».', 'printgestion'), PrinterLog::getLabelFor($part), PrinterLog::getLabelFor($whole));
            }
        }
        if (empty($levels) && empty($counters)) {
            $errors[] = __('Rien à enregistrer : au moins un niveau ou un compteur.', 'printgestion');
        }
        $comment = mb_substr(trim((string) ($input['comment'] ?? '')), 0, 1000);
        if (!empty($errors)) {
            return ['ok' => false, 'errors' => $errors, 'warnings' => $warnings];
        }
        $total_pages = $counters['total_pages'] ?? null;
        $color_pages = $counters['color_pages'] ?? null;

        // Un compteur plus bas que le précédent : signalé, pas refusé (compteur remis à zéro, imprimante changée).
        $previous = (int) ($printer->fields['last_pages_counter'] ?? 0);
        if ($total_pages !== null && $previous > 0 && $total_pages < $previous) {
            $warnings[] = sprintf(__('Compteur saisi (%1$d) inférieur au précédent (%2$d) : enregistré tel quel.', 'printgestion'), $total_pages, $previous);
        }
        // Noir + couleur différent du total : les trois viennent du client, on garde ce qu'il a dit et on le signale.
        if (isset($counters['total_pages'], $counters['bw_pages'], $counters['color_pages'])
            && $counters['bw_pages'] + $counters['color_pages'] !== $counters['total_pages']) {
            $warnings[] = sprintf(
                __('Noir & blanc (%1$d) + couleur (%2$d) ne font pas le total (%3$d) : enregistré tel quel.', 'printgestion'),
                $counters['bw_pages'],
                $counters['color_pages'],
                $counters['total_pages']
            );
        }
        // Une sonde relève déjà cette imprimante : elle reprendra la main à son prochain passage.
        if (self::hasInventory($printer)) {
            $warnings[] = __('Cette imprimante est relevée par une sonde : au prochain inventaire, ses valeurs remplaceront ce relevé.', 'printgestion');
        }

        // 1. Niveaux, là où l'inventaire les écrit : une ligne par emplacement.
        foreach ($levels as $property => $level) {
            $info = new Printer_CartridgeInfo();
            if ($info->getFromDBByCrit(['printers_id' => $printers_id, 'property' => $property])) {
                if (!$info->update(['id' => (int) $info->getID(), 'value' => (string) $level])) {
                    return ['ok' => false, 'errors' => [__('Niveau non enregistré (refus de GLPI) : rien d\'autre n\'a été écrit.', 'printgestion')], 'warnings' => $warnings];
                }
            } elseif (!$info->add(['printers_id' => $printers_id, 'property' => $property, 'value' => (string) $level])) {
                return ['ok' => false, 'errors' => [__('Niveau non enregistré (refus de GLPI) : rien d\'autre n\'a été écrit.', 'printgestion')], 'warnings' => $warnings];
            }
        }

        // 2. Compteurs, là où l'inventaire les écrit : le journal du jour (une ligne par date, complétée si elle
        //    existe) et le compteur de la fiche.
        if (!empty($counters)) {
            $log = new PrinterLog();
            $row = ['itemtype' => Printer::class, 'items_id' => $printers_id, 'date' => $date] + $counters;
            if ($log->getFromDBByCrit(['itemtype' => Printer::class, 'items_id' => $printers_id, 'date' => $date])) {
                $ok = $log->update(['id' => (int) $log->getID()] + $row);
            } else {
                $ok = (bool) $log->add($row);
            }
            if (!$ok) {
                return ['ok' => false, 'errors' => [__('Compteurs non enregistrés (refus de GLPI).', 'printgestion')], 'warnings' => $warnings];
            }
            if ($total_pages !== null) {
                $printer->update(['id' => $printers_id, 'last_pages_counter' => $total_pages]);
            }
            // Le tableau de bord du coût à la page garde ses calculs dix minutes : des compteurs saisis à la main
            // doivent s'y lire tout de suite, comme dans l'onglet de l'imprimante.
            PluginPrintgestionBilling::invalidateCache();
        }

        // 3. La trace du plugin : qui, quand, quoi.
        $DB->insert(self::getTable(), [
            'printers_id'   => $printers_id,
            'entities_id'   => (int) $printer->fields['entities_id'],
            'is_recursive'  => (int) $printer->fields['is_recursive'],
            'reading_date'  => $date,
            'levels'        => json_encode($levels),
            'counters'      => json_encode($counters),
            'total_pages'   => $total_pages,
            'color_pages'   => $color_pages,
            'comment'       => $comment,
            'users_id'      => (int) Session::getLoginUserID(),
            'date_creation' => date('Y-m-d H:i:s'),
            'superseded'    => 0,
        ]);

        // 4. Le relevé du plugin à la date dite par le client, marqué manuel, pour les estimations et la détection
        //    de cartouche changée (le passage quotidien retrouvera les mêmes valeurs dans GLPI et n'ajoutera rien).
        if (!empty($levels)) {
            $pages = $total_pages ?? PluginPrintgestionCartridgehistory::getPrinterPagesCounter($printers_id);
            $bw    = $pages - ($color_pages ?? 0);
            foreach ($levels as $property => $level) {
                $DB->updateOrInsert(PluginPrintgestionTonerreading::getTable(), [
                    'level_percent' => $level,
                    'total_pages'   => $pages,
                    'bw_pages'      => max(0, $bw),
                    'color_pages'   => $color_pages ?? 0,
                    'is_suspect'    => 0,
                    'source'        => self::SOURCE_MANUAL,
                    'entities_id'   => (int) $printer->fields['entities_id'],
                    'is_recursive'  => (int) $printer->fields['is_recursive'],
                ], [
                    'printers_id'   => $printers_id,
                    'property_name' => $property,
                    'reading_date'  => $date . ' 00:00:00',
                ]);
            }
            // Une cartouche neuve saisie ferme l'envoi en cours : la détection n'attend pas la nuit.
            PluginPrintgestionCartridgehistory::detectChanges();
        }

        // 5. Les alertes de cette imprimante, tout de suite.
        PluginPrintgestionAlert::invalidateCache();
        PluginPrintgestionAlertview::rebuild([$printers_id]);

        return ['ok' => true, 'errors' => [], 'warnings' => $warnings];
    }

    /**
     * La sonde a raison : un inventaire réseau reçu après un relevé manuel rend ce relevé caduc. Les valeurs
     * natives sont déjà celles de l'inventaire ; les relevés du plugin marqués manuels de ce relevé sont retirés
     * (sans quoi une hausse de niveau saisie par erreur passerait pour une cartouche changée) et la trace est
     * marquée « dépassée » avec la date de l'inventaire. Appelé avant chaque photographie quotidienne des niveaux
     * et chaque recalcul des alertes.
     *
     * @return int relevés dépassés
     */
    public static function supersedeByInventory(): int {
        global $DB;

        self::ensureTable();
        $rows = iterator_to_array($DB->request(['FROM' => self::getTable(), 'WHERE' => ['superseded' => 0]]), false);
        if (empty($rows)) {
            return 0;
        }
        $dates = PluginPrintgestionCollect::getImportDates(array_values(array_unique(array_map(static fn(array $r): int => (int) $r['printers_id'], $rows))));
        $count = 0;
        foreach ($rows as $row) {
            $pid  = (int) $row['printers_id'];
            $snmp = $dates[$pid]['snmp'] ?? null;
            if ($snmp === null || (string) $snmp <= (string) $row['date_creation']) {
                continue;
            }
            $levels = json_decode((string) $row['levels'], true) ?: [];
            if (!empty($levels)) {
                $DB->delete(PluginPrintgestionTonerreading::getTable(), [
                    'printers_id'   => $pid,
                    'property_name' => array_keys($levels),
                    'reading_date'  => (string) $row['reading_date'] . ' 00:00:00',
                    'source'        => self::SOURCE_MANUAL,
                ]);
            }
            $DB->update(self::getTable(), ['superseded' => 1, 'superseded_date' => (string) $snmp], ['id' => (int) $row['id']]);
            $count++;
        }
        return $count;
    }

    // ── Affichage ──────────────────────────────────────────────────────────

    /** Phrase « Suivi manuel : N relevés, le dernier le … par … », ou chaîne vide. */
    public static function getSummary(int $printers_id): string {
        $last = self::getLastForPrinters([$printers_id])[$printers_id] ?? null;
        if ($last === null) {
            return '';
        }
        return sprintf(
            _n('Suivi manuel : %1$d relevé, le dernier le %2$s par %3$s.', 'Suivi manuel : %1$d relevés, le dernier le %2$s par %3$s.', $last['count'], 'printgestion'),
            $last['count'],
            Html::convDate(substr($last['date'], 0, 10)),
            $last['users_id'] > 0 ? getUserName($last['users_id']) : __('inconnu', 'printgestion')
        );
    }

    /** Ce qu'un relevé contient, en une ligne : « Toner noir 8 %, Pages 12 340, Impressions couleur 340 ». */
    private static function describe(array $row): string {
        $levels = json_decode((string) $row['levels'], true) ?: [];
        $parts  = [];
        foreach ($levels as $property => $level) {
            $parts[] = self::labelFor((string) $property) . ' ' . (int) $level . ' %';
        }
        $counters = json_decode((string) ($row['counters'] ?? ''), true);
        if (!is_array($counters) || empty($counters)) {
            // Relevés d'avant la saisie de tous les compteurs : total et couleur seulement.
            $counters = array_filter(['total_pages' => $row['total_pages'], 'color_pages' => $row['color_pages']], static fn($v) => $v !== null);
        }
        foreach (self::COUNTERS as $field) {
            if (isset($counters[$field])) {
                $parts[] = PrinterLog::getLabelFor($field) . ' ' . number_format((int) $counters[$field], 0, ',', ' ');
            }
        }
        return implode(', ', $parts);
    }

    /** Bouton et historique dans un onglet natif de l'imprimante (Cartouches, Compteurs de pages) : hook POST_SHOW_TAB. */
    public static function renderForTab(Printer $printer): void {
        if (!self::canSee($printer)) {
            return;
        }
        echo "<div class='card mt-3'><div class='card-body'>";
        self::renderButton($printer, true);
        echo "</div></div>";
    }

    /**
     * Hook POST_ITEM_FORM : la carte « Informations d'inventaire manuel » sous la fiche de l'imprimante — pour une
     * imprimante qui n'a pas d'inventaire réseau, ou qui a déjà un relevé manuel. Là où la carte native
     * « Informations d'inventaire » n'a rien à dire, celle-ci dit quand et par qui la machine a été relevée.
     */
    public static function showAfterForm(array $params): void {
        $item = $params['item'] ?? null;
        if (!$item instanceof Printer || (int) $item->getID() <= 0 || !self::canSee($item) || self::nativeCardShown($item)) {
            return;
        }
        self::showCard($item);
    }

    /** La carte « Informations d'inventaire manuel » sous le formulaire, quand la carte native n'est pas affichée. */
    public static function showCard(Printer $printer): void {
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        echo "<div class='card mt-3' id='pg-manual-card'><div class='card-header'><h4 class='card-title mb-0'><i class='ti ti-pencil me-1'></i>"
            . $esc(__('Informations d\'inventaire manuel', 'printgestion')) . "</h4></div><div class='card-body row'>";
        self::renderFields($printer);
        echo "</div></div>";
    }

    /** Dernier relevé (date et heure), par qui, contenu, commentaire, état ; puis le bouton. */
    private static function renderFields(Printer $printer): void {
        $esc         = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $printers_id = (int) $printer->getID();
        $last        = self::getHistory($printers_id, 1)[0] ?? null;
        $field       = static function (string $label, string $html) use ($esc): void {
            echo "<div class='mb-3 col-12 col-sm-6 col-lg-3'><label class='form-label'>" . $esc($label) . "</label><div>" . $html . "</div></div>";
        };
        if ($last === null) {
            echo "<div class='col-12 mb-3 text-muted'>" . $esc(self::hasInventory($printer)
                ? __('Aucun relevé manuel : cette imprimante est relevée par une sonde. Un relevé saisi ici vaudrait jusqu\'au prochain passage de la sonde.', 'printgestion')
                : __('Aucun relevé manuel. Cette imprimante n\'est relevée par aucune sonde : ses niveaux et ses compteurs se saisissent ici, d\'après ce que le client envoie ou dit.', 'printgestion')) . "</div>";
        } else {
            $field(__('Dernier relevé', 'printgestion'), "<span class='fw-bold'>" . $esc(Html::convDateTime((string) $last['date_creation'])) . "</span>");
            $field(__('Par', 'printgestion'), $esc(getUserName((int) $last['users_id'])));
            $field(__('Contenu', 'printgestion'), $esc(self::describe($last)));
            if ((string) $last['comment'] !== '') {
                $field(__('Commentaire', 'printgestion'), $esc($last['comment']));
            }
            if (!empty($last['superseded'])) {
                $field(__('État', 'printgestion'), "<span class='badge bg-secondary text-secondary-fg'>" . $esc(sprintf(
                    __('Dépassé par l\'inventaire d\'une sonde le %s', 'printgestion'),
                    Html::convDateTime((string) $last['superseded_date'])
                )) . "</span>");
            }
        }
        echo "<div class='col-12'>";
        self::renderButton($printer, false);
        echo "</div>";
    }

    /**
     * Le bouton « Saisir un relevé manuel », la phrase de suivi, l'historique récent si demandé, et la fenêtre.
     * Chaque rendu porte son propre identifiant : la fiche et un onglet peuvent en afficher un chacun sans se
     * gêner. La fenêtre est déplacée à la racine du document quand on l'ouvre : rendue dans un onglet ou une
     * carte, elle pourrait sinon rester dans un bloc masqué, et rien ne s'afficherait. Pas de <form> : elle peut
     * se trouver dans le formulaire de la fiche, et un formulaire dans un formulaire n'existe pas.
     */
    public static function renderButton(Printer $printer, bool $with_history): void {
        $esc         = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $printers_id = (int) $printer->getID();
        $can_record  = self::canRecord($printer);
        $summary     = self::getSummary($printers_id);
        $uid         = 'pgm' . bin2hex(random_bytes(4));

        echo "<div class='d-flex flex-wrap align-items-center gap-2'>";
        if ($can_record) {
            echo "<button type='button' class='btn btn-primary' data-pg-manual-open='" . $uid . "'><i class='ti ti-pencil-plus me-1'></i>"
                . $esc(__('Saisir un relevé manuel', 'printgestion')) . "</button>";
        }
        echo "<span class='text-muted small'>" . $esc($summary !== '' ? $summary : __('Aucun relevé manuel : pour une imprimante sans sonde, les niveaux et les compteurs se saisissent ici.', 'printgestion')) . "</span>";
        echo "</div>";

        if ($with_history) {
            $history = self::getHistory($printers_id, 5);
            if (!empty($history)) {
                echo "<ul class='list-unstyled small text-muted mt-2 mb-0'>";
                foreach ($history as $row) {
                    echo "<li><i class='ti ti-pencil me-1'></i>" . $esc(Html::convDate((string) $row['reading_date'])) . " — " . $esc(self::describe($row))
                        . " <span class='text-muted'>(" . $esc(getUserName((int) $row['users_id'])) . ")</span>"
                        . ((string) $row['comment'] !== '' ? " <em>" . $esc($row['comment']) . "</em>" : '')
                        . (!empty($row['superseded']) ? " <span class='badge bg-secondary text-secondary-fg'>" . $esc(__('dépassé par la sonde', 'printgestion')) . "</span>" : '')
                        . "</li>";
                }
                echo "</ul>";
            }
        }

        if ($can_record) {
            self::renderModal($printer, $uid);
        }
    }

    /** Pastille de couleur et icône d'un emplacement : on reconnaît le toner cyan avant de lire son nom. */
    private static function slotIcon(array $slot): string {
        $swatches = ['black' => '#1f2937', 'cyan' => '#06b6d4', 'magenta' => '#db2777', 'yellow' => '#eab308'];
        $icons    = ['toner' => 'ti ti-droplet-filled', 'drum' => 'ti ti-cylinder'];
        $others   = ['fuserkit' => 'ti ti-flame', 'transferkit' => 'ti ti-arrows-horizontal', 'wastetoner' => 'ti ti-trash', 'maintenancekit' => 'ti ti-tool'];
        if (isset($swatches[$slot['color']])) {
            return "<i class='" . $icons[$slot['kind']] . " me-1' style='color:" . $swatches[$slot['color']] . "'></i>";
        }
        $icon = 'ti ti-circle';
        foreach ($others as $prefix => $candidate) {
            if (str_starts_with(mb_strtolower($slot['property']), $prefix)) {
                $icon = $candidate;
            }
        }
        return "<i class='" . $icon . " me-1 text-muted'></i>";
    }

    /** Bandeau d'une section de la fenêtre : fond coloré, majuscules — rien à voir avec l'étiquette d'un champ. */
    private static function sectionBand(string $icon, string $title, string $unit): string {
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        // Bleu clair de Tabler, pas la couleur du thème : sur un thème jaune, le bandeau était jaune.
        return "<div class='d-flex align-items-center rounded bg-azure-lt text-azure text-uppercase small fw-bold px-2 py-1 mt-2 mb-2'>"
            . "<i class='" . $esc($icon) . " me-2'></i>" . $esc($title)
            . ($unit !== '' ? "<span class='ms-auto fw-normal text-lowercase'>" . $esc($unit) . "</span>" : '') . "</div>";
    }

    private static function renderModal(Printer $printer, string $uid): void {
        $esc         = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $printers_id = (int) $printer->getID();
        $groups      = [
            'toner' => ['ti ti-droplet', __('Toners', 'printgestion'), []],
            'drum'  => ['ti ti-cylinder', __('Tambours', 'printgestion'), []],
            'other' => ['ti ti-settings', __('Autres consommables', 'printgestion'), []],
        ];
        foreach (self::getSlots($printer) as $property => $slot) {
            $groups[$slot['kind']][2][(string) $property] = $slot + ['property' => (string) $property];
        }

        echo "<div class='modal fade' id='" . $uid . "-modal' tabindex='-1'><div class='modal-dialog modal-lg'><div class='modal-content'>";
        echo "<div class='modal-header'><h5 class='modal-title'><i class='ti ti-pencil-plus me-2'></i>" . $esc(sprintf(__('Relevé manuel — %s', 'printgestion'), $printer->fields['name'])) . "</h5>"
            . "<button type='button' class='btn-close' data-bs-dismiss='modal'></button></div>";
        echo "<div class='modal-body'>";
        echo "<p class='text-muted small'>" . $esc(__('Ce que le client a envoyé ou dit : GLPI le garde comme s\'il venait d\'une sonde, daté de maintenant. Vide = inchangé.', 'printgestion')) . "</p>";
        echo "<div id='" . $uid . "-errors' class='alert alert-danger py-2' style='display:none'></div>";
        foreach ($groups as [$icon, $title, $slots]) {
            if (empty($slots)) {
                continue;
            }
            // Un bandeau par famille, d'une autre couleur que les étiquettes des champs : on ne les confond plus.
            echo self::sectionBand($icon, $title, __('en %', 'printgestion')) . "<div class='row g-2 mb-3'>";
            foreach ($slots as $property => $slot) {
                $id = $uid . '-level-' . preg_replace('/[^a-z0-9_]/', '', $property);
                echo "<div class='col-6 col-md-3'><label class='form-label small fw-normal mb-1' for='" . $esc($id) . "'>" . self::slotIcon($slot) . $esc($slot['label']) . "</label>"
                    . "<input type='number' min='0' max='100' class='form-control' id='" . $esc($id) . "' data-pg-manual-level='" . $esc($property) . "'"
                    . " placeholder='" . ($slot['current'] !== null ? $esc(sprintf(__('actuel : %d', 'printgestion'), $slot['current'])) : '—') . "'></div>";
            }
            echo "</div>";
        }
        // Les compteurs du journal natif, avec les noms de GLPI (ceux de l'onglet « Compteurs de pages ») et leur
        // dernière valeur connue en filigrane.
        $current = self::getCurrentCounters($printer);
        $icons   = [
            'total_pages' => 'ti ti-sum', 'bw_pages' => 'ti ti-contrast', 'color_pages' => 'ti ti-palette', 'rv_pages' => 'ti ti-arrows-left-right',
            'prints' => 'ti ti-printer', 'bw_prints' => 'ti ti-printer', 'color_prints' => 'ti ti-printer',
            'copies' => 'ti ti-copy', 'bw_copies' => 'ti ti-copy', 'color_copies' => 'ti ti-copy',
            'scanned' => 'ti ti-scan', 'faxed' => 'ti ti-device-landline-phone',
        ];
        echo self::sectionBand('ti ti-file-text', __('Compteurs de pages', 'printgestion'), __('en pages', 'printgestion')) . "<div class='row g-2 mb-3'>";
        foreach (self::COUNTERS as $field) {
            $id = $uid . '-counter-' . $field;
            echo "<div class='col-6 col-md-3'><label class='form-label small fw-normal mb-1' for='" . $esc($id) . "'><i class='" . $icons[$field] . " me-1 text-muted'></i>" . $esc(PrinterLog::getLabelFor($field)) . "</label>"
                . "<input type='number' min='0' class='form-control' id='" . $esc($id) . "' data-pg-manual-counter='" . $field . "' placeholder='"
                . $esc($current[$field] > 0 ? sprintf(__('actuel : %d', 'printgestion'), $current[$field]) : '—') . "'></div>";
        }
        echo "</div>";
        echo self::sectionBand('ti ti-message', __('Commentaire', 'printgestion'), '');
        echo "<div class='mb-2'><input type='text' class='form-control' id='" . $uid . "-comment' maxlength='1000' aria-label='" . $esc(__('Commentaire', 'printgestion')) . "' placeholder='" . $esc(__('mail de M. Dupont, appel du 12/09…', 'printgestion')) . "'></div>";
        echo "</div>";
        echo "<div class='modal-footer'><button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>" . $esc(_sx('button', 'Close')) . "</button>"
            . "<button type='button' class='btn btn-primary' id='" . $uid . "-save'><i class='ti ti-device-floppy me-1'></i>" . $esc(__('Enregistrer le relevé', 'printgestion')) . "</button></div>";
        echo "</div></div></div>";

        echo PluginPrintgestionUi::jsonData($uid . '-config', [
            'uid'         => $uid,
            'printers_id' => $printers_id,
            'url'         => PLUGIN_PRINTGESTION_WEBDIR . '/ajax/manual_reading.php',
            // Jeton CSRF envoyé en en-tête X-Glpi-Csrf-Token (requête AJAX POST).
            'csrf'        => Session::getNewCSRFToken(),
            'msgError'    => __('Relevé non enregistré.', 'printgestion'),
            'msgNet'      => __('Erreur réseau : relevé non enregistré.', 'printgestion'),
            'msgNoModal'  => __('Fenêtre indisponible : rechargez la page.', 'printgestion'),
        ]);
        // Identifiant hexadécimal, posé en clair dans le script : aucune donnée d'utilisateur n'y entre.
        echo "<script>(function () {\n"
            . "  var cfg = JSON.parse(document.getElementById('" . $uid . "-config').textContent);\n"
            . "  var uid = cfg.uid;\n"
            . "  function byId(suffix) { return document.getElementById(uid + suffix); }\n"
            . "  function show() {\n"
            . "    var modalEl = byId('-modal');\n"
            . "    if (!modalEl || !window.bootstrap) { alert(cfg.msgNoModal); return; }\n"
            . "    if (modalEl.parentNode !== document.body) { document.body.appendChild(modalEl); }\n"
            . "    byId('-errors').style.display = 'none';\n"
            . "    bootstrap.Modal.getOrCreateInstance(modalEl).show();\n"
            . "  }\n"
            . "  var buttons = document.querySelectorAll('[data-pg-manual-open=\"' + uid + '\"]');\n"
            . "  for (var i = 0; i < buttons.length; i++) { buttons[i].addEventListener('click', show); }\n"
            . "  byId('-save').addEventListener('click', function () {\n"
            . "    var save = byId('-save');\n"
            . "    var fd = new FormData();\n"
            . "    fd.append('printers_id', cfg.printers_id);\n"
            . "    fd.append('comment', byId('-comment').value);\n"
            . "    var counters = byId('-modal').querySelectorAll('[data-pg-manual-counter]');\n"
            . "    for (var c = 0; c < counters.length; c++) { fd.append('counters[' + counters[c].getAttribute('data-pg-manual-counter') + ']', counters[c].value); }\n"
            . "    var levels = byId('-modal').querySelectorAll('[data-pg-manual-level]');\n"
            . "    for (var k = 0; k < levels.length; k++) { fd.append('levels[' + levels[k].getAttribute('data-pg-manual-level') + ']', levels[k].value); }\n"
            . "    save.disabled = true;\n"
            . "    fetch(cfg.url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': cfg.csrf } })\n"
            . "      .then(function (r) { return r.json(); })\n"
            . "      .then(function (d) {\n"
            . "        if (d && d.ok) { window.location.reload(); return; }\n"
            . "        var box = byId('-errors');\n"
            . "        box.textContent = (d && d.errors && d.errors.length) ? d.errors.join(' ') : cfg.msgError;\n"
            . "        box.style.display = 'block';\n"
            . "        save.disabled = false;\n"
            . "      })\n"
            . "      .catch(function () { var box = byId('-errors'); box.textContent = cfg.msgNet; box.style.display = 'block'; save.disabled = false; });\n"
            . "  });\n"
            . "})();</script>";
    }
}
