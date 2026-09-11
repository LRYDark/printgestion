<?php
/**
 * PluginPrintgestionLogger — journal applicatif du plugin.
 *
 * Écrit dans files/_log/printgestion.log via Toolbox::logInFile(), en écriture
 * forcée (même si la journalisation fichier globale de GLPI est désactivée) :
 * une erreur interceptée doit toujours laisser une trace exploitable, jamais un
 * catch muet.
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

    private static function write(string $level, string $context, string $message, ?Throwable $e): void {
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
        Toolbox::logInFile(self::LOG_NAME, $line . "\n", true, false);
    }
}
