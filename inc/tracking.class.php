<?php
/**
 * PluginPrintgestionTracking — Phase 3 : intégrations externes.
 *
 * 1. Plugin Gestion : BL signé dans glpi_plugin_gestion_surveys
 *    → passe l'expédition Print Gestion en "delivered".
 *
 * 2. APIs transporteurs (UPS / GLS / Chronopost) : récupère le statut d'un
 *    numéro de tracking et met à jour l'expédition (transit / delivered).
 *
 * Les appels API sont volontairement défensifs : si une clé manque ou si
 * l'API ne répond pas, on ignore silencieusement (le cron passera à la suite).
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionTracking extends CommonDBTM {

    static $rightname = 'plugin_printgestion_expedition';

    static function getTypeName($nb = 0) {
        return __('Suivi expéditions', 'printgestion');
    }

    // ─────────────────────────────────────────────────────────────
    //  INTÉGRATION PLUGIN GESTION (BL signé → delivered)
    // ─────────────────────────────────────────────────────────────

    /**
     * Pour chaque expédition liée à un BL du plugin Gestion, vérifie si le BL a
     * été signé. Si oui, passe l'expédition en "delivered" et notifie le commercial.
     */
    public static function syncDeliveredFromGestion(): int {
        global $DB;

        $config = PluginPrintgestionConfig::getInstance();
        if ((int)($config->fields['plugin_gestion_enabled'] ?? 0) !== 1) {
            return 0;
        }

        if (!$DB->tableExists('glpi_plugin_gestion_surveys')) {
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

    // ─────────────────────────────────────────────────────────────
    //  APIS TRANSPORTEURS
    // ─────────────────────────────────────────────────────────────

    /**
     * Parcourt les expéditions "shipped"/"transit" ayant un numéro de suivi
     * et interroge l'API du transporteur.
     */
    public static function refreshFromCarriers(): int {
        global $DB;

        $rows = $DB->request([
            'FROM'  => 'glpi_plugin_printgestion_expeditions',
            'WHERE' => [
                'statut'            => ['shipped', 'transit'],
                'transport_number'  => ['!=', ''],
                'transport_carrier' => ['!=', ''],
            ],
        ]);

        $updates = 0;
        foreach ($rows as $exp) {
            $status = null;
            try {
                switch ((string)$exp['transport_carrier']) {
                    case 'ups':
                        $status = self::fetchUpsStatus((string)$exp['transport_number'], PluginPrintgestionConfig::getSecret('api_ups'));
                        break;
                    case 'gls':
                        $status = self::fetchGlsStatus((string)$exp['transport_number'], PluginPrintgestionConfig::getSecret('api_gls'));
                        break;
                    case 'chronopost':
                        $status = self::fetchChronopostStatus((string)$exp['transport_number'], PluginPrintgestionConfig::getSecret('api_chronopost'));
                        break;
                }
            } catch (Throwable $e) {
                // Une expédition en échec ne bloque pas les suivantes, mais l'échec est tracé.
                PluginPrintgestionLogger::error(
                    'Tracking::refreshFromCarriers',
                    sprintf(
                        'Suivi transporteur de l\'expédition %d (%s) en échec.',
                        (int)$exp['id'],
                        (string)$exp['transport_carrier']
                    ),
                    $e
                );
                continue;
            }

            if ($status === null) {
                continue;
            }

            $new_statut = self::mapCarrierStatusToExpedition($status);
            if ($new_statut === null || $new_statut === $exp['statut']) {
                continue;
            }

            $data = ['statut' => $new_statut];
            if ($new_statut === PluginPrintgestionExpedition::STATUS_DELIVERED) {
                $data['date_delivered'] = date('Y-m-d H:i:s');
            }

            $DB->update('glpi_plugin_printgestion_expeditions', $data, ['id' => (int)$exp['id']]);
            $updates++;
        }

        return $updates;
    }

    /**
     * Traduit un statut générique transporteur en statut d'expédition Print Gestion.
     */
    protected static function mapCarrierStatusToExpedition(string $carrier_status): ?string {
        $s = strtolower($carrier_status);

        if (strpos($s, 'delivered') !== false || strpos($s, 'livr') !== false) {
            return PluginPrintgestionExpedition::STATUS_DELIVERED;
        }
        if (strpos($s, 'transit') !== false || strpos($s, 'in_transit') !== false) {
            return PluginPrintgestionExpedition::STATUS_TRANSIT;
        }
        return null;
    }

    /**
     * Stub API UPS : à compléter avec les endpoints réels.
     * Retourne null si la clé n'est pas configurée ou en cas d'erreur.
     */
    protected static function fetchUpsStatus(string $tracking, string $api_key): ?string {
        if ($api_key === '' || $tracking === '') {
            return null;
        }
        // TODO : appel réel https://onlinetools.ups.com/api/track/v1/details/{$tracking}
        return null;
    }

    protected static function fetchGlsStatus(string $tracking, string $api_key): ?string {
        if ($api_key === '' || $tracking === '') {
            return null;
        }
        // TODO : appel réel GLS Track & Trace API
        return null;
    }

    protected static function fetchChronopostStatus(string $tracking, string $api_key): ?string {
        if ($api_key === '' || $tracking === '') {
            return null;
        }
        // TODO : appel SOAP Chronopost TrackingServiceWS
        return null;
    }

    /**
     * Effectue un appel HTTP simple avec timeout court.
     */
    protected static function httpGet(string $url, array $headers = [], int $timeout = 5): ?string {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($err || !is_string($response)) {
            return null;
        }
        return $response;
    }

    static function install(Migration $migration) { return true; }
    static function uninstall(Migration $migration) { return true; }
}
