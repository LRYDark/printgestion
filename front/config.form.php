<?php
include('../../../inc/includes.php');

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

Session::checkLoginUser();
Session::checkRight('plugin_printgestion_config', UPDATE);

global $DB, $CFG_GLPI;

if (isset($_POST['activate_contract_alerts'])) {
    // Réglages natifs GLPI (action automatique, entité racine, notifications) : droit GLPI.
    if (!Session::haveRight('config', UPDATE)) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $result = PluginPrintgestionContractalert::activate();
    foreach ($result['done'] as $message) {
        Session::addMessageAfterRedirect(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), false, INFO);
    }
    foreach ($result['errors'] as $message) {
        Session::addMessageAfterRedirect(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), false, WARNING);
    }
    if (empty($result['done']) && empty($result['errors'])) {
        Session::addMessageAfterRedirect(__('Les alertes de contrat natives étaient déjà actives.', 'printgestion'), false, INFO);
    }
} elseif (isset($_POST['create_tag_rule']) || isset($_POST['activate_tag_rule'])) {
    // Bouton de la carte « Santé de la configuration » : droits vérifiés par createTagRule() et activateTagRule().
    $result = isset($_POST['activate_tag_rule'])
        ? PluginPrintgestionAgentdeploy::activateTagRule()
        : PluginPrintgestionAgentdeploy::createTagRule();
    Session::addMessageAfterRedirect(htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8'), false, $result['ok'] ? INFO : WARNING);
} elseif (isset($_POST['ack_glpicrypt'])) {
    // « J'ai vérifié » la sauvegarde de glpicrypt.key : date et auteur mémorisés, la ligne se repliera six mois.
    PluginPrintgestionConfighealth::acknowledgeKeyBackup();
    Session::addMessageAfterRedirect(__('Sauvegarde de glpicrypt.key : vérification enregistrée. La ligne reviendra d\'elle-même dans six mois.', 'printgestion'), false, INFO);
} elseif (isset($_POST['cron_switch_cli'])) {
    // « Passer en mode CLI » de la carte de santé : le mode d'exécution seulement, jamais l'état des tâches — passer
    // en CLI et activer sont deux décisions, chacune son bouton. Jamais les tâches de GLPI ni d'un autre plugin.
    $done = PluginPrintgestionConfighealth::switchTasksToCli();
    Session::addMessageAfterRedirect($done > 0
        ? sprintf(_n('%d tâche de Print Gestion passée en mode CLI. Son état n\'a pas changé.', '%d tâches de Print Gestion passées en mode CLI. Leur état n\'a pas changé.', $done, 'printgestion'), $done)
        : __('Les tâches de Print Gestion qui acceptent le mode CLI y sont déjà toutes.', 'printgestion'),
        false, $done > 0 ? INFO : WARNING);
} elseif (isset($_POST['cron_enable_tasks'])) {
    // « Activer » : l'état seulement, jamais le mode. PrintgestionProposeDemandes garde son propre bouton : elle ne
    // doit être activée qu'après validation métier du flux d'export.
    $done = PluginPrintgestionConfighealth::enableTasks();
    Session::addMessageAfterRedirect($done > 0
        ? sprintf(_n('%d tâche de Print Gestion réactivée. Son mode d\'exécution n\'a pas changé.', '%d tâches de Print Gestion réactivées. Leur mode d\'exécution n\'a pas changé.', $done, 'printgestion'), $done)
        : __('Aucune tâche de Print Gestion à réactiver : la proposition automatique de demandes d\'envoi a son propre bouton.', 'printgestion'),
        false, $done > 0 ? INFO : WARNING);
} elseif (isset($_POST['cron_unblock_tasks'])) {
    // « Débloquer » : les seules tâches du plugin coincées « en cours d'exécution » repassent en attente.
    $done = PluginPrintgestionConfighealth::unblockTasks();
    Session::addMessageAfterRedirect($done > 0
        ? sprintf(_n('%d tâche de Print Gestion débloquée : elle repartira au prochain passage du cron.', '%d tâches de Print Gestion débloquées : elles repartiront au prochain passage du cron.', $done, 'printgestion'), $done)
        : __('Aucune tâche de Print Gestion coincée « en cours d\'exécution ».', 'printgestion'),
        false, $done > 0 ? INFO : WARNING);
} elseif (isset($_POST['cron_declare_system'])) {
    // Déclaration du cron système : constante native de GLPI écrite dans config/local_define.php, donc droit GLPI de
    // configuration en plus de celui du plugin. Elle dit à GLPI qu'un cron système existe, elle ne le crée pas.
    if (!Session::haveRight('config', UPDATE)) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $result = PluginPrintgestionConfighealth::declareSystemCron();
    Session::addMessageAfterRedirect(htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8'), false, $result['ok'] ? INFO : ERROR);
} elseif (isset($_POST['test_gls'])) {
    // « Tester la connexion » GLS : un jeton demandé puis jeté ; le message ne le contient jamais.
    $result = (new PluginPrintgestionGlsclient())->testConnection();
    Session::addMessageAfterRedirect(htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8'), false, $result['ok'] ? INFO : ERROR);
} elseif (isset($_POST['clear_gls'])) {
    // « Retirer les clés » GLS : identifiant et secret effacés, les suivis déjà collectés restent ; mémo et jeton oubliés.
    PluginPrintgestionConfig::getInstance()->update(['id' => 1, 'gls_client_id' => '', 'gls_client_secret' => '', 'gls_secret_date' => null]);
    PluginPrintgestionGlsclient::resetMemo();
    (new PluginPrintgestionGlsclient())->forgetToken();
    Session::addMessageAfterRedirect(__('Identifiant et secret GLS retirés : le suivi GLS est inactif, les suivis déjà collectés restent en place.', 'printgestion'), false, INFO);
} elseif (isset($_POST['test_mbe'])) {
    // « Tester la connexion » MBE : la liste des sept derniers jours, page 1, lue puis jetée ; jamais un identifiant dans le message.
    $result = (new PluginPrintgestionMbeclient())->testConnection();
    Session::addMessageAfterRedirect(htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8'), false, $result['ok'] ? INFO : ERROR);
} elseif (isset($_POST['clear_mbe'])) {
    // « Retirer les identifiants » MBE : identifiant, passphrase et date effacés, mémo de santé oublié.
    PluginPrintgestionConfig::getInstance()->update(['id' => 1, 'mbe_username' => '', 'mbe_passphrase' => '', 'mbe_secret_date' => null]);
    PluginPrintgestionMbeclient::resetMemo();
    Session::addMessageAfterRedirect(__('Identifiant et passphrase MBE retirés : plus aucun appel MBE.', 'printgestion'), false, INFO);
} elseif (isset($_POST['test_log'])) {
    // Bouton de la ligne « Journal du plugin » de la carte « Santé de la configuration » : aucun réglage enregistré.
    if (PluginPrintgestionLogger::writeTestEntry(getUserName((int) Session::getLoginUserID()))) {
        Session::addMessageAfterRedirect(__('Journal du plugin : entrée de test écrite et relue dans le fichier.', 'printgestion'), false, INFO);
    } else {
        Session::addMessageAfterRedirect(sprintf(
            __('Journal du plugin : écriture impossible dans %s (droits du dossier ou disque). Les traces partent dans le journal d\'erreurs du serveur web.', 'printgestion'),
            PluginPrintgestionLogger::getPath()
        ), false, ERROR);
    }
} elseif (isset($_POST['update'])) {
    // Validation CSRF faite par CheckCsrfListener (kernel Symfony) avant ce fichier.

    $config = PluginPrintgestionConfig::getInstance();

    // Helper : normalise une liste d'IDs users GLPI (depuis User::dropdown multi)
    // → stocke en CSV dans la colonne emails_X (nom legacy).
    $normalize_user_ids = function ($raw): string {
        if (is_array($raw)) {
            $items = $raw;
        } else {
            $items = preg_split('/[,;\s]+/u', (string)$raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $valid = [];
        foreach ($items as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $valid[$id] = $id;
            }
        }
        return implode(',', array_values($valid));
    };

    $allowed_modes = ['group', 'emails'];
    $mode_planif     = in_array($_POST['mode_planif']     ?? 'group', $allowed_modes, true) ? $_POST['mode_planif']     : 'group';
    $mode_achat      = in_array($_POST['mode_achat']      ?? 'group', $allowed_modes, true) ? $_POST['mode_achat']      : 'group';
    $mode_commercial = in_array($_POST['mode_commercial'] ?? 'group', $allowed_modes, true) ? $_POST['mode_commercial'] : 'group';

    $values = [
        'id'                     => 1,
        'threshold_days'         => max(0, (int)($_POST['threshold_days']  ?? 30)),
        'threshold_level'        => max(0, min(100, (int)($_POST['threshold_level'] ?? 15))),
        'reminder_days'          => max(1, (int)($_POST['reminder_days']   ?? 7)),
        'detection_delta'        => max(1, min(100, (int)($_POST['detection_delta'] ?? 20))),
        'wrong_printer_lookback_days'      => max(1, (int)($_POST['wrong_printer_lookback_days'] ?? 30)),
        'group_planif'           => (int)($_POST['group_planif']     ?? 0) ?: null,
        'group_achat'            => (int)($_POST['group_achat']      ?? 0) ?: null,
        'group_commercial'       => (int)($_POST['group_commercial'] ?? 0) ?: null,
        'mode_planif'            => $mode_planif,
        'mode_achat'             => $mode_achat,
        'mode_commercial'        => $mode_commercial,
        'emails_planif'          => $normalize_user_ids($_POST['emails_planif']     ?? ''),
        'emails_achat'           => $normalize_user_ids($_POST['emails_achat']      ?? ''),
        'emails_commercial'      => $normalize_user_ids($_POST['emails_commercial'] ?? ''),
        'gabarit_planif'         => (int)($_POST['gabarit_planif']     ?? 0) ?: null,
        'gabarit_planif_group'   => (int)($_POST['gabarit_planif_group'] ?? 0) ?: null,
        'gabarit_achat'          => (int)($_POST['gabarit_achat']      ?? 0) ?: null,
        'gabarit_commercial'     => (int)($_POST['gabarit_commercial'] ?? 0) ?: null,
        'gabarit_rappel'         => (int)($_POST['gabarit_rappel']     ?? 0) ?: null,
        'gabarit_courtoisie'     => (int)($_POST['gabarit_courtoisie'] ?? 0) ?: null,
        'reminder_recipients'    => in_array($_POST['reminder_recipients'] ?? 'both', ['planif', 'commercial', 'both'], true) ? $_POST['reminder_recipients'] : 'both',
        'default_pages_per_cartridge' => max(100, (int)($_POST['default_pages_per_cartridge'] ?? 5000)),
        // Anti-double-envoi
        'guard_days'             => max(0, min(365, (int)($_POST['guard_days'] ?? 5))),
        'guard_bypass_level'     => max(0, min(100, (int)($_POST['guard_bypass_level'] ?? 10))),
        'guard_ticket_days'      => max(0, min(365, (int)($_POST['guard_ticket_days'] ?? 10))),
        'silent_days'            => max(1, min(365, (int)($_POST['silent_days'] ?? PluginPrintgestionCollect::DEFAULT_SILENT_DAYS))),
        'demande_reminder_days'  => max(0, min(90, (int)($_POST['demande_reminder_days'] ?? 2))),
        // Types de contrat « consommables inclus » (IDs ContractType, CSV). Liste vide
        // si aucun type n'est sélectionné (le sélecteur multiple ne poste alors rien).
        'consumables_contracttypes' => $normalize_user_ids($_POST['consumables_contracttypes'] ?? ''),
        // Fichier Gesconso : séparateur NON rogné (ses espaces font partie du format).
        'gesconso_separator'       => trim((string)($_POST['gesconso_separator'] ?? '')) !== ''
            ? mb_substr((string)$_POST['gesconso_separator'], 0, 20)
            : PluginPrintgestionGesconso::DEFAULT_SEPARATOR,
        'gesconso_designation_max' => max(10, min(255, (int)($_POST['gesconso_designation_max'] ?? PluginPrintgestionGesconso::DEFAULT_DESIGNATION_MAX))),
        // Interrupteurs de modules
        'enable_contrats'        => ((int)($_POST['enable_contrats'] ?? 0) === 1) ? 1 : 0,
        'enable_toner'           => ((int)($_POST['enable_toner']    ?? 0) === 1) ? 1 : 0,
        'enable_cout'            => ((int)($_POST['enable_cout']     ?? 0) === 1) ? 1 : 0,
        'enable_deploiement'     => ((int)($_POST['enable_deploiement'] ?? 0) === 1) ? 1 : 0,
        'enable_sage'            => ((int)($_POST['enable_sage']     ?? 0) === 1) ? 1 : 0,
    ];

    // Suivi GLS : identifiant en clair (ce n'est pas un secret), secret chiffré (GLPIKey), jamais réaffiché.
    // Secret vide = inchangé ; « Retirer les clés » est un bouton à part.
    $values['gls_client_id'] = mb_substr(trim((string) ($_POST['gls_client_id'] ?? '')), 0, 255);
    $submitted = trim((string) ($_POST['gls_client_secret'] ?? ''));
    if ($submitted !== '') {
        $encrypted = (new GLPIKey())->encrypt($submitted);
        if ($encrypted === '') {
            Session::addMessageAfterRedirect(__('Secret GLS non enregistré : chiffrement impossible (clé de chiffrement GLPI illisible).', 'printgestion'), true, ERROR);
        } else {
            $values['gls_client_secret'] = $encrypted;
            $values['gls_secret_date']   = date('Y-m-d H:i:s');
        }
    }

    // MBE : identifiant et passphrase chiffrés (GLPIKey). L'identifiant, réaffiché, n'est rechiffré que s'il change ;
    // passphrase vide = inchangée ; « Retirer les identifiants » est un bouton à part.
    $mbe_max  = PluginPrintgestionMbeclient::MAX_CREDENTIAL_LENGTH;
    $mbe_user = mb_substr(trim((string) ($_POST['mbe_username'] ?? '')), 0, $mbe_max);
    if ($mbe_user !== PluginPrintgestionMbeclient::getUsername()) {
        $encrypted = $mbe_user === '' ? '' : (new GLPIKey())->encrypt($mbe_user);
        if ($mbe_user !== '' && $encrypted === '') {
            Session::addMessageAfterRedirect(__('Identifiant MBE non enregistré : chiffrement impossible (clé de chiffrement GLPI illisible).', 'printgestion'), true, ERROR);
        } else {
            $values['mbe_username'] = $encrypted;
        }
    }
    $submitted = mb_substr(trim((string) ($_POST['mbe_passphrase'] ?? '')), 0, $mbe_max);
    if ($submitted !== '') {
        $encrypted = (new GLPIKey())->encrypt($submitted);
        if ($encrypted === '') {
            Session::addMessageAfterRedirect(__('Passphrase MBE non enregistrée : chiffrement impossible (clé de chiffrement GLPI illisible).', 'printgestion'), true, ERROR);
        } else {
            $values['mbe_passphrase']  = $encrypted;
            $values['mbe_secret_date'] = date('Y-m-d H:i:s');
        }
    }

    // Liaison avec le plugin Gestion : rangée dans le contexte de configuration du plugin, pas dans une colonne
    // (elle doit exister sur une base déjà installée, sans migration de schéma). Le témoin caché dit que la carte
    // était affichée : sans lui, un écran rendu sans cette carte (plugin Gestion retiré) couperait la liaison au
    // premier enregistrement.
    if (!empty($_POST['gestion_link_posted'])) {
        $link_wanted = (int) ($_POST['enable_gestion_link'] ?? 0) === 1;
        if ($link_wanted !== PluginPrintgestionTracking::isGestionLinkEnabled()) {
            PluginPrintgestionTracking::setGestionLinkEnabled($link_wanted);
            Session::addMessageAfterRedirect($link_wanted
                ? __('Liaison avec le plugin Gestion activée : un BL signé fera passer son expédition en « livrée », dès la signature.', 'printgestion')
                : __('Liaison avec le plugin Gestion coupée : plus aucun BL signé ne fera passer une expédition en « livrée », et l\'association de BL à une expédition est refusée. Les expéditions déjà livrées le restent.', 'printgestion'),
                true, INFO);
        }
    }

    // Proposition automatique des demandes d'envoi : l'état vit dans la tâche automatique de GLPI, pas dans la
    // configuration du plugin. Le témoin caché dit que la carte était affichée — sans lui, un écran rendu sans elle
    // (module Toner coupé) couperait la tâche au premier enregistrement.
    if (!empty($_POST['propose_posted'])) {
        $propose_wanted = (int) ($_POST['enable_propose'] ?? 0) === 1;
        if (PluginPrintgestionConfighealth::setProposeTask($propose_wanted)) {
            Session::addMessageAfterRedirect($propose_wanted
                ? __('Proposition automatique des demandes d\'envoi activée : chaque heure, une demande sera proposée par client et par site pour les cartouches en alerte. Chaque ligne proposée bloque la commande de sa cartouche depuis l\'écran Alertes jusqu\'à son export ou son annulation.', 'printgestion')
                : __('Proposition automatique des demandes d\'envoi coupée : plus aucune demande ne sera proposée toute seule. Les demandes déjà proposées restent et gardent leur verrou jusqu\'à leur export ou leur annulation.', 'printgestion'),
                true, INFO);
        }
    }

    if ($config->update($values)) {
        Session::addMessageAfterRedirect(__('Configuration mise à jour', 'printgestion'), true, INFO);
    } else {
        Session::addMessageAfterRedirect(__('Erreur lors de la mise à jour', 'printgestion'), true, ERROR);
    }

    // Règles de lecture SNMP par constructeur (suppressions cochées, ajout).
    foreach (PluginPrintgestionSnmprule::saveConfig($_POST) as $snmp_error) {
        Session::addMessageAfterRedirect(htmlspecialchars($snmp_error, ENT_QUOTES, 'UTF-8'), false, ERROR);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Batch mapping SNMP — tout passe dans le même POST que la config.
    //  Le hidden `snmp_mapping_batch=1` est rendu dans showSnmpMappingCard().
    // ══════════════════════════════════════════════════════════════════
    if (!empty($_POST['snmp_mapping_batch'])) {

        $deleted = 0;
        $updated = 0;
        $created = 0;

        // 1. Suppressions (cases cochées)
        $delete_ids = [];
        if (isset($_POST['delete']) && is_array($_POST['delete'])) {
            foreach ($_POST['delete'] as $id => $flag) {
                if ((int)$flag === 1 && (int)$id > 0) {
                    $delete_ids[] = (int)$id;
                }
            }
        }
        foreach ($delete_ids as $id) {
            $DB->delete('glpi_plugin_printgestion_snmp_mapping', ['id' => $id]);
            $deleted += max(0, (int) $DB->affectedRows()); // ligne déjà supprimée : non comptée
        }

        // 2. Mises à jour lignes existantes (non supprimées)
        $existing_ids = [];
        if (isset($_POST['existing_ids']) && is_array($_POST['existing_ids'])) {
            foreach ($_POST['existing_ids'] as $id) {
                $id = (int)$id;
                if ($id > 0 && !in_array($id, $delete_ids, true)) {
                    $existing_ids[] = $id;
                }
            }
        }
        // Fallback : si existing_ids manque, scan les clés existing_type_N
        if (empty($existing_ids)) {
            foreach (array_keys($_POST) as $key) {
                if (preg_match('/^existing_type_(\d+)$/', (string)$key, $mm)) {
                    $id = (int)$mm[1];
                    if ($id > 0 && !in_array($id, $delete_ids, true)) {
                        $existing_ids[] = $id;
                    }
                }
            }
            $existing_ids = array_values(array_unique($existing_ids));
        }
        foreach ($existing_ids as $id) {
            $type_id = (int)($_POST["existing_type_{$id}"] ?? 0);

            $row = $DB->request([
                'SELECT' => ['snmp_property', 'cartridgeitemtypes_id'],
                'FROM'   => 'glpi_plugin_printgestion_snmp_mapping',
                'WHERE'  => ['id' => $id],
                'LIMIT'  => 1,
            ])->current();
            if (!is_array($row)) {
                continue;
            }

            // Skip si aucun changement réel sur le type cartouche
            $current_type = (int)($row['cartridgeitemtypes_id'] ?? 0);
            if ($current_type === $type_id) {
                continue;
            }

            $color = PluginPrintgestionSnmpmapping::detectColor((string)$row['snmp_property']);

            $DB->update('glpi_plugin_printgestion_snmp_mapping', [
                'cartridgeitemtypes_id' => $type_id ?: null,
                'toner_color'           => $color,
            ], ['id' => $id]);
            $updated++;
        }

        // 3. Nouvelles lignes
        if (isset($_POST['new']) && is_array($_POST['new'])) {
            foreach ($_POST['new'] as $new_row) {
                if (!is_array($new_row)) {
                    continue;
                }
                $snmp_property = trim((string)($new_row['snmp_property'] ?? ''));
                $type_id       = (int)($new_row['cartridgeitemtypes_id'] ?? 0);
                if ($snmp_property === '') {
                    continue;
                }
                $color = PluginPrintgestionSnmpmapping::detectColor($snmp_property);

                // Unique sur snmp_property → update si déjà présent
                $existing = $DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => 'glpi_plugin_printgestion_snmp_mapping',
                    'WHERE'  => ['snmp_property' => $snmp_property],
                    'LIMIT'  => 1,
                ])->current();

                if (is_array($existing)) {
                    $DB->update('glpi_plugin_printgestion_snmp_mapping', [
                        'cartridgeitemtypes_id' => $type_id ?: null,
                        'toner_color'           => $color,
                    ], ['id' => (int)$existing['id']]);
                    $updated++;
                } else {
                    $DB->insert('glpi_plugin_printgestion_snmp_mapping', [
                        'snmp_property'         => $snmp_property,
                        'cartridgeitemtypes_id' => $type_id ?: null,
                        'toner_color'           => $color,
                    ]);
                    $created++;
                }
            }
        }

        $parts = [];
        if ($created > 0) $parts[] = sprintf(__('%d ajoutée(s)', 'printgestion'), $created);
        if ($updated > 0) $parts[] = sprintf(__('%d mise(s) à jour', 'printgestion'), $updated);
        if ($deleted > 0) $parts[] = sprintf(__('%d supprimée(s)', 'printgestion'), $deleted);
        if (!empty($parts)) {
            Session::addMessageAfterRedirect(
                __('Mappings SNMP : ', 'printgestion') . implode(', ', $parts),
                true, INFO
            );
        }
    }
}

Html::redirect($CFG_GLPI['root_doc'] . '/front/config.form.php?forcetab=' . urlencode('PluginPrintgestionConfig$1'));
