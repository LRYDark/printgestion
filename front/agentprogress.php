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
 *   - **rendre le compte et les noms** des imprimantes que cette sonde vient de faire entrer. Rien d'autre : ni
 *     adresse, ni numéro de série, ni identifiant SNMP — la fenêtre n'a besoin que de noms pour être crédible.
 *
 * Réponses possibles, une ligne chacune : `ATTENTE`, `AUCUNE`, ou `TROUVE <n>` suivi d'un nom par ligne.
 */
include('../../../inc/includes.php');

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
$depuis      = (string) date('Y-m-d H:i:s', (int) ($entry['created_at'] ?? time()) - 60);

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
    $racc = new PluginPrintgestionRaccordement();
    foreach ($racc->find(['agents_id' => $agents_id], ['id DESC'], 1) as $ligne) {
        if ($racc->getFromDB((int) $ligne['id']) && PluginPrintgestionCollectsetup::isAvailable()) {
            foreach ((PluginPrintgestionCollectsetup::verify($racc)['events'] ?? []) as [$level, $message]) {
                $racc->addLog(4, $level, $message);
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
    // Mode local (ToolBox) : aucun raccordement, mais la sonde envoie ses inventaires. C'est GLPI qui dit ce
    // qu'elle a fait entrer (glpi_rulematchedlogs), et la fenêtre du PC mérite la même réponse qu'en mode piloté.
    if ($racc->getID() <= 0) {
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
            $nom = trim((string) $row['name']);
            $noms[] = $nom !== '' ? $nom : sprintf(__('Imprimante n° %d', 'printgestion'), (int) $row['id']);
            if (countElementsInTable('glpi_printers_cartridgeinfos', ['printers_id' => (int) $row['id']]) > 0) {
                $niveaux++;
            }
        }
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
