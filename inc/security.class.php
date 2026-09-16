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
     * Entités où peut se trouver un BL (plugin Gestion) lié à une expédition de cette imprimante : l'entité de
     * l'imprimante et ses entités parentes (BL d'un groupe couvrant ses sites), limitées au périmètre de
     * l'utilisateur. Vide si l'imprimante n'existe pas.
     *
     * @return int[]
     */
    public static function getBlEntities(int $printers_id): array {
        $printer = new Printer();
        if ($printers_id <= 0 || !$printer->getFromDB($printers_id)) {
            return [];
        }
        $entities_id = (int) $printer->fields['entities_id'];
        $entities    = array_merge([$entities_id], array_map('intval', array_values(getAncestorsOf(Entity::getTable(), $entities_id))));
        return array_values(array_filter($entities, static fn(int $id): bool => Session::haveAccessToEntity($id)));
    }

    /**
     * BL du plugin Gestion utilisable pour une expédition : il existe et son entité est dans getBlEntities().
     * Null si le BL n'existe pas OU s'il est d'un autre client : les deux cas sont indiscernables pour l'appelant,
     * pour ne pas révéler l'existence d'un document d'un autre client.
     */
    public static function getBlForExpedition(int $bl_surveys_id, array $expedition): ?array {
        global $DB;

        if ($bl_surveys_id <= 0 || !$DB->tableExists('glpi_plugin_gestion_surveys')) {
            return null;
        }
        $entities = self::getBlEntities((int) ($expedition['printers_id'] ?? 0));
        if (empty($entities)) {
            return null;
        }
        $bl = $DB->request([
            'FROM'  => 'glpi_plugin_gestion_surveys',
            'WHERE' => ['id' => $bl_surveys_id, 'entities_id' => $entities],
            'LIMIT' => 1,
        ])->current();
        return is_array($bl) ? $bl : null;
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
