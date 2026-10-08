<?php
/**
 * PluginPrintgestionDependencies — ce que Print Gestion emprunte à GLPI et à GLPI Inventory, et le contrôle qui
 * prévient quand l'un de ces emprunts disparaît ou change de nom.
 *
 * Pourquoi cette classe existe. Le plugin lit des tables, des colonnes et des classes qui ne lui appartiennent
 * pas : celles de GLPI, celles du plugin voisin, et il parle à l'agent. Quand l'un de ces éléments bouge — une
 * mise à jour de GLPI, une version d'agent qui range une adresse ailleurs —, le plugin ne tombe pas en panne
 * bruyamment : il devient *aveugle*. Une fenêtre d'installation tourne dix minutes devant une imprimante pourtant
 * présente à l'écran, et personne ne sait pourquoi. C'est arrivé le 27/09/2026 avec l'agent 1.20.
 *
 * Un contrôle ne devine pas l'avenir. Mais il transforme « cherche, on ne sait pas » en « la colonne X a disparu,
 * voilà ce qui ne marche plus ». C'est tout ce qu'on lui demande.
 *
 * Il est joué à trois moments : à l'installation ou la mise à jour du plugin (message à l'administrateur), dans la
 * carte « Santé de la configuration » (une ligne qui passe au rouge), et à chaque appel via le journal du plugin.
 *
 * Ce qu'il ne fait pas : juger les tables du plugin lui-même (le schéma s'en charge), ni vérifier ce qui vit sur
 * le poste du client (la ToolBox de l'agent n'est pas joignable d'ici).
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionDependencies {

    /**
     * Tables et colonnes de GLPI dont dépend le plugin, avec ce que chacune sert.
     *
     * Seules les colonnes qui portent une fonction sont listées : perdre `glpi_printers.last_inventory_update`
     * rend la fenêtre d'installation aveugle, perdre une colonne d'affichage ne casse rien. Une table absente
     * masque ses colonnes — on ne répète pas quinze fois la même panne.
     *
     * @return array[] ['table' => nom, 'colonnes' => string[], 'sert' => phrase]
     */
    public static function tables(): array {
        return [
            [
                'table'    => 'glpi_printers',
                'colonnes' => ['entities_id', 'is_deleted', 'last_inventory_update'],
                'sert'     => __('Reconnaître les imprimantes d\'un client et savoir lesquelles GLPI vient d\'inventorier : c\'est ce qui permet à la fenêtre d\'installation d\'annoncer « 1 imprimante trouvée ».', 'printgestion'),
            ],
            [
                'table'    => 'glpi_printers_cartridgeinfos',
                'colonnes' => ['printers_id', 'property', 'value'],
                'sert'     => __('Lire les niveaux de cartouches relevés en SNMP : alertes de consommables, seuils, et le « niveaux relevés » de la fenêtre d\'installation.', 'printgestion'),
            ],
            [
                'table'    => 'glpi_printerlogs',
                // itemtype/items_id, et non « printers_id » : GLPI y range les compteurs de tout matériel.
                'colonnes' => ['itemtype', 'items_id', 'date', 'total_pages', 'color_pages'],
                'sert'     => __('Lire les compteurs de pages : facturation à la page, historique des relevés, détection des imprimantes muettes.', 'printgestion'),
            ],
            [
                'table'    => 'glpi_ipaddresses',
                'colonnes' => ['name', 'version', 'is_deleted', 'mainitemtype', 'mainitems_id'],
                'sert'     => __('Retrouver une imprimante à son adresse IP : raccordement, contrôle de la remontée, et reconnaissance des imprimantes pendant une installation.', 'printgestion'),
            ],
            [
                'table'    => 'glpi_rulematchedlogs',
                'colonnes' => ['itemtype', 'items_id', 'agents_id', 'method', 'date'],
                'sert'     => __('Savoir quelle sonde a fait entrer une imprimante dans GLPI et quand : « sonde responsable », dates des derniers relevés, et ce qu\'un retrait total emporte.', 'printgestion'),
            ],
            [
                'table'    => 'glpi_agents',
                'colonnes' => ['deviceid', 'entities_id', 'itemtype', 'items_id', 'last_contact', 'tag',
                               'use_module_network_discovery', 'use_module_network_inventory'],
                'sert'     => __('Reconnaître les sondes, leur entité, la machine qui les porte, et leur autoriser la découverte et l\'inventaire réseau.', 'printgestion'),
            ],
            [
                'table'    => 'glpi_cartridgeitems',
                'colonnes' => ['id', 'name', 'entities_id'],
                'sert'     => __('Relier un modèle de cartouche à une imprimante, pour les commandes et les propositions d\'envoi.', 'printgestion'),
            ],
            [
                'table'    => 'glpi_contracts_items',
                'colonnes' => ['contracts_id', 'items_id', 'itemtype'],
                'sert'     => __('Savoir quelles imprimantes sont couvertes par quel contrat : coût à la page et alertes de contrat.', 'printgestion'),
            ],
            [
                'table'    => 'glpi_profilerights',
                'colonnes' => ['profiles_id', 'name', 'rights'],
                'sert'     => __('Poser et lire les droits du plugin, dont « Retirer une sonde ».', 'printgestion'),
            ],
        ];
    }

    /**
     * Classes et méthodes de GLPI appelées par le plugin, et constantes dont la valeur voyage jusque dans la base.
     *
     * Une méthode qui disparaît lève une erreur visible ; une **constante** qui change de valeur, non — les
     * journaux d'import ne seraient simplement plus reconnus, en silence. D'où leur présence ici.
     *
     * @return array[] ['quoi' => libellé, 'classe' => nom, 'methodes' => string[], 'constantes' => string[], 'sert' => phrase]
     */
    public static function classes(): array {
        return [
            [
                'classe'     => 'Agent',
                'constantes' => ['DEFAULT_PORT'],
                'sert'       => __('Joindre l\'agent sur le poste (port de son interface locale) pour le réveiller et lancer un scan.', 'printgestion'),
            ],
            [
                'classe'   => 'Printer',
                'methodes' => ['getFriendlyNameById', 'getTable'],
                'sert'     => __('Nommer les imprimantes dans les écrans et dans la fenêtre d\'installation.', 'printgestion'),
            ],
            [
                'classe'   => 'SNMPCredential',
                'methodes' => ['getTable'],
                'sert'     => __('Créer et relire les identifiants SNMP posés pour un raccordement.', 'printgestion'),
            ],
            [
                'classe'   => 'CronTask',
                'methodes' => ['Register', 'getFromDBbyName'],
                'sert'     => __('Enregistrer les tâches automatiques du plugin et vérifier qu\'elles tournent.', 'printgestion'),
            ],
            [
                'classe'   => 'ProfileRight',
                'methodes' => ['updateProfileRights', 'getProfileRights'],
                'sert'     => __('Accorder le droit « Retirer une sonde » aux profils qui pouvaient déjà supprimer une sonde.', 'printgestion'),
            ],
            [
                'classe'   => 'Glpi\\Agent\\Communication\\AbstractRequest',
                'constantes' => ['SNMP_QUERY', 'OLD_SNMP_QUERY', 'NETINV_TASK', 'NETDISCOVERY_TASK'],
                'sert'     => __('Reconnaître, dans les journaux d\'import, ce qui est une découverte et ce qui est un inventaire réseau : dates des relevés et contrôle de la remontée.', 'printgestion'),
            ],
        ];
    }

    /**
     * Tables et classes de GLPI Inventory : vérifiées **seulement s'il est actif**. Absent, ce n'est pas une
     * panne — les sondes scannent alors en local (voir la carte de santé).
     *
     * @return array[]
     */
    public static function inventoryPlugin(): array {
        return [
            [
                'table'    => 'glpi_plugin_glpiinventory_taskjoblogs',
                'colonnes' => ['plugin_glpiinventory_taskjobstates_id', 'date', 'comment'],
                'sert'     => __('Dater les passages réels des tâches de relevé, et montrer ce qu\'elles ont répondu.', 'printgestion'),
            ],
            [
                'table'    => 'glpi_plugin_glpiinventory_taskjobstates',
                'colonnes' => ['id', 'plugin_glpiinventory_taskjobs_id', 'agents_id'],
                'sert'     => __('Savoir quelle sonde a fait le dernier inventaire réseau d\'une imprimante (alertes, imprimantes muettes, couverture des sondes).', 'printgestion'),
            ],
            [
                'table'    => 'glpi_plugin_glpiinventory_taskjobs',
                'colonnes' => ['id', 'method'],
                'sert'     => __('Reconnaître les travaux d\'inventaire réseau parmi les tâches de GLPI Inventory.', 'printgestion'),
            ],
            [
                'table'    => 'glpi_plugin_glpiinventory_configs',
                'colonnes' => ['type', 'value'],
                'sert'     => __('Lire la durée de conservation des journaux de tâches, pour régler la fréquence de relevé.', 'printgestion'),
            ],
            [
                'classe'     => 'PluginGlpiinventoryTaskjoblog',
                'methodes'   => ['getTable'],
                'constantes' => ['TASK_OK', 'TASK_ERROR', 'TASK_PREPARED'],
                'sert'       => __('Lire le résultat des passages de tâche : relevé réussi, en erreur, en attente.', 'printgestion'),
            ],
            [
                'classe'     => 'PluginGlpiinventoryTaskjobstate',
                'methodes'   => ['getTable'],
                'constantes' => ['PREPARED', 'SERVER_HAS_SENT_DATA', 'AGENT_HAS_SENT_DATA', 'FINISHED', 'IN_ERROR'],
                'sert'       => __('Suivre l\'avancement d\'un relevé pendant une installation de sonde.', 'printgestion'),
            ],
            [
                'classe'   => 'PluginGlpiinventoryTask',
                'methodes' => ['getTable'],
                'sert'     => __('Créer et piloter les tâches de découverte et d\'inventaire réseau d\'un raccordement.', 'printgestion'),
            ],
            [
                'classe'   => 'PluginGlpiinventoryTaskjob',
                'methodes' => ['getTable'],
                'sert'     => __('Poser les travaux de ces tâches : quelles plages, quelles sondes.', 'printgestion'),
            ],
            [
                'classe'   => 'PluginGlpiinventoryIPRange',
                'methodes' => ['getTable'],
                'sert'     => __('Créer la plage d\'adresses à scanner pour un client.', 'printgestion'),
            ],
            [
                'classe'   => 'PluginGlpiinventoryIPRange_SNMPCredential',
                'methodes' => ['getTable'],
                'sert'     => __('Relier la plage aux identifiants SNMP, dans l\'ordre d\'essai.', 'printgestion'),
            ],
            [
                'classe'   => 'PluginGlpiinventoryAgentmodule',
                'methodes' => ['getTable'],
                'sert'     => __('Autoriser une sonde à exécuter la découverte et l\'inventaire réseau.', 'printgestion'),
            ],
        ];
    }

    /**
     * Passe tout en revue.
     *
     * @return array ['manquants' => [['quoi', 'sert']], 'verifies' => int, 'voisin' => bool (GLPI Inventory actif)]
     */
    public static function check(bool $neighbour_tables = false): array {
        global $DB;

        $manquants = [];
        $verifies  = 0;
        $voisin    = Plugin::isPluginActive('glpiinventory');
        // GLPI Inventory installé ou mis à jour mais pas encore activé : ses tables sont là (et c'est elles qu'une
        // mise à jour change), ses classes pas encore chargeables. Les tables seules, sans faux « disparu ».
        $tables_seules = !$voisin && $neighbour_tables;

        foreach (self::tables() as $attendu) {
            $verifies++;
            if (!$DB->tableExists($attendu['table'])) {
                // Table absente : ses colonnes ne sont pas listées une à une, la panne est la même.
                $manquants[] = ['quoi' => $attendu['table'], 'sert' => $attendu['sert']];
                continue;
            }
            foreach ($attendu['colonnes'] as $colonne) {
                $verifies++;
                if (!$DB->fieldExists($attendu['table'], $colonne)) {
                    $manquants[] = ['quoi' => $attendu['table'] . '.' . $colonne, 'sert' => $attendu['sert']];
                }
            }
        }

        $liste = self::classes();
        if ($voisin) {
            $liste = array_merge($liste, self::inventoryPlugin());
        } elseif ($tables_seules) {
            $liste = array_merge($liste, array_filter(self::inventoryPlugin(), static fn(array $a) => isset($a['table'])));
        }
        foreach ($liste as $attendu) {
            if (isset($attendu['table'])) {
                $verifies++;
                if (!$DB->tableExists($attendu['table'])) {
                    $manquants[] = ['quoi' => $attendu['table'], 'sert' => $attendu['sert']];
                    continue;
                }
                foreach ($attendu['colonnes'] ?? [] as $colonne) {
                    $verifies++;
                    if (!$DB->fieldExists($attendu['table'], $colonne)) {
                        $manquants[] = ['quoi' => $attendu['table'] . '.' . $colonne, 'sert' => $attendu['sert']];
                    }
                }
                continue;
            }
            $classe = (string) $attendu['classe'];
            $verifies++;
            if (!class_exists($classe)) {
                $manquants[] = ['quoi' => $classe, 'sert' => $attendu['sert']];
                continue;
            }
            foreach ($attendu['methodes'] ?? [] as $methode) {
                $verifies++;
                if (!method_exists($classe, $methode)) {
                    $manquants[] = ['quoi' => $classe . '::' . $methode . '()', 'sert' => $attendu['sert']];
                }
            }
            foreach ($attendu['constantes'] ?? [] as $constante) {
                $verifies++;
                if (!defined($classe . '::' . $constante)) {
                    $manquants[] = ['quoi' => $classe . '::' . $constante, 'sert' => $attendu['sert']];
                }
            }
        }

        return ['manquants' => $manquants, 'verifies' => $verifies, 'voisin' => $voisin];
    }

    /** Une phrase pour l'état, avec le compte : c'est elle que lit l'administrateur pressé. */
    public static function summary(array $etat): string {
        if (empty($etat['manquants'])) {
            return sprintf(
                __('Les %d éléments de GLPI utilisés par le plugin sont tous en place.', 'printgestion'),
                (int) $etat['verifies']
            );
        }
        return sprintf(
            _n('%1$d élément sur %2$d a changé ou disparu.', '%1$d éléments sur %2$d ont changé ou disparu.',
                count($etat['manquants']), 'printgestion'),
            count($etat['manquants']),
            (int) $etat['verifies']
        );
    }

    /** Session : plugin voisin activé dans la requête précédente, contrôle à faire au chargement suivant. */
    const SESSION_PENDING = 'plugin_printgestion_dependencies_pending';

    /** Plugins voisins dont la mise à jour peut changer ce que Print Gestion lit. */
    const WATCHED_PLUGINS = ['glpiinventory'];

    /**
     * Hooks post_plugin_install (installation ou « Mettre à jour » : les tables changent ici) et post_plugin_enable
     * (activation). Le contrôle n'est pas fait dans cette requête : les classes d'un plugin tout juste installé ou
     * activé n'y sont pas encore chargeables et passeraient pour disparues. Il est noté pour la page suivante
     * (afterNeighbourChange()), qui est celle où GLPI affiche son propre message.
     */
    public static function onPluginInstall($directory): void {
        self::markPending((string) $directory, 'install');
    }

    public static function onPluginEnable($directory): void {
        self::markPending((string) $directory, 'enable');
    }

    private static function markPending(string $directory, string $step): void {
        global $GLPI_CACHE;

        if (!in_array($directory, self::WATCHED_PLUGINS, true)) {
            return;
        }
        $pending = ['directory' => $directory, 'step' => $step];
        $_SESSION[self::SESSION_PENDING] = $pending;
        // Aussi dans le cache GLPI : une mise à jour en ligne de commande (bin/console plugin:install) n'a pas de
        // session de navigateur ; le contrôle est alors fait par la requête suivante, page d'un administrateur ou cron.
        if (isset($GLPI_CACHE)) {
            $GLPI_CACHE->set(self::SESSION_PENDING, $pending, 7 * DAY_TIMESTAMP);
        }
    }

    /**
     * Page suivant l'installation, la mise à jour ou l'activation d'un plugin voisin (appelé à l'initialisation du
     * plugin) : message à l'administrateur — rouge avec la liste de ce qui a changé, ou la confirmation que tout est
     * en place. Après « Mettre à jour », les tables seules ; après « Activer », tout.
     */
    public static function afterNeighbourChange(): void {
        global $GLPI_CACHE;

        $pending = $_SESSION[self::SESSION_PENDING] ?? null;
        if (!is_array($pending) && isset($GLPI_CACHE)) {
            // Marque laissée par une mise à jour en ligne de commande : faite par un administrateur (message à
            // l'écran) ou par le cron (journal seul). Une page d'un simple utilisateur la laisse en place.
            $cached = $GLPI_CACHE->get(self::SESSION_PENDING);
            if (is_array($cached) && (PHP_SAPI === 'cli' || Session::haveRight('config', UPDATE))) {
                $pending = $cached;
            }
        }
        if (!is_array($pending)) {
            return;
        }
        unset($_SESSION[self::SESSION_PENDING]);
        if (isset($GLPI_CACHE)) {
            $GLPI_CACHE->delete(self::SESSION_PENDING);
        }
        $directory = (string) ($pending['directory'] ?? '');
        $active    = Plugin::isPluginActive($directory);
        $name      = $directory === 'glpiinventory' ? 'GLPI Inventory' : $directory;
        $etat      = self::check(true);
        $step      = $active
            ? sprintf(__('après la mise à jour ou l\'activation de %s', 'printgestion'), $name)
            : sprintf(__('après l\'installation ou la mise à jour de %s (tables ; le reste sera vérifié à son activation)', 'printgestion'), $name);

        if (empty($etat['manquants'])) {
            PluginPrintgestionLogger::info('dependencies', sprintf('Contrôle %s : %s', $step, self::summary($etat)));
            Session::addMessageAfterRedirect(htmlspecialchars(sprintf(
                __('Print Gestion a vérifié ce qu\'il utilise %1$s : %2$s', 'printgestion'),
                $step,
                self::summary($etat)
            ), ENT_QUOTES, 'UTF-8'), false, INFO);
            return;
        }
        self::reportMissing($etat, sprintf(__('Print Gestion : attention %s', 'printgestion'), $step));
    }

    /** Message rouge et journal : ce qui manque, et ce que chaque manque éteint. */
    private static function reportMissing(array $etat, string $title): void {
        $detail = [];
        foreach ($etat['manquants'] as $manque) {
            $detail[] = $manque['quoi'] . ' — ' . $manque['sert'];
        }
        PluginPrintgestionLogger::error('dependencies', $title . ' — ' . self::summary($etat) . ' ' . implode(' | ', $detail));
        $message = '<strong>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . '</strong> — ' . htmlspecialchars(self::summary($etat), ENT_QUOTES, 'UTF-8')
            . ' ' . htmlspecialchars(__('Ce qui en dépend dans Print Gestion ne fonctionnera plus :', 'printgestion'), ENT_QUOTES, 'UTF-8')
            . '<ul><li>' . implode('</li><li>', array_map(
                static fn(array $m) => htmlspecialchars($m['quoi'] . ' — ' . $m['sert'], ENT_QUOTES, 'UTF-8'),
                $etat['manquants']
            )) . '</li></ul>';
        Session::addMessageAfterRedirect($message, false, ERROR);
    }

    /**
     * Contrôle joué à l'installation et à chaque mise à jour du plugin : l'administrateur doit l'apprendre là,
     * pas le jour où un technicien reste planté devant une fenêtre qui cherche.
     *
     * Ne bloque jamais l'installation : un élément manquant ne rend pas le plugin inutilisable, il en éteint une
     * partie — autant l'installer et le dire.
     */
    public static function reportAtInstall(): void {
        $etat = self::check();
        if (empty($etat['manquants'])) {
            PluginPrintgestionLogger::info('dependencies', self::summary($etat));
            return;
        }
        $detail = [];
        foreach ($etat['manquants'] as $manque) {
            $detail[] = $manque['quoi'] . ' — ' . $manque['sert'];
        }
        PluginPrintgestionLogger::error('dependencies', self::summary($etat) . ' ' . implode(' | ', $detail));
        $message = '<strong>' . htmlspecialchars(__('Print Gestion : attention', 'printgestion'), ENT_QUOTES, 'UTF-8')
            . '</strong> — ' . htmlspecialchars(self::summary($etat), ENT_QUOTES, 'UTF-8')
            . ' ' . htmlspecialchars(__('Le plugin est installé, mais ce qui en dépend ne fonctionnera plus :', 'printgestion'), ENT_QUOTES, 'UTF-8')
            . '<ul><li>' . implode('</li><li>', array_map(
                static fn(array $m) => htmlspecialchars($m['quoi'] . ' — ' . $m['sert'], ENT_QUOTES, 'UTF-8'),
                $etat['manquants']
            )) . '</li></ul>';
        Session::addMessageAfterRedirect($message, true, ERROR);
    }
}
