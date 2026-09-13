<?php
include('../../../inc/includes.php');

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    Html::displayNotFoundError();
}

Session::checkLoginUser();
Session::checkRight('plugin_printgestion_config', UPDATE);

global $DB, $CFG_GLPI;

if (isset($_POST['update'])) {
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
        'reminder_days'          => max(0, (int)($_POST['reminder_days']   ?? 7)),
        'detection_delta'        => max(1, min(100, (int)($_POST['detection_delta'] ?? 20))),
        'wrong_printer_lookback_days'      => max(1, (int)($_POST['wrong_printer_lookback_days'] ?? 30)),
        'wrong_printer_auto_reassign_days' => max(1, (int)($_POST['wrong_printer_auto_reassign_days'] ?? 7)),
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
        'gabarit_achat'          => (int)($_POST['gabarit_achat']      ?? 0) ?: null,
        'gabarit_commercial'     => (int)($_POST['gabarit_commercial'] ?? 0) ?: null,
        'gabarit_rappel'         => (int)($_POST['gabarit_rappel']     ?? 0) ?: null,
        'gabarit_courtoisie'     => (int)($_POST['gabarit_courtoisie'] ?? 0) ?: null,
        'reminder_recipients'    => in_array($_POST['reminder_recipients'] ?? 'both', ['planif', 'commercial', 'both'], true) ? $_POST['reminder_recipients'] : 'both',
        'tracking_frequency'     => max(1, (int)($_POST['tracking_frequency'] ?? 4)),
        'plugin_gestion_enabled' => ((int)($_POST['plugin_gestion_enabled'] ?? 0) === 1) ? 1 : 0,
        'billing_require_contract' => ((int)($_POST['billing_require_contract'] ?? 0) === 1) ? 1 : 0,
        'billing_require_counter'  => ((int)($_POST['billing_require_counter']  ?? 0) === 1) ? 1 : 0,
        'billing_require_activity' => ((int)($_POST['billing_require_activity'] ?? 0) === 1) ? 1 : 0,
        'default_pages_per_cartridge' => max(100, (int)($_POST['default_pages_per_cartridge'] ?? 5000)),
        // Anti-double-envoi
        'guard_days'             => max(0, min(365, (int)($_POST['guard_days'] ?? 5))),
        'guard_bypass_level'     => max(0, min(100, (int)($_POST['guard_bypass_level'] ?? 10))),
        'guard_ticket_days'      => max(0, min(365, (int)($_POST['guard_ticket_days'] ?? 10))),
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
    ];

    // Clés API transporteurs : chiffrées (GLPIKey) et jamais réaffichées.
    // Champ vide = clé inchangée ; case « Effacer » = suppression de la clé.
    foreach (PluginPrintgestionConfig::SECRET_FIELDS as $secret_field) {
        if (!empty($_POST['clear_' . $secret_field])) {
            $values[$secret_field] = '';
            continue;
        }
        $submitted = trim((string)($_POST[$secret_field] ?? ''));
        if ($submitted === '') {
            continue;
        }
        $encrypted = (new GLPIKey())->encrypt($submitted);
        if ($encrypted === '') {
            Session::addMessageAfterRedirect(
                sprintf(__('Clé %s non enregistrée : chiffrement impossible (clé de chiffrement GLPI illisible).', 'printgestion'), $secret_field),
                true,
                ERROR
            );
            continue;
        }
        $values[$secret_field] = $encrypted;
    }

    if ($config->update($values)) {
        Session::addMessageAfterRedirect(__('Configuration mise à jour', 'printgestion'), true, INFO);
    } else {
        Session::addMessageAfterRedirect(__('Erreur lors de la mise à jour', 'printgestion'), true, ERROR);
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
            $deleted++;
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
