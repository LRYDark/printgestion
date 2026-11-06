<?php
/**
 * Tests de la règle du lieu de livraison (PluginPrintgestionDeliverylocation), sans base ni GLPI : arbre des lieux et
 * des entités simulé, fonctions d'arbre natives reproduites (getAncestorsOf() renvoie l'élément lui-même pour les
 * identifiants les plus bas, getSonsOf() l'inclut). Exemple de référence : 193 rue du Général Metman, Étage 1 / 3.
 *
 * Usage : php test_deliverylocation.php <chemin de inc/deliverylocation.class.php> — code de sortie 1 au moindre échec.
 */
define('GLPI_ROOT', __DIR__);

$TABLES = [
    'glpi_entities' => [
        0  => ['id' => 0, 'name' => 'Entité racine', 'level' => 1, 'entities_id' => -1, 'email' => 'support@prestataire.fr'],
        10 => ['id' => 10, 'name' => 'CLIENT1', 'level' => 2, 'entities_id' => 0, 'email' => 'contact@client.fr; compta@client.fr'],
        11 => ['id' => 11, 'name' => 'Site Nord', 'level' => 3, 'entities_id' => 10, 'email' => ''],
        20 => ['id' => 20, 'name' => 'AUTRECLIENT', 'level' => 2, 'entities_id' => 0, 'email' => ''],
    ],
    'glpi_locations' => [
        1   => ['id' => 1, 'level' => 1, 'name' => 'Metz', 'completename' => 'Metz', 'locations_id' => 0, 'entities_id' => 0, 'code' => 'PARTAGE', 'comment' => 'standard@prestataire.fr'],
        100 => ['id' => 100, 'level' => 2, 'name' => '193 rue du Général Metman', 'completename' => 'Metz > 193 rue du Général Metman', 'locations_id' => 1, 'entities_id' => 10, 'code' => 'METMAN',
                'comment' => "Accueil : Mme Claire Martin 03 87 11 22 33\naccueil@client.fr"],
        101 => ['id' => 101, 'level' => 3, 'name' => 'Étage 1', 'completename' => 'Metz > 193 rue du Général Metman > Étage 1', 'locations_id' => 100, 'entities_id' => 10, 'code' => '',
                'comment' => "M. Jean Dupont - 06 12 34 56 78 - jean.dupont@client.fr\nMme Paule Durand 06 98 76 54 32 paule.durand@client.fr"],
        103 => ['id' => 103, 'level' => 3, 'name' => 'Étage 3', 'completename' => 'Metz > 193 rue du Général Metman > Étage 3', 'locations_id' => 100, 'entities_id' => 10, 'code' => '', 'comment' => ''],
        104 => ['id' => 104, 'level' => 4, 'name' => 'Bureau 12', 'completename' => 'Metz > 193 rue du Général Metman > Étage 3 > Bureau 12', 'locations_id' => 103, 'entities_id' => 10, 'code' => 'METMAN-B12', 'comment' => ''],
        200 => ['id' => 200, 'level' => 1, 'name' => 'Autre site', 'completename' => 'Autre site', 'locations_id' => 0, 'entities_id' => 20, 'code' => 'AUTRE', 'comment' => 'x@autre.fr'],
        300 => ['id' => 300, 'level' => 1, 'name' => 'Sans rien', 'completename' => 'Sans rien', 'locations_id' => 0, 'entities_id' => 10, 'code' => '', 'comment' => ''],
    ],
];

class FakeResult implements IteratorAggregate {
    public function __construct(private array $rows) {}
    public function current() { return $this->rows[0] ?? null; }
    public function getIterator(): Iterator { return new ArrayIterator($this->rows); }
}
class FakeDB {
    public function request(array $q): FakeResult {
        global $TABLES;
        $rows = [];
        foreach ($TABLES[$q['FROM']] as $row) {
            $ids = (array) $q['WHERE']['id'];
            if (in_array($row['id'], array_map('intval', $ids), true)) {
                $rows[] = $row;
            }
        }
        return new FakeResult($rows);
    }
}
$DB = new FakeDB();

function parentKey(string $table): string { return $table === 'glpi_entities' ? 'entities_id' : 'locations_id'; }
// Comme GLPI : l'élément lui-même pour les identifiants les plus bas (0 entités, 1 lieux).
function getAncestorsOf(string $table, int $id): array {
    global $TABLES;
    if ($id <= ($table === 'glpi_entities' ? 0 : 1)) { return [$id => $id]; }
    $out = [];
    $k = parentKey($table);
    $parent = $TABLES[$table][$id][$k] ?? -1;
    while ($parent >= ($table === 'glpi_entities' ? 0 : 1)) {
        $out[$parent] = $parent;
        $parent = $TABLES[$table][$parent][$k] ?? -1;
    }
    return $out;
}
// Comme GLPI : l'élément lui-même compris.
function getSonsOf(string $table, int $id): array {
    global $TABLES;
    $out = [$id => $id];
    $k = parentKey($table);
    do {
        $added = false;
        foreach ($TABLES[$table] as $row) {
            if (isset($out[$row[$k]]) && !isset($out[$row['id']])) { $out[$row['id']] = $row['id']; $added = true; }
        }
    } while ($added);
    return $out;
}
class Location { public static function getTable(): string { return 'glpi_locations'; } }
class Entity { public static function getTable(): string { return 'glpi_entities'; } }

// Règle Sage simulée : nom en forme de code, sur l'entité ou l'ancêtre le plus proche.
class PluginPrintgestionSage {
    public static function getClientForEntity(int $id): ?array {
        global $TABLES;
        $chain = array_merge([$id], array_keys(getAncestorsOf('glpi_entities', $id)));
        foreach ($chain as $e) {
            if (preg_match('/^[A-Z0-9][A-Z0-9._-]{0,16}$/', $TABLES['glpi_entities'][$e]['name'])) {
                return ['code' => $TABLES['glpi_entities'][$e]['name'], 'entities_id' => $e, 'inherited' => $e !== $id];
            }
        }
        return null;
    }
}

require $argv[1] ?? __DIR__ . '/../../inc/deliverylocation.class.php';
$L = 'PluginPrintgestionDeliverylocation';

$failures = 0;
$check = static function (string $what, $got, $expected) use (&$failures): void {
    $ok = $got === $expected;
    $failures += $ok ? 0 : 1;
    echo ($ok ? 'OK   ' : 'ÉCHEC') . " $what" . ($ok ? '' : "\n      attendu : " . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n      obtenu  : " . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n";
};

echo "── Entités du client ──\n";
$check('imprimante dans CLIENT1', $L::clientEntities(10), [10]);
$check('imprimante dans Site Nord (sous CLIENT1, sans code)', $L::clientEntities(11), [11, 10]);

echo "── Imprimante A, choix « Étage 1 » ──\n";
$d = $L::describe(101, 10);
$check('intitulé Sage hérité du lieu parent', [$d['sage_label'], $d['sage_label_from']], ['METMAN', 'Metz > 193 rue du Général Metman']);
$check('mails de l\'étage 1 (deux contacts)', $d['emails'], ['jean.dupont@client.fr', 'paule.durand@client.fr']);
$check('complément = contacts sans les mails', $d['complement'], 'M. Jean Dupont - 06 12 34 56 78 / Mme Paule Durand 06 98 76 54 32');
$check('lieu partagé « Metz » sauté', $d['skipped'], ['Metz']);
$check('désignation : nom propre', $d['name'], 'Étage 1');

echo "── Imprimante B, choix « Étage 3 » (commentaire vide) ──\n";
$d = $L::describe(103, 10);
$check('mails remontés du lieu parent', [$d['emails'], $d['emails_from']], [['accueil@client.fr'], 'Metz > 193 rue du Général Metman']);
$check('complément remonté du lieu parent', $d['complement'], 'Accueil : Mme Claire Martin 03 87 11 22 33');
$check('jamais le mail du lieu partagé', in_array('standard@prestataire.fr', $d['emails'], true), false);

echo "── Sous-sous-lieu « Bureau 12 » avec son propre code ──\n";
$d = $L::describe(104, 10);
$check('son propre code l\'emporte', $d['sage_label'], 'METMAN-B12');
$check('mails remontés de deux niveaux', $d['emails'], ['accueil@client.fr']);

echo "── Imprimante en sous-entité Site Nord, lieu sur CLIENT1 ──\n";
$d = $L::describe(101, 11);
$check('lieu du client parent lu', $d['emails'], ['jean.dupont@client.fr', 'paule.durand@client.fr']);

echo "── Lieu d'un autre client ──\n";
$d = $L::describe(200, 10);
$check('rien lu sur un lieu hors du client', [$d['sage_label'], $d['emails'], $d['skipped']], ['', [], ['Autre site']]);

echo "── Lieu choisi à la commande ──\n";
$check('sous-lieu de l\'imprimante retenu', $L::resolveForPrinter(100, 101), 101);
$check('sous-sous-lieu retenu', $L::resolveForPrinter(100, 104), 104);
$check('lieu hors de celui de l\'imprimante refusé', $L::resolveForPrinter(100, 200), 100);
$check('lieu parent de l\'imprimante refusé', $L::resolveForPrinter(101, 100), 101);
$check('rien demandé = lieu de l\'imprimante', $L::resolveForPrinter(100, 0), 100);
$check('imprimante sans lieu', $L::resolveForPrinter(0, 101), 0);
$check('lieu inexistant demandé', $L::resolveForPrinter(100, 999), 100);
$check('sous-lieux de « 193 rue », tous niveaux', $L::subLocations(100), [101, 103, 104]);
$check('lieu sans sous-lieu', $L::subLocations(104), []);
$check('lieu n°1 (cas bas de GLPI) : pas son propre parent', $L::isWithin(100, 1) && !$L::isWithin(1, 100), true);

echo "── Messagerie de l'entité ──\n";
$check('entité sans mail → parent porteur du code', $L::entityEmails(11), ['emails' => ['contact@client.fr', 'compta@client.fr'], 'entities_id' => 10]);
$check('jamais le mail de l\'entité racine', $L::entityEmails(20), ['emails' => [], 'entities_id' => 0]);

echo "── Lieu sans code ni commentaire ──\n";
$d = $L::describe(300, 10);
$check('rien : retombe sur l\'entité', [$d['sage_label'], $d['emails'], $d['complement']], ['', [], '']);

echo "── Mails dans un commentaire de contacts ──
";
$check('deux contacts, noms et téléphones ignorés', $L::extractEmails("M. Jean Dupont - 06 12 34 56 78 - jean.dupont@client.fr
Mme Claire Martin 03 87 11 22 33 claire.martin@client.fr"), ['jean.dupont@client.fr', 'claire.martin@client.fr']);
$check('chevrons, point-virgule, mailto, doublon en casse différente', $L::extractEmails("Jean <jean@x.fr> ; mailto:Paul@X.fr, paul@x.fr."), ['jean@x.fr', 'Paul@X.fr']);
$check('faux positifs ignorés', $L::extractEmails("un @ seul, toto@ et @titi.fr"), []);
$check('complément : contacts sans les mails', $L::stripEmails("M. Jean Dupont - 06 12 34 56 78 - jean.dupont@client.fr
Mail : j@x.fr Tél : 06 00 00 00 00"), 'M. Jean Dupont - 06 12 34 56 78 / Tél : 06 00 00 00 00');
$check('ligne sans mail laissée telle quelle', $L::stripEmails("Livrer à l'adresse indiquée :"), "Livrer à l'adresse indiquée :");

echo $failures === 0 ? "Tous les tests passent.
" : "$failures échec(s).
";
exit($failures === 0 ? 0 : 1);
