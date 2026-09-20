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

    /** Réglage de la liaison, dans le contexte de configuration du plugin. Absent = activé : la liaison marche seule. */
    const LINK_SETTING = 'gestion_link_enabled';

    /**
     * Le plugin Gestion a-t-il été installé sur ce GLPI, à quelque état qu'il soit aujourd'hui ? Sa table des BL
     * suffit à le dire : elle est créée à l'installation et n'existe pas avant. Sert à décider si la carte « Liaison
     * avec le plugin Gestion » a un sens — sur un GLPI qui n'a jamais eu le plugin Gestion, elle ne s'affiche pas du
     * tout. Ne dit pas que la liaison fonctionne : plugin désactivé, la table reste là (c'est isGestionUsable()).
     */
    public static function isGestionPresent(): bool {
        global $DB;
        return $DB->tableExists('glpi_plugin_gestion_surveys');
    }

    /**
     * La liaison est-elle techniquement possible ? Plugin Gestion actif et sa table des BL présente. Déduit du réel,
     * jamais réglé : un GLPI sans le plugin Gestion, ou avec le plugin désactivé, n'a rien à lire.
     */
    public static function isGestionUsable(): bool {
        global $DB;
        return Plugin::isPluginActive('gestion') && $DB->tableExists('glpi_plugin_gestion_surveys');
    }

    /**
     * Interrupteur de la liaison (carte « Liaison avec le plugin Gestion »). Activé par défaut : la valeur n'existe
     * en base que si quelqu'un l'a changée, donc une installation qui n'a jamais vu cet écran est liée. Rangé dans le
     * contexte de configuration du plugin, pas dans une colonne : un réglage qui doit exister sur une base déjà
     * installée, sans migration de schéma (la 1.0.0 ne crée que les tables absentes, elle n'ajoute pas de colonne).
     */
    public static function isGestionLinkEnabled(): bool {
        $value = Config::getConfigurationValue(PluginPrintgestionSchema::CONFIG_CONTEXT, self::LINK_SETTING);
        return $value === null || $value === '' || (int) $value === 1;
    }

    /** Enregistre l'interrupteur de la liaison. */
    public static function setGestionLinkEnabled(bool $enabled): void {
        Config::setConfigurationValues(PluginPrintgestionSchema::CONFIG_CONTEXT, [self::LINK_SETTING => $enabled ? '1' : '0']);
    }

    /**
     * Lien avec le plugin Gestion (BL signé → expédition livrée, liaison des BL) : possible techniquement ET non
     * coupé par l'interrupteur. Sans effet sur le verrou anti-doublon : une expédition « livrée » reste un envoi en
     * cours, seule la pose détectée le clôt (Guard, statuts actifs).
     */
    public static function isGestionLinkActive(): bool {
        return self::isGestionLinkEnabled() && self::isGestionUsable();
    }

    /**
     * Appelée par le plugin Gestion juste après qu'un BL passe à signé, et par la liaison d'un BL à une expédition :
     * l'expédition passe « livrée » tout de suite, sans attendre le passage du cron. Même travail que le cron (statut
     * puis propagation aux demandes d'envoi), et strictement rien si la liaison est coupée ou le plugin Gestion
     * inutilisable. Le cron garde le même appel : il rattrape ce qu'aucun clic n'a déclenché (BL importé déjà signé,
     * `signed` basculé directement en base, appel direct en échec).
     *
     * @return int nombre d'expéditions passées en « livrée »
     */
    public static function onGestionBlSigned(): int {
        return PluginPrintgestionDelivery::markDeliveredBatch(self::findDeliveredFromGestion());
    }

    /**
     * Rapprochement des BL signés à l'ouverture d'un écran qui affiche ou consomme le statut « livrée ».
     *
     * **Aucune ligne de Print Gestion ne vit dans le plugin Gestion**, et c'est le point : une réinstallation ou une
     * mise à jour de Gestion n'emporte rien, il n'y a rien à remettre. Gestion n'appelle donc pas Print Gestion ; le
     * rapprochement se fait ici, au moment où la réponse compte — celui qui ouvre l'écran voit l'état à jour.
     *
     * Pourquoi c'est acceptable alors que le suivi GLS refuse de travailler au chargement d'une page : ce
     * rapprochement est du SQL local, deux jointures indexées, sans réseau, sans quota, sans API tierce. Ce que la
     * règle de GLS interdit, c'est d'appeler un transporteur parce que quelqu'un a ouvert une page — pas de lire sa
     * propre base. Sans BL signé à reprendre, il ne se passe rien et rien n'est écrit.
     *
     * Jamais d'exception vers la page : un écran ne doit pas tomber parce qu'un rapprochement a échoué. La tâche
     * automatique le reprendra.
     */
    public static function syncOnDisplay(): void {
        try {
            self::onGestionBlSigned();
        } catch (Throwable $e) {
            PluginPrintgestionLogger::error('livraison', 'Rapprochement des BL signés à l\'affichage : à reprendre au prochain passage de la tâche automatique.', $e);
        }
    }

    /**
     * Expéditions parties dont un BL du plugin Gestion est signé : la preuve de livraison la plus forte, écrite et
     * signée par le client. Rendues sous forme de constats, jamais écrites ici — c'est Delivery qui décide, garde la
     * hiérarchie des preuves et interdit les rétrogradations.
     *
     * La date retenue est celle de la signature (`doc_date` du BL), pas celle du passage : une expédition rattrapée
     * par la tâche automatique ne doit pas être datée du rattrapage.
     *
     * @return array[] constats prêts pour Delivery::markDeliveredBatch()
     */
    public static function findDeliveredFromGestion(): array {
        global $DB;

        if (!self::isGestionLinkActive()) {
            return [];
        }

        $delivered = [];
        $retenir   = static function (array $row) use (&$delivered): void {
            $id   = (int) $row['id'];
            $date = trim((string) ($row['doc_date'] ?? ''));
            // Plusieurs BL signés sur un même envoi : c'est le premier signé qui fait la livraison. Un BL signé sans
            // date connue ne chasse jamais une date connue — il ne fait que tenir la place si aucune n'est donnée.
            $connue = (string) ($delivered[$id]['date'] ?? '');
            $garder = !isset($delivered[$id])
                || ($connue === '' && $date !== '')
                || ($connue !== '' && $date !== '' && $date < $connue);
            if ($garder) {
                $delivered[$id] = [
                    'id'     => $id,
                    'source' => PluginPrintgestionDelivery::SOURCE_BL,
                    'date'   => $date,
                    'detail' => sprintf(__('BL #%d signé.', 'printgestion'), (int) $row['surveys_id']),
                ];
            }
        };

        // 1. Rétro-compat : expeditions avec bl_surveys_id (1 BL "principal")
        foreach ($DB->request([
            'SELECT'    => ['e.id', 's.doc_date', 's.id AS surveys_id'],
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
            $retenir($exp);
        }

        // 2. Nouveau : expeditions avec N BL via la table de liaison.
        //    Un seul BL signé suffit à passer l'expédition en delivered.
        if ($DB->tableExists('glpi_plugin_printgestion_expedition_bls')) {
            foreach ($DB->request([
                'SELECT'     => ['e.id', 's.doc_date', 's.id AS surveys_id'],
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
            ]) as $exp) {
                $retenir($exp);
            }
        }
        return array_values($delivered);
    }
}
