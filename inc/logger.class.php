<?php
/**
 * PluginPrintgestionLogger — journal applicatif du plugin.
 *
 * Écrit dans files/_log/printgestion.log via Toolbox::logInFile(), en écriture
 * forcée (même si la journalisation fichier globale de GLPI est désactivée) :
 * une erreur interceptée doit toujours laisser une trace exploitable, jamais un
 * catch muet.
 *
 * Toolbox::logInFile() absorbe un échec d'écriture (dossier non inscriptible, disque plein) et renvoie
 * seulement false : l'échec est donc vérifié ici, et la ligne part alors dans le journal d'erreurs natif de
 * PHP (journal du serveur web, ou sortie d'erreur en ligne de commande). Carte « Journal du plugin » de la
 * configuration : état du fichier et écriture de test relue.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionLogger {

    const LOG_NAME = 'printgestion';

    /** Erreur : l'opération n'a pas abouti. */
    public static function error(string $context, string $message, ?Throwable $e = null): void {
        self::write('ERREUR', $context, $message, $e);
    }

    /** Avertissement : opération dégradée mais poursuivie. */
    public static function warning(string $context, string $message, ?Throwable $e = null): void {
        self::write('AVERTISSEMENT', $context, $message, $e);
    }

    /**
     * Information : l'opération a abouti, mais elle mérite une trace. Réservé à ce qui se demande après coup — qui
     * a récupéré l'installeur, quand, depuis quelle adresse. Pas un journal de fonctionnement : le reste du plugin
     * n'écrit que ses échecs.
     */
    public static function info(string $context, string $message): void {
        self::write('INFO', $context, $message, null);
    }

    /** Chemin du fichier journal. */
    public static function getPath(): string {
        return GLPI_LOG_DIR . '/' . self::LOG_NAME . '.log';
    }

    /**
     * État du journal pour la configuration.
     *
     * @return array ['path', 'dir_writable', 'exists', 'writable', 'size', 'modified' (Y-m-d H:i:s ou null)]
     */
    public static function getStatus(): array {
        $path   = self::getPath();
        $exists = is_file($path);
        return [
            'path'         => $path,
            'dir_writable' => is_dir(GLPI_LOG_DIR) && is_writable(GLPI_LOG_DIR),
            'exists'       => $exists,
            'writable'     => $exists ? is_writable($path) : (is_dir(GLPI_LOG_DIR) && is_writable(GLPI_LOG_DIR)),
            'size'         => $exists ? (int) filesize($path) : 0,
            'modified'     => $exists ? date('Y-m-d H:i:s', (int) filemtime($path)) : null,
        ];
    }

    /**
     * Écrit une entrée de test et la relit dans le fichier : preuve que le journal fonctionne réellement sur
     * ce serveur (droits du dossier, disque), pas seulement qu'aucune erreur n'a eu lieu.
     */
    public static function writeTestEntry(string $author): bool {
        $marker = 'test-' . bin2hex(random_bytes(6));
        if (!self::write('INFO', 'journal', sprintf('Entrée de test écrite par %s (%s).', $author, $marker), null)) {
            return false;
        }
        clearstatcache(true, self::getPath());
        $size = is_file(self::getPath()) ? (int) filesize(self::getPath()) : 0;
        if ($size === 0) {
            return false;
        }
        $handle = fopen(self::getPath(), 'rb');
        if ($handle === false) {
            return false;
        }
        fseek($handle, max(0, $size - 4096));
        $tail = (string) fread($handle, 4096);
        fclose($handle);
        return str_contains($tail, $marker);
    }

    private static function write(string $level, string $context, string $message, ?Throwable $e): bool {
        $line = sprintf('[%s] %s : %s', $level, $context, $message);
        if ($e !== null) {
            $line .= sprintf(
                ' — %s : %s (%s:%d)',
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );
        }
        if (Toolbox::logInFile(self::LOG_NAME, $line . "\n", true, false)) {
            return true;
        }
        // Fichier du plugin non inscriptible : la trace ne doit pas disparaître en silence.
        \error_log('[printgestion] journal ' . self::getPath() . ' non inscriptible — ' . $line);
        return false;
    }
}
