<?php
/**
 * PluginPrintgestionAgenttoken — jetons temporaires de récupération de l'installeur GLPI Agent.
 *
 * Un seul fichier ne peut pas emporter l'installeur officiel (22 Mo dans un script se ferait refuser par les
 * antivirus, et un exécutable fabriqué ici ne serait pas signé). Le fichier unique vient donc le chercher sur ce
 * serveur GLPI, et pour cela il lui faut une clé — puisque le PC de la sonde n'a aucun compte GLPI.
 *
 * Ce que le jeton ouvre : les fichiers officiels de GLPI Agent d'UN système, déjà vérifiés et servis par ce serveur —
 * des binaires publics publiés par Teclib'. Windows en demande un (le MSI), Linux un (l'installeur Perl), macOS deux
 * (Apple Silicon et Intel) : le script du Mac ne télécharge que celui de sa puce, mais il ne sait laquelle avant
 * d'être lancé. Une clé porte donc la liste de ce qu'elle ouvre, et l'URL dit lequel.
 *
 * Rien d'autre : ni session, ni donnée de GLPI, ni écriture. Elle ne donne donc pas plus que ce que donne github.com
 * à tout le monde ; sa raison d'être est d'éviter d'ouvrir une URL de téléchargement à l'Internet entier, et de
 * laisser une trace de chaque récupération.
 *
 * Ce qui le referme, au plus tôt des deux : une seule récupération, ou vingt-quatre heures. C'est volontairement
 * strict — le fichier est fabriqué au moment où l'on part chez le client, et il ne sert qu'une fois. Un
 * téléchargement interrompu demande donc de régénérer le fichier dans GLPI (un clic) : mieux vaut ce clic qu'un
 * jeton qui traîne, réutilisable, dans un dossier « Téléchargements ».
 *
 * Le jeton n'est jamais stocké en clair : seule son empreinte SHA-256 est gardée, comme un mot de passe. Une copie
 * de la base ne permet donc pas de fabriquer un fichier qui marche.
 *
 * Rangement : configuration GLPI du plugin (glpi_configs, contexte plugin:printgestion), pas de table dédiée — une
 * poignée de lignes qui vivent un jour ne valent pas une étape de schéma. L'écriture se fait par comparaison de
 * l'ancienne valeur (UPDATE ... WHERE value = <valeur lue>) : deux requêtes simultanées ne peuvent pas consommer le
 * même jeton, la seconde perd le pari et repart d'une lecture fraîche.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionAgenttoken {

    /** Rangement dans la configuration GLPI du plugin. */
    const CONTEXT = 'plugin:printgestion';
    const KEY     = 'agent_pull_tokens';

    /** Durée de vie d'un jeton : le temps d'une intervention, jamais plus. */
    const TTL = DAY_TIMESTAMP;

    /**
     * Les deux usages, jamais interchangeables : une clé de rapport ne télécharge rien, une clé de téléchargement ne
     * déclare rien. Le fichier unique en emporte une de chaque.
     */
    const USAGE_PULL   = 'pull';
    const USAGE_REPORT = 'report';
    /** Suivi de la découverte, le temps d'une installation : plusieurs lectures, et rien d'autre. */
    const USAGE_PROGRESS = 'progress';
    /**
     * Durée de vie de la clé de suivi : le temps d'une découverte, pas celui d'une journée. Un quart d'heure, soit
     * plus que la surveillance elle-même (dix minutes) : une clé qui expire avant la fin de la surveillance fait
     * échouer les derniers appels, ceux-là mêmes qui rapportent enfin quelque chose.
     */
    const PROGRESS_TTL = 900;

    /** Longueur du jeton en caractères hexadécimaux (24 octets tirés au hasard). */
    const LENGTH = 48;

    /**
     * Garde-fou : au-delà, les plus anciens jetons encore valides sont oubliés. Trente interventions ouvertes en
     * même temps n'arrivent pas ; une boucle qui régénère le fichier, si. La valeur borne la ligne de configuration.
     */
    const MAX_KEPT = 60;

    /** Nombre de tentatives d'écriture avant d'abandonner : une perte de pari se rejoue, elle ne se subit pas. */
    const WRITE_TRIES = 3;

    /**
     * URL de récupération (route sans session, déclarée dans plugin_printgestion_boot()). $asset : le fichier voulu
     * parmi ceux que la clé ouvre ; inutile quand elle n'en ouvre qu'un.
     */
    public static function getPullURL(string $token, string $asset = ''): string {
        // getServerUrl() rend l'URL terminée par « / » (propriété SERVER de l'agent) : une seule barre ici.
        return rtrim(PluginPrintgestionAgentdeploy::getServerUrl()['url'], '/')
            . '/plugins/printgestion/front/agentpull.php?t=' . rawurlencode($token)
            . ($asset !== '' ? '&a=' . rawurlencode($asset) : '');
    }

    /** URL du compte rendu d'installation ; l'appelant y ajoute « &maj= » et « &pc= ». */
    public static function getReportURL(string $token): string {
        return rtrim(PluginPrintgestionAgentdeploy::getServerUrl()['url'], '/')
            . '/plugins/printgestion/front/agentreport.php?t=' . rawurlencode($token);
    }

    /**
     * Crée un jeton pour les fichiers officiels d'un système et le renvoie en clair — la seule fois où il existe en
     * clair, le temps d'être écrit dans le fichier unique. La base ne garde que son empreinte.
     *
     * @param array $installers Retours de PluginPrintgestionAgentdeploy::getCachedInstaller(true), par clé de
     *                          fichier officiel : version et empreinte de chacun, et rien d'autre.
     *
     * @return ?string Le jeton, ou null si la configuration n'a pas pu être écrite (rien n'est alors promis).
     */
    public static function create(int $entities_id, array $installers): ?string {
        $assets = [];
        foreach ($installers as $asset => $installer) {
            $assets[(string) $asset] = [
                'version' => (string) ($installer['version'] ?? ''),
                'sha256'  => (string) ($installer['sha256'] ?? ''),
            ];
        }
        if ($assets === []) {
            return null;
        }
        return self::add($entities_id, self::USAGE_PULL, [
            'assets'  => $assets,
            'version' => (string) reset($assets)['version'],
        ]);
    }

    /** Adresse du suivi de la découverte, donnée au PC dans la réponse au compte rendu. */
    public static function getProgressURL(string $token): string {
        return rtrim(PluginPrintgestionAgentdeploy::getServerUrl()['url'], '/')
            . '/plugins/printgestion/front/agentprogress.php?t=' . rawurlencode($token);
    }

    /**
     * Clé de compte rendu : elle n'ouvre rien, elle permet seulement au fichier unique de dire à GLPI, une fois
     * l'installation finie, si la tâche de mise à jour a été posée sur ce PC.
     *
     * Sans elle, l'écran de la sonde affiche un réglage souhaité et personne ne sait ce que le PC a réellement reçu.
     */
    public static function createReport(int $entities_id, string $tag, string $platform, bool $purge = false): ?string {
        // La ligne qui recevra le compte rendu est créée maintenant, pendant qu'une session est ouverte : le PC, lui,
        // rendra compte sans session et ne doit emprunter que l'écriture directe.
        PluginPrintgestionAgentreport::ensureStore();
        // « purge » : celui qui génère un fichier de retrait a le droit de supprimer une sonde. C'est lui qui décide,
        // ici, pendant que sa session est ouverte ; le PC ne fait que cocher une case que ce droit a rendue possible.
        return self::add($entities_id, self::USAGE_REPORT, ['tag' => $tag, 'platform' => $platform, 'purge' => $purge]);
    }

    /**
     * Clé de suivi : elle ne sait dire qu'une chose, combien d'imprimantes la découverte a trouvées et leurs noms.
     *
     * Elle se lit plusieurs fois — la fenêtre d'installation interroge toutes les dix secondes — mais un quart
     * d'heure seulement, et pour ce PC-là dans cette entité-là. Elle n'ouvre rien, ne modifie rien qui ne serait déjà fait
     * par l'écran du raccordement, et disparaît avec l'installation.
     *
     * $ips : les adresses tapées dans la fenêtre. Elles ne servent qu'à reconnaître les imprimantes de ce chantier
     * en scan local — et jamais à écrire quoi que ce soit. $local : le scan est fait par la ToolBox de l'agent,
     * donc rien à attendre d'un raccordement, même s'il en traîne un d'une installation précédente.
     */
    public static function createProgress(int $entities_id, string $computer, string $ips = '', bool $local = false): ?string {
        // Les adresses saisies par le technicien voyagent avec la clé : sans GLPI Inventory, il n'y a pas de
        // raccordement à relire, et c'est à elles qu'on reconnaît les imprimantes de ce chantier.
        // Le mode aussi : il est décidé à l'installation, et ne se devine pas côté serveur. Un poste déjà utilisé
        // en mode piloté garde son raccordement dans GLPI ; le suivi le retrouvait et lisait sa table d'adresses,
        // que personne ne remplit en local — six minutes d'attente pour rien.
        return self::add($entities_id, self::USAGE_PROGRESS, [
            'pc'    => $computer,
            'ips'   => $ips,
            'local' => $local,
        ], self::PROGRESS_TTL);
    }

    /**
     * Vérifie une clé sans la consommer : le suivi la relit à chaque appel.
     *
     * @return ?array L'entrée, ou null : inconnue, expirée, ou faite pour un autre usage.
     */
    public static function peek(string $token, string $usage): ?array {
        if (preg_match('/^[a-f0-9]{' . self::LENGTH . '}$/', $token) !== 1) {
            return null;
        }
        $hash = hash('sha256', $token);
        foreach (self::prune(self::read()['list']) as $entry) {
            if (hash_equals((string) $entry['hash'], $hash) && (string) ($entry['usage'] ?? '') === $usage) {
                return $entry;
            }
        }
        return null;
    }

    /** Écrit une clé, quel que soit son usage, et la renvoie en clair — la seule fois où elle existe en clair. */
    private static function add(int $entities_id, string $usage, array $extra, ?int $ttl = null): ?string {
        $token   = bin2hex(random_bytes((int) (self::LENGTH / 2)));
        $now     = time();
        $entry   = $extra + [
            'hash'        => hash('sha256', $token),
            'entities_id' => $entities_id,
            'usage'       => $usage,
            'expires'     => $now + ($ttl ?? self::TTL),
            'created_at'  => $now,
            'users_id'    => (int) Session::getLoginUserID(),
        ];
        for ($try = 0; $try < self::WRITE_TRIES; $try++) {
            $state = self::read();
            $list  = self::prune($state['list']);
            $list[] = $entry;
            if (count($list) > self::MAX_KEPT) {
                $list = array_slice($list, -self::MAX_KEPT);
            }
            if (self::write($state, $list)) {
                return $token;
            }
        }
        PluginPrintgestionLogger::error('agenttoken', 'Jeton de récupération non enregistré : configuration non écrite.');
        return null;
    }

    /**
     * Vérifie un jeton et le consomme dans le même geste : il ne pourra plus servir, que la suite réussisse ou non.
     *
     * Consommer avant de servir, et non après : un jeton qui ne se referme qu'une fois le fichier entièrement
     * envoyé se rejoue en coupant la connexion. Le prix est connu et assumé — un téléchargement interrompu se
     * refait en régénérant le fichier dans GLPI.
     *
     * @param string $usage L'usage exigé : une clé faite pour l'un ne sert jamais pour l'autre.
     *
     * @return ?array L'entrée consommée (version, empreinte, entité), ou null : inconnu, expiré, déjà servi.
     */
    public static function consume(string $token, string $usage = self::USAGE_PULL): ?array {
        if (preg_match('/^[a-f0-9]{' . self::LENGTH . '}$/', $token) !== 1) {
            return null;
        }
        $hash = hash('sha256', $token);
        for ($try = 0; $try < self::WRITE_TRIES; $try++) {
            $state = self::read();
            $list  = self::prune($state['list']);
            $found = null;
            $kept  = [];
            foreach ($list as $entry) {
                if ($found === null && hash_equals((string) ($entry['hash'] ?? ''), $hash)) {
                    $found = $entry;
                    continue; // usage unique : l'entrée disparaît de la liste écrite
                }
                $kept[] = $entry;
            }
            if ($found === null) {
                return null;
            }
            // Mauvais usage : la clé existe, mais pas pour ça. Elle n'est pas consommée pour autant — ce serait
            // offrir à n'importe qui le moyen de brûler la clé d'une intervention en cours.
            if ((string) ($found['usage'] ?? self::USAGE_PULL) !== $usage) {
                return null;
            }
            if (self::write($state, $kept)) {
                return $found;
            }
        }
        // Écriture perdue trois fois : refuser plutôt que servir un jeton qu'on n'a pas pu refermer.
        PluginPrintgestionLogger::warning('agenttoken', 'Jeton valide refusé : consommation non écrite en base.');
        return null;
    }

    /**
     * Clés de téléchargement encore valides, pour l'affichage (une entité, ou toutes avec 0). Les clés de compte
     * rendu ne sont pas comptées : elles n'ouvrent rien, les montrer ferait du bruit.
     */
    public static function countActive(int $entities_id = 0): int {
        $list = array_filter(
            self::prune(self::read()['list']),
            static fn(array $e) => (string) ($e['usage'] ?? self::USAGE_PULL) === self::USAGE_PULL
        );
        if ($entities_id === 0) {
            return count($list);
        }
        return count(array_filter($list, static fn(array $e) => (int) ($e['entities_id'] ?? -1) === $entities_id));
    }

    // ── Rangement d'une liste courte dans la configuration ────────────────────

    /**
     * Lecture d'une liste rangée dans la configuration du plugin, et écriture avec pari sur l'ancienne valeur.
     * Partagé avec les comptes rendus d'installation (PluginPrintgestionAgentreport) : deux copies de ce code
     * auraient fini par diverger sur le détail qui compte — le pari.
     *
     * @return array ['raw' => ?string (null : la ligne n'existe pas encore), 'list' => array]
     */
    public static function readList(string $key): array {
        $values = Config::getConfigurationValues(self::CONTEXT, [$key]);
        if (!array_key_exists($key, $values)) {
            return ['raw' => null, 'list' => []];
        }
        $raw  = (string) $values[$key];
        $list = json_decode((string) base64_decode($raw, true), true);
        return ['raw' => $raw, 'list' => is_array($list) ? $list : []];
    }

    /**
     * Écrit la liste, mais seulement si personne ne l'a touchée depuis la lecture.
     *
     * En base64, et non en JSON lisible : la valeur passe par Config::add()/update(), donc par CommonDBTM, puis par
     * une écriture directe au tour suivant. Un guillemet échappé par l'un et pas par l'autre rendrait la liste
     * illisible — et la panne serait silencieuse. Sur un alphabet de 64 caractères, aucune des deux écritures n'a
     * rien à échapper.
     */
    public static function writeList(string $key, array $state, array $list): bool {
        global $DB;

        $json = json_encode(array_values($list));
        if ($json === false) {
            return false;
        }
        $raw = base64_encode($json);
        if ($state['raw'] === null) {
            // Première écriture : la ligne n'existe pas encore. Config la crée, ou la met à jour si un autre
            // l'a créée entre-temps — le tour suivant de la boucle appelante repartira alors d'une lecture fraîche.
            Config::setConfigurationValues(self::CONTEXT, [$key => $raw]);
            return self::readList($key)['raw'] === $raw;
        }
        $DB->update(
            Config::getTable(),
            ['value' => $raw],
            ['context' => self::CONTEXT, 'name' => $key, 'value' => $state['raw']]
        );
        return $DB->affectedRows() === 1;
    }

    /** Retire les jetons expirés : appelé à chaque lecture utile, personne n'a à balayer. */
    private static function prune(array $list): array {
        $now = time();
        return array_values(array_filter(
            $list,
            static fn($e) => is_array($e) && isset($e['hash'], $e['expires']) && (int) $e['expires'] > $now
        ));
    }

    /** Les clés sont rangées comme les comptes rendus : même lecture, même pari à l'écriture. */
    private static function read(): array {
        return self::readList(self::KEY);
    }

    private static function write(array $state, array $list): bool {
        return self::writeList(self::KEY, $state, $list);
    }
}
