<?php
/**
 * Tests de la ligne de temps du graphique des cartouches (PluginPrintgestionTonerchart::timeline()), sans base ni GLPI.
 *
 * Cas de référence (imprimante iR-ADV C5550, 06/11/2026) : la tâche quotidienne n'enregistre un relevé que quand un
 * niveau change. Le 08/10, seuls le noir et le jaune ont bougé : le graphique doit quand même donner, ce jour-là, le
 * niveau de TOUS les toners, et prolonger chaque toner jusqu'au dernier inventaire.
 *
 * Usage : php test_tonerchart.php <chemin de inc/tonerchart.class.php> — code de sortie 1 au moindre échec.
 */
define('GLPI_ROOT', __DIR__);
require $argv[1] ?? __DIR__ . '/../../inc/tonerchart.class.php';

$failures = 0;
$check = static function (string $what, $got, $expected) use (&$failures): void {
    $ok = $got === $expected;
    $failures += $ok ? 0 : 1;
    echo ($ok ? 'OK   ' : 'ÉCHEC') . " $what" . ($ok ? '' : "\n      attendu : " . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n      obtenu  : " . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n";
};
$at = static function (array $points, string $date): ?int {
    foreach ($points as [$d, $level]) {
        if ($d === $date) {
            return $level;
        }
    }
    return null;
};

$readings = [
    'tonerblack'   => [['2026-10-06 00:00:00', 86], ['2026-10-08 00:00:00', 85]],
    'tonercyan'    => [['2026-10-06 00:00:00', 22], ['2026-10-20 00:00:00', 21]],
    'tonermagenta' => [['2026-10-06 00:00:00', 26], ['2026-10-13 00:00:00', 25]],
    'toneryellow'  => [['2026-10-06 00:00:00', 5], ['2026-10-08 00:00:00', 21]],
];
$t = PluginPrintgestionTonerchart::timeline($readings, null, '2026-11-05 00:00:00', 20);

echo "── Tous les toners à chaque date ──\n";
$check('le 08/10, les quatre toners ont un niveau', array_map(static fn($s) => $at($s['points'], '2026-10-08 00:00:00'), $t['series']),
    ['tonerblack' => 85, 'tonercyan' => 22, 'tonermagenta' => 26, 'toneryellow' => 21]);
$check('prolongés jusqu\'au dernier inventaire (05/11)', array_map(static fn($s) => end($s['points']), $t['series']), [
    'tonerblack' => ['2026-11-05 00:00:00', 85], 'tonercyan' => ['2026-11-05 00:00:00', 21],
    'tonermagenta' => ['2026-11-05 00:00:00', 25], 'toneryellow' => ['2026-11-05 00:00:00', 21],
]);
$check('même nombre de points pour chaque toner', array_values(array_unique(array_map(static fn($s) => count($s['points']), $t['series']))), [5]);
$check('relevés réels comptés (8), bornes', [$t['count'], $t['from'], $t['to']], [8, '2026-10-06 00:00:00', '2026-11-05 00:00:00']);
$check('+16 points (jaune 5 → 21) sous le seuil de 20 : pas un changement', $t['series']['toneryellow']['changes'], []);

echo "── Changement de cartouche ──\n";
$t = PluginPrintgestionTonerchart::timeline(['tonerblack' => [['2026-10-01 00:00:00', 30], ['2026-10-05 00:00:00', 4], ['2026-10-06 00:00:00', 100]]], null, null, 20);
$check('4 % → 100 % : changement daté', $t['series']['tonerblack']['changes'], [['date' => '2026-10-06 00:00:00', 'from' => 4, 'to' => 100]]);

echo "── Période commencée en cours de route ──\n";
$t = PluginPrintgestionTonerchart::timeline($readings, '2026-10-10 00:00:00', '2026-11-05 00:00:00', 20);
$check('niveau au début de la période = dernier relevé d\'avant', array_map(static fn($s) => $s['points'][0], $t['series']), [
    'tonerblack' => ['2026-10-10 00:00:00', 85], 'tonercyan' => ['2026-10-10 00:00:00', 22],
    'tonermagenta' => ['2026-10-10 00:00:00', 26], 'toneryellow' => ['2026-10-10 00:00:00', 21],
]);
$check('seuls les relevés de la période sont comptés', $t['count'], 2);

echo "── Période plus longue que l'historique ──\n";
$t = PluginPrintgestionTonerchart::timeline($readings, '2025-11-06 00:00:00', '2026-11-05 00:00:00', 20);
$check('l\'axe commence au premier relevé, pas au début de la période (pas de vide)', $t['from'], '2026-10-06 00:00:00');

echo "── Imprimante sans relevé ──\n";
$check('aucune série', PluginPrintgestionTonerchart::timeline([], null, '2026-11-05 00:00:00', 20)['series'], []);

echo "── Niveau à une date (levelsAt) ──\n";
$check('dernier relevé connu à chaque date, null avant le premier', PluginPrintgestionTonerchart::levelsAt(
    [['2026-10-06 00:00:00', 22], ['2026-10-20 00:00:00', 21]],
    ['2026-10-01 23:59:59', '2026-10-06 23:59:59', '2026-10-19 23:59:59', '2026-10-20 23:59:59', '2026-11-05 23:59:59']
), [null, 22, 22, 21, 21]);

echo "── Distributions (bucketDates) ──\n";
$check('journalière : fin de chaque jour', PluginPrintgestionTonerchart::bucketDates('2026-10-06 00:00:00', '2026-10-08 00:00:00', 'daily'),
    ['2026-10-06 23:59:59', '2026-10-07 23:59:59', '2026-10-08 23:59:59']);
$check('hebdomadaire : dimanche de chaque semaine, la dernière ramenée à la fin', PluginPrintgestionTonerchart::bucketDates('2026-10-06 00:00:00', '2026-10-21 00:00:00', 'weekly'),
    ['2026-10-11 23:59:59', '2026-10-18 23:59:59', '2026-10-21 23:59:59']);
$check('mensuelle : dernier jour de chaque mois', PluginPrintgestionTonerchart::bucketDates('2026-09-20 00:00:00', '2026-11-05 00:00:00', 'monthly'),
    ['2026-09-30 23:59:59', '2026-10-31 23:59:59', '2026-11-05 23:59:59']);

echo $failures === 0 ? "Tous les tests passent.\n" : "$failures échec(s).\n";
exit($failures === 0 ? 0 : 1);
