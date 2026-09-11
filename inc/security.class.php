<?php
/**
 * PluginPrintgestionSecurity — cloisonnement des données par entité (client).
 *
 * GLPI est exposé sur Internet et des clients y disposent d'un compte : aucune
 * donnée d'un client ne doit être lisible ni modifiable depuis le périmètre
 * d'entités d'un autre utilisateur.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionSecurity {

    /**
     * Empreinte du périmètre d'entités de la session courante.
     *
     * À inclure dans toute clé de cache contenant des données restreintes par
     * entité : deux utilisateurs aux périmètres différents ne doivent jamais
     * partager la même entrée de cache.
     */
    public static function sessionEntityScopeKey(): string {
        $normalize = static function ($ids): array {
            $ids = is_array($ids) ? $ids : [$ids];
            $ids = array_map('intval', array_values($ids));
            sort($ids);
            return $ids;
        };

        return md5(json_encode([
            'active'   => $normalize($_SESSION['glpiactiveentities'] ?? []),
            'parents'  => $normalize($_SESSION['glpiparententities'] ?? []),
            'show_all' => !empty($_SESSION['glpishowallentities']),
        ]));
    }

    /**
     * Imprimante existante et située dans une entité accessible à l'utilisateur
     * (récursivité comprise, via le contrôle d'entité natif de GLPI).
     */
    public static function canAccessPrinter(int $printers_id): bool {
        if ($printers_id <= 0) {
            return false;
        }
        $printer = new Printer();
        return $printer->getFromDB($printers_id) && $printer->canViewItem();
    }

    /**
     * Expédition dont l'imprimante est accessible à l'utilisateur.
     * Retourne null si elle n'existe pas OU si elle est hors périmètre : les deux
     * cas sont volontairement indiscernables pour l'appelant.
     */
    public static function getAccessibleExpedition(int $expedition_id): ?array {
        global $DB;

        if ($expedition_id <= 0) {
            return null;
        }
        $exp = $DB->request([
            'FROM'  => 'glpi_plugin_printgestion_expeditions',
            'WHERE' => ['id' => $expedition_id],
            'LIMIT' => 1,
        ])->current();

        if (!is_array($exp) || !self::canAccessPrinter((int) $exp['printers_id'])) {
            return null;
        }
        return $exp;
    }

    /**
     * Alerte dont l'imprimante concernée est accessible à l'utilisateur.
     * Pour une alerte « mauvaise imprimante », printers_id est l'imprimante sur
     * laquelle la cartouche a été détectée. Null si inexistante OU hors périmètre.
     */
    public static function getAccessibleAlert(int $alert_id): ?array {
        global $DB;

        if ($alert_id <= 0) {
            return null;
        }
        $alert = $DB->request([
            'FROM'  => 'glpi_plugin_printgestion_alerts',
            'WHERE' => ['id' => $alert_id],
            'LIMIT' => 1,
        ])->current();
        if (!is_array($alert)) {
            return null;
        }

        $printers_id = (int) ($alert['printers_id'] ?: ($alert['detected_printers_id'] ?? 0));
        return self::canAccessPrinter($printers_id) ? $alert : null;
    }

    /**
     * Refus d'accès pour un endpoint AJAX JSON : répond 403 et termine la requête.
     */
    public static function denyJson(string $error = 'Forbidden'): never {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }
}
