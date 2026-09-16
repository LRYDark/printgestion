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
     * Ligne d'une donnée commerciale (expédition, alerte) dans le périmètre de l'utilisateur : son entité,
     * figée à la création, ou une entité parente si la ligne est récursive. Jamais l'entité actuelle de
     * l'imprimante : après un transfert, l'historique reste au client d'origine.
     */
    public static function canAccessRow(array $row): bool {
        return isset($row['entities_id'])
            && Session::haveAccessToEntity((int) $row['entities_id'], (bool) ($row['is_recursive'] ?? false));
    }

    /**
     * Expédition dans le périmètre de l'utilisateur (entité de l'expédition, figée à sa création).
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

        return is_array($exp) && self::canAccessRow($exp) ? $exp : null;
    }

    /**
     * Entités où peut se trouver un BL (plugin Gestion) lié à cette expédition : l'entité de l'expédition, figée
     * à sa création, et ses entités parentes (BL d'un groupe couvrant ses sites), limitées au périmètre de
     * l'utilisateur. Jamais une entité sœur. Vide si l'expédition n'a pas d'entité.
     *
     * @param array $expedition ligne de glpi_plugin_printgestion_expeditions
     * @return int[]
     */
    public static function getBlEntities(array $expedition): array {
        if (!isset($expedition['entities_id'])) {
            return [];
        }
        $entities_id = (int) $expedition['entities_id'];
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
        $entities = self::getBlEntities($expedition);
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
     * Alerte dans le périmètre de l'utilisateur (entité de l'alerte, figée à sa création). Null si inexistante
     * OU hors périmètre.
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
        return is_array($alert) && self::canAccessRow($alert) ? $alert : null;
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
