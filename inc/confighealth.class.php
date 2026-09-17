<?php
/**
 * PluginPrintgestionConfighealth — carte « Santé de la configuration », en tête de la configuration du plugin.
 *
 * Contrôles automatiques de l'environnement, lus à chaque affichage (rien à cocher) : ce qui est obligatoire (sans
 * lui, quelque chose ne marche pas) et ce qui est recommandé. Chaque ligne : vert ou rouge, ce qui casse, où
 * corriger. Un contrôle qui ne peut pas être automatisé le dit sur sa ligne. La carte ne bloque rien ; tout vert,
 * elle se replie en une ligne « Configuration : complète ». Réservée à l'administrateur (droit de configuration
 * du plugin, comme la page).
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionConfighealth {

    const STATE_OK     = 'ok';
    const STATE_ERROR  = 'error';
    const STATE_MANUAL = 'manual';

    /** Fenêtre dans laquelle au moins une action automatique en mode CLI doit avoir tourné (le cron système lance GLPI chaque minute). */
    const CLI_RUN_WINDOW = HOUR_TIMESTAMP;

    /**
     * Contrôles, dans l'ordre d'affichage.
     *
     * @return array[] ['key', 'group' (required|recommended), 'label', 'state', 'status' (état en une phrase),
     *                 'breaks' (ce qui casse), 'fix' (où corriger), 'url' (lien vers l'écran, ou ''), 'detail' (HTML)]
     */
    public static function getChecks(): array {
        global $CFG_GLPI;

        $esc    = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $checks = [];

        $inventory_on = (int) Config::getConfigurationValue('inventory', 'enabled_inventory') === 1;
        $checks[] = [
            'key'    => 'inventory',
            'group'  => 'required',
            'label'  => __('Inventaire GLPI activé', 'printgestion'),
            'state'  => $inventory_on ? self::STATE_OK : self::STATE_ERROR,
            'status' => $inventory_on ? __('Activé.', 'printgestion') : __('Désactivé.', 'printgestion'),
            'breaks' => __('Aucun inventaire n\'est reçu : ni sonde, ni imprimante, ni relevé de niveaux.', 'printgestion'),
            'fix'    => __('Administration → Inventaire, « Activer l\'inventaire »', 'printgestion'),
            'url'    => $CFG_GLPI['root_doc'] . '/front/inventory.conf.php',
            'detail' => '',
        ];

        $prerequisites = PluginPrintgestionCollectsetup::getPrerequisites();
        $inventory_ok  = empty($prerequisites['blocking']);
        $checks[] = [
            'key'    => 'glpiinventory',
            'group'  => 'required',
            'label'  => __('Plugin GLPI Inventory installé et actif', 'printgestion'),
            'state'  => $inventory_ok ? self::STATE_OK : self::STATE_ERROR,
            'status' => $inventory_ok
                ? sprintf(__('Actif, version %s.', 'printgestion'), $prerequisites['version'])
                : __('Absent, inactif ou inutilisable.', 'printgestion'),
            'breaks' => __('Aucune imprimante ne remonte en mode central : rien ne dit aux sondes quelles plages IP scanner.', 'printgestion'),
            'fix'    => __('Configuration → Plugins → Marketplace, rechercher « GLPI Inventory »', 'printgestion'),
            'url'    => Plugin::getSearchURL(),
            'detail' => implode('<br>', array_map($esc, array_merge($prerequisites['blocking'], $prerequisites['warnings']))),
        ];

        $cron = self::getCronStatus();
        $checks[] = [
            'key'    => 'cron',
            'group'  => 'required',
            'label'  => __('Actions automatiques en mode CLI avec un cron système', 'printgestion'),
            'state'  => $cron['ok'] ? self::STATE_OK : self::STATE_ERROR,
            'status' => $cron['status'],
            'breaks' => __('Les tâches ne tournent que quand quelqu\'un navigue : relevés irréguliers, alertes en retard, commandes non transmises jamais signalées.', 'printgestion'),
            'fix'    => __('Configuration → Actions automatiques, mode « CLI » pour chaque action ; sur le serveur, cron système qui lance front/cron.php chaque minute', 'printgestion'),
            'url'    => CronTask::getSearchURL(),
            'detail' => empty($cron['internal']) ? '' : $esc(sprintf(
                __('En mode GLPI : %s.', 'printgestion'),
                implode(', ', $cron['internal'])
            )),
        ];

        $xlsx_ok  = (bool) Document::isValidDoc('Gesconso.xlsx');
        $checks[] = [
            'key'    => 'xlsx',
            'group'  => 'required',
            'label'  => __('Type de document xlsx autorisé', 'printgestion'),
            'state'  => $xlsx_ok ? self::STATE_OK : self::STATE_ERROR,
            'status' => $xlsx_ok ? __('Autorisé.', 'printgestion') : __('Non autorisé.', 'printgestion'),
            'breaks' => __('Le fichier Gesconso ne peut pas être archivé, donc aucune commande ne part.', 'printgestion'),
            'fix'    => __('Configuration → Intitulés → Types de document, extension xlsx, « Autoriser l\'import » : Oui', 'printgestion'),
            'url'    => DocumentType::getSearchURL(),
            'detail' => '',
        ];

        $rule     = PluginPrintgestionAgentdeploy::getTagRuleStatus();
        $rule_ok  = $rule['active'] !== null;
        $checks[] = [
            'key'    => 'tag_rule',
            'group'  => 'required',
            'label'  => __('Règle d\'affectation par TAG présente et active', 'printgestion'),
            'state'  => $rule_ok ? self::STATE_OK : self::STATE_ERROR,
            'status' => $rule_ok
                ? __('Active.', 'printgestion')
                : (empty($rule['rules']) ? __('Absente.', 'printgestion') : __('Présente mais désactivée.', 'printgestion')),
            'breaks' => __('Les équipements arrivent dans la mauvaise entité, sans correction possible ensuite.', 'printgestion'),
            'fix'    => __('Bouton de cette carte', 'printgestion'),
            'url'    => '',
            'detail' => $esc(PluginPrintgestionAgentdeploy::describeTagRule($rule)),
            'button' => PluginPrintgestionAgentdeploy::getTagRuleButton($rule),
        ];

        $checks[] = [
            'key'    => 'glpicrypt',
            'group'  => 'recommended',
            'label'  => __('Sauvegarde de config/glpicrypt.key avec la base', 'printgestion'),
            'state'  => self::STATE_MANUAL,
            'status' => __('Non vérifiable automatiquement : rappel permanent.', 'printgestion'),
            'breaks' => __('Sans ce fichier, les valeurs chiffrées sont définitivement perdues à la restauration : mots de passe LDAP et SMTP, clés d\'API transporteurs.', 'printgestion'),
            'fix'    => sprintf(__('Sauvegarde du serveur : fichier %s, dans le même jeu que la base de données', 'printgestion'), GLPI_CONFIG_DIR . '/glpicrypt.key'),
            'url'    => '',
            'detail' => '',
        ];

        $log    = PluginPrintgestionLogger::getStatus();
        $log_ok = $log['dir_writable'] && $log['writable'];
        $checks[] = [
            'key'    => 'log',
            'group'  => 'recommended',
            'label'  => __('Journal du plugin inscriptible', 'printgestion'),
            'state'  => $log_ok ? self::STATE_OK : self::STATE_ERROR,
            'status' => $log_ok ? __('Inscriptible.', 'printgestion') : __('Non inscriptible : aucune trace ne sera écrite ici.', 'printgestion'),
            'breaks' => __('Sinon les erreurs sont perdues en silence.', 'printgestion'),
            'fix'    => sprintf(__('Serveur : droits d\'écriture du serveur web sur %s', 'printgestion'), $log['path']),
            'url'    => '',
            'detail' => $esc($log['exists']
                ? sprintf(__('%1$s octets, dernière écriture le %2$s.', 'printgestion'), number_format($log['size'], 0, ',', ' '), Html::convDateTime($log['modified']))
                : __('Fichier pas encore créé : aucune erreur ni avertissement journalisé depuis l\'installation, ou journal non inscriptible.', 'printgestion')),
        ];

        return $checks;
    }

    /**
     * Mode d'exécution des actions automatiques actives et preuve que le cron système tourne : aucune action active
     * en mode GLPI, et au moins une action en mode CLI lancée dans l'heure (le cron système lance GLPI chaque minute,
     * l'envoi des notifications en file tourne à chaque passage).
     *
     * @return array ['ok' => bool, 'status' => string, 'internal' => noms des actions en mode GLPI, 'last_cli' => ?string]
     */
    public static function getCronStatus(): array {
        global $DB;

        $internal = [];
        foreach ($DB->request([
            'SELECT' => ['itemtype', 'name'],
            'FROM'   => CronTask::getTable(),
            'WHERE'  => ['mode' => CronTask::MODE_INTERNAL, 'state' => ['<>', CronTask::STATE_DISABLE]],
            'ORDER'  => ['itemtype', 'name'],
        ]) as $task) {
            $internal[] = $task['name'];
        }
        $last_cli = $DB->request([
            'SELECT' => ['MAX' => 'lastrun AS lastrun'],
            'FROM'   => CronTask::getTable(),
            'WHERE'  => ['mode' => CronTask::MODE_EXTERNAL, 'state' => ['<>', CronTask::STATE_DISABLE]],
        ])->current()['lastrun'] ?? null;
        $recent = $last_cli !== null && strtotime($last_cli) >= strtotime(Session::getCurrentTime()) - self::CLI_RUN_WINDOW;

        if (!empty($internal)) {
            $status = sprintf(_n(
                '%d action automatique active en mode GLPI.',
                '%d actions automatiques actives en mode GLPI.',
                count($internal),
                'printgestion'
            ), count($internal));
        } elseif (!$recent) {
            $status = $last_cli === null
                ? __('Mode CLI réglé, mais aucune action n\'a jamais été lancée par le cron système : il ne tourne pas.', 'printgestion')
                : sprintf(__('Mode CLI réglé, mais aucune action lancée par le cron système depuis le %s : il ne tourne pas.', 'printgestion'), Html::convDateTime($last_cli));
        } else {
            $status = sprintf(__('Mode CLI, cron système actif (dernière exécution le %s).', 'printgestion'), Html::convDateTime($last_cli));
        }
        return ['ok' => empty($internal) && $recent, 'status' => $status, 'internal' => $internal, 'last_cli' => $last_cli];
    }

    /** Carte en tête de la configuration, dans le formulaire de la page (boutons envoyés à front/config.form.php). */
    public static function showCard(bool $canedit): void {
        if (!PluginPrintgestionUi::isAdmin()) {
            return;
        }
        $esc      = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $checks   = self::getChecks();
        $failed   = array_filter($checks, static fn(array $c) => $c['group'] === 'required' && $c['state'] === self::STATE_ERROR);
        $complete = empty(array_filter($checks, static fn(array $c) => $c['state'] === self::STATE_ERROR));

        $groups = [
            'required'    => __('Obligatoire — sans ça, quelque chose ne marche pas', 'printgestion'),
            'recommended' => __('Recommandé — ça marche sans, mais c\'est mieux avec', 'printgestion'),
        ];
        $icons = [
            self::STATE_OK     => ['ti-circle-check', 'text-success', __('Correct', 'printgestion')],
            self::STATE_ERROR  => ['ti-alert-octagon', 'text-danger', __('À corriger', 'printgestion')],
            self::STATE_MANUAL => ['ti-help-circle', 'text-secondary', __('Non vérifiable automatiquement', 'printgestion')],
        ];
        $body = '';
        foreach ($groups as $group => $title) {
            $body .= "<h4 class='mt-3 mb-2'>" . $esc($title) . "</h4><div class='list-group mb-2'>";
            foreach (array_filter($checks, static fn(array $c) => $c['group'] === $group) as $check) {
                [$icon, $color, $state_label] = $icons[$check['state']];
                $error = $check['state'] === self::STATE_ERROR;
                $fix   = $check['url'] !== ''
                    ? "<a href='" . $esc($check['url']) . "'>" . $esc($check['fix']) . "</a>"
                    : $esc($check['fix']);
                $body .= "<div class='list-group-item" . ($error && $group === 'required' ? ' list-group-item-danger' : '') . "' data-pg-health='" . $esc($check['key']) . "' data-pg-state='" . $esc($check['state']) . "'>"
                    . "<div class='d-flex align-items-start gap-2'><i class='ti {$icon} {$color} fs-2' title='" . $esc($state_label) . "' aria-label='" . $esc($state_label) . "'></i><div class='flex-fill'>"
                    . "<div><span class='fw-bold'>" . $esc($check['label']) . "</span> — " . $esc($check['status']) . "</div>"
                    . "<div class='" . ($error ? '' : 'text-muted ') . "small'>" . $esc($check['breaks']) . "</div>"
                    . "<div class='small'><i class='ti ti-tool me-1'></i>" . $fix . "</div>"
                    . ($check['detail'] !== '' ? "<div class='text-muted small mt-1'>" . $check['detail'] . "</div>" : '');
                if ($error && ($check['button'] ?? '') !== '' && $canedit) {
                    $body .= "<div class='mt-2'>" . $check['button'] . "</div>";
                }
                if ($check['key'] === 'log' && $canedit) {
                    $body .= "<div class='mt-2'><button type='submit' name='test_log' value='1' class='btn btn-sm btn-outline-secondary' formnovalidate><i class='ti ti-file-check me-1'></i>"
                        . $esc(__('Écrire une entrée de test et la relire', 'printgestion')) . "</button></div>";
                }
                $body .= "</div></div></div>";
            }
            $body .= "</div>";
        }

        $info = "<p>" . $esc(__('Le mode d\'exécution des actions automatiques est le prérequis le plus souvent négligé, et le plus lourd de conséquences.', 'printgestion')) . "</p>"
            . "<p>" . $esc(__('En mode « GLPI », une action ne se lance que lorsqu\'un utilisateur ouvre une page : la nuit, le week-end ou sur un GLPI peu consulté, rien ne tourne. Les relevés deviennent irréguliers, les alertes arrivent en retard, et une commande non transmise aux Achats n\'est jamais signalée, sans qu\'aucun écran ne le montre.', 'printgestion')) . "</p>"
            . "<p class='mb-0'>" . $esc(__('En mode « CLI », le cron du serveur lance GLPI chaque minute (front/cron.php, avec l\'utilisateur du serveur web) et chaque action tourne à l\'heure prévue, que quelqu\'un navigue ou non. Les deux sont nécessaires : en mode CLI sans cron système, plus rien ne tourne du tout. Ce contrôle est vert quand aucune action active n\'est en mode GLPI et qu\'une action en mode CLI a tourné dans l\'heure.', 'printgestion')) . "</p>";

        echo "<div class='card mb-3' data-pg-admin='1' id='pg-config-health'><div class='card-header d-flex align-items-center'><h3 class='card-title mb-0'>"
            . $esc(__('Santé de la configuration', 'printgestion')) . "</h3><div class='ms-auto'>"
            . PluginPrintgestionUi::infoButton(__('Actions automatiques : mode CLI et cron système', 'printgestion'), $info) . "</div></div><div class='card-body'>";
        if ($complete) {
            echo PluginPrintgestionUi::statusLine('ok', __('Configuration : complète', 'printgestion'), $body);
        } else {
            if (!empty($failed)) {
                // Impossible à manquer, sans bloquer la page.
                echo "<div class='alert alert-danger mb-2' role='alert'><i class='ti ti-alert-octagon me-1'></i><strong>" . $esc(sprintf(_n(
                    '%d prérequis obligatoire manquant : quelque chose ne marche pas.',
                    '%d prérequis obligatoires manquants : quelque chose ne marche pas.',
                    count($failed),
                    'printgestion'
                ), count($failed))) . "</strong> " . $esc(implode(', ', array_column($failed, 'label'))) . "</div>";
            }
            echo $body;
        }
        echo "</div></div>";
    }
}
