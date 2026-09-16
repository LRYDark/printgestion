<?php
/*
 * ============================================================================
 *  SIMULATION DE TEST — plugin Gestion factice pour le harnais de Print Gestion
 *
 *  N'a rien à faire sur un serveur de production. Crée seulement la table des BL
 *  lue par Print Gestion, vide ; le harnais y pose des BL inventés.
 * ============================================================================
 */
function plugin_gestion_install()
{
    global $DB;
    $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_gestion_surveys` (
        `id` int unsigned NOT NULL AUTO_INCREMENT, `tickets_id` int unsigned NOT NULL DEFAULT '0', `entities_id` int unsigned NOT NULL DEFAULT '0',
        `users_id` int unsigned NULL, `users_ext` varchar(255) NULL, `tracker` varchar(255) NULL, `url_bl` varchar(255) NULL, `bl` varchar(255) NULL,
        `bl_number` varchar(50) NULL, `signed` int NOT NULL DEFAULT '0', `date_creation` timestamp NULL, `doc_id` int unsigned NULL, `doc_url` text NULL,
        `doc_date` timestamp NULL, `relatedInvoiceToBL` varchar(255) NULL, `save` varchar(255) NULL, `paid` tinyint(1) NOT NULL DEFAULT '0', `comment` text NULL,
        PRIMARY KEY (`id`), KEY `entities_id` (`entities_id`), UNIQUE KEY `bl_number` (`bl_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    return true;
}

function plugin_gestion_uninstall()
{
    return true;
}
