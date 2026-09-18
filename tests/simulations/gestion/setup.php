<?php
/*
 * ============================================================================
 *  SIMULATION DE TEST — plugin Gestion factice pour le harnais de Print Gestion
 *
 *  N'a rien à faire sur un serveur de production : ne jamais le copier dans le
 *  dossier plugins/ d'une instance réelle (il remplacerait le vrai plugin Gestion).
 *  Installé uniquement par tests/securite/instance.sh sur une instance jetable.
 * ============================================================================
 */
define('PLUGIN_GESTION_VERSION', '0.0.1-simulation');
define('PLUGIN_GESTION_NOTFULL_WEBDIR', 'plugins/gestion');

function plugin_init_gestion()
{
    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS['csrf_compliant']['gestion'] = true;
}

function plugin_version_gestion()
{
    return [
        'name'         => 'Gestion — SIMULATION DE TEST (jamais en production)',
        'version'      => PLUGIN_GESTION_VERSION,
        'author'       => 'Harnais de test Print Gestion',
        'requirements' => ['glpi' => ['min' => '11.0.0', 'max' => '11.0.99']],
    ];
}

function plugin_gestion_check_prerequisites()
{
    return true;
}

function plugin_gestion_check_config()
{
    return true;
}
