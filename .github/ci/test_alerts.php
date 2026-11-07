<?php
/**
 * Tests du classement des alertes toner et du « niveau figé suspect » (PluginPrintgestionAlert), sans base ni GLPI.
 *
 *   - classement : Critique sous X % OU sous X jours restants, À surveiller sous X jours ;
 *   - niveau figé : suspect seulement si l'imprimante a imprimé l'équivalent d'au moins FROZEN_PERCENT_STEPS points de
 *     SA cartouche (rendement mesuré) sans que le niveau bouge — 1000 pages sans bouger : suspect ; 3 pages : non ;
 *     compteur de départ inconnu : non.
 *
 * Usage : php test_alerts.php <dossier inc/ du plugin> — code de sortie 1 au moindre échec.
 */
define('GLPI_ROOT', __DIR__);
if (!class_exists('CommonDBTM')) {
    eval('class CommonDBTM {}');
}
$inc = rtrim($argv[1] ?? __DIR__ . '/../../inc', '/\\');
require $inc . '/tonerreading.class.php';
require $inc . '/alert.class.php';

$failures = 0;
$check = static function (string $what, $got, $expected) use (&$failures): void {
    $ok = $got === $expected;
    $failures += $ok ? 0 : 1;
    echo ($ok ? 'OK   ' : 'ÉCHEC') . " $what" . ($ok ? '' : "\n      attendu : " . json_encode($expected) . "\n      obtenu  : " . json_encode($got)) . "\n";
};
$A = 'PluginPrintgestionAlert';

echo "── Classement : % ou jours ──\n";
$check('12 % sous le seuil de 15 % : Critique', $A::classify(['level' => 12, 'days_remaining' => 200], 15, 30, 7), $A::STATUS_CRITICAL);
$check('50 %, 5 jours restants sous 7 : Critique', $A::classify(['level' => 50, 'days_remaining' => 5], 15, 30, 7), $A::STATUS_CRITICAL);
$check('50 %, 20 jours restants sous 30 : À surveiller', $A::classify(['level' => 50, 'days_remaining' => 20], 15, 30, 7), $A::STATUS_WATCH);
$check('critique en jours réglable : 5 jours avec seuil 3 → À surveiller', $A::classify(['level' => 50, 'days_remaining' => 5], 15, 30, 3), $A::STATUS_WATCH);
$check('client qui imprime peu : 3 %, 6 mois restants, seuil 2 % → Bon', $A::classify(['level' => 3, 'days_remaining' => 180], 2, 30, 7), $A::STATUS_OK);
$check('gros volume : 10 %, 3 jours restants → Critique', $A::classify(['level' => 10, 'days_remaining' => 3], 5, 30, 7), $A::STATUS_CRITICAL);

echo "── Niveau figé suspect ──\n";
// Rendement mesuré : 100 % → 50 % pour 5000 pages = 100 pages par point ; seuil = 3 points = 300 pages.
$reading = static fn(string $date, int $level, int $pages, int $suspect = 0) => [
    'reading_date' => $date, 'level_percent' => $level, 'total_pages' => $pages, 'bw_pages' => $pages, 'color_pages' => 0, 'is_suspect' => $suspect,
];
$base = [$reading('2026-09-01 00:00:00', 100, 1000), $reading('2026-10-01 00:00:00', 50, 6000)];
$frozen = static fn(array $readings) => $A::computeRemainingPagesBased(50, $readings, 'black', 5000)['level_suspect'];
$check('1000 pages imprimées, niveau inchangé : suspect', $frozen(array_merge($base, [$reading('2026-10-20 00:00:00', 50, 7000, 1)])), true);
$check('3 pages imprimées en un mois, niveau inchangé : pas suspect', $frozen(array_merge($base, [$reading('2026-10-31 00:00:00', 50, 6003)])), false);
$check('250 pages (sous les 3 points de cette cartouche) : pas suspect', $frozen(array_merge($base, [$reading('2026-10-20 00:00:00', 50, 6250, 1)])), false);
$check('compteur de départ inconnu (0) : jamais suspect', $frozen([$reading('2026-09-01 00:00:00', 50, 0), $reading('2026-10-01 00:00:00', 50, 9000, 1)]), false);
$check('le niveau a bougé au dernier relevé : pas suspect', $frozen(array_merge($base, [$reading('2026-10-20 00:00:00', 45, 6500)])), false);

echo $failures === 0 ? "Tous les tests passent.\n" : "$failures échec(s).\n";
exit($failures === 0 ? 0 : 1);
