<?php
/**
 * PluginPrintgestionTonerchart — graphique des niveaux de cartouches d'une imprimante, sur le modèle du graphique natif
 * des compteurs de pages (PrinterLog::showMetrics()) : mêmes sélecteurs de période et de distribution, même widget
 * de tableau de bord (Glpi\Dashboard\Widget::multipleAreas()), même cadre (`div.dashboard.printer_barchart`).
 *
 * Bouton posé par le hook PRE_SHOW_TAB puis logé par le script dans l'en-tête du tableau natif « Cartouche -
 * Informations inventoriées » ; le graphique s'ouvre dans une fenêtre.
 *
 * Données : les relevés gardés par le plugin (PluginPrintgestionTonerreading, RETENTION_DAYS jours). La tâche
 * quotidienne n'enregistre un relevé que quand le niveau d'un toner CHANGE : un toner sans relevé un jour donné est
 * resté à son dernier niveau. Chaque toner est donc prolongé à son dernier niveau connu (levelsAt()) : à chaque date,
 * tous les toners ont une valeur. Une remontée d'au moins « Delta détection cartouche » points entre deux relevés est un
 * changement de cartouche (même seuil que la détection des poses), marqué sur la courbe et listé sous le graphique.
 * Le widget natif ne connaît que sa palette : les couleurs des toners (noir, cyan, magenta, jaune) sont appliquées par
 * le script une fois le graphique créé.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionTonerchart {

    /** Couleur de courbe par couleur de toner (correspondance SNMP, sinon nom de la propriété). */
    const COLORS = [
        'black'   => '#1f2328',
        'cyan'    => '#0ea5e9',
        'magenta' => '#d6336c',
        'yellow'  => '#f2b200',
    ];
    const OTHER_COLORS = ['#6f42c1', '#2fb344', '#f76707', '#4263eb', '#868e96', '#ae3ec9'];

    /** Périodes : celles du graphique natif des compteurs de pages, libellés de GLPI. */
    public static function getPeriods(): array {
        return [
            'P1M'    => sprintf(__('Last %s days'), 30),
            'P3M'    => __('Last quarter'),
            'P1Y'    => __('Last year'),
            'P1000Y' => __('All time'),
        ];
    }

    /** Distributions : celles du graphique natif des compteurs de pages, libellés de GLPI. */
    public static function getFormats(): array {
        return [
            'dynamic' => __('Dynamic distribution'),
            'daily'   => __('Daily'),
            'weekly'  => __('Weekly'),
            'monthly' => __('Monthly'),
        ];
    }

    /** Le graphique se montre à qui peut voir l'imprimante, module toner allumé. */
    public static function canView(Printer $printer): bool {
        return PluginPrintgestionConfig::isFeatureEnabled('toner')
            && (int) $printer->getID() > 0
            && $printer->can((int) $printer->getID(), READ);
    }

    /** Menu déroulant au balisage du graphique natif (components/printer_graph_buttons.html.twig). */
    private static function dropdown(string $name, string $icon, array $choices, string $current): string {
        $esc  = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $html = "<div class='dropdown' data-pg-select='" . $esc($name) . "'>"
            . "<button class='btn dropdown-toggle' type='button' data-bs-toggle='dropdown' aria-expanded='false'>"
            . "<i class='{$icon} me-2'></i><span data-pg-select-label='1'>" . $esc($choices[$current]) . "</span></button><ul class='dropdown-menu'>";
        foreach ($choices as $key => $label) {
            $html .= "<li><span class='dropdown-item" . ($key === $current ? ' active' : '') . "' role='button' data-key='" . $esc($key) . "'>"
                . $esc($label) . "</span></li>";
        }
        return $html . '</ul></div>';
    }

    /**
     * Bouton et fenêtre du graphique. Le bouton est déplacé par le script dans l'en-tête du tableau natif
     * « Cartouche - Informations inventoriées » (à droite) ; sans ce tableau, il reste en haut de l'onglet.
     */
    public static function renderButton(Printer $printer): void {
        global $CFG_GLPI;

        if (!self::canView($printer)) {
            return;
        }
        $esc   = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $modal = 'pg-tonerchart-' . mt_rand();

        echo "<div class='d-flex justify-content-end mb-2' id='{$modal}-slot'>"
            . "<button type='button' class='btn btn-sm btn-primary text-nowrap' id='{$modal}-open' data-bs-toggle='modal' data-bs-target='#{$modal}'>"
            . "<i class='ti ti-chart-line me-1'></i>" . $esc(__('Graphique des cartouches', 'printgestion')) . "</button></div>";

        echo "<div class='modal fade' id='{$modal}' tabindex='-1' aria-hidden='true'>"
            . "<div class='modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable' style='max-width:min(1360px, 94vw)'><div class='modal-content'>"
            . "<div class='modal-header'><h3 class='modal-title'><i class='ti ti-chart-line me-2'></i>"
            . $esc(sprintf(__('Niveaux des cartouches — %s', 'printgestion'), $printer->getFriendlyName())) . "</h3>"
            . "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='" . $esc(__('Fermer', 'printgestion')) . "'></button></div>"
            . "<div class='modal-body'>"
            . "<div class='d-flex gap-2 w-full'>"
            . self::dropdown('period', 'ti ti-calendar', self::getPeriods(), 'P1Y')
            . self::dropdown('format', 'ti ti-chart-dots-3', self::getFormats(), 'dynamic')
            . "</div>"
            . "<div class='dashboard printer_barchart pt-2' data-pg-chart='1'></div>"
            . "<div class='text-secondary small mt-2' data-pg-chart-status='1'></div>"
            . "<div class='mt-3' data-pg-chart-changes='1'></div>"
            . "</div></div></div></div>";

        echo "<script>window.pgTonerChart && window.pgTonerChart(" . json_encode([
            'modal'    => $modal,
            'url'      => PLUGIN_PRINTGESTION_WEBDIR . '/ajax/toner_chart.php',
            'printer'  => (int) $printer->getID(),
            'filename' => 'cartouches-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $printer->fields['name']),
            'period'   => 'P1Y',
            'format'   => 'dynamic',
            'header'   => Printer_CartridgeInfo::getTypeName(Session::getPluralNumber()),
            'echarts'  => rtrim((string) $CFG_GLPI['root_doc'], '/') . '/lib/echarts.js',
            'messages' => [
                'loading' => __('Chargement des relevés…', 'printgestion'),
                'empty'   => __('Aucun relevé de niveau pour cette imprimante sur cette période : le graphique se remplit avec les relevés SNMP (ou manuels).', 'printgestion'),
                'error'   => __('Graphique indisponible (relevés illisibles) : réessayez, ou consultez le journal du plugin.', 'printgestion'),
                'summary' => __('%1$d relevé(s) sur la période, du %2$s au %3$s. Un relevé est enregistré quand le niveau d\'un toner change : entre deux relevés, le toner est resté à son dernier niveau.', 'printgestion'),
                'changes' => __('Changements de cartouche détectés', 'printgestion'),
                'none'    => __('Aucun changement de cartouche détecté sur cette période.', 'printgestion'),
                'date'    => __('Date', 'printgestion'),
                'toner'   => __('Toner', 'printgestion'),
                'change'  => __('Niveau', 'printgestion'),
                'changed' => __('Changement de cartouche', 'printgestion'),
            ],
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ");</script>";
    }

    /** Début de la période (Y-m-d H:i:s), null pour tout l'historique. */
    public static function periodStart(string $period): ?string {
        return match ($period) {
            'P1M'    => date('Y-m-d 00:00:00', strtotime('-1 month')),
            'P3M'    => date('Y-m-d 00:00:00', strtotime('-3 months')),
            'P1000Y' => null,
            default  => date('Y-m-d 00:00:00', strtotime('-1 year')),
        };
    }

    /**
     * Le graphique d'une imprimante : widget natif (HTML et son script) et ce que le script y ajoute (couleurs des
     * toners, changements de cartouche), plus le tableau des changements.
     *
     * @return array ['html' => string, 'chart_id' => string, 'series' => [['name', 'cartridge', 'color',
     *                'marks' => [[index, niveau, date]]]], 'changes' => [['date', 'name', 'cartridge', 'color', 'from',
     *                'to']], 'count' => int, 'from' => ?string, 'to' => ?string]
     */
    public static function getData(Printer $printer, string $period, string $format): array {
        global $DB;

        $printers_id = (int) $printer->getID();
        $delta       = max(1, min(100, (int) (PluginPrintgestionConfig::getInstance()->fields['detection_delta'] ?? 20)));
        $empty       = ['html' => '', 'chart_id' => '', 'series' => [], 'changes' => [], 'count' => 0, 'from' => null, 'to' => null];

        // Tous les relevés de l'imprimante (dédoublonnés à l'écriture : peu de lignes), y compris avant la période :
        // le dernier relevé d'avant donne le niveau au début de la période.
        $readings = [];
        foreach ($DB->request([
            'SELECT' => ['property_name', 'level_percent', 'reading_date'],
            'FROM'   => PluginPrintgestionTonerreading::getTable(),
            'WHERE'  => ['printers_id' => $printers_id],
            'ORDER'  => ['reading_date', 'property_name'],
        ]) as $row) {
            $readings[(string) $row['property_name']][] = [(string) $row['reading_date'], max(0, min(100, (int) $row['level_percent']))];
        }
        $last_inventory = trim((string) ($printer->fields['last_inventory_update'] ?? ''));
        $end            = $last_inventory !== '' ? date('Y-m-d 00:00:00', min(time(), (int) strtotime($last_inventory))) : null;
        $timeline       = self::timeline($readings, self::periodStart($period), $end, $delta);
        if (empty($timeline['series'])) {
            return $empty;
        }

        // Dates du graphique : celles des relevés (distribution dynamique) ou une par jour, semaine, mois. array_values()
        // avant l'éclatement : des clés texte (propriétés) seraient prises pour des arguments nommés.
        $dates = $format === 'dynamic' || !isset(self::getFormats()[$format])
            ? array_values(array_unique(array_merge(...array_values(array_map(static fn(array $s) => array_column($s['points'], 0), $timeline['series'])))))
            : self::bucketDates((string) $timeline['from'], (string) $timeline['to'], $format);
        sort($dates);

        $fmt = new IntlDateFormatter(
            $_SESSION['glpilanguage'] ?? 'fr_FR',
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            null,
            null,
            $format === 'monthly' ? 'MMM y' : 'd MMM y'
        );
        $labels = array_map(static fn(string $date) => (string) $fmt->format(new DateTime($date)), $dates);
        $long   = new IntlDateFormatter($_SESSION['glpilanguage'] ?? 'fr_FR', IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE);

        $tags    = (new \Glpi\Inventory\Asset\Cartridge($printer))->knownTags();
        $series  = [];
        $changes = [];
        $other   = 0;
        foreach ($timeline['series'] as $property => $line) {
            $mapping = PluginPrintgestionSnmpmapping::resolveForPrinter($printers_id, $property);
            $color   = (string) ($mapping['toner_color'] ?? '');
            if (!isset(self::COLORS[$color]) && preg_match('/(black|cyan|magenta|yellow)/', $property, $m)) {
                $color = $m[1];
            }
            $cartridge_id = PluginPrintgestionSnmpmapping::resolveCartridgeItemForSnmp($printers_id, $property);
            $name         = (string) ($tags[$property]['name'] ?? $property);
            $cartridge    = $cartridge_id > 0 ? (string) Dropdown::getDropdownName(CartridgeItem::getTable(), $cartridge_id) : '';
            $hex          = self::COLORS[$color] ?? self::OTHER_COLORS[$other++ % count(self::OTHER_COLORS)];

            // Changement de cartouche : sur le point de la date (ou de la période) qui le contient.
            $marks = [];
            foreach ($line['changes'] as $change) {
                foreach ($dates as $index => $date) {
                    if ($date >= $change['date']) {
                        $marks[] = [$index, $change['to'], (string) $long->format(new DateTime($change['date']))];
                        break;
                    }
                }
                $changes[] = [
                    'date'      => (string) $long->format(new DateTime($change['date'])),
                    'sort'      => $change['date'],
                    'name'      => $name,
                    'cartridge' => $cartridge,
                    'color'     => $hex,
                    'from'      => $change['from'],
                    'to'        => $change['to'],
                ];
            }
            $series[] = [
                'key'       => $property,
                'name'      => $name,
                'cartridge' => $cartridge,
                'color'     => $hex,
                'values'    => self::levelsAt($readings[$property], $dates),
                'marks'     => $marks,
            ];
        }
        // Ordre habituel des toners : noir, cyan, magenta, jaune, puis les autres consommables par nom.
        $rank = array_flip(array_values(self::COLORS));
        usort($series, static fn(array $a, array $b) => [$rank[$a['color']] ?? 99, $a['name']] <=> [$rank[$b['color']] ?? 99, $b['name']]);
        usort($changes, static fn(array $a, array $b) => strcmp($b['sort'], $a['sort']));

        // Widget natif, tel que l'appelle le graphique des compteurs de pages (PrinterLog::showMetrics()).
        $rand = mt_rand();
        $html = \Glpi\Dashboard\Widget::multipleAreas([
            'data'        => [
                'labels' => $labels,
                'series' => array_map(static fn(array $s) => ['name' => $s['name'], 'data' => $s['values']], $series),
            ],
            'label'       => __('Niveaux des cartouches', 'printgestion'),
            'icon'        => CartridgeItem::getIcon(),
            'color'       => '#ffffff',
            'distributed' => false,
            'show_points' => true,
            'line_width'  => 2,
            'rand'        => $rand,
        ]);

        return [
            'html'     => $html,
            'chart_id' => Toolbox::slugify('chart_' . $rand),
            'series'   => array_map(static fn(array $s) => array_diff_key($s, ['values' => true, 'key' => true]), $series),
            'changes'  => $changes,
            'count'    => $timeline['count'],
            'from'     => (string) $long->format(new DateTime((string) $timeline['from'])),
            'to'       => (string) $long->format(new DateTime((string) $timeline['to'])),
        ];
    }

    /**
     * Niveau d'un toner à chaque date (dernier relevé connu à cette date, null avant son premier relevé). Sans base,
     * testée par la CI.
     *
     * @param array $points [[date 'Y-m-d H:i:s', niveau]] triés par date
     * @param array $dates  dates triées
     * @return array niveaux (int|null), dans l'ordre des dates
     */
    public static function levelsAt(array $points, array $dates): array {
        $values  = [];
        $i       = 0;
        $current = null;
        foreach ($dates as $date) {
            while ($i < count($points) && $points[$i][0] <= $date) {
                $current = $points[$i][1];
                $i++;
            }
            $values[] = $current;
        }
        return $values;
    }

    /**
     * Dates d'une distribution journalière, hebdomadaire ou mensuelle entre deux dates : la fin de chaque jour, de chaque
     * semaine (dimanche) ou de chaque mois, la dernière ramenée à la date de fin. Sans base, testée par la CI.
     *
     * @return string[] dates 'Y-m-d H:i:s'
     */
    public static function bucketDates(string $from, string $to, string $format): array {
        $start = new DateTimeImmutable(substr($from, 0, 10));
        $stop  = new DateTimeImmutable(substr($to, 0, 10) . ' 23:59:59');
        $dates = [];
        $day   = $start;
        for ($guard = 0; $guard < 5000 && $day <= $stop; $guard++) {
            $bucket_end = match ($format) {
                'weekly'  => $day->modify('sunday this week')->setTime(23, 59, 59),
                'monthly' => $day->modify('last day of this month')->setTime(23, 59, 59),
                default   => $day->setTime(23, 59, 59),
            };
            $dates[] = ($bucket_end > $stop ? $stop : $bucket_end)->format('Y-m-d H:i:s');
            $day     = $bucket_end->modify('+1 second');
        }
        return $dates;
    }

    /**
     * Ligne de temps commune, sans base (testée par la CI) : les relevés ne sont enregistrés que quand un niveau change,
     * chaque toner est donc prolongé à son dernier niveau connu sur toutes les dates de l'échelle.
     *
     * Échelle : dates des relevés de la période, plus le début de la période quand un relevé d'avant donne le niveau à
     * prolonger (jamais un axe ouvert sur du vide), et la date de fin (dernier inventaire de l'imprimante) quand elle est
     * plus récente que le dernier relevé.
     *
     * @param array   $readings propriété => [[date 'Y-m-d H:i:s', niveau]], chaque liste triée par date
     * @param ?string $start    début de la période (null : tout l'historique)
     * @param ?string $end      dernier inventaire (null : inconnu)
     * @param int     $delta    remontée minimale (points) qui vaut changement de cartouche
     * @return array ['series' => propriété => ['points' => [[date, niveau]], 'changes' => [['date', 'from', 'to']]],
     *                'count' => relevés réels de la période, 'from' => ?string, 'to' => ?string]
     */
    public static function timeline(array $readings, ?string $start, ?string $end, int $delta): array {
        $dates = [];
        $count = 0;
        foreach ($readings as $points) {
            foreach ($points as [$date]) {
                if ($start === null || $date >= $start) {
                    $dates[$date] = true;
                    $count++;
                }
            }
        }
        $last = empty($dates) ? null : max(array_keys($dates));
        if ($end !== null && ($last === null || $end > $last) && ($start === null || $end >= $start)) {
            $dates[$end] = true;
        }
        // Début de période seulement s'il y a un niveau d'avant à prolonger : sinon l'axe s'ouvrirait sur du vide.
        if ($start !== null) {
            foreach ($readings as $points) {
                if (!empty($points) && $points[0][0] < $start) {
                    $dates[$start] = true;
                    break;
                }
            }
        }
        ksort($dates);
        $dates = array_keys($dates);

        $series = [];
        foreach ($readings as $property => $points) {
            $values = [];
            foreach (self::levelsAt($points, $dates) as $i => $level) {
                if ($level !== null) {
                    $values[] = [$dates[$i], $level];
                }
            }
            if (empty($values)) {
                continue;
            }
            $changes = [];
            for ($k = 1, $n = count($points); $k < $n; $k++) {
                if ($points[$k][1] - $points[$k - 1][1] >= $delta && ($start === null || $points[$k][0] >= $start)) {
                    $changes[] = ['date' => $points[$k][0], 'from' => $points[$k - 1][1], 'to' => $points[$k][1]];
                }
            }
            $series[(string) $property] = ['points' => $values, 'changes' => $changes];
        }
        return ['series' => $series, 'count' => $count, 'from' => $dates[0] ?? null, 'to' => empty($dates) ? null : end($dates)];
    }
}
