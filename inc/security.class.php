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
}
