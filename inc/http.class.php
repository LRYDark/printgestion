<?php
/**
 * Print Gestion — plugin GLPI de gestion de flotte d'impression.
 * Copyright (C) 2026 JCD Groupe — Auteur : Joris Reinert.
 * Licence GPL v3+ : texte intégral dans le fichier LICENSE à la racine du plugin.
 */
/**
 * PluginPrintgestionHttp — le client HTTP des appels sortants du plugin (GitHub, GLS, MBE).
 *
 * Pourquoi cette classe existe. Jusqu'à GLPI 11, le plugin demandait son client à `Toolbox::getGuzzleClient()`, qui
 * rendait un client Guzzle réglé sur le proxy de GLPI. GLPI 12 a retiré cette méthode. Celle-ci en est la copie
 * exacte : mêmes options, même proxy, donc mêmes réponses pour tout le code qui s'en sert.
 *
 * Ce qu'il faut savoir : Guzzle n'est plus une dépendance directe de GLPI 12 (il n'y arrive que par
 * league/oauth2-client). Le jour où GLPI ne le livre plus, c'est ici — et seulement ici — qu'il faudra passer à
 * `Glpi\Toolbox\HttpClient` (client Symfony : il lève une exception sur les codes 3xx/4xx/5xx et refuse les
 * réseaux privés hors contexte autorisé, d'où une réécriture des transports et non un simple renommage).
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionHttp {

    /**
     * Client Guzzle avec le proxy réglé dans GLPI (Configuration → Générale → Proxy).
     *
     * @param array $extra_options options Guzzle ; `proxy_excluded => true` ignore le proxy de GLPI
     */
    public static function guzzle(array $extra_options = []): \GuzzleHttp\Client {
        global $CFG_GLPI;

        $options = $extra_options + ['connect_timeout' => 5];
        if (!empty($CFG_GLPI['proxy_name']) && ($extra_options['proxy_excluded'] ?? false) === false) {
            $proxy_creds = '';
            if (!empty($CFG_GLPI['proxy_user'])) {
                $proxy_user  = rawurlencode($CFG_GLPI['proxy_user']);
                $proxy_pass  = rawurlencode((new GLPIKey())->decrypt($CFG_GLPI['proxy_passwd']));
                $proxy_creds = $proxy_user . ':' . $proxy_pass . '@';
            }
            $options['proxy'] = "http://{$proxy_creds}" . $CFG_GLPI['proxy_name'] . ':' . $CFG_GLPI['proxy_port'];
        }
        return new \GuzzleHttp\Client($options);
    }
}
