<?php

/**
 * Suivi de la découverte, pendant l'installation d'une sonde : ce que le PC affiche dans sa fenêtre.
 *
 * Troisième et dernière page du plugin joignable sans être connecté (route déclarée dans plugin_printgestion_boot()),
 * pour la même raison que front/agentpull.php et front/agentreport.php : le PC d'un client n'a aucun compte GLPI.
 * La clé vaut dix minutes, se relit à volonté pendant ce temps, et ne sait faire que deux choses :
 *
 *   - **faire avancer le raccordement** comme le fait l'écran de GLPI quand un technicien l'ouvre : dès que la
 *     découverte est finie, le relevé SNMP est préparé et la sonde rappelée. Sans cela, rien ne bouge tant que
 *     personne ne regarde, et le technicien repart sans savoir si le client a des imprimantes dans GLPI ;
 *   - **rendre le compte et les noms** des imprimantes de ce chantier : celles du raccordement en mode piloté,
 *     celles qui portent une des adresses saisies dans la fenêtre en mode local. Rien d'autre : ni
 *     adresse, ni numéro de série, ni identifiant SNMP — la fenêtre n'a besoin que de noms pour être crédible.
 *
 * Réponses possibles, une ligne chacune : `ATTENTE`, `AUCUNE`, ou `TROUVE <n>` suivi d'un nom par ligne.
 */
include('../../../inc/includes.php');

// GLPI 11 charge les fichiers front DANS une fonction (LegacyFileLoadController) : rien n'est global ici de
// lui-même. Sans cette ligne, $DB est simplement absent, et le premier appel meurt en « Call to a member function
// request() on null » — une erreur 500 que la fenêtre du poste attrape sans un mot, puis réessaie pendant six
// minutes. Les autres pages front du plugin le déclarent toutes ; celle-ci, écrite en dernier, l'avait oublié.
global $DB;

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('deploiement')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

/** Adresse du demandeur, pour le journal seulement : jamais un critère d'autorisation. */
$from  = (string) ($_SERVER['REMOTE_ADDR'] ?? '?');
$token = (string) ($_GET['t'] ?? '');
$entry = $token === '' ? null : PluginPrintgestionAgenttoken::peek($token, PluginPrintgestionAgenttoken::USAGE_PROGRESS);
if ($entry === null) {
    PluginPrintgestionLogger::warning('agentprogress', sprintf('Suivi refusé (clé inconnue ou expirée) depuis %s.', $from));
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

$entities_id = (int) $entry['entities_id'];
$computer    = (string) ($entry['pc'] ?? '');

// Depuis quand cette clé est ouverte, dans l'horloge de PHP — la même que celle qui date les lignes.
//
// Ce serveur a deux horloges qui ne s'accordent pas : MySQL est deux heures devant PHP (vu dans le journal, une
// ligne horodatée 18:39 par PHP pendant que NOW() rendait 20:34). C'est PHP qui écrit les dates de GLPI : une
// imprimante relevée à 18 h 22 s'enregistre « 16:22 ». La borne doit donc venir de PHP, comme les valeurs
// auxquelles on la compare. (Le désaccord des deux horloges, lui, se règle sur le serveur, pas ici.)
$depuis = (string) date('Y-m-d H:i:s', (int) ($entry['created_at'] ?? time()) - 60);

$reponse = 'ATTENTE';
$agents_id = PluginPrintgestionRaccordement::findAgentByComputer($entities_id, $computer);
if ($agents_id > 0) {
    // Les modules réseau de la sonde, dès qu'elle est connue : le compte rendu les pose déjà, mais il part parfois
    // avant que GLPI ait rattaché l'agent à son ordinateur — et sans eux, GLPI Inventory n'envoie aucune tâche
    // réseau. Ici, la sonde existe : on repose le profil, sans bruit s'il est déjà bon.
    if (PluginPrintgestionCollectsetup::isAvailable()) {
        foreach (PluginPrintgestionCollectsetup::applyPrinterProbeProfile($agents_id)['events'] as [$niveau, $message]) {
            // Au vrai niveau : un module qu'on n'a pas pu poser condamne toutes les tâches réseau de cette sonde.
            // Journalisé en « info », personne ne le voyait passer.
            if ($niveau === 'error') {
                PluginPrintgestionLogger::error('agentprogress', sprintf('Sonde %1$s : %2$s', $computer, $message));
            } elseif ($niveau !== 'info') {
                PluginPrintgestionLogger::info('agentprogress', sprintf('Sonde %1$s : %2$s', $computer, $message));
            }
        }
    }

    // Le raccordement avance : découverte finie, relevé SNMP préparé, sonde rappelée. C'est le geste de l'écran
    // du raccordement, fait ici pour le PC qui attend — le résultat est le même, journal du raccordement compris.
    // Le mode vient de la clé, jamais de ce qui traîne en base : un poste déjà utilisé en mode piloté garde son
    // raccordement, et le suivi lisait alors SA table d'adresses — que personne ne remplit quand c'est la ToolBox
    // de l'agent qui scanne. Six minutes d'attente pendant que l'imprimante entrait dans GLPI.
    $mode_local = !empty($entry['local']);
    $racc       = new PluginPrintgestionRaccordement();
    if (!$mode_local) {
        foreach ($racc->find(['agents_id' => $agents_id], ['id DESC'], 1) as $ligne) {
            if ($racc->getFromDB((int) $ligne['id']) && PluginPrintgestionCollectsetup::isAvailable()) {
                foreach ((PluginPrintgestionCollectsetup::verify($racc)['events'] ?? []) as [$level, $message]) {
                    $racc->addLog(4, $level, $message);
                }
            }
        }
    }

    // Les imprimantes trouvées : exactement celles que l'écran du raccordement affiche, lues au même endroit
    // (la table des adresses, remplie par la vérification ci-dessus). Chercher ailleurs, c'était risquer de dire
    // « rien » quand l'écran, lui, montrait l'imprimante.
    $noms    = [];
    $niveaux = 0;
    $fini    = false;
    if ($racc->getID() > 0) {
        $fini = !empty(PluginPrintgestionCollectsetup::getProgress($racc)['discovery_finished']);
        $vues = [];
        foreach ($racc->getIps() as $ligne) {
            if ((string) ($ligne['itemtype'] ?? '') !== Printer::class || (int) $ligne['items_id'] <= 0) {
                continue;
            }
            // Une fiche rattachée à une autre entité n'est pas une imprimante du client : l'écran le dit, la
            // fenêtre du PC ne va pas prétendre le contraire.
            if ((string) $ligne['result'] === 'wrong_entity' || isset($vues[(int) $ligne['items_id']])) {
                continue;
            }
            $vues[(int) $ligne['items_id']] = true;
            $nom = trim((string) Printer::getFriendlyNameById((int) $ligne['items_id']));
            $noms[] = $nom !== '' ? $nom : sprintf(__('Imprimante n° %d', 'printgestion'), (int) $ligne['items_id']);
            // Niveaux relevés : c'est le vrai but du plugin. Le PC attend ce nombre pour savoir s'il peut partir.
            if (countElementsInTable('glpi_printers_cartridgeinfos', ['printers_id' => (int) $ligne['items_id']]) > 0) {
                $niveaux++;
            }
        }
    }
    // Mode local (ToolBox) : rien à attendre d'un raccordement — il n'y en a pas, ou il date d'une installation
    // pilotée précédente. Les imprimantes se reconnaissent aux ADRESSES que le
    // technicien a tapées — la clé de suivi les porte —, et non à l'agent du journal d'import : en inventaire
    // natif, cette ligne porte l'agent rattaché à l'imprimante elle-même, pas la sonde. La fenêtre attendait donc
    // six minutes sans rien voir pendant que l'imprimante entrait dans GLPI avec ses cartouches.
    if ($racc->getID() <= 0) {
        $adresses = [];
        foreach (array_keys(PluginPrintgestionRaccordement::parseIps((string) ($entry['ips'] ?? ''))['ips']) as $long) {
            $adresses[] = long2ip((int) $long);
        }
        $trouvees = [];
        $inventaires = [];
        if (!empty($adresses)) {
            foreach ($DB->request([
                'SELECT'     => ['p.id', 'p.name', 'p.last_inventory_update'],
                'DISTINCT'   => true,
                'FROM'       => 'glpi_ipaddresses AS ip',
                'INNER JOIN' => [Printer::getTable() . ' AS p' => ['ON' => ['ip' => 'mainitems_id', 'p' => 'id']]],
                'WHERE'      => [
                    'ip.mainitemtype' => Printer::class,
                    'ip.version'      => 4,
                    'ip.is_deleted'   => 0,
                    'ip.name'         => $adresses,
                    'p.entities_id'   => $entities_id,
                    'p.is_deleted'    => 0,
                ],
                'ORDER'      => ['p.name'],
                'LIMIT'      => 50,
            ]) as $row) {
                $trouvees[(int) $row['id']]    = (string) $row['name'];
                $inventaires[(int) $row['id']] = (string) ($row['last_inventory_update'] ?? '');
            }
            // Une imprimante déjà connue ne compte que si la sonde vient de la relever : sur un parc déjà
            // inventorié, la fenêtre annoncerait sinon « trouvée » avant même que le scan ait eu lieu.
            //
            // Deux témoins, et le plus récent l'emporte. GLPI date lui-même chaque inventaire reçu sur la fiche
            // (last_inventory_update) : c'est le seul qui bouge quand une imprimante déjà connue est simplement
            // relevée à nouveau — le journal d'import, lui, n'ajoute pas forcément de ligne dans ce cas, et la
            // fenêtre refusait alors une imprimante pourtant relevée à l'instant.
            $dates   = PluginPrintgestionCollect::getImportDates(array_keys($trouvees));
            $refusee = [];
            foreach (array_keys($trouvees) as $printers_id) {
                $vue = max(
                    (string) ($inventaires[$printers_id] ?? ''),
                    (string) ($dates[$printers_id]['snmp'] ?? ''),
                    (string) ($dates[$printers_id]['discovery'] ?? '')
                );
                if ($vue === '' || $vue < $depuis) {
                    $refusee[$printers_id] = $vue === '' ? 'jamais inventoriée' : $vue;
                    unset($trouvees[$printers_id]);
                }
            }
            // Le seul cas qui mérite une trace : des imprimantes existent bien aux adresses du technicien, mais
            // aucune n'a été relevée depuis l'ouverture de la clé. La fenêtre attend alors sans que personne puisse
            // le deviner. Quand tout va bien, rien ne s'écrit — un journal qui répète « rien pour l'instant » finit
            // par n'être plus lu. Les adresses y figurent (elles viennent du technicien), jamais la communauté SNMP.
            if ($trouvees === [] && $refusee !== []) {
                PluginPrintgestionLogger::info('agentprogress', sprintf(
                    'Sonde %1$s (agent %2$d) : scan local, adresses %3$s — %4$d imprimante(s) connue(s) à ces adresses, aucune relevée depuis %5$s (%6$s).',
                    $computer,
                    $agents_id,
                    implode(', ', $adresses),
                    count($refusee),
                    $depuis,
                    implode(', ', array_map(
                        static fn($id, $vue) => sprintf('%1$d vue %2$s', (int) $id, (string) $vue),
                        array_keys($refusee),
                        $refusee
                    ))
                ));
            }
        } else {
            // Clé d'un fichier plus ancien, sans adresses : l'ancien chemin, par les journaux d'import de la sonde.
            foreach ($DB->request([
                'SELECT'     => ['p.id', 'p.name'],
                'DISTINCT'   => true,
                'FROM'       => 'glpi_rulematchedlogs AS l',
                'INNER JOIN' => [Printer::getTable() . ' AS p' => ['ON' => ['l' => 'items_id', 'p' => 'id']]],
                'WHERE'      => [
                    'l.itemtype'    => Printer::class,
                    'l.agents_id'   => $agents_id,
                    'p.entities_id' => $entities_id,
                    'p.is_deleted'  => 0,
                    ['l.date' => ['>=', $depuis]],
                ],
                'ORDER'      => ['p.name'],
                'LIMIT'      => 50,
            ]) as $row) {
                $trouvees[(int) $row['id']] = (string) $row['name'];
            }
        }
        // Deuxième regard, qui ne doit rien au rangement des adresses : les imprimantes de cette entité que GLPI
        // vient d'inventorier depuis l'ouverture de la clé. La date est posée par GLPI lui-même sur la fiche, à
        // chaque inventaire reçu — c'est le fait brut « quelque chose est arrivé pendant cette installation ».
        //
        // Pourquoi deux chemins : avec l'agent 1.19, l'adresse suffisait ; avec la 1.20, la fenêtre est devenue
        // aveugle du jour au lendemain devant une imprimante pourtant affichée dans GLPI. Un seul chemin de
        // reconnaissance, c'est une panne muette à chaque changement chez le voisin.
        if ($DB->fieldExists(Printer::getTable(), 'last_inventory_update')) {
            foreach ($DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => Printer::getTable(),
                'WHERE'  => [
                    'entities_id' => $entities_id,
                    'is_deleted'  => 0,
                    ['last_inventory_update' => ['>=', $depuis]],
                ],
                'ORDER'  => ['name'],
                'LIMIT'  => 50,
            ]) as $row) {
                $trouvees[(int) $row['id']] = (string) $row['name'];
            }
        }

        // Rien aux adresses du technicien alors que la clé a déjà trois minutes : l'attente n'est plus normale,
        // et c'est le seul moment où le silence coûte cher. Avant, on se taisait — et l'on cherchait ensuite à
        // l'aveugle. Une installation qui se passe bien n'écrit toujours rien : elle trouve en moins de trois
        // minutes, et la fenêtre se referme.
        if ($trouvees === [] && $refusee === [] && (time() - (int) ($entry['created_at'] ?? time())) > 180) {
            PluginPrintgestionLogger::info('agentprogress', sprintf(
                'Sonde %1$s (agent %2$d) : aucune imprimante à ces adresses (%3$s) ni inventoriée dans l\'entité %4$d depuis le début — la fenêtre attend depuis plus de trois minutes.',
                $computer,
                $agents_id,
                implode(', ', $adresses),
                $entities_id
            ));
        }

        foreach ($trouvees as $printers_id => $nom) {
            $nom    = trim($nom);
            $noms[] = $nom !== '' ? $nom : sprintf(__('Imprimante n° %d', 'printgestion'), $printers_id);
            if (countElementsInTable('glpi_printers_cartridgeinfos', ['printers_id' => $printers_id]) > 0) {
                $niveaux++;
            }
        }
    }

    // La fenêtre n'a fini que lorsque CHAQUE imprimante trouvée a ses niveaux. Quand il en manque, elle attend
    // sans pouvoir le dire : une ligne ici, et une seule dans ce cas, montre l'écart — deux imprimantes à la même
    // adresse dont une seule relevée, par exemple. Silence quand le compte est bon.
    if (!empty($noms) && $niveaux < count($noms)) {
        PluginPrintgestionLogger::info('agentprogress', sprintf(
            'Sonde %1$s (agent %2$d) : %3$d imprimante(s) — %4$s —, %5$d avec des niveaux : la fenêtre attend les autres.',
            $computer,
            $agents_id,
            count($noms),
            implode(', ', $noms),
            $niveaux
        ));
    }

    if (!empty($noms)) {
        sort($noms);
        // « TROUVE <imprimantes> <avec niveaux> », puis un nom par ligne.
        $reponse = 'TROUVE ' . count($noms) . ' ' . $niveaux . chr(10) . implode(chr(10), array_slice($noms, 0, 50));
    } elseif ($fini) {
        $reponse = 'AUCUNE';
    }
}

// Le PC n'attend rien d'autre : une page d'erreur ne doit pas passer pour un résultat.
return new \Symfony\Component\HttpFoundation\Response($reponse, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
