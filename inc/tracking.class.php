<?php
/**
 * PluginPrintgestionTracking — Phase 3 : intégrations externes.
 *
 * 1. Plugin Gestion : BL signé dans glpi_plugin_gestion_surveys
 *    → passe l'expédition Print Gestion en "delivered".
 *
 * 2. Suivi des colis GLS : à venir (client GLS, bloc 4). Règle fixée : un suivi transporteur est de l'information
 *    affichée, il ne change jamais le statut d'une expédition ni ne pèse sur une décision — les anciennes
 *    fonctions UPS / GLS / Chronopost, vides et branchées sur le statut, sont supprimées.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionTracking {

    // ─────────────────────────────────────────────────────────────
    //  INTÉGRATION PLUGIN GESTION (BL signé → delivered)
    // ─────────────────────────────────────────────────────────────

    /**
     * Pour chaque expédition liée à un BL du plugin Gestion, vérifie si le BL a
     * été signé. Si oui, passe l'expédition en "delivered" et notifie le commercial.
     */
    /**
     * Lien avec le plugin Gestion (BL signé → expédition livrée, liaison des BL) : déduit, jamais réglé. Actif dès
     * que le plugin Gestion est actif et que sa table des BL existe. Sans effet sur le verrou anti-doublon : une
     * expédition « livrée » reste un envoi en cours, seule la pose détectée le clôt (Guard, statuts actifs).
     */
    public static function isGestionLinkActive(): bool {
        global $DB;
        return Plugin::isPluginActive('gestion') && $DB->tableExists('glpi_plugin_gestion_surveys');
    }

    public static function syncDeliveredFromGestion(): int {
        global $DB;

        if (!self::isGestionLinkActive()) {
            return 0;
        }

        $delivered_ids = [];

        // 1. Rétro-compat : expeditions avec bl_surveys_id (1 BL "principal")
        foreach ($DB->request([
            'SELECT'    => ['e.id'],
            'FROM'      => 'glpi_plugin_printgestion_expeditions AS e',
            'INNER JOIN'=> [
                'glpi_plugin_gestion_surveys AS s' => [
                    'ON' => ['e' => 'bl_surveys_id', 's' => 'id'],
                ],
            ],
            'WHERE' => [
                'e.statut' => ['shipped', 'transit'],
                's.signed' => 1,
            ],
        ]) as $exp) {
            $delivered_ids[(int)$exp['id']] = true;
        }

        // 2. Nouveau : expeditions avec N BL via la table de liaison.
        //    Un seul BL signé suffit à passer l'expédition en delivered.
        if ($DB->tableExists('glpi_plugin_printgestion_expedition_bls')) {
            foreach ($DB->request([
                'SELECT'     => ['e.id'],
                'FROM'       => 'glpi_plugin_printgestion_expeditions AS e',
                'INNER JOIN' => [
                    'glpi_plugin_printgestion_expedition_bls AS eb' => [
                        'ON' => ['e' => 'id', 'eb' => 'expeditions_id'],
                    ],
                    'glpi_plugin_gestion_surveys AS s' => [
                        'ON' => ['eb' => 'bl_surveys_id', 's' => 'id'],
                    ],
                ],
                'WHERE' => [
                    'e.statut' => ['shipped', 'transit'],
                    's.signed' => 1,
                ],
                'GROUPBY' => ['e.id'],
            ]) as $exp) {
                $delivered_ids[(int)$exp['id']] = true;
            }
        }

        if (empty($delivered_ids)) {
            return 0;
        }

        foreach (array_keys($delivered_ids) as $eid) {
            $DB->update('glpi_plugin_printgestion_expeditions', [
                'statut'         => PluginPrintgestionExpedition::STATUS_DELIVERED,
                'date_delivered' => date('Y-m-d H:i:s'),
            ], ['id' => $eid]);
        }

        return count($delivered_ids);
    }
}
