<?php
/**
 * PluginPrintgestionAgentreport — ce que l'installation a réellement fait sur le PC.
 *
 * GLPI ne pousse rien : la tâche de mise à jour n'existe que si un fichier l'a posée sur le PC. Jusqu'ici, l'écran
 * d'une sonde affichait donc un **souhait** (« Mise à jour automatique : oui ») sans rien savoir de la réalité — et
 * la case restait cochée pour un PC où personne n'avait rien posé. Un écran qui affirme ce qu'il ignore est pire
 * qu'un écran qui se tait.
 *
 * Le fichier unique d'installation dit donc, en dernier geste, ce qu'il a fait : tâche posée, ou non. Ce compte rendu
 * est gardé ici et remonté sur la fiche de la sonde, à côté du réglage souhaité.
 *
 * Il est rapproché de la sonde par le **nom du PC** : au moment de l'installation, l'agent n'existe pas encore dans
 * GLPI (il ne s'enregistre qu'à son premier inventaire), il n'y a donc aucun identifiant à transmettre. Le nom du PC
 * est ce que les deux côtés connaissent.
 *
 * Ce que ce compte rendu vaut : une déclaration de l'installeur, datée, et rien de plus. Il dit ce qui a été fait ce
 * jour-là ; il ne dit pas ce que quelqu'un a pu changer depuis sur le PC. L'écran le formule ainsi, sans jamais le
 * présenter comme l'état courant.
 *
 * Rangement : configuration GLPI du plugin, même mécanique que les clés (PluginPrintgestionAgenttoken::readList()
 * et writeList() : base64, écriture avec pari sur l'ancienne valeur). Pas de table dédiée — une ligne par sonde
 * installée, gardée trois mois, ne vaut pas une étape de schéma.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionAgentreport {

    /** Clé de rangement dans la configuration GLPI du plugin. */
    const KEY = 'agent_install_reports';

    /** Au-delà, les plus anciens comptes rendus sont oubliés : c'est un mémo, pas un journal. */
    const MAX_KEPT = 200;

    /** Durée de garde : trois mois. Au-delà, un compte rendu ne dit plus rien d'utile sur l'état d'un PC. */
    const TTL = 90 * DAY_TIMESTAMP;

    /** Nombre de tentatives d'écriture : une perte de pari se rejoue. */
    const WRITE_TRIES = 3;

    /**
     * Crée la ligne de rangement si elle n'existe pas encore, pendant qu'une session GLPI est ouverte.
     *
     * Le compte rendu, lui, arrive sans session : il ne doit alors emprunter que l'écriture directe (UPDATE avec pari
     * sur l'ancienne valeur). La toute première écriture, qui passe par Config et donc par CommonDBTM, est ainsi
     * faite ici, au moment où l'on fabrique le fichier d'installation.
     */
    public static function ensureStore(): void {
        $state = PluginPrintgestionAgenttoken::readList(self::KEY);
        if ($state['raw'] === null) {
            PluginPrintgestionAgenttoken::writeList(self::KEY, $state, []);
        }
    }

    /**
     * Enregistre ce que l'installation a fait sur un PC. Un nouveau compte rendu remplace le précédent pour le même
     * PC dans la même entité : ce qui intéresse, c'est la dernière installation, pas leur histoire.
     */
    public static function record(int $entities_id, string $computer, string $platform, bool $scheduled, bool $removed = false, string $ips = '', int $racc = 0, bool $scan = false): bool {
        $nom = self::normalize($computer);
        if ($nom === '') {
            return false;
        }
        $entry = [
            'pc'          => $nom,
            'entities_id' => $entities_id,
            'platform'    => $platform,
            'scheduled'   => $scheduled,
            'removed'     => $removed,
            // Adresses déclarées depuis le PC, et le raccordement qu'elles ont créé (0 : aucun). La communauté SNMP,
            // elle, n'est jamais gardée : elle a servi à créer les identifiants dans GLPI, c'est là qu'elle vit.
            'ips'         => substr($ips, 0, 500),
            'racc'        => $racc,
            // Vrai : GLPI Inventory manquait, c'est donc le PC qui scanne tout seul. La fiche doit le dire, sinon on
            // chercherait en vain une plage IP côté serveur.
            'scan'        => $scan,
            'at'          => time(),
        ];
        for ($try = 0; $try < self::WRITE_TRIES; $try++) {
            $state = PluginPrintgestionAgenttoken::readList(self::KEY);
            $list  = array_values(array_filter(
                self::prune($state['list']),
                static fn(array $e) => $e['pc'] !== $nom || (int) $e['entities_id'] !== $entities_id
            ));
            $list[] = $entry;
            if (count($list) > self::MAX_KEPT) {
                $list = array_slice($list, -self::MAX_KEPT);
            }
            if (PluginPrintgestionAgenttoken::writeList(self::KEY, $state, $list)) {
                return true;
            }
        }
        PluginPrintgestionLogger::error('agentreport', sprintf('Compte rendu d\'installation de %s non enregistré.', $nom));
        return false;
    }

    /**
     * Supprime de GLPI la sonde d'un PC que le fichier de retrait vient de nettoyer, quand il l'a demandé.
     *
     * Deux niveaux : la sonde seule, ou **tout** — la sonde, les imprimantes qu'elle a fait entrer dans GLPI, la
     * fiche de l'ordinateur qui la portait, et les objets de collecte que son raccordement avait créés dans GLPI
     * Inventory (tâches, jobs, plage IP, identifiants SNMP — jamais ceux qu'il avait seulement réutilisés). Tout passe par les classes natives (`Agent`, `Printer`,
     * `Computer`) : historique, hooks des autres plugins et le nôtre (`item_purge` → `Cleanup`) jouent comme pour
     * une suppression faite dans GLPI. Rien hors de l'entité du fichier.
     *
     * @return array ['issue' => OK | TOTAL | ABSENT | REFUSE, 'agents', 'printers', 'computers', 'collecte']
     */
    public static function purgeProbe(int $entities_id, string $computer, bool $total = false): array {
        $compte = ['issue' => 'ABSENT', 'agents' => 0, 'printers' => 0, 'computers' => 0, 'collecte' => 0];
        $id     = PluginPrintgestionRaccordement::findAgentByComputer($entities_id, $computer);
        if ($id <= 0) {
            return $compte;
        }
        $agent = new Agent();
        if (!$agent->getFromDB($id)) {
            return $compte;
        }
        // Les imprimantes sont cherchées tant que la sonde est là : c'est elle qui les désigne.
        $printers     = $total ? self::printersOfProbe($entities_id, $id) : [];
        $computers_id = $total && (string) $agent->fields['itemtype'] === Computer::class ? (int) $agent->fields['items_id'] : 0;
        try {
            // Les objets de collecte d'abord, tant que les raccordements existent : c'est eux qui en gardent la
            // liste, et la suppression de la sonde les emporte.
            if ($total) {
                $racc = new PluginPrintgestionRaccordement();
                foreach ($racc->find(['agents_id' => $id]) as $ligne) {
                    if ($racc->getFromDB((int) $ligne['id'])) {
                        $compte['collecte'] += PluginPrintgestionCollectsetup::purgeCreatedItems($racc);
                    }
                }
            }
            $printer = new Printer();
            foreach ($printers as $printers_id) {
                if ($printer->getFromDB($printers_id) && $printer->delete(['id' => $printers_id], true)) {
                    $compte['printers']++;
                }
            }
            if (!$agent->delete(['id' => $id], true)) {
                PluginPrintgestionLogger::error('agentreport', sprintf('Sonde %1$s (agent %2$d) : suppression refusée par GLPI.', $computer, $id));
                $compte['issue'] = 'REFUSE';
                return $compte;
            }
            $compte['agents'] = 1;
            // La fiche de l'ordinateur en dernier : la sonde y était rattachée, et seulement si elle est bien
            // dans l'entité du fichier — une sonde déplacée depuis ne doit pas emporter le PC d'un autre client.
            $pc = new Computer();
            if ($computers_id > 0 && $pc->getFromDB($computers_id) && (int) $pc->fields['entities_id'] === $entities_id
                && $pc->delete(['id' => $computers_id], true)) {
                $compte['computers'] = 1;
            }
        } catch (Throwable $e) {
            PluginPrintgestionLogger::error('agentreport', sprintf('Sonde %1$s (agent %2$d) : suppression interrompue.', $computer, $id), $e);
            $compte['issue'] = 'REFUSE';
            return $compte;
        }
        $compte['issue'] = $total ? 'TOTAL' : 'OK';
        return $compte;
    }

    /**
     * Les imprimantes qu'une sonde a fait entrer dans GLPI, dans une entité.
     *
     * C'est GLPI qui le dit : `glpi_rulematchedlogs` garde, pour chaque import, l'agent qui l'a envoyé. On retient
     * la **dernière** sonde connue de chaque imprimante — celle que le plugin nomme déjà « sonde responsable »
     * dans le contrôle de la remontée. Une imprimante relevée depuis par une autre sonde n'est donc pas emportée.
     *
     * @return int[] identifiants d'imprimantes
     */
    private static function printersOfProbe(int $entities_id, int $agents_id): array {
        global $DB;

        $derniere = [];
        foreach ($DB->request([
            'SELECT'     => ['l.items_id', 'l.agents_id'],
            'FROM'       => 'glpi_rulematchedlogs AS l',
            'INNER JOIN' => [Printer::getTable() . ' AS p' => ['ON' => ['l' => 'items_id', 'p' => 'id']]],
            'WHERE'      => ['l.itemtype' => Printer::class, 'p.entities_id' => $entities_id],
            'ORDER'      => ['l.date ASC', 'l.id ASC'],
        ]) as $row) {
            $derniere[(int) $row['items_id']] = (int) $row['agents_id'];
        }
        return array_keys(array_filter($derniere, static fn(int $sonde): bool => $sonde === $agents_id));
    }

    /**
     * Dernier compte rendu connu pour un PC d'une entité, ou null.
     *
     * @return ?array ['pc', 'entities_id', 'platform', 'scheduled' => bool, 'at' => timestamp]
     */
    public static function find(int $entities_id, string $computer): ?array {
        $nom = self::normalize($computer);
        if ($nom === '') {
            return null;
        }
        foreach (self::prune(PluginPrintgestionAgenttoken::readList(self::KEY)['list']) as $entry) {
            if ($entry['pc'] === $nom && (int) $entry['entities_id'] === $entities_id) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Phrase à afficher sous le réglage souhaité. Elle distingue trois situations, et n'en maquille aucune : posée,
     * non posée, ou inconnue de GLPI.
     */
    public static function describe(?array $report): string {
        if ($report === null) {
            return __('Sur ce PC : GLPI ne sait pas. Rien ne lui a été déclaré — seule la consigne lancée sur le PC applique le réglage ci-dessus.', 'printgestion');
        }
        $quand = Html::convDateTime(date('Y-m-d H:i:s', (int) $report['at']));
        if (!empty($report['removed'])) {
            return sprintf(__('Sur ce PC : GLPI Agent a été retiré le %s (déclaré par le fichier de retrait). Cette sonde ne remontera plus rien ; elle peut être supprimée de la liste des agents.', 'printgestion'), $quand);
        }
        // macOS n'a pas de tâche automatique : l'absence y est normale, la dire « non posée » ferait croire à un oubli.
        if ((string) ($report['platform'] ?? '') === 'macos') {
            return sprintf(__('Sur ce Mac : installation déclarée le %s. Pas de tâche automatique sous macOS — la mise à jour s\'y fait en relançant un fichier d\'installation plus récent.', 'printgestion'), $quand);
        }
        $phrase = !empty($report['scheduled'])
            ? sprintf(__('Sur ce PC : tâche de mise à jour posée, déclarée par l\'installation du %s. Ce qui a pu changer sur le PC depuis n\'est pas connu de GLPI.', 'printgestion'), $quand)
            : sprintf(__('Sur ce PC : aucune tâche de mise à jour posée, déclaré par l\'installation du %s. Ce qui a pu changer sur le PC depuis n\'est pas connu de GLPI.', 'printgestion'), $quand);
        // Adresses saisies dans la fenêtre d'installation : dire ce qu'elles ont donné, sinon personne ne saura si
        // le raccordement a été créé ou s'il reste à faire.
        if (trim((string) ($report['ips'] ?? '')) !== '') {
            $phrase .= ' ' . match (true) {
                !empty($report['scan'])           => sprintf(__('Adresses déclarées à l\'installation (%s) : GLPI Inventory étant absent, c\'est ce PC qui balaie le réseau tous les jours et envoie ce qu\'il trouve.', 'printgestion'), $report['ips']),
                (int) ($report['racc'] ?? 0) > 0  => sprintf(__('Adresses déclarées à l\'installation (%1$s) : raccordement n° %2$d créé.', 'printgestion'), $report['ips'], (int) $report['racc']),
                default                           => sprintf(__('Adresses déclarées à l\'installation (%s), mais le raccordement n\'a pas pu être créé : à reprendre dans l\'assistant Raccordements.', 'printgestion'), $report['ips']),
            };
        }
        return $phrase;
    }

    /** Clé de rapprochement : une entité et un nom de PC. */
    public static function key(int $entities_id, string $computer): string {
        return $entities_id . '|' . self::normalize($computer);
    }

    /** Tous les comptes rendus valides, indexés par entité et nom de PC : une seule lecture pour toute une liste. */
    public static function index(): array {
        $index = [];
        foreach (self::prune(PluginPrintgestionAgenttoken::readList(self::KEY)['list']) as $entry) {
            $index[self::key((int) $entry['entities_id'], (string) $entry['pc'])] = $entry;
        }
        return $index;
    }

    /** Résumé d'une ligne de liste : trois mots, pas une phrase. */
    public static function summarize(?array $report): string {
        if ($report === null) {
            return __('non déclarée', 'printgestion');
        }
        if (!empty($report['removed'])) {
            return __('agent retiré', 'printgestion');
        }
        if ((string) ($report['platform'] ?? '') === 'macos') {
            return __('sans objet (macOS)', 'printgestion');
        }
        return !empty($report['scheduled']) ? __('posée', 'printgestion') : __('non posée', 'printgestion');
    }

    /** Nom de PC comparable des deux côtés : sans espaces, en majuscules, sans domaine. */
    private static function normalize(string $computer): string {
        $nom = strtoupper(trim($computer));
        $nom = (string) preg_replace('/\..*$/', '', $nom);          // lenovo-01.societe.lan → LENOVO-01
        $nom = (string) preg_replace('/[^A-Z0-9._-]/', '', $nom);
        return substr($nom, 0, 64);
    }

    /** Oublie les comptes rendus trop vieux pour dire encore quelque chose. */
    private static function prune(array $list): array {
        $limite = time() - self::TTL;
        return array_values(array_filter(
            $list,
            static fn($e) => is_array($e) && isset($e['pc'], $e['at']) && (int) $e['at'] > $limite
        ));
    }
}
