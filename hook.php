<?php
/**
 * Print Gestion — hook.php
 * Install / uninstall : délègue aux classes inc/*.class.php (pattern plugin Gestion).
 */

function plugin_printgestion_install() {
    // Chargement explicite des classes utiles à l'installation : l'ordre
    // d'exécution ne dépend plus de l'ordre alphabétique des fichiers de inc/.
    foreach (['schema', 'config', 'snmpmapping', 'reminder', 'profile', 'notificationtargetdemande', 'agentsetting', 'agentalert', 'notificationtargetagentalert', 'purchaseorder', 'notificationtargetpurchaseorder', 'logger', 'entityscope', 'alertview'] as $name) {
        if (!class_exists('PluginPrintgestion' . ucfirst($name), false)) {
            include_once(dirname(__FILE__) . '/inc/' . $name . '.class.php');
        }
    }

    // La 1.0.0 s'installe sur une base vierge du plugin : une autre version en base est refusée, rien n'est touché.
    $refusal = PluginPrintgestionSchema::refusal();
    if ($refusal !== '') {
        Session::addMessageAfterRedirect(htmlspecialchars($refusal, ENT_QUOTES, 'UTF-8'), true, ERROR);
        return false;
    }
    $migration = new Migration(PLUGIN_PRINTGESTION_VERSION);
    // Schéma : tables absentes créées, lignes de référence posées ; une erreur SQL lève une exception,
    // l'installation échoue visiblement.
    PluginPrintgestionSchema::install();

    // Hors schéma, idempotent : enregistrement des tâches automatiques.
    PluginPrintgestionReminder::install($migration);
    PluginPrintgestionEntityscope::install($migration);

    $migration->executeMigration();

    PluginPrintgestionProfile::initProfile();
    // Nouveau niveau « Retirer une sonde » : donné une fois aux profils qui pouvaient déjà supprimer une sonde.
    PluginPrintgestionProfile::grantRemovalRightOnce();
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        PluginPrintgestionProfile::createFirstAccess($_SESSION['glpiactiveprofile']['id']);
    }

    // Création des gabarits de notifications par défaut
    plugin_printgestion_create_templates();

    // Notifications natives des demandes d'envoi (créées inactives, idempotent).
    PluginPrintgestionNotificationTargetDemande::install();

    // Notifications natives des alertes de sondes (créées inactives, idempotent).
    PluginPrintgestionNotificationTargetAgentalert::install();

    // Notification native des commandes non transmises aux Achats (créée active, idempotent).
    PluginPrintgestionNotificationTargetPurchaseorder::install();

    return true;
}

function plugin_printgestion_uninstall() {
    global $DB;

    $migration = new Migration(PLUGIN_PRINTGESTION_VERSION);

    foreach (glob(dirname(__FILE__) . '/inc/*.class.php') as $filepath) {
        if (preg_match('/inc.(.+)\.class\.php/', $filepath, $matches)) {
            $classname = 'PluginPrintgestion' . ucfirst($matches[1]);
            include_once($filepath);
            if (class_exists($classname) && method_exists($classname, 'uninstall')) {
                $classname::uninstall($migration);
            }
        }
    }

    $migration->executeMigration();

    // Tables (vivantes et anciennes) et contexte de configuration du plugin (version, mémos de santé et GLS).
    PluginPrintgestionSchema::uninstall();
    // Tâches automatiques du plugin, toutes : ce que chaque classe n'aurait pas retiré.
    $DB->delete('glpi_crontasks', ['itemtype' => ['LIKE', 'PluginPrintgestion%']]);
    // Préférences d'affichage et recherches enregistrées sur les objets du plugin.
    $DB->delete('glpi_displaypreferences', ['itemtype' => ['LIKE', 'PluginPrintgestion%']]);
    $DB->delete('glpi_savedsearches', ['itemtype' => ['LIKE', 'PluginPrintgestion%']]);
    // Liens documents ↔ objets du plugin ; les documents eux-mêmes (archives Gesconso envoyées aux Achats) restent.
    $DB->delete('glpi_documents_items', ['itemtype' => ['LIKE', 'PluginPrintgestion%']]);
    // Cache : jeton GLS, couverture des sondes, marqueurs de vues.
    global $GLPI_CACHE;
    if (isset($GLPI_CACHE)) {
        foreach (['printgestion_gls_token', 'printgestion_probe_coverage', 'plugin_printgestion_alertview_stale', 'plugin_printgestion_billing_ver'] as $key) {
            $GLPI_CACHE->delete($key);
        }
    }
    // Journal du plugin, installeurs et paquets mis en réserve, fichiers temporaires.
    foreach (glob(GLPI_LOG_DIR . '/printgestion*.log') ?: [] as $file) {
        @unlink($file);
    }
    foreach (glob(GLPI_TMP_DIR . '/printgestion-*') ?: [] as $file) {
        @unlink($file);
    }
    if (is_dir(GLPI_PLUGIN_DOC_DIR . '/printgestion')) {
        Toolbox::deleteDir(GLPI_PLUGIN_DOC_DIR . '/printgestion');
    }
    // Nettoyage droits
    $profileRight = new ProfileRight();
    foreach (PluginPrintgestionProfile::getAllRights() as $right) {
        $profileRight->deleteByCriteria(['name' => $right['field']]);
    }
    PluginPrintgestionProfile::removeRightsFromSession();
    PluginPrintgestionMenu::removeRightsFromSession();

    // Nettoyage gabarits de notification créés par le plugin
    $tpls = $DB->request([
        'SELECT' => ['id'],
        'FROM'   => 'glpi_notificationtemplates',
        'WHERE'  => ['comment' => 'Created by plugin printgestion'],
    ]);
    foreach ($tpls as $tpl) {
        $tpl_id = (int)$tpl['id'];
        $DB->delete('glpi_notificationtemplatetranslations', ['notificationtemplates_id' => $tpl_id]);
        $DB->delete('glpi_notificationtemplates', ['id' => $tpl_id]);
    }

    return true;
}

/**
 * Squelette HTML email-safe (CSS inline) : en-tête coloré + corps + pied de page.
 */
function plugin_printgestion_email_html(string $accent, string $title, string $bodyInner): string {
    return '<div style="margin:0;padding:24px 0;background:#f4f5f7;'
        . 'font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;">'
        . '<tr><td align="center">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" '
        . 'style="width:600px;max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e7e9ec;">'
        . '<tr><td style="background:' . $accent . ';padding:22px 28px;">'
        . '<div style="color:#ffffff;font-size:12px;letter-spacing:1.5px;text-transform:uppercase;opacity:0.8;">Print Gestion</div>'
        . '<div style="color:#ffffff;font-size:21px;font-weight:700;margin-top:5px;">' . $title . '</div>'
        . '</td></tr>'
        . '<tr><td style="padding:28px;color:#2c3338;font-size:15px;line-height:1.6;">' . $bodyInner . '</td></tr>'
        . '<tr><td style="padding:16px 28px;background:#f8f9fa;border-top:1px solid #eceef0;color:#9aa0a6;font-size:12px;">'
        . 'Message automatique — Print Gestion · JCD Groupe'
        . '</td></tr>'
        . '</table></td></tr></table></div>';
}

/**
 * Tableau d'informations clé/valeur stylé (CSS inline) pour le corps d'un email.
 * @param array $rows Liste de [label, valeur] (la valeur peut contenir des balises ##...##).
 */
function plugin_printgestion_email_rows(array $rows): string {
    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
        . 'style="border-collapse:collapse;margin:6px 0 18px;">';
    foreach ($rows as $r) {
        $html .= '<tr>'
            . '<td style="padding:9px 14px;background:#f8f9fa;border:1px solid #eceef0;'
            . 'font-size:13px;color:#6b7280;width:42%;vertical-align:top;">' . $r[0] . '</td>'
            . '<td style="padding:9px 14px;border:1px solid #eceef0;'
            . 'font-size:14px;color:#1f2937;font-weight:600;">' . $r[1] . '</td>'
            . '</tr>';
    }
    return $html . '</table>';
}

/**
 * Définitions des gabarits par défaut (pur, sans BDD) — partagées entre
 * create_templates() et tools/generate_apercu.php (aperçu HTML).
 */
function plugin_printgestion_template_definitions(): array {
    return [
        'gabarit_planif' => [
            'name'    => 'Print Gestion - Expédition cartouche',
            'subject' => '[Print Gestion] Cartouche à expédier — ##printgestion.client## · ##printgestion.printer##',
            'html'    => plugin_printgestion_email_html('#2563eb', 'Expédition cartouche requise',
                '<p style="margin:0 0 6px;">Une cartouche est à expédier pour le client suivant :</p>'
                . plugin_printgestion_email_rows([
                    ['Client',                '##printgestion.client##'],
                    ['Imprimante',            '##printgestion.printer##'],
                    ['Toner',                 '##printgestion.toner## — ##printgestion.level##%'],
                    ['Temps estimé restant',  '##printgestion.days## jours'],
                    ['Cartouche à envoyer',   '##printgestion.cartridge##'],
                    ['Contrat',               '##printgestion.contract##'],
                ])
                . '<p style="margin:0;color:#4b5563;">Merci de procéder à l\'expédition et de mettre à jour le statut dans GLPI.</p>'
            ),
        ],
        'gabarit_planif_group' => [
            'name'    => 'Print Gestion - Expédition cartouches groupée',
            'subject' => '[Print Gestion] Cartouches à expédier (##printgestion.count##) — ##printgestion.client##',
            'html'    => plugin_printgestion_email_html('#2563eb', 'Expédition de cartouches',
                plugin_printgestion_email_rows([
                    ['Client(s)',     '##printgestion.client##'],
                    ['Imprimante(s)', '##printgestion.printer##'],
                    ['Contrat',       '##printgestion.contract##'],
                ])
                . '<p style="margin:0 0 6px;font-weight:600;color:#1f2937;">Cartouches à préparer (##printgestion.count##) :</p>'
                . '<div style="margin:0 0 14px;color:#374151;">##printgestion.cartridges_list##</div>'
                . '<p style="margin:0 0 14px;color:#4b5563;">Le détail complet (code client, adresse de livraison, référence article) figure dans le <strong>fichier Gesconso joint</strong>.</p>'
                . '<p style="margin:0;color:#4b5563;">Merci de procéder aux expéditions et de mettre à jour le statut dans GLPI.</p>'
            ),
        ],
        'gabarit_achat' => [
            'name'    => 'Print Gestion - Commande cartouches (Achats)',
            'subject' => '[Print Gestion] Commande cartouches — ##printgestion.count## référence(s)',
            'html'    => plugin_printgestion_email_html('#ea580c', 'Commande cartouche requise',
                '<p style="margin:0 0 14px;">Bonjour,</p>'
                . '<p style="margin:0 0 14px;"><strong>##printgestion.count## référence(s)</strong> de cartouches sont à commander'
                . ' (client(s) : ##printgestion.client##).</p>'
                . '<p style="margin:0 0 14px;">Le détail complet (code client, adresse de livraison, référence article, quantité, prix) figure dans le '
                . '<strong>fichier Gesconso joint</strong>, prêt à importer.</p>'
                . '<p style="margin:0;color:#4b5563;">Merci de passer commande via Sage.</p>'
            ),
        ],
        'gabarit_commercial' => [
            'name'    => 'Print Gestion - Information client toner',
            'subject' => '[Print Gestion] Alerte toner — ##printgestion.client##',
            'html'    => plugin_printgestion_email_html('#0891b2', 'Information toner client',
                plugin_printgestion_email_rows([
                    ['Client',            '##printgestion.client##'],
                    ['Imprimante',        '##printgestion.printer##'],
                    ['Toner',             '##printgestion.toner## — ##printgestion.level##%'],
                    ['Temps estimé',      '##printgestion.days## jours'],
                    ['Statut expédition', '##printgestion.carrier## ##printgestion.tracking##'],
                ])
                // Liste détaillée — renseignée uniquement par les digests
                // (balise vide sur un envoi unitaire → bloc invisible)
                . '<div style="margin:0;color:#374151;">##printgestion.cartridges_list##</div>'
            ),
        ],
        'gabarit_rappel' => [
            'name'    => 'Print Gestion - Rappel installation cartouche',
            'subject' => '[Print Gestion] Rappel — ##printgestion.count## cartouche(s) expédiée(s) non installée(s)',
            'html'    => plugin_printgestion_email_html('#dc2626', 'Rappel installation cartouche',
                '<p style="margin:0 0 6px;">Les cartouches suivantes ont été expédiées mais n\'ont pas encore été '
                . 'détectées comme installées :</p>'
                . '<div style="margin:0 0 14px;color:#374151;">##printgestion.cartridges_list##</div>'
                . '<p style="margin:0;color:#4b5563;">Merci de vérifier l\'installation côté client.</p>'
            ),
        ],
        'gabarit_courtoisie' => [
            'name'    => 'Print Gestion - Courtoisie client (cartouche en route)',
            'subject' => 'Cartouche(s) en cours d\'envoi pour votre parc d\'impression',
            'html'    => plugin_printgestion_email_html('#16a34a', 'Cartouche(s) en cours d\'envoi',
                '<p style="margin:0 0 14px;">Bonjour,</p>'
                . '<p style="margin:0 0 6px;">Nous vous informons que des cartouches vont être expédiées pour la ou les imprimantes suivantes :</p>'
                . '<div style="margin:0 0 14px;color:#374151;">##printgestion.printers_list##</div>'
                . '<p style="margin:0 0 14px;">Vous les recevrez prochainement ; merci de procéder à leur installation dès réception.</p>'
                . '<p style="margin:0;color:#4b5563;">Cordialement,</p>'
            ),
        ],
    ];
}

/**
 * Crée les gabarits de notifications par défaut (pattern plugin Gestion), seulement ceux qui manquent :
 * un gabarit existant n'est jamais réécrit. Les IDs sont stockés dans glpi_plugin_printgestion_configs (id=1).
 */
function plugin_printgestion_create_templates() {
    global $DB;

    if (!$DB->tableExists('glpi_plugin_printgestion_configs')) {
        return;
    }

    $templates = plugin_printgestion_template_definitions();

    $config_updates = [];

    foreach ($templates as $config_field => $tpl) {
        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_notificationtemplates',
            'WHERE'  => ['name' => $tpl['name'], 'comment' => 'Created by plugin printgestion'],
            'LIMIT'  => 1,
        ])->current();

        if (is_array($existing) && !empty($existing['id'])) {
            // Gabarit existant : jamais réécrit. Sujet et contenu appartiennent à l'administrateur dès l'installation,
            // une mise à jour du plugin ne défait pas ses modifications ; seul l'identifiant est repris dans la
            // configuration. Un gabarit supprimé est recréé avec le texte par défaut.
            $tpl_id = (int)$existing['id'];
            // Migration : corrige itemtype='Printer' (ancienne version buggée) → 'Ticket'
            $DB->update('glpi_notificationtemplates',
                ['itemtype' => 'Ticket'],
                ['id' => $tpl_id, 'itemtype' => 'Printer']
            );
            $config_updates[$config_field] = $tpl_id;
            continue;
        }

        $DB->insert('glpi_notificationtemplates', [
            'name'          => $tpl['name'],
            'itemtype'      => 'Ticket', // Ticket expose un NotificationTarget → showAvailableTags() ne crash pas
            'comment'       => 'Created by plugin printgestion',
            'css'           => '',
            'date_creation' => date('Y-m-d H:i:s'),
            'date_mod'      => date('Y-m-d H:i:s'),
        ]);
        $tpl_id = (int)$DB->insertId();

        $DB->insert('glpi_notificationtemplatetranslations', [
            'notificationtemplates_id' => $tpl_id,
            'language'                 => 'fr_FR',
            'subject'                  => $tpl['subject'],
            'content_text'             => strip_tags($tpl['html']),
            'content_html'             => $tpl['html'],
        ]);

        $config_updates[$config_field] = $tpl_id;
    }

    if (!empty($config_updates)) {
        $DB->update('glpi_plugin_printgestion_configs', $config_updates, ['id' => 1]);
    }
}

/**
 * Hook AUTOINVENTORY_INFORMATION (Printer) : sonde responsable dans la carte native « Informations d'inventaire ».
 */
function plugin_printgestion_printer_probe_card($item): void {
    PluginPrintgestionPrinteragent::showInInventoryCard($item);
}

/**
 * Hook POST_ITEM_FORM : sonde responsable sous le formulaire d'une imprimante, quand la carte native n'est pas
 * affichée à l'utilisateur.
 */
function plugin_printgestion_printer_probe_form($params): void {
    PluginPrintgestionPrinteragent::showAfterForm(is_array($params) ? $params : []);
}
