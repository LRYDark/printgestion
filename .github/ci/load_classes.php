<?php
/**
 * Charge chaque classe du plugin contre le code de GLPI, sans base de données.
 *
 * `php -l` ne voit que la syntaxe. Une méthode dont la signature ne correspond
 * plus à celle de GLPI, ou une propriété typée par GLPI 12 (`$rightname`,
 * `$dohistory`…) redéclarée sans type, ne casse qu'au chargement de la classe :
 * erreur fatale, page blanche. Chaque classe est chargée dans son propre
 * processus, pour toutes les voir d'un coup.
 *
 * Usage : php load_classes.php <dossier GLPI> <dossier du plugin dans GLPI>
 */

[$glpi, $plugin] = [rtrim($argv[1], '/'), rtrim($argv[2], '/')];
$key = basename($plugin);

if (!isset($argv[3])) {
    $files = array_merge(glob("$plugin/inc/*.class.php") ?: [], glob("$plugin/src/*.php") ?: []);
    $failed = 0;
    foreach ($files as $file) {
        // Un fichier sans déclaration de premier niveau est un script, pas une classe : il s'exécute au chargement
        // et peut demander la base (rt/inc/chrono.class.php). Rien à charger hors de GLPI.
        if (!preg_match('/^(?:final |abstract |readonly )*(?:class|interface|trait|enum) \w+/m', file_get_contents($file))) {
            echo "--   " . basename($file) . " : script sans classe de premier niveau, non chargé\n";
            continue;
        }
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' '
            . escapeshellarg($glpi) . ' ' . escapeshellarg($plugin) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
        $out = trim(implode("\n", $out));
        if ($rc !== 0) {
            $failed++;
            echo "FAIL " . basename($file) . "\n$out\n";
        } else {
            echo "OK   " . basename($file) . ($out !== '' ? "\n     $out" : '') . "\n";
        }
    }
    echo count($files) . " fichier(s) de classe, $failed en erreur.\n";
    exit($failed === 0 ? 0 : 1);
}

// --- Processus enfant : une seule classe ---
$file = $argv[3];
chdir($glpi);
// Les dépréciations s'affichent sans faire échouer : elles ne cassent rien aujourd'hui.
set_error_handler(static function (int $no, string $msg, string $f, int $l): bool {
    if (in_array($no, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
        echo "dépréciation : $msg ($f:$l)\n";
        return true;
    }
    fwrite(STDERR, "erreur PHP : $msg ($f:$l)\n");
    exit(1);
});
require "$glpi/vendor/autoload.php";

$prefix = 'Plugin' . ucfirst($key);
spl_autoload_register(static function (string $class) use ($plugin, $prefix): void {
    if (stripos($class, $prefix) !== 0) {
        return;
    }
    $legacy = "$plugin/inc/" . strtolower(substr($class, strlen($prefix))) . '.class.php';
    $psr4   = "$plugin/src/$class.php";
    if (is_file($legacy)) {
        require_once $legacy;
    } elseif (is_file($psr4)) {
        require_once $psr4;
    }
}, true, true);

// Constantes du plugin (PLUGIN_XXX_DIR…). setup.php peut appeler GLPI au
// chargement : sans base, un échec ici n'est pas celui de la classe testée.
try {
    restore_error_handler();
    @include_once "$plugin/setup.php";
} catch (Throwable $e) {
    // ignoré
}
set_error_handler(static function (int $no, string $msg, string $f, int $l): bool {
    if (in_array($no, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
        echo "dépréciation : $msg ($f:$l)\n";
        return true;
    }
    fwrite(STDERR, "erreur PHP : $msg ($f:$l)\n");
    exit(1);
});

if (str_ends_with($file, '.class.php')) {
    $class = $prefix . ucfirst(basename($file, '.class.php'));
} else {
    // src/ : classe éventuellement dans un espace de noms (GlpiPlugin\Xxx\…), chargée par l'autoload du plugin.
    $code  = file_get_contents($file);
    $ns    = preg_match('/^namespace\s+([^;\s]+)\s*;/m', $code, $m) ? $m[1] . '\\' : '';
    $class = $ns . basename($file, '.php');
    if ($ns !== '') {
        require_once $file;
    }
}
try {
    if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
        // Pas une erreur pour GLPI, qui n'appelle alors simplement pas la classe (rt/inc/chrono.class.php ne la
        // déclare que sur la page d'un ticket) : signalé, sans faire échouer.
        echo "avertissement : la classe $class n'est pas déclarée au chargement de " . basename($file) . "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ' : ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ")\n");
    exit(1);
}
exit(0);
