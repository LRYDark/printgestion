<?php
/**
 * PluginPrintgestionAgentdeploy — module « Déploiement Agent », phase 1 : onglet « Déploiement Agent »
 * de la fiche Entité et installeur GLPI Agent pré-paramétré pour Windows.
 *
 * Le paquet téléchargé par le technicien ne contient que l'URL du serveur GLPI et le TAG de
 * l'entité : aucun identifiant, aucun jeton, aucun secret. Tout le reste se configure dans GLPI.
 *
 * Réutilise le natif sans le réafficher : champ TAG de l'entité, règle d'affectation d'entité
 * « Entity from TAG » (vérifiée ; créée seulement sur clic explicite d'un administrateur, une seule pour tous les
 * clients), fiche Agent (lien seulement).
 * Installeur : MSI officiel, récupéré par le serveur sur GitHub avec vérification de l'empreinte
 * SHA-256 publiée, ou déposé à la main puis vérifié ; toujours servi par le plugin, jamais par
 * un lien direct vers GitHub, qui peut être bloqué chez le client.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionAgentdeploy extends CommonGLPI {

    static $rightname = 'plugin_printgestion_deploiement';

    /** Dépôt officiel de GLPI Agent. */
    const REPOSITORY = 'glpi-project/glpi-agent';
    /** Dernière version publiée vérifiée (release GitHub du 24 septembre 2026) ; épinglable dans les réglages. */
    const DEFAULT_VERSION = '1.20';
    /** Inventaire du poste + découverte et inventaire réseau : seul l'inventaire est installé par défaut depuis l'agent 1.8. */
    const ADDLOCAL = 'feat_AGENT,feat_NETINV';
    /** Réessais SNMP (0 par défaut : un paquet perdu fait disparaître les consommables d'un relevé). */
    const SNMP_RETRIES = 2;
    /** Interface locale de l'agent ouverte au poste lui-même (valeur par défaut du MSI), toujours conservée. */
    const LOCAL_TRUST = '127.0.0.1/32';
    /** En-tête d'un fichier MSI (format Compound File Binary). */
    const MSI_MAGIC = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    /** Description de l'installeur vérifié, à côté du fichier. */
    const METADATA_FILE = 'installer.json';
    /**
     * Paquet Windows : le seul fichier à lancer. Son nom le dit, et il est le seul exécutable du dossier — tout le
     * reste est une donnée (le MSI, le script de mise à jour) ou de la documentation.
     *
     * Il installe, puis demande si l'on veut la mise à jour automatique : deux décisions, un seul geste. Livrer deux
     * .bat numérotés obligeait celui qui ouvre le dossier à deviner lequel lancer et dans quel ordre.
     */
    const WINDOWS_INSTALL_BAT = 'INSTALLER-GLPI-AGENT.bat';

    /**
     * Sous-dossier du paquet Windows où descendent les données : le MSI officiel, le script de mise à jour et la
     * commande de secours. À la racine il ne reste que le fichier à lancer et le mode d'emploi — un dossier qui
     * montre six éléments dont un seul se lance ne dit pas lequel.
     */
    const WINDOWS_DATA_DIR = 'fichiers/';

    static function getTypeName($nb = 0) {
        return __('Déploiement Agent', 'printgestion');
    }

    // ── Onglet de la fiche Entité ─────────────────────────────────────────────

    /**
     * Icône de l'onglet. Onglet « Déploiement Agent » de la fiche Entité : on y télécharge l'installeur de la sonde.
     *
     * Sans cette méthode, GLPI retombe sur l'icône par défaut de CommonDBTM, qui vaut « fa-empty-icon » et
     * que createTabEntry() remplace alors par rien : le libellé reste nu à côté des onglets natifs.
     */
    static function getIcon() {
        return 'ti ti-cloud-download';
    }

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item instanceof Entity
            && PluginPrintgestionConfig::isFeatureEnabled('deploiement')
            && Session::haveRight(self::$rightname, READ)) {
            $nb = countElementsInTable(Agent::getTable(), ['entities_id' => (int) $item->getID()]);
            return self::createTabEntry(self::getTypeName(), $nb);
        }
        return '';
    }

    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        // Contenu joignable par l'URL de l'onglet : module, droit et accès à l'entité revérifiés.
        if (!$item instanceof Entity
            || !PluginPrintgestionConfig::isFeatureEnabled('deploiement')
            || !Session::haveRight(self::$rightname, READ)
            || !Session::haveAccessToEntity((int) $item->getID())) {
            return false;
        }
        self::showForEntity($item);
        return true;
    }

    // ── Réglages ──────────────────────────────────────────────────────────────

    public static function getPageURL(): string {
        return PLUGIN_PRINTGESTION_WEBDIR . '/front/agentdeploy.php';
    }

    public static function getDownloadURL(int $entities_id, string $os): string {
        return PLUGIN_PRINTGESTION_WEBDIR . '/front/agentdeploy.download.php?'
            . http_build_query(['entities_id' => $entities_id, 'os' => $os]);
    }

    /** Version servie : épinglée dans les réglages, sinon la dernière version vérifiée. */
    public static function getServedVersion(): string {
        $version = trim((string) (PluginPrintgestionConfig::getInstance()->fields['agent_version'] ?? ''));
        return $version !== '' ? $version : self::DEFAULT_VERSION;
    }

    public static function getMsiName(string $version): string {
        return sprintf('GLPI-Agent-%s-x64.msi', $version);
    }

    /** Systèmes servis : clé => libellé. */
    public static function getPlatforms(): array {
        return ['windows' => __('Windows', 'printgestion'), 'linux' => __('Linux', 'printgestion'), 'macos' => __('macOS', 'printgestion')];
    }

    /** Fichiers officiels nécessaires au paquet de chaque système. */
    const PLATFORM_ASSETS = [
        'windows' => ['windows'],
        'linux'   => ['linux'],
        'macos'   => ['macos-arm64', 'macos-x86_64'],
    ];

    /**
     * Fichiers officiels de GLPI Agent servis par le plugin : nom dans la release, début de fichier attendu,
     * description gardée à côté, motif des fichiers d'autres versions à retirer, libellé.
     */
    public static function getAssets(?string $version = null): array {
        $version ??= self::getServedVersion();
        return [
            'windows'      => ['file' => self::getMsiName($version), 'magic' => self::MSI_MAGIC, 'meta' => self::METADATA_FILE, 'glob' => 'GLPI-Agent-*-x64.msi', 'label' => __('Windows (MSI)', 'printgestion')],
            'linux'        => ['file' => sprintf('glpi-agent-%s-linux-installer.pl', $version), 'magic' => '#!', 'meta' => 'installer-linux.json', 'glob' => 'glpi-agent-*-linux-installer.pl', 'label' => __('Linux (installeur Perl)', 'printgestion')],
            'macos-arm64'  => ['file' => sprintf('GLPI-Agent-%s_arm64.pkg', $version), 'magic' => 'xar!', 'meta' => 'installer-macos-arm64.json', 'glob' => 'GLPI-Agent-*_arm64.pkg', 'label' => __('macOS Apple Silicon (pkg)', 'printgestion')],
            'macos-x86_64' => ['file' => sprintf('GLPI-Agent-%s_x86_64.pkg', $version), 'magic' => 'xar!', 'meta' => 'installer-macos-x86_64.json', 'glob' => 'GLPI-Agent-*_x86_64.pkg', 'label' => __('macOS Intel (pkg)', 'printgestion')],
        ];
    }

    public static function getCacheDir(): string {
        return GLPI_PLUGIN_DOC_DIR . '/printgestion/agent';
    }

    /**
     * URL du serveur donnée à l'agent : toujours l'URL de l'application GLPI (Configuration → Générale), racine
     * comprise — déduite, jamais réglée dans le plugin (l'ancienne surcharge « URL du serveur » est supprimée en 1.6.8). Jamais le chemin du plugin GLPI Inventory : GLPI 11 reçoit
     * les agents nativement à sa racine (CatchInventoryAgentRequestListener) et GLPI Inventory y greffe ses
     * tâches réseau par hooks ; « /plugins/glpiinventory/ » ne marchait que par une route de compatibilité du
     * plugin, qui disparaît avec lui (plugin désactivé ou nettoyé : 404 sur chaque agent, irréparable à
     * distance). Vérifié avec GLPI Agent 1.19 contre GLPI 11.0.8 : les deux valeurs répondent, seule la racine
     * ne dépend de rien.
     *
     * @return array ['url' => string, 'source' => 'glpi', 'error' => string]
     */
    public static function getServerUrl(): array {
        global $CFG_GLPI;

        $base = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/');
        if ($base === '') {
            return [
                'url'    => '',
                'source' => 'glpi',
                'error'  => __('URL de GLPI non renseignée (Configuration → Générale → URL de l\'application) : l\'agent ne saurait pas où envoyer ses inventaires.', 'printgestion'),
            ];
        }
        return ['url' => $base . '/', 'source' => 'glpi', 'error' => ''];
    }

    /** Adresses autorisées sur l'interface de l'agent : le poste lui-même, plus les adresses réglées. */
    /**
     * Défaut de l'URL de l'application GLPI (Configuration → Générale) qui rendrait tout agent déployé incapable de
     * joindre GLPI, sans réparation à distance ; chaîne vide si rien à redire. Ce qui se vérifie d'ici : absolue
     * avec un schéma, ni locale (localhost, 127.0.0.0/8, ::1, 0.0.0.0), ni nom sans domaine qui ne se résout pas.
     * Qu'elle soit joignable depuis le réseau d'un client, seul un agent qui remonte le prouve (carte Santé).
     */
    public static function getApplicationUrlIssue(): string {
        global $CFG_GLPI;

        $url = trim((string) ($CFG_GLPI['url_base'] ?? ''));
        if ($url === '') {
            return __('URL de l\'application vide (Configuration → Générale) : un agent ne saurait pas où envoyer ses inventaires.', 'printgestion');
        }
        $parts  = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return sprintf(__('URL de l\'application « %s » sans https:// (ou http://) ni nom de serveur : un agent ne peut pas l\'utiliser.', 'printgestion'), $url);
        }
        $ip = filter_var($host, FILTER_VALIDATE_IP);
        if ($host === 'localhost' || $host === '::1' || $host === '0.0.0.0' || ($ip !== false && str_starts_with($host, '127.'))) {
            return sprintf(__('URL de l\'application locale (« %s ») : un agent chez un client ne joindrait jamais GLPI.', 'printgestion'), $url);
        }
        if ($ip === false && !str_contains($host, '.') && gethostbyname($host) === $host) {
            return sprintf(__('URL de l\'application avec un nom sans domaine qui ne se résout pas (« %s ») : un agent chez un client ne le trouverait pas.', 'printgestion'), $url);
        }
        return '';
    }

    public static function getHttpdTrust(): string {
        $extra = (string) preg_replace('/\s+/', '', (string) (PluginPrintgestionConfig::getInstance()->fields['agent_httpd_trust'] ?? ''));
        return $extra !== '' ? self::LOCAL_TRUST . ',' . $extra : self::LOCAL_TRUST;
    }

    /** URL sûre dans une commande cmd : schéma, hôte, port, chemin simple ; ni espace, ni guillemet, ni % ^ & | < >. */
    public static function isValidServerUrl(string $url): bool {
        return (bool) preg_match('#^https?://[A-Za-z0-9.-]+(:\d{1,5})?(/[A-Za-z0-9._~/-]*)?$#', $url);
    }

    /** Liste d'adresses IPv4 ou de plages CIDR séparées par des virgules. */
    public static function isValidTrustList(string $list): bool {
        foreach (explode(',', $list) as $entry) {
            if (!preg_match('#^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})(?:/(\d{1,2}))?$#', $entry, $m)) {
                return false;
            }
            if (max((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]) > 255 || (isset($m[5]) && (int) $m[5] > 32)) {
                return false;
            }
        }
        return true;
    }

    /** TAG sûr dans une commande cmd : lettres, chiffres, point, tiret, soulignement. */
    public static function isValidTag(string $tag): bool {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $tag);
    }

    /**
     * Enregistre les réglages de l'installeur (valeurs vides : automatiques).
     *
     * @return string[] Erreurs ; vide si enregistré.
     */
    public static function saveSettings(array $input): array {
        $version = trim((string) ($input['agent_version'] ?? ''));
        $trust   = (string) preg_replace('/\s+/', '', (string) ($input['agent_httpd_trust'] ?? ''));

        $errors = [];
        if ($version !== '' && !preg_match('/^\d+\.\d+(\.\d+)?$/', $version)) {
            $errors[] = __('Version invalide (exemple : 1.20).', 'printgestion');
        }
        if ($trust !== '' && !self::isValidTrustList($trust)) {
            $errors[] = __('Adresses autorisées invalides : adresses IPv4 ou plages CIDR séparées par des virgules.', 'printgestion');
        }
        if (!empty($errors)) {
            return $errors;
        }

        // Valeur vide : automatique.
        $config = PluginPrintgestionConfig::getInstance();
        if (!$config->update([
            'id'                => (int) $config->getID(),
            'agent_version'     => $version,
            'agent_httpd_trust' => $trust,
        ])) {
            return [__('Paramètres de l\'installeur non enregistrés.', 'printgestion')];
        }
        // Les réglages des prochains paquets sont dans le même formulaire : un seul bouton, un seul enregistrement.
        return PluginPrintgestionAgentsetting::saveDefaults($input);
    }

    // ── Installeur en cache ───────────────────────────────────────────────────

    /**
     * Fichier officiel vérifié de la version servie ($asset : clé de getAssets()), ou null. $verify : empreinte
     * comparée à celle de la vérification (avant de servir un paquet), recalculée si le fichier a changé sur le
     * disque depuis le dernier calcul (getInstallerHash) ; sinon contrôle de la taille seulement (affichage).
     *
     * @return ?array ['path', 'file', 'version', 'size', 'sha256', 'source', 'date', 'users_id']
     */
    public static function getCachedInstaller(bool $verify = false, string $asset = 'windows'): ?array {
        $version = self::getServedVersion();
        $spec    = self::getAssets($version)[$asset] ?? null;
        if ($spec === null) {
            return null;
        }
        $meta = self::readMetadata($spec['meta']);
        $path = self::getCacheDir() . '/' . $spec['file'];
        if ($meta === null || ($meta['version'] ?? '') !== $version || ($meta['file'] ?? '') !== $spec['file'] || !is_file($path)
            || (int) ($meta['size'] ?? -1) !== (int) filesize($path)) {
            return null;
        }
        if ($verify && !hash_equals((string) ($meta['sha256'] ?? ''), self::getInstallerHash($path, $spec['meta']))) {
            PluginPrintgestionLogger::error('agentdeploy', sprintf('Installeur %s modifié depuis sa vérification : refusé.', $path));
            return null;
        }
        return $meta + ['path' => $path];
    }

    /** Fichier qui mémorise l'empreinte calculée d'un fichier servi, à côté de sa description. */
    private static function getHashCacheFile(string $meta_file): string {
        return self::getCacheDir() . '/' . $meta_file . '.sha256';
    }

    /**
     * Empreinte SHA-256 d'un fichier servi, recalculée seulement si le fichier a changé sur le disque depuis le
     * dernier calcul : chemin, taille, date de modification et date de changement (ctime) sont mémorisés avec elle
     * (fichier <description>.sha256), et le moindre écart relance hash_file().
     *
     * Pourquoi l'empreinte avant de servir : un fichier abîmé sur le disque, ou remplacé dans le dossier depuis sa
     * vérification (dépôt à la main, copie maladroite, altération), ne doit jamais partir chez un client. Le recalcul
     * complet relisait pourtant des dizaines de Mo (MSI, deux paquets macOS) à chaque paquet et à chaque récupération
     * par clé. Le compromis : toute réécriture, copie ou remplacement change la taille ou les dates — et sous Linux le
     * ctime ne se remet pas à la main, touch ne le peut pas —, l'empreinte est alors recalculée et comparée comme
     * avant. Ce qui échappe désormais jusqu'au prochain changement : une corruption silencieuse du disque qui ne
     * touche pas aux métadonnées. Qui peut réécrire le fichier en maquillant ses dates peut aussi réécrire sa
     * description, empreinte comprise : le recalcul systématique ne protégeait pas davantage contre lui. Les
     * contrôles forts restent entiers : installCandidate() recalcule toujours au dépôt, et le fichier unique vérifie
     * l'empreinte attendue sur le poste après téléchargement.
     *
     * Chaîne vide si le fichier est illisible : refusée par l'appelant, comme avant.
     */
    private static function getInstallerHash(string $path, string $meta_file): string {
        $state = static function () use ($path): ?array {
            clearstatcache(true, $path);
            $stat = @stat($path);
            return $stat === false ? null : [
                'path'  => $path,
                'size'  => (int) $stat['size'],
                'mtime' => (int) $stat['mtime'],
                'ctime' => (int) $stat['ctime'],
            ];
        };
        $before = $state();
        if ($before === null) {
            return '';
        }
        $file   = self::getHashCacheFile($meta_file);
        $cached = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (is_array($cached) && preg_match('/^[0-9a-f]{64}$/', (string) ($cached['sha256'] ?? ''))
            && ($cached['path'] ?? null) === $before['path'] && ($cached['size'] ?? null) === $before['size']
            && ($cached['mtime'] ?? null) === $before['mtime'] && ($cached['ctime'] ?? null) === $before['ctime']) {
            return (string) $cached['sha256'];
        }

        $started = microtime(true);
        $hash    = (string) hash_file('sha256', $path);
        PluginPrintgestionLogger::duration('agentdeploy', 'Empreinte SHA-256 recalculée', $started, sprintf('%s, %d octets', basename($path), $before['size']));
        // Mémorisée seulement si le fichier n'a pas bougé pendant le calcul ; écrite à part puis renommée, pour
        // qu'une lecture simultanée ne voie jamais un fichier à moitié écrit (elle recalculerait, sans plus).
        if ($hash !== '' && $state() === $before) {
            $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (file_put_contents($tmp, json_encode($before + ['sha256' => $hash], JSON_UNESCAPED_SLASHES)) === false || !rename($tmp, $file)) {
                if (is_file($tmp)) {
                    unlink($tmp);
                }
                PluginPrintgestionLogger::warning('agentdeploy', sprintf('Empreinte de %s non mémorisée dans %s : recalculée au prochain paquet.', $path, $file));
            }
        }
        return $hash;
    }

    private static function readMetadata(string $meta_file): ?array {
        $file = self::getCacheDir() . '/' . $meta_file;
        if (!is_file($file)) {
            return null;
        }
        $meta = json_decode((string) file_get_contents($file), true);
        return is_array($meta) ? $meta : null;
    }

    /**
     * Récupère un fichier officiel de la version servie sur GitHub (MSI, installeur Linux, paquet macOS), par le
     * serveur GLPI (proxy GLPI respecté), et ne le garde que si son empreinte est celle publiée par GitHub.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public static function fetchFromGitHub(string $asset_key = 'windows'): array {
        $version = self::getServedVersion();
        $spec    = self::getAssets($version)[$asset_key] ?? null;
        if ($spec === null) {
            return ['ok' => false, 'message' => __('Fichier d\'installation inconnu.', 'printgestion')];
        }
        $name    = $spec['file'];
        $client  = Toolbox::getGuzzleClient(['timeout' => 600, 'connect_timeout' => 20]);
        $headers = ['User-Agent' => 'GLPI-printgestion', 'Accept' => 'application/vnd.github+json'];

        try {
            $response = $client->request('GET', sprintf('https://api.github.com/repos/%s/releases/tags/%s', self::REPOSITORY, rawurlencode($version)), [
                'headers' => $headers,
            ]);
            $release = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            PluginPrintgestionLogger::error('agentdeploy', sprintf('Release GLPI Agent %s non lue sur GitHub.', $version), $e);
            return ['ok' => false, 'message' => sprintf(
                __('GitHub injoignable depuis le serveur GLPI, ou version %s inconnue (détail dans le journal printgestion). Sans accès Internet : déposez le fichier à la main.', 'printgestion'),
                $version
            )];
        }

        $asset = null;
        foreach ((array) ($release['assets'] ?? []) as $candidate) {
            if (is_array($candidate) && ($candidate['name'] ?? '') === $name) {
                $asset = $candidate;
                break;
            }
        }
        if ($asset === null) {
            return ['ok' => false, 'message' => sprintf(__('La version %1$s de GLPI Agent ne publie pas de fichier %2$s.', 'printgestion'), $version, $name)];
        }
        if (!preg_match('/^sha256:([0-9a-f]{64})$/', (string) ($asset['digest'] ?? ''), $digest)) {
            return ['ok' => false, 'message' => sprintf(
                __('GitHub ne publie pas d\'empreinte SHA-256 pour %1$s : déposez le fichier à la main avec l\'empreinte du fichier glpi-agent-%2$s.sha256 de la release.', 'printgestion'),
                $name,
                $version
            )];
        }
        $url = (string) ($asset['browser_download_url'] ?? '');
        if (!str_starts_with($url, sprintf('https://github.com/%s/releases/download/', self::REPOSITORY))) {
            PluginPrintgestionLogger::error('agentdeploy', sprintf('Adresse de téléchargement inattendue pour %s : %s', $name, $url));
            return ['ok' => false, 'message' => __('Adresse de téléchargement inattendue : fichier non récupéré (détail dans le journal printgestion).', 'printgestion')];
        }

        $dir = self::getCacheDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            PluginPrintgestionLogger::error('agentdeploy', sprintf('Dossier %s non créé.', $dir));
            return ['ok' => false, 'message' => __('Dossier de l\'installeur non créé sur le serveur (détail dans le journal printgestion).', 'printgestion')];
        }
        $part = $dir . '/' . $name . '.part';
        try {
            $client->request('GET', $url, ['headers' => ['User-Agent' => 'GLPI-printgestion'], 'sink' => $part]);
        } catch (Throwable $e) {
            if (is_file($part)) {
                unlink($part);
            }
            PluginPrintgestionLogger::error('agentdeploy', sprintf('Téléchargement de %s interrompu.', $url), $e);
            return ['ok' => false, 'message' => __('Téléchargement depuis GitHub interrompu (détail dans le journal printgestion).', 'printgestion')];
        }

        return self::installCandidate($part, $version, $digest[1], 'github', true, $asset_key);
    }

    /**
     * Vérifie un fichier officiel déposé à la main dans le dossier de l'installeur (serveur sans accès Internet),
     * contre l'empreinte SHA-256 publiée avec la release. Un fichier refusé n'est pas supprimé.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public static function verifyDeposited(string $expected_sha256, string $asset = 'windows'): array {
        $expected = strtolower(trim($expected_sha256));
        if (!preg_match('/^[0-9a-f]{64}$/', $expected)) {
            return ['ok' => false, 'message' => __('Empreinte SHA-256 invalide : 64 caractères hexadécimaux.', 'printgestion')];
        }
        $version = self::getServedVersion();
        $spec    = self::getAssets($version)[$asset] ?? null;
        if ($spec === null) {
            return ['ok' => false, 'message' => __('Fichier d\'installation inconnu.', 'printgestion')];
        }
        $path = self::getCacheDir() . '/' . $spec['file'];
        if (!is_file($path)) {
            return ['ok' => false, 'message' => sprintf(__('Fichier %1$s absent du dossier %2$s.', 'printgestion'), $spec['file'], self::getCacheDir())];
        }
        return self::installCandidate($path, $version, $expected, 'depot', false, $asset);
    }

    /** Contrôle un fichier candidat (début de fichier, empreinte) puis l'enregistre comme fichier servi. */
    private static function installCandidate(string $path, string $version, string $sha256, string $source, bool $delete_if_refused, string $asset = 'windows'): array {
        $spec   = self::getAssets($version)[$asset];
        $refuse = static function (string $message) use ($path, $delete_if_refused): array {
            if ($delete_if_refused && is_file($path)) {
                unlink($path);
            }
            return ['ok' => false, 'message' => $message];
        };

        $handle = fopen($path, 'rb');
        $magic  = $handle !== false ? (string) fread($handle, 8) : '';
        if ($handle !== false) {
            fclose($handle);
        }
        if (!str_starts_with($magic, $spec['magic'])) {
            return $refuse(sprintf(__('Le fichier n\'est pas un fichier %s : refusé.', 'printgestion'), $spec['label']));
        }
        $actual = (string) hash_file('sha256', $path);
        if (!hash_equals($sha256, $actual)) {
            PluginPrintgestionLogger::warning('agentdeploy', sprintf('Empreinte de %s : %s au lieu de %s, fichier refusé.', $path, $actual, $sha256));
            return $refuse(__('Empreinte SHA-256 différente de celle publiée : fichier corrompu ou modifié, refusé.', 'printgestion'));
        }

        $dir   = self::getCacheDir();
        $final = $dir . '/' . $spec['file'];
        if ($path !== $final && !rename($path, $final)) {
            PluginPrintgestionLogger::error('agentdeploy', sprintf('Installeur %s non déplacé vers %s.', $path, $final));
            return $refuse(__('Installeur vérifié mais non enregistré sur le serveur (détail dans le journal printgestion).', 'printgestion'));
        }
        // Empreinte mémorisée d'un fichier précédent oubliée : le premier paquet servi la recalcule sur ce fichier-ci.
        // Par prudence seulement, la taille et les dates suffiraient à la périmer.
        $hash_cache = self::getHashCacheFile($spec['meta']);
        if (is_file($hash_cache)) {
            @unlink($hash_cache);
        }
        $meta = [
            'file'     => $spec['file'],
            'version'  => $version,
            'size'     => (int) filesize($final),
            'sha256'   => $actual,
            'source'   => $source,
            'date'     => date('Y-m-d H:i:s'),
            'users_id' => (int) Session::getLoginUserID(),
        ];
        if (file_put_contents($dir . '/' . $spec['meta'], json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            PluginPrintgestionLogger::error('agentdeploy', sprintf('Description de l\'installeur non écrite dans %s.', $dir));
            return ['ok' => false, 'message' => __('Installeur vérifié mais sa description n\'a pas pu être écrite (détail dans le journal printgestion).', 'printgestion')];
        }
        // Une seule version servie : les fichiers des autres versions sont retirés.
        foreach (glob($dir . '/' . $spec['glob']) ?: [] as $other) {
            if ($other !== $final && !unlink($other)) {
                PluginPrintgestionLogger::warning('agentdeploy', sprintf('Ancien installeur %s non supprimé.', $other));
            }
        }
        return ['ok' => true, 'message' => sprintf(
            __('%1$s de GLPI Agent %2$s vérifié (SHA-256 %3$s) et prêt à être servi.', 'printgestion'),
            $spec['label'],
            $version,
            $actual
        )];
    }

    // ── Paquet Windows ────────────────────────────────────────────────────────

    /**
     * Motifs qui bloquent le déploiement, parce qu'ils ne se réparent pas sans retourner sur le site : TAG absent,
     * inutilisable ou porté par plusieurs entités ; règle d'affectation par TAG absente ou désactivée (les règles
     * d'entité ne jouent qu'au premier import : une imprimante remontée sans elles reste dans la mauvaise entité) ;
     * URL de l'application vide, locale ou sans schéma (l'agent ne contacterait jamais GLPI, qui ne peut donc rien
     * lui dire). Tout le reste — GLPI Inventory, cron — se répare après coup et n'est qu'un avertissement.
     */
    public static function getDeployBlockers(Entity $entity): array {
        $blockers = [];
        $tag      = trim((string) ($entity->fields['tag'] ?? ''));
        if ($tag === '') {
            $blockers[] = __('TAG de l\'entité vide : sans lui, les équipements découverts par la sonde n\'arrivent pas dans cette entité.', 'printgestion');
        } elseif (!self::isValidTag($tag)) {
            $blockers[] = __('TAG inutilisable dans la commande d\'installation : lettres, chiffres, point, tiret et soulignement uniquement (100 caractères au plus).', 'printgestion');
        } elseif (countElementsInTable(Entity::getTable(), ['tag' => $tag]) > 1) {
            $blockers[] = sprintf(__('TAG « %s » porté par plusieurs entités : GLPI rattacherait les équipements à la première trouvée.', 'printgestion'), $tag);
        }
        $rule = self::getTagRuleStatus();
        if ($rule['active'] === null) {
            $blockers[] = empty($rule['rules'])
                ? __('Aucune règle d\'affectation par TAG : les équipements découverts arriveraient dans l\'entité par défaut.', 'printgestion')
                : __('Règle d\'affectation par TAG désactivée : elle n\'affecte rien, les équipements découverts arriveraient dans l\'entité par défaut.', 'printgestion');
        }
        $url_issue = self::getApplicationUrlIssue();
        if ($url_issue !== '') {
            $blockers[] = $url_issue;
        }
        return $blockers;
    }

    /** Motifs empêchant de produire le paquet d'une entité pour un système ; vide si prêt. */
    public static function getPackageBlockers(Entity $entity, string $platform = 'windows'): array {
        $blockers = self::getDeployBlockers($entity);

        $server = self::getServerUrl();
        if (self::getApplicationUrlIssue() !== '') {
            // Déjà signalée comme blocage du déploiement : rien à ajouter.
        } elseif ($server['error'] !== '') {
            $blockers[] = $server['error'];
        } elseif (!self::isValidServerUrl($server['url'])) {
            $blockers[] = __('URL du serveur donnée à l\'agent invalide (page « Installeur GLPI Agent »).', 'printgestion');
        }
        if (!self::isValidTrustList(self::getHttpdTrust())) {
            $blockers[] = __('Adresses autorisées sur l\'interface de l\'agent invalides (page « Installeur GLPI Agent »).', 'printgestion');
        }
        $assets = self::getAssets();
        foreach (self::PLATFORM_ASSETS[$platform] ?? [] as $asset) {
            if (self::getCachedInstaller(false, $asset) === null) {
                $blockers[] = sprintf(
                    __('%1$s de GLPI Agent %2$s pas encore disponible sur le serveur : page « Installeur GLPI Agent » (droit de configuration du plugin).', 'printgestion'),
                    $assets[$asset]['label'],
                    self::getServedVersion()
                );
            }
        }
        return $blockers;
    }

    /** Propriétés MSI transmises, dans l'ordre de la commande (valeurs contrôlées en amont). */
    public static function getWindowsProperties(string $tag): array {
        return [
            'SERVER'       => self::getServerUrl()['url'],
            'TAG'          => $tag,
            'ADDLOCAL'     => self::ADDLOCAL,
            'HTTPD_TRUST'  => self::getHttpdTrust(),
            'SNMP_RETRIES' => (string) self::SNMP_RETRIES,
            'RUNNOW'       => '1',
            'EXECMODE'     => '1',
            'QUICKINSTALL' => '1',
        ];
    }

    /**
     * Commande msiexec à lancer depuis cmd, jamais PowerShell (documentation de GLPI Agent) :
     * noms de propriétés sensibles à la casse, pas d'espace autour du « = », valeurs entre
     * guillemets.
     */
    public static function buildWindowsCommand(string $msi, string $tag): string {
        return 'msiexec ' . self::buildWindowsArguments($msi, $tag);
    }

    /**
     * Les mêmes arguments sans le nom du programme : le .bat les donne à msiexec, le fichier unique les donne à
     * Start-Process. Une seule écriture, donc les deux chemins installent exactement le même agent réglé pareil.
     */
    public static function buildWindowsArguments(string $msi, string $tag): string {
        $parts = ['/i', '"' . $msi . '"'];
        foreach (self::getWindowsProperties($tag) as $name => $value) {
            $parts[] = $name . '="' . $value . '"';
        }
        // Muet : tout est déjà renseigné, l'assistant n'avait plus rien à demander — il ne donnait qu'une occasion
        // de se tromper. /norestart est le compagnon obligé de /qn : sans lui, l'installeur peut redémarrer le PC
        // de lui-même, pendant que quelqu'un travaille. Le code 3010 dit qu'un redémarrage est attendu ; c'est à
        // nous de le dire, pas à l'installeur de le faire.
        $parts[] = '/qn';
        $parts[] = '/norestart';
        $parts[] = '/l*v "%TEMP%\\GLPI-Agent-install.log"';
        return implode(' ', $parts);
    }

    /** Nom du script de la fenêtre, rangé avec les données : personne n'a à le lancer. */
    const WINDOWS_DIALOG_PS1 = 'fenetre-installation.ps1';

    /** Valeur littérale dans un script PowerShell entre apostrophes : l'apostrophe s'y double. */
    private static function psQuote(string $value): string {
        // PowerShell prend aussi ’ ‘ ‚ ‛ pour des apostrophes : non doublées, un nom de client comme « L’Atelier »
        // fermait la chaîne et rendait tout le fichier illisible, sans un mot. Doublée, chacune reste elle-même.
        return "'" . str_replace(["'", "\u{2018}", "\u{2019}", "\u{201A}", "\u{201B}"], ["''", "\u{2018}\u{2018}", "\u{2019}\u{2019}", "\u{201A}\u{201A}", "\u{201B}\u{201B}"], $value) . "'";
    }

    /**
     * Ce que la fenêtre rappelle avant d'installer : pour qui, avec quel TAG, vers quel serveur.
     *
     * Trois lignes, et pas une de plus : la fenêtre ne demande rien, donc dire « rien à saisir » n'apprend rien à
     * celui qui la regarde — il le voit.
     */
    private static function dialogInfoLines(string $client, string $tag, string $server): array {
        return [
            sprintf(__('Client : %s', 'printgestion'), $client),
            sprintf(__('TAG : %s', 'printgestion'), $tag),
            sprintf(__('Serveur GLPI : %s', 'printgestion'), $server),
        ];
    }

    /**
     * Cadre commun des fenêtres Windows : la fenêtre, son bandeau et la carte « pour qui ».
     *
     * L'installation et le retrait le partagent : même allure, même place pour le nom du client. Laisse $suite sous
     * la carte, pour que l'appelant pose la suite dessous.
     *
     * @param int[] $band couleur du bandeau (rouge, vert, bleu)
     */
    private static function buildWindowsFrameLines(string $caption, string $title, string $subtitle, array $infos, array $band = [31, 58, 95]): array {
        $large     = self::WIN_WIDTH;
        $h_bandeau = self::WIN_BAND;
        $couleur   = static fn(int $r, int $v, int $b) => sprintf('[System.Drawing.Color]::FromArgb(%d, %d, %d)', $r, $v, $b);
        return [
            '$f = New-Object System.Windows.Forms.Form',
            '$f.Text = ' . self::psQuote($caption),
            '$f.ClientSize = New-Object System.Drawing.Size(' . $large . ', ' . ($h_bandeau + 260) . ')',
            '$f.StartPosition = "CenterScreen"',
            '$f.FormBorderStyle = "FixedDialog"',
            '$f.MaximizeBox = $false',
            '$f.MinimizeBox = $false',
            '$f.TopMost = $true',
            '$f.BackColor = [System.Drawing.Color]::White',
            '$f.Font = New-Object System.Drawing.Font("Segoe UI", 9)',
            'try { $f.Icon = [System.Drawing.SystemIcons]::Information } catch { }',
            '',
            '# Bandeau : ce que fait cette fenetre. Le reste est blanc.',
            '$bandeau = New-Object System.Windows.Forms.Panel',
            '$bandeau.Location = New-Object System.Drawing.Point(0, 0)',
            '$bandeau.Size = New-Object System.Drawing.Size(' . $large . ', ' . $h_bandeau . ')',
            '$bandeau.BackColor = ' . $couleur($band[0], $band[1], $band[2]),
            '$f.Controls.Add($bandeau)',
            '',
            '$titre = New-Object System.Windows.Forms.Label',
            '$titre.Text = ' . self::psQuote($title),
            '$titre.Font = New-Object System.Drawing.Font("Segoe UI", 14, [System.Drawing.FontStyle]::Bold)',
            '$titre.ForeColor = [System.Drawing.Color]::White',
            '$titre.BackColor = [System.Drawing.Color]::Transparent',
            '$titre.Location = New-Object System.Drawing.Point(24, 14)',
            '$titre.Size = New-Object System.Drawing.Size(' . ($large - 48) . ', 30)',
            '$bandeau.Controls.Add($titre)',
            '',
            '$sous = New-Object System.Windows.Forms.Label',
            '$sous.Text = ' . self::psQuote($subtitle),
            '$sous.ForeColor = ' . $couleur(210, 220, 235),
            '$sous.BackColor = [System.Drawing.Color]::Transparent',
            '$sous.Location = New-Object System.Drawing.Point(26, 46)',
            '$sous.Size = New-Object System.Drawing.Size(' . ($large - 52) . ', 18)',
            '$bandeau.Controls.Add($sous)',
            '',
            '# Carte : pour qui. Ce sont les seules valeurs que le technicien a besoin de reconnaitre.',
            '$carte = New-Object System.Windows.Forms.Panel',
            '$carte.Location = New-Object System.Drawing.Point(24, ' . ($h_bandeau + 20) . ')',
            '$carte.BackColor = ' . $couleur(244, 246, 249),
            '$f.Controls.Add($carte)',
            '',
            '$infos = New-Object System.Windows.Forms.Label',
            '$infos.Text = ' . self::psQuote(implode("\r\n", $infos)),
            '$infos.BackColor = [System.Drawing.Color]::Transparent',
            '$infos.MaximumSize = New-Object System.Drawing.Size(' . ($large - 84) . ', 0)',
            '$infos.AutoSize = $true',
            '$infos.Location = New-Object System.Drawing.Point(18, 14)',
            '$carte.Controls.Add($infos)',
            '$carte.Size = New-Object System.Drawing.Size(' . ($large - 48) . ', ($infos.Bottom + 14))',
            '$suite = $carte.Bottom + 14',
            '',
        ];
    }

    /**
     * Lignes PowerShell qui construisent la fenêtre d'installation sans l'ouvrir : ce qui va être installé, pour qui,
     * et la seule décision à prendre — la mise à jour automatique, **décochée par défaut**. Rien à taper.
     *
     * Écrite en PowerShell/WinForms plutôt qu'en binaire : présent sur tout Windows 10 et 11, rien à compiler, rien
     * à signer, et le technicien peut lire ce qu'il lance.
     *
     * Partagée par le paquet ZIP et le fichier unique : le premier l'ouvre pour rendre un mot sur sa sortie standard
     * et laisse son .bat agir, le second enchaîne le travail lui-même. Une seule écriture de la fenêtre, donc une
     * seule case à cocher au même état par défaut, quel que soit le paquet.
     *
     * Mise en page : bandeau bleu (ce qu'on installe), carte claire (pour qui), notes en gris (ce que le bouton va
     * faire), pied de page pour les boutons. Une fenêtre grise pleine de texte dense, sur un PC client, ressemble à
     * un message d'erreur — et un technicien qui hésite à cliquer perd cinq minutes ou appelle.
     *
     * Toutes les hauteurs se calculent depuis le nombre de lignes : une ligne de plus ne doit jamais passer sous un
     * bouton.
     *
     * Elle laisse à l'appelant $f (la fenêtre, à ouvrir) et $maj (la case, ou $null si le serveur ne propose pas la
     * mise à jour).
     *
     * @param array $infos lignes d'identité (client, TAG, serveur), dans la carte
     * @param array $notes lignes grises sous la carte : ce que « Installer » va faire
     * @param bool  $impose vrai : le serveur a déjà tranché, la fenêtre l'annonce et ne demande rien ; faux : la
     *                      case apparaît, décochée, et le technicien peut l'ajouter sur place
     */
    private static function buildWindowsDialogLines(string $version, array $infos, array $notes, string $target, bool $impose, bool $reseau = false): array {
        // « Version visée : la dernière publiée » n'est pas une version : la ligne n'a de sens qu'épinglée.
        $aide = $impose ? [] : array_merge(
            [__('Décochée, l\'agent fonctionne normalement mais restera dans cette version jusqu\'à une intervention sur ce PC.', 'printgestion')],
            $target !== '' ? [sprintf(__('Version visée : %s.', 'printgestion'), $target)] : []
        );
        $large     = self::WIN_WIDTH;
        $couleur   = static fn(int $r, int $v, int $b) => sprintf('[System.Drawing.Color]::FromArgb(%d, %d, %d)', $r, $v, $b);
        // Chaque bloc de texte se dimensionne lui-même et le suivant se pose dessous ($suite) : une traduction plus
        // longue, un nom de client à rallonge ou un écran réglé à 125 % ne coupent plus rien.
        // Trois pages seulement quand il y a de quoi les remplir : le paquet ZIP ne pose qu'une question.
        $pageA     = $reseau ? '$pageA' : '$f';
        $pageB     = $reseau ? '$pageB' : '$f';
        $pageC     = $reseau ? '$pageC' : '$f';
        $bloc      = static fn(string $var, int $x, int $l) => [
            $var . '.MaximumSize = New-Object System.Drawing.Size(' . $l . ', 0)',
            $var . '.AutoSize = $true',
            $var . '.Location = New-Object System.Drawing.Point(' . $x . ', $suite)',
        ];

        return array_merge(self::buildWindowsFrameLines(
            sprintf(__('Installation de GLPI Agent %s', 'printgestion'), $version),
            __('Sonde d\'inventaire des imprimantes', 'printgestion'),
            sprintf(__('GLPI Agent %s — installation préparée par Print Gestion', 'printgestion'), $version),
            $infos
        ),
        $notes === [] ? [] : array_merge([
            '$notes = New-Object System.Windows.Forms.Label',
            '$notes.Text = ' . self::psQuote(implode("\r\n", $notes)),
            '$notes.ForeColor = ' . $couleur(90, 98, 110),
        ], $bloc('$notes', 26, $large - 52), [
            '$f.Controls.Add($notes)',
            '$suite = $notes.Bottom + 16',
            '',
        ]),
        // Le serveur a tranché : on l'annonce, on ne le redemande pas. Deux avis pour une même décision, c'est
        // l'assurance qu'ils finiront par se contredire — et personne ne saurait lequel a gagné.
        !$reseau ? [] : [
            '# ── Page 1 : l agent. Trois panneaux au meme endroit, montres a tour de role. ──',
            '$pageA = New-Object System.Windows.Forms.Panel',
            '$pageA.Location = New-Object System.Drawing.Point(0, $suite)',
            '$f.Controls.Add($pageA)',
            '$suite = 0',
            '',
        ],
        $impose ? array_merge([
            '$maj = $null',
            '$maj_imposee = $true',
            '$note = New-Object System.Windows.Forms.Label',
            '$note.Text = ' . self::psQuote(sprintf(
                __('Mise à jour automatique : posée sur ce PC (%s), réglage du serveur GLPI.', 'printgestion'),
                $target !== ''
                    ? sprintf(__('1er du mois à 3 h, version visée %s', 'printgestion'), $target)
                    : __('1er du mois à 3 h', 'printgestion')
            )),
            '$note.ForeColor = ' . $couleur(90, 98, 110),
        ], $bloc('$note', 26, $large - 52), [
            $pageA . '.Controls.Add($note)',
            '$suite = $note.Bottom',
            '',
        ]) : array_merge([
            '$maj_imposee = $false',
            '$maj = New-Object System.Windows.Forms.CheckBox',
            '$maj.Text = ' . self::psQuote(__('Mettre à jour l\'agent automatiquement (1er du mois à 3 h)', 'printgestion')),
            '$maj.Checked = $false',
        ], $bloc('$maj', 24, $large - 48), [
            $pageA . '.Controls.Add($maj)',
            '$suite = $maj.Bottom + 6',
            '',
        ], $aide === [] ? [] : array_merge([
            '$aide = New-Object System.Windows.Forms.Label',
            '$aide.Text = ' . self::psQuote(implode("\r\n", $aide)),
            '$aide.ForeColor = ' . $couleur(120, 128, 140),
        ], $bloc('$aide', 44, $large - 72), [
            $pageA . '.Controls.Add($aide)',
            '$suite = $aide.Bottom',
            '',
        ])),
        // Les adresses des imprimantes, demandées seulement par le fichier unique : lui seul peut les rapporter à
        // GLPI (le paquet ZIP ne parle à personne). Deux champs, et le second est prérempli.
        !$reseau ? ['$ips = $null', '$snmp = $null', '$freq = $null', '$mode = $null', ''] : array_merge([
            '# ── Page 2 : les imprimantes. ──',
            '$pageB = New-Object System.Windows.Forms.Panel',
            '$pageB.Location = $pageA.Location',
            '$pageB.Visible = $false',
            '$f.Controls.Add($pageB)',
            '$suite = 0',
            '',
            '$titre_ips = New-Object System.Windows.Forms.Label',
            '$titre_ips.Text = ' . self::psQuote(__('Adresses IP des imprimantes de ce client', 'printgestion')),
            '$titre_ips.Font = New-Object System.Drawing.Font("Segoe UI", 9, [System.Drawing.FontStyle]::Bold)',
            '$titre_ips.MaximumSize = New-Object System.Drawing.Size(552, 0)',
            '$titre_ips.AutoSize = $true',
            '$titre_ips.Location = New-Object System.Drawing.Point(24, ($suite + 12))',
            $pageB . '.Controls.Add($titre_ips)',
            '$suite = $titre_ips.Bottom + 4',
            '',
            '$ips = New-Object System.Windows.Forms.TextBox',
            '$ips.Size = New-Object System.Drawing.Size(552, 24)',
            '$ips.Location = New-Object System.Drawing.Point(24, $suite)',
            $pageB . '.Controls.Add($ips)',
            '$suite = $ips.Bottom + 4',
            '',
            '$aide_ips = New-Object System.Windows.Forms.Label',
            '$aide_ips.Text = ' . self::psQuote(__('Exemples : 192.168.1.0/24 (tout le réseau), 192.168.1.30-35 (une plage), ou des adresses séparées par des virgules. Laissé vide : rien n\'est créé dans GLPI, le raccordement restera à faire.', 'printgestion')),
            '$aide_ips.ForeColor = [System.Drawing.Color]::FromArgb(120, 128, 140)',
            '$aide_ips.MaximumSize = New-Object System.Drawing.Size(552, 0)',
            '$aide_ips.AutoSize = $true',
            '$aide_ips.Location = New-Object System.Drawing.Point(24, $suite)',
            $pageB . '.Controls.Add($aide_ips)',
            '$suite = $aide_ips.Bottom + 10',
            '',
            '$titre_snmp = New-Object System.Windows.Forms.Label',
            '$titre_snmp.Text = ' . self::psQuote(__('Communauté SNMP des imprimantes', 'printgestion')),
            '$titre_snmp.Font = New-Object System.Drawing.Font("Segoe UI", 9, [System.Drawing.FontStyle]::Bold)',
            '$titre_snmp.AutoSize = $true',
            '$titre_snmp.Location = New-Object System.Drawing.Point(24, $suite)',
            $pageB . '.Controls.Add($titre_snmp)',
            '$suite = $titre_snmp.Bottom + 4',
            '',
            '$snmp = New-Object System.Windows.Forms.TextBox',
            '$snmp.Text = "public"',
            '$snmp.Size = New-Object System.Drawing.Size(200, 24)',
            '$snmp.Location = New-Object System.Drawing.Point(24, $suite)',
            $pageB . '.Controls.Add($snmp)',
            '$suite = $snmp.Bottom + 10',
            '',
            '# ── Page 3 : le scan. ──',
            '$pageC = New-Object System.Windows.Forms.Panel',
            '$pageC.Location = $pageA.Location',
            '$pageC.Visible = $false',
            '$f.Controls.Add($pageC)',
            '$suite = 0',
            '',
            '$titre_freq = New-Object System.Windows.Forms.Label',
            '$titre_freq.Text = ' . self::psQuote(__('Fréquence des relevés', 'printgestion')),
            '$titre_freq.Font = New-Object System.Drawing.Font("Segoe UI", 9, [System.Drawing.FontStyle]::Bold)',
            '$titre_freq.AutoSize = $true',
            '$titre_freq.Location = New-Object System.Drawing.Point(24, $suite)',
            $pageC . '.Controls.Add($titre_freq)',
            '$suite = $titre_freq.Bottom + 4',
            '',
            '# Liste fermee : le choix part tel quel au serveur, qui le refuse s il ne le connait pas.',
            '$freq = New-Object System.Windows.Forms.ComboBox',
            '$freq.DropDownStyle = "DropDownList"',
            '$freq.Size = New-Object System.Drawing.Size(260, 24)',
            '$freq.Location = New-Object System.Drawing.Point(24, $suite)',
        ], array_map(
            static fn(string $code, string $libelle): string => '$freq.Items.Add(' . self::psQuote($code . '  —  ' . $libelle) . ') | Out-Null',
            array_keys(PluginPrintgestionCollectfrequency::getInstallerChoices()),
            array_values(PluginPrintgestionCollectfrequency::getInstallerChoices())
        ), [
            '$freq.SelectedIndex = ' . array_search(
                PluginPrintgestionCollectfrequency::INSTALLER_DEFAULT,
                array_keys(PluginPrintgestionCollectfrequency::getInstallerChoices()),
                true
            ),
            $pageC . '.Controls.Add($freq)',
            '$suite = $freq.Bottom + 4',
            '',
            '$aide_freq = New-Object System.Windows.Forms.Label',
            '$aide_freq.Text = ' . self::psQuote(__('Tous les combien les imprimantes sont relevées. Ce choix règle aussi le délai au-delà duquel GLPI signale qu\'une imprimante ne remonte plus.', 'printgestion')),
            '$aide_freq.ForeColor = [System.Drawing.Color]::FromArgb(120, 128, 140)',
            '$aide_freq.MaximumSize = New-Object System.Drawing.Size(552, 0)',
            '$aide_freq.AutoSize = $true',
            '$aide_freq.Location = New-Object System.Drawing.Point(24, $suite)',
            $pageC . '.Controls.Add($aide_freq)',
            '$suite = $aide_freq.Bottom + 10',
            '',
        ], self::buildWindowsPilotLines($pageC)), !$reseau ? [] : [
            '# Les trois pages prennent la hauteur de la plus chargee : la fenetre ne bouge plus d une page a l autre,',
            '# et une traduction plus longue ou un ecran a 125 % ne coupe rien (on mesure ce qui est pose).',
            '$hauteur = 0',
            'foreach ($p in @($pageA, $pageB, $pageC)) {',
            '  foreach ($c in @($p.Controls)) { if ($c.Bottom -gt $hauteur) { $hauteur = $c.Bottom } }',
            '}',
            'foreach ($p in @($pageA, $pageB, $pageC)) { $p.Size = New-Object System.Drawing.Size(' . $large . ', ($hauteur + 8)) }',
            '$suite = $pageA.Bottom',
            '',
        ], [
            '# Pied de page : les boutons, separes du contenu par un fond plus sombre.',
            '$pied = New-Object System.Windows.Forms.Panel',
            '$pied.Location = New-Object System.Drawing.Point(0, ($suite + 18))',
            '$pied.Size = New-Object System.Drawing.Size(' . $large . ', 64)',
            '$pied.BackColor = ' . $couleur(241, 243, 246),
            '$f.Controls.Add($pied)',
            '',
            '$ok = New-Object System.Windows.Forms.Button',
            '$ok.Text = ' . self::psQuote(__('Installer', 'printgestion')),
            '$ok.Location = New-Object System.Drawing.Point(' . ($large - 24 - 120 - 12 - 130) . ', 16)',
            '$ok.Size = New-Object System.Drawing.Size(130, 32)',
            '$ok.DialogResult = [System.Windows.Forms.DialogResult]::OK',
            '$pied.Controls.Add($ok)',
            '$f.AcceptButton = $ok',
            '',
        ], !$reseau ? [] : [
            '$prec = New-Object System.Windows.Forms.Button',
            '$prec.Text = ' . self::psQuote(__('Précédent', 'printgestion')),
            '$prec.Location = New-Object System.Drawing.Point(24, 16)',
            '$prec.Size = New-Object System.Drawing.Size(130, 32)',
            '$prec.Visible = $false',
            '$pied.Controls.Add($prec)',
            '',
            '$suiv = New-Object System.Windows.Forms.Button',
            '$suiv.Text = ' . self::psQuote(__('Suivant', 'printgestion')),
            '$suiv.Location = $ok.Location',
            '$suiv.Size = $ok.Size',
            '$pied.Controls.Add($suiv)',
            '',
            '# Une page a la fois : « Installer » n apparait qu à la derniere, et la touche Entree suit le bouton',
            '# visible. Le nom est « page_reglages » : « page » appartient deja a la liste des etapes, et l ecraser',
            '# cassait la suite de l installation — la simulation l a vu tout de suite.',
            '# visible — sinon elle installerait depuis la premiere page.',
            '$script:page_reglages = 1',
            'function MontrerPage($n) {',
            '  $script:page_reglages = $n',
            '  $pageA.Visible = ($n -eq 1)',
            '  $pageB.Visible = ($n -eq 2)',
            '  $pageC.Visible = ($n -eq 3)',
            '  $prec.Visible = ($n -gt 1)',
            '  $suiv.Visible = ($n -lt 3)',
            '  $ok.Visible = ($n -eq 3)',
            '  if ($n -eq 3) { $f.AcceptButton = $ok } else { $f.AcceptButton = $suiv }',
            '}',
            '$suiv.Add_Click({ MontrerPage ([Math]::Min(3, $script:page_reglages + 1)) })',
            '$prec.Add_Click({ MontrerPage ([Math]::Max(1, $script:page_reglages - 1)) })',
            'MontrerPage 1',
            '',
        ], [
            '$non = New-Object System.Windows.Forms.Button',
            '$non.Text = ' . self::psQuote(__('Annuler', 'printgestion')),
            '$non.Location = New-Object System.Drawing.Point(' . ($large - 24 - 120) . ', 16)',
            '$non.Size = New-Object System.Drawing.Size(120, 32)',
            '$non.DialogResult = [System.Windows.Forms.DialogResult]::Cancel',
            '$pied.Controls.Add($non)',
            '$f.CancelButton = $non',
            '',
            '# La fenetre prend sa hauteur une fois tout pose : rien ne peut depasser.',
            '$f.ClientSize = New-Object System.Drawing.Size(' . $large . ', $pied.Bottom)',
            '',
        ]);
    }

    /** Les trois lignes qui chargent WinForms : mêmes lignes pour la fenêtre du ZIP et pour le fichier unique. */
    private static function psFormsHeader(): array {
        return [
            '$ErrorActionPreference = "Stop"',
            'Add-Type -AssemblyName System.Windows.Forms',
            'Add-Type -AssemblyName System.Drawing',
            '[System.Windows.Forms.Application]::EnableVisualStyles()',
            '# Filet : une erreur survenue avant que la fenetre existe s affiche quand meme, dans une boite de message.',
            '# Sans lui, PowerShell sortait sans rien montrer : la console clignotait, rien d autre.',
            '$script:fenetre_prete = $false',
            'function Secours($texte) {',
            '  try { [void][System.Windows.Forms.MessageBox]::Show($texte + [Environment]::NewLine + [Environment]::NewLine + ' . self::psQuote(__('Journal :', 'printgestion')) . ' + " " + $script:Journal, "Print Gestion", "OK", "Error") } catch { }',
            '}',
            '',
        ];
    }

    /**
     * Qui pilote le scan : une liste fermée de deux choix, ou une simple ligne quand le serveur n'a pas GLPI
     * Inventory — il n'y a alors qu'un chemin possible, et proposer un choix serait mentir.
     *
     * Le libellé de chaque choix porte son explication, à la suite : une liste déroulante ne montre qu'une ligne à
     * la fois, et l'aide sous le champ suivrait le choix au lieu de le précéder.
     */
    private static function buildWindowsPilotLines(string $parent = '$f'): array {
        $large = self::WIN_WIDTH;
        if (!PluginPrintgestionCollectsetup::isAvailable()) {
            return [
                '$mode = $null',
                '$aide_mode = New-Object System.Windows.Forms.Label',
                '$aide_mode.Text = ' . self::psQuote(self::pilotLocalOnly()),
                '$aide_mode.ForeColor = [System.Drawing.Color]::FromArgb(120, 128, 140)',
                '$aide_mode.MaximumSize = New-Object System.Drawing.Size(' . ($large - 48) . ', 0)',
                '$aide_mode.AutoSize = $true',
                '$aide_mode.Location = New-Object System.Drawing.Point(24, $suite)',
                $parent . '.Controls.Add($aide_mode)',
                '$suite = $aide_mode.Bottom',
                '',
            ];
        }
        $choix = self::pilotChoices();
        return array_merge([
            '$titre_mode = New-Object System.Windows.Forms.Label',
            '$titre_mode.Text = ' . self::psQuote(__('Qui pilote le scan des imprimantes', 'printgestion')),
            '$titre_mode.Font = New-Object System.Drawing.Font("Segoe UI", 9, [System.Drawing.FontStyle]::Bold)',
            '$titre_mode.AutoSize = $true',
            '$titre_mode.Location = New-Object System.Drawing.Point(24, $suite)',
            $parent . '.Controls.Add($titre_mode)',
            '$suite = $titre_mode.Bottom + 4',
            '',
            '# Liste fermee : le premier est le choix par defaut.',
            '$mode = New-Object System.Windows.Forms.ComboBox',
            '$mode.DropDownStyle = "DropDownList"',
            '$mode.Size = New-Object System.Drawing.Size(' . ($large - 48) . ', 24)',
            '$mode.Location = New-Object System.Drawing.Point(24, $suite)',
        ], array_map(
            static fn(array $texte): string => '$mode.Items.Add(' . self::psQuote($texte[0]) . ') | Out-Null',
            array_values($choix)
        ), [
            '$mode.SelectedIndex = 0',
            $parent . '.Controls.Add($mode)',
            '$suite = $mode.Bottom + 4',
            '',
            '# L explication sous le champ : une liste deroulante ne montre qu une ligne, le texte y serait coupe.',
            '$aide_mode = New-Object System.Windows.Forms.Label',
            '$aide_mode.Text = ' . self::psQuote($choix['glpi'][0] . ' : ' . $choix['glpi'][1]) . ' + [Environment]::NewLine + ' . self::psQuote($choix['local'][0] . ' : ' . $choix['local'][1]),
            '$aide_mode.ForeColor = [System.Drawing.Color]::FromArgb(120, 128, 140)',
            '$aide_mode.MaximumSize = New-Object System.Drawing.Size(' . ($large - 48) . ', 0)',
            '$aide_mode.AutoSize = $true',
            '$aide_mode.Location = New-Object System.Drawing.Point(24, $suite)',
            $parent . '.Controls.Add($aide_mode)',
            '$suite = $aide_mode.Bottom',
            '',
        ]);
    }

    /**
     * Fenêtre du paquet ZIP : elle ne décide de rien elle-même, elle écrit un mot sur sa sortie standard (AVEC,
     * SANS, ANNULE) et c'est le .bat qui agit. Le fichier unique, lui, ouvre la même fenêtre et enchaîne.
     */
    public static function buildWindowsDialogScript(string $version, string $client, string $tag, string $server, string $target, bool $impose = true): string {
        return implode("\r\n", array_merge(
            [
                '# Fenetre d installation de GLPI Agent, posee par Print Gestion. Aucun identifiant ni secret.',
                '# Elle ne fait que poser une question : c est le .bat qui installe.',
            ],
            self::psFormsHeader(),
            self::buildWindowsDialogLines($version, self::dialogInfoLines($client, $tag, $server), [], $target, $impose),
            [
                '$r = $f.ShowDialog()',
                'if ($r -ne [System.Windows.Forms.DialogResult]::OK) { Write-Output "ANNULE"; exit 0 }',
                'if ($maj_imposee -or ($null -ne $maj -and $maj.Checked)) { Write-Output "AVEC" } else { Write-Output "SANS" }',
                '',
            ]
        ));
    }

    /**
     * Paquet Windows d'une entité, écrit dans un fichier temporaire que l'appelant supprime après
     * envoi : MSI officiel vérifié et deux gestes séparés. Geste 1 : un .bat qui ne fait que lancer le MSI avec
     * ses propriétés (un .bat ne se signe pas, et les antivirus se méfient de ceux qui enchaînent installation et
     * tâche planifiée). Geste 2, facultatif, fourni si les réglages par défaut le prévoient : un second .bat qui
     * pose seulement la tâche planifiée de mise à jour, avec son script. Plus la commande à copier et la note.
     *
     * @return array ['ok' => bool, 'errors' => string[], 'path', 'filename', 'version', 'tag']
     */
    public static function buildWindowsPackage(Entity $entity): array {
        $started  = microtime(true);
        $blockers = self::getPackageBlockers($entity);
        if (!empty($blockers)) {
            return ['ok' => false, 'errors' => $blockers];
        }
        $installer = self::getCachedInstaller(true);
        if ($installer === null) {
            return ['ok' => false, 'errors' => [__('Installeur absent ou modifié depuis sa vérification : refaites la vérification (page « Installeur GLPI Agent »).', 'printgestion')]];
        }

        $tag     = trim((string) $entity->fields['tag']);
        $version = (string) $installer['version'];
        $msi     = self::getMsiName($version);
        $data    = str_replace('/', chr(92), self::WINDOWS_DATA_DIR); // chemins Windows dans le .bat
        $ps1     = $data . self::WINDOWS_DIALOG_PS1;
        $config  = PluginPrintgestionConfig::getInstance()->fields;
        $update  = (int) ($config['agent_update_default'] ?? 1) === 1;
        // Une seule version pour tout : celle que ce serveur distribue est aussi celle que les sondes visent.
        $target  = PluginPrintgestionAgentsetting::getTargetVersion();

        // Le seul fichier à lancer : il vérifie qu'il est administrateur, que le MSI est là, ouvre la fenêtre
        // d'installation, puis installe le MSI officiel signé avec ses propriétés (start /wait ; codes 0, 3010 et
        // 1641 : installé, redémarrage demandé ou lancé). Aucun identifiant, aucun secret, rien d'autre.
        //
        // La fenêtre passe avant l'installation : elle montre ce qui va être installé et pour qui, et recueille la
        // seule décision (la mise à jour automatique, décochée). Ce que le .bat vérifie avant, lui, ce sont les deux
        // choses qui rendraient la fenêtre inutile : pas administrateur, ou MSI absent.
        //
        // Sans PowerShell, ou si la fenêtre échoue, la question revient en console : vingt secondes, « non » tout
        // seul — un double clic suivi d'un départ ne pose jamais une tâche planifiée que personne n'a voulue.
        $install_bat = implode("\r\n", array_merge(
            [
                '@echo off',
                'rem GLPI Agent ' . $version . ' - installation pre-parametree pour le TAG ' . $tag,
                'rem Seul fichier a lancer du dossier. Clic droit, puis Executer en tant qu administrateur.',
                'rem Lance le MSI officiel signe avec ses proprietes. Aucun identifiant ni secret.',
                'title Installation de GLPI Agent ' . $version,
            ],
            PluginPrintgestionAgentsetting::buildAdminCheckLines(),
            [
                'if not exist "%~dp0' . $data . $msi . '" (',
                '  echo Fichier ' . $msi . ' introuvable : extraire tout le ZIP, puis relancer depuis le dossier extrait.',
                '  pause',
                '  exit /b 1',
                ')',
                'set "PGMAJ="',
                'if exist "%~dp0' . $ps1 . '" (',
                '  for /f "usebackq delims=" %%R in (`powershell -NoProfile -ExecutionPolicy Bypass -STA -File "%~dp0' . $ps1 . '"`) do set "PGMAJ=%%R"',
                ')',
                'if "%PGMAJ%"=="ANNULE" (',
                '  echo Installation annulee : rien n a ete installe.',
                '  exit /b 0',
                ')',
                'echo Installation de GLPI Agent ' . $version . ' en cours, merci de patienter...',
                'start "" /wait ' . self::buildWindowsCommand('%~dp0' . $data . $msi, $tag),
                'set "RC=%ERRORLEVEL%"',
                'if not "%RC%"=="0" if not "%RC%"=="3010" if not "%RC%"=="1641" (',
                '  echo Installation non terminee, code %RC% : journal "%TEMP%\\GLPI-Agent-install.log"',
                '  pause',
                '  exit /b %RC%',
                ')',
                'echo.',
                'echo GLPI Agent installe.',
            ],
            // Le bloc qui pose la tâche est toujours écrit : avec la règle du OU, le technicien peut la vouloir
            // alors que le serveur ne l'impose pas. C'est la réponse qui décide, pas la présence du bloc.
            array_merge(
                ['echo.'],
                $update
                    ? [
                        'rem Le serveur impose la mise a jour automatique : fenetre indisponible ou pas, c est oui.',
                        'if not defined PGMAJ set "PGMAJ=AVEC"',
                    ]
                    : [
                        'rem Fenetre indisponible ou fermee sans reponse : la question en console, non par defaut.',
                        'if not defined PGMAJ (',
                        '  echo Mise a jour automatique mensuelle : le 1er du mois a 3 h, seulement si l agent est en attente.',
                        '  choice /C ON /T 20 /D N /M "La poser maintenant (O = oui, N = non)"',
                        '  if errorlevel 2 (set "PGMAJ=SANS") else (set "PGMAJ=AVEC")',
                        ')',
                    ],
                ['if not "%PGMAJ%"=="AVEC" goto :fin'],
                PluginPrintgestionAgentsetting::buildScheduleLines(true, $data),
                [
                    'echo Mise a jour automatique mensuelle posee : ' . ($target !== '' ? 'version cible ' . $target . '.' : 'derniere version publiee.'),
                    ':fin',
                ]
            ),
            ['echo.', 'pause', '']
        ));
        $command      = self::buildWindowsCommand($msi, $tag) . "\r\n";
        $target_label = $target !== '' ? sprintf(__('version cible %s', 'printgestion'), $target) : __('dernière version publiée', 'printgestion');
        $readme       = implode("\r\n", array_merge(
            [
                sprintf(__('Installation de GLPI Agent %1$s — %2$s (TAG : %3$s)', 'printgestion'), $version, (string) $entity->fields['completename'], $tag),
                sprintf(__('Paquet généré par Print Gestion le %1$s par %2$s.', 'printgestion'), Html::convDateTime(date('Y-m-d H:i:s')), getUserName((int) Session::getLoginUserID())),
                __('Ce dossier ne contient aucun identifiant, mot de passe ni jeton : seulement l\'adresse du serveur GLPI et le TAG du client.', 'printgestion'),
                '',
                sprintf(__('UN SEUL FICHIER À LANCER : %s', 'printgestion'), self::WINDOWS_INSTALL_BAT),
                __('Tout le reste du dossier est soit une donnée dont il se sert, soit ce mode d\'emploi. Rien d\'autre n\'est à ouvrir ni à double-cliquer.', 'printgestion'),
                '',
                __('MARCHE À SUIVRE', 'printgestion'),
                __('1. Sur le PC qui servira de sonde (allumé en permanence, sur le réseau des imprimantes) : clic droit sur le fichier ZIP > Extraire tout.', 'printgestion'),
                sprintf(__('2. Dans le dossier extrait : clic droit sur %s > Exécuter en tant qu\'administrateur. Une fenêtre s\'ouvre : elle rappelle le client, le TAG et le serveur, et propose la mise à jour automatique. Cliquer sur « Installer » — l\'installation se fait ensuite sans aucune question. Attendre le message final avant de fermer la fenêtre noire.', 'printgestion'), self::WINDOWS_INSTALL_BAT),
                __('3. Dans GLPI (fiche de l\'entité, onglet « Déploiement Agent ») : vérifier que l\'agent apparaît avec un contact récent, puis raccorder les imprimantes avec l\'assistant (bloc 3), avant de partir.', 'printgestion'),
                sprintf(__('Si Windows refuse de lancer le fichier .bat : ouvrir l\'invite de commandes en administrateur (cmd, pas PowerShell) dans le dossier extrait et coller la commande du fichier %scommande-cmd.txt.', 'printgestion'), self::WINDOWS_DATA_DIR),
                __('En cas d\'échec de l\'installation : journal %TEMP%\\GLPI-Agent-install.log sur le PC.', 'printgestion'),
                '',
            ],
            $update
                ? [
                    __('MISE À JOUR AUTOMATIQUE (facultative, case à cocher de la fenêtre)', 'printgestion'),
                    sprintf(
                        __('La fenêtre d\'installation propose « Mettre à jour l\'agent automatiquement » : décochée par défaut, à cocher avant de cliquer sur « Installer ». Cochée, elle pose la tâche planifiée « %1$s » (le 1er du mois à 3 h, compte SYSTEM, winget, %2$s, seulement si l\'agent est en attente ; journal C:\\ProgramData\\PrintGestion\\glpi-agent-update.log). Si la fenêtre ne s\'ouvre pas (PowerShell absent), la question est posée en console : O pour oui, N pour non, « non » tout seul au bout de vingt secondes.', 'printgestion'),
                        PluginPrintgestionAgentsetting::TASK_NAME,
                        $target_label
                    ),
                    sprintf(__('Ce que l\'on perd en laissant la case décochée : la mise à jour automatique de cette sonde, rien d\'autre. L\'agent fonctionne normalement (inventaire du PC, découverte et relevés des imprimantes) mais reste en version %s jusqu\'à une intervention sur ce PC. La page « Sondes » de Print Gestion montre sa conformité de version : elle le signalera « À mettre à jour » dès qu\'une version plus récente sera visée.', 'printgestion'), $version),
                    __('Case décochée, ou tâche bloquée par l\'antivirus : l\'agent installé reste valable. Dans GLPI, décocher « Mise à jour automatique » sur la sonde (page « Sondes ») pour que son réglage corresponde au PC ; pour poser la tâche plus tard : paquet de consigne de la sonde, lancé sur ce PC.', 'printgestion'),
                    __('Pour changer ou retirer la tâche : régler la sonde dans GLPI, puis lancer son paquet de consigne sur ce PC ; le réglage de GLPI seul ne change rien sur le PC.', 'printgestion'),
                ]
                : [
                    sprintf(__('Mise à jour automatique : non posée par ce paquet (réglage de la page « Installeur GLPI Agent »). L\'agent reste en version %s jusqu\'à une intervention sur ce PC ; la page « Sondes » de Print Gestion le signalera « À mettre à jour » dès qu\'une version plus récente sera visée. Pour la poser plus tard : paquet de consigne de la sonde dans GLPI.', 'printgestion'), $version),
                ],
            [
                '',
                PluginPrintgestionCollectfrequency::getPackageLine((int) $entity->getID()),
                '',
            ]
        ));

        $path = GLPI_TMP_DIR . '/printgestion-agent-' . bin2hex(random_bytes(8)) . '.zip';
        $zip  = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            PluginPrintgestionLogger::error('agentdeploy', sprintf('Paquet %s non créé.', $path));
            return ['ok' => false, 'errors' => [__('Paquet non généré (détail dans le journal printgestion).', 'printgestion')]];
        }
        // Racine : le fichier à lancer et le mode d'emploi. Tout le reste est une donnée, rangée dans « fichiers ».
        $zip->addFile($installer['path'], self::WINDOWS_DATA_DIR . $msi);
        $zip->setCompressionName(self::WINDOWS_DATA_DIR . $msi, ZipArchive::CM_STORE); // MSI déjà compressé
        $zip->addFromString(self::WINDOWS_INSTALL_BAT, $install_bat);
        // Toujours présent : le technicien peut poser la tâche même quand le serveur ne l'impose pas.
        $zip->addFromString(
            self::WINDOWS_DATA_DIR . PluginPrintgestionAgentsetting::UPDATE_SCRIPT,
            PluginPrintgestionAgentsetting::buildUpdateScript($target)
        );
        // La fenêtre est une donnée du .bat, pas un second exécutable : personne n'a à la lancer.
        $zip->addFromString(
            self::WINDOWS_DATA_DIR . self::WINDOWS_DIALOG_PS1,
            "\xEF\xBB\xBF" . self::buildWindowsDialogScript($version, (string) $entity->fields['completename'], $tag, self::getServerUrl()['url'], $target, $update)
        );
        $zip->addFromString(self::WINDOWS_DATA_DIR . 'commande-cmd.txt', $command);
        $zip->addFromString('LISEZMOI.txt', "\xEF\xBB\xBF" . $readme);
        if (!$zip->close()) {
            if (is_file($path)) {
                unlink($path);
            }
            PluginPrintgestionLogger::error('agentdeploy', sprintf('Paquet %s non finalisé.', $path));
            return ['ok' => false, 'errors' => [__('Paquet non généré (détail dans le journal printgestion).', 'printgestion')]];
        }
        PluginPrintgestionLogger::duration('agentdeploy', 'Paquet Windows (ZIP) produit', $started, sprintf('TAG %s, %d octets', $tag, (int) filesize($path)));

        return [
            'ok'       => true,
            'errors'   => [],
            'path'     => $path,
            'filename' => sprintf('GLPI-Agent-%s-windows-%s.zip', $version, $tag),
            'version'  => $version,
            'tag'      => $tag,
        ];
    }

    // ── Fichiers uniques : briques communes ───────────────────────────────────

    /** Valeur littérale dans un script shell entre apostrophes : l'apostrophe s'y ferme, s'échappe et se rouvre. */
    private static function shQuote(string $value): string {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }


    /**
     * Nom du fichier unique : il dit ce qu'il fait, pour quelle version et pour quel client. Un fichier posé sur un
     * bureau à côté de trois autres doit se reconnaître sans être ouvert.
     */
    public static function getSingleFileName(string $tag, string $version, string $platform = 'windows'): string {
        $safe = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $tag), '-');
        $safe = $safe !== '' ? $safe : 'sonde';
        return match ($platform) {
            'linux' => sprintf('installer-glpi-agent-%1$s-%2$s.sh', $version, $safe),
            'macos' => sprintf('installer-glpi-agent-macos-%1$s-%2$s.sh', $version, $safe),
            default => sprintf('INSTALLER-GLPI-AGENT-%1$s-%2$s.bat', $version, $safe),
        };
    }


    /**
     * Journal d'une exécution sous Linux ou macOS : un fichier par exécution dans /var/tmp.
     *
     * /var/tmp et non /tmp, que beaucoup de distributions vident au redémarrage — et c'est souvent après un
     * redémarrage qu'on veut relire ce qui s'est passé. La communauté SNMP et la clé de téléchargement n'y sont
     * jamais écrites : ce sont des secrets.
     *
     * @param string[] $header premières lignes : ce qu'on fait, pour qui
     */
    private static function buildShellJournalLines(string $log_name, array $header): array {
        return array_merge([
            '# Journal : un fichier par execution, dans /var/tmp (garde au redemarrage, contrairement a /tmp).',
            'PG_JOURNAL="/var/tmp/printgestion-' . $log_name . '-$(date +%Y%m%d-%H%M%S).log"',
            'if ! ( : > "$PG_JOURNAL" ) 2>/dev/null; then PG_JOURNAL=/dev/null; fi',
            'chmod 644 "$PG_JOURNAL" 2>/dev/null',
            'pg_journal() { printf "%s  %s\\n" "$(date "+%Y-%m-%d %H:%M:%S")" "$*" >> "$PG_JOURNAL"; }',
        ], array_map(static fn(string $ligne): string => 'pg_journal ' . self::shQuote($ligne), $header), [
            'pg_journal "PC : $(hostname)   compte : ${SUDO_USER:-root}"',
            '',
        ]);
    }

    /**
     * Outils communs aux fichiers Linux et macOS : télécharger, calculer une empreinte, interroger une URL.
     *
     * L'empreinte est recalculée sur le poste. Sans outil pour la calculer, on refuse : mieux vaut ne rien installer
     * qu'installer un fichier dont personne n'a vérifié la provenance.
     */
    private static function buildShellToolLines(): array {
        return [
            '# Detail sous la barre d avancement, quand une fenetre sait le montrer (macOS) ; rien ailleurs.',
            'pg_detail() { :; }',
            '',
            '# Le nom du poste tel que l agent le declare a GLPI, celui par lequel le serveur retrouve la sonde :',
            '# sous macOS, le « nom de l ordinateur » (module MacOS/Hostname de l agent, system_profiler), que scutil',
            '# rend ; ailleurs, le nom coupe au premier point (getHostname(short => 1)). Windows envoie COMPUTERNAME.',
            '# Avec « hostname » (« Mac.local »), GLPI ne retrouvait pas la sonde : dix minutes d attente pour rien.',
            'pg_poste() {',
            '  pg_nom_poste=""',
            '  if command -v scutil >/dev/null 2>&1; then pg_nom_poste=$(scutil --get ComputerName 2>/dev/null); fi',
            '  if [ -z "$pg_nom_poste" ]; then pg_nom_poste=$(hostname 2>/dev/null | cut -d. -f1); fi',
            '  printf "%s" "$pg_nom_poste"',
            '}',
            '# Telechargement suivi dans la fenetre, comme sous Windows : « 12 Mo sur 22 Mo » sous la barre, qui avance',
            '# de 2 a 40 %. curl travaille en arriere-plan ; on mesure ce qu il a deja ecrit. Rend le code de curl.',
            'pg_telecharger_suivi() {',
            '  pg_telecharger "$1" "$2" >/dev/null 2>&1 &',
            '  pg_tel=$!',
            '  pg_total=$(( ${3:-0} / 1048576 ))',
            '  while kill -0 "$pg_tel" 2>/dev/null; do',
            '    sleep 1',
            '    if [ -f "$2" ] && [ "${3:-0}" -gt 0 ]; then',
            '      pg_recu=$(wc -c < "$2" | tr -d " ")',
            '      pg_p=$(( pg_recu * 100 / $3 ))',
            '      if [ "$pg_p" -gt 100 ]; then pg_p=100; fi',
            '      pg_pct $(( 2 + pg_p * 38 / 100 ))',
            '      pg_detail "' . str_replace(['@RECU@', '@TOTAL@'], ['$(( pg_recu / 1048576 ))', '$pg_total'], __('@RECU@ Mo sur @TOTAL@ Mo', 'printgestion')) . '"',
            '    fi',
            '  done',
            '  wait "$pg_tel"',
            '}',
            '',
            '# Une valeur dans une URL, chaque octet encode : espaces et accents d un nom d ordinateur, virgules des',
            '# adresses, tout ce qu une communaute SNMP peut contenir. Windows fait de meme (EscapeDataString).',
            'pg_url() { printf "%s" "$1" | od -An -v -tx1 | tr -d " \\n" | sed "s/../%&/g"; }',
            '',
            '# Telechargement, avec la barre de curl quand on est en console.',
            'pg_telecharger() {',
            '  if command -v curl >/dev/null 2>&1; then',
            '    curl -fL --progress-bar --max-time 900 -o "$2" "$1"',
            '    return $?',
            '  fi',
            '  if command -v wget >/dev/null 2>&1; then',
            '    wget -q --show-progress -O "$2" "$1" 2>&1 || wget -q -O "$2" "$1"',
            '    return $?',
            '  fi',
            '  return 127',
            '}',
            '',
            '# Empreinte SHA-256 en hexadecimal minuscule, vide si le poste n a aucun outil pour la calculer.',
            'pg_empreinte() {',
            '  if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d" " -f1; return 0; fi',
            '  if command -v shasum >/dev/null 2>&1; then shasum -a 256 "$1" | cut -d" " -f1; return 0; fi',
            '  if command -v openssl >/dev/null 2>&1; then openssl dgst -sha256 "$1" | sed "s/.*= *//"; return 0; fi',
            '  printf ""',
            '}',
            '',
            '# Envoi d un formulaire ($2), sans rien afficher. Rend 0 si le serveur a repondu.',
            'pg_poster() {',
            '  if command -v curl >/dev/null 2>&1; then curl -fsS --max-time 10 -X POST -d "$2" "$1" >/dev/null 2>&1; return $?; fi',
            '  if command -v wget >/dev/null 2>&1; then wget -q -T 10 -O - --post-data "$2" "$1" >/dev/null 2>&1; return $?; fi',
            '  return 127',
            '}',
            '',
            '# Corps d une page web, vide en cas d echec. $2 : delai maximal en secondes (5 par defaut).',
            'pg_http() {',
            '  if command -v curl >/dev/null 2>&1; then curl -fsS --max-time "${2:-5}" "$1" 2>/dev/null; return $?; fi',
            '  if command -v wget >/dev/null 2>&1; then wget -q -T "${2:-5}" -O - "$1" 2>/dev/null; return $?; fi',
            '  return 127',
            '}',
            '',
        ];
    }

    /**
     * Libellés et rangs des étapes, pour pg_etape : « Étape 3/7 — Installation de GLPI Agent 1.19 ».
     *
     * @param array $steps clé => libellé, dans l'ordre
     */
    private static function buildShellStepNames(array $steps): array {
        $cles = array_keys($steps);
        return array_merge(
            ['pg_libelle() {', '  case "$1" in'],
            array_map(static fn(string $cle): string => '    ' . $cle . ') printf "%s" ' . self::shQuote($steps[$cle]) . ' ;;', $cles),
            ['    *) printf "%s" "$1" ;;', '  esac', '}', 'pg_rang() {', '  case "$1" in'],
            array_map(static fn(int $rang, string $cle): string => '    ' . $cle . ') printf "%s" "' . ($rang + 1) . '" ;;', array_keys($cles), $cles),
            ['    *) printf "?" ;;', '  esac', '}', 'PG_TOTAL=' . count($steps), 'PG_EN_COURS=""', '']
        );
    }

    /**
     * Premier contact avec GLPI : attendre que GLPI connaisse la sonde avant de lui confier les imprimantes.
     *
     * Le compte rendu partait dès la fin de l'installation, souvent avant que l'agent ait envoyé son premier
     * inventaire : GLPI ne connaissait pas encore la sonde, et ne pouvait ni régler ses modules ni lui confier les
     * imprimantes. On attend donc que l'agent local ait fini un passage — son état redevient « waiting ».
     */
    private static function buildShellContactLines(): array {
        return [
            '# ── Premier contact : GLPI doit connaitre la sonde avant qu on lui confie les imprimantes ──',
            'pg_etape contact encours ""',
            'pg_detail ' . self::shQuote(__('L\'agent envoie son premier inventaire : GLPI doit le connaître avant qu\'on lui confie les imprimantes.', 'printgestion')),
            'pg_statut=""',
            'pg_i=0',
            '# Le service vient d etre installe : son interface met un moment a repondre (2 min au plus).',
            'while [ "$pg_i" -lt 40 ]; do',
            '  pg_statut=$(pg_http ' . self::shQuote(self::getAgentStatusUrl()) . ')',
            '  if [ -n "$pg_statut" ]; then break; fi',
            '  pg_i=$((pg_i + 1))',
            '  sleep 3',
            'done',
            'if [ -z "$pg_statut" ]; then',
            '  pg_etape contact saute ' . self::shQuote(__('l\'agent ne répond pas encore sur ce poste', 'printgestion')),
            'else',
            '  pg_journal "        agent local : $pg_statut"',
            '  # Un passage tout de suite, puis on attend qu il soit fini (3 min au plus).',
            '  pg_http ' . self::shQuote(self::getAgentWakeUrl()) . ' >/dev/null',
            '  sleep 5',
            '  pg_fini=0',
            '  pg_i=0',
            '  while [ "$pg_i" -lt 60 ]; do',
            '    case "$(pg_http ' . self::shQuote(self::getAgentStatusUrl()) . ')" in *waiting*) pg_fini=1; break ;; esac',
            '    pg_i=$((pg_i + 1))',
            '    sleep 3',
            '  done',
            '  if [ "$pg_fini" = 1 ]; then pg_etape contact ok ""; else pg_etape contact saute ' . self::shQuote(__('toujours en cours après trois minutes', 'printgestion')) . '; fi',
            'fi',
            '',
        ];
    }

    /**
     * Interface des fichiers Linux : zenity quand le poste a un écran, la console sinon.
     *
     * zenity tourne sous le compte de la personne connectée (SUDO_USER), pas en root : depuis Wayland, root n'a plus le
     * droit d'ouvrir une fenêtre sur la session de quelqu'un d'autre. L'affichage est retrouvé même quand sudo a
     * effacé DISPLAY (socket X11 ou Wayland de la session). Sans écran — serveur, SSH — tout se fait en console, étape
     * par étape, avec le même journal.
     *
     * Définit l'interface d'étapes commune (pg_etape, pg_dire, pg_pct, pg_fin, pg_echec) et $PG_ETAT, le fichier où
     * pg_fin laisse le résultat : la fenêtre d'avancement lit un tube, le travail tourne donc dans un sous-shell.
     *
     * @param string[] $infos lignes de présentation : client, TAG, serveur…
     * @param array    $steps clé => libellé
     */
    private static function buildLinuxUiLines(string $title, array $infos, array $steps, string $mort = ''): array {
        return array_merge([
            'PG_TITRE=' . self::shQuote($title),
            'PG_INFOS=' . self::shQuote(implode("\n", $infos)),
            'PG_MORT=' . self::shQuote($mort),
            'PG_MORT_Z=' . self::shQuote(htmlspecialchars($mort, ENT_NOQUOTES, 'UTF-8')),
            'PG_TRAVAIL=0',
            '# zenity lit ses textes en balisage Pango : un « & » ou un « < » dans un nom de client le ferait taire.',
            'PG_INFOS_Z=' . self::shQuote(htmlspecialchars(implode("\n", $infos), ENT_NOQUOTES, 'UTF-8')),
            '',
            '# Fenetre si le poste en a une, console sinon. zenity tourne sous le compte de la personne connectee.',
            'pg_gui=""',
            'pg_uid=""',
            'if [ -n "${SUDO_USER:-}" ] && [ "${SUDO_USER}" != root ]; then pg_uid=$(id -u "$SUDO_USER" 2>/dev/null); fi',
            'pg_run="${XDG_RUNTIME_DIR:-}"',
            'if [ -n "$pg_uid" ]; then pg_run="/run/user/$pg_uid"; fi',
            'pg_display="${DISPLAY:-}"',
            'if [ -z "$pg_display" ] && [ -S /tmp/.X11-unix/X0 ]; then pg_display=":0"; fi',
            'pg_wayland="${WAYLAND_DISPLAY:-}"',
            'if [ -z "$pg_wayland" ] && [ -n "$pg_run" ] && [ -S "$pg_run/wayland-0" ]; then pg_wayland="wayland-0"; fi',
            '# « --console » : les questions dans le terminal meme si le poste a un ecran — pour verifier ce chemin,',
            '# ou en SSH, sans qu une fenetre s ouvre sur l ecran du poste.',
            'PG_CONSOLE=0',
            'for pg_arg in "$@"; do case "$pg_arg" in --console) PG_CONSOLE=1 ;; esac; done',
            'if [ "$PG_CONSOLE" = 0 ] && command -v zenity >/dev/null 2>&1 && { [ -n "$pg_display" ] || [ -n "$pg_wayland" ]; }; then pg_gui=zenity; fi',
            'if [ "$PG_CONSOLE" = 1 ]; then pg_journal "Option --console : questions dans le terminal"; fi',
            'pg_zen() {',
            '  if [ -n "$pg_uid" ]; then',
            '    sudo -u "$SUDO_USER" env DISPLAY="$pg_display" WAYLAND_DISPLAY="$pg_wayland" XDG_RUNTIME_DIR="$pg_run" zenity "$@"',
            '  else',
            '    env DISPLAY="$pg_display" WAYLAND_DISPLAY="$pg_wayland" zenity "$@"',
            '  fi',
            '}',
            '',
        ], self::buildShellStepNames($steps), [
            '# « ¶ » marque un retour a la ligne dans un message : la fenetre l aplatit, la console le rend.',
            'pg_lignes() { printf "%s\\n" "$1" | awk \'{ gsub("¶", "\\n"); print }\'; }',
            'pg_aplat() { printf "%s" "$1" | awk \'{ gsub("¶", " "); printf "%s", $0 }\'; }',
            '',
            '# Dans la fenetre, une ligne « # texte » change le texte et un nombre la barre ; en console, du texte.',
            'PG_DIRE=""',
            'pg_dire() {',
            '  PG_DIRE="$1"',
            '  if [ "$pg_gui" = zenity ]; then printf "# %s\\n" "$1"; else printf "   %s\\n" "$1"; fi',
            '}',
            '# Le detail sous la barre (« 12 Mo sur 22 Mo », « Environ une minute ») : zenity n a qu une ligne, il',
            '# s ajoute a la suite du texte de l etape.',
            'pg_detail() {',
            '  if [ "$pg_gui" = zenity ] && [ -n "$1" ]; then printf "# %s   %s\\n" "$PG_DIRE" "$1"; fi',
            '}',
            'pg_pct() {',
            '  if [ "$pg_gui" = zenity ]; then printf "%s\\n" "$1"; fi',
            '}',
            'pg_etape() {',
            '  pg_nom=$(pg_libelle "$1")',
            '  pg_note=""',
            '  if [ -n "${3:-}" ]; then pg_note="  (${3})"; fi',
            '  case "$2" in',
            '    encours)',
            '      PG_EN_COURS="$1"',
            '      pg_journal "DEBUT   $pg_nom"',
            '      pg_dire "' . __('Étape', 'printgestion') . ' $(pg_rang "$1")/$PG_TOTAL — $pg_nom"',
            '      ;;',
            '    ok)',
            '      pg_journal "OK      $pg_nom$pg_note"',
            '      if [ "$pg_gui" != zenity ]; then printf "[ OK ] %s%s\\n" "$pg_nom" "$pg_note"; fi',
            '      ;;',
            '    echec)',
            '      pg_journal "ECHEC   $pg_nom$pg_note"',
            '      if [ "$pg_gui" != zenity ]; then printf "[ECHEC] %s%s\\n" "$pg_nom" "$pg_note"; fi',
            '      ;;',
            '    *)',
            '      pg_journal "SAUTE   $pg_nom$pg_note"',
            '      if [ "$pg_gui" != zenity ]; then printf "[ -- ] %s%s\\n" "$pg_nom" "$pg_note"; fi',
            '      ;;',
            '  esac',
            '  if [ "$2" != encours ] && [ "$PG_EN_COURS" = "$1" ]; then PG_EN_COURS=""; fi',
            '}',
            '',
            '# Le resultat : dernier texte de la fenetre, qui passe a 100 % et propose OK. Garde aussi dans $PG_ETAT,',
            '# parce que le travail tourne dans un sous-shell (celui qui alimente la fenetre).',
            'PG_ETAT=$(mktemp 2>/dev/null || printf "%s" "/tmp/printgestion-etat-$$")',
            '# Sortie sans fin donnee alors que le travail avait commence (erreur du script) : le dire, comme le piege',
            '# d erreur de Windows, plutot qu une console qui se tait. La fenetre zenity, elle, est prevenue par pg_suivi_zen.',
            'pg_sortie() {',
            '  if [ "$PG_TRAVAIL" = 1 ] && [ ! -s "$PG_ETAT" ] && [ "$pg_gui" != zenity ]; then',
            '    pg_journal "IMPREVU script arrete avant la fin"',
            '    printf "\\n%s\\n" "$PG_MORT"',
            '  fi',
            '  rm -f "$PG_ETAT"',
            '  pg_liberer',
            '}',
            "trap 'exit 1' INT TERM HUP",
            'trap pg_sortie EXIT',
            '# Le travail dans la fenetre : dans son propre sous-shell, pour qu une mort du script laisse la main a ce',
            '# qui suit — la fin « Interrompu », et 100 % qui rend le bouton OK. Sans quoi la fenetre restait ouverte',
            '# sur une etape, sans rien dire.',
            'pg_suivi_zen() {',
            '  ( pg_travail )',
            '  if [ ! -s "$PG_ETAT" ]; then',
            '    pg_journal "IMPREVU script arrete avant la fin"',
            '    printf "# %s\\n100\\n" "$PG_MORT_Z"',
            '  fi',
            '}',
            'pg_fin() {',
            '  pg_journal "FIN     $1"',
            '  printf "%s\\n" "$1" > "$PG_ETAT"',
            '  if [ "$pg_gui" = zenity ]; then',
            '    printf "# %s   ' . __('Journal :', 'printgestion') . ' %s\\n" "$(pg_aplat "$2")" "$PG_JOURNAL"',
            '    printf "100\\n"',
            '  else',
            '    printf "\\n"',
            '    pg_lignes "$2"',
            '    printf "\\n' . __('Journal :', 'printgestion') . ' %s\\n" "$PG_JOURNAL"',
            '  fi',
            '}',
            'pg_echec() {',
            '  if [ -n "$PG_EN_COURS" ]; then pg_etape "$PG_EN_COURS" echec ""; fi',
            '  pg_journal "ERREUR  $1"',
            '  pg_fin ECHEC "$1"',
            '  exit 1',
            '}',
            '',
        ], self::buildShellLockLines([
            '  if [ "$pg_gui" = zenity ]; then pg_zen --warning --width=480 --title="$PG_TITRE" --text=' . self::shQuote(self::ALREADY_RUNNING) . ' >/dev/null 2>&1; fi',
        ]));
    }

    /** Message du deuxième lancement, sur les trois systèmes. */
    const ALREADY_RUNNING = 'Une installation ou un retrait de GLPI Agent est déjà en cours sur ce poste. Attendez la fin de la fenêtre déjà ouverte, puis relancez si besoin.';

    /**
     * Linux et macOS : une seule exécution à la fois, installation et retrait confondus.
     *
     * Un dossier verrou — mkdir est atomique — qui porte le numéro du processus. flock n'existe pas sur macOS. Un
     * verrou laissé par un lancement interrompu (terminal fermé, poste éteint) est repris : son processus n'existe
     * plus. /var/run est vidé au démarrage, sur les deux systèmes. Libéré par le trap EXIT de l'interface.
     *
     * @param string[] $gui_lines lignes qui montrent le message dans une fenêtre, en plus de la console
     */
    private static function buildShellLockLines(array $gui_lines): array {
        return array_merge([
            '# ── Une seule execution a la fois sur ce poste, installation et retrait confondus ──',
            'PG_VERROU=/var/run/printgestion-glpi-agent.lock',
            'pg_verrou=0',
            'pg_liberer() { if [ "$pg_verrou" = 1 ]; then rm -rf "$PG_VERROU"; pg_verrou=0; fi; }',
            'pg_prendre() {',
            '  if mkdir "$PG_VERROU" 2>/dev/null; then printf "%s\\n" "$$" > "$PG_VERROU/pid"; pg_verrou=1; return 0; fi',
            '  pg_autre=$(cat "$PG_VERROU/pid" 2>/dev/null)',
            '  # Pris a l instant par l autre lancement : son numero arrive.',
            '  if [ -z "$pg_autre" ]; then sleep 2; pg_autre=$(cat "$PG_VERROU/pid" 2>/dev/null); fi',
            '  if [ -n "$pg_autre" ] && kill -0 "$pg_autre" 2>/dev/null; then return 1; fi',
            '  pg_journal "Verrou laisse par un lancement interrompu (processus ${pg_autre:-inconnu}) : repris"',
            '  rm -rf "$PG_VERROU"',
            '  if mkdir "$PG_VERROU" 2>/dev/null; then printf "%s\\n" "$$" > "$PG_VERROU/pid"; pg_verrou=1; return 0; fi',
            '  return 1',
            '}',
            'if ! pg_prendre; then',
            '  pg_journal "Deja en cours sur ce poste (processus ${pg_autre:-inconnu}) : rien n a ete fait"',
            '  printf "\\n%s\\n\\n" ' . self::shQuote(self::ALREADY_RUNNING),
        ], $gui_lines, [
            '  exit 1',
            'fi',
            '',
        ]);
    }

    /** La fenêtre Cocoa des fichiers macOS, relative à la racine du plugin. */
    const MACOS_WINDOW_JS = 'resources/macos-fenetre.js';

    /**
     * La fenêtre macOS, prête à être écrite par le script : ses textes, puis son code.
     *
     * Le code vit dans un fichier JavaScript à part entière (resources/macos-fenetre.js), relu par node --check ; le
     * fichier de l'entité le recopie, précédé d'une ligne « var T = {...}; ». Fichier absent : aucune ligne, osascript
     * échoue, et le script reprend en console — jamais d'installation empêchée par la fenêtre.
     */
    private static function buildMacosWindowJsLines(array $texts): array {
        $code = @file_get_contents(dirname(__DIR__) . '/' . self::MACOS_WINDOW_JS);
        if (!is_string($code) || $code === '') {
            return [];
        }
        return array_merge(
            ['var T = ' . json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';'],
            explode("\n", rtrim(str_replace("\r\n", "\n", $code), "\n"))
        );
    }

    /**
     * Interface des fichiers macOS : la fenêtre Cocoa, ou la console quand elle ne peut pas s'ouvrir.
     *
     * Le script tourne en root, la fenêtre sous le compte de la personne connectée (celle qui a tapé sudo, ou celle qui
     * a la session à l'écran) : root n'a pas le droit d'afficher sur sa session. Ils se parlent par deux fichiers dans
     * un dossier privé, au propriétaire de la session et fermé aux autres : « reponses » écrit par la fenêtre, « etat »
     * écrit ici. En partant, le script attend que la fenêtre soit fermée : elle doit avoir lu la fin avant que son
     * dossier disparaisse.
     *
     * @param array $texts textes de la fenêtre (voir resources/macos-fenetre.js)
     */
    private static function buildMacosUiLines(string $title, array $infos, array $steps, array $texts): array {
        return array_merge([
            'PG_TITRE=' . self::shQuote($title),
            'PG_INFOS=' . self::shQuote(implode("\n", $infos)),
            'pg_gui=""',
            'PG_DIR=""',
            'PG_FENETRE=""',
            'PG_SENTINELLE=""',
            'PG_PID=$$',
            'PG_MORT=' . self::shQuote((string) ($texts['mort'] ?? '')),
            '# La personne connectee : celle qui a tape sudo, ou a defaut celle qui a la session a l ecran.',
            'pg_user="${SUDO_USER:-}"',
            'if [ -z "$pg_user" ] || [ "$pg_user" = root ]; then pg_user=$(stat -f %Su /dev/console 2>/dev/null); fi',
            '',
        ], self::buildShellStepNames($steps), [
            '# « ¶ » marque un retour a la ligne dans un message.',
            'pg_lignes() { printf "%s\\n" "$1" | awk \'{ gsub("¶", "\\n"); print }\'; }',
            '# Une ligne pour la fenetre : elle relit ce fichier en continu.',
            'pg_ecrire() {',
            '  if [ "$pg_gui" = cocoa ]; then printf "%s\\n" "$1" >> "$PG_DIR/etat"; fi',
            '}',
            'pg_dire() {',
            '  pg_ecrire "dire|$1"',
            '  if [ "$pg_gui" != cocoa ]; then printf "   %s\\n" "$1"; fi',
            '}',
            'pg_pct() { pg_ecrire "pct|$1"; }',
            'pg_detail() { pg_ecrire "note|$1"; }',
            'pg_etape() {',
            '  pg_nom=$(pg_libelle "$1")',
            '  pg_note=""',
            '  if [ -n "${3:-}" ]; then pg_note="  (${3})"; fi',
            '  pg_ecrire "etape|$1|$2|${3:-}"',
            '  case "$2" in',
            '    encours)',
            '      PG_EN_COURS="$1"',
            '      pg_ecrire "note|"',
            '      pg_journal "DEBUT   $pg_nom"',
            '      pg_dire "' . __('Étape', 'printgestion') . ' $(pg_rang "$1")/$PG_TOTAL — $pg_nom"',
            '      ;;',
            '    ok)',
            '      pg_journal "OK      $pg_nom$pg_note"',
            '      if [ "$pg_gui" != cocoa ]; then printf "[ OK ] %s%s\\n" "$pg_nom" "$pg_note"; fi',
            '      ;;',
            '    echec)',
            '      pg_journal "ECHEC   $pg_nom$pg_note"',
            '      if [ "$pg_gui" != cocoa ]; then printf "[ECHEC] %s%s\\n" "$pg_nom" "$pg_note"; fi',
            '      ;;',
            '    *)',
            '      pg_journal "SAUTE   $pg_nom$pg_note"',
            '      if [ "$pg_gui" != cocoa ]; then printf "[ -- ] %s%s\\n" "$pg_nom" "$pg_note"; fi',
            '      ;;',
            '  esac',
            '  if [ "$2" != encours ] && [ "$PG_EN_COURS" = "$1" ]; then PG_EN_COURS=""; fi',
            '}',
            '',
            'PG_ETAT=$(mktemp 2>/dev/null || printf "%s" "/tmp/printgestion-etat-$$")',
            '# En partant : la fenetre doit avoir lu la fin avant que son dossier disparaisse. On attend qu on la ferme.',
            'pg_sortie() {',
            '  # Sortie sans fin donnee (erreur du script, Ctrl-C) alors que la fenetre vit encore : elle l apprend,',
            '  # comme le piege d erreur de Windows — sinon elle attendrait sans aucun bouton pour la fermer.',
            '  if [ "$pg_gui" = cocoa ] && [ ! -s "$PG_ETAT" ] && [ -n "$PG_FENETRE" ] && kill -0 "$PG_FENETRE" 2>/dev/null; then',
            '    pg_journal "IMPREVU script arrete avant la fin"',
            '    pg_ecrire "fin|ECHEC|$PG_MORT"',
            '  fi',
            '  if [ -n "$PG_SENTINELLE" ]; then kill "$PG_SENTINELLE" 2>/dev/null; fi',
            '  if [ -n "$PG_FENETRE" ]; then wait "$PG_FENETRE" 2>/dev/null; fi',
            '  rm -f "$PG_ETAT"',
            '  if [ -n "$PG_DIR" ]; then rm -rf "$PG_DIR"; fi',
            '  pg_liberer',
            '}',
            '# Ctrl-C, fermeture du terminal, arret demande : on passe par la sortie ordinaire, qui previent la fenetre.',
            "trap 'exit 1' INT TERM HUP",
            'trap pg_sortie EXIT',
            'pg_fin() {',
            '  pg_journal "FIN     $1"',
            '  printf "%s\\n" "$1" > "$PG_ETAT"',
            '  pg_ecrire "fin|$1|$2"',
            '  if [ "$pg_gui" != cocoa ]; then',
            '    printf "\\n"',
            '    pg_lignes "$2"',
            '    printf "\\n' . __('Journal :', 'printgestion') . ' %s\\n" "$PG_JOURNAL"',
            '  fi',
            '}',
            'pg_echec() {',
            '  if [ -n "$PG_EN_COURS" ]; then pg_etape "$PG_EN_COURS" echec ""; fi',
            '  pg_journal "ERREUR  $1"',
            '  pg_fin ECHEC "$1"',
            '  exit 1',
            '}',
            '',
            '# Ouvre la fenetre et attend sa reponse : 0 si elle a repondu, 1 si elle n a pas pu s ouvrir.',
            '# Une fenetre qui ne s ouvre pas (session SSH, personne a l ecran) ne vaut jamais une annulation.',
            '# « --console » : les questions dans le terminal meme si le Mac a un ecran — pour verifier ce chemin, ou en SSH.',
            'PG_CONSOLE=0',
            'for pg_arg in "$@"; do case "$pg_arg" in --console) PG_CONSOLE=1 ;; esac; done',
            'pg_fenetre() {',
            '  if [ "$PG_CONSOLE" = 1 ]; then pg_journal "Option --console : questions dans le terminal"; return 1; fi',
            '  if [ -z "$pg_user" ] || [ "$pg_user" = root ] || ! command -v osascript >/dev/null 2>&1; then return 1; fi',
            '  pg_uid=$(id -u "$pg_user" 2>/dev/null) || return 1',
            '  PG_DIR=$(mktemp -d /tmp/printgestion.XXXXXX) || return 1',
            '  chown "$pg_user" "$PG_DIR" && chmod 700 "$PG_DIR"',
            '  : > "$PG_DIR/etat"',
            '  chmod 644 "$PG_DIR/etat"',
            "  cat > \"\$PG_DIR/fenetre.js\" <<'PRINTGESTION_JS'",
        ], self::buildMacosWindowJsLines($texts), [
            'PRINTGESTION_JS',
            '  chmod 644 "$PG_DIR/fenetre.js"',
            '  # Sous le compte de la personne connectee, dans sa session : launchctl asuser, puis sudo -u.',
            '  launchctl asuser "$pg_uid" sudo -u "$pg_user" osascript -l JavaScript "$PG_DIR/fenetre.js" "$PG_DIR" "$PG_JOURNAL" >/dev/null 2>&1 &',
            '  PG_FENETRE=$!',
            '  printf "%s\\n" ' . self::shQuote(__('La fenêtre est ouverte : c\'est elle qui suit chaque étape. Ce terminal attend qu\'on la ferme.', 'printgestion')),
            '  while [ ! -f "$PG_DIR/reponses" ]; do',
            '    if ! kill -0 "$PG_FENETRE" 2>/dev/null; then',
            '      PG_FENETRE=""',
            '      return 1',
            '    fi',
            '    sleep 1',
            '  done',
            '  pg_gui=cocoa',
            '  # Sentinelle : si ce script disparait sans avoir donne la fin (arret force, fermeture du terminal), elle',
            '  # l ecrit a la fenetre, qui montre alors « Interrompu » avec son bouton Fermer. Tache de fond : elle',
            '  # ignore Ctrl-C et le raccrochage, et bat toutes les deux secondes pour que la fenetre sache qu elle vit.',
            '  (',
            '    trap "" INT HUP',
            '    while kill -0 "$PG_PID" 2>/dev/null; do',
            '      date +%s > "$PG_DIR/vivant" 2>/dev/null',
            '      sleep 2',
            '    done',
            '    if [ ! -s "$PG_ETAT" ] && kill -0 "$PG_FENETRE" 2>/dev/null; then',
            '      pg_journal "IMPREVU script disparu avant la fin (sentinelle)"',
            '      printf "%s\\n" "fin|ECHEC|$PG_MORT" >> "$PG_DIR/etat" 2>/dev/null',
            '    fi',
            '  ) &',
            '  PG_SENTINELLE=$!',
            '  return 0',
            '}',
            '',
        ], self::buildShellLockLines([
            '  # Aussi a l ecran de la personne connectee, au cas ou ce terminal serait cache derriere la fenetre.',
            '  if [ -n "$pg_user" ] && [ "$pg_user" != root ] && command -v osascript >/dev/null 2>&1; then',
            '    launchctl asuser "$(id -u "$pg_user")" sudo -u "$pg_user" osascript -e "on run argv" -e "display alert (item 1 of argv) message (item 2 of argv) as critical" -e "end run" "$PG_TITRE" ' . self::shQuote(self::ALREADY_RUNNING) . ' >/dev/null 2>&1',
            '  fi',
        ]));
    }

    /**
     * Dossier de configuration de l'agent et relance de son service, sous Linux : ce dont la ToolBox a besoin.
     */
    private static function buildLinuxServiceLines(): array {
        return [
            'PG_CONFDIR=/etc/glpi-agent',
            '# Les plugins de l agent se lisent au demarrage : systemd d abord, l ancien « service » sinon.',
            'pg_relancer_agent() {',
            '  if command -v systemctl >/dev/null 2>&1 && systemctl restart glpi-agent >> "$PG_JOURNAL" 2>&1; then return 0; fi',
            '  if command -v service >/dev/null 2>&1 && service glpi-agent restart >> "$PG_JOURNAL" 2>&1; then return 0; fi',
            '  return 1',
            '}',
            '',
        ];
    }

    /**
     * Même chose sous macOS. bootout/bootstrap sur macOS 13 et plus, unload/load sur macOS 12 et avant : on essaie le
     * moderne, puis l'ancien. Sert aussi à l'installation, qui relance l'agent pour qu'il relise local.cfg.
     */
    private static function buildMacosServiceLines(string $plist): array {
        return [
            'PG_CONFDIR=/Applications/GLPI-Agent/etc',
            'PG_PLIST=' . self::shQuote($plist),
            'pg_relancer_agent() {',
            '  launchctl bootout system "$PG_PLIST" >> "$PG_JOURNAL" 2>&1',
            '  if launchctl bootstrap system "$PG_PLIST" >> "$PG_JOURNAL" 2>&1; then return 0; fi',
            '  launchctl unload "$PG_PLIST" >> "$PG_JOURNAL" 2>&1',
            '  launchctl load "$PG_PLIST" >> "$PG_JOURNAL" 2>&1',
            '}',
            '',
        ];
    }

    /**
     * Compte rendu à GLPI, et ce que le poste fait de la réponse.
     *
     * RUN vaut pour les deux systèmes : un appel HTTP sur 127.0.0.1. SCAN aussi : la ToolBox native de l'agent existe
     * sur Linux comme sur Mac. Le script appelant définit $PG_CONFDIR (dossier de configuration de l'agent) et
     * pg_relancer_agent (relance du service, propre à chaque système).
     *
     * Deux variables en sortent, lues par le message final : $pg_decouverte et $pg_scan_local (1 ou 0), plus la
     * réponse brute $pg_reponse (NOAGENT : GLPI ne connaît pas encore la sonde).
     */
    private static function buildShellReportLines(string $report, string $pose): array {
        if ($report === '') {
            return ['pg_etape declaration saute ""', 'pg_etape decouverte saute ""', 'pg_reponse=""', 'pg_decouverte=0', 'pg_scan_local=0', ''];
        }
        return array_merge([
            '# ── Compte rendu : ce qui a ete fait sur ce poste, et ce que GLPI en fait ──',
            'pg_etape declaration encours ""',
            'pg_fait=0',
            'if [ ' . $pose . ' = oui ]; then pg_fait=1; fi',
            '# Espaces et retours a la ligne en virgules : la valeur voyage dans une URL.',
            'pg_ips_url=$(printf "%s" "${pg_ips:-}" | tr "\\n " ",,")',
            'pg_journal "        poste declare a GLPI : $(pg_poste)"',
            'pg_rendu=' . self::shQuote(PluginPrintgestionAgenttoken::getReportURL($report)) . '"&maj=$pg_fait&pc=$(pg_url "$(pg_poste)")&ips=$(pg_url "$pg_ips_url")&snmp=$(pg_url "${pg_snmp:-}")&freq=${pg_freq:-}&mode=${pg_mode:-glpi}"',
            '# Deux minutes : GLPI cree ici le raccordement (plage, identifiants, taches), ce qui prend parfois plus de',
            '# vingt secondes. Un delai trop court disait « GLPI n a pas recu le compte rendu » alors qu il l avait recu.',
            'pg_reponse=$(pg_http "$pg_rendu" 120)',
            'pg_rc=$?',
            'pg_decouverte=0',
            'pg_scan_local=0',
            '# La reponse, jamais l URL : elle porte la cle et la communaute SNMP.',
            'if [ -n "$pg_reponse" ]; then pg_journal "        reponse de GLPI : $pg_reponse"; else pg_journal "        reponse de GLPI : (vide, code $pg_rc)"; fi',
            'if [ "$pg_rc" -ne 0 ]; then',
            '  pg_etape declaration echec ' . self::shQuote(__('GLPI n\'a pas reçu le compte rendu', 'printgestion')),
            'elif [ "$pg_reponse" = NOAGENT ]; then',
            '  pg_etape declaration echec ' . self::shQuote(__('la sonde n\'est pas encore connue de GLPI', 'printgestion')),
            'elif [ "${pg_reponse#ERREUR }" != "$pg_reponse" ]; then',
            '  # GLPI a refuse le raccordement et dit pourquoi.',
            '  pg_etape declaration echec "${pg_reponse#ERREUR }"',
            'else',
            '  pg_etape declaration ok ""',
            'fi',
            '',
            '# Suivi : le serveur dit combien d imprimantes la sonde vient de faire entrer, et leurs noms. Chaque',
            '# appel fait aussi avancer le raccordement cote serveur : sans lui, rien ne bouge tant que personne',
            '# n ouvre son ecran dans GLPI, et le technicien repart sans savoir.',
            'pg_noms=""',
            'pg_niveaux=0',
            'pg_suivre() {',
            '  pg_i=0',
            '  pg_reveille=0',
            '  while [ "$pg_i" -lt ' . self::WATCH_TRIES . ' ]; do',
            '    sleep ' . self::WATCH_WAIT,
            '    pg_r=$(pg_http "$1" 20)',
            '    case "$pg_r" in',
            '      "TROUVE "*)',
            '        pg_noms=$(printf "%s" "$pg_r" | tail -n +2 | tr "\\n" ",")',
            '        pg_niveaux=$(printf "%s" "$pg_r" | head -n 1 | cut -d" " -f3)',
            '        case "$pg_niveaux" in ""|*[!0-9]*) pg_niveaux=0 ;; esac',
            '        # Toutes, pas la premiere : sur un parc de dix, annoncer « niveaux releves » des la premiere',
            '        # serait faux neuf fois sur dix. Comme sous Windows, on attend le releve de chaque imprimante.',
            '        pg_nb_suivi=$(printf "%s" "$pg_noms" | tr "," "\\n" | grep -c .)',
            '        if [ "$pg_nb_suivi" -gt 0 ] && [ "$pg_niveaux" -ge "$pg_nb_suivi" ]; then return 0; fi',
            '        # Les imprimantes sont la, les niveaux pas encore : GLPI ne pousse rien, c est l agent qui',
            '        # vient chercher son travail. Ce script tourne sur le poste : on lui redemande un passage.',
            '        if [ "$pg_reveille" = 0 ]; then',
            '          pg_journal "        releve des niveaux : agent local rappele"',
            '          pg_http ' . self::shQuote(self::getAgentWakeUrl()) . ' >/dev/null',
            '          pg_reveille=1',
            '        fi',
            '        ;;',
            '      AUCUNE) pg_noms=""; pg_niveaux=0; return 0 ;;',
            '    esac',
            '    pg_i=$((pg_i + 1))',
            '  done',
            '  if [ -n "${pg_noms:-}" ]; then return 0; fi',
            '  return 1',
            '}',
            '',
            '# ── Decouverte ──',
            'case "$pg_reponse" in',
            '  RUN|"RUN "*)',
            '    # L interface locale de l agent est toujours ouverte sur le poste, et ce script y tourne : c est le',
            '    # geste du bouton « Force an Inventory », fait par le script.',
            '    pg_etape decouverte encours ""',
            '    pg_dire ' . self::shQuote(__('Lancement de la découverte des imprimantes...', 'printgestion')),
            '    pg_essai=0',
            '    while [ "$pg_essai" -lt ' . self::WAKE_TRIES . ' ]; do',
            '      if pg_http ' . self::shQuote(self::getAgentWakeUrl()) . ' >/dev/null; then pg_decouverte=1; break; fi',
            '      pg_essai=$((pg_essai + 1))',
            '      sleep ' . self::WAKE_WAIT,
            '    done',
            '    pg_suivi=$(printf "%s" "$pg_reponse" | cut -d" " -f2)',
            '    if [ "$pg_decouverte" != 1 ]; then',
            '      pg_etape decouverte echec ' . self::shQuote(__('armée dans GLPI, elle partira au prochain appel de l\'agent', 'printgestion')),
            '    elif [ -z "$pg_suivi" ]; then',
            '      pg_etape decouverte ok ""',
            '    else',
            '      pg_dire ' . self::shQuote(self::watchTexts()['cours']),
            '      pg_detail ' . self::shQuote(__('Quelques minutes au plus.', 'printgestion')),
            '      if pg_suivre "$pg_suivi"; then',
            '        if [ -n "$pg_noms" ]; then',
            '          pg_journal "        imprimantes : $pg_noms"',
            '          pg_compte_noms=$(printf "%s" "$pg_noms" | tr "," "\\n" | grep -c .)',
            '          # Toutes les imprimantes, pas la premiere : sinon « niveaux releves » mentirait sur un parc.',
            '          if [ "${pg_niveaux:-0}" -ge "$pg_compte_noms" ]; then',
            '            pg_suite=' . self::shQuote(self::watchTexts()['niveaux']),
            '          elif [ "${pg_niveaux:-0}" -gt 0 ]; then',
            '            pg_suite="${pg_niveaux} ' . self::watchTexts()['sur'] . ' ${pg_compte_noms} — ' . self::watchTexts()['reste'] . '"',
            '          else',
            '            pg_suite=' . self::shQuote(self::watchTexts()['plus_tard']),
            '          fi',
            '          pg_etape decouverte ok "$pg_compte_noms ' . __('imprimante(s) trouvée(s) et ajoutée(s) dans GLPI', 'printgestion') . ', $pg_suite"',
            '        else',
            '          pg_etape decouverte ok ' . self::shQuote(self::watchTexts()['aucune']),
            '        fi',
            '      else',
            '        pg_etape decouverte ok ' . self::shQuote(self::watchTexts()['attente']),
            '      fi',
            '    fi',
            '    ;;',
        ], [
            '  "SCAN "*)',
            '    # GLPI Inventory manque au serveur : c est la ToolBox native de l agent qui scanne — plage, identifiant,',
            '    # tache planifiee, resultats envoyes a server0. Tout reste visible et modifiable dans 127.0.0.1:62354/toolbox.',
            '    pg_etape decouverte encours ""',
            '    pg_dire ' . self::shQuote(__('Configuration de la ToolBox de l\'agent...', 'printgestion')),
            '    pg_first=$(printf "%s" "$pg_reponse" | cut -d" " -f2)',
            '    pg_last=$(printf "%s" "$pg_reponse" | cut -d" " -f3)',
            '    pg_delai=$(printf "%s" "$pg_reponse" | cut -d" " -f4)',
            '    # Cinquieme mot : l adresse de suivi, pour dire ensuite ce que la ToolBox a trouve.',
            '    pg_suivi=$(printf "%s" "$pg_reponse" | cut -d" " -f5)',
            '    # Chaine YAML entre apostrophes : une apostrophe s y double. Ecrite ensuite par printf, jamais par sed.',
            '    pg_c=$(printf "%s" "${pg_snmp:-public}" | sed "s/\'/\'\'/g")',
            '    if [ -d "$PG_CONFDIR" ]; then',
            '      {',
        ], array_map(
            static fn(string $ligne): string => '        printf "%s\\n" "' . strtr($ligne, [
                '@FIRST@'     => '${pg_first}',
                '@LAST@'      => '${pg_last}',
                '@DELAY@'     => '${pg_delai}',
                '@COMMUNITY@' => '${pg_c}',
            ]) . '"',
            PluginPrintgestionAgentsetting::buildToolboxYaml()
        ), [
            '      } > "$PG_CONFDIR/toolbox.yaml"',
            '      # La communaute SNMP y est en clair : root seulement.',
            '      chmod 600 "$PG_CONFDIR/toolbox.yaml"',
            '      {',
        ], array_map(
            static fn(string $ligne): string => '        printf "%s\\n" ' . self::shQuote($ligne),
            PluginPrintgestionAgentsetting::buildToolboxPluginConfig()
        ), [
            '      } > "$PG_CONFDIR/toolbox-plugin.local"',
            '      chmod 644 "$PG_CONFDIR/toolbox-plugin.local"',
            '      pg_journal "        ToolBox : $PG_CONFDIR/toolbox.yaml   plage $pg_first - $pg_last   cadence $pg_delai"',
            '      # Les plugins de l agent se lisent au demarrage : on relance le service.',
            '      if pg_relancer_agent; then',
            '        pg_scan_local=1',
            '        # Le scan tout de suite : le geste du bouton « Run task » de la ToolBox, en local, sans rien',
            '        # afficher. Le service vient de redemarrer : on lui laisse le temps, et on reessaie.',
            '        pg_lance=0',
            '        pg_i=0',
            '        while [ "$pg_i" -lt 6 ] && [ "$pg_lance" = 0 ]; do',
            '          sleep 5',
            '          if pg_poster ' . self::shQuote(self::getToolboxJobsUrl()) . ' ' . self::shQuote(self::getToolboxRunNowBody()) . '; then pg_lance=1; fi',
            '          pg_i=$((pg_i + 1))',
            '        done',
            '        if [ "$pg_lance" = 1 ]; then',
            '          pg_journal "        scan demande a la ToolBox tout de suite (bouton Run task)"',
            '        else',
            '          pg_journal "        ToolBox injoignable : le scan partira a sa cadence"',
            '        fi',
            '        if [ -n "${pg_suivi:-}" ]; then',
            '          pg_dire ' . self::shQuote(self::watchTexts()['cours']),
            '          pg_detail ' . self::shQuote(__('Quelques minutes au plus.', 'printgestion')),
            '          pg_suivre "$pg_suivi" || true',
            '        fi',
            '        if [ -n "$pg_noms" ]; then',
            '          pg_journal "        imprimantes : $pg_noms"',
            '          pg_compte_noms=$(printf "%s" "$pg_noms" | tr "," "\\n" | grep -c .)',
            '          # Toutes les imprimantes, pas la premiere : sinon « niveaux releves » mentirait sur un parc.',
            '          if [ "${pg_niveaux:-0}" -ge "$pg_compte_noms" ]; then',
            '            pg_suite=' . self::shQuote(self::watchTexts()['niveaux']),
            '          elif [ "${pg_niveaux:-0}" -gt 0 ]; then',
            '            pg_suite="${pg_niveaux} ' . self::watchTexts()['sur'] . ' ${pg_compte_noms} — ' . self::watchTexts()['reste'] . '"',
            '          else',
            '            pg_suite=' . self::shQuote(self::watchTexts()['plus_tard']),
            '          fi',
            '          pg_etape decouverte ok "$pg_compte_noms ' . __('imprimante(s) trouvée(s) et ajoutée(s) dans GLPI', 'printgestion') . ', $pg_suite"',
            '        else',
            '          pg_etape decouverte ok ' . self::shQuote(__('ToolBox de l\'agent : 127.0.0.1:62354/toolbox', 'printgestion')),
            '        fi',
            '      else',
            '        pg_etape decouverte echec ' . self::shQuote(__('ToolBox configurée, mais le service n\'a pas redémarré : redémarrer le poste', 'printgestion')),
            '      fi',
            '    else',
            '      pg_journal "        dossier de configuration de l agent introuvable : $PG_CONFDIR"',
            '      pg_etape decouverte echec ' . self::shQuote(__('ToolBox non configurée, voir le journal', 'printgestion')),
            '    fi',
            '    ;;',
        ], [
            '  *)',
            '    if [ -z "${pg_ips:-}" ]; then',
            '      pg_etape decouverte saute ' . self::shQuote(__('aucune adresse saisie', 'printgestion')),
            '    else',
            '      pg_etape decouverte saute ' . self::shQuote(__('rien à lancer', 'printgestion')),
            '    fi',
            '    ;;',
            'esac',
            '',
        ]);
    }

    // ── Fichier unique Windows ────────────────────────────────────────────────

    /**
     * Marqueur de la partie PowerShell du fichier unique. La ligne qui l'extrait l'écrit en deux morceaux collés,
     * sinon elle se trouverait elle-même : le premier marqueur rencontré doit être le vrai.
     */
    const SINGLE_FILE_MARKER = '#PG-POWERSHELL';

    /** Largeur des fenêtres Windows et hauteur de leur bandeau : les mêmes pour l'installation et le retrait. */
    const WIN_WIDTH = 600;
    const WIN_BAND  = 76;

    /** Nombre de tentatives de réveil local, et l'attente entre deux : le service vient d'être installé. */
    const WAKE_TRIES = 12;
    const WAKE_WAIT  = 5;
    /**
     * Suivi de la découverte : un appel toutes les dix secondes, dix minutes au plus — et l'attente s'arrête
     * d'elle-même dès que tout est remonté, ce n'est donc pas dix minutes de perdues.
     *
     * Mesuré chez un client : la ToolBox de l'agent a mis 5 min 30 à lancer son premier scan après la relance du
     * service. À six minutes, on repartait avec « rien trouvé » vingt secondes avant que tout arrive.
     */
    const WATCH_TRIES = 60;
    const WATCH_WAIT  = 10;

    /** Ce que la fenêtre dit de la découverte, selon ce que le serveur a répondu. */
    private static function watchTexts(): array {
        return [
            'cours'   => __('Recherche des imprimantes sur le réseau...', 'printgestion'),
            'trouve'  => __('imprimante(s) trouvée(s) et ajoutée(s) dans GLPI', 'printgestion'),
            'niveaux' => __('niveaux relevés', 'printgestion'),
            'plus_tard' => __('niveaux au prochain passage de la sonde', 'printgestion'),
            // Compte partiel : « 7 niveaux relevés sur 10 — les autres au prochain passage de la sonde ».
            'sur'     => __('niveaux relevés sur', 'printgestion'),
            'reste'   => __('les autres au prochain passage de la sonde', 'printgestion'),
            'aucune'  => __('aucune imprimante n\'a répondu sur ces adresses', 'printgestion'),
            'attente' => __('lancée ; le résultat s\'affichera dans GLPI', 'printgestion'),
            'liste'   => __('Imprimantes trouvées et ajoutées :', 'printgestion'),
            // Le détail (niveaux, modèle, contrat) est dans GLPI : la fenêtre donne le compte et les noms, et dit
            // où regarder le reste. Une fenêtre d'installation n'est pas un écran d'inventaire.
            'detail'  => __('Le détail complet est dans GLPI : fiche de l\'entité, onglet « Déploiement Agent ».', 'printgestion'),
        ];
    }



    /**
     * Interface locale de l'agent, sur le poste lui-même.
     *
     * C'est l'adresse du bouton « Force an Inventory » de l'agent. Depuis le serveur elle est inatteignable
     * derrière la box d'un client ; depuis le poste, elle est toujours ouverte — et le fichier d'installation,
     * lui, tourne sur le poste. D'où ce raccourci : plus rien à attendre une fois la découverte armée.
     */
    public static function getAgentWakeUrl(): string {
        return 'http://127.0.0.1:' . Agent::DEFAULT_PORT . '/now';
    }

    /**
     * Clés « système » des anciennes archives dans l'URL de téléchargement. Les trois boutons servent un fichier
     * unique ; les archives restent le recours quand l'antivirus d'un client refuse les scripts, atteignables depuis
     * le panneau « Contenu des paquets ».
     */
    const ARCHIVE_OS = ['windows' => 'windows-zip', 'linux' => 'linux-targz', 'macos' => 'macos-zip'];

    /**
     * Clés des fichiers de retrait. Poser une sonde prend un clic ; la retirer demandait de savoir ce que le plugin
     * avait posé et où — donc un fichier par système, au même endroit que ceux qui installent.
     */
    const REMOVE_OS = ['windows' => 'windows-retrait', 'linux' => 'linux-retrait', 'macos' => 'macos-retrait'];

    /**
     * Système du poste qui consulte l'écran, pour mettre son bouton en avant parmi les trois.
     *
     * Lu dans l'en-tête du navigateur : c'est une commodité d'affichage, jamais une décision. Les trois fichiers
     * restent téléchargeables, et un système mal reconnu ne coûte qu'un bouton moins voyant.
     *
     * Les mobiles retombent sur Windows : on n'installe pas une sonde depuis un téléphone, et l'en-tête d'un
     * iPhone contient « like Mac OS X » — sans ce garde-fou, il mettrait macOS en avant.
     *
     * @return string windows | linux | macos
     */
    public static function getVisitorPlatform(): string {
        $navigateur = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (preg_match('/(Android|iPhone|iPad|iPod|Windows Phone)/i', $navigateur) === 1) {
            return 'windows';
        }
        if (preg_match('/(Macintosh|Mac OS X)/i', $navigateur) === 1) {
            return 'macos';
        }
        if (preg_match('/(Linux|X11|CrOS|BSD)/i', $navigateur) === 1) {
            return 'linux';
        }
        return 'windows';
    }

    /** Interface locale de l'agent : son état (« waiting » quand il ne fait rien). */
    public static function getAgentStatusUrl(): string {
        return 'http://127.0.0.1:' . Agent::DEFAULT_PORT . '/status';
    }

    /** Page des tâches de la ToolBox de l'agent, sur le poste lui-même. */
    public static function getToolboxJobsUrl(): string {
        return 'http://127.0.0.1:' . Agent::DEFAULT_PORT . '/toolbox/inventory';
    }

    /**
     * Le formulaire du bouton « Run task » de la ToolBox : lancer notre tâche de scan tout de suite.
     *
     * Sans lui, on dépend de la minuterie de la ToolBox. Le code de l'agent annonce un premier passage « dans la
     * minute » pour une tâche jamais lancée ; mesuré chez un client, il est parti au bout de 5 min 30, puis de
     * 14 minutes — le technicien était reparti depuis longtemps. Ce formulaire, lui, appelle netscan()
     * immédiatement, puis reprogramme la cadence normale : c'est exactement le bouton de l'interface.
     *
     * Les noms de champs portent une barre oblique (« submit/run-now ») : elle est encodée comme le ferait un
     * navigateur, sans quoi certaines versions ne reconnaissent pas le champ.
     */
    public static function getToolboxRunNowBody(): string {
        return 'submit%2Frun-now=1&checkbox%2F'
            . rawurlencode(PluginPrintgestionAgentsetting::TOOLBOX_NAME . '-imprimantes') . '=on';
    }

    /**
     * Journal d'une exécution sous Windows, et les deux outils dont toutes les étapes se servent.
     *
     * Un fichier par exécution, dans %TEMP%\PrintGestion : chaque étape horodatée, avec ce qui sert à comprendre un
     * échec (codes de retour, tailles, empreintes, réponses de GLPI). La communauté SNMP et la clé de téléchargement
     * n'y sont jamais écrites : ce sont des secrets, et ce fichier traîne dans un dossier temporaire.
     *
     * Le dossier temporaire et non %ProgramData%\PrintGestion : le retrait efface ce dernier, et c'est justement
     * après un retrait qu'on voudra relire ce qui s'est passé.
     *
     * @param string[] $header premières lignes du journal : ce qu'on fait, pour qui
     */
    private static function buildWindowsJournalLines(string $log_name, array $header): array {
        return array_merge([
            '# Journal : un fichier par execution, dans le dossier temporaire de ce PC.',
            '$script:Journal = Join-Path $env:TEMP ("PrintGestion\\' . $log_name . '-" + (Get-Date -Format "yyyyMMdd-HHmmss") + ".log")',
            'try { New-Item -ItemType Directory -Force -Path (Split-Path -Parent $script:Journal) | Out-Null } catch { }',
            'function Journal($texte) {',
            '  try { Add-Content -LiteralPath $script:Journal -Value ((Get-Date -Format "yyyy-MM-dd HH:mm:ss") + "  " + $texte) -Encoding UTF8 } catch { }',
            '}',
            '# Attente qui laisse la fenetre se redessiner : Start-Sleep seul la figerait.',
            'function Attendre($secondes) {',
            '  $fin = (Get-Date).AddSeconds($secondes)',
            '  while ((Get-Date) -lt $fin) {',
            '    [System.Windows.Forms.Application]::DoEvents()',
            '    Start-Sleep -Milliseconds 100',
            '  }',
            '}',
            '# Le script temporaire s efface : PowerShell l a deja lu en entier, et il porte une cle a usage unique.',
            'try { if ($PSCommandPath) { Remove-Item -LiteralPath $PSCommandPath -Force -ErrorAction SilentlyContinue } } catch { }',
        ], array_map(static fn(string $ligne): string => 'Journal ' . self::psQuote($ligne), $header), [
            'Journal ("PC : " + $env:COMPUTERNAME + "   compte : " + $env:USERNAME)',
            '',
            '# Met en mots les quatre nombres du serveur, apres ses deux premiers mots : « COMPTE OK 1 10 1 6 »',
            '# avant de supprimer, « PURGE TOTAL 1 10 1 6 » apres. Meme phrase, pour que le technicien reconnaisse',
            '# ce qu on lui avait annonce.',
            'function Liste($reponse) {',
            '  $n = @($reponse -split " ")',
            '  if ($n.Count -lt 6) { return "" }',
            '  return $n[2] + " " + ' . self::psQuote(self::purgeCountWords()['sondes'])
                . ' + ", " + $n[3] + " " + ' . self::psQuote(self::purgeCountWords()['imprimantes'])
                . ' + ", " + $n[4] + " " + ' . self::psQuote(self::purgeCountWords()['ordinateurs'])
                . ' + ", " + $n[5] + " " + ' . self::psQuote(self::purgeCountWords()['collecte']),
            '}',
            'function Compte($reponse) {',
            '  $l = Liste $reponse',
            '  if ($l -eq "") { return "" }',
            '  return ' . self::psQuote(self::purgeCountWords()['tete']) . ' + " " + $l',
            '}',
            '# Une seule execution a la fois sur ce PC, installation et retrait confondus : un double clic lancait deux',
            '# fenetres qui installaient ou retiraient en meme temps. Windows libere ce verrou a la fin du processus,',
            '# meme tue : jamais de verrou fantome.',
            '$script:Verrou = New-Object System.Threading.Mutex($false, "Global\\PrintGestion-GLPI-Agent")',
            '$pris = $false',
            'try { $pris = $script:Verrou.WaitOne(0) } catch [System.Threading.AbandonedMutexException] { $pris = $true }',
            'if (-not $pris) {',
            '  Journal "Deja en cours sur ce PC : rien n a ete fait"',
            '  # Une boite ordinaire : mesuree a l ecran et au premier plan, meme devant la fenetre deja ouverte, qui',
            '  # est pourtant TopMost. L option DefaultDesktopOnly, elle, l ouvrait sur un autre bureau : invisible.',
            '  try { [void][System.Windows.Forms.MessageBox]::Show(' . self::psQuote(self::ALREADY_RUNNING) . ', "Print Gestion", "OK", "Warning") } catch { }',
            '  exit 0',
            '}',
            '',
        ]);
    }

    /**
     * Pages 2 et 3 de la fenêtre unique Windows : les étapes, puis le résultat.
     *
     * Une seule fenêtre du début à la fin. La page des réglages s'efface, la liste des étapes prend sa place et se
     * coche au fil de l'eau, puis le résultat s'affiche au même endroit, avec « Ouvrir le journal » et « Fermer ».
     * Plus de boîte de message ni de seconde fenêtre : on ne se demande jamais laquelle regarder.
     *
     * Les libellés viennent de PHP, la mécanique est en PowerShell : une boucle crée une ligne par étape.
     *
     * @param array $steps clé => libellé, dans l'ordre où elles s'exécutent
     */
    private static function buildWindowsWizardLines(array $steps): array {
        $large   = self::WIN_WIDTH;
        $couleur = static fn(int $r, int $v, int $b) => sprintf('[System.Drawing.Color]::FromArgb(%d, %d, %d)', $r, $v, $b);
        $liste   = implode(', ', array_map(
            static fn(string $cle, string $libelle): string => '@(' . self::psQuote($cle) . ', ' . self::psQuote($libelle) . ')',
            array_keys($steps),
            array_values($steps)
        ));
        return [
            '# Page des etapes, cachee tant que le technicien n a pas valide la premiere page.',
            '$gras = New-Object System.Drawing.Font("Segoe UI", 9, [System.Drawing.FontStyle]::Bold)',
            '$normal = New-Object System.Drawing.Font("Segoe UI", 9)',
            '$page = New-Object System.Windows.Forms.Panel',
            '$page.Location = New-Object System.Drawing.Point(0, $bandeau.Bottom)',
            '$page.Size = New-Object System.Drawing.Size(' . $large . ', 40)',
            '$page.BackColor = [System.Drawing.Color]::White',
            '$page.Visible = $false',
            '$f.Controls.Add($page)',
            '',
            '$etape = New-Object System.Windows.Forms.Label',
            '$etape.Font = New-Object System.Drawing.Font("Segoe UI", 11, [System.Drawing.FontStyle]::Bold)',
            '$etape.ForeColor = ' . $couleur(31, 58, 95),
            '$etape.Location = New-Object System.Drawing.Point(24, 20)',
            '$etape.Size = New-Object System.Drawing.Size(' . ($large - 48) . ', 26)',
            '$page.Controls.Add($etape)',
            '$suite = $etape.Bottom + 10',
            '',
            '# Une ligne par etape : un point tant qu elle attend, puis une fleche, une coche, une croix ou un tiret.',
            '$lignes = @{}',
            '$libelles = @{}',
            'foreach ($e in @(' . $liste . ')) {',
            '  $l = New-Object System.Windows.Forms.Label',
            '  $l.Text = [string][char]0x00B7 + "   " + $e[1]',
            '  $l.ForeColor = ' . $couleur(150, 157, 168),
            '  $l.Font = $normal',
            '  $l.AutoSize = $true',
            '  $l.Location = New-Object System.Drawing.Point(32, $suite)',
            '  $page.Controls.Add($l)',
            '  $lignes[$e[0]] = $l',
            '  $libelles[$e[0]] = $e[1]',
            '  $suite = $l.Bottom + 6',
            '}',
            '',
            '$barre = New-Object System.Windows.Forms.ProgressBar',
            '$barre.Location = New-Object System.Drawing.Point(24, ($suite + 12))',
            '$barre.Size = New-Object System.Drawing.Size(' . ($large - 48) . ', 18)',
            '$barre.Style = "Continuous"',
            '$barre.Minimum = 0',
            '$barre.Maximum = 100',
            '$page.Controls.Add($barre)',
            '',
            '$detail = New-Object System.Windows.Forms.Label',
            '$detail.ForeColor = ' . $couleur(90, 98, 110),
            '$detail.Location = New-Object System.Drawing.Point(24, ($barre.Bottom + 6))',
            '$detail.Size = New-Object System.Drawing.Size(' . ($large - 48) . ', 20)',
            '$page.Controls.Add($detail)',
            '',
            '# Le resultat prend la place de la barre, a la fin.',
            '$resultat = New-Object System.Windows.Forms.Label',
            '$resultat.MaximumSize = New-Object System.Drawing.Size(' . ($large - 48) . ', 0)',
            '$resultat.AutoSize = $true',
            '$resultat.Location = New-Object System.Drawing.Point(24, $barre.Top)',
            '$resultat.Visible = $false',
            '$page.Controls.Add($resultat)',
            '$page.Size = New-Object System.Drawing.Size(' . $large . ', ($detail.Bottom + 20))',
            '',
            '$pied2 = New-Object System.Windows.Forms.Panel',
            '$pied2.Size = New-Object System.Drawing.Size(' . $large . ', 64)',
            '$pied2.BackColor = ' . $couleur(241, 243, 246),
            '$pied2.Visible = $false',
            '$f.Controls.Add($pied2)',
            '',
            '$ouvrir = New-Object System.Windows.Forms.Button',
            '$ouvrir.Text = ' . self::psQuote(__('Ouvrir le journal', 'printgestion')),
            '$ouvrir.Location = New-Object System.Drawing.Point(24, 16)',
            '$ouvrir.Size = New-Object System.Drawing.Size(160, 32)',
            '# Le Bloc-notes passerait derriere une fenetre toujours au premier plan.',
            '$ouvrir.Add_Click({ $f.TopMost = $false; Start-Process notepad.exe -ArgumentList ([string][char]34 + $script:Journal + [string][char]34) })',
            '$pied2.Controls.Add($ouvrir)',
            '',
            '$fermer = New-Object System.Windows.Forms.Button',
            '$fermer.Text = ' . self::psQuote(__('Fermer', 'printgestion')),
            '$fermer.Location = New-Object System.Drawing.Point(' . ($large - 24 - 120) . ', 16)',
            '$fermer.Size = New-Object System.Drawing.Size(120, 32)',
            '$fermer.Add_Click({ $script:occupe = $false; $f.Close() })',
            '$pied2.Controls.Add($fermer)',
            '',
            '$script:en_cours = ""',
            'function Etape($cle, $etat, $note) {',
            '  if (-not $lignes.ContainsKey($cle)) { return }',
            '  $l = $lignes[$cle]',
            '  $nom = $libelles[$cle]',
            '  $suffixe = ""',
            '  if ($note) { $suffixe = "  (" + $note + ")" }',
            '  if ($etat -eq "encours") {',
            '    $l.Text = [string][char]0x25B6 + "   " + $nom',
            '    $l.ForeColor = ' . $couleur(31, 58, 95),
            '    $l.Font = $gras',
            '    $script:en_cours = $cle',
            '    Journal ("DEBUT   " + $nom)',
            '  } else {',
            '    if ($etat -eq "ok") {',
            '      $marque = 0x2713',
            '      $teinte = ' . $couleur(22, 128, 60),
            '      $mot = "OK      "',
            '    } elseif ($etat -eq "echec") {',
            '      $marque = 0x2717',
            '      $teinte = ' . $couleur(190, 30, 45),
            '      $mot = "ECHEC   "',
            '    } else {',
            '      $marque = 0x2013',
            '      $teinte = ' . $couleur(120, 128, 140),
            '      $mot = "SAUTE   "',
            '    }',
            '    $l.Text = [string][char]$marque + "   " + $nom + $suffixe',
            '    $l.ForeColor = $teinte',
            '    $l.Font = $normal',
            '    if ($script:en_cours -eq $cle) { $script:en_cours = "" }',
            '    Journal ($mot + $nom + $suffixe)',
            '  }',
            '  $f.Refresh()',
            '  [System.Windows.Forms.Application]::DoEvents()',
            '}',
            '',
            'function Avancement($texte, $pourcent, $note) {',
            '  $etape.Text = $texte',
            '  $detail.Text = $note',
            '  if ($pourcent -lt 0) {',
            '    if ($barre.Style -ne "Marquee") {',
            '      $barre.Style = "Marquee"',
            '      $barre.MarqueeAnimationSpeed = 30',
            '    }',
            '  } else {',
            '    if ($barre.Style -ne "Continuous") { $barre.Style = "Continuous" }',
            '    $barre.Value = [Math]::Max(0, [Math]::Min(100, [int]$pourcent))',
            '  }',
            '  $f.Refresh()',
            '  [System.Windows.Forms.Application]::DoEvents()',
            '}',
            '',
            '# La premiere page s efface, la liste des etapes prend sa place : c est la meme fenetre qui continue.',
            'function PageEtapes() {',
            '  foreach ($c in @($f.Controls)) {',
            '    if (-not [object]::ReferenceEquals($c, $bandeau) -and -not [object]::ReferenceEquals($c, $page) -and -not [object]::ReferenceEquals($c, $pied2)) { $c.Visible = $false }',
            '  }',
            '  $page.Visible = $true',
            '  $f.ClientSize = New-Object System.Drawing.Size(' . $large . ', $page.Bottom)',
            '  $f.Refresh()',
            '  [System.Windows.Forms.Application]::DoEvents()',
            '}',
            '',
            '# Le resultat, au meme endroit que les etapes, puis la fenetre attend qu on la ferme.',
            'function Fin($reussi, $texte) {',
            '  $script:occupe = $false',
            '  if (-not $page.Visible) { PageEtapes }',
            '  $barre.Visible = $false',
            '  $detail.Visible = $false',
            '  if ($reussi) {',
            '    $etape.Text = ' . self::psQuote(__('Terminé', 'printgestion')),
            '    $etape.ForeColor = ' . $couleur(22, 128, 60),
            '  } else {',
            '    $etape.Text = ' . self::psQuote(__('Interrompu', 'printgestion')),
            '    $etape.ForeColor = ' . $couleur(190, 30, 45),
            '  }',
            '  $resultat.Text = $texte + [Environment]::NewLine + [Environment]::NewLine + ' . self::psQuote(__('Journal :', 'printgestion')) . ' + " " + $script:Journal',
            '  $resultat.Visible = $true',
            '  $page.Size = New-Object System.Drawing.Size(' . $large . ', ($resultat.Bottom + 20))',
            '  $pied2.Location = New-Object System.Drawing.Point(0, $page.Bottom)',
            '  $pied2.Visible = $true',
            '  $f.ClientSize = New-Object System.Drawing.Size(' . $large . ', $pied2.Bottom)',
            '  $f.AcceptButton = $fermer',
            '  $f.CancelButton = $fermer',
            '  Journal ("FIN     " + $etape.Text)',
            '  # Garde-fou : une fenetre jamais montree laisserait tourner un processus invisible.',
            '  if (-not $f.Visible) { $f.Show(); $f.Hide(); $f.Show() }',
            '  $f.Activate()',
            '  while (-not $script:ferme) {',
            '    [System.Windows.Forms.Application]::DoEvents()',
            '    Start-Sleep -Milliseconds 50',
            '  }',
            '}',
            '# A partir d ici la fenetre sait afficher un echec : le piege passe par elle.',
            '$script:fenetre_prete = $true',
            '',
        ];
    }

    /**
     * La première page attend son bouton, sans fermer la fenêtre : c'est la même qui continue, page suivante.
     *
     * Pendant le travail, la croix ne ferme rien — un agent à moitié installé est pire qu'une minute d'attente.
     */
    private static function buildWindowsChoiceLines(string $cancelled): array {
        return [
            '$script:choix = ""',
            '$script:occupe = $false',
            '$script:ferme = $false',
            '$ok.DialogResult = [System.Windows.Forms.DialogResult]::None',
            '$non.DialogResult = [System.Windows.Forms.DialogResult]::None',
            '$ok.Add_Click({ $script:choix = "ok" })',
            '$non.Add_Click({ $script:choix = "annule"; $f.Close() })',
            '$f.Add_FormClosing({ param($s, $e) if ($script:occupe) { $e.Cancel = $true } elseif ($script:choix -eq "") { $script:choix = "annule" } })',
            '$f.Add_FormClosed({ $script:ferme = $true })',
            '# PowerShell est lance masque (-WindowStyle Hidden) : Windows applique ce masque au premier affichage d une',
            '# fenetre du processus, la notre. Elle existait sans etre a l ecran, et attendait un clic impossible.',
            '# Le deuxieme affichage, lui, est respecte : d ou Show, Hide, Show.',
            '$f.Show(); $f.Hide(); $f.Show()',
            '$f.Activate()',
            'while ($script:choix -eq "") {',
            '  [System.Windows.Forms.Application]::DoEvents()',
            '  Start-Sleep -Milliseconds 50',
            '}',
            'if ($script:choix -ne "ok") {',
            '  Journal ' . self::psQuote($cancelled),
            '  if (-not $script:ferme) { $f.Close() }',
            '  exit 0',
            '}',
            '$script:occupe = $true',
            '',
        ];
    }

    /**
     * Première page d'une fenêtre qui confirme un geste (le retrait) : une phrase, et deux boutons.
     *
     * Aucun bouton par défaut sur Entrée : on ne retire pas un agent parce qu'on a appuyé sur une touche.
     */
    private static function buildWindowsConfirmLines(string $message, string $action, string $cancel, bool $glpi = false): array {
        $large   = self::WIN_WIDTH;
        $couleur = static fn(int $r, int $v, int $b) => sprintf('[System.Drawing.Color]::FromArgb(%d, %d, %d)', $r, $v, $b);
        return array_merge([
            '$message = New-Object System.Windows.Forms.Label',
            '$message.Text = ' . self::psQuote($message),
            '$message.MaximumSize = New-Object System.Drawing.Size(' . ($large - 52) . ', 0)',
            '$message.AutoSize = $true',
            '$message.Location = New-Object System.Drawing.Point(26, $suite)',
            '$f.Controls.Add($message)',
            '$suite = $message.Bottom',
            '',
        ], !$glpi ? ['$script:niveau = 0', ''] : array_merge([
            '# Trois choix exclusifs pour GLPI, le premier coche : rien n est supprime sans qu on l ait choisi.',
            '# Des boutons radio d un meme conteneur s excluent tout seuls sous Windows Forms.',
            '$groupe = New-Object System.Windows.Forms.Panel',
            '$groupe.Location = New-Object System.Drawing.Point(24, ($suite + 12))',
            '$groupe.Size = New-Object System.Drawing.Size(' . ($large - 48) . ', 116)',
            '$script:niveau = 0',
        ], self::buildWindowsRadioLines(self::purgeChoices()), [
            '$f.Controls.Add($groupe)',
            '$suite = $groupe.Bottom',
            '',
        ]), [
            '$pied = New-Object System.Windows.Forms.Panel',
            '$pied.Location = New-Object System.Drawing.Point(0, ($suite + 18))',
            '$pied.Size = New-Object System.Drawing.Size(' . $large . ', 64)',
            '$pied.BackColor = ' . $couleur(241, 243, 246),
            '$f.Controls.Add($pied)',
            '',
            '$ok = New-Object System.Windows.Forms.Button',
            '$ok.Text = ' . self::psQuote($action),
            '$ok.Location = New-Object System.Drawing.Point(' . ($large - 24 - 120 - 12 - 170) . ', 16)',
            '$ok.Size = New-Object System.Drawing.Size(170, 32)',
            '$pied.Controls.Add($ok)',
            '',
            '$non = New-Object System.Windows.Forms.Button',
            '$non.Text = ' . self::psQuote($cancel),
            '$non.Location = New-Object System.Drawing.Point(' . ($large - 24 - 120) . ', 16)',
            '$non.Size = New-Object System.Drawing.Size(120, 32)',
            '$pied.Controls.Add($non)',
            '$f.CancelButton = $non',
            '',
            '$f.ClientSize = New-Object System.Drawing.Size(' . $large . ', $pied.Bottom)',
            '',
        ]);
    }

    /**
     * Les boutons radio du choix de suppression : un par rang, chacun retenant son rang dans $script:niveau.
     *
     * @param string[] $choices libellés, du moins au plus fort
     */
    private static function buildWindowsRadioLines(array $choices): array {
        $large = self::WIN_WIDTH;
        return [
            // Les libellés viennent de PHP, la mécanique est en PowerShell : une boucle crée un bouton par choix,
            // comme la liste des étapes. Des boutons radio d'un même conteneur s'excluent tout seuls.
            '$choix = @(' . implode(', ', array_map(static fn(string $label): string => self::psQuote($label), $choices)) . ')',
            '$rang = 0',
            'foreach ($libelle in $choix) {',
            '  $r = New-Object System.Windows.Forms.RadioButton',
            '  $r.Text = $libelle',
            '  $r.Location = New-Object System.Drawing.Point(0, (38 * $rang))',
            '  $r.Size = New-Object System.Drawing.Size(' . ($large - 56) . ', 36)',
            '  $r.Checked = ($rang -eq 0)',
            '  $r.Tag = $rang',
            '  $r.Add_CheckedChanged({ param($s, $e) if ($s.Checked) { $script:niveau = [int]$s.Tag } })',
            '  $groupe.Controls.Add($r)',
            '  $rang++',
            '}',
        ];
    }

    /**
     * Moitié cmd des fichiers Windows : vérifier les droits, écrire la partie PowerShell à côté, la lancer SANS
     * console, puis se fermer.
     *
     * La console n'est pas cachée après coup : PowerShell est lancé d'emblée sans fenêtre (Start-Process
     * -WindowStyle Hidden). La cacher depuis le script ne marche pas sous Windows 11 quand le Terminal Windows est
     * l'hôte par défaut : la fenêtre à cacher n'est alors plus celle que l'on croit. Reste l'éclair d'une seconde de
     * la console de cmd — le prix d'un .bat, dont les deux autres formes sont pires (.ps1 ouvert dans le Bloc-notes,
     * .exe non signé arrêté par SmartScreen).
     *
     * Plus aucun « pause » après le lancement : avec une console invisible, il bloquerait tout sans que personne le
     * voie. Les échecs s'affichent dans la fenêtre. Seul le défaut de droits reste en console : il survient avant
     * qu'aucune fenêtre n'existe.
     *
     * @param string[] $comments en-tête du fichier, sans « rem »
     */
    private static function buildWindowsLauncherLines(string $title, array $comments, string $tmp_name): array {
        $marker = self::SINGLE_FILE_MARKER;
        $half   = (int) ceil(strlen($marker) / 2);
        $seek   = '\'' . substr($marker, 0, $half) . '\' + \'' . substr($marker, $half) . '\'';
        // cmd exécute < > | & même dans un « rem » ou un « title » : un nom d'entité comme « Root entity > EASI
        // SUPPORT » créait un fichier parasite à chaque lancement. ^ et % sont ses caractères d'échappement.
        $sur = static fn(string $texte): string => strtr($texte, ['<' => '-', '>' => '-', '|' => '-', '&' => '+', '^' => '', '%' => '']);
        return array_merge(
            ['@echo off'],
            array_map(static fn(string $ligne): string => 'rem ' . $sur($ligne), $comments),
            ['title ' . $sur($title)],
            PluginPrintgestionAgentsetting::buildAdminCheckLines(),
            [
                'rem La partie PowerShell de ce fichier (apres le marqueur) est ecrite a part, puis lancee.',
                'set "PGSELF=%~f0"',
                'set "PGPS=%TEMP%\\' . $tmp_name . '-%RANDOM%.ps1"',
                'powershell -NoProfile -ExecutionPolicy Bypass -Command "$m = ' . $seek . '; $t = [IO.File]::ReadAllText($env:PGSELF); $i = $t.IndexOf($m); if ($i -lt 0) { exit 1 }; [IO.File]::WriteAllText($env:PGPS, $t.Substring($i), [Text.Encoding]::UTF8)' . self::buildWindowsParseCheck() . '"',
                'if errorlevel 2 exit /b 1',
                'if not exist "%PGPS%" (',
                '  echo PowerShell est necessaire pour ce fichier.',
                '  pause',
                '  exit /b 1',
                ')',
                'rem PowerShell part sans console : seule sa fenetre reste a l ecran, et celle-ci se ferme.',
                'powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process powershell.exe -WindowStyle Hidden -ArgumentList (\'-NoProfile -ExecutionPolicy Bypass -STA -File \' + [char]34 + $env:PGPS + [char]34)"',
                'exit /b 0',
                '',
                $marker,
            ]
        );
    }

    /**
     * Suite de la commande du lanceur : analyse le script PowerShell qu'il vient d'écrire, avant de le lancer.
     *
     * Un script que PowerShell ne sait pas lire ne démarre pas du tout : aucun piège ne joue, et lancé sans console
     * il disparaissait sans un mot. Ici, la console est encore là et l'on peut parler : les erreurs vont dans un
     * journal du dossier temporaire, une boîte de message le dit, et le lanceur s'arrête (code 2).
     * Texte ASCII : cmd lit ce fichier dans la page de code de la console, un accent y serait défiguré.
     */
    private static function buildWindowsParseCheck(): string {
        return "; \$e = \$null; [void][System.Management.Automation.Language.Parser]::ParseFile(\$env:PGPS, [ref]\$null, [ref]\$e);"
            . " if (\$e.Count -gt 0) {"
            . " \$d = Join-Path \$env:TEMP 'PrintGestion'; New-Item -ItemType Directory -Force -Path \$d | Out-Null;"
            . " \$j = Join-Path \$d ('lanceur-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.log');"
            . " (\$e | ForEach-Object { 'Ligne ' + \$_.Extent.StartLineNumber + ' : ' + \$_.Message }) | Set-Content -LiteralPath \$j -Encoding UTF8;"
            . " Remove-Item -LiteralPath \$env:PGPS -Force -ErrorAction SilentlyContinue;"
            . " Add-Type -AssemblyName System.Windows.Forms;"
            . " [void][System.Windows.Forms.MessageBox]::Show('Ce fichier est abime : PowerShell ne peut pas le lire. Retelechargez-le depuis GLPI.' + [Environment]::NewLine + [Environment]::NewLine + 'Journal : ' + \$j, 'Print Gestion', 'OK', 'Error');"
            . " exit 2 }";
    }

    /**
     * Fichier unique d'installation Windows : un seul .bat, ni ZIP à extraire, ni fichier à deviner. Il ouvre la
     * fenêtre d'installation, va chercher le MSI officiel sur ce serveur GLPI avec une clé temporaire, vérifie son
     * empreinte SHA-256, l'installe, et pose la mise à jour automatique si la case était cochée.
     *
     * Pourquoi la clé plutôt que le MSI dans le fichier : 22 Mo de MSI encodés dans un script sont le motif que les
     * antivirus refusent le plus volontiers, et un .exe fabriqué ici ne serait pas signé — SmartScreen le bloquerait
     * chez le client. Le binaire reste donc celui de Teclib', et son empreinte est vérifiée avant l'installation :
     * un fichier modifié en route n'est jamais installé.
     *
     * Ce que le fichier contient : l'URL de ce serveur, le TAG du client, l'empreinte attendue et **une clé à usage
     * unique valable vingt-quatre heures**. C'est le seul livrable du plugin qui porte un secret : il se donne au
     * technicien pour l'intervention, il ne s'archive pas. Le paquet ZIP reste le chemin sans aucun secret.
     *
     * Le contenu est rendu tel quel, sans fichier temporaire : quelques kilo-octets de texte.
     *
     * @return array ['ok' => bool, 'errors' => string[], 'content', 'filename', 'version', 'tag', 'expires']
     */
    public static function buildWindowsSingleFile(Entity $entity): array {
        $blockers = self::getPackageBlockers($entity);
        if (!empty($blockers)) {
            return ['ok' => false, 'errors' => $blockers];
        }
        $installer = self::getCachedInstaller(true);
        if ($installer === null) {
            return ['ok' => false, 'errors' => [__('Installeur absent ou modifié depuis sa vérification : refaites la vérification (page « Installeur GLPI Agent »).', 'printgestion')]];
        }
        // La clé est créée maintenant, pas au téléchargement : le fichier ne vaut que par elle, et elle n'est écrite
        // nulle part ailleurs qu'ici. Si la base la refuse, aucun fichier n'est rendu — un .bat qui ne pourrait rien
        // télécharger ne rendrait service à personne.
        $token = PluginPrintgestionAgenttoken::create((int) $entity->getID(), ['windows' => $installer]);
        if ($token === null) {
            return ['ok' => false, 'errors' => [__('Clé de téléchargement non enregistrée : le fichier unique serait incapable de récupérer l\'agent (détail dans le journal printgestion). Le paquet ZIP, lui, reste disponible.', 'printgestion')]];
        }
        $tag     = trim((string) $entity->fields['tag']);
        $version = (string) $installer['version'];
        return [
            'ok'       => true,
            'errors'   => [],
            'content'  => self::buildWindowsSingleFileScript($entity, $installer, $tag, $token, (string) PluginPrintgestionAgenttoken::createReport((int) $entity->getID(), $tag, 'windows')),
            'filename' => self::getSingleFileName($tag, $version, 'windows'),
            'version'  => $version,
            'tag'      => $tag,
            'expires'  => date('Y-m-d H:i:s', time() + PluginPrintgestionAgenttoken::TTL),
        ];
    }

    /**
     * Contenu du fichier unique : un .bat dont la seconde moitié est du PowerShell.
     *
     * Deux parties dans un seul fichier, parce que l'utilisateur ne doit avoir qu'un fichier et que Windows ne lance
     * pas un .ps1 au double-clic (il l'ouvre dans le Bloc-notes). La partie cmd, en ASCII pur, ne fait que vérifier
     * les droits, écrire la partie PowerShell dans un fichier temporaire et la lancer ; elle s'arrête sur
     * « exit /b », si bien que tout ce qui suit le marqueur n'est jamais lu par cmd. Rien n'est encodé ni caché :
     * le technicien et l'antivirus peuvent lire l'intégralité de ce qui va se passer.
     */
    private static function buildWindowsSingleFileScript(Entity $entity, array $installer, string $tag, string $token, string $report): string {
        $version = (string) $installer['version'];
        $config  = PluginPrintgestionConfig::getInstance()->fields;
        $update  = (int) ($config['agent_update_default'] ?? 1) === 1;
        // Une seule version pour tout : celle que ce serveur distribue est aussi celle que les sondes visent.
        $target  = PluginPrintgestionAgentsetting::getTargetVersion();
        $expires = date('d/m/Y H:i', time() + PluginPrintgestionAgenttoken::TTL);
        $size_mb = max(1, (int) round(((int) ($installer['size'] ?? 0)) / 1048576));

        // « 12 Mo sur 22 Mo » sous la barre : la phrase se traduit, {0} et {1} sont les repères de PowerShell.
        $recus = str_replace(['@RECU@', '@TOTAL@'], ['{0:N0}', '{1:N0}'], __('@RECU@ Mo sur @TOTAL@ Mo', 'printgestion'));

        // La fenêtre dit en plus ce que « Installer » va faire — il télécharge, ce que le paquet ZIP n'a pas à faire
        // — et jusqu'à quand la clé vaut : un fichier retrouvé la semaine suivante doit s'expliquer tout seul.
        $infos = self::dialogInfoLines((string) $entity->fields['completename'], $tag, self::getServerUrl()['url']);
        $notes = [
            sprintf(__('« Installer » télécharge l\'agent officiel depuis ce serveur (%d Mo), vérifie son empreinte, puis l\'installe sans rien demander.', 'printgestion'), $size_mb),
            sprintf(__('Clé de téléchargement : une seule utilisation, jusqu\'au %s.', 'printgestion'), $expires),
        ];

        $client = (string) $entity->fields['completename'];
        $server = self::getServerUrl()['url'];

        $batch = self::buildWindowsLauncherLines('Installation de GLPI Agent ' . $version, [
            'GLPI Agent ' . $version . ' - fichier unique d installation, pre-parametre pour le TAG ' . $tag . '.',
            'Clic droit, puis Executer en tant qu administrateur. Rien a extraire, rien a saisir.',
            'Il ouvre une fenetre, telecharge l agent officiel signe depuis ce serveur GLPI, verifie son',
            'empreinte SHA-256, puis l installe. Journal de chaque etape : dossier temporaire, PrintGestion.',
            'Cle de telechargement : une seule utilisation, jusqu au ' . $expires . '. Ce fichier ne vaut plus rien ensuite.',
        ], 'printgestion-installation');

        // Les étapes, dans l'ordre où elles s'exécutent : ce sont elles que la deuxième page coche.
        $steps = [
            'telechargement' => __('Téléchargement de l\'agent officiel', 'printgestion'),
            'empreinte'      => __('Vérification de l\'empreinte', 'printgestion'),
            'installation'   => sprintf(__('Installation de GLPI Agent %s', 'printgestion'), $version),
            'maj'            => __('Mise à jour automatique', 'printgestion'),
            'contact'        => __('Premier contact avec GLPI', 'printgestion'),
            'declaration'    => __('Compte rendu à GLPI', 'printgestion'),
            'decouverte'     => __('Découverte des imprimantes', 'printgestion'),
        ];

        $ps = array_merge(
            [
                '# Installation de GLPI Agent ' . $version . ', posee par Print Gestion pour le TAG ' . $tag . '.',
                '# Lu par PowerShell seulement : cmd s arrete avant (exit /b).',
                '# Aucun mot de passe ici : une URL, un TAG, une empreinte SHA-256 et une cle a usage unique.',
            ],
            self::psFormsHeader(),
            self::buildWindowsJournalLines('installation', [
                sprintf('Installation de GLPI Agent %s, preparee par Print Gestion', $version),
                sprintf('Client : %s   TAG : %s   serveur : %s', $client, $tag, $server),
            ]),
            [
                '$Url = ' . self::psQuote(PluginPrintgestionAgenttoken::getPullURL($token)),
                '$Sha = ' . self::psQuote(strtolower((string) $installer['sha256'])),
                '$Msi = Join-Path $env:TEMP ' . self::psQuote(self::getMsiName($version)),
                '',
                '# Un echec s affiche dans la page des etapes, puis la fenetre attend qu on la ferme.',
                'function Echec($texte) {',
                '  if ($script:en_cours -ne "") { Etape $script:en_cours "echec" "" }',
                '  Journal ("ERREUR  " + ($texte -replace "\r?\n", " | "))',
                '  Fin $false $texte',
                '  exit 1',
                '}',
                '# Imprevu (droit refuse, composant absent) : « Stop » arreterait tout sans un mot. Le piege le dit.',
                'trap {',
                '  Journal ("IMPREVU " + $_)',
                '  $texte = (' . self::psQuote(__('L\'installation s\'est arrêtée sur une erreur inattendue : rien n\'est garanti installé.', 'printgestion')) . ') + [Environment]::NewLine + [Environment]::NewLine + $_',
                '  if ($script:fenetre_prete) { try { Echec $texte } catch { Secours $texte } } else { Secours $texte }',
                '  exit 1',
                '}',
                '',
            ],
            self::buildWindowsDialogLines($version, $infos, $notes, $target, $update, true),
            self::buildWindowsWizardLines($steps),
            self::buildWindowsChoiceLines(__('Installation annulée par le technicien : rien n\'a été installé.', 'printgestion')),
            [
                '$avec_maj = ($maj_imposee -or ($null -ne $maj -and $maj.Checked))',
                '# Ce que le technicien a saisi : les adresses partent au compte rendu, GLPI en fait un raccordement.',
                '$adresses = ""',
                '$communaute = ""',
                '$frequence = ""',
                'if ($null -ne $ips) { $adresses = $ips.Text.Trim() }',
                'if ($null -ne $snmp) { $communaute = $snmp.Text.Trim() }',
                '# Le code seul, avant le tiret : le libelle n est la que pour l oeil.',
                'if ($null -ne $freq -and $null -ne $freq.SelectedItem) { $frequence = $freq.SelectedItem.ToString().Split(" ")[0] }',
                'if ($adresses -eq "") { Journal "Adresses des imprimantes : aucune" } else { Journal ("Adresses des imprimantes : " + $adresses) }',
                '# Jamais la communaute elle-meme : c est un secret, et ce journal traine dans un dossier temporaire.',
                'if ($communaute -eq "") { Journal "Communaute SNMP : vide" } else { Journal "Communaute SNMP : renseignee (jamais ecrite dans ce journal)" }',
                'Journal ("Frequence des releves : " + $frequence)',
            '# Qui pilote le scan : le deuxieme choix de la liste est le mode local. Sans liste — GLPI Inventory',
            '# manque au serveur —, il n y a rien a choisir : c est local, et le journal doit le dire.',
            '$script:pilotage = ' . self::psQuote(PluginPrintgestionCollectsetup::isAvailable() ? 'glpi' : 'local'),
            'if ($null -ne $mode -and $mode.SelectedIndex -eq 1) { $script:pilotage = "local" }',
            'Journal ("Pilotage du scan : " + $script:pilotage)',
                'if ($avec_maj) { Journal "Mise a jour automatique : demandee" } else { Journal "Mise a jour automatique : non demandee" }',
                'PageEtapes',
                '',
                '# ── Telechargement ──',
                'Etape "telechargement" "encours" ""',
                'Avancement ' . self::psQuote(__('Téléchargement de l\'agent officiel...', 'printgestion')) . ' 0 ""',
                '# TLS 1.2 : un Windows plus ancien ne le choisit pas seul, et un serveur GLPI en HTTPS n accepte que lui.',
                'try { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 } catch { }',
                'if (Test-Path -LiteralPath $Msi) { Remove-Item -LiteralPath $Msi -Force -ErrorAction SilentlyContinue }',
                '# Morceau par morceau : DownloadFile ne rend la main qu a la fin, il ne sait rien dire pendant.',
                '$entree = $null',
                '$sortie = $null',
                '$recu = 0',
                'try {',
                '  $requete = [System.Net.HttpWebRequest]::Create($Url)',
                '  $requete.UserAgent = "PrintGestion"',
                '  $requete.Timeout = 60000',
                '  $reponse_http = $requete.GetResponse()',
                '  $taille = $reponse_http.ContentLength',
                '  $entree = $reponse_http.GetResponseStream()',
                '  $sortie = [System.IO.File]::Create($Msi)',
                '  $tampon = New-Object byte[] 262144',
                '  $lu = $entree.Read($tampon, 0, $tampon.Length)',
                '  while ($lu -gt 0) {',
                '    $sortie.Write($tampon, 0, $lu)',
                '    $recu += $lu',
                '    if ($taille -gt 0) {',
                '      Avancement ' . self::psQuote(__('Téléchargement de l\'agent officiel...', 'printgestion')) . ' ([int](100 * $recu / $taille)) (' . self::psQuote($recus) . ' -f ($recu / 1MB), ($taille / 1MB))',
                '    }',
                '    $lu = $entree.Read($tampon, 0, $tampon.Length)',
                '  }',
                '  $sortie.Close()',
                '  $entree.Close()',
                '  $reponse_http.Close()',
                '} catch {',
                '  try { if ($null -ne $sortie) { $sortie.Close() } } catch { }',
                '  try { if ($null -ne $entree) { $entree.Close() } } catch { }',
                '  # Le message de .NET, pas l URL : elle porte la cle de telechargement.',
                '  Journal ("        " + $_.Exception.Message)',
                '  Echec((' . self::psQuote(__('Téléchargement impossible : rien n\'a été installé. Causes habituelles : clé déjà utilisée ou expirée (régénérer le fichier dans GLPI), serveur GLPI injoignable depuis ce PC, ou certificat HTTPS inconnu de ce PC.', 'printgestion')) . ' + [Environment]::NewLine + [Environment]::NewLine + $_.Exception.Message))',
                '}',
                'Etape "telechargement" "ok" (($recu / 1MB).ToString("N1") + " Mo")',
                '',
                '# ── Empreinte : ce qui a ete telecharge doit etre exactement le MSI que GLPI a verifie ──',
                'Etape "empreinte" "encours" ""',
                'Avancement ' . self::psQuote(__('Vérification de l\'empreinte du fichier reçu...', 'printgestion')) . ' 100 ""',
                '$algo = [Security.Cryptography.SHA256]::Create()',
                '$flux = [IO.File]::OpenRead($Msi)',
                'try { $empreinte = ([BitConverter]::ToString($algo.ComputeHash($flux)) -replace "-", "").ToLower() } finally { $flux.Close() }',
                'Journal ("        attendue : " + $Sha)',
                'Journal ("        recue    : " + $empreinte)',
                'if ($empreinte -ne $Sha) {',
                '  Remove-Item -LiteralPath $Msi -Force -ErrorAction SilentlyContinue',
                '  Echec(' . self::psQuote(__('Le fichier téléchargé n\'est pas celui attendu (empreinte différente) : rien n\'a été installé. Prévenir l\'administrateur.', 'printgestion')) . ')',
                '}',
                'Etape "empreinte" "ok" ""',
                '',
                '# ── Installation ──',
                'Etape "installation" "encours" ""',
                '$arguments = ' . self::psQuote(self::buildWindowsArguments('@MSI@', $tag)),
                '$arguments = $arguments.Replace("@MSI@", $Msi).Replace("%TEMP%", $env:TEMP)',
                'Journal ("        msiexec " + $arguments)',
                '# -1 : barre defilante. L installateur ne dit pas ou il en est, et une barre arretee a 40 % qui ne',
                '# bouge plus inquiete davantage qu une barre qui defile.',
                'Avancement ' . self::psQuote(sprintf(__('Installation de GLPI Agent %s...', 'printgestion'), $version)) . ' (-1) ' . self::psQuote(__('Environ une minute. Aucune question ne sera posée.', 'printgestion')),
                '# Sans -Wait : on interroge le processus, ce qui laisse la fenetre se redessiner pendant ce temps.',
                '$p = Start-Process -FilePath "msiexec.exe" -ArgumentList $arguments -PassThru',
                'while (-not $p.HasExited) {',
                '  [System.Windows.Forms.Application]::DoEvents()',
                '  Start-Sleep -Milliseconds 150',
                '}',
                'Journal ("        code de retour : " + $p.ExitCode + "   journal du MSI : " + (Join-Path $env:TEMP "GLPI-Agent-install.log"))',
                '# 0 installe, 3010 redemarrage demande, 1641 redemarrage lance : les trois sont des succes.',
                'if (@(0, 3010, 1641) -notcontains $p.ExitCode) {',
                '  Echec(' . self::psQuote(sprintf(__('Installation non terminée (code @CODE@). Journal : %s', 'printgestion'), '@LOG@'))
                    . '.Replace("@CODE@", [string]$p.ExitCode).Replace("@LOG@", (Join-Path $env:TEMP "GLPI-Agent-install.log")))',
                '}',
                'Etape "installation" "ok" ""',
                '',
                '$decouverte = $false',
                '$scan_local = $false',
                '$maj_ratee = $false',
                '# Un poste installe avant la ToolBox garde peut-etre l ancien scan maison : retire, sinon il scannerait deux fois.',
                'Start-Process -FilePath "schtasks.exe" -ArgumentList ("/Delete /TN " + [char]34 + ' . self::psQuote(PluginPrintgestionAgentsetting::SCAN_TASK_NAME) . ' + [char]34 + " /F") -Wait -WindowStyle Hidden | Out-Null',
                'Remove-Item -LiteralPath (Join-Path $env:ProgramData "PrintGestion\glpi-scan-imprimantes.cmd") -Force -ErrorAction SilentlyContinue',
                '',
                '# ── Mise a jour automatique ──',
                'if ($avec_maj) {',
                '  Etape "maj" "encours" ""',
                '  Avancement ' . self::psQuote(__('Mise à jour automatique : pose de la tâche planifiée...', 'printgestion')) . ' (-1) ""',
                '  $dossier = Join-Path $env:ProgramData "PrintGestion"',
                '  if (-not (Test-Path -LiteralPath $dossier)) { New-Item -ItemType Directory -Path $dossier | Out-Null }',
                '  $cible = Join-Path $dossier ' . self::psQuote(PluginPrintgestionAgentsetting::UPDATE_SCRIPT),
                '  # Le script de la tache, mot pour mot celui du paquet ZIP. Le terminateur du bloc ci-dessous',
                '  # doit rester colle a la marge : PowerShell ne le reconnait qu en debut de ligne.',
                '  $script = @' . "'",
            ],
            explode("\r\n", PluginPrintgestionAgentsetting::buildUpdateScript($target)),
            [
                "'" . '@',
                '  [IO.File]::WriteAllText($cible, $script, [Text.Encoding]::ASCII)',
                '  $sched = ' . self::psQuote(PluginPrintgestionAgentsetting::buildScheduleArguments()),
                '  $tache = Start-Process -FilePath "schtasks.exe" -ArgumentList $sched.Replace("@SCRIPT@", $cible) -Wait -PassThru -WindowStyle Hidden',
                '  Journal ("        schtasks : code " + $tache.ExitCode)',
                '  if ($tache.ExitCode -eq 0) {',
                '    Etape "maj" "ok" ' . self::psQuote(__('le 1er du mois à 3 h', 'printgestion')),
                '  } else {',
                '    # Pas un echec de l installation : l agent est la et fonctionne, seule la mise a jour manque.',
                '    $avec_maj = $false',
                '    $maj_ratee = $true',
                '    Etape "maj" "echec" ' . self::psQuote(__('tâche refusée par Windows', 'printgestion')),
                '  }',
                '} else {',
                '  Etape "maj" "saute" ' . self::psQuote(__('non demandée', 'printgestion')),
                '}',
                '',
                '# ── Premier contact : GLPI doit connaitre la sonde avant qu on lui confie les imprimantes ──',
                '# Le compte rendu partait des la fin du MSI, souvent avant le premier inventaire de l agent : GLPI ne',
                '# connaissait pas encore la sonde, et ne pouvait ni regler ses modules ni lui confier les imprimantes.',
                '# On attend donc que l agent local ait fini son premier passage : son etat redevient « waiting ».',
                'Etape "contact" "encours" ""',
                'Avancement ' . self::psQuote(__('Premier contact de l\'agent avec GLPI...', 'printgestion')) . ' (-1) ' . self::psQuote(__('L\'agent envoie son premier inventaire : GLPI doit le connaître avant qu\'on lui confie les imprimantes.', 'printgestion')),
                '$statut = ""',
                '# Le service vient d etre installe : son interface met un moment a repondre (2 min au plus).',
                'for ($i = 0; $i -lt 40; $i++) {',
                '  try { $statut = (New-Object System.Net.WebClient).DownloadString(' . self::psQuote(self::getAgentStatusUrl()) . '); break } catch { Attendre 3 }',
                '}',
                'if ($statut -eq "") {',
                '  Etape "contact" "saute" ' . self::psQuote(__('l\'agent ne répond pas encore sur ce PC', 'printgestion')),
                '} else {',
                '  Journal ("        agent local : " + $statut.Trim())',
                '  # Un passage tout de suite, puis on attend qu il soit fini (3 min au plus).',
                '  try { (New-Object System.Net.WebClient).DownloadString(' . self::psQuote(self::getAgentWakeUrl()) . ') | Out-Null } catch { }',
                '  Attendre 5',
                '  $fini = $false',
                '  for ($i = 0; $i -lt 60; $i++) {',
                '    try { $statut = (New-Object System.Net.WebClient).DownloadString(' . self::psQuote(self::getAgentStatusUrl()) . '); if ($statut -match "waiting") { $fini = $true; break } } catch { }',
                '    Attendre 3',
                '  }',
                '  if ($fini) { Etape "contact" "ok" "" } else { Etape "contact" "saute" ' . self::psQuote(__('toujours en cours après trois minutes', 'printgestion')) . ' }',
                '}',
                '',
            ],
            $report === '' ? [
                'Etape "declaration" "saute" ""',
                'Etape "decouverte" "saute" ""',
                '$reponse = ""',
                '',
            ] : [
                '# ── Compte rendu : ce qui a ete fait sur ce PC, et ce que GLPI en fait ──',
                '$script:noms = $null',
                '$script:niveaux = 0',
                'Etape "declaration" "encours" ""',
                'Avancement ' . self::psQuote(__('Compte rendu à GLPI...', 'printgestion')) . ' (-1) ""',
                '$reponse = ""',
                '$declare = $false',
                'try {',
                '  $fait = "0"',
                '  if ($avec_maj) { $fait = "1" }',
                '  $rendu = ' . self::psQuote(PluginPrintgestionAgenttoken::getReportURL($report)) . ' + "&maj=" + $fait + "&pc=" + [Uri]::EscapeDataString($env:COMPUTERNAME) + "&ips=" + [Uri]::EscapeDataString($adresses) + "&snmp=" + [Uri]::EscapeDataString($communaute) + "&freq=" + [Uri]::EscapeDataString($frequence) + "&mode=" + $script:pilotage',
                '  $wc2 = New-Object System.Net.WebClient',
                '  $wc2.Headers.Add("User-Agent", "PrintGestion")',
                '  $reponse = $wc2.DownloadString($rendu)',
                '  $declare = $true',
                '} catch {',
                '  Journal ("        " + $_.Exception.Message)',
                '}',
                'if ($null -eq $reponse) { $reponse = "" }',
                '$reponse = $reponse.Trim()',
                'if ($reponse -eq "") { Journal "        reponse de GLPI : (vide)" } else { Journal ("        reponse de GLPI : " + $reponse) }',
                'if (-not $declare) {',
                '  Etape "declaration" "echec" ' . self::psQuote(__('GLPI n\'a pas reçu le compte rendu', 'printgestion')),
                '} elseif ($reponse -eq "NOAGENT") {',
                '  Etape "declaration" "echec" ' . self::psQuote(__('la sonde n\'est pas encore connue de GLPI', 'printgestion')),
                '} elseif ($reponse -like "ERREUR *") {',
                '  # GLPI a refuse le raccordement et dit pourquoi : on le montre, au lieu de laisser croire que',
                '  # tout va bien. Le detail complet reste dans le journal du raccordement, cote serveur.',
                '  Etape "declaration" "echec" ($reponse.Substring(7))',
                '} else {',
                '  Etape "declaration" "ok" ""',
                '}',
                '',
                '# Suivi : le serveur dit combien d imprimantes la sonde vient de faire entrer, et leurs noms.',
                '# Chaque appel fait aussi avancer le raccordement cote serveur — sinon rien ne bouge tant que',
                '# personne n ouvre son ecran dans GLPI, et le technicien repart sans savoir.',
                'function Suivre($url) {',
                '  $vu = $null',
                '  $reveille = $false',
                '  for ($i = 0; $i -lt ' . self::WATCH_TRIES . '; $i++) {',
                '    Attendre ' . self::WATCH_WAIT,
                '    $brut = ""',
                '    try { $brut = (New-Object System.Net.WebClient).DownloadString($url) } catch { }',
                '    if ($null -eq $brut) { $brut = "" }',
                '    $lignes = @($brut -split "\r?\n" | Where-Object { $_.Trim() -ne "" })',
                '    if ($lignes.Count -gt 0 -and $lignes[0] -eq "AUCUNE") { return @{ noms = @(); niveaux = 0 } }',
                '    if ($lignes.Count -gt 1 -and $lignes[0] -like "TROUVE *") {',
                '      $mots = @($lignes[0] -split " ")',
                '      $vu = @{ noms = @($lignes[1..($lignes.Count - 1)]); niveaux = 0 }',
                '      if ($mots.Count -gt 2) { $vu.niveaux = [int]$mots[2] }',
                '      # Toutes, pas la premiere : sur un parc de dix, annoncer « niveaux releves » des la premiere',
                '      # serait faux neuf fois sur dix.',
                '      if ($vu.niveaux -ge $vu.noms.Count) { return $vu }',
                '      # Les imprimantes sont la, les niveaux pas encore : GLPI a prepare le releve, mais il ne',
                '      # pousse rien — c est l agent qui vient le chercher. Ce script tourne sur le poste : on lui',
                '      # redemande un passage tout de suite, au lieu d attendre son rappel (jusqu a 24 h).',
                '      if (-not $reveille) {',
                '        Journal "        releve des niveaux : agent local rappele"',
                '        try { (New-Object System.Net.WebClient).DownloadString(' . self::psQuote(self::getAgentWakeUrl()) . ') | Out-Null } catch { }',
                '        $reveille = $true',
                '      }',
                '    }',
                '  }',
                '  return $vu',
                '}',
                '',
                '# ── Decouverte ──',
                '# « RUN » : GLPI a arme la decouverte. L interface locale de l agent est toujours ouverte sur ce PC, et',
                '# ce script y tourne : on lui demande de rappeler GLPI tout de suite. Le geste du bouton « Force an Inventory ».',
                'if ($reponse -like "RUN*") {',
                '  Etape "decouverte" "encours" ""',
                '  Avancement ' . self::psQuote(__('Lancement de la découverte des imprimantes...', 'printgestion')) . ' (-1) ""',
                '  $suivi = ""',
                '  $mots = @($reponse -split " ")',
                '  if ($mots.Count -gt 1) { $suivi = $mots[1] }',
                '  for ($essai = 0; $essai -lt ' . self::WAKE_TRIES . '; $essai++) {',
                '    try {',
                '      (New-Object System.Net.WebClient).DownloadString(' . self::psQuote(self::getAgentWakeUrl()) . ') | Out-Null',
                '      $decouverte = $true',
                '      break',
                '    } catch {',
                '      Attendre ' . self::WAKE_WAIT,
                '    }',
                '  }',
                '  if (-not $decouverte) {',
                '    Etape "decouverte" "echec" ' . self::psQuote(__('armée dans GLPI, elle partira au prochain appel de l\'agent', 'printgestion')),
                '  } elseif ($suivi -eq "") {',
                '    Etape "decouverte" "ok" ""',
                '  } else {',
                '    Avancement ' . self::psQuote(self::watchTexts()['cours']) . ' (-1) ' . self::psQuote(__('Quelques minutes au plus.', 'printgestion')),
                '    $vu = Suivre $suivi',
                '    $script:noms = $null',
                '    if ($null -ne $vu) { $script:noms = $vu.noms; $script:niveaux = [int]$vu.niveaux }',
                '    if ($null -eq $script:noms) {',
                '      Etape "decouverte" "ok" ' . self::psQuote(self::watchTexts()['attente']),
                '    } elseif ($script:noms.Count -eq 0) {',
                '      Etape "decouverte" "ok" ' . self::psQuote(self::watchTexts()['aucune']),
                '    } else {',
                '      Journal ("        imprimantes : " + ($script:noms -join ", ") + "   niveaux releves : " + $script:niveaux)',
                '      $suite_note = ' . self::psQuote(self::watchTexts()['plus_tard']),
                '      if ($script:niveaux -ge $script:noms.Count) {',
                '        $suite_note = ' . self::psQuote(self::watchTexts()['niveaux']),
                '      } elseif ($script:niveaux -gt 0) {',
                '        $suite_note = [string]$script:niveaux + " " + ' . self::psQuote(self::watchTexts()['sur'])
                    . ' + " " + [string]$script:noms.Count + " — " + ' . self::psQuote(self::watchTexts()['reste']),
                '      }',
                '      Etape "decouverte" "ok" ([string]$script:noms.Count + " " + ' . self::psQuote(self::watchTexts()['trouve']) . ' + ", " + $suite_note)',
                '    }',
                '  }',
                '} elseif ($reponse -like "SCAN *") {',
                '  # « SCAN premiere derniere cadence » : GLPI Inventory manque au serveur, personne ne dira a l agent de',
                '  # balayer le reseau. C est sa ToolBox native qui s en charge : plage, identifiant et tache planifiee,',
                '  # resultats envoyes a server0. Tout reste visible et modifiable dans 127.0.0.1:62354/toolbox.',
                '  Etape "decouverte" "encours" ""',
                '  Avancement ' . self::psQuote(__('Configuration de la ToolBox de l\'agent...', 'printgestion')) . ' (-1) ""',
                '  # « SCAN premiere derniere cadence adresse-de-suivi » : le dernier mot sert a suivre le resultat.',
                '  $bornes = $reponse.Split(" ", 5)',
                '  $etc = Join-Path $env:ProgramFiles "GLPI-Agent\etc"',
                '  if ($communaute -eq "") { $communaute = "public" }',
                '  # Chaine YAML entre apostrophes : une apostrophe s y double.',
                '  $yaml = (' . implode(', ', array_map(static fn(string $l): string => self::psQuote($l), PluginPrintgestionAgentsetting::buildToolboxYaml())) . ') -join [Environment]::NewLine',
                '  $yaml = $yaml.Replace("@FIRST@", $bornes[1]).Replace("@LAST@", $bornes[2]).Replace("@DELAY@", $bornes[3]).Replace("@COMMUNITY@", $communaute.Replace("\'", "\'\'"))',
                '  $activation = (' . implode(', ', array_map(static fn(string $l): string => self::psQuote($l), PluginPrintgestionAgentsetting::buildToolboxPluginConfig())) . ') -join [Environment]::NewLine',
                '  try {',
                '    if (-not (Test-Path -LiteralPath $etc)) { throw ("dossier de configuration de l agent introuvable : " + $etc) }',
                '    $sans_bom = New-Object System.Text.UTF8Encoding($false)',
                '    $cible_yaml = Join-Path $etc "toolbox.yaml"',
                '    [IO.File]::WriteAllText($cible_yaml, $yaml + [Environment]::NewLine, $sans_bom)',
                '    [IO.File]::WriteAllText((Join-Path $etc "toolbox-plugin.local"), $activation + [Environment]::NewLine, $sans_bom)',
                '    # La communaute SNMP y est en clair : SYSTEM et les administrateurs seulement (SID, pas les noms traduits).',
                '    Start-Process -FilePath "icacls.exe" -ArgumentList ([char]34 + $cible_yaml + [char]34 + " /inheritance:r /grant:r *S-1-5-18:F *S-1-5-32-544:F") -Wait -WindowStyle Hidden | Out-Null',
                '    Journal ("        ToolBox : " + $cible_yaml + "   plage " + $bornes[1] + " - " + $bornes[2] + "   cadence " + $bornes[3])',
                '    # Les plugins de l agent se lisent au demarrage. Le service est cherche par son nom affiche.',
                '    $service = Get-Service | Where-Object { $_.Name -like "glpi-agent*" -or $_.DisplayName -like "GLPI Agent*" } | Select-Object -First 1',
                '    if ($null -eq $service) { throw "service GLPI Agent introuvable" }',
                '    Restart-Service -InputObject $service -Force',
                '    Journal ("        service relance : " + $service.Name)',
                '    $scan_local = $true',
                '    # Le scan tout de suite, sans attendre la minuterie de la ToolBox : c est le geste de son',
                '    # bouton « Run task ». Un appel HTTP sur 127.0.0.1, rien ne s affiche. Le service vient de',
                '    # redemarrer : on lui laisse le temps d ouvrir son port, et on reessaie.',
                '    $lance = $false',
                '    for ($essai = 0; $essai -lt 6 -and -not $lance; $essai++) {',
                '      Attendre 5',
                '      try {',
                '        $poste = New-Object System.Net.WebClient',
                '        $poste.Headers.Add("Content-Type", "application/x-www-form-urlencoded")',
                '        [void]$poste.UploadString(' . self::psQuote(self::getToolboxJobsUrl()) . ', "POST", ' . self::psQuote(self::getToolboxRunNowBody()) . ')',
                '        $lance = $true',
                '      } catch { }',
                '    }',
                '    if ($lance) {',
                '      Journal "        scan demande a la ToolBox tout de suite (bouton Run task)"',
                '    } else {',
                '      Journal "        ToolBox injoignable : le scan partira a sa cadence"',
                '    }',
                '    if ($bornes.Count -gt 4 -and $bornes[4] -ne "") {',
                '      Avancement ' . self::psQuote(self::watchTexts()['cours']) . ' (-1) ' . self::psQuote(__('Quelques minutes au plus.', 'printgestion')),
                '      $vu = Suivre $bornes[4]',
                '      if ($null -ne $vu) { $script:noms = $vu.noms; $script:niveaux = [int]$vu.niveaux }',
                '    }',
                '    if ($null -ne $script:noms -and $script:noms.Count -gt 0) {',
                '      Journal ("        imprimantes : " + ($script:noms -join ", "))',
                '      Etape "decouverte" "ok" ([string]$script:noms.Count + " " + ' . self::psQuote(self::watchTexts()['trouve']) . ')',
                '    } else {',
                '      Etape "decouverte" "ok" ' . self::psQuote(__('ToolBox de l\'agent : 127.0.0.1:62354/toolbox', 'printgestion')),
                '    }',
                '  } catch {',
                '    Journal ("        " + $_.Exception.Message)',
                '    Etape "decouverte" "echec" ' . self::psQuote(__('ToolBox non configurée, voir le journal', 'printgestion')),
                '  }',
                '} elseif ($adresses -eq "") {',
                '  Etape "decouverte" "saute" ' . self::psQuote(__('aucune adresse saisie', 'printgestion')),
                '} else {',
                '  Etape "decouverte" "saute" ' . self::psQuote(__('rien à lancer', 'printgestion')),
                '}',
                '',
            ],
            [
                '# ── Resultat : ce qui s est reellement passe, jamais une promesse ──',
                'Journal ("MSI conserve pour une reinstallation : " + $Msi)',
                '$final = ' . self::psQuote(sprintf(__('GLPI Agent %s est installé sur ce PC.', 'printgestion'), $version)),
                'if ($avec_maj) { $final = $final + [Environment]::NewLine + ' . self::psQuote(__('Mise à jour automatique : posée (le 1er du mois à 3 h).', 'printgestion')) . ' }',
                'if ($maj_ratee) { $final = $final + [Environment]::NewLine + ' . self::psQuote(__('Mise à jour automatique : non posée (antivirus ou stratégie de groupe). L\'agent fonctionne, seule la mise à jour manque.', 'printgestion')) . ' }',
                '$final = $final + [Environment]::NewLine + [Environment]::NewLine',
                'if ($decouverte) {',
                '  $final = $final + ' . self::psQuote(__('Les imprimantes sont déclarées dans GLPI et la découverte vient de partir : rien d\'autre à faire sur ce PC. Le résultat s\'affiche dans GLPI, fiche de l\'entité, onglet « Déploiement Agent ».', 'printgestion')),
                '} elseif ($scan_local) {',
                '  $final = $final + ' . self::psQuote(__('Le scan des imprimantes est confié à la ToolBox de l\'agent, à la cadence choisie : elle envoie elle-même ses résultats à GLPI. Plage, identifiant et tâche se voient et se corrigent sur ce PC, à l\'adresse http://127.0.0.1:62354/toolbox.', 'printgestion')),
                '} elseif ($reponse -eq "NOAGENT") {',
                '  $final = $final + ' . self::psQuote(__('GLPI ne connaît pas encore cette sonde : les imprimantes n\'ont pas pu lui être confiées. Vérifier que ce PC joint le serveur GLPI, puis raccorder les imprimantes depuis la fiche de l\'entité, onglet « Déploiement Agent ».', 'printgestion')),
                '} else {',
                '  $final = $final + ' . self::psQuote(__('Dernière étape, dans GLPI : fiche de l\'entité, onglet « Déploiement Agent » — vérifier que la sonde apparaît avec un contact récent, puis raccorder les imprimantes (bloc 3).', 'printgestion')),
                '}',
                '# Les imprimantes trouvees, par leur nom : c est ce que le technicien vient verifier.',
                'if ($null -ne $script:noms -and $script:noms.Count -gt 0) {',
                '  $fin_niveaux = ' . self::psQuote(self::watchTexts()['plus_tard']),
                '  if ($script:niveaux -ge $script:noms.Count) {',
                '    $fin_niveaux = ' . self::psQuote(self::watchTexts()['niveaux']),
                '  } elseif ($script:niveaux -gt 0) {',
                '    $fin_niveaux = [string]$script:niveaux + " " + ' . self::psQuote(self::watchTexts()['sur'])
                    . ' + " " + [string]$script:noms.Count + " — " + ' . self::psQuote(self::watchTexts()['reste']),
                '  }',
                '  $final = $final + [Environment]::NewLine + [Environment]::NewLine + [string]$script:noms.Count + " " + ' . self::psQuote(self::watchTexts()['trouve']) . ' + ", " + $fin_niveaux',
                '  $final = $final + [Environment]::NewLine + ' . self::psQuote(self::watchTexts()['liste']) . ' + " " + ($script:noms -join ", ")',
                '  $final = $final + [Environment]::NewLine + ' . self::psQuote(self::watchTexts()['detail']),
                '}',
                '# Le journal de l agent lui-meme : tenu par defaut par le MSI, c est la qu on lit ce qu il a fait ensuite.',
                '$journal_agent = Join-Path $env:ProgramFiles "GLPI-Agent\logs\glpi-agent.log"',
                'Journal ("Journal de l agent : " + $journal_agent)',
                '$final = $final + [Environment]::NewLine + [Environment]::NewLine + ' . self::psQuote(__('Journal de l\'agent :', 'printgestion')) . ' + " " + $journal_agent',
                'Fin $true $final',
                'exit 0',
                '',
            ]
        );

        return implode("\r\n", array_merge($batch, $ps));
    }

    // ── Retrait d'une sonde ───────────────────────────────────────────────────

    /**
     * Fichier qui retire GLPI Agent d'un PC : notre tâche planifiée, nos fichiers, l'agent, sa configuration.
     *
     * Il rend compte à GLPI en dernier geste, comme le fichier d'installation : sans cela l'écran d'une sonde
     * continuerait d'afficher une tâche posée sur une machine qui n'a plus d'agent.
     *
     * Aucun installeur à télécharger, donc aucune clé de récupération — seulement celle du compte rendu.
     *
     * @return array ['ok' => bool, 'errors' => string[], 'content', 'filename', 'version', 'tag']
     */
    public static function buildRemovalFile(Entity $entity, string $os): array {
        if (!isset(self::REMOVE_OS[$os])) {
            return ['ok' => false, 'errors' => [__('Système inconnu : fichier de retrait non généré.', 'printgestion')]];
        }
        $tag    = trim((string) $entity->fields['tag']);
        $safe   = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $tag), '-');
        $safe   = $safe !== '' ? $safe : 'sonde';
        // Le pouvoir de supprimer dans GLPI vient du droit qui a permis de télécharger ce fichier : « Retirer une
        // sonde ». La route l'exige déjà ; on le relit ici pour qu'aucun autre appelant ne fabrique un fichier
        // plus puissant que son auteur.
        $purge  = Session::haveRight('plugin_printgestion_deploiement', PURGE);
        $report = (string) PluginPrintgestionAgenttoken::createReport((int) $entity->getID(), $tag, $os, $purge);
        $purge  = $purge && $report !== '';
        $quand  = date('Y-m-d H:i');
        $client = (string) $entity->fields['completename'];

        if ($os === 'windows') {
            return ['ok' => true, 'errors' => [], 'content' => self::buildWindowsRemovalScript($entity, $tag, $report, $purge), 'filename' => sprintf('RETIRER-GLPI-AGENT-%s.bat', $safe), 'version' => '', 'tag' => $tag];
        }

        $nom = ($os === 'macos' ? 'retirer-glpi-agent-macos-' : 'retirer-glpi-agent-') . $safe . '.sh';
        $contenu = $os === 'linux'
            ? self::buildLinuxRemovalScript($entity, $tag, $report, $nom, $purge)
            : self::buildMacosRemovalScript($entity, $tag, $report, $nom, $purge);
        return ['ok' => true, 'errors' => [], 'content' => $contenu, 'filename' => $nom, 'version' => '', 'tag' => $tag];
    }

    /**
     * Les trois choix offerts pour GLPI, dans l'ordre et du moins au plus fort. Exclusifs : on ne clique pas
     * « tout supprimer » en croyant cocher « la sonde ».
     *
     * @return string[] rang (0, 1, 2) => libellé
     */
    private static function purgeChoices(): array {
        return [
            __('Ne rien supprimer dans GLPI', 'printgestion'),
            __('Retirer la sonde de GLPI, avec ses réglages, alertes et raccordements Print Gestion', 'printgestion'),
            __('Tout supprimer de GLPI : la sonde, les imprimantes qu\'elle a fait entrer, la fiche de cet ordinateur, et les tâches et plages créées pour lui', 'printgestion'),
        ];
    }

    /**
     * Qui pilote le scan des imprimantes, et ce que ça change pour le client.
     *
     * Les deux chemins existent déjà et font le même travail sur le réseau : c'est le même agent, sur le même PC,
     * qui interroge les imprimantes en SNMP. Ce qui change est ailleurs — qui planifie, où vit la communauté SNMP,
     * et si l'on peut y revenir à distance.
     *
     * @return array [code => [libellé, explication]] ; le premier est celui par défaut
     */
    private static function pilotChoices(): array {
        return [
            'glpi'  => [
                __('Piloté par GLPI (recommandé)', 'printgestion'),
                __('Les tâches de scan se voient et se modifient dans GLPI, sans revenir sur ce PC.', 'printgestion'),
            ],
            'local' => [
                __('En local, par l\'agent de ce PC', 'printgestion'),
                __('Tout reste sur ce PC : la communauté SNMP et la planification y sont écrites, et se modifient sur place.', 'printgestion'),
            ],
        ];
    }

    /**
     * Le choix du pilotage en console (Linux et macOS), quand aucune fenêtre ne peut s'ouvrir.
     *
     * Sans GLPI Inventory sur le serveur, pas de question : une phrase, et le mode local.
     */
    private static function buildShellPilotConsoleLines(): array {
        if (!PluginPrintgestionCollectsetup::isAvailable()) {
            return [
                '  pg_mode=local',
                '  printf "%s\\n" ' . self::shQuote(self::pilotLocalOnly()),
            ];
        }
        $choix = array_values(self::pilotChoices());
        return [
            '  printf "\\n%s\\n" ' . self::shQuote(__('Qui pilote le scan des imprimantes ?', 'printgestion')),
            '  printf "  1) %s\\n" ' . self::shQuote($choix[0][0] . ' — ' . $choix[0][1]),
            '  printf "  2) %s\\n" ' . self::shQuote($choix[1][0] . ' — ' . $choix[1][1]),
            '  printf "%s " ' . self::shQuote(__('Numéro [1] :', 'printgestion')),
            '  read -r pg_num_mode',
            '  case "${pg_num_mode:-1}" in 2) pg_mode=local ;; *) pg_mode=glpi ;; esac',
        ];
    }

    /** Ligne affichée quand le serveur n'a pas GLPI Inventory : il n'y a alors qu'un seul chemin possible. */
    private static function pilotLocalOnly(): string {
        return __('GLPI Inventory n\'est pas installé sur ce serveur : le scan des imprimantes sera fait par l\'agent de ce PC, qui le planifiera lui-même.', 'printgestion');
    }

    /** Mots du compte rendu de suppression, assemblés avec les nombres renvoyés par le serveur. */
    private static function purgeCountWords(): array {
        return [
            'tete'        => __('Supprimés de GLPI :', 'printgestion'),
            'sondes'      => __('sonde(s)', 'printgestion'),
            'imprimantes' => __('imprimante(s)', 'printgestion'),
            'ordinateurs' => __('ordinateur(s)', 'printgestion'),
            'collecte'    => __('objet(s) de collecte', 'printgestion'),
        ];
    }

    /** Ce que la fenêtre dit de GLPI à la fin, selon la réponse du serveur. */
    private static function purgeEndTexts(): array {
        return [
            'OK'     => __('Dans GLPI, la sonde et ses éléments Print Gestion sont supprimés. Les imprimantes et la fiche de l\'ordinateur restent.', 'printgestion'),
            'ABSENT' => __('Dans GLPI, aucune sonde de ce nom n\'a été trouvée : rien n\'y a été supprimé.', 'printgestion'),
            'REFUSE' => __('GLPI n\'a pas supprimé la sonde : elle reste listée, et se supprime depuis la liste des sondes — case cochée, puis Actions → Supprimer.', 'printgestion'),
        ];
    }

    /** Fin de « tout supprimer » : les mots autour des nombres, le reste étant assemblé par le script. */
    private static function purgeTotalEndText(): string {
        return __('Les imprimantes relevées par une autre sonde, elles, restent.', 'printgestion');
    }

    /**
     * Les mots de la confirmation de « tout supprimer », posée avant de commencer.
     *
     * Annoncer « 10 imprimantes supprimées » après coup ne sert à rien : leurs compteurs de pages, historiques de
     * cartouches et lignes de coût sont déjà partis. Le poste demande donc à GLPI ce que ce choix emporterait, le
     * montre, et attend un oui.
     */
    private static function purgeConfirmTexts(): array {
        return [
            'titre'    => __('Tout supprimer de GLPI ?', 'printgestion'),
            'tete'     => __('Ce choix va supprimer définitivement de GLPI :', 'printgestion'),
            'rien'     => __('GLPI ne connaît aucune sonde sur ce poste : ce choix n\'y supprimerait rien.', 'printgestion'),
            'inconnu'  => __('GLPI n\'a pas répondu : impossible de dire ici combien d\'imprimantes ce choix supprimerait.', 'printgestion'),
            'fin'      => __('Les compteurs de pages, l\'historique des cartouches et les lignes de coût de ces imprimantes partent avec elles. Les expéditions et les demandes d\'envoi sont conservées.', 'printgestion'),
            'question' => __('Continuer ? « Non » arrête tout : rien ne sera retiré de ce poste, rien ne sera supprimé de GLPI.', 'printgestion'),
            'invite'   => __('Tout supprimer de GLPI ? [o/N]', 'printgestion'),
            'bouton'   => __('Tout supprimer', 'printgestion'),
            'annuler'  => __('Annuler', 'printgestion'),
            'annule'   => __('Tout supprimer non confirmé : rien n\'a été retiré de ce poste ni supprimé de GLPI.', 'printgestion'),
        ];
    }

    /**
     * Windows : la confirmation de « tout supprimer », avec les nombres que GLPI vient de donner.
     *
     * Le bouton « Non » est celui par défaut : un appui sur Entrée ne supprime pas dix imprimantes. Et une boîte
     * qui ne peut pas s'afficher ne vaut ni oui ni non — le choix coché dans la fenêtre que le technicien a vue est
     * alors conservé, comme partout ailleurs dans ce plugin : une fenêtre qui manque n'est jamais une annulation.
     */
    private static function buildWindowsPurgeConfirmLines(string $report): array {
        $t = self::purgeConfirmTexts();
        return [
            '# ── « Tout supprimer » : dire combien AVANT, pas apres. GLPI est interroge, il ne supprime rien. ──',
            '# Toutes les variables en $pg_ : le script est d un seul tenant, et $detail, par exemple, est deja',
            '# l etiquette qui porte la note sous la barre d avancement. Lui donner une chaine tuait la fenetre a',
            '# l etape suivante (« la propriete Text est introuvable dans cet objet »).',
            'if ($script:niveau -eq 2) {',
            '  $pg_compte = ""',
            '  try {',
            '    $pg_compte = ([string](New-Object System.Net.WebClient).DownloadString(' . self::psQuote(PluginPrintgestionAgenttoken::getReportURL($report)) . ' + "&q=1&pc=" + [Uri]::EscapeDataString($env:COMPUTERNAME))).Trim()',
            '  } catch { Journal ("        compte refuse ou injoignable : " + $_.Exception.Message) }',
            '  Journal ("        tout supprimer emporterait : " + $pg_compte)',
            '  $pg_detail = ' . self::psQuote($t['inconnu']),
            '  if ($pg_compte -like "COMPTE OK *") { $pg_detail = ' . self::psQuote($t['tete']) . ' + " " + (Liste $pg_compte) + "." }',
            '  elseif ($pg_compte -eq "COMPTE ABSENT") { $pg_detail = ' . self::psQuote($t['rien']) . ' }',
            '  $pg_saut = [Environment]::NewLine + [Environment]::NewLine',
            '  $pg_texte = $pg_detail + $pg_saut + ' . self::psQuote($t['fin']) . ' + $pg_saut + ' . self::psQuote($t['question']),
            '  $pg_rep = [System.Windows.Forms.DialogResult]::Yes',
            '  $pg_vue = $false',
            '  try { $pg_rep = [System.Windows.Forms.MessageBox]::Show($pg_texte, ' . self::psQuote($t['titre']) . ', "YesNo", "Warning", "Button2"); $pg_vue = $true }',
            '  catch { Journal "        confirmation non affichable : le choix du technicien est conserve" }',
            '  if ($pg_vue -and $pg_rep -ne [System.Windows.Forms.DialogResult]::Yes) {',
            '    Journal ' . self::psQuote($t['annule']),
            '    $script:occupe = $false',
            '    if (-not $script:ferme) { $f.Close() }',
            '    exit 0',
            '  }',
            '}',
            '',
        ];
    }

    /**
     * Linux et macOS : les mêmes mots, la même question, avec la fenêtre de chaque système — zenity ici, une boîte
     * Cocoa là — et la console quand aucune ne s'ouvre.
     *
     * Une fenêtre qui ne s'ouvre pas ne vaut jamais un « non » : la question se repose alors dans le terminal, et si
     * même lui ne peut pas répondre (pas d'entrée standard), le choix du technicien est conservé.
     */
    private static function buildShellPurgeConfirmLines(string $report, bool $macos): array {
        $t = self::purgeConfirmTexts();
        $fenetre = $macos ? [
            '  if [ -n "${pg_user:-}" ] && [ "$pg_user" != root ] && command -v osascript >/dev/null 2>&1; then',
            '    # Texte et libelles passent en arguments : rien a echapper dans le script AppleScript.',
            '    pg_rep=$(launchctl asuser "$(id -u "$pg_user")" sudo -u "$pg_user" osascript -e "on run argv"'
                . ' -e "display dialog (item 2 of argv) with title (item 1 of argv) buttons {(item 3 of argv), (item 4 of argv)} default button 1 with icon caution"'
                . ' -e "end run" "$PG_TITRE" "$pg_texte" "$PG_C_ANNULER" "$PG_C_TOUT" 2>/dev/null)',
            '    pg_rc=$?',
            '    case "$pg_rep" in *"$PG_C_TOUT"*) return 0 ;; esac',
            '    # Une reponse claire : le technicien a choisi d annuler. Sinon, la fenetre n a pas pu s ouvrir.',
            '    if [ "$pg_rc" = 0 ]; then return 1; fi',
            '    pg_journal "Fenetre indisponible : confirmation en console"',
            '  fi',
        ] : [
            '  if [ "$pg_gui" = zenity ]; then',
            '    pg_zen --question --width=560 --title="$PG_TITRE" --text="$pg_texte" --ok-label="$PG_C_TOUT" --cancel-label="$PG_C_ANNULER" --default-cancel >/dev/null 2>&1',
            '    pg_rc=$?',
            '    case "$pg_rc" in',
            '      0) return 0 ;;',
            '      1) return 1 ;;',
            '      *) pg_journal "Fenetre indisponible (code $pg_rc) : confirmation en console" ; pg_gui="" ;;',
            '    esac',
            '  fi',
        ];
        return array_merge([
            '# ── « Tout supprimer » : dire combien AVANT, pas apres. GLPI est interroge, il ne supprime rien. ──',
            'PG_C_TETE=' . self::shQuote($t['tete']),
            'PG_C_RIEN=' . self::shQuote($t['rien']),
            'PG_C_INCONNU=' . self::shQuote($t['inconnu']),
            'PG_C_FIN=' . self::shQuote($t['fin']),
            'PG_C_QUESTION=' . self::shQuote($t['question']),
            'PG_C_TOUT=' . self::shQuote($t['bouton']),
            'PG_C_ANNULER=' . self::shQuote($t['annuler']),
            'pg_confirmer_total() {',
            '  pg_n=$(pg_http ' . self::shQuote(PluginPrintgestionAgenttoken::getReportURL($report)) . '"&q=1&pc=$(pg_url "$(pg_poste)")" 60 | tr -d "\r\n")',
            '  pg_journal "Tout supprimer : ce que GLPI annonce — ${pg_n:-aucune reponse}"',
            '  case "$pg_n" in',
            '    "COMPTE OK "*) pg_detail="$PG_C_TETE $(pg_liste "$pg_n")." ;;',
            '    "COMPTE ABSENT") pg_detail="$PG_C_RIEN" ;;',
            '    *) pg_detail="$PG_C_INCONNU" ;;',
            '  esac',
            '  pg_texte=$(printf "%s\n\n%s\n\n%s" "$pg_detail" "$PG_C_FIN" "$PG_C_QUESTION")',
        ], $fenetre, [
            '  # Pas de fenetre, ou pas de reponse claire : la question se repose ici.',
            '  printf "\n%s\n\n" "$pg_texte"',
            '  printf "%s " ' . self::shQuote($t['invite']),
            '  if ! read -r pg_rep; then',
            '    pg_journal "Question impossible a poser : le choix du technicien est conserve"',
            '    return 0',
            '  fi',
            '  case "$pg_rep" in [oOyY]*) return 0 ;; esac',
            '  return 1',
            '}',
            'if [ "$PG_GLPI" = 2 ] && ! pg_confirmer_total; then',
            '  pg_journal ' . self::shQuote($t['annule']),
            '  printf "%s\n" ' . self::shQuote($t['annule']),
            '  exit 0',
            'fi',
            '',
        ]);
    }

    /**
     * Les quatre nombres d'une suppression mis en mots, sous Linux et macOS : avant (ce qu'elle emporterait) comme
     * après (ce qu'elle a emporté). Définies au premier niveau, car la confirmation s'en sert bien avant le travail.
     */
    private static function buildShellCountLines(): array {
        $mots = self::purgeCountWords();
        return [
            '# Met en mots les quatre nombres du serveur, apres ses deux premiers mots : « COMPTE OK 1 10 1 6 »',
            '# avant de supprimer, « PURGE TOTAL 1 10 1 6 » apres.',
            'pg_liste() {',
            '  set -- $1',
            '  if [ $# -lt 6 ]; then printf ""; return 0; fi',
            '  printf "%s %s, %s %s, %s %s, %s %s" "$3" ' . self::shQuote($mots['sondes'])
                . ' "$4" ' . self::shQuote($mots['imprimantes']) . ' "$5" ' . self::shQuote($mots['ordinateurs'])
                . ' "$6" ' . self::shQuote($mots['collecte']),
            '}',
            'pg_compte() {',
            '  pg_l=$(pg_liste "$1")',
            '  if [ -z "$pg_l" ]; then printf ""; return 0; fi',
            '  printf "%s %s" ' . self::shQuote($mots['tete']) . ' "$pg_l"',
            '}',
            '',
        ];
    }

    /** Notes de l'étape « Compte rendu », selon la réponse du serveur. */
    private static function purgeStepNotes(): array {
        return [
            'OK'     => __('sonde supprimée de GLPI', 'printgestion'),
            'ABSENT' => __('sonde introuvable dans GLPI', 'printgestion'),
            'REFUSE' => __('GLPI a refusé de supprimer la sonde', 'printgestion'),
        ];
    }

    /**
     * Compte rendu du retrait, sous Linux et macOS : il dit à GLPI que le poste n'est plus une sonde, demande la
     * suppression si elle a été choisie ($PG_GLPI), et garde la réponse dans $PG_PURGE pour la fin.
     */
    private static function buildShellRemovalReportLines(string $report): array {
        $notes = self::purgeStepNotes();
        return [
            '  # Compte rendu : ce poste n est plus une sonde. Un echec ici ne change rien au retrait.',
            '  pg_etape declaration encours ""',
            '  pg_gl=""',
            '  if [ "$PG_GLPI" != 0 ]; then pg_gl="&gl=$PG_GLPI"; pg_journal "        suppression demandee dans GLPI, niveau $PG_GLPI"; fi',
            '  PG_PURGE=""',
            '  if pg_rep=$(pg_http ' . self::shQuote(PluginPrintgestionAgenttoken::getReportURL($report)) . '"&maj=0&off=1${pg_gl}&pc=$(pg_url "$(pg_poste)")" 120); then',
            '    PG_PURGE=$(printf "%s" "$pg_rep" | tr -d "\\r\\n")',
            '    if [ -n "$PG_PURGE" ]; then pg_journal "        reponse de GLPI : $PG_PURGE"; fi',
            '    case "$PG_PURGE" in',
            '      "PURGE TOTAL "*) pg_etape declaration ok "$(pg_compte "$PG_PURGE")" ;;',
            '      "PURGE OK") pg_etape declaration ok ' . self::shQuote($notes['OK']) . ' ;;',
            '      "PURGE ABSENT") pg_etape declaration ok ' . self::shQuote($notes['ABSENT']) . ' ;;',
            '      "PURGE REFUSE") pg_etape declaration echec ' . self::shQuote($notes['REFUSE']) . ' ;;',
            '      *) pg_etape declaration ok "" ;;',
            '    esac',
            '  else',
            '    pg_etape declaration echec ' . self::shQuote(__('GLPI n\'a pas reçu le compte rendu', 'printgestion')),
            '  fi',
            '',
        ];
    }

    /** Fin du retrait sous Linux et macOS : ce que la fenêtre dit de GLPI dépend de la réponse du serveur. */
    private static function buildShellRemovalEndLines(string $done): array {
        $fins = self::purgeEndTexts();
        return [
            // La fin de « tout supprimer » porte des nombres : elle s'assemble à l'exécution. Les phrases fixes
            // passent par des variables du script, pour que les apostrophes du français ne cassent rien.
            '  PG_FAIT=' . self::shQuote($done),
            '  PG_RESTE=' . self::shQuote(self::purgeTotalEndText()),
            '  case "$PG_PURGE" in',
            '    "PURGE TOTAL "*) pg_fin OK "${PG_FAIT}¶¶$(pg_compte "$PG_PURGE"). $PG_RESTE" ;;',
            '    "PURGE OK") pg_fin OK ' . self::shQuote($done . '¶¶' . $fins['OK']) . ' ;;',
            '    "PURGE ABSENT") pg_fin OK ' . self::shQuote($done . '¶¶' . $fins['ABSENT']) . ' ;;',
            '    "PURGE REFUSE") pg_fin OK ' . self::shQuote($done . '¶¶' . $fins['REFUSE']) . ' ;;',
            '    *) pg_fin OK ' . self::shQuote($done . '¶¶' . __('Dans GLPI, la sonde reste listée : elle se supprime depuis la liste des sondes — case cochée, puis Actions → Supprimer.', 'printgestion')) . ' ;;',
            '  esac',
        ];
    }

    /**
     * Fichier de retrait Linux : une confirmation, une fenêtre d'avancement qui se termine sur le résultat, et le même
     * journal dans /var/tmp.
     *
     * La désinstallation regarde d'abord si le paquet est là (dpkg ou rpm) : « apt-get remove » d'un paquet absent rend
     * 0, « dnf remove » rend 1 — on n'affichait donc ni la même chose, ni la vérité, selon la distribution. La sortie du
     * gestionnaire de paquets part au journal au lieu de défiler dans le terminal.
     */
    private static function buildLinuxRemovalScript(Entity $entity, string $tag, string $report, string $nom, bool $purge = false): string {
        $client = (string) $entity->fields['completename'];
        $server = self::getServerUrl()['url'];
        $title  = __('Retrait de GLPI Agent', 'printgestion');
        $infos  = array_merge(self::dialogInfoLines($client, $tag, $server), [
            '',
            $purge
                ? __('Ce fichier retire de ce poste : les tâches posées par Print Gestion, GLPI Agent lui-même, et leurs fichiers. Rien d\'autre n\'est touché.', 'printgestion')
                : __('Ce fichier retire de ce poste : les tâches posées par Print Gestion, GLPI Agent lui-même, et leurs fichiers. Rien d\'autre n\'est touché. Dans GLPI, la sonde reste listée : elle se supprime ensuite depuis la liste des sondes.', 'printgestion'),
        ]);
        $steps  = [
            'taches'          => __('Retrait des tâches planifiées', 'printgestion'),
            'desinstallation' => __('Désinstallation de GLPI Agent', 'printgestion'),
            'fichiers'        => __('Retrait des fichiers', 'printgestion'),
            'declaration'     => __('Compte rendu à GLPI', 'printgestion'),
        ];
        $taches  = [
            PluginPrintgestionAgentsetting::LINUX_CRON,
            PluginPrintgestionAgentsetting::LINUX_SCAN_CRON,
            PluginPrintgestionAgentsetting::LINUX_SCAN_SCRIPT,
            // Chemin des postes installés avant le passage à cron.d.
            PluginPrintgestionAgentsetting::LINUX_SCAN_OLD,
        ];
        $fichiers = [
            '/etc/glpi-agent/conf.d/90-printgestion.cfg',
            // La ToolBox posée quand GLPI Inventory manque, et le journal de l'agent.
            '/etc/glpi-agent/toolbox.yaml',
            '/etc/glpi-agent/toolbox-plugin.local',
            self::AGENT_LOG_UNIX,
            // Les journaux des deux tâches : ce sont des fichiers du plugin, pas de l'agent.
            PluginPrintgestionAgentsetting::LINUX_LOG,
            PluginPrintgestionAgentsetting::LINUX_SCAN_LOG,
        ];

        return implode("\n", array_merge(
            [
                '#!/bin/sh',
                '# Retrait de GLPI Agent de ce poste - ' . $client . ' (TAG ' . $tag . '), genere le ' . date('Y-m-d H:i') . ' par Print Gestion.',
                '# A lancer en root : sudo sh ' . $nom . '   Journal : /var/tmp.',
                'set -u',
                'if [ "$(id -u)" -ne 0 ]; then',
                '  echo "A lancer en root : sudo sh $0"',
                '  exit 1',
                'fi',
                '',
            ],
            self::buildShellJournalLines('retrait', [
                'Retrait de GLPI Agent, prepare par Print Gestion',
                sprintf('Client : %s   TAG : %s   serveur : %s', $client, $tag, $server),
            ]),
            self::buildShellToolLines(),
            self::buildShellCountLines(),
            self::buildLinuxUiLines($title, $infos, $steps, __('Le script de retrait s\'est arrêté sans donner de résultat (erreur, fermeture du terminal ou arrêt forcé) : GLPI Agent est peut-être encore en partie sur ce poste. Voir le journal.', 'printgestion')),
            [
                '# ── Confirmation : on ne retire pas un agent parce qu on a appuye sur Entree ──',
                'if [ "$pg_gui" = zenity ]; then',
                '  pg_zen --question --width=560 --title="$PG_TITRE" --text="$PG_INFOS_Z" --ok-label=' . self::shQuote(__('Retirer GLPI Agent', 'printgestion')) . ' --cancel-label=' . self::shQuote(__('Annuler', 'printgestion')) . ' --default-cancel >/dev/null 2>&1',
                '  pg_rc=$?',
                '  case "$pg_rc" in',
                '    0) ;;',
                '    1)',
                '      pg_journal ' . self::shQuote(__('Retrait annulé par le technicien : rien n\'a été retiré.', 'printgestion')),
                '      exit 0',
                '      ;;',
                '    *)',
                '      pg_journal "Fenetre indisponible (code $pg_rc) : confirmation en console"',
                '      pg_gui=""',
                '      ;;',
                '  esac',
                'fi',
                'if [ -z "$pg_gui" ]; then',
                '  printf "%s\\n\\n%s\\n\\n" "$PG_TITRE" "$PG_INFOS"',
                '  printf "%s " ' . self::shQuote(__('Retirer GLPI Agent de ce poste ? [o/N]', 'printgestion')),
                '  read -r pg_rep',
                '  case "$pg_rep" in',
                '    [oOyY]*) ;;',
                '    *)',
                '      pg_journal ' . self::shQuote(__('Retrait annulé par le technicien : rien n\'a été retiré.', 'printgestion')),
                '      echo ' . self::shQuote(__('Retrait annulé : rien n\'a été retiré.', 'printgestion')),
                '      exit 0',
                '      ;;',
                '  esac',
                'fi',
                '',
            ],
            self::buildLinuxPurgeQuestionLines($purge),
            $purge && $report !== '' ? self::buildShellPurgeConfirmLines($report, false) : [],
            [
                'pg_travail() {',
                '  pg_pct 5',
                '  pg_etape taches encours ""',
                '  pg_retirees=0',
                '  for pg_f in ' . implode(' ', $taches) . '; do',
                '    if [ -e "$pg_f" ]; then',
                '      rm -f "$pg_f"',
                '      pg_journal "        retire : $pg_f"',
                '      pg_retirees=$((pg_retirees + 1))',
                '    fi',
                '  done',
                '  if [ "$pg_retirees" = 0 ]; then pg_etape taches ok ' . self::shQuote(__('aucune n\'était posée', 'printgestion')) . '; else pg_etape taches ok "$pg_retirees ' . __('retirée(s)', 'printgestion') . '"; fi',
                '  pg_pct 20',
                '',
                '  pg_etape desinstallation encours ""',
                '  pg_installe=0',
                '  if command -v dpkg >/dev/null 2>&1 && dpkg -s glpi-agent >/dev/null 2>&1; then pg_installe=1; fi',
                '  if command -v rpm >/dev/null 2>&1 && rpm -q glpi-agent >/dev/null 2>&1; then pg_installe=1; fi',
                '  if [ "$pg_installe" = 0 ]; then',
                '    pg_etape desinstallation saute ' . self::shQuote(__('aucun GLPI Agent installé sur ce poste', 'printgestion')),
                '  else',
                '    # Le gestionnaire de paquets : c est lui qui a installe (l installeur officiel depose un .deb ou un .rpm).',
                '    if command -v apt-get >/dev/null 2>&1; then',
                '      # « purge », pas « remove » : dpkg garderait la trace des fichiers de configuration, et une',
                '      # reinstallation ne remettrait plus agent.cfg — l agent ne demarrait plus (vu sur un poste).',
                '      pg_paquets=$(dpkg-query -W -f="\\${Package}\\n" "glpi-agent*" 2>/dev/null | tr "\\n" " ")',
                '      apt-get -y purge ${pg_paquets:-glpi-agent} >> "$PG_JOURNAL" 2>&1',
                '    elif command -v dnf >/dev/null 2>&1; then',
                '      dnf -y remove glpi-agent >> "$PG_JOURNAL" 2>&1',
                '    elif command -v yum >/dev/null 2>&1; then',
                '      yum -y remove glpi-agent >> "$PG_JOURNAL" 2>&1',
                '    elif command -v zypper >/dev/null 2>&1; then',
                '      zypper -n remove glpi-agent >> "$PG_JOURNAL" 2>&1',
                '    else',
                '      pg_echec ' . self::shQuote(__('Gestionnaire de paquets inconnu : retirer le paquet glpi-agent à la main.', 'printgestion')),
                '    fi',
                '    pg_rc=$?',
                '    pg_journal "        code de retour : $pg_rc"',
                '    if [ "$pg_rc" -ne 0 ]; then pg_echec "' . __('La désinstallation de GLPI Agent a échoué (code $pg_rc) : le détail du gestionnaire de paquets est dans le journal.', 'printgestion') . '"; fi',
                '    pg_etape desinstallation ok ""',
                '  fi',
                '  pg_pct 70',
                '',
                '  pg_etape fichiers encours ""',
                '  for pg_f in ' . implode(' ', $fichiers) . '; do',
                '    if [ -e "$pg_f" ]; then',
                '      rm -f "$pg_f"',
                '      pg_journal "        retire : $pg_f"',
                '    fi',
                '  done',
                '  # Ce que le paquet laisse derriere lui (« remove » garde la configuration) et ce que l agent a ecrit :',
                '  # sa configuration, son etat (deviceid, dernier inventaire), et l installeur telecharge dans /tmp.',
                '  # Comme Windows (dossiers ProgramData et Program Files) et macOS (dossier de l agent).',
                '  for pg_d in /etc/glpi-agent /var/lib/glpi-agent; do',
                '    if [ -d "$pg_d" ]; then',
                '      rm -rf "$pg_d"',
                '      pg_journal "        retire : $pg_d"',
                '    fi',
                '  done',
                '  for pg_f in /tmp/glpi-agent-*-linux-installer.pl "${TMPDIR:-/tmp}"/glpi-agent-*-linux-installer.pl; do',
                '    if [ -f "$pg_f" ]; then rm -f "$pg_f"; pg_journal "        retire : $pg_f"; fi',
                '  done',
                '  pg_etape fichiers ok ""',
                '  pg_pct 85',
                '',
            ],
            $report === '' ? ['  PG_PURGE=""', '  pg_etape declaration saute ""', ''] : self::buildShellRemovalReportLines($report),
            self::buildShellRemovalEndLines(__('GLPI Agent est retiré de ce poste.', 'printgestion')),
            [
                '}',
                '',
                'PG_TRAVAIL=1',
                'if [ "$pg_gui" = zenity ]; then',
                '  pg_suivi_zen | pg_zen --progress --width=560 --title="$PG_TITRE" --text=" " --percentage=0 --no-cancel >/dev/null 2>&1',
                'else',
                '  pg_travail',
                'fi',
                'if [ "$(cat "$PG_ETAT" 2>/dev/null)" = OK ]; then exit 0; fi',
                'exit 1',
                '',
            ]
        ));
    }

    /**
     * Linux : « retirer aussi de GLPI », posé comme une question à part, après la confirmation. zenity n'a pas de
     * case à cocher dans une question, et une liste à cocher validerait le retrait sur Entrée. « Non » par défaut :
     * une touche Entrée ne supprime rien de GLPI. Une fenêtre qui ne s'ouvre pas repose la question en console.
     */
    private static function buildLinuxPurgeQuestionLines(bool $purge): array {
        if (!$purge) {
            return ['PG_GLPI=0', ''];
        }
        $choix  = self::purgeChoices();
        $titre  = __('Que faire dans GLPI ?', 'printgestion');
        return [
            '# ── Ce qu on supprime dans GLPI : rien, la sonde, ou tout. Rien est le choix par defaut. ──',
            'PG_GLPI=0',
            'if [ "$pg_gui" = zenity ]; then',
            '  # Une liste a choix unique : zenity n a pas de case dans une question, et trois questions de suite',
            '  # feraient trois fois « Entree » sur un geste definitif.',
            '  # Toutes les options avant les lignes : zenity prend ce qui reste pour les données de la liste.',
            '  pg_choix=$(pg_zen --list --radiolist --width=640 --height=320 --title="$PG_TITRE"'
                . ' --text=' . self::shQuote(htmlspecialchars($titre, ENT_NOQUOTES, 'UTF-8'))
                . ' --hide-header --hide-column=2 --print-column=2'
                . ' --column="" --column=rang --column=' . self::shQuote(__('Choix', 'printgestion'))
                . ' TRUE 0 ' . self::shQuote($choix[0])
                . ' FALSE 1 ' . self::shQuote($choix[1])
                . ' FALSE 2 ' . self::shQuote($choix[2]) . ' 2>/dev/null)',
            '  pg_rc=$?',
            '  case "$pg_rc" in',
            '    0) case "$pg_choix" in 1) PG_GLPI=1 ;; 2) PG_GLPI=2 ;; *) PG_GLPI=0 ;; esac ;;',
            '    1) PG_GLPI=0 ;;',
            '    *) pg_gui="" ;;',
            '  esac',
            'fi',
            'if [ -z "$pg_gui" ]; then',
            '  printf "\\n%s\\n" ' . self::shQuote($titre),
            '  printf "  0) %s\\n" ' . self::shQuote($choix[0]),
            '  printf "  1) %s\\n" ' . self::shQuote($choix[1]),
            '  printf "  2) %s\\n" ' . self::shQuote($choix[2]),
            '  printf "%s " ' . self::shQuote(__('Votre choix [0] :', 'printgestion')),
            '  read -r pg_rep',
            '  case "$pg_rep" in 1) PG_GLPI=1 ;; 2) PG_GLPI=2 ;; *) PG_GLPI=0 ;; esac',
            'fi',
            'pg_journal "Suppression dans GLPI : niveau $PG_GLPI"',
            '',
        ];
    }

    /**
     * Fichier de retrait macOS : la même fenêtre Cocoa que l'installation — une confirmation, les étapes, le résultat —
     * et le même journal dans /var/tmp.
     */
    private static function buildMacosRemovalScript(Entity $entity, string $tag, string $report, string $nom, bool $purge = false): string {
        $client  = (string) $entity->fields['completename'];
        $server  = self::getServerUrl()['url'];
        $title   = __('Retrait de GLPI Agent', 'printgestion');
        $plist   = '/Library/LaunchDaemons/com.teclib.glpi-agent.plist';
        $infos   = self::dialogInfoLines($client, $tag, $server);
        $message = $purge
            ? __('Ce fichier retire de ce Mac : le service GLPI Agent, l\'agent lui-même et sa configuration. Rien d\'autre n\'est touché.', 'printgestion')
            : __('Ce fichier retire de ce Mac : le service GLPI Agent, l\'agent lui-même et sa configuration. Rien d\'autre n\'est touché. Dans GLPI, la sonde reste listée : elle se supprime ensuite depuis la liste des sondes.', 'printgestion');
        $steps   = [
            'taches'          => __('Retrait de la mise à jour automatique', 'printgestion'),
            'service'         => __('Arrêt du service', 'printgestion'),
            'desinstallation' => __('Retrait de GLPI Agent', 'printgestion'),
            'declaration'     => __('Compte rendu à GLPI', 'printgestion'),
        ];
        $fenetre = [
            'fenetre'     => $title,
            'titre'       => __('Retrait de la sonde d\'inventaire', 'printgestion'),
            'sous_titre'  => __('GLPI Agent va être désinstallé de ce Mac — fichier préparé par Print Gestion', 'printgestion'),
            'bande'       => [153, 27, 27],
            'infos'       => implode("\n", $infos),
            'message'     => $message,
            'formulaire'  => false,
            // Les trois choix pour GLPI : absents quand le fichier n'a pas ce pouvoir.
            'choix_glpi'  => $purge ? self::purgeChoices() : [],
            'bouton'      => __('Retirer GLPI Agent', 'printgestion'),
            'annuler'     => __('Annuler', 'printgestion'),
            'ouvrir'      => __('Ouvrir le journal', 'printgestion'),
            'fermer'      => __('Fermer', 'printgestion'),
            'etapes'      => array_map(null, array_keys($steps), array_values($steps)),
            'preparation' => __('Préparation…', 'printgestion'),
            'termine'     => __('Terminé', 'printgestion'),
            'interrompu'  => __('Interrompu', 'printgestion'),
            'journal'     => __('Journal :', 'printgestion'),
            'mort'        => __('Le script de retrait s\'est arrêté sans donner de résultat (erreur, fermeture du terminal ou arrêt forcé) : GLPI Agent est peut-être encore en partie sur ce Mac. Voir le journal.', 'printgestion'),
        ];

        return implode("\n", array_merge(
            [
                '#!/bin/sh',
                '# Retrait de GLPI Agent de ce Mac - ' . $client . ' (TAG ' . $tag . '), genere le ' . date('Y-m-d H:i') . ' par Print Gestion.',
                '# A lancer en root dans le Terminal : sudo sh ' . $nom . '   Journal : /var/tmp.',
                'set -u',
                'if [ "$(id -u)" -ne 0 ]; then',
                '  echo "A lancer en root : sudo sh $0"',
                '  exit 1',
                'fi',
                '',
            ],
            self::buildShellJournalLines('retrait', [
                'Retrait de GLPI Agent, prepare par Print Gestion',
                sprintf('Client : %s   TAG : %s   serveur : %s', $client, $tag, $server),
            ]),
            self::buildShellToolLines(),
            self::buildShellCountLines(),
            self::buildMacosUiLines($title, array_merge($infos, [''], [$message]), $steps, $fenetre),
            [
                '# ── Confirmation : on ne retire pas un agent parce qu on a appuye sur Entree ──',
                'PG_GLPI=0',
                'if pg_fenetre; then',
                '  if [ "$(head -n 1 "$PG_DIR/reponses")" = annule ]; then',
                '    pg_journal ' . self::shQuote(__('Retrait annulé par le technicien : rien n\'a été retiré.', 'printgestion')),
                '    exit 0',
                '  fi',
                '  pg_rep_glpi=$(grep "^glpi=" "$PG_DIR/reponses" | head -n 1)',
                '  case "${pg_rep_glpi#glpi=}" in 1) PG_GLPI=1 ;; 2) PG_GLPI=2 ;; *) PG_GLPI=0 ;; esac',
                'else',
                '  pg_journal "Fenetre indisponible : confirmation en console"',
                '  printf "%s\\n\\n%s\\n\\n" "$PG_TITRE" "$PG_INFOS"',
                '  printf "%s " ' . self::shQuote(__('Retirer GLPI Agent de ce Mac ? [o/N]', 'printgestion')),
                '  read -r pg_rep',
                '  case "$pg_rep" in',
                '    [oOyY]*) ;;',
                '    *)',
                '      pg_journal ' . self::shQuote(__('Retrait annulé par le technicien : rien n\'a été retiré.', 'printgestion')),
                '      echo ' . self::shQuote(__('Retrait annulé : rien n\'a été retiré.', 'printgestion')),
                '      exit 0',
                '      ;;',
                '  esac',
            ],
            $purge ? [
                '  printf "\\n%s\\n" ' . self::shQuote(__('Que faire dans GLPI ?', 'printgestion')),
                '  printf "  0) %s\\n" ' . self::shQuote(self::purgeChoices()[0]),
                '  printf "  1) %s\\n" ' . self::shQuote(self::purgeChoices()[1]),
                '  printf "  2) %s\\n" ' . self::shQuote(self::purgeChoices()[2]),
                '  printf "%s " ' . self::shQuote(__('Votre choix [0] :', 'printgestion')),
                '  read -r pg_rep',
                '  case "$pg_rep" in 1) PG_GLPI=1 ;; 2) PG_GLPI=2 ;; *) PG_GLPI=0 ;; esac',
            ] : [],
            [
                'fi',
                'pg_journal "Suppression dans GLPI : niveau $PG_GLPI"',
                '',
            ],
            $purge && $report !== '' ? self::buildShellPurgeConfirmLines($report, true) : [],
            [
                'pg_travail() {',
                '  pg_pct 5',
                '  # La mise a jour automatique d abord : un service qui survivrait installerait chaque mois un',
                '  # paquet pour un agent absent.',
                '  pg_etape taches encours ""',
                '  pg_avait_maj=0',
                '  if [ -f ' . PluginPrintgestionAgentsetting::MACOS_PLIST . ' ] || [ -f ' . PluginPrintgestionAgentsetting::MACOS_SCRIPT . ' ]; then pg_avait_maj=1; fi',
                '  {',
            ],
            PluginPrintgestionAgentsetting::buildMacosScheduleLines(false, ''),
            [
                '  } >> "$PG_JOURNAL" 2>&1',
                '  rm -f ' . PluginPrintgestionAgentsetting::MACOS_LOG,
                '  if [ "$pg_avait_maj" = 1 ]; then',
                '    pg_etape taches ok ' . self::shQuote(__('service et script retirés', 'printgestion')),
                '  else',
                '    pg_etape taches saute ' . self::shQuote(__('aucune n\'était posée', 'printgestion')),
                '  fi',
                '  pg_pct 15',
                '',
                '  pg_etape service encours ""',
                '  PG_PLIST=' . self::shQuote($plist),
                '  # bootout sur macOS 13 et plus, unload avant : les deux, dans cet ordre.',
                '  launchctl bootout system "$PG_PLIST" >> "$PG_JOURNAL" 2>&1',
                '  launchctl unload "$PG_PLIST" >> "$PG_JOURNAL" 2>&1',
                '  # Le processus doit etre parti avant qu on efface ses fichiers, comme le fait le desinstalleur de',
                '  # Teclib : trente secondes, puis on l arrete nous-memes.',
                '  pg_i=0',
                '  while pgrep -f /Applications/GLPI-Agent/bin/glpi-agent >/dev/null 2>&1 && [ "$pg_i" -lt 30 ]; do sleep 1; pg_i=$((pg_i + 1)); done',
                '  if pgrep -f /Applications/GLPI-Agent/bin/glpi-agent >/dev/null 2>&1; then',
                '    pkill -f /Applications/GLPI-Agent/bin/glpi-agent',
                '    pg_journal "        processus de l agent arrete de force"',
                '  fi',
                '  if [ -e "$PG_PLIST" ]; then',
                '    rm -f "$PG_PLIST"',
                '    pg_journal "        retire : $PG_PLIST"',
                '    pg_etape service ok ""',
                '  else',
                '    pg_etape service saute ' . self::shQuote(__('aucun service GLPI Agent sur ce Mac', 'printgestion')),
                '  fi',
                '  pg_pct 35',
                '',
                '  pg_etape desinstallation encours ""',
                '  pg_paquets=$(pkgutil --pkgs 2>/dev/null | grep -i glpi)',
                '  if [ -d /Applications/GLPI-Agent ] || [ -n "$pg_paquets" ]; then',
                '    # Le dossier de l agent emporte aussi la ToolBox, posee dans son etc.',
                '    rm -rf /Applications/GLPI-Agent',
                '    pg_journal "        retire : /Applications/GLPI-Agent"',
                '    rm -f ' . self::AGENT_LOG_UNIX,
                '    # Oubli du paquet : son identifiant varie selon la version, on prend ceux qui parlent de GLPI.',
                '    # dmidecode : pose par le paquet sur certains Mac (son desinstalleur l enleve). Seulement s il vient',
                '    # de lui — la liste des fichiers se lit AVANT l oubli du recu, qui l efface.',
                '    for pg_paquet in $pg_paquets; do',
                '      if pkgutil --files "$pg_paquet" 2>/dev/null | grep -qx "usr/local/bin/dmidecode"; then',
                '        rm -f /usr/local/bin/dmidecode',
                '        pg_journal "        retire : /usr/local/bin/dmidecode"',
                '      fi',
                '      pkgutil --forget "$pg_paquet" >> "$PG_JOURNAL" 2>&1',
                '    done',
                '    # Le paquet telecharge par l installation, garde pour une reinstallation : plus rien a reinstaller.',
                '    for pg_pkg in /tmp/GLPI-Agent-*.pkg "${TMPDIR:-/tmp}"/GLPI-Agent-*.pkg; do',
                '      if [ -f "$pg_pkg" ]; then rm -f "$pg_pkg"; pg_journal "        retire : $pg_pkg"; fi',
                '    done',
                '    pg_etape desinstallation ok ""',
                '  else',
                '    pg_etape desinstallation saute ' . self::shQuote(__('aucun GLPI Agent installé sur ce Mac', 'printgestion')),
                '  fi',
                '  pg_pct 75',
                '',
            ],
            $report === '' ? ['  PG_PURGE=""', '  pg_etape declaration saute ""', ''] : self::buildShellRemovalReportLines($report),
            self::buildShellRemovalEndLines(__('GLPI Agent est retiré de ce Mac.', 'printgestion')),
            [
                '}',
                '',
                'pg_travail',
                'if [ "$(cat "$PG_ETAT" 2>/dev/null)" = OK ]; then exit 0; fi',
                'exit 1',
                '',
            ]
        ));
    }

    /**
     * Fichier de retrait Windows : la même fenêtre que l'installation.
     *
     * Une confirmation d'abord — on ne retire pas un agent parce qu'on a appuyé sur Entrée —, puis les étapes
     * cochées une à une, puis le résultat, et le même journal dans le dossier temporaire : pas dans
     * %ProgramData%\PrintGestion, que le retrait efface justement.
     *
     * Le bandeau est rouge : on doit voir au premier coup d'œil que cette fenêtre-là enlève quelque chose.
     */
    private static function buildWindowsRemovalScript(Entity $entity, string $tag, string $report, bool $purge = false): string {
        $client = (string) $entity->fields['completename'];
        $server = self::getServerUrl()['url'];
        $batch  = self::buildWindowsLauncherLines('Retrait de GLPI Agent', [
            'Retrait de GLPI Agent de ce PC - ' . $client . ' (TAG ' . $tag . '), genere le ' . date('Y-m-d H:i') . ' par Print Gestion.',
            'Clic droit, puis Executer en tant qu administrateur.',
            'Il retire les taches planifiees, GLPI Agent et les fichiers du plugin. Journal : dossier temporaire, PrintGestion.',
        ], 'printgestion-retrait');

        $steps = [
            'taches'          => __('Retrait des tâches planifiées', 'printgestion'),
            'desinstallation' => __('Désinstallation de GLPI Agent', 'printgestion'),
            'fichiers'        => __('Retrait des fichiers', 'printgestion'),
            'declaration'     => __('Compte rendu à GLPI', 'printgestion'),
        ];

        $ps = array_merge(
            [
                '# Retrait de GLPI Agent, pose par Print Gestion pour le TAG ' . $tag . '.',
                '# Lu par PowerShell seulement : cmd s arrete avant (exit /b).',
            ],
            self::psFormsHeader(),
            self::buildWindowsJournalLines('retrait', [
                'Retrait de GLPI Agent, prepare par Print Gestion',
                sprintf('Client : %s   TAG : %s   serveur : %s', $client, $tag, $server),
            ]),
            [
                'function Echec($texte) {',
                '  if ($script:en_cours -ne "") { Etape $script:en_cours "echec" "" }',
                '  Journal ("ERREUR  " + ($texte -replace "\r?\n", " | "))',
                '  Fin $false $texte',
                '  exit 1',
                '}',
                'trap {',
                '  Journal ("IMPREVU " + $_)',
                '  $texte = (' . self::psQuote(__('Le retrait s\'est arrêté sur une erreur inattendue : GLPI Agent est peut-être encore en partie sur ce PC.', 'printgestion')) . ') + [Environment]::NewLine + [Environment]::NewLine + $_',
                '  if ($script:fenetre_prete) { try { Echec $texte } catch { Secours $texte } } else { Secours $texte }',
                '  exit 1',
                '}',
                '',
            ],
            self::buildWindowsFrameLines(
                __('Retrait de GLPI Agent', 'printgestion'),
                __('Retrait de la sonde d\'inventaire', 'printgestion'),
                __('GLPI Agent va être désinstallé de ce PC — fichier préparé par Print Gestion', 'printgestion'),
                self::dialogInfoLines($client, $tag, $server),
                [153, 27, 27]
            ),
            self::buildWindowsConfirmLines(
                $purge
                    ? __('Ce fichier retire de ce PC : les tâches planifiées posées par Print Gestion, GLPI Agent lui-même, et leurs fichiers. Rien d\'autre n\'est touché.', 'printgestion')
                    : __('Ce fichier retire de ce PC : les tâches planifiées posées par Print Gestion, GLPI Agent lui-même, et leurs fichiers. Rien d\'autre n\'est touché. Dans GLPI, la sonde reste listée : elle se supprime ensuite depuis la liste des sondes.', 'printgestion'),
                __('Retirer GLPI Agent', 'printgestion'),
                __('Annuler', 'printgestion'),
                $purge
            ),
            self::buildWindowsWizardLines($steps),
            self::buildWindowsChoiceLines(__('Retrait annulé par le technicien : rien n\'a été retiré.', 'printgestion')),
            // « Tout supprimer » se confirme, avec les nombres que GLPI annonce, avant que rien ne soit touché.
            $purge && $report !== '' ? self::buildWindowsPurgeConfirmLines($report) : [],
            [
                'PageEtapes',
                '',
                '# ── Taches planifiees : code 0 retiree, 1 absente ──',
                'Etape "taches" "encours" ""',
                'Avancement ' . self::psQuote(__('Retrait des tâches planifiées...', 'printgestion')) . ' (-1) ""',
                '$retirees = 0',
                'foreach ($nom in @(' . self::psQuote(PluginPrintgestionAgentsetting::TASK_NAME) . ', ' . self::psQuote(PluginPrintgestionAgentsetting::SCAN_TASK_NAME) . ')) {',
                '  $t = Start-Process -FilePath "schtasks.exe" -ArgumentList ("/Delete /TN " + [char]34 + $nom + [char]34 + " /F") -Wait -PassThru -WindowStyle Hidden',
                '  Journal ("        " + $nom + " : code " + $t.ExitCode)',
                '  if ($t.ExitCode -eq 0) { $retirees++ }',
                '}',
                'if ($retirees -eq 0) { Etape "taches" "ok" ' . self::psQuote(__('aucune n\'était posée', 'printgestion')) . ' } else { Etape "taches" "ok" ([string]$retirees + " " + ' . self::psQuote(__('retirée(s)', 'printgestion')) . ') }',
                '',
                '# ── Desinstallation : la cle du registre plutot que winget, qui peut etre absent ou ignorer ──',
                '# un agent installe par le MSI. Les deux ruches sont regardees (64 et 32 bits).',
                'Etape "desinstallation" "encours" ""',
                'Avancement ' . self::psQuote(__('Désinstallation de GLPI Agent...', 'printgestion')) . ' (-1) ' . self::psQuote(__('Environ une minute.', 'printgestion')),
                '$produits = @()',
                'foreach ($cle in @("HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*", "HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*")) {',
                '  $produits += @(Get-ItemProperty $cle -ErrorAction SilentlyContinue | Where-Object { $_.DisplayName -like "GLPI Agent*" })',
                '}',
                'if ($produits.Count -eq 0) {',
                '  Etape "desinstallation" "saute" ' . self::psQuote(__('aucun GLPI Agent installé sur ce PC', 'printgestion')),
                '} else {',
                '  $rate = $false',
                '  foreach ($pr in $produits) {',
                '    Journal ("        " + $pr.DisplayName + "   " + $pr.PSChildName)',
                '    $m = Start-Process -FilePath "msiexec.exe" -ArgumentList ("/x " + $pr.PSChildName + " /qn /norestart") -PassThru',
                '    while (-not $m.HasExited) {',
                '      [System.Windows.Forms.Application]::DoEvents()',
                '      Start-Sleep -Milliseconds 150',
                '    }',
                '    Journal ("        code de retour : " + $m.ExitCode)',
                '    # 0 fait, 3010 et 1641 redemarrage, 1605 deja absent : tout le reste est un echec.',
                '    if (@(0, 3010, 1641, 1605) -notcontains $m.ExitCode) { $rate = $true }',
                '  }',
                '  if ($rate) { Echec(' . self::psQuote(__('La désinstallation de GLPI Agent a échoué : l\'agent est peut-être encore sur ce PC. Le journal donne le code de retour de Windows Installer.', 'printgestion')) . ') }',
                '  Etape "desinstallation" "ok" ""',
                '}',
                '',
                '# ── Fichiers : ceux du plugin, les donnees que le MSI laisse, et un dossier d agent orphelin ──',
                'Etape "fichiers" "encours" ""',
                'Avancement ' . self::psQuote(__('Retrait des fichiers...', 'printgestion')) . ' (-1) ""',
                '$restes = 0',
                '$reportes = 0',
                '# Ce qui tient encore un fichier : le service (le MSI vient de l arreter, il lache son journal une',
                '# a deux secondes plus tard) et les programmes lances DEPUIS le dossier vise. Un programme du client',
                '# au nom voisin n est jamais touche : on compare le chemin, pas le nom.',
                'function Fermer($dossier) {',
                '  foreach ($s in @(Get-Service -ErrorAction SilentlyContinue | Where-Object { $_.Name -like "glpi-agent*" -or $_.DisplayName -like "GLPI Agent*" })) {',
                '    if ($s.Status -ne "Stopped") {',
                '      try { Stop-Service -InputObject $s -Force -ErrorAction Stop; Journal ("        service arrete : " + $s.Name) } catch { Journal ("        service non arrete : " + $s.Name) }',
                '    }',
                '  }',
                '  foreach ($p in @(Get-Process -ErrorAction SilentlyContinue)) {',
                '    $vise = $false',
                '    try { $vise = ([string]$p.Path).StartsWith($dossier, [System.StringComparison]::OrdinalIgnoreCase) } catch { }',
                '    if (-not $vise) {',
                '      # Lance d ailleurs, mais tenant le dossier par une bibliotheque chargee depuis lui.',
                '      try {',
                '        foreach ($m in @($p.Modules)) {',
                '          if (([string]$m.FileName).StartsWith($dossier, [System.StringComparison]::OrdinalIgnoreCase)) { $vise = $true; break }',
                '        }',
                '      } catch { }',
                '    }',
                '    if ($vise) {',
                '      try { Stop-Process -Id $p.Id -Force -ErrorAction Stop; Journal ("        ferme : " + $p.ProcessName + " (" + $p.Id + ")") } catch { }',
                '    }',
                '  }',
                '}',
                '# Dernier recours, quand un programme qui n est pas le notre tient encore un fichier : Windows efface',
                '# le dossier au prochain demarrage. C est ce que font les installeurs, et personne ne se fait tuer',
                '# son editeur de texte. Les fichiers d abord, puis les dossiers du plus profond au moins profond :',
                '# une suppression programmee n emporte un dossier que s il est vide a ce moment-la.',
                'function EffacerAuRedemarrage($dossier) {',
                '  try {',
                '    Add-Type -Namespace PgWin -Name Fichier -MemberDefinition \'[DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)] public static extern bool MoveFileEx(string lpExistingFileName, string lpNewFileName, int dwFlags);\' -ErrorAction SilentlyContinue',
                '  } catch { }',
                '  $cibles = @()',
                '  try { $cibles += @(Get-ChildItem -LiteralPath $dossier -Recurse -Force -File -ErrorAction SilentlyContinue | ForEach-Object { $_.FullName }) } catch { }',
                '  try { $cibles += @(Get-ChildItem -LiteralPath $dossier -Recurse -Force -Directory -ErrorAction SilentlyContinue | ForEach-Object { $_.FullName } | Sort-Object -Property Length -Descending) } catch { }',
                '  $cibles += $dossier',
                '  $ok = $true',
                '  foreach ($cible in $cibles) {',
                '    # 4 = MOVEFILE_DELAY_UNTIL_REBOOT, sans destination : la suppression. [NullString]::Value et',
                '    # non $null : PowerShell passe une chaine VIDE pour un parametre texte, et Windows repond alors',
                '    # « chemin introuvable » (code 3) au lieu de programmer quoi que ce soit. Verifie a l essai.',
                '    try { if (-not [PgWin.Fichier]::MoveFileEx($cible, [NullString]::Value, 4)) { $ok = $false } } catch { $ok = $false }',
                '  }',
                '  return $ok',
                '}',
                'foreach ($d in @((Join-Path $env:ProgramData "PrintGestion"), (Join-Path $env:ProgramData "GLPI-Agent"), (Join-Path $env:ProgramFiles "GLPI-Agent"))) {',
                '  if (Test-Path -LiteralPath $d) {',
                '    $efface = $false',
                '    $dernier = ""',
                '    for ($essai = 0; $essai -lt 3 -and -not $efface; $essai++) {',
                '      try {',
                '        Remove-Item -LiteralPath $d -Recurse -Force -ErrorAction Stop',
                '        $efface = $true',
                '      } catch {',
                '        $dernier = $_.Exception.Message',
                '        # Premier echec : on ferme ce qui tient les fichiers, puis on laisse deux secondes.',
                '        Fermer $d',
                '        Attendre 2',
                '      }',
                '    }',
                '    if ($efface) {',
                '      Journal ("        retire : " + $d)',
                '    } elseif (EffacerAuRedemarrage $d) {',
                '      $reportes++',
                '      Journal ("        tenu par un programme, efface au prochain redemarrage : " + $d + " - " + $dernier)',
                '    } else {',
                '      $restes++',
                '      Journal ("        non retire : " + $d + " - " + $dernier)',
                '    }',
                '  }',
                '}',
                'if ($restes -gt 0) {',
                '  Etape "fichiers" "echec" ' . self::psQuote(__('un dossier est resté (voir le journal) — sans effet sur une réinstallation', 'printgestion')),
                '} elseif ($reportes -gt 0) {',
                '  Etape "fichiers" "ok" ' . self::psQuote(__('un dossier était encore utilisé : Windows l\'effacera au prochain redémarrage', 'printgestion')),
                '} else {',
                '  Etape "fichiers" "ok" ""',
                '}',
                '',
            ],
            ['$script:purge = ""'],
            $report === '' ? ['Etape "declaration" "saute" ""', ''] : [
                '# ── Compte rendu : ce PC n est plus une sonde. Un echec ici ne change rien au retrait. ──',
                '# Case cochee : GLPI supprime aussi la sonde, et repond PURGE OK, PURGE ABSENT ou PURGE REFUSE.',
                'Etape "declaration" "encours" ""',
                'Avancement ' . self::psQuote(__('Compte rendu à GLPI...', 'printgestion')) . ' (-1) ""',
                '$gl = ""',
                'if ($script:niveau -gt 0) { $gl = "&gl=" + $script:niveau; Journal ("        suppression demandee dans GLPI, niveau " + $script:niveau) }',
                'try {',
                '  $rep = [string](New-Object System.Net.WebClient).DownloadString(' . self::psQuote(PluginPrintgestionAgenttoken::getReportURL($report)) . ' + "&maj=0&off=1" + $gl + "&pc=" + [Uri]::EscapeDataString($env:COMPUTERNAME))',
                '  $script:purge = $rep.Trim()',
                '  if ($script:purge -ne "") { Journal ("        reponse de GLPI : " + $script:purge) }',
                '  if ($script:purge -like "PURGE TOTAL *") { Etape "declaration" "ok" (Compte $script:purge) }',
                '  elseif ($script:purge -eq "PURGE OK") { Etape "declaration" "ok" ' . self::psQuote(self::purgeStepNotes()['OK']) . ' }',
                '  elseif ($script:purge -eq "PURGE ABSENT") { Etape "declaration" "ok" ' . self::psQuote(self::purgeStepNotes()['ABSENT']) . ' }',
                '  elseif ($script:purge -eq "PURGE REFUSE") { Etape "declaration" "echec" ' . self::psQuote(self::purgeStepNotes()['REFUSE']) . ' }',
                '  else { Etape "declaration" "ok" "" }',
                '} catch {',
                '  Journal ("        " + $_.Exception.Message)',
                '  Etape "declaration" "echec" ' . self::psQuote(__('GLPI n\'a pas reçu le compte rendu', 'printgestion')),
                '}',
                '',
            ],
            [
                '# Ce que la fenetre dit de GLPI depend de sa reponse. Deux phrases jointes a l execution : un retour a la',
                '# ligne dans une chaine couperait la ligne du script.',
                '$glpi_fin = ' . self::psQuote(__('Dans GLPI, la sonde reste listée : elle se supprime depuis la liste des sondes — case cochée, puis Actions → Supprimer.', 'printgestion')),
                'if ($script:purge -like "PURGE TOTAL *") { $glpi_fin = (Compte $script:purge) + ". " + ' . self::psQuote(self::purgeTotalEndText()) . ' }',
                'elseif ($script:purge -eq "PURGE OK") { $glpi_fin = ' . self::psQuote(self::purgeEndTexts()['OK']) . ' }',
                'elseif ($script:purge -eq "PURGE ABSENT") { $glpi_fin = ' . self::psQuote(self::purgeEndTexts()['ABSENT']) . ' }',
                'elseif ($script:purge -eq "PURGE REFUSE") { $glpi_fin = ' . self::psQuote(self::purgeEndTexts()['REFUSE']) . ' }',
                'Fin $true (' . self::psQuote(__('GLPI Agent est retiré de ce PC.', 'printgestion')) . ' + [Environment]::NewLine + [Environment]::NewLine + $glpi_fin)',
                'exit 0',
                '',
            ]
        );

        return implode("\r\n", array_merge($batch, $ps));
    }

    /** Un appelant par système : la route sert un constructeur qui ne prend que l'entité. */
    public static function buildWindowsRemoval(Entity $entity): array {
        return self::buildRemovalFile($entity, 'windows');
    }

    public static function buildLinuxRemoval(Entity $entity): array {
        return self::buildRemovalFile($entity, 'linux');
    }

    public static function buildMacosRemoval(Entity $entity): array {
        return self::buildRemovalFile($entity, 'macos');
    }

    // ── Fichiers uniques Linux et macOS ───────────────────────────────────────

    /**
     * Fichier unique Linux : un seul .sh, ni archive à extraire ni fichier à deviner. Il ouvre une fenêtre (zenity)
     * ou pose la question en console, va chercher l'installeur Perl officiel sur ce serveur GLPI avec une clé
     * temporaire, vérifie son empreinte SHA-256, l'installe avec la découverte et l'inventaire réseau, et pose la
     * tâche cron mensuelle de mise à jour si on l'a voulu.
     *
     * Comme pour Windows : la clé plutôt que l'installeur dans le fichier, parce qu'un script de plusieurs mégaoctets
     * encodés se fait refuser, et parce que le binaire doit rester celui de Teclib'. La clé vaut une fois et un jour.
     *
     * @return array ['ok' => bool, 'errors' => string[], 'content', 'filename', 'version', 'tag', 'expires']
     */
    public static function buildLinuxSingleFile(Entity $entity): array {
        $blockers = self::getPackageBlockers($entity, 'linux');
        if (!empty($blockers)) {
            return ['ok' => false, 'errors' => $blockers];
        }
        $installer = self::getCachedInstaller(true, 'linux');
        if ($installer === null) {
            return ['ok' => false, 'errors' => [__('Installeur Linux absent ou modifié depuis sa vérification : refaites la vérification (page « Installeur GLPI Agent »).', 'printgestion')]];
        }
        $token = PluginPrintgestionAgenttoken::create((int) $entity->getID(), ['linux' => $installer]);
        if ($token === null) {
            return ['ok' => false, 'errors' => [__('Clé de téléchargement non enregistrée : le fichier unique serait incapable de récupérer l\'agent (détail dans le journal printgestion).', 'printgestion')]];
        }
        $tag     = trim((string) $entity->fields['tag']);
        $version = (string) $installer['version'];
        return [
            'ok'       => true,
            'errors'   => [],
            'content'  => self::buildLinuxSingleFileScript($entity, $installer, $tag, $token, (string) PluginPrintgestionAgenttoken::createReport((int) $entity->getID(), $tag, 'linux')),
            'filename' => self::getSingleFileName($tag, $version, 'linux'),
            'version'  => $version,
            'tag'      => $tag,
            'expires'  => date('Y-m-d H:i:s', time() + PluginPrintgestionAgenttoken::TTL),
        ];
    }

    private static function buildLinuxSingleFileScript(Entity $entity, array $installer, string $tag, string $token, string $report): string {
        $version = (string) $installer['version'];
        $config  = PluginPrintgestionConfig::getInstance()->fields;
        $update  = (int) ($config['agent_update_default'] ?? 1) === 1;
        // Une seule version pour tout : celle que ce serveur distribue est aussi celle que les sondes visent.
        $target  = PluginPrintgestionAgentsetting::getTargetVersion();
        $expires = date('d/m/Y H:i', time() + PluginPrintgestionAgenttoken::TTL);
        $size_mb = max(1, (int) round(((int) ($installer['size'] ?? 0)) / 1048576));
        $client  = (string) $entity->fields['completename'];
        $server  = self::getServerUrl()['url'];
        $title   = sprintf(__('Installation de GLPI Agent %s', 'printgestion'), $version);
        $infos   = array_merge(self::dialogInfoLines($client, $tag, $server), [
            '',
            sprintf(__('« Installer » télécharge l\'agent officiel depuis ce serveur (%d Mo), vérifie son empreinte, puis l\'installe.', 'printgestion'), $size_mb),
            sprintf(__('Clé de téléchargement : une seule utilisation, jusqu\'au %s.', 'printgestion'), $expires),
        ]);
        $steps = [
            'telechargement' => __('Téléchargement de l\'agent officiel', 'printgestion'),
            'empreinte'      => __('Vérification de l\'empreinte', 'printgestion'),
            'installation'   => sprintf(__('Installation de GLPI Agent %s', 'printgestion'), $version),
            'maj'            => __('Mise à jour automatique', 'printgestion'),
            'contact'        => __('Premier contact avec GLPI', 'printgestion'),
            'declaration'    => __('Compte rendu à GLPI', 'printgestion'),
            'decouverte'     => __('Découverte des imprimantes', 'printgestion'),
        ];

        // Les fréquences, celle d'avance en tête : le menu de zenity ne sait pas présélectionner, il montre le premier.
        $choix = PluginPrintgestionCollectfrequency::getInstallerChoices();
        $ordre = array_merge([PluginPrintgestionCollectfrequency::INSTALLER_DEFAULT], array_diff(array_keys($choix), [PluginPrintgestionCollectfrequency::INSTALLER_DEFAULT]));
        $libelles = array_map(static fn(string $code): string => $code . '  —  ' . $choix[$code], $ordre);
        // Ce que Windows et macOS disent sous leurs champs, ici dans le texte du formulaire — zenity n'a qu'un texte.
        $aides = array_merge(
            [''],
            $update ? [sprintf(
                __('Mise à jour automatique : posée sur ce poste (%s), réglage du serveur GLPI.', 'printgestion'),
                $target !== '' ? sprintf(__('1er du mois à 3 h, version visée %s', 'printgestion'), $target) : __('1er du mois à 3 h', 'printgestion')
            )] : array_merge(
                [__('Mise à jour automatique : « Non », l\'agent fonctionne normalement mais restera dans cette version jusqu\'à une intervention sur ce poste.', 'printgestion')],
                $target !== '' ? [sprintf(__('Version visée : %s.', 'printgestion'), $target)] : []
            ),
            [__('Adresses IP : 192.168.1.0/24 (tout le réseau), 192.168.1.30-35 (une plage), ou des adresses séparées par des virgules. Laissé vide : rien n\'est créé dans GLPI, le raccordement restera à faire.', 'printgestion')],
            [__('Relevés : tous les combien les imprimantes sont relevées. Ce choix règle aussi le délai au-delà duquel GLPI signale qu\'une imprimante ne remonte plus.', 'printgestion')],
            PluginPrintgestionCollectsetup::isAvailable()
                ? array_map(static fn(array $texte): string => $texte[0] . ' : ' . $texte[1], array_values(self::pilotChoices()))
                : [self::pilotLocalOnly()]
        );
        $mort = __('Le script d\'installation s\'est arrêté sans donner de résultat (erreur, fermeture du terminal ou arrêt forcé) : rien n\'est garanti installé. Voir le journal.', 'printgestion');

        return implode("\n", array_merge(
            [
                '#!/bin/sh',
                '# GLPI Agent ' . $version . ' - fichier unique d installation pour le TAG ' . $tag . ' (Print Gestion).',
                '# A lancer en root : sudo sh ' . self::getSingleFileName($tag, $version, 'linux'),
                '# Il telecharge l installeur Perl officiel depuis ce serveur GLPI, verifie son empreinte SHA-256,',
                '# puis l installe. Aucun mot de passe ici : une URL, un TAG, une empreinte et une cle a usage unique.',
                '# Cle de telechargement : une seule utilisation, jusqu au ' . $expires . '. Journal : /var/tmp.',
                'set -u',
                'if [ "$(id -u)" -ne 0 ]; then',
                '  echo "A lancer en root : sudo sh $0"',
                '  exit 1',
                'fi',
                '',
            ],
            self::buildShellJournalLines('installation', [
                sprintf('Installation de GLPI Agent %s, preparee par Print Gestion', $version),
                sprintf('Client : %s   TAG : %s   serveur : %s', $client, $tag, $server),
            ]),
            self::buildShellToolLines(),
            self::buildLinuxUiLines($title, $infos, $steps, $mort),
            self::buildLinuxServiceLines(),
            [
                'PG_FORM_Z=' . self::shQuote(htmlspecialchars(implode("\n", array_merge($infos, $aides)), ENT_NOQUOTES, 'UTF-8')),
                'PG_URL=' . self::shQuote(PluginPrintgestionAgenttoken::getPullURL($token, 'linux')),
                'PG_SHA=' . self::shQuote(strtolower((string) $installer['sha256'])),
                'PG_FICHIER="${TMPDIR:-/tmp}/' . $installer['file'] . '"',
                'PG_TAILLE=' . (int) ($installer['size'] ?? 0),
                '',
                '# ── Premiere page : les questions, toutes ensemble ──',
                'pg_ips=""',
                'pg_snmp=""',
                'pg_freq=""',
                # Sans GLPI Inventory sur le serveur, il n'y a qu'un chemin : l'agent scanne lui-même.
                'pg_mode=' . (PluginPrintgestionCollectsetup::isAvailable() ? 'glpi' : 'local'),
                'pg_maj=' . ($update ? 'oui' : 'non'),
                'if [ "$pg_gui" = zenity ]; then',
                '  pg_form=$(pg_zen --forms --width=640 --title="$PG_TITRE" --text="$PG_FORM_Z" \\',
                '    --ok-label=' . self::shQuote(__('Installer', 'printgestion')) . ' --cancel-label=' . self::shQuote(__('Annuler', 'printgestion')) . ' --separator="|" \\',
                '    --add-entry=' . self::shQuote(__('Adresses IP (vide : aucune)', 'printgestion')) . ' \\',
                '    --add-entry=' . self::shQuote(__('Communauté SNMP (vide : public)', 'printgestion')) . ' \\',
                // Chaque option finit par une continuation, la dernière comprise : sans elle, la redirection de la ligne
                // suivante deviendrait une commande à part, et $? vaudrait toujours 0 — « Annuler » lancerait l'installation.
                '    --add-combo=' . self::shQuote(__('Fréquence des relevés', 'printgestion')) . ' --combo-values=' . self::shQuote(implode('|', $libelles)) . ' \\',
            ],
            $update ? [] : [
                '    --add-combo=' . self::shQuote(__('Mise à jour auto. (1er du mois, 3 h)', 'printgestion')) . ' --combo-values=' . self::shQuote(__('Non', 'printgestion') . '|' . __('Oui', 'printgestion')) . ' \\',
            ],
            !PluginPrintgestionCollectsetup::isAvailable() ? [] : [
                '    --add-combo=' . self::shQuote(__('Qui pilote le scan des imprimantes', 'printgestion'))
                    . ' --combo-values=' . self::shQuote(implode('|', array_column(self::pilotChoices(), 0))) . ' \\',
            ],
            [
                '    2>/dev/null)',
                '  pg_rc=$?',
                '  case "$pg_rc" in',
                '    0)',
                '      pg_ips=$(printf "%s" "$pg_form" | cut -d"|" -f1)',
                '      pg_snmp=$(printf "%s" "$pg_form" | cut -d"|" -f2)',
                '      pg_freq=$(printf "%s" "$pg_form" | cut -d"|" -f3 | cut -d" " -f1)',
            ],
            $update ? [] : [
                '      case "$(printf "%s" "$pg_form" | cut -d"|" -f4)" in ' . __('Oui', 'printgestion') . ') pg_maj=oui ;; esac',
            ],
            !PluginPrintgestionCollectsetup::isAvailable() ? [] : [
                // Le rang de la colonne suit la présence de la liste « mise à jour » juste avant.
                '      case "$(printf "%s" "$pg_form" | cut -d"|" -f' . ($update ? 4 : 5) . ')" in '
                    . self::shQuote(array_values(self::pilotChoices())[1][0]) . ') pg_mode=local ;; esac',
            ],
            [
                '      ;;',
                '    1)',
                '      pg_journal ' . self::shQuote(__('Installation annulée par le technicien : rien n\'a été installé.', 'printgestion')),
                '      exit 0',
                '      ;;',
                '    *)',
                '      # Une fenetre qui ne s ouvre pas ne vaut jamais une annulation : on redemande en console.',
                '      pg_journal "Fenetre indisponible (code $pg_rc) : questions en console"',
                '      pg_gui=""',
                '      ;;',
                '  esac',
                'fi',
                'if [ -z "$pg_gui" ]; then',
                '  printf "%s\\n\\n" "$PG_TITRE"',
                '  printf "%s\\n\\n" "$PG_INFOS"',
                '  printf "%s " ' . self::shQuote(__('Installer maintenant ? [O/n]', 'printgestion')),
                '  read -r pg_rep',
                '  case "$pg_rep" in',
                '    [nN]*)',
                '      pg_journal ' . self::shQuote(__('Installation annulée par le technicien : rien n\'a été installé.', 'printgestion')),
                '      echo ' . self::shQuote(__('Installation annulée : rien n\'a été installé.', 'printgestion')),
                '      exit 0',
                '      ;;',
                '  esac',
                '  printf "%s : " ' . self::shQuote(__('Adresses IP des imprimantes (192.168.1.0/24, 192.168.1.30-35, ou une liste ; vide : aucune)', 'printgestion')),
                '  read -r pg_ips',
                '  printf "%s [public] : " ' . self::shQuote(__('Communauté SNMP des imprimantes', 'printgestion')),
                '  read -r pg_snmp',
                '  printf "%s\\n" ' . self::shQuote(__('Tous les combien les imprimantes sont-elles relevées ?', 'printgestion')),
            ],
            array_map(static fn(int $i, string $libelle): string => '  printf "%s\\n" ' . self::shQuote('  ' . ($i + 1) . ') ' . $libelle), array_keys($libelles), $libelles),
            [
                '  printf "%s " ' . self::shQuote(__('Numéro [1] :', 'printgestion')),
                '  read -r pg_num',
                '  case "${pg_num:-1}" in',
            ],
            array_map(static fn(int $i, string $code): string => '    ' . ($i + 1) . ') pg_freq=' . $code . ' ;;', array_keys($ordre), $ordre),
            [
                '  esac',
            ],
            $update ? [] : [
                '  printf "%s " ' . self::shQuote(__('Mettre à jour l\'agent automatiquement (1er du mois à 3 h) ? [o/N]', 'printgestion')),
                '  read -r pg_rep',
                '  case "$pg_rep" in [oOyY]*) pg_maj=oui ;; esac',
            ],
            self::buildShellPilotConsoleLines(),
            [
                'fi',
                'if [ -z "$pg_snmp" ]; then pg_snmp=public; fi',
                'if [ -z "$pg_freq" ]; then pg_freq=' . PluginPrintgestionCollectfrequency::INSTALLER_DEFAULT . '; fi',
                'if [ -n "$pg_ips" ]; then pg_journal "Adresses des imprimantes : $pg_ips"; else pg_journal "Adresses des imprimantes : aucune"; fi',
                '# Jamais la communaute elle-meme : c est un secret, et ce journal reste dans /var/tmp.',
                'pg_journal "Communaute SNMP : renseignee (jamais ecrite dans ce journal)"',
                'pg_journal "Frequence des releves : $pg_freq"',
                'pg_journal "Mise a jour automatique : $pg_maj"',
                'pg_journal "Pilotage du scan : $pg_mode"',
                '',
                '# ── Le travail, etape par etape. Dans un sous-shell quand la fenetre le suit (elle lit un tube). ──',
                'pg_travail() {',
                '  pg_pct 2',
                '  pg_etape telechargement encours ""',
                '  if [ "$pg_gui" = zenity ]; then pg_telecharger_suivi "$PG_URL" "$PG_FICHIER" "$PG_TAILLE"; else pg_telecharger "$PG_URL" "$PG_FICHIER"; fi',
                '  if [ "$?" -ne 0 ]; then pg_echec ' . self::shQuote(__('Téléchargement impossible : rien n\'a été installé. Causes habituelles : clé déjà utilisée ou expirée (régénérer le fichier dans GLPI), serveur GLPI injoignable depuis ce poste, certificat HTTPS inconnu, ou ni curl ni wget sur ce poste.', 'printgestion')) . '; fi',
                '  pg_journal "        recu : $(wc -c < "$PG_FICHIER") octets"',
                '  pg_etape telechargement ok ""',
                '  pg_pct 40',
                '',
                '  pg_etape empreinte encours ""',
                '  pg_reelle=$(pg_empreinte "$PG_FICHIER")',
                '  pg_journal "        attendue : $PG_SHA"',
                '  pg_journal "        recue    : $pg_reelle"',
                '  if [ -z "$pg_reelle" ]; then pg_echec ' . self::shQuote(__('Aucun outil d\'empreinte sur ce poste (sha256sum, shasum ou openssl) : rien n\'a été installé, faute de pouvoir vérifier ce qui a été téléchargé.', 'printgestion')) . '; fi',
                '  if [ "$pg_reelle" != "$PG_SHA" ]; then',
                '    rm -f "$PG_FICHIER"',
                '    pg_echec ' . self::shQuote(__('Le fichier téléchargé n\'est pas celui attendu (empreinte différente) : rien n\'a été installé. Prévenir l\'administrateur.', 'printgestion')),
                '  fi',
                '  pg_etape empreinte ok ""',
                '  pg_pct 50',
                '',
                '  pg_etape installation encours ""',
                '  pg_detail ' . self::shQuote(__('Environ une minute. Aucune question ne sera posée.', 'printgestion')),
                '  # Reessais SNMP : option absente de l installeur, posee en conf.d pour survivre aux mises a jour.',
                '  mkdir -p /etc/glpi-agent/conf.d',
                "  cat > /etc/glpi-agent/conf.d/90-printgestion.cfg <<'PRINTGESTION_EOF'",
                rtrim(self::buildAgentConfig($tag, false), "\n"),
                'PRINTGESTION_EOF',
                '  # Un paquet retire dont dpkg garde la trace (etat « rc ») ne remet pas ses fichiers de configuration a',
                '  # la reinstallation : agent.cfg manquerait, conf.d ne serait pas lu, l agent n aurait pas de serveur.',
                '  # La trace est purgee d abord (un ancien retrait qui effacait /etc/glpi-agent a la main la laissait).',
                '  if command -v dpkg-query >/dev/null 2>&1; then',
                '    for pg_p in $(dpkg-query -W -f="\\${Package} \\${Status}\\n" "glpi-agent*" 2>/dev/null | awk \'$NF == "config-files" { print $1 }\'); do',
                '      dpkg --purge "$pg_p" >> "$PG_JOURNAL" 2>&1',
                '      pg_journal "        trace dpkg purgee avant installation : $pg_p"',
                '    done',
                '  fi',
                '  # Ce que dit l installeur part au journal : c est la qu on le relira en cas d echec.',
                '  ' . self::buildLinuxCommand('"$PG_FICHIER"', $tag) . ' >> "$PG_JOURNAL" 2>&1',
                '  PG_RC=$?',
                '  pg_journal "        code de retour : $PG_RC"',
                '  if [ "$PG_RC" -ne 0 ]; then',
                '    pg_echec "' . __('Installation non terminée, code $PG_RC : le détail de l\'installeur est dans le journal.', 'printgestion') . '"',
                '  fi',
                '  # Sans agent.cfg, l agent ne lit pas conf.d : un fichier minimal qui l inclut, et il repart.',
                '  if [ ! -f /etc/glpi-agent/agent.cfg ]; then',
                '    printf "include \\"conf.d/\\"\\n" > /etc/glpi-agent/agent.cfg',
                '    pg_journal "        agent.cfg absent apres installation : fichier minimal pose (include conf.d/)"',
                '    pg_relancer_agent >/dev/null 2>&1 || true',
                '  fi',
                '  pg_etape installation ok ""',
                '  # Un poste installe avant la ToolBox garde peut-etre l ancien scan maison : retire, sinon il scannerait deux fois.',
                '  for pg_f in ' . PluginPrintgestionAgentsetting::LINUX_SCAN_CRON . ' ' . PluginPrintgestionAgentsetting::LINUX_SCAN_SCRIPT . ' ' . PluginPrintgestionAgentsetting::LINUX_SCAN_OLD . '; do',
                '    if [ -e "$pg_f" ]; then rm -f "$pg_f"; pg_journal "        ancien scan retire : $pg_f"; fi',
                '  done',
                '  pg_pct 70',
                '',
                '  pg_maj_ratee=0',
                '  if [ "$pg_maj" = oui ]; then',
                '    pg_etape maj encours ""',
                '    {',
            ],
            PluginPrintgestionAgentsetting::buildLinuxScheduleLines(true, $target),
            [
                '    } >> "$PG_JOURNAL" 2>&1',
                '    if [ -x ' . PluginPrintgestionAgentsetting::LINUX_CRON . ' ]; then',
                '      pg_etape maj ok ' . self::shQuote(__('le 1er du mois à 3 h', 'printgestion')),
                '    else',
                '      pg_maj=non',
                '      pg_maj_ratee=1',
                '      pg_etape maj echec ' . self::shQuote(__('ni curl ni wget sur ce poste', 'printgestion')),
                '    fi',
                '  else',
                '    pg_etape maj saute ' . self::shQuote(__('non demandée', 'printgestion')),
                '  fi',
                '  pg_pct 78',
                '',
            ],
            self::buildShellContactLines(),
            ['  pg_pct 88', ''],
            self::buildShellReportLines($report, '"$pg_maj"'),
            [
                '  # ── Resultat : ce qui s est reellement passe, jamais une promesse ──',
                '  pg_final=' . self::shQuote(sprintf(__('GLPI Agent %s est installé sur ce poste.', 'printgestion'), $version)),
                '  if [ "$pg_maj" = oui ]; then pg_final="${pg_final}¶"' . self::shQuote(__('Mise à jour automatique : posée (le 1er du mois à 3 h).', 'printgestion')) . '; fi',
                '  if [ "$pg_maj_ratee" = 1 ]; then pg_final="${pg_final}¶"' . self::shQuote(__('Mise à jour automatique : non posée (ni curl ni wget). L\'agent fonctionne, seule la mise à jour manque.', 'printgestion')) . '; fi',
                '  pg_final="${pg_final}¶¶"',
                '  if [ "$pg_decouverte" = 1 ]; then',
                '    pg_final="$pg_final"' . self::shQuote(__('Les imprimantes sont déclarées dans GLPI et la découverte vient de partir : rien d\'autre à faire sur ce poste. Le résultat s\'affiche dans GLPI, fiche de l\'entité, onglet « Déploiement Agent ».', 'printgestion')),
                '  elif [ "$pg_scan_local" = 1 ]; then',
                '    pg_final="$pg_final"' . self::shQuote(__('Le scan des imprimantes est confié à la ToolBox de l\'agent, à la cadence choisie : elle envoie elle-même ses résultats à GLPI. Plage, identifiant et tâche se voient et se corrigent sur ce poste, à l\'adresse http://127.0.0.1:62354/toolbox.', 'printgestion')),
                '  elif [ "$pg_reponse" = NOAGENT ]; then',
                '    pg_final="$pg_final"' . self::shQuote(__('GLPI ne connaît pas encore cette sonde : les imprimantes n\'ont pas pu lui être confiées. Vérifier que ce poste joint le serveur GLPI, puis raccorder les imprimantes depuis la fiche de l\'entité, onglet « Déploiement Agent ».', 'printgestion')),
                '  else',
                '    pg_final="$pg_final"' . self::shQuote(__('Dernière étape, dans GLPI : fiche de l\'entité, onglet « Déploiement Agent » — vérifier que la sonde apparaît avec un contact récent, puis raccorder les imprimantes (bloc 3).', 'printgestion')),
                '  fi',
                '  # Les imprimantes trouvees, par leur nom, decouverte GLPI ou ToolBox : c est ce que le technicien vient verifier.',
                '  if [ -n "${pg_noms:-}" ]; then',
                '    pg_nb=$(printf "%s" "$pg_noms" | tr "," "\\n" | grep -c .)',
                '    if [ "${pg_niveaux:-0}" -ge "$pg_nb" ]; then',
                '      pg_fin_niveaux=' . self::shQuote(self::watchTexts()['niveaux']),
                '    elif [ "${pg_niveaux:-0}" -gt 0 ]; then',
                '      pg_fin_niveaux="${pg_niveaux} ' . self::watchTexts()['sur'] . ' ${pg_nb} — ' . self::watchTexts()['reste'] . '"',
                '    else',
                '      pg_fin_niveaux=' . self::shQuote(self::watchTexts()['plus_tard']),
                '    fi',
                '    pg_final="${pg_final}¶¶$pg_nb ' . self::watchTexts()['trouve'] . ', $pg_fin_niveaux"',
                '    pg_final="${pg_final}¶' . self::watchTexts()['liste'] . ' $(printf "%s" "$pg_noms" | sed "s/,$//")"',
                '    pg_final="${pg_final}¶"' . self::shQuote(self::watchTexts()['detail']),
                '  fi',
                '  pg_journal "Journal de l agent : ' . self::AGENT_LOG_UNIX . '"',
                '  pg_final="${pg_final}¶¶"' . self::shQuote(__('Journal de l\'agent :', 'printgestion') . ' ' . self::AGENT_LOG_UNIX),
                '  pg_fin OK "$pg_final"',
                '}',
                '',
                'PG_TRAVAIL=1',
                'if [ "$pg_gui" = zenity ]; then',
                '  pg_suivi_zen | pg_zen --progress --width=560 --title="$PG_TITRE" --text=" " --percentage=0 --no-cancel >/dev/null 2>&1',
                'else',
                '  pg_travail',
                'fi',
                'if [ "$(cat "$PG_ETAT" 2>/dev/null)" = OK ]; then exit 0; fi',
                'exit 1',
                '',
            ]
        ));
    }

    /**
     * Fichier unique macOS : un seul .sh qui fait les quatre gestes que le technicien devait faire à la main —
     * choisir le bon paquet selon la puce, l'installer, déposer local.cfg, relancer le service.
     *
     * Il lit la puce avec uname -m et ne télécharge QUE le paquet de cette puce : la clé ouvre les deux (Apple
     * Silicon et Intel) parce que GLPI ne sait pas, au moment où l'on fabrique le fichier, sur quel Mac il tournera.
     * Elle ne sert quand même qu'une fois, puisque le Mac n'en télécharge qu'un.
     *
     * Aucune question de mise à jour : sur macOS elle est manuelle (réinstaller un paquet plus récent, local.cfg est
     * gardé). Une case à cocher qui ne ferait rien serait un mensonge.
     *
     * @return array ['ok' => bool, 'errors' => string[], 'content', 'filename', 'version', 'tag', 'expires']
     */
    public static function buildMacosSingleFile(Entity $entity): array {
        $blockers = self::getPackageBlockers($entity, 'macos');
        if (!empty($blockers)) {
            return ['ok' => false, 'errors' => $blockers];
        }
        $installers = [];
        foreach (self::PLATFORM_ASSETS['macos'] as $asset) {
            $installers[$asset] = self::getCachedInstaller(true, $asset);
            if ($installers[$asset] === null) {
                return ['ok' => false, 'errors' => [__('Paquet macOS absent ou modifié depuis sa vérification : refaites la vérification (page « Installeur GLPI Agent »).', 'printgestion')]];
            }
        }
        $token = PluginPrintgestionAgenttoken::create((int) $entity->getID(), $installers);
        if ($token === null) {
            return ['ok' => false, 'errors' => [__('Clé de téléchargement non enregistrée : le fichier unique serait incapable de récupérer l\'agent (détail dans le journal printgestion).', 'printgestion')]];
        }
        $tag     = trim((string) $entity->fields['tag']);
        $version = (string) $installers['macos-arm64']['version'];
        return [
            'ok'       => true,
            'errors'   => [],
            'content'  => self::buildMacosSingleFileScript($entity, $installers, $tag, $token, (string) PluginPrintgestionAgenttoken::createReport((int) $entity->getID(), $tag, 'macos')),
            'filename' => self::getSingleFileName($tag, $version, 'macos'),
            'version'  => $version,
            'tag'      => $tag,
            'expires'  => date('Y-m-d H:i:s', time() + PluginPrintgestionAgenttoken::TTL),
        ];
    }

    private static function buildMacosSingleFileScript(Entity $entity, array $installers, string $tag, string $token, string $report): string {
        $version = (string) $installers['macos-arm64']['version'];
        $config  = PluginPrintgestionConfig::getInstance()->fields;
        // « Imposée » : l'administrateur a tranché pour tout le monde, le technicien n'a pas à choisir.
        $update  = (int) ($config['agent_update_default'] ?? 1) === 1;
        $target  = PluginPrintgestionAgentsetting::getTargetVersion();
        $expires = date('d/m/Y H:i', time() + PluginPrintgestionAgenttoken::TTL);
        $size_mb = max(1, (int) round(((int) ($installers['macos-arm64']['size'] ?? 0)) / 1048576));
        $client  = (string) $entity->fields['completename'];
        $server  = self::getServerUrl()['url'];
        $title   = sprintf(__('Installation de GLPI Agent %s', 'printgestion'), $version);
        $plist   = '/Library/LaunchDaemons/com.teclib.glpi-agent.plist';
        $infos   = self::dialogInfoLines($client, $tag, $server);
        $notes   = [
            sprintf(__('« Installer » télécharge le paquet officiel de ce Mac depuis ce serveur (%d Mo), vérifie son empreinte, l\'installe, puis relance l\'agent.', 'printgestion'), $size_mb),
            sprintf(__('Clé de téléchargement : une seule utilisation, jusqu\'au %s.', 'printgestion'), $expires),
        ];
        $steps = [
            'telechargement' => __('Téléchargement du paquet officiel', 'printgestion'),
            'empreinte'      => __('Vérification de l\'empreinte', 'printgestion'),
            'installation'   => sprintf(__('Installation de GLPI Agent %s', 'printgestion'), $version),
            'maj'            => __('Mise à jour automatique', 'printgestion'),
            'contact'        => __('Premier contact avec GLPI', 'printgestion'),
            'declaration'    => __('Compte rendu à GLPI', 'printgestion'),
            'decouverte'     => __('Découverte des imprimantes', 'printgestion'),
        ];
        // Les fréquences, celle d'avance en tête : c'est elle que le menu montre à l'ouverture.
        $choix    = PluginPrintgestionCollectfrequency::getInstallerChoices();
        $ordre    = array_merge([PluginPrintgestionCollectfrequency::INSTALLER_DEFAULT], array_diff(array_keys($choix), [PluginPrintgestionCollectfrequency::INSTALLER_DEFAULT]));
        $libelles = array_map(static fn(string $code): string => $code . '  —  ' . $choix[$code], $ordre);
        // La fenêtre, elle, montre la liste dans l'ordre de Windows, la fréquence d'avance sélectionnée.
        $menu     = array_map(static fn(string $code): string => $code . '  —  ' . $choix[$code], array_keys($choix));
        $defaut   = (int) array_search(PluginPrintgestionCollectfrequency::INSTALLER_DEFAULT, array_keys($choix), true);
        $fenetre  = [
            'fenetre'     => $title,
            'titre'       => __('Sonde d\'inventaire des imprimantes', 'printgestion'),
            'sous_titre'  => sprintf(__('GLPI Agent %s — installation préparée par Print Gestion', 'printgestion'), $version),
            'bande'       => [31, 58, 95],
            'infos'       => implode("\n", $infos),
            'message'     => implode("\n", $notes),
            'formulaire'  => true,
            // La case quand le technicien choisit, la phrase quand l'administrateur a déjà tranché : les mêmes
            // mots que sous Windows, pour que les deux écrans se répondent.
            'lib_maj'     => __('Mettre à jour l\'agent automatiquement (1er du mois à 3 h)', 'printgestion'),
            'maj_impose'  => $update ? sprintf(
                __('Mise à jour automatique : posée sur ce Mac (%s), réglage du serveur GLPI.', 'printgestion'),
                $target !== ''
                    ? sprintf(__('1er du mois à 3 h, version visée %s', 'printgestion'), $target)
                    : __('1er du mois à 3 h', 'printgestion')
            ) : '',
            // Sous la case, ce que « décochée » veut dire — les mêmes phrases que sous Windows, « Mac » remplaçant « PC ».
            'aide_maj'    => $update ? '' : implode(chr(10), array_merge(
                [__('Décochée, l\'agent fonctionne normalement mais restera dans cette version jusqu\'à une intervention sur ce Mac.', 'printgestion')],
                $target !== '' ? [sprintf(__('Version visée : %s.', 'printgestion'), $target)] : []
            )),
            'lib_ips'     => __('Adresses IP des imprimantes de ce client', 'printgestion'),
            'exemple_ips' => '192.168.1.0/24',
            'aide_ips'    => __('Exemples : 192.168.1.0/24 (tout le réseau), 192.168.1.30-35 (une plage), ou des adresses séparées par des virgules. Laissé vide : rien n\'est créé dans GLPI, le raccordement restera à faire.', 'printgestion'),
            'lib_snmp'    => __('Communauté SNMP des imprimantes', 'printgestion'),
            'lib_freq'    => __('Fréquence des relevés', 'printgestion'),
            'aide_freq'   => __('Tous les combien les imprimantes sont relevées. Ce choix règle aussi le délai au-delà duquel GLPI signale qu\'une imprimante ne remonte plus.', 'printgestion'),
            // Qui pilote le scan : deux choix, ou rien du tout quand le serveur n'a pas GLPI Inventory.
            'lib_mode'    => __('Qui pilote le scan des imprimantes', 'printgestion'),
            'modes'       => PluginPrintgestionCollectsetup::isAvailable()
                ? array_map(static fn(array $texte): string => $texte[0], array_values(self::pilotChoices()))
                : [],
            'aide_mode'   => PluginPrintgestionCollectsetup::isAvailable()
                ? implode(chr(10), array_map(static fn(array $texte): string => $texte[0] . ' : ' . $texte[1], array_values(self::pilotChoices())))
                : '',
            'sans_mode'   => PluginPrintgestionCollectsetup::isAvailable() ? '' : self::pilotLocalOnly(),
            'frequences'  => array_values($menu),
            'freq_defaut' => $defaut,
            'bouton'      => __('Installer', 'printgestion'),
            'annuler'     => __('Annuler', 'printgestion'),
            // Trois pages de réglages, comme sous Windows : l'agent, les imprimantes, le scan.
            'suivant'     => __('Suivant', 'printgestion'),
            'precedent'   => __('Précédent', 'printgestion'),
            'ouvrir'      => __('Ouvrir le journal', 'printgestion'),
            'fermer'      => __('Fermer', 'printgestion'),
            'etapes'      => array_map(null, array_keys($steps), array_values($steps)),
            'preparation' => __('Préparation…', 'printgestion'),
            'termine'     => __('Terminé', 'printgestion'),
            'interrompu'  => __('Interrompu', 'printgestion'),
            'journal'     => __('Journal :', 'printgestion'),
            // Quand le script meurt sans donner la fin : la fenêtre le dit et se laisse fermer, au lieu d'attendre.
            'mort'        => __('Le script d\'installation s\'est arrêté sans donner de résultat (erreur, fermeture du terminal ou arrêt forcé) : rien n\'est garanti installé. Voir le journal.', 'printgestion'),
        ];

        return implode("\n", array_merge(
            [
                '#!/bin/sh',
                '# GLPI Agent ' . $version . ' - fichier unique d installation pour le TAG ' . $tag . ' (Print Gestion).',
                '# A lancer en root dans le Terminal : sudo sh ' . self::getSingleFileName($tag, $version, 'macos'),
                '# Il ouvre une fenetre, lit la puce du Mac, telecharge le paquet officiel signe qui lui correspond depuis',
                '# ce serveur GLPI, verifie son empreinte SHA-256, l installe, pose local.cfg et relance l agent.',
                '# Cle de telechargement : une seule utilisation, jusqu au ' . $expires . '. Journal : /var/tmp.',
                'set -u',
                'if [ "$(id -u)" -ne 0 ]; then',
                '  echo "A lancer en root : sudo sh $0"',
                '  exit 1',
                'fi',
                '',
            ],
            self::buildShellJournalLines('installation', [
                sprintf('Installation de GLPI Agent %s, preparee par Print Gestion', $version),
                sprintf('Client : %s   TAG : %s   serveur : %s', $client, $tag, $server),
            ]),
            self::buildShellToolLines(),
            self::buildMacosUiLines($title, array_merge($infos, [''], $notes), $steps, $fenetre),
            self::buildMacosServiceLines($plist),
            [
                '# Le bon paquet, et lui seul : les paquets sont signes et notarises par Teclib, le mauvais est refuse.',
                'PG_ARCH=$(uname -m)',
                'PG_URL=""',
                'PG_SHA=""',
                'PG_FICHIER=""',
                'PG_TAILLE=0',
                'case "$PG_ARCH" in',
                '  arm64)',
                '    PG_URL=' . self::shQuote(PluginPrintgestionAgenttoken::getPullURL($token, 'macos-arm64')),
                '    PG_SHA=' . self::shQuote(strtolower((string) $installers['macos-arm64']['sha256'])),
                '    PG_FICHIER="${TMPDIR:-/tmp}/' . $installers['macos-arm64']['file'] . '"',
                '    PG_TAILLE=' . (int) ($installers['macos-arm64']['size'] ?? 0),
                '    ;;',
                '  x86_64)',
                '    PG_URL=' . self::shQuote(PluginPrintgestionAgenttoken::getPullURL($token, 'macos-x86_64')),
                '    PG_SHA=' . self::shQuote(strtolower((string) $installers['macos-x86_64']['sha256'])),
                '    PG_FICHIER="${TMPDIR:-/tmp}/' . $installers['macos-x86_64']['file'] . '"',
                '    PG_TAILLE=' . (int) ($installers['macos-x86_64']['size'] ?? 0),
                '    ;;',
                'esac',
                'pg_journal "Processeur : $PG_ARCH"',
                '',
                '# ── Premiere page : la fenetre, ou la console si elle ne peut pas s ouvrir ──',
                'pg_ips=""',
                'pg_snmp=""',
                'pg_freq=""',
                'pg_mode=' . (PluginPrintgestionCollectsetup::isAvailable() ? 'glpi' : 'local'),
                'pg_maj=' . ($update ? 'oui' : 'non'),
                'if pg_fenetre; then',
                '  if [ "$(head -n 1 "$PG_DIR/reponses")" = annule ]; then',
                '    pg_journal ' . self::shQuote(__('Installation annulée par le technicien : rien n\'a été installé.', 'printgestion')),
                '    exit 0',
                '  fi',
                '  pg_ips=$(sed -n "s/^ips=//p" "$PG_DIR/reponses")',
                '  pg_snmp=$(sed -n "s/^snmp=//p" "$PG_DIR/reponses")',
                '  pg_freq=$(sed -n "s/^freq=//p" "$PG_DIR/reponses")',
                '  pg_rep_mode=$(sed -n "s/^mode=//p" "$PG_DIR/reponses")',
                '  if [ -n "$pg_rep_mode" ]; then pg_mode="$pg_rep_mode"; fi',
                '  pg_rep_maj=$(sed -n "s/^maj=//p" "$PG_DIR/reponses")',
                '  if [ -n "$pg_rep_maj" ]; then pg_maj="$pg_rep_maj"; fi',
                '  # La communaute SNMP ne reste pas sur le disque plus longtemps que necessaire.',
                '  rm -f "$PG_DIR/reponses"',
                'else',
                '  pg_journal "Fenetre indisponible : questions en console"',
                '  printf "%s\\n\\n%s\\n\\n" "$PG_TITRE" "$PG_INFOS"',
                '  printf "%s " ' . self::shQuote(__('Installer maintenant ? [O/n]', 'printgestion')),
                '  read -r pg_rep',
                '  case "$pg_rep" in',
                '    [nN]*)',
                '      pg_journal ' . self::shQuote(__('Installation annulée par le technicien : rien n\'a été installé.', 'printgestion')),
                '      echo ' . self::shQuote(__('Installation annulée : rien n\'a été installé.', 'printgestion')),
                '      exit 0',
                '      ;;',
                '  esac',
                '  printf "%s : " ' . self::shQuote(__('Adresses IP des imprimantes (192.168.1.0/24, 192.168.1.30-35, ou une liste ; vide : aucune)', 'printgestion')),
                '  read -r pg_ips',
                '  printf "%s [public] : " ' . self::shQuote(__('Communauté SNMP des imprimantes', 'printgestion')),
                '  read -r pg_snmp',
                '  printf "%s\\n" ' . self::shQuote(__('Tous les combien les imprimantes sont-elles relevées ?', 'printgestion')),
            ],
            array_map(static fn(int $i, string $libelle): string => '  printf "%s\\n" ' . self::shQuote('  ' . ($i + 1) . ') ' . $libelle), array_keys($libelles), $libelles),
            [
                '  printf "%s " ' . self::shQuote(__('Numéro [1] :', 'printgestion')),
                '  read -r pg_num',
                '  case "${pg_num:-1}" in',
            ],
            array_map(static fn(int $i, string $code): string => '    ' . ($i + 1) . ') pg_freq=' . $code . ' ;;', array_keys($ordre), $ordre),
            [
                '  esac',
            ],
            // Question posée seulement si l'administrateur laisse le choix : sinon, elle serait sans effet.
            $update ? [] : [
                '  printf "%s " ' . self::shQuote(__('Mettre à jour l\'agent automatiquement (1er du mois à 3 h) ? [o/N]', 'printgestion')),
                '  read -r pg_rep',
                '  case "$pg_rep" in [oOyY]*) pg_maj=oui ;; esac',
            ],
            [
                'fi',
                'if [ -z "$pg_snmp" ]; then pg_snmp=public; fi',
                'if [ -z "$pg_freq" ]; then pg_freq=' . PluginPrintgestionCollectfrequency::INSTALLER_DEFAULT . '; fi',
                'if [ -n "$pg_ips" ]; then pg_journal "Adresses des imprimantes : $pg_ips"; else pg_journal "Adresses des imprimantes : aucune"; fi',
                '# Jamais la communaute elle-meme : c est un secret, et ce journal reste dans /var/tmp.',
                'pg_journal "Communaute SNMP : renseignee (jamais ecrite dans ce journal)"',
                'pg_journal "Frequence des releves : $pg_freq"',
                'pg_journal "Mise a jour automatique : $pg_maj"',
                'pg_journal "Pilotage du scan : $pg_mode"',
                '',
                'pg_travail() {',
                '  pg_pct 2',
                '  pg_etape telechargement encours ""',
                '  if [ -z "$PG_URL" ]; then pg_echec "' . __('Processeur inconnu ($PG_ARCH) : ce Mac n\'est ni Apple Silicon ni Intel. Rien n\'a été installé.', 'printgestion') . '"; fi',
                '  if [ "$pg_gui" = cocoa ]; then pg_telecharger_suivi "$PG_URL" "$PG_FICHIER" "$PG_TAILLE"; else pg_telecharger "$PG_URL" "$PG_FICHIER"; fi',
                '  if [ "$?" -ne 0 ]; then pg_echec ' . self::shQuote(__('Téléchargement impossible : rien n\'a été installé. Causes habituelles : clé déjà utilisée ou expirée (régénérer le fichier dans GLPI), serveur GLPI injoignable depuis ce Mac, ou certificat HTTPS inconnu.', 'printgestion')) . '; fi',
                '  pg_journal "        recu : $(wc -c < "$PG_FICHIER" | tr -d " ") octets"',
                '  pg_etape telechargement ok ""',
                '  pg_pct 40',
                '',
                '  pg_etape empreinte encours ""',
                '  pg_reelle=$(pg_empreinte "$PG_FICHIER")',
                '  pg_journal "        attendue : $PG_SHA"',
                '  pg_journal "        recue    : $pg_reelle"',
                '  if [ -z "$pg_reelle" ]; then pg_echec ' . self::shQuote(__('Aucun outil d\'empreinte sur ce Mac : rien n\'a été installé, faute de pouvoir vérifier ce qui a été téléchargé.', 'printgestion')) . '; fi',
                '  if [ "$pg_reelle" != "$PG_SHA" ]; then',
                '    rm -f "$PG_FICHIER"',
                '    pg_echec ' . self::shQuote(__('Le fichier téléchargé n\'est pas celui attendu (empreinte différente) : rien n\'a été installé. Prévenir l\'administrateur.', 'printgestion')),
                '  fi',
                '  pg_etape empreinte ok ""',
                '  pg_pct 50',
                '',
                '  pg_etape installation encours ""',
                '  pg_detail ' . self::shQuote(__('Environ une minute. Aucune question ne sera posée.', 'printgestion')),
                '  # Ce que dit l installeur d Apple part au journal : c est la qu on le relira en cas de refus.',
                '  if ! installer -pkg "$PG_FICHIER" -target / >> "$PG_JOURNAL" 2>&1; then',
                '    pg_echec ' . self::shQuote(__('Installation du paquet refusée : rien d\'autre n\'a été fait. Ouvrir Réglages Système > Confidentialité et sécurité, puis relancer.', 'printgestion')),
                '  fi',
                '  # local.cfg apres l installation : c est le paquet qui cree /Applications/GLPI-Agent.',
                '  mkdir -p /Applications/GLPI-Agent/etc/conf.d',
                "  cat > /Applications/GLPI-Agent/etc/conf.d/local.cfg <<'PRINTGESTION_EOF'",
                rtrim(self::buildAgentConfig($tag, true), "\n"),
                'PRINTGESTION_EOF',
                '  # Relance du service pour qu il relise local.cfg.',
                '  if ! pg_relancer_agent; then',
                '    pg_echec ' . self::shQuote(__('Agent installé et configuré, mais le service n\'a pas redémarré : redémarrer le Mac, puis vérifier dans GLPI que la sonde apparaît.', 'printgestion')),
                '  fi',
                '  pg_etape installation ok ""',
                '  pg_pct 70',
                '',
                '  pg_maj_ratee=0',
                '  if [ "$pg_maj" = oui ]; then',
                '    pg_etape maj encours ""',
                '    {',
            ],
            PluginPrintgestionAgentsetting::buildMacosScheduleLines(true, $target),
            [
                '    } >> "$PG_JOURNAL" 2>&1',
                '    if [ -f ' . PluginPrintgestionAgentsetting::MACOS_PLIST . ' ] && [ -x ' . PluginPrintgestionAgentsetting::MACOS_SCRIPT . ' ]; then',
                '      pg_etape maj ok ' . self::shQuote(__('le 1er du mois à 3 h', 'printgestion')),
                '    else',
                '      pg_maj=non',
                '      pg_maj_ratee=1',
                '      pg_etape maj echec ' . self::shQuote(__('service non posé (voir le journal)', 'printgestion')),
                '    fi',
                '  else',
                '    pg_etape maj saute ' . self::shQuote(__('non demandée', 'printgestion')),
                '  fi',
                '  pg_pct 78',
                '',
            ],
            self::buildShellContactLines(),
            ['  pg_pct 88', ''],
            self::buildShellReportLines($report, '"$pg_maj"'),
            [
                '  pg_final=' . self::shQuote(sprintf(__('GLPI Agent %s est installé sur ce Mac.', 'printgestion'), $version)),
                '  if [ "$pg_maj" = oui ]; then pg_final="${pg_final}¶"' . self::shQuote(__('Mise à jour automatique : posée (le 1er du mois à 3 h).', 'printgestion')) . '; fi',
                '  if [ "$pg_maj_ratee" = 1 ]; then pg_final="${pg_final}¶"' . self::shQuote(__('Mise à jour automatique : non posée (voir le journal). L\'agent fonctionne, seule la mise à jour manque.', 'printgestion')) . '; fi',
                '  if [ "$pg_maj" != oui ] && [ "$pg_maj_ratee" != 1 ]; then pg_final="${pg_final}¶"' . self::shQuote(__('Mise à jour : à faire à la main (relancer un fichier d\'installation plus récent ; la configuration est gardée).', 'printgestion')) . '; fi',
                '  pg_final="${pg_final}¶¶"',
                '  if [ "$pg_decouverte" = 1 ]; then',
                '    pg_final="$pg_final"' . self::shQuote(__('Les imprimantes sont déclarées dans GLPI et la découverte vient de partir : rien d\'autre à faire sur ce Mac. Le résultat s\'affiche dans GLPI, fiche de l\'entité, onglet « Déploiement Agent ».', 'printgestion')),
                '  elif [ "$pg_scan_local" = 1 ]; then',
                '    pg_final="$pg_final"' . self::shQuote(__('Le scan des imprimantes est confié à la ToolBox de l\'agent, à la cadence choisie : elle envoie elle-même ses résultats à GLPI. Plage, identifiant et tâche se voient et se corrigent sur ce Mac, à l\'adresse http://127.0.0.1:62354/toolbox.', 'printgestion')),
                '  elif [ "$pg_reponse" = NOAGENT ]; then',
                '    pg_final="$pg_final"' . self::shQuote(__('GLPI ne connaît pas encore cette sonde : les imprimantes n\'ont pas pu lui être confiées. Vérifier que ce Mac joint le serveur GLPI, puis raccorder les imprimantes depuis la fiche de l\'entité, onglet « Déploiement Agent ».', 'printgestion')),
                '  else',
                '    pg_final="$pg_final"' . self::shQuote(__('Dernière étape, dans GLPI : fiche de l\'entité, onglet « Déploiement Agent » — vérifier que la sonde apparaît avec un contact récent, puis raccorder les imprimantes (bloc 3).', 'printgestion')),
                '  fi',
                '  # Les imprimantes trouvees, par leur nom, decouverte GLPI ou ToolBox : c est ce que le technicien vient verifier.',
                '  if [ -n "${pg_noms:-}" ]; then',
                '    pg_nb=$(printf "%s" "$pg_noms" | tr "," "\\n" | grep -c .)',
                '    if [ "${pg_niveaux:-0}" -ge "$pg_nb" ]; then',
                '      pg_fin_niveaux=' . self::shQuote(self::watchTexts()['niveaux']),
                '    elif [ "${pg_niveaux:-0}" -gt 0 ]; then',
                '      pg_fin_niveaux="${pg_niveaux} ' . self::watchTexts()['sur'] . ' ${pg_nb} — ' . self::watchTexts()['reste'] . '"',
                '    else',
                '      pg_fin_niveaux=' . self::shQuote(self::watchTexts()['plus_tard']),
                '    fi',
                '    pg_final="${pg_final}¶¶$pg_nb ' . self::watchTexts()['trouve'] . ', $pg_fin_niveaux"',
                '    pg_final="${pg_final}¶' . self::watchTexts()['liste'] . ' $(printf "%s" "$pg_noms" | sed "s/,$//")"',
                '    pg_final="${pg_final}¶"' . self::shQuote(self::watchTexts()['detail']),
                '  fi',
                '  pg_journal "Journal de l agent : ' . self::AGENT_LOG_UNIX . '"',
                '  pg_final="${pg_final}¶¶"' . self::shQuote(__('Journal de l\'agent :', 'printgestion') . ' ' . self::AGENT_LOG_UNIX),
                '  pg_fin OK "$pg_final"',
                '}',
                '',
                'pg_travail',
                'if [ "$(cat "$PG_ETAT" 2>/dev/null)" = OK ]; then exit 0; fi',
                'exit 1',
                '',
            ]
        ));
    }

    // ── Paquets Linux et macOS ────────────────────────────────────────────────

    /**
     * Configuration de GLPI Agent au format agent.cfg : mêmes réglages que les propriétés MSI (valeurs contrôlées en
     * amont). $full : serveur, TAG, adresses autorisées et tâches réseau en plus des réessais SNMP (macOS, dont le
     * paquet ne règle que la tâche d'inventaire du poste).
     */
    /** Journal de l'agent sous Linux et macOS : un fichier que la configuration posée par le plugin lui donne. */
    const AGENT_LOG_UNIX = '/var/log/glpi-agent.log';

    public static function buildAgentConfig(string $tag, bool $full): string {
        $lines = ['# GLPI Agent : configuration posee par Print Gestion pour le TAG ' . $tag . '. Aucun identifiant ni secret.'];
        if ($full) {
            $lines[] = 'server = ' . self::getServerUrl()['url'];
            $lines[] = 'tag = ' . $tag;
            $lines[] = 'tasks = inventory,netdiscovery,netinventory';
            $lines[] = 'httpd-trust = ' . self::getHttpdTrust();
        }
        $lines[] = '# Un paquet SNMP perdu ne fait plus disparaitre les consommables d un releve (0 par defaut).';
        $lines[] = 'snmp-retries = ' . self::SNMP_RETRIES;
        $lines[] = '# Journal de l agent dans un fichier, pour relire ce qu il a fait : 4 Mo au plus, comme le MSI sous Windows.';
        $lines[] = 'logger = file';
        $lines[] = 'logfile = ' . self::AGENT_LOG_UNIX;
        $lines[] = 'logfile-maxsize = 4';
        return implode("\n", $lines) . "\n";
    }

    /** Commande de l'installeur Linux officiel, sans question : réglages passés en options (valeurs contrôlées en amont). */
    public static function buildLinuxCommand(string $installer, string $tag): string {
        // --silent pour la même raison que /qn sous Windows : tout est passé en options, il ne reste rien à demander.
        return sprintf(
            'perl %s --install --silent --type=network --server="%s" --tag="%s" --httpd-trust="%s" --runnow',
            $installer,
            self::getServerUrl()['url'],
            $tag,
            self::getHttpdTrust()
        );
    }

    /**
     * Script lancé en root sur le PC sonde Linux (LF) : réessais SNMP dans conf.d (option absente de l'installeur,
     * gardée aux mises à jour), installeur officiel avec l'inventaire réseau, puis tâche cron mensuelle de mise à jour
     * si elle est activée.
     */
    public static function buildLinuxInstallScript(string $installer, string $tag, string $version, bool $update, string $target): string {
        return implode("\n", array_merge(
            [
                '#!/bin/sh',
                '# GLPI Agent ' . $version . ' - installation pre-parametree pour le TAG ' . $tag . ' (Print Gestion).',
                '# Aucun identifiant ni secret : adresse du serveur GLPI et TAG uniquement.',
                '# A lancer en root depuis le dossier extrait : sudo sh installer-glpi-agent.sh',
                'cd "$(dirname "$0")" || exit 1',
                'if [ "$(id -u)" -ne 0 ]; then',
                '  echo "A lancer en root : sudo sh installer-glpi-agent.sh"',
                '  exit 1',
                'fi',
                'mkdir -p /etc/glpi-agent/conf.d',
                "cat > /etc/glpi-agent/conf.d/90-printgestion.cfg <<'PRINTGESTION_EOF'",
                rtrim(self::buildAgentConfig($tag, false), "\n"),
                'PRINTGESTION_EOF',
                self::buildLinuxCommand($installer, $tag),
                'RC=$?',
                'if [ "$RC" -ne 0 ]; then',
                '  echo "Installation non terminee, code $RC : relancer avec --verbose pour le detail."',
                '  exit "$RC"',
                'fi',
            ],
            ['echo "GLPI Agent installe."'],
            $update ? PluginPrintgestionAgentsetting::buildLinuxScheduleLines(true, $target) : ['echo "Aucune mise a jour automatique posee."'],
            ['']
        ));
    }

    /**
     * Paquet Linux d'une entité (.tar.gz, dossier unique), fichier temporaire que l'appelant supprime : installeur
     * Perl officiel vérifié, script d'installation, commande seule, note d'une page.
     *
     * @return array ['ok' => bool, 'errors' => string[], 'path', 'filename', 'version', 'tag']
     */
    public static function buildLinuxPackage(Entity $entity): array {
        $started  = microtime(true);
        $blockers = self::getPackageBlockers($entity, 'linux');
        if (!empty($blockers)) {
            return ['ok' => false, 'errors' => $blockers];
        }
        $installer = self::getCachedInstaller(true, 'linux');
        if ($installer === null) {
            return ['ok' => false, 'errors' => [__('Installeur Linux absent ou modifié depuis sa vérification : refaites la vérification (page « Installeur GLPI Agent »).', 'printgestion')]];
        }
        if (!class_exists(PharData::class)) {
            return ['ok' => false, 'errors' => [__('Extension PHP Phar absente du serveur : archive Linux impossible à produire.', 'printgestion')]];
        }

        $tag     = trim((string) $entity->fields['tag']);
        $version = (string) $installer['version'];
        $folder  = sprintf('GLPI-Agent-%s-linux-%s', $version, $tag);
        $config  = PluginPrintgestionConfig::getInstance()->fields;
        $update  = (int) ($config['agent_update_default'] ?? 1) === 1;
        // Une seule version pour tout : celle que ce serveur distribue est aussi celle que les sondes visent.
        $target  = PluginPrintgestionAgentsetting::getTargetVersion();
        $readme  = implode("\n", [
            sprintf(__('Installation de GLPI Agent %1$s pour Linux — %2$s (TAG : %3$s)', 'printgestion'), $version, (string) $entity->fields['completename'], $tag),
            sprintf(__('Paquet généré par Print Gestion le %1$s par %2$s.', 'printgestion'), Html::convDateTime(date('Y-m-d H:i:s')), getUserName((int) Session::getLoginUserID())),
            __('Ce dossier ne contient aucun identifiant, mot de passe ni jeton : seulement l\'adresse du serveur GLPI et le TAG du client.', 'printgestion'),
            __('Distributions prises en charge par l\'installeur officiel : Debian, Ubuntu, Red Hat, CentOS, Fedora, openSUSE, AlmaLinux, Rocky Linux, Oracle Linux.', 'printgestion'),
            '',
            __('Les 3 gestes', 'printgestion'),
            sprintf(__('1. Sur le PC qui servira de sonde (allumé en permanence, sur le réseau des imprimantes), dans un terminal : tar -xzf %s.tar.gz', 'printgestion'), $folder),
            sprintf(__('2. cd %s puis sudo sh installer-glpi-agent.sh — l\'installeur officiel installe l\'agent avec la découverte et l\'inventaire réseau ; l\'adresse du serveur et le TAG sont déjà réglés, aucune question n\'est posée.', 'printgestion'), $folder),
            __('3. Dans GLPI (fiche de l\'entité, onglet « Déploiement Agent ») : vérifier que l\'agent apparaît avec un contact récent, puis raccorder les imprimantes avec l\'assistant (bloc 3), avant de partir.', 'printgestion'),
            '',
            PluginPrintgestionCollectfrequency::getPackageLine((int) $entity->getID()),
            $update
                ? sprintf(
                    __('Mise à jour automatique : le script pose la tâche cron mensuelle %1$s (%2$s ; installeur officiel téléchargé sur GitHub, empreinte vérifiée ; seulement si l\'agent est en attente ; journal /var/log/glpi-agent-printgestion-update.log ; curl nécessaire). Pour la changer ou la retirer : régler la sonde dans GLPI, puis lancer son paquet de consigne sur ce PC ; le réglage de GLPI seul ne change rien sur le PC.', 'printgestion'),
                    PluginPrintgestionAgentsetting::LINUX_CRON,
                    $target !== '' ? sprintf(__('version cible %s', 'printgestion'), $target) : __('dernière version publiée', 'printgestion')
                )
                : __('Mise à jour automatique : non posée par ce paquet. Pour la poser plus tard : paquet de consigne de la sonde dans GLPI.', 'printgestion'),
            '',
            __('Commande seule (commande.txt, en root) : elle installe l\'agent sans le réglage des réessais SNMP ni la mise à jour automatique.', 'printgestion'),
            __('En cas d\'échec : relancer la commande avec --verbose pour le détail.', 'printgestion'),
            '',
        ]);

        $base = GLPI_TMP_DIR . '/printgestion-agent-' . bin2hex(random_bytes(8));
        try {
            $archive = new PharData($base . '.tar');
            $archive->addFile($installer['path'], $folder . '/' . $installer['file']);
            $archive->addFromString($folder . '/installer-glpi-agent.sh', self::buildLinuxInstallScript($installer['file'], $tag, $version, $update, $target));
            $archive[$folder . '/installer-glpi-agent.sh']->chmod(0755);
            $archive->addFromString($folder . '/commande.txt', self::buildLinuxCommand($installer['file'], $tag) . "\n");
            $archive->addFromString($folder . '/LISEZMOI.txt', $readme);
            $archive->compress(Phar::GZ);
            unset($archive);
        } catch (Throwable $e) {
            PluginPrintgestionLogger::error('agentdeploy', sprintf('Paquet Linux %s non créé.', $base), $e);
            foreach (['.tar', '.tar.gz'] as $extension) {
                if (is_file($base . $extension)) {
                    unlink($base . $extension);
                }
            }
            return ['ok' => false, 'errors' => [__('Paquet non généré (détail dans le journal printgestion).', 'printgestion')]];
        }
        if (is_file($base . '.tar')) {
            unlink($base . '.tar');
        }
        PluginPrintgestionLogger::duration('agentdeploy', 'Paquet Linux (.tar.gz) produit', $started, sprintf('TAG %s, %d octets', $tag, (int) filesize($base . '.tar.gz')));
        return ['ok' => true, 'errors' => [], 'path' => $base . '.tar.gz', 'filename' => $folder . '.tar.gz', 'version' => $version, 'tag' => $tag];
    }

    public static function getMacosFolder(string $version, string $tag): string {
        return sprintf('GLPI-Agent-%s-macos-%s', $version, $tag);
    }

    /** Commandes à coller dans le Terminal du Mac sonde (ZIP extrait dans Téléchargements), macOS 13 ou plus. */
    public static function getMacosCommands(string $version, string $tag): array {
        return [
            'sudo cp ~/Downloads/' . self::getMacosFolder($version, $tag) . '/local.cfg /Applications/GLPI-Agent/etc/conf.d/local.cfg',
            'sudo launchctl bootout system /Library/LaunchDaemons/com.teclib.glpi-agent.plist',
            'sudo launchctl bootstrap system /Library/LaunchDaemons/com.teclib.glpi-agent.plist',
        ];
    }

    /** Gestes sur le Mac sonde, pour la note du paquet et l'onglet de l'entité. */
    public static function getMacosSteps(string $version, string $tag): array {
        $assets = self::getAssets($version);
        return [
            sprintf(__('1. Sur le Mac qui servira de sonde : double-clic sur le fichier ZIP téléchargé (dossier %s dans Téléchargements).', 'printgestion'), self::getMacosFolder($version, $tag)),
            sprintf(
                __('2. Double-clic sur le paquet de ce Mac et suivre l\'installeur : %1$s si « À propos de ce Mac » indique une puce Apple, %2$s pour un processeur Intel. Paquets signés et notarisés par Teclib ; le mauvais paquet est refusé.', 'printgestion'),
                $assets['macos-arm64']['file'],
                $assets['macos-x86_64']['file']
            ),
            __('3. Ouvrir Terminal (Applications > Utilitaires), coller les trois commandes ci-dessous une par une (mot de passe administrateur demandé) : dépôt de local.cfg, arrêt puis redémarrage de l\'agent. Si le dossier n\'est pas dans Téléchargements, glisser le fichier local.cfg dans la fenêtre du Terminal à la place du chemin.', 'printgestion'),
            __('4. Dans GLPI (fiche de l\'entité, onglet « Déploiement Agent ») : vérifier que l\'agent apparaît avec un contact récent, puis raccorder les imprimantes avec l\'assistant (bloc 3), avant de partir.', 'printgestion'),
        ];
    }

    /**
     * Paquet macOS d'une entité (ZIP), fichier temporaire que l'appelant supprime : les deux paquets officiels
     * vérifiés (Apple Silicon, Intel), local.cfg à déposer dans /Applications/GLPI-Agent/etc/conf.d, note avec la
     * procédure. Pas de fichier .command ni de mise à jour automatique dans ce recours (le fichier unique, lui, la pose).
     *
     * @return array ['ok' => bool, 'errors' => string[], 'path', 'filename', 'version', 'tag']
     */
    public static function buildMacosPackage(Entity $entity): array {
        $started  = microtime(true);
        $blockers = self::getPackageBlockers($entity, 'macos');
        if (!empty($blockers)) {
            return ['ok' => false, 'errors' => $blockers];
        }
        $installers = [];
        foreach (self::PLATFORM_ASSETS['macos'] as $asset) {
            $installers[$asset] = self::getCachedInstaller(true, $asset);
            if ($installers[$asset] === null) {
                return ['ok' => false, 'errors' => [__('Paquet macOS absent ou modifié depuis sa vérification : refaites la vérification (page « Installeur GLPI Agent »).', 'printgestion')]];
            }
        }

        $tag     = trim((string) $entity->fields['tag']);
        $version = (string) $installers['macos-arm64']['version'];
        $readme  = implode("\r\n", array_merge(
            [
                sprintf(__('Installation de GLPI Agent %1$s pour macOS — %2$s (TAG : %3$s)', 'printgestion'), $version, (string) $entity->fields['completename'], $tag),
                sprintf(__('Paquet généré par Print Gestion le %1$s par %2$s.', 'printgestion'), Html::convDateTime(date('Y-m-d H:i:s')), getUserName((int) Session::getLoginUserID())),
                __('Ce dossier ne contient aucun identifiant, mot de passe ni jeton : seulement l\'adresse du serveur GLPI et le TAG du client.', 'printgestion'),
                '',
            ],
            self::getMacosSteps($version, $tag),
            [''],
            self::getMacosCommands($version, $tag),
            [
                '',
                PluginPrintgestionCollectfrequency::getPackageLine((int) $entity->getID()),
                __('macOS 12 ou antérieur : remplacer les deux dernières commandes par « sudo launchctl unload » puis « sudo launchctl load » suivis du même chemin.', 'printgestion'),
                __('Mise à jour : manuelle, en réinstallant le paquet d\'une version plus récente (onglet « Déploiement Agent » de l\'entité) ; local.cfg est gardé.', 'printgestion'),
                '',
            ]
        ));

        $path = GLPI_TMP_DIR . '/printgestion-agent-' . bin2hex(random_bytes(8)) . '.zip';
        $zip  = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            PluginPrintgestionLogger::error('agentdeploy', sprintf('Paquet macOS %s non créé.', $path));
            return ['ok' => false, 'errors' => [__('Paquet non généré (détail dans le journal printgestion).', 'printgestion')]];
        }
        foreach ($installers as $installer) {
            $zip->addFile($installer['path'], $installer['file']);
            $zip->setCompressionName($installer['file'], ZipArchive::CM_STORE); // paquets déjà compressés
        }
        $zip->addFromString('local.cfg', self::buildAgentConfig($tag, true));
        $zip->addFromString('LISEZMOI.txt', "\xEF\xBB\xBF" . $readme);
        if (!$zip->close()) {
            if (is_file($path)) {
                unlink($path);
            }
            PluginPrintgestionLogger::error('agentdeploy', sprintf('Paquet macOS %s non finalisé.', $path));
            return ['ok' => false, 'errors' => [__('Paquet non généré (détail dans le journal printgestion).', 'printgestion')]];
        }
        PluginPrintgestionLogger::duration('agentdeploy', 'Paquet macOS (ZIP) produit', $started, sprintf('TAG %s, %d octets', $tag, (int) filesize($path)));
        return ['ok' => true, 'errors' => [], 'path' => $path, 'filename' => self::getMacosFolder($version, $tag) . '.zip', 'version' => $version, 'tag' => $tag];
    }

    // ── Vérifications ─────────────────────────────────────────────────────────

    /**
     * Règles d'affectation d'entité qui utilisent l'action « Entity from TAG ».
     *
     * @return array ['rules' => lignes, 'active' => ?ligne (première active), 'earlier' => règles
     *               actives jouées avant elle, 'tag_criterion' => bool]
     */
    public static function getTagRuleStatus(): array {
        global $DB;

        $rules = [];
        foreach ($DB->request([
            'SELECT'     => ['r.id', 'r.name', 'r.ranking', 'r.is_active'],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_rules AS r',
            'INNER JOIN' => ['glpi_ruleactions AS a' => ['ON' => ['a' => 'rules_id', 'r' => 'id']]],
            'WHERE'      => ['r.sub_type' => 'RuleImportEntity', 'a.field' => '_affect_entity_by_tag'],
            'ORDER'      => ['r.ranking'],
        ]) as $rule) {
            $rules[] = $rule;
        }

        $active = null;
        foreach ($rules as $rule) {
            if ((int) $rule['is_active'] === 1) {
                $active = $rule;
                break;
            }
        }
        $earlier       = [];
        $tag_criterion = false;
        if ($active !== null) {
            foreach ($DB->request([
                'SELECT' => ['id', 'name', 'ranking'],
                'FROM'   => 'glpi_rules',
                'WHERE'  => ['sub_type' => 'RuleImportEntity', 'is_active' => 1, 'ranking' => ['<', (int) $active['ranking']]],
                'ORDER'  => ['ranking'],
            ]) as $rule) {
                $earlier[] = $rule;
            }
            $tag_criterion = countElementsInTable('glpi_rulecriterias', ['rules_id' => (int) $active['id'], 'criteria' => 'tag']) > 0;
        }
        return ['rules' => $rules, 'active' => $active, 'earlier' => $earlier, 'tag_criterion' => $tag_criterion];
    }

    /** Agents rattachés à une entité, du plus récent au plus ancien contact. */
    public static function getEntityAgents(int $entities_id): array {
        global $DB;

        $agents = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'version', 'tag', 'last_contact', 'itemtype', 'items_id', 'use_module_network_discovery', 'use_module_network_inventory'],
            'FROM'   => Agent::getTable(),
            'WHERE'  => ['entities_id' => $entities_id],
            'ORDER'  => ['last_contact DESC'],
        ]) as $agent) {
            // Version : texte simple (« 1.19 », que la lecture JSON rend en nombre), ou versions
            // par module (même lecture que la fiche Agent native).
            $modules = importArrayFromDB((string) $agent['version']);
            $agent['version_value'] = is_array($modules) && !empty($modules)
                ? (string) reset($modules)
                : trim((string) $agent['version']);
            $agents[] = $agent;
        }
        return $agents;
    }

    // ── Affichage ─────────────────────────────────────────────────────────────

    /** Ligne de vérification : icône, libellé, détail (HTML déjà échappé). */
    private static function checkItem(bool $ok, string $label, string $detail_html): string {
        return "<div class='col-md-6 col-xl-4'><div class='d-flex align-items-start'>"
            . "<i class='ti " . ($ok ? 'ti-circle-check text-success' : 'ti-alert-triangle text-warning') . " fs-2 me-2'></i>"
            . "<div><div class='fw-bold'>" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</div>"
            . "<div class='text-muted small'>" . $detail_html . "</div></div></div></div>";
    }

    /**
     * TAG proposé pour une entité : son nom en majuscules, sans accents, sans espaces ni caractères spéciaux
     * (« Ville d'Exemple » → VILLEDEXEMPLE). Chaîne vide si le nom n'en contient aucun.
     */
    public static function normalizeTag(string $name): string {
        static $transliterator = null;
        if ($transliterator === null) {
            $transliterator = Transliterator::create('Any-Latin; Latin-ASCII; Upper()') ?: false;
        }
        $text = $transliterator ? (string) $transliterator->transliterate($name) : mb_strtoupper($name);
        return mb_substr((string) preg_replace('/[^A-Z0-9]+/', '', strtoupper($text)), 0, 100);
    }

    /** Autres entités portant ce TAG (identifiant => nom complet). */
    public static function getTagOwners(string $tag, int $except_entities_id): array {
        global $DB;

        $owners = [];
        if ($tag === '') {
            return $owners;
        }
        foreach ($DB->request([
            'SELECT' => ['id', 'completename'],
            'FROM'   => Entity::getTable(),
            'WHERE'  => ['tag' => $tag, 'NOT' => ['id' => $except_entities_id]],
        ]) as $row) {
            $owners[(int) $row['id']] = (string) $row['completename'];
        }
        return $owners;
    }

    /**
     * Écrit le TAG d'une entité (action « Créer le TAG ») : droit natif de modification de l'entité, TAG valide,
     * jamais un TAG déjà porté par une autre entité (un équipement partirait chez le mauvais client).
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public static function createTag(int $entities_id, string $tag): array {
        $entity = new Entity();
        if (!$entity->getFromDB($entities_id) || !$entity->can($entities_id, UPDATE)) {
            return ['ok' => false, 'message' => __('Entité introuvable ou hors de vos droits de modification.', 'printgestion')];
        }
        $tag = trim($tag);
        if ($tag === '' || !self::isValidTag($tag)) {
            return ['ok' => false, 'message' => __('TAG invalide : lettres, chiffres, point, tiret ou soulignement.', 'printgestion')];
        }
        $owners = self::getTagOwners($tag, $entities_id);
        if (!empty($owners)) {
            return ['ok' => false, 'message' => sprintf(__('TAG « %1$s » déjà utilisé par l\'entité « %2$s » : choisissez-en un autre.', 'printgestion'), $tag, reset($owners))];
        }
        if (!$entity->update(['id' => $entities_id, 'tag' => $tag])) {
            return ['ok' => false, 'message' => __('TAG non enregistré : modification refusée par GLPI.', 'printgestion')];
        }
        $message = sprintf(__('TAG « %s » enregistré sur l\'entité.', 'printgestion'), $tag);
        // Sans règle d'affectation par TAG active, le TAG ne sert à rien (une règle désactivée n'affecte rien) : même
        // clic, la règle générique est créée ou activée pour toutes les entités si le compte en a les droits, sinon
        // l'administrateur est prévenu.
        $status = self::getTagRuleStatus();
        if ($status['active'] === null) {
            if (empty($status['rules']) && self::canCreateTagRule()) {
                $message .= ' ' . self::createTagRule()['message'];
            } elseif (!empty($status['rules']) && self::canActivateTagRule()) {
                $message .= ' ' . self::activateTagRule()['message'];
            } else {
                $message .= ' ' . (empty($status['rules'])
                    ? __('Règle d\'affectation par TAG absente : l\'administrateur doit la créer, les équipements n\'arriveraient pas dans cette entité.', 'printgestion')
                    : __('Règle d\'affectation par TAG désactivée : l\'administrateur doit l\'activer, les équipements n\'arriveraient pas dans cette entité.', 'printgestion'));
            }
        }
        return ['ok' => true, 'message' => $message];
    }

    /** Droit de créer la règle d'affectation par TAG : administrateur du plugin ET droit natif sur les règles d'import. */
    public static function canCreateTagRule(): bool {
        return Session::haveRight('plugin_printgestion_config', UPDATE) && Session::haveRight('rule_import', CREATE);
    }

    /** Droit d'activer la règle d'affectation par TAG désactivée : administrateur du plugin ET droit natif de modifier les règles d'import. */
    public static function canActivateTagRule(): bool {
        return Session::haveRight('plugin_printgestion_config', UPDATE) && Session::haveRight('rule_import', UPDATE);
    }

    /**
     * Active, sur clic explicite d'un administrateur, la règle d'affectation par TAG présente mais désactivée (la
     * première dans l'ordre des règles). Rien n'est créé ni déplacé ; sans effet si une règle est déjà active.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public static function activateTagRule(): array {
        if (!self::canActivateTagRule()) {
            return ['ok' => false, 'message' => __('Activation de la règle réservée à l\'administrateur (droit de configuration du plugin et droit de modifier les règles d\'import).', 'printgestion')];
        }
        $status = self::getTagRuleStatus();
        if ($status['active'] !== null || empty($status['rules'])) {
            return ['ok' => false, 'message' => self::describeTagRule($status)];
        }
        $rules_id = (int) $status['rules'][0]['id'];
        if (!(new RuleImportEntity())->update(['id' => $rules_id, 'is_active' => 1])) {
            PluginPrintgestionLogger::error('agentdeploy', sprintf('Règle d\'affectation par TAG %d non activée : refusée par GLPI.', $rules_id));
            return ['ok' => false, 'message' => __('Règle non activée : refusée par GLPI (détail dans le journal du plugin).', 'printgestion')];
        }
        return ['ok' => true, 'message' => __('Règle activée. ', 'printgestion') . self::describeTagRule(self::getTagRuleStatus())];
    }

    /**
     * Crée, sur clic explicite d'un administrateur, LA règle générique d'affectation par TAG, commune à tous les
     * clients : critère « Inventory tag » expression régulière /^(.*)$/, action « Entity from TAG » = #0 (structure
     * de RuleImportEntity, GLPI 11). Jamais une règle par client ; jamais recréée si une règle portant cette action
     * existe déjà, active ou non : l'état est alors rendu tel quel. Créée active, en dernière position : l'ordre des
     * règles existantes n'est jamais modifié (les règles jouées avant elle sont signalées).
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public static function createTagRule(): array {
        global $DB;

        if (!self::canCreateTagRule()) {
            return ['ok' => false, 'message' => __('Création de la règle réservée à l\'administrateur (droit de configuration du plugin et droit de créer des règles d\'import).', 'printgestion')];
        }
        $status = self::getTagRuleStatus();
        if (!empty($status['rules'])) {
            return ['ok' => false, 'message' => self::describeTagRule($status)];
        }

        $DB->beginTransaction();
        try {
            $rules_id = (int) (new RuleImportEntity())->add([
                'name'         => __('Affectation par TAG (Print Gestion)', 'printgestion'),
                'description'  => __('Entité dont le TAG est celui de l\'inventaire reçu. Règle unique pour tous les clients, créée depuis Print Gestion.', 'printgestion'),
                'sub_type'     => RuleImportEntity::class,
                'match'        => Rule::AND_MATCHING,
                'condition'    => 0,
                'is_active'    => 1,
                'entities_id'  => 0,
                'is_recursive' => 1,
            ]);
            if ($rules_id <= 0
                || (int) (new RuleCriteria())->add(['rules_id' => $rules_id, 'criteria' => 'tag', 'condition' => Rule::REGEX_MATCH, 'pattern' => '/^(.*)$/']) <= 0
                || (int) (new RuleAction())->add(['rules_id' => $rules_id, 'action_type' => 'regex_result', 'field' => '_affect_entity_by_tag', 'value' => '#0']) <= 0) {
                throw new RuntimeException('Règle, critère ou action refusé par GLPI.');
            }
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            PluginPrintgestionLogger::error('agentdeploy', 'Règle d\'affectation par TAG non créée : rien n\'a été enregistré.', $e);
            return ['ok' => false, 'message' => __('Règle non créée : refusée par GLPI, rien n\'a été enregistré (détail dans le journal du plugin).', 'printgestion')];
        }
        return ['ok' => true, 'message' => __('Règle créée. ', 'printgestion') . self::describeTagRule(self::getTagRuleStatus())];
    }

    /**
     * Bouton qui rend la règle d'affectation par TAG opérante, selon son état et les droits : « Créer la règle
     * d'affectation par TAG » si aucune n'existe, « Activer la règle » si elle est désactivée ; chaîne vide si une
     * règle est active ou sans le droit. Confirmation : la règle sert à tous les clients. Bouton seul, à placer dans
     * un formulaire (jeton CSRF) envoyé à un contrôleur qui traite create_tag_rule et activate_tag_rule.
     */
    public static function getTagRuleButton(array $status): string {
        if ($status['active'] !== null) {
            return '';
        }
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        if (empty($status['rules']) && self::canCreateTagRule()) {
            [$name, $label, $confirm] = ['create_tag_rule', __('Créer la règle d\'affectation par TAG', 'printgestion'),
                __('Créer la règle d\'affectation par TAG ? Elle servira à TOUS les clients : chaque équipement inventorié ira dans l\'entité dont le TAG est le sien.', 'printgestion')];
        } elseif (!empty($status['rules']) && self::canActivateTagRule()) {
            [$name, $label, $confirm] = ['activate_tag_rule', __('Activer la règle', 'printgestion'),
                __('Activer la règle d\'affectation par TAG ? Elle servira à TOUS les clients : chaque équipement inventorié ira dans l\'entité dont le TAG est le sien.', 'printgestion')];
        } else {
            return '';
        }
        return "<button type='submit' name='{$name}' value='1' data-pg-submit-once='1' class='btn btn-primary' formnovalidate onclick=\"return confirm(" . $esc(json_encode($confirm)) . ");\">"
            . "<i class='ti ti-list-check me-1'></i>" . $esc($label) . "</button>";
    }

    /** Même bouton dans son propre formulaire, envoyé à la page Installeur (onglet de l'entité, carte Prérequis). */
    public static function getTagRuleForm(int $entities_id, array $status): string {
        $button = self::getTagRuleButton($status);
        if ($button === '') {
            return '';
        }
        return "<form method='post' action='" . htmlspecialchars(self::getPageURL(), ENT_QUOTES, 'UTF-8') . "' class='mt-2'>"
            . Html::hidden('entities_id', ['value' => $entities_id]) . $button . Html::closeForm(false);
    }

    /** État de la règle en une phrase : absente, désactivée, active et sa position, règles jouées avant elle. */
    public static function describeTagRule(array $status): string {
        if ($status['active'] === null) {
            return empty($status['rules'])
                ? __('Aucune règle d\'affectation par TAG.', 'printgestion')
                : sprintf(__('Règle présente mais désactivée (%s) : elle n\'affecte rien, les équipements n\'iront pas dans la bonne entité. Rien n\'a été recréé.', 'printgestion'), implode(', ', array_column($status['rules'], 'name')));
        }
        $text = sprintf(__('Règle « %1$s » active, position %2$d.', 'printgestion'), $status['active']['name'], (int) $status['active']['ranking']);
        if (!$status['tag_criterion']) {
            $text .= ' ' . sprintf(__('Sans critère « %s » : à vérifier.', 'printgestion'), __('Inventory tag'));
        }
        if (!empty($status['earlier'])) {
            $text .= ' ' . sprintf(
                __('Règles actives jouées avant elle (la première qui correspond l\'emporte) : %s. À vérifier ou déplacer dans Administration → Règles.', 'printgestion'),
                implode(', ', array_map(static fn(array $r) => $r['name'] . ' (' . (int) $r['ranking'] . ')', $status['earlier']))
            );
        }
        return $text;
    }

    /**
     * Onglet « Déploiement Agent » de la fiche Entité, trois blocs. Technicien : l'état et l'action. Administrateur :
     * les mêmes, plus les détails techniques repliés (chevron fermé) ou en fenêtre (bouton « i »).
     */
    public static function showForEntity(Entity $entity): void {
        $esc   = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $id    = (int) $entity->getID();
        $tag   = trim((string) ($entity->fields['tag'] ?? ''));
        $admin = PluginPrintgestionUi::isAdmin();

        // ── 1. État du rattachement ──
        $rule       = self::getTagRuleStatus();
        $owners     = self::getTagOwners($tag, $id);
        $tag_ok     = $tag !== '' && self::isValidTag($tag) && empty($owners);
        $rule_ok    = $rule['active'] !== null && empty($rule['earlier']) && $rule['tag_criterion'];
        $can_tag    = $entity->can($id, UPDATE);
        echo "<div class='card mb-3 mt-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('1. Rattachement des équipements', 'printgestion')) . "</h3></div><div class='card-body'>";

        // Toujours visible, sans dépliage : erreur irréversible (les règles d'affectation ne jouent qu'au premier import).
        if (!empty($owners)) {
            echo "<div class='alert alert-danger mb-2'><i class='ti ti-alert-octagon me-1'></i>" . $esc(sprintf(
                __('TAG « %1$s » déjà utilisé par l\'entité « %2$s » : un équipement partirait chez ce client, sans retour possible. Ne rien installer avant correction.', 'printgestion'),
                $tag,
                implode(', ', $owners)
            )) . "</div>";
        }

        $details = '';
        if ($admin) {
            $tag_url  = Entity::getFormURLWithID($id) . '&forcetab=' . urlencode('Entity$3');
            $checks   = [];
            $checks[] = self::checkItem($tag_ok, __('TAG de l\'entité', 'printgestion'), $tag === ''
                ? $esc(__('Vide.', 'printgestion'))
                : "<code>" . $esc($tag) . "</code> " . (!self::isValidTag($tag) ? $esc(__('— caractères refusés dans la commande d\'installation.', 'printgestion')) : '')
                    . " <a href='" . $esc($tag_url) . "'>" . $esc(__('Modifier', 'printgestion')) . "</a>");
            $rule_detail = $esc(self::describeTagRule($rule));
            if ($rule['active'] !== null) {
                $rule_detail .= " <a href='" . $esc(RuleImportEntity::getFormURLWithID((int) $rule['active']['id'])) . "'>" . $esc(__('Voir', 'printgestion')) . "</a>";
            }
            $checks[] = self::checkItem($rule_ok, __('Règle d\'affectation par TAG', 'printgestion'), $rule_detail);
            $details  = "<div class='row g-3'>" . implode('', $checks) . "</div>";
        }
        $rule_disabled = $rule['active'] === null && !empty($rule['rules']);
        echo PluginPrintgestionUi::statusLine(
            $tag_ok && $rule_ok ? 'ok' : 'error',
            match (true) {
                $tag_ok && $rule_ok => __('Rattachement : correct', 'printgestion'),
                $rule_disabled      => __('Règle d\'affectation présente mais désactivée — les équipements n\'iront pas dans la bonne entité', 'printgestion'),
                $tag === ''         => __('TAG de l\'entité absent', 'printgestion'),
                default             => __('Rattachement incomplet', 'printgestion'),
            },
            $details
        );
        echo "</div></div>";

        // Actions qui lèvent le blocage du rattachement, affichées dans le bloc 2 à côté du blocage.
        ob_start();

        // Action : règle d'affectation par TAG absente ou désactivée, et droit de la créer ou de l'activer
        // (administrateur) — bouton visible, clic confirmé. Sans objet si le formulaire du TAG ci-dessous la traite.
        $rule_form = self::getTagRuleForm($id, $rule);
        if ($rule_form !== '' && !($tag === '' && $can_tag)) {
            echo $rule_form;
        }

        // Action : TAG absent et droit natif de modifier l'entité (la règle est créée ou activée du même clic si besoin).
        if ($tag === '' && $can_tag) {
            $proposed  = self::normalizeTag((string) $entity->fields['name']);
            $with_rule = $rule_form !== '';
            echo "<form method='post' action='" . $esc(self::getPageURL()) . "' class='row g-2 align-items-end mt-1'>"
                . Html::hidden('entities_id', ['value' => $id])
                . "<div class='col-sm-6 col-lg-4'><label class='form-label'>" . $esc(__('TAG proposé (nom de l\'entité)', 'printgestion')) . "</label>"
                . "<input type='text' class='form-control' name='tag' maxlength='100' pattern='[A-Za-z0-9][A-Za-z0-9._-]*' required value='" . $esc($proposed) . "'></div>"
                . "<div class='col-auto'><button type='submit' name='create_tag' value='1' data-pg-submit-once='1' class='btn btn-primary'"
                . ($with_rule ? " onclick=\"return confirm(" . $esc(json_encode($rule_disabled
                    ? __('Créer le TAG de cette entité ET activer la règle d\'affectation par TAG, qui servira ensuite à TOUS les clients ?', 'printgestion')
                    : __('Créer le TAG de cette entité ET la règle d\'affectation par TAG, qui servira ensuite à TOUS les clients ?', 'printgestion'))) . ");\"" : '')
                . "><i class='ti ti-tag me-1'></i>" . $esc(match (true) {
                    !$with_rule    => __('Créer le TAG', 'printgestion'),
                    $rule_disabled => __('Créer le TAG et activer la règle d\'affectation', 'printgestion'),
                    default        => __('Créer le TAG et la règle d\'affectation', 'printgestion'),
                }) . "</button></div>"
                . Html::closeForm(false);
        }
        $fix_html = (string) ob_get_clean();
        // L'utilisateur lève lui-même tout le blocage : TAG absent qu'il peut créer (ou TAG correct), règle active ou
        // qu'il peut créer ou activer. Sinon « Contactez l'administrateur ».
        $can_fix = ($tag_ok || ($tag === '' && $can_tag)) && ($rule['active'] !== null || $rule_form !== '') && self::getApplicationUrlIssue() === '';

        // ── 2. Télécharger l'installeur ──
        $version   = self::getServedVersion();
        $platforms = self::getPlatforms();
        $icons     = ['windows' => 'ti-brand-windows', 'linux' => 'ti-brand-ubuntu', 'macos' => 'ti-brand-apple'];
        $blockers  = [];
        foreach (array_keys($platforms) as $platform) {
            $blockers[$platform] = self::getPackageBlockers($entity, $platform);
        }
        echo "<div class='card mb-3'><div class='card-header d-flex align-items-center'><h3 class='card-title mb-0'>" . $esc(__('2. Télécharger l\'installeur', 'printgestion')) . "</h3>"
            // Panneau replié : « comment lancer » pour tout le monde (il n'y a plus de LISEZMOI dans une archive,
            // puisqu'il n'y a plus d'archive), le détail technique et les archives de recours pour l'administrateur.
            . "<div class='ms-auto'>" . PluginPrintgestionUi::infoButton(
                __('Comment lancer le fichier téléchargé', 'printgestion'),
                self::getLaunchHelpHtml() . ($admin ? self::getPackageDetailsHtml($tag, $version, $id) : '')
            ) . "</div></div><div class='card-body'>";
        $attachment   = self::getDeployBlockers($entity);
        $all_blockers = array_values(array_diff(array_unique(array_merge(...array_values($blockers))), $attachment));
        $list         = static fn(array $items) => "<ul class='mb-0'>" . implode('', array_map(static fn(string $b) => '<li>' . $esc($b) . '</li>', $items)) . "</ul>";
        if (!empty($attachment)) {
            // Seul blocage de l'écran : geste irréversible (règles d'affectation jouées au premier import seulement).
            echo PluginPrintgestionUi::statusLine('error', __('Configuration incomplète — le déploiement est bloqué', 'printgestion'), $list($attachment));
            echo "<p class='mb-1'>" . $esc(__('Les imprimantes seraient rattachées au mauvais client, sans correction possible ensuite.', 'printgestion'))
                . ($can_fix ? '' : ' ' . $esc(__('Contactez l\'administrateur.', 'printgestion'))) . "</p>";
            echo $fix_html;
        }
        if (!empty($all_blockers)) {
            echo PluginPrintgestionUi::statusLine('error', __('Installeur indisponible — contactez l\'administrateur', 'printgestion'), $list($all_blockers));
        }
        // Le bouton en avant est celui du système depuis lequel on regarde, et non Windows pour tout le monde :
        // sur un Mac, on cherchait le sien parmi les trois. Les deux autres restent à côté, également cliquables.
        $sien = self::getVisitorPlatform();
        echo "<div class='d-flex flex-wrap gap-2 my-2'>";
        foreach ($platforms as $platform => $label) {
            $class = $platform === $sien ? 'btn-primary' : 'btn-outline-primary';
            $titre = $platform === $sien ? " title='" . $esc(__('Le système depuis lequel vous consultez cet écran', 'printgestion')) . "'" : '';
            if (empty($blockers[$platform])) {
                echo "<a class='btn {$class}' href='" . $esc(self::getDownloadURL($id, $platform)) . "'{$titre}><i class='ti {$icons[$platform]} me-1'></i>" . $esc($label) . "</a>";
            } else {
                echo "<button type='button' class='btn {$class}' disabled{$titre}><i class='ti {$icons[$platform]} me-1'></i>" . $esc($label) . "</button>";
            }
        }
        echo "</div>";

        // Linux et macOS ne se lancent pas comme Windows : dit ici, sous les boutons, et pas seulement dans le
        // panneau replié — c'est au moment où l'on tient le fichier qu'on se le demande. Un double-clic sur un .sh
        // n'ouvre qu'un éditeur de texte : rien ne se passe, et rien ne l'explique.
        echo "<p class='text-muted small mb-2'><i class='ti ti-info-circle me-1'></i>"
            . $esc(__('Windows : clic droit sur le fichier, « Exécuter en tant qu\'administrateur ». Linux et macOS : un double-clic ne lance rien — ouvrir un terminal, taper « sudo sh » suivi d\'un espace SANS VALIDER, glisser le fichier dans la fenêtre du terminal, puis appuyer sur Entrée ; le mot de passe administrateur est demandé. Valider trop tôt ouvre un shell root, et le fichier glissé ensuite répond « permission denied ». Installation comme retrait.', 'printgestion'))
            . "</p>";

        // Retirer une sonde : au même endroit que ce qui l'installe, mais en retrait — c'est le geste rare, et un
        // droit à part (« Retirer une sonde »). Sans lui, pas de boutons — et l'URL est refusée de la même façon.
        if (Session::haveRight('plugin_printgestion_deploiement', PURGE)) {
            echo "<div class='d-flex flex-wrap align-items-center gap-2 mb-2'><span class='text-muted small'>"
                . $esc(__('Retirer l\'agent d\'un PC :', 'printgestion')) . "</span>";
            foreach ($platforms as $platform => $label) {
                echo "<a class='btn btn-sm btn-outline-danger' href='" . $esc(self::getDownloadURL($id, self::REMOVE_OS[$platform])) . "'>"
                    . "<i class='ti {$icons[$platform]} me-1'></i>" . $esc($label) . "</a>";
            }
            echo "</div>";
        }

        // Ce qui empêche toute remontée sans bloquer le déploiement (réparable après coup, à distance) : le
        // technicien voit l'état, l'administrateur ce qui manque et où le corriger. Jamais vert quand rien ne remontera.
        // La préparation des inventaires réseau se juge sur les tâches de CETTE entité : une tâche en retard ailleurs
        // ne dit pas que rien ne remontera ici. Le reste est global (cron, inventaire, GLPI Inventory…).
        $checks      = PluginPrintgestionConfighealth::getChecks();
        $environment = array_filter($checks, static fn(array $c) => $c['group'] === 'required'
            && $c['state'] === PluginPrintgestionConfighealth::STATE_ERROR && !in_array($c['key'], ['tag_rule', 'app_url', 'collect_prep'], true));
        foreach ($checks as $check) {
            if ($check['key'] !== 'collect_prep' || $check['state'] !== PluginPrintgestionConfighealth::STATE_ERROR) {
                continue;
            }
            try {
                $prep = PluginPrintgestionCollectfrequency::getPreparationStatus();
            } catch (RuntimeException $e) {
                // Colonne de GLPI Inventory absente : la ligne globale dit déjà pourquoi, elle vaut pour toutes les entités.
                $environment[] = $check;
                break;
            }
            $late = array_values(array_filter($prep['tasks'], static fn(array $t) => $t['late'] && (int) $t['entities_id'] === (int) $id));
            if (!empty($late)) {
                $last           = array_filter(array_column($late, 'last_prepared'));
                $check['status'] = sprintf(
                    _n('%1$d tâche de collecte de cette entité sans travail préparé à temps (dernier : %2$s).', '%1$d tâches de collecte de cette entité sans travail préparé à temps (dernier : %2$s).', count($late), 'printgestion'),
                    count($late),
                    empty($last) ? __('jamais', 'printgestion') : Html::convDateTime((string) max($last))
                );
                $environment[] = $check;
            }
        }
        if (!empty($environment)) {
            $items = '';
            foreach ($environment as $check) {
                $fix    = $check['url'] !== '' ? "<a href='" . $esc($check['url']) . "'>" . $esc($check['fix']) . "</a>" : $esc($check['fix']);
                $items .= "<li><span class='fw-bold'>" . $esc($check['label']) . "</span> — " . $esc($check['status']) . ' ' . $esc($check['breaks'])
                    . " <i class='ti ti-tool mx-1'></i>" . $fix . "</li>";
            }
            echo PluginPrintgestionUi::statusLine('error', __('Rien ne remontera pour l\'instant — contactez l\'administrateur', 'printgestion'), "<ul class='mb-0'>" . $items . "</ul>");
        }

        // Sans GLPI Inventory, ce n'est pas une panne : le fichier bascule sur le scan local, et les imprimantes
        // remontent. Le technicien doit seulement savoir ce qui change pour lui — et ne pas chercher un
        // raccordement qui n'existera pas.
        if (!PluginPrintgestionCollectsetup::isAvailable()) {
            echo PluginPrintgestionUi::statusLine(
                'info',
                __('Mode local : chaque PC sonde scannera lui-même', 'printgestion'),
                "<p class='mb-0'>" . $esc(__('Le fichier d\'installation écrit la plage IP, la communauté SNMP et la cadence dans la ToolBox de l\'agent, sur le PC : les imprimantes remontent dans GLPI, avec leurs cartouches et leurs compteurs. En revanche, rien ne se pilote depuis GLPI — ni plage, ni tâche, ni raccordement — et la cadence se change sur le PC, ou en réinstallant la sonde.', 'printgestion')) . "</p>"
            );
        }
        echo "</div></div>";

        // ── 3. Raccordement ──
        PluginPrintgestionRaccordement::showForEntity($entity);

        // ── 4. Fréquence des relevés (administrateur) ──
        PluginPrintgestionCollectfrequency::showForEntity($entity);
    }

    /** Contenu technique des paquets (fenêtre « i » de l'administrateur) : commandes, propriétés, procédures. */
    /**
     * Comment lancer le fichier téléchargé — visible par le technicien, donc sans numéro de version, sans nom de
     * fichier (il porte la version) et sans détail technique. « Glisser le fichier dans le terminal » évite d'avoir
     * à taper un chemin, et reste vrai quel que soit le dossier de téléchargement.
     */
    private static function getLaunchHelpHtml(): string {
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        return "<p class='mb-1'>" . $esc(__('Un seul fichier à lancer par système, sur le PC qui servira de sonde (allumé en permanence, sur le réseau des imprimantes). Une fenêtre s\'ouvre et rappelle le client : rien à saisir.', 'printgestion')) . "</p>"
            . "<ul class='mb-2'>"
            . "<li>" . $esc(__('Windows : clic droit sur le fichier téléchargé > Exécuter en tant qu\'administrateur.', 'printgestion')) . "</li>"
            . "<li>" . $esc(__('Linux : dans un terminal, taper « sudo sh » suivi d\'un espace — sans valider —, glisser le fichier téléchargé dans la fenêtre du terminal, puis appuyer sur Entrée.', 'printgestion')) . "</li>"
            . "<li>" . $esc(__('macOS : ouvrir Terminal (Applications > Utilitaires), taper « sudo sh » suivi d\'un espace — sans valider —, glisser le fichier téléchargé dans la fenêtre, puis appuyer sur Entrée. Le mot de passe administrateur du Mac est demandé.', 'printgestion')) . "</li>"
            // Le faux pas le plus courant, nommé par le mot qui s'affiche à l'écran : sans cette ligne, on
            // soupçonne le fichier, et l'on cherche du côté des droits ou de l'antivirus.
            . "<li>" . $esc(__('« permission denied » sous Linux ou macOS : c\'est qu\'on a validé après « sudo sh », ce qui ouvre un shell root ; le fichier glissé ensuite est lancé au lieu d\'être lu, et un fichier téléchargé n\'a pas le droit d\'exécution. Taper « exit », puis recommencer en une seule ligne.', 'printgestion')) . "</li>"
            . "</ul>"
            . "<p class='mb-3'>" . $esc(__('Le fichier va chercher l\'agent officiel sur ce serveur GLPI avec une clé qui ne vaut qu\'une fois et qu\'un jour : le télécharger au moment de partir, et le régénérer ici s\'il a déjà servi ou si le téléchargement a été interrompu. Ensuite, dans GLPI : vérifier que la sonde apparaît, puis raccorder les imprimantes (bloc 3) avant de partir.', 'printgestion')) . "</p>";
    }

    private static function getPackageDetailsHtml(string $tag, string $version, int $entities_id = 0): string {
        $esc  = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $html = "<div class='fw-bold mb-1'>" . $esc(__('Ce que contient le fichier unique', 'printgestion')) . "</div>"
            . "<p class='small'>" . $esc(sprintf(__('GLPI Agent %s. Le fichier porte l\'adresse du serveur GLPI, le TAG, l\'empreinte SHA-256 attendue et une clé de récupération à usage unique valable 24 h : aucun identifiant, aucun mot de passe. Il télécharge l\'installeur officiel de Teclib\' depuis ce serveur, refuse d\'installer quoi que ce soit si l\'empreinte diffère, puis l\'installe avec les propriétés ci-dessous. C\'est le seul livrable du plugin qui porte un secret : il se donne au technicien pour l\'intervention, il ne s\'archive pas.', 'printgestion'), $version)) . "</p>"
            . "<p class='small'>" . $esc(__('Windows : un .bat dont la seconde moitié est du PowerShell (fenêtre WinForms) ; la mise à jour automatique est une case à cocher, décochée. Linux : un .sh (fenêtre zenity si le poste en a une, question en console sinon) qui pose aussi les réessais SNMP en conf.d et la tâche cron mensuelle si on l\'a voulu. macOS : un .sh qui lit la puce du Mac, ne télécharge que le paquet correspondant, l\'installe, dépose local.cfg, relance le service et pose la mise à jour automatique (service launchd mensuel) si on l\'a voulu.', 'printgestion')) . "</p>";
        if ($entities_id > 0) {
            // Recours quand l'antivirus d'un client refuse les scripts : les archives complètes, qui ne portent aucun
            // secret mais demandent d'extraire un dossier. Rangées ici, pas dans l'écran : ce n'est plus le chemin normal.
            $cles  = PluginPrintgestionAgenttoken::countActive($entities_id);
            $html .= "<div class='fw-bold mt-3 mb-1'>" . $esc(__('Recours : archives complètes, sans clé', 'printgestion')) . "</div>"
                . "<p class='small mb-1'>" . $esc(__('L\'installeur officiel est dans l\'archive : rien à télécharger depuis le PC, aucune clé, mais un dossier à extraire et un fichier à lancer dedans. À prendre si l\'antivirus du client refuse les scripts.', 'printgestion')) . "</p>"
                . "<div class='d-flex flex-wrap gap-2 mb-2'>";
            foreach (self::ARCHIVE_OS as $platform => $os) {
                $html .= "<a class='btn btn-sm btn-outline-secondary' href='" . $esc(self::getDownloadURL($entities_id, $os)) . "'>"
                    . $esc(sprintf(__('%s (archive)', 'printgestion'), self::getPlatforms()[$platform])) . "</a>";
            }
            $html .= "</div>";
            if ($cles > 0) {
                // Une clé déjà émise continue d'ouvrir jusqu'à son heure : le dire évite d'en semer sans le savoir.
                $html .= "<p class='small mb-0'>" . $esc(sprintf(
                    _n('%d clé déjà émise pour ce client est encore valable.', '%d clés déjà émises pour ce client sont encore valables.', $cles, 'printgestion'),
                    $cles
                )) . "</p>";
            }
        }
        if ($tag === '' || !self::isValidTag($tag)) {
            return $html . "<p class='text-danger'>" . $esc(__('Commandes non affichées : TAG de l\'entité absent ou invalide.', 'printgestion')) . "</p>";
        }
        $reasons = [
            'SERVER'       => __('Serveur GLPI qui reçoit les inventaires et distribue les tâches.', 'printgestion'),
            'TAG'          => __('Rattachement des équipements à cette entité (règle « Entity from TAG »).', 'printgestion'),
            'ADDLOCAL'     => __('Inventaire du poste plus découverte et inventaire réseau : seul l\'inventaire du poste est installé par défaut depuis l\'agent 1.8.', 'printgestion'),
            'HTTPD_TRUST'  => __('Interface locale de l\'agent ouverte au poste lui-même (forcer une exécution sur place).', 'printgestion'),
            'SNMP_RETRIES' => __('Un paquet SNMP perdu ne fait plus disparaître les consommables d\'un relevé (0 par défaut).', 'printgestion'),
            'RUNNOW'       => __('Premier inventaire aussitôt l\'installation terminée.', 'printgestion'),
            'EXECMODE'     => __('Agent installé comme service Windows.', 'printgestion'),
            'QUICKINSTALL' => __('Installation directe, sans les écrans de configuration détaillée (l\'installation est muette de toute façon : /qn).', 'printgestion'),
        ];
        $html .= "<div class='fw-bold mt-3 mb-1'>" . $esc(sprintf(__('Windows : commande lancée par %s', 'printgestion'), self::WINDOWS_INSTALL_BAT)) . "</div>"
            . "<pre class='mb-2' style='white-space:pre-wrap'>" . $esc(self::buildWindowsCommand(self::getMsiName($version), $tag)) . "</pre>";
        // Propriétés du MSI : toujours les mêmes clés, jamais vide ; aucun lien, donc aucun clic de ligne. La petite
        // taille de l'ancienne cellule « Pourquoi » passe dans un bloc. Pas de marge basse, comme avant (table-responsive :
        // Tabler la ramène à zéro) : l'écart avec le titre Linux reste son mt-3.
        $entries = [];
        foreach (self::getWindowsProperties($tag) as $name => $value) {
            $entries[] = [
                'name'  => "<code>" . $esc($name) . "</code>",
                'value' => "<code>" . $esc($value !== '' ? $value : '—') . "</code>",
                'why'   => "<span class='d-block small'>" . $esc($reasons[$name] ?? '') . "</span>",
            ];
        }
        $html .= PluginPrintgestionUi::datatable([
            'name'  => __('Propriété', 'printgestion'),
            'value' => __('Valeur', 'printgestion'),
            'why'   => __('Pourquoi', 'printgestion'),
        ], $entries, ['name' => 'raw_html', 'value' => 'raw_html', 'why' => 'raw_html'])
            . "<div class='fw-bold mt-3 mb-1'>" . $esc(__('Linux : commande lancée par installer-glpi-agent.sh, en root', 'printgestion')) . "</div>"
            . "<pre class='mb-1' style='white-space:pre-wrap'>" . $esc(self::buildLinuxCommand(self::getAssets($version)['linux']['file'], $tag)) . "</pre>"
            . "<p class='small'>" . $esc(__('Avant la commande, le script pose /etc/glpi-agent/conf.d/90-printgestion.cfg (snmp-retries = 2) ; après, la tâche cron mensuelle de mise à jour si elle est activée.', 'printgestion')) . "</p>"
            . "<div class='fw-bold mt-3 mb-1'>" . $esc(__('macOS : procédure sur le Mac sonde', 'printgestion')) . "</div><ol class='small ps-3'>";
        foreach (self::getMacosSteps($version, $tag) as $step) {
            $html .= "<li>" . $esc((string) preg_replace('/^\d+\.\s*/', '', $step)) . "</li>";
        }
        return $html . "</ol><pre class='mb-2' style='white-space:pre-wrap'>" . $esc(implode("\n", self::getMacosCommands($version, $tag))) . "</pre>"
            . "<div class='small fw-bold'>local.cfg</div><pre class='mb-0' style='white-space:pre-wrap'>" . $esc(self::buildAgentConfig($tag, true)) . "</pre>";
    }

    /** Page « Installeur GLPI Agent » : installeur servi, adresses, prérequis ; actions avec le droit de configuration. */
    public static function showPage(): void {
        global $CFG_GLPI;

        $esc       = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $can_edit  = Session::haveRight('plugin_printgestion_config', UPDATE);
        $page      = self::getPageURL();
        $version   = self::getServedVersion();
        $config    = PluginPrintgestionConfig::getInstance();

        $intro_html = "<p>" . $esc(__('Fichiers officiels de GLPI Agent servis aux techniciens depuis l\'onglet « Déploiement Agent » des entités, récupérés par ce serveur et vérifiés : l\'installation ne télécharge rien depuis GitHub sur les postes des clients. Seule la mise à jour automatique, si elle est posée, passe par winget (Windows) ou GitHub (Linux).', 'printgestion')) . "</p>";

        // Installeurs servis : détail de l'administrateur.
        ob_start();
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(sprintf(__('Installeurs servis : GLPI Agent %s', 'printgestion'), $version)) . "</h3></div><div class='card-body'>";
        // Colonne d'action seulement avec le droit de configuration : sans lui, elle n'existe pas (comme avant).
        $columns = [
            'label' => __('Fichier officiel', 'printgestion'),
            'file'  => __('Nom', 'printgestion'),
            'state' => __('État', 'printgestion'),
        ];
        if ($can_edit) {
            $columns['action'] = '';
        }
        // Liste fixe des fichiers officiels : jamais vide.
        $entries = [];
        foreach (self::getAssets($version) as $asset => $spec) {
            $installer = self::getCachedInstaller(false, $asset);
            $entry     = [
                'label' => $spec['label'],
                'file'  => "<code>" . $esc($spec['file']) . "</code>",
                'state' => $installer !== null
                    ? "<span class='badge bg-green text-green-fg'>" . $esc(__('Vérifié', 'printgestion')) . "</span> <span class='small text-muted'>" . $esc(sprintf(
                        __('%1$s octets, SHA-256 %2$s, %3$s le %4$s', 'printgestion'),
                        number_format((int) $installer['size'], 0, ',', ' '),
                        $installer['sha256'],
                        $installer['source'] === 'github' ? __('récupéré sur GitHub', 'printgestion') : __('déposé à la main', 'printgestion'),
                        Html::convDateTime((string) $installer['date'])
                    )) . "</span>"
                    : "<span class='badge bg-orange text-orange-fg'>" . $esc(__('Pas encore sur ce serveur', 'printgestion')) . "</span>",
            ];
            if ($can_edit) {
                // Un formulaire autonome par ligne (jeton CSRF de Html::closeForm), tel qu'avant ; l'alignement à
                // droite de l'ancienne cellule passe dans un bloc.
                $entry['action'] = "<div class='text-end'><form method='post' action='" . $esc($page) . "' class='d-inline'>"
                    . "<button type='submit' data-pg-submit-once='1' name='fetch_github' value='" . $esc($asset) . "' class='btn btn-sm btn-outline-primary'><i class='ti ti-cloud-download me-1'></i>" . $esc(__('Récupérer depuis GitHub', 'printgestion')) . "</button>"
                    . Html::closeForm(false) . "</div>";
            }
            $entries[] = $entry;
        }
        // data-pg-noclick : la ligne porte un formulaire, elle ne réagit pas au clic (comme avant). Pas de marge basse
        // avant le paragraphe qui suit, comme avant : l'ancien tableau était dans un table-responsive, où Tabler la
        // ramène à zéro.
        echo "<div data-pg-noclick='1'>" . PluginPrintgestionUi::datatable($columns, $entries, [
            'file'   => 'raw_html',
            'state'  => 'raw_html',
            'action' => 'raw_html',
        ]) . "</div>";
        echo "<p class='text-muted small'>" . $esc(__('Paquet de l\'entité disponible dès que ses fichiers sont vérifiés : le MSI pour Windows, l\'installeur Perl pour Linux, les deux paquets (Apple Silicon et Intel) pour macOS. Empreinte comparée à celle que GitHub publie pour chaque fichier.', 'printgestion')) . "</p>";
        if ($can_edit) {
            echo "<p class='small mb-1'>" . $esc(sprintf(
                __('Serveur sans accès à GitHub : déposer le fichier dans le dossier %1$s, puis coller son empreinte SHA-256 publiée avec la release (fichier glpi-agent-%2$s.sha256).', 'printgestion'),
                self::getCacheDir(),
                $version
            )) . "</p>";
            echo "<form method='post' action='" . $esc($page) . "' class='row g-2 align-items-end'>";
            echo "<div class='col-md-3'><select class='form-select' name='asset'>";
            foreach (self::getAssets($version) as $asset => $spec) {
                echo "<option value='" . $esc($asset) . "'>" . $esc($spec['label']) . "</option>";
            }
            echo "</select></div>";
            echo "<div class='col-md-6'><input type='text' class='form-control' name='sha256' maxlength='64' pattern='[0-9a-fA-F]{64}' placeholder='" . $esc(__('Empreinte SHA-256 (64 caractères)', 'printgestion')) . "' required></div>";
            echo "<div class='col-md-3'><button type='submit' data-pg-submit-once='1' name='verify_deposit' value='1' class='btn btn-outline-primary'><i class='ti ti-file-check me-1'></i>" . $esc(__('Vérifier le fichier déposé', 'printgestion')) . "</button></div>";
            Html::closeForm();
        } else {
            echo "<p class='text-muted small mb-0'>" . $esc(__('Récupération et vérification de l\'installeur : droit de configuration du plugin.', 'printgestion')) . "</p>";
        }
        echo "</div></div>";
        $installers_html = (string) ob_get_clean();

        // Adresses et version.
        $server   = self::getServerUrl();
        $sources  = [
            'glpi' => __('URL de l\'application GLPI (Configuration → Générale), jamais réglée ici', 'printgestion'),
        ];
        $host     = (string) parse_url((string) ($CFG_GLPI['url_base'] ?? ''), PHP_URL_HOST);
        $resolved = $host !== '' ? gethostbyname($host) : '';
        ob_start();
        echo "<ul class='mb-3'>";
        echo "<li>" . $esc(__('Adresse du serveur (SERVER) :', 'printgestion')) . " <code>" . $esc($server['url'] !== '' ? $server['url'] : '—') . "</code> <span class='text-muted small'>(" . $esc($sources[$server['source']]) . ")</span>"
            . ($server['error'] !== '' ? " <span class='text-danger'>" . $esc($server['error']) . "</span>" : '') . "</li>";
        echo "<li>" . $esc(__('Adresses autorisées sur l\'interface de l\'agent (HTTPD_TRUST) :', 'printgestion')) . " <code>" . $esc(self::getHttpdTrust()) . "</code></li>";
        echo "<li>" . $esc(__('Fonctions (ADDLOCAL), réessais SNMP, mode :', 'printgestion')) . " <code>" . $esc(self::ADDLOCAL) . "</code>, <code>SNMP_RETRIES=" . (int) self::SNMP_RETRIES . "</code>, <code>RUNNOW=1 EXECMODE=1 QUICKINSTALL=1</code></li>";
        echo "<li>" . $esc(__('Linux et macOS : mêmes réglages, en options de l\'installeur Linux (--type=network) et dans conf.d, ou dans local.cfg sur macOS (tasks = inventory,netdiscovery,netinventory ; snmp-retries = 2).', 'printgestion')) . "</li>";
        echo "</ul>";
        echo "<p class='text-muted small'>" . $esc(__('La sonde appelle GLPI d\'elle-même, et GLPI lui rend à ce moment-là les tâches à exécuter : une découverte préparée part donc seule, derrière la box d\'un client, sans VPN. Seul le sens inverse est fermé — réveiller une sonde (statut, inventaire à la demande) suppose de la joindre sur son port 62354. Ce qu\'on y perd n\'est pas la commande, c\'est l\'attente : au plus tard un intervalle d\'inventaire. L\'accès depuis le poste lui-même (127.0.0.1) reste toujours ouvert.', 'printgestion'))
            . ($resolved !== '' && $resolved !== $host ? ' ' . $esc(sprintf(__('Adresse du serveur GLPI selon le DNS : %s.', 'printgestion'), $resolved)) : '') . "</p>";
        // Avertissement hors du formulaire : pleine largeur, il couperait la grille en deux s'il était dedans.
        echo PluginPrintgestionAgentsetting::getServedVersionWarning();
        if ($can_edit) {
            // Alignement par le haut : ce sont les étiquettes qui doivent se répondre d'une colonne à l'autre, pas
            // les champs — une aide plus longue que les autres décalait tout le reste vers le bas.
            echo "<form method='post' action='" . $esc($page) . "' class='row g-3 align-items-start'>";
            // Ce champ décide du fichier que le serveur distribue. Le choix vide n'épingle rien : le plugin
            // prend alors la version qu'il connaît, et le libellé le dit en ces termes — « référence du plugin »
            // ne voulait rien dire pour qui lit l'écran, et laissait croire à une recommandation.
            echo "<div class='col-md-4'><label class='form-label'>" . $esc(__('Version des agents', 'printgestion')) . "</label>"
                . PluginPrintgestionAgentsetting::versionField('agent_version', trim((string) ($config->fields['agent_version'] ?? '')), sprintf(__('Ne rien épingler — version connue du plugin : %s', 'printgestion'), self::DEFAULT_VERSION))
                . "<div class='form-hint'>" . $esc(__('Distribuée aux nouvelles sondes, et visée par les mises à jour des autres. À récupérer ci-dessous après changement.', 'printgestion')) . "</div></div>";
            PluginPrintgestionAgentsetting::showLatestVersionColumn(true, 'col-md-4');
            PluginPrintgestionAgentsetting::showUpdateRuleColumn(true, 'col-md-4');
            // Adresse du serveur : déduite de l'URL de l'application, affichée, jamais saisie ici.
            echo "<div class='col-md-6'><label class='form-label'>" . $esc(__('Adresse du serveur donnée aux agents (déduite)', 'printgestion')) . "</label>"
                . "<div><code>" . $esc($server['url'] !== '' ? $server['url'] : '—') . "</code> <a href='" . $esc(Config::getFormURL()) . "' class='small'>" . $esc(__('Configuration → Générale, « URL de l\'application »', 'printgestion')) . "</a></div></div>";
            echo "<div class='col-md-6'><label class='form-label'>" . $esc(__('Adresses autorisées en plus du poste (IPv4, CIDR)', 'printgestion')) . "</label>"
                . "<input type='text' class='form-control' name='agent_httpd_trust' value='" . $esc($config->fields['agent_httpd_trust'] ?? '') . "' placeholder='203.0.113.10'></div>";
            PluginPrintgestionAgentsetting::showManualVersionColumn('col-md-6');
            echo "<div class='col-12'><button type='submit' data-pg-submit-once='1' name='save_settings' value='1' class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>" . $esc(__('Enregistrer', 'printgestion')) . "</button></div>";
            Html::closeForm();
        } else {
            echo "<div class='row g-3 align-items-start'>";
            PluginPrintgestionAgentsetting::showLatestVersionColumn(false, 'col-md-4');
            PluginPrintgestionAgentsetting::showUpdateRuleColumn(false, 'col-md-8');
            echo "</div>";
        }
        echo "<p class='text-muted small mt-3 mb-0'>" . $esc(PluginPrintgestionAgentsetting::getUpdateNotice()) . "</p>";
        // Statut GLPI des PC sondes : un réglage, donc ici avec les autres, et non au milieu de l'écran de
        // consultation des sondes. Hors du formulaire ci-dessus : un <form> dans un <form> n'existe pas.
        if ($can_edit) {
            echo "<hr class='my-3'>";
            PluginPrintgestionAgentsetting::showProbeStateForm($page);
        }
        $params_html = (string) ob_get_clean();

        // Prérequis communs à tous les clients.
        $rule          = self::getTagRuleStatus();
        $inventory_on  = (int) Config::getConfigurationValue('inventory', 'enabled_inventory') === 1;
        $with_tag      = countElementsInTable(Entity::getTable(), ['NOT' => ['tag' => null], ['tag' => ['<>', '']]]);
        $inventory     = PluginPrintgestionCollectsetup::getPrerequisites();
        $checks        = [
            self::checkItem($inventory_on, __('Inventaire GLPI', 'printgestion'), $esc($inventory_on
                ? __('Activé.', 'printgestion')
                : __('Désactivé (Administration → Inventaire) : aucun inventaire ne peut être reçu.', 'printgestion'))),
            // Un seul texte sur GLPI Inventory, celui des prérequis (Collectsetup::getPrerequisites) : la carte Santé le reprend tel quel.
            self::checkItem(empty($inventory['blocking']), __('Plugin GLPI Inventory', 'printgestion'), $esc(implode(' ', array_merge(
                empty($inventory['blocking']) ? [sprintf(__('Actif, version %s.', 'printgestion'), $inventory['version'])] : [],
                $inventory['blocking'],
                $inventory['warnings']
            )))),
            self::checkItem($rule['active'] !== null, __('Règle d\'affectation par TAG', 'printgestion'), $esc(self::describeTagRule($rule))),
            self::checkItem($with_tag > 0, __('Entités avec un TAG', 'printgestion'), $esc(sprintf(__('%d entité(s) ont un TAG renseigné.', 'printgestion'), $with_tag))),
        ];

        // Technicien : l'état seul (ni version, ni fichier, ni menu) ; administrateur : les mêmes lignes et le détail replié.
        $admin  = PluginPrintgestionUi::isAdmin();
        $ready  = true;
        foreach (array_keys(self::getAssets($version)) as $asset) {
            $ready = $ready && self::getCachedInstaller(false, $asset) !== null;
        }
        $latest = PluginPrintgestionAgentsetting::getLatestVersion();
        echo "<div class='card mb-3'><div class='card-header d-flex align-items-center'><h3 class='card-title mb-0'>" . $esc(__('Installeur GLPI Agent', 'printgestion')) . "</h3>"
            . "<div class='ms-auto'>" . PluginPrintgestionUi::infoButton(__('Installeur GLPI Agent', 'printgestion'), $admin ? $intro_html : '') . "</div></div><div class='card-body'>";
        echo PluginPrintgestionUi::statusLine(
            $ready ? 'ok' : 'error',
            $ready
                ? ($admin ? sprintf(__('Installeur prêt : GLPI Agent %s', 'printgestion'), $version) : __('Installeur prêt', 'printgestion'))
                : __('Installeur incomplet — contactez l\'administrateur', 'printgestion'),
            $installers_html
        );
        if ($admin && version_compare($version, $latest['version'], '<')) {
            echo "<div data-pg-admin='1'>" . PluginPrintgestionUi::statusLine('warning', sprintf(__('Nouvelle version de GLPI Agent disponible : %1$s (servie : %2$s)', 'printgestion'), $latest['version'], $version)) . "</div>";
        }
        echo "</div></div>";
        // Toujours dépliée : ce sont des réglages qu'on vient changer, pas un détail qu'on consulte.
        echo PluginPrintgestionUi::adminCard(__('Réglages des sondes — transmis par le fichier d\'installation', 'printgestion'), $params_html, false);

        $prerequisites_ok = $inventory_on && empty($inventory['blocking']) && $rule['active'] !== null && $with_tag > 0;
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('Prérequis', 'printgestion')) . "</h3></div><div class='card-body'>";
        echo PluginPrintgestionUi::statusLine(
            $prerequisites_ok ? 'ok' : 'error',
            $prerequisites_ok ? __('Prérequis : corrects', 'printgestion') : __('Configuration incomplète — contactez l\'administrateur', 'printgestion'),
            "<div class='row g-3'>" . implode('', $checks) . "</div>"
        );
        echo self::getTagRuleForm(-1, $rule);
        echo "</div></div>";
    }

    static function install(Migration $migration) {
        return true;
    }

    /** Désinstallation : l'installeur en cache se récupère à nouveau, il est retiré. */
    static function uninstall(Migration $migration) {
        $dir = self::getCacheDir();
        if (!is_dir($dir)) {
            return true;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && !unlink($file)) {
                PluginPrintgestionLogger::warning('agentdeploy', sprintf('Fichier %s non supprimé à la désinstallation.', $file));
            }
        }
        if (!rmdir($dir)) {
            PluginPrintgestionLogger::warning('agentdeploy', sprintf('Dossier %s non supprimé à la désinstallation.', $dir));
        }
        return true;
    }
}
