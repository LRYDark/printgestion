<?php
/**
 * PluginPrintgestionAgentdeploy — module « Déploiement Agent », phase 1 : onglet « Déploiement Agent »
 * de la fiche Entité et installeur GLPI Agent pré-paramétré pour Windows.
 *
 * Le paquet téléchargé par le technicien ne contient que l'URL du serveur GLPI et le TAG de
 * l'entité : aucun identifiant, aucun jeton, aucun secret. Tout le reste se configure dans GLPI.
 *
 * Réutilise le natif sans le réafficher : champ TAG de l'entité, règle d'affectation d'entité
 * « Entity from TAG » (vérifiée, jamais créée par le plugin), fiche Agent (lien seulement).
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
    /** Dernière version publiée vérifiée (release GitHub du 4 août 2026) ; épinglable dans les réglages. */
    const DEFAULT_VERSION = '1.19';
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

    static function getTypeName($nb = 0) {
        return __('Déploiement Agent', 'printgestion');
    }

    // ── Onglet de la fiche Entité ─────────────────────────────────────────────

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

    public static function getCacheDir(): string {
        return GLPI_PLUGIN_DOC_DIR . '/printgestion/agent';
    }

    /**
     * URL du serveur donnée à l'agent : réglage, sinon point d'entrée du plugin GLPI Inventory
     * s'il est actif (tâches de découverte et d'inventaire réseau), sinon l'URL de GLPI
     * (inventaire du poste seulement).
     *
     * @return array ['url' => string, 'source' => 'config'|'glpiinventory'|'glpi', 'error' => string]
     */
    public static function getServerUrl(): array {
        global $CFG_GLPI;

        $override = trim((string) (PluginPrintgestionConfig::getInstance()->fields['agent_server_url'] ?? ''));
        if ($override !== '') {
            return ['url' => $override, 'source' => 'config', 'error' => ''];
        }
        $base = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/');
        if ($base === '') {
            return [
                'url'    => '',
                'source' => 'glpi',
                'error'  => __('URL de GLPI non renseignée (Configuration → Générale → URL de l\'application) : l\'agent ne saurait pas où envoyer ses inventaires.', 'printgestion'),
            ];
        }
        if (Plugin::isPluginActive('glpiinventory')) {
            return ['url' => $base . '/plugins/glpiinventory/', 'source' => 'glpiinventory', 'error' => ''];
        }
        return ['url' => $base . '/', 'source' => 'glpi', 'error' => ''];
    }

    /** Adresses autorisées sur l'interface de l'agent : le poste lui-même, plus les adresses réglées. */
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
        $url     = trim((string) ($input['agent_server_url'] ?? ''));
        $trust   = (string) preg_replace('/\s+/', '', (string) ($input['agent_httpd_trust'] ?? ''));

        $errors = [];
        if ($version !== '' && !preg_match('/^\d+\.\d+(\.\d+)?$/', $version)) {
            $errors[] = __('Version invalide (exemple : 1.19).', 'printgestion');
        }
        if ($url !== '' && !self::isValidServerUrl($url)) {
            $errors[] = __('URL du serveur invalide : https://…, sans espace ni guillemet.', 'printgestion');
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
            'agent_server_url'  => $url,
            'agent_httpd_trust' => $trust,
        ])) {
            return [__('Paramètres de l\'installeur non enregistrés.', 'printgestion')];
        }
        return [];
    }

    // ── Installeur en cache ───────────────────────────────────────────────────

    /**
     * Installeur vérifié de la version servie, ou null. $verify : empreinte recalculée (avant de
     * servir un paquet) ; sinon contrôle de la taille seulement (affichage).
     *
     * @return ?array ['path', 'file', 'version', 'size', 'sha256', 'source', 'date', 'users_id']
     */
    public static function getCachedInstaller(bool $verify = false): ?array {
        $version = self::getServedVersion();
        $meta    = self::readMetadata();
        $path    = self::getCacheDir() . '/' . self::getMsiName($version);
        if ($meta === null || ($meta['version'] ?? '') !== $version || !is_file($path)
            || (int) ($meta['size'] ?? -1) !== (int) filesize($path)) {
            return null;
        }
        if ($verify && !hash_equals((string) ($meta['sha256'] ?? ''), (string) hash_file('sha256', $path))) {
            PluginPrintgestionLogger::error('agentdeploy', sprintf('Installeur %s modifié depuis sa vérification : refusé.', $path));
            return null;
        }
        return $meta + ['path' => $path];
    }

    private static function readMetadata(): ?array {
        $file = self::getCacheDir() . '/' . self::METADATA_FILE;
        if (!is_file($file)) {
            return null;
        }
        $meta = json_decode((string) file_get_contents($file), true);
        return is_array($meta) ? $meta : null;
    }

    /**
     * Récupère le MSI de la version servie sur GitHub, par le serveur GLPI (proxy GLPI respecté),
     * et ne le garde que si son empreinte est celle publiée par GitHub pour ce fichier.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public static function fetchFromGitHub(): array {
        $version = self::getServedVersion();
        $name    = self::getMsiName($version);
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

        return self::installCandidate($part, $version, $digest[1], 'github', true);
    }

    /**
     * Vérifie un MSI déposé à la main dans le dossier de l'installeur (serveur sans accès Internet),
     * contre l'empreinte SHA-256 publiée avec la release. Un fichier refusé n'est pas supprimé.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public static function verifyDeposited(string $expected_sha256): array {
        $expected = strtolower(trim($expected_sha256));
        if (!preg_match('/^[0-9a-f]{64}$/', $expected)) {
            return ['ok' => false, 'message' => __('Empreinte SHA-256 invalide : 64 caractères hexadécimaux.', 'printgestion')];
        }
        $version = self::getServedVersion();
        $path    = self::getCacheDir() . '/' . self::getMsiName($version);
        if (!is_file($path)) {
            return ['ok' => false, 'message' => sprintf(__('Fichier %1$s absent du dossier %2$s.', 'printgestion'), self::getMsiName($version), self::getCacheDir())];
        }
        return self::installCandidate($path, $version, $expected, 'depot', false);
    }

    /** Contrôle un fichier candidat (en-tête MSI, empreinte) puis l'enregistre comme installeur servi. */
    private static function installCandidate(string $path, string $version, string $sha256, string $source, bool $delete_if_refused): array {
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
        if ($magic !== self::MSI_MAGIC) {
            return $refuse(__('Le fichier n\'est pas un installeur MSI : refusé.', 'printgestion'));
        }
        $actual = (string) hash_file('sha256', $path);
        if (!hash_equals($sha256, $actual)) {
            PluginPrintgestionLogger::warning('agentdeploy', sprintf('Empreinte de %s : %s au lieu de %s, fichier refusé.', $path, $actual, $sha256));
            return $refuse(__('Empreinte SHA-256 différente de celle publiée : fichier corrompu ou modifié, refusé.', 'printgestion'));
        }

        $dir   = self::getCacheDir();
        $final = $dir . '/' . self::getMsiName($version);
        if ($path !== $final && !rename($path, $final)) {
            PluginPrintgestionLogger::error('agentdeploy', sprintf('Installeur %s non déplacé vers %s.', $path, $final));
            return $refuse(__('Installeur vérifié mais non enregistré sur le serveur (détail dans le journal printgestion).', 'printgestion'));
        }
        $meta = [
            'file'     => self::getMsiName($version),
            'version'  => $version,
            'size'     => (int) filesize($final),
            'sha256'   => $actual,
            'source'   => $source,
            'date'     => date('Y-m-d H:i:s'),
            'users_id' => (int) Session::getLoginUserID(),
        ];
        if (file_put_contents($dir . '/' . self::METADATA_FILE, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            PluginPrintgestionLogger::error('agentdeploy', sprintf('Description de l\'installeur non écrite dans %s.', $dir));
            return ['ok' => false, 'message' => __('Installeur vérifié mais sa description n\'a pas pu être écrite (détail dans le journal printgestion).', 'printgestion')];
        }
        // Une seule version servie : les installeurs des autres versions sont retirés.
        foreach (glob($dir . '/GLPI-Agent-*-x64.msi') ?: [] as $other) {
            if ($other !== $final && !unlink($other)) {
                PluginPrintgestionLogger::warning('agentdeploy', sprintf('Ancien installeur %s non supprimé.', $other));
            }
        }
        return ['ok' => true, 'message' => sprintf(
            __('Installeur GLPI Agent %1$s vérifié (SHA-256 %2$s) et prêt à être servi.', 'printgestion'),
            $version,
            $actual
        )];
    }

    // ── Paquet Windows ────────────────────────────────────────────────────────

    /** Motifs empêchant de produire le paquet d'une entité ; vide si prêt. */
    public static function getPackageBlockers(Entity $entity): array {
        $blockers = [];
        $tag      = trim((string) ($entity->fields['tag'] ?? ''));
        if ($tag === '') {
            $blockers[] = __('TAG de l\'entité vide : sans lui, les équipements découverts par la sonde n\'arrivent pas dans cette entité.', 'printgestion');
        } elseif (!self::isValidTag($tag)) {
            $blockers[] = __('TAG inutilisable dans la commande d\'installation : lettres, chiffres, point, tiret et soulignement uniquement (100 caractères au plus).', 'printgestion');
        } elseif (countElementsInTable(Entity::getTable(), ['tag' => $tag]) > 1) {
            $blockers[] = sprintf(__('TAG « %s » porté par plusieurs entités : GLPI rattacherait les équipements à la première trouvée.', 'printgestion'), $tag);
        }

        $server = self::getServerUrl();
        if ($server['error'] !== '') {
            $blockers[] = $server['error'];
        } elseif (!self::isValidServerUrl($server['url'])) {
            $blockers[] = __('URL du serveur donnée à l\'agent invalide (page « Installeur GLPI Agent »).', 'printgestion');
        }
        if (!self::isValidTrustList(self::getHttpdTrust())) {
            $blockers[] = __('Adresses autorisées sur l\'interface de l\'agent invalides (page « Installeur GLPI Agent »).', 'printgestion');
        }
        if (self::getCachedInstaller() === null) {
            $blockers[] = sprintf(
                __('Installeur GLPI Agent %s pas encore disponible sur le serveur : page « Installeur GLPI Agent » (droit de configuration du plugin).', 'printgestion'),
                self::getServedVersion()
            );
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
     * guillemets. Pas de /quiet : l'assistant graphique reste, avec les valeurs déjà remplies.
     */
    public static function buildWindowsCommand(string $msi, string $tag): string {
        $parts = ['msiexec', '/i', '"' . $msi . '"'];
        foreach (self::getWindowsProperties($tag) as $name => $value) {
            $parts[] = $name . '="' . $value . '"';
        }
        $parts[] = '/l*v "%TEMP%\\GLPI-Agent-install.log"';
        return implode(' ', $parts);
    }

    /**
     * Paquet Windows d'une entité, écrit dans un fichier temporaire que l'appelant supprime après
     * envoi : MSI officiel vérifié, lanceur .bat d'une ligne, commande à copier, note d'une page.
     *
     * @return array ['ok' => bool, 'errors' => string[], 'path', 'filename', 'version', 'tag']
     */
    public static function buildWindowsPackage(Entity $entity): array {
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

        $bat = implode("\r\n", [
            '@echo off',
            'rem GLPI Agent ' . $version . ' - installation pre-parametree pour le TAG ' . $tag,
            'rem Aucun identifiant ni secret : adresse du serveur GLPI et TAG uniquement.',
            'rem A lancer par double-clic depuis le dossier extrait du ZIP.',
            self::buildWindowsCommand('%~dp0' . $msi, $tag),
            '',
        ]);
        $command = self::buildWindowsCommand($msi, $tag) . "\r\n";
        $readme  = implode("\r\n", [
            sprintf(__('Installation de GLPI Agent %1$s — %2$s (TAG : %3$s)', 'printgestion'), $version, (string) $entity->fields['completename'], $tag),
            sprintf(__('Paquet généré par Print Gestion le %1$s par %2$s.', 'printgestion'), Html::convDateTime(date('Y-m-d H:i:s')), getUserName((int) Session::getLoginUserID())),
            __('Ce dossier ne contient aucun identifiant, mot de passe ni jeton : seulement l\'adresse du serveur GLPI et le TAG du client.', 'printgestion'),
            '',
            __('Les 3 gestes', 'printgestion'),
            __('1. Sur le PC qui servira de sonde (allumé en permanence, sur le réseau des imprimantes) : clic droit sur le fichier ZIP > Extraire tout.', 'printgestion'),
            __('2. Dans le dossier extrait : double-clic sur installer-glpi-agent.bat, accepter la demande d\'administrateur et suivre l\'assistant. L\'adresse du serveur et le TAG sont déjà remplis : ne pas les modifier.', 'printgestion'),
            __('3. Dans GLPI (fiche de l\'entité, onglet « Déploiement Agent ») : vérifier que l\'agent apparaît avec un contact récent, avant de partir.', 'printgestion'),
            '',
            __('Si Windows refuse de lancer le fichier .bat : ouvrir l\'invite de commandes (cmd, pas PowerShell) dans le dossier extrait et coller la commande du fichier commande-cmd.txt.', 'printgestion'),
            __('En cas d\'échec de l\'installation : journal %TEMP%\\GLPI-Agent-install.log sur le PC.', 'printgestion'),
            '',
        ]);

        $path = GLPI_TMP_DIR . '/printgestion-agent-' . bin2hex(random_bytes(8)) . '.zip';
        $zip  = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            PluginPrintgestionLogger::error('agentdeploy', sprintf('Paquet %s non créé.', $path));
            return ['ok' => false, 'errors' => [__('Paquet non généré (détail dans le journal printgestion).', 'printgestion')]];
        }
        $zip->addFile($installer['path'], $msi);
        $zip->setCompressionName($msi, ZipArchive::CM_STORE); // MSI déjà compressé
        $zip->addFromString('installer-glpi-agent.bat', $bat);
        $zip->addFromString('commande-cmd.txt', $command);
        $zip->addFromString('LISEZMOI.txt', "\xEF\xBB\xBF" . $readme);
        if (!$zip->close()) {
            if (is_file($path)) {
                unlink($path);
            }
            PluginPrintgestionLogger::error('agentdeploy', sprintf('Paquet %s non finalisé.', $path));
            return ['ok' => false, 'errors' => [__('Paquet non généré (détail dans le journal printgestion).', 'printgestion')]];
        }

        return [
            'ok'       => true,
            'errors'   => [],
            'path'     => $path,
            'filename' => sprintf('GLPI-Agent-%s-windows-%s.zip', $version, $tag),
            'version'  => $version,
            'tag'      => $tag,
        ];
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

    /** Onglet « Déploiement Agent » de la fiche Entité : état du rattachement, installeur. */
    public static function showForEntity(Entity $entity): void {
        $esc  = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $date = static fn($value) => $value === null || $value === '' ? '—' : Html::convDateTime((string) $value);
        $id   = (int) $entity->getID();
        $tag  = trim((string) ($entity->fields['tag'] ?? ''));

        echo "<div class='mt-3'>";
        echo "<p class='text-muted small'>" . $esc(__('Installer GLPI Agent sur un PC du client (la sonde), puis vérifier ici que les équipements arrivent dans cette entité. Le paquet téléchargé ne contient que l\'adresse du serveur GLPI et le TAG : aucun identifiant, aucun secret.', 'printgestion')) . "</p>";

        // ── 1. État du rattachement ──
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('1. État du rattachement', 'printgestion')) . "</h3></div><div class='card-body'>";
        $tag_url = Entity::getFormURLWithID($id) . '&forcetab=' . urlencode('Entity$3');
        if ($tag === '') {
            echo "<div class='alert alert-danger'><i class='ti ti-alert-octagon me-1'></i><strong>" . $esc(__('TAG de l\'entité vide : rien ne fonctionnera.', 'printgestion')) . "</strong> "
                . $esc(__('Les équipements découverts par la sonde sont rattachés à l\'entité dont le TAG correspond à celui de l\'agent. Les règles d\'entité ne jouent qu\'au premier import : un TAG corrigé après coup ne rapatrie rien, il faudrait transférer chaque équipement à la main.', 'printgestion'))
                . " <a href='" . $esc($tag_url) . "'>" . $esc(__('Renseigner le TAG (onglet Informations avancées)', 'printgestion')) . "</a></div>";
        }

        $rule   = self::getTagRuleStatus();
        $checks = [];
        $checks[] = self::checkItem(
            $tag !== '' && self::isValidTag($tag) && countElementsInTable(Entity::getTable(), ['tag' => $tag]) === 1,
            __('TAG de l\'entité', 'printgestion'),
            $tag === ''
                ? $esc(__('Vide.', 'printgestion'))
                : ("<code>" . $esc($tag) . "</code> "
                    . (!self::isValidTag($tag)
                        ? $esc(__('— caractères non acceptés dans la commande d\'installation (lettres, chiffres, point, tiret, soulignement).', 'printgestion'))
                        : (countElementsInTable(Entity::getTable(), ['tag' => $tag]) > 1
                            ? $esc(__('— porté aussi par une autre entité : les équipements iraient à la première trouvée.', 'printgestion'))
                            : ''))
                    . " <a href='" . $esc($tag_url) . "'>" . $esc(__('Modifier', 'printgestion')) . "</a>")
        );
        if ($rule['active'] === null) {
            $rule_detail = $esc(sprintf(
                __('Aucune règle active. À créer une seule fois pour tous les clients (Administration → Règles → affectation d\'un élément à une entité) : critère « %1$s » vérifie l\'expression régulière /^(.*)$/, action « %2$s » = #0. Le plugin ne la crée pas : elle engage tout GLPI.', 'printgestion'),
                __('Inventory tag'),
                __('Entity from TAG')
            ));
            if (!empty($rule['rules'])) {
                $rule_detail .= ' ' . $esc(sprintf(__('Règle présente mais désactivée : %s.', 'printgestion'), implode(', ', array_column($rule['rules'], 'name'))));
            }
        } else {
            $rule_detail = $esc(sprintf(__('Règle « %1$s » active (position %2$d).', 'printgestion'), $rule['active']['name'], (int) $rule['active']['ranking']))
                . " <a href='" . $esc(RuleImportEntity::getFormURLWithID((int) $rule['active']['id'])) . "'>" . $esc(__('Voir', 'printgestion')) . "</a>";
            if (!$rule['tag_criterion']) {
                $rule_detail .= ' ' . $esc(sprintf(__('Elle n\'a pas de critère « %s » : vérifiez qu\'elle s\'applique bien aux inventaires des sondes.', 'printgestion'), __('Inventory tag')));
            }
            if (!empty($rule['earlier'])) {
                $rule_detail .= ' ' . $esc(sprintf(
                    __('Jouées avant elle (le moteur s\'arrête à la première règle qui correspond, vérifiez qu\'elles ne capturent pas les inventaires de ce client) : %s.', 'printgestion'),
                    implode(', ', array_map(static fn(array $r) => $r['name'] . ' (' . (int) $r['ranking'] . ')', $rule['earlier']))
                ));
            }
        }
        $checks[] = self::checkItem($rule['active'] !== null && empty($rule['earlier']), __('Règle d\'affectation par TAG', 'printgestion'), $rule_detail);

        $inventory_plugin = Plugin::isPluginActive('glpiinventory');
        $checks[] = self::checkItem(
            $inventory_plugin,
            __('Plugin GLPI Inventory', 'printgestion'),
            $esc($inventory_plugin
                ? __('Actif : l\'agent recevra les tâches de découverte et d\'inventaire réseau.', 'printgestion')
                : __('Absent ou inactif : l\'agent inventoriera son PC mais ne recevra aucune tâche réseau. Installez-le AVANT de déployer : l\'adresse du serveur donnée à l\'agent change, un agent déjà installé serait à réinstaller.', 'printgestion'))
        );
        echo "<div class='row g-3 mb-3'>" . implode('', $checks) . "</div>";

        // Agents de l'entité : lien vers la fiche native, rien de plus que ce qui aide à conclure.
        $agents  = self::getEntityAgents($id);
        $silent  = PluginPrintgestionCollect::getSilentDays();
        $badges  = [
            'old'     => ['bg-red text-red-fg', __('Trop ancienne', 'printgestion')],
            'update'  => ['bg-orange text-orange-fg', __('À mettre à jour', 'printgestion')],
            'ok'      => ['bg-green text-green-fg', __('À jour', 'printgestion')],
            'unknown' => ['bg-secondary text-secondary-fg', __('Inconnue', 'printgestion')],
        ];
        echo "<h4 class='mb-2'>" . $esc(__('Agents rattachés à cette entité', 'printgestion')) . "</h4>";
        if (empty($agents)) {
            echo "<p class='text-muted mb-0'>" . $esc(__('Aucun agent pour l\'instant. Après l\'installation, l\'agent apparaît ici dès son premier contact.', 'printgestion')) . "</p>";
        } else {
            echo "<div class='table-responsive'><table class='table table-sm mb-0'><thead><tr>"
                . "<th>" . $esc(__('Agent', 'printgestion')) . "</th><th>" . $esc(__('Poste', 'printgestion')) . "</th>"
                . "<th>" . $esc(__('Version', 'printgestion')) . "</th><th>" . $esc(__('Dernier contact', 'printgestion')) . "</th>"
                . "<th>" . $esc(__('TAG', 'printgestion')) . "</th><th>" . $esc(__('Collecte réseau', 'printgestion')) . "</th></tr></thead><tbody>";
            foreach ($agents as $agent) {
                [$badge_class, $badge_label] = $badges[PluginPrintgestionCollect::getAgentVersionStatus($agent['version_value'])];
                $is_silent = $agent['last_contact'] === null || strtotime((string) $agent['last_contact']) < time() - $silent * DAY_TIMESTAMP;
                $host      = '—';
                if (is_a((string) $agent['itemtype'], CommonDBTM::class, true) && (int) $agent['items_id'] > 0) {
                    $host = "<a href='" . $esc($agent['itemtype']::getFormURLWithID((int) $agent['items_id'])) . "'>" . $esc($agent['itemtype']::getTypeName(1) . ' #' . (int) $agent['items_id']) . "</a>";
                }
                $agent_tag = trim((string) $agent['tag']);
                $network   = (int) $agent['use_module_network_discovery'] === 1 && (int) $agent['use_module_network_inventory'] === 1;
                echo "<tr><td><a href='" . $esc(Agent::getFormURLWithID((int) $agent['id'])) . "'>" . $esc($agent['name']) . "</a></td>"
                    . "<td>" . $host . "</td>"
                    . "<td>" . $esc($agent['version_value'] !== '' ? $agent['version_value'] : '—') . " <span class='badge {$badge_class}'>" . $esc($badge_label) . "</span></td>"
                    . "<td>" . $esc($date($agent['last_contact'])) . ($is_silent ? " <span class='badge bg-red text-red-fg'>" . $esc(__('Muet', 'printgestion')) . "</span>" : '') . "</td>"
                    . "<td>" . ($agent_tag !== '' ? "<code>" . $esc($agent_tag) . "</code>" : '—')
                    . ($agent_tag !== $tag ? " <span class='badge bg-orange text-orange-fg'>" . $esc(__('≠ TAG de l\'entité', 'printgestion')) . "</span>" : '') . "</td>"
                    . "<td>" . ($network
                        ? "<span class='badge bg-green text-green-fg'>" . $esc(__('Installée', 'printgestion')) . "</span>"
                        : "<span class='badge bg-red text-red-fg'>" . $esc(__('Absente : réinstaller avec feat_NETINV', 'printgestion')) . "</span>") . "</td></tr>";
            }
            echo "</tbody></table></div>";
        }
        echo "</div></div>";

        // ── 2. Installeur ──
        $version  = self::getServedVersion();
        $blockers = self::getPackageBlockers($entity);
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(sprintf(__('2. Télécharger l\'installeur (GLPI Agent %s)', 'printgestion'), $version)) . "</h3></div><div class='card-body'>";
        if (!empty($blockers)) {
            echo "<div class='alert alert-warning'><strong>" . $esc(__('Paquet indisponible :', 'printgestion')) . "</strong><ul class='mb-0'>";
            foreach ($blockers as $blocker) {
                echo "<li>" . $esc($blocker) . "</li>";
            }
            echo "</ul></div>";
        }
        echo "<div class='d-flex flex-wrap gap-2 mb-3'>";
        if (empty($blockers)) {
            echo "<a class='btn btn-primary' href='" . $esc(self::getDownloadURL($id, 'windows')) . "'><i class='ti ti-brand-windows me-1'></i>" . $esc(__('Windows', 'printgestion')) . "</a>";
        } else {
            echo "<button type='button' class='btn btn-primary' disabled><i class='ti ti-brand-windows me-1'></i>" . $esc(__('Windows', 'printgestion')) . "</button>";
        }
        foreach ([['ti-brand-ubuntu', __('Linux', 'printgestion')], ['ti-brand-apple', __('macOS', 'printgestion')]] as [$icon, $label]) {
            echo "<button type='button' class='btn btn-outline-secondary' disabled title='" . $esc(__('Prévu en phase 6', 'printgestion')) . "'><i class='ti {$icon} me-1'></i>" . $esc($label) . "</button>";
        }
        echo "</div>";
        echo "<p class='text-muted small'>" . $esc(__('Paquet ZIP : MSI officiel servi par ce serveur, lanceur installer-glpi-agent.bat (commande d\'une ligne), la même commande à copier dans cmd, et une note d\'une page pour le technicien. Linux et macOS : phase 6.', 'printgestion')) . "</p>";

        if ($tag !== '' && self::isValidTag($tag)) {
            echo "<div class='mb-2 fw-bold'>" . $esc(__('Commande lancée par le paquet', 'printgestion')) . "</div>";
            echo "<pre class='mb-3' style='white-space:pre-wrap'>" . $esc(self::buildWindowsCommand(self::getMsiName($version), $tag)) . "</pre>";
        }
        $reasons = [
            'SERVER'       => __('Serveur GLPI qui reçoit les inventaires et distribue les tâches.', 'printgestion'),
            'TAG'          => __('Rattachement des équipements à cette entité (règle « Entity from TAG »).', 'printgestion'),
            'ADDLOCAL'     => __('Inventaire du poste plus découverte et inventaire réseau : seul l\'inventaire du poste est installé par défaut depuis l\'agent 1.8.', 'printgestion'),
            'HTTPD_TRUST'  => __('Interface locale de l\'agent ouverte au poste lui-même (forcer une exécution sur place).', 'printgestion'),
            'SNMP_RETRIES' => __('Un paquet SNMP perdu ne fait plus disparaître les consommables d\'un relevé (0 par défaut).', 'printgestion'),
            'RUNNOW'       => __('Premier inventaire aussitôt l\'installation terminée.', 'printgestion'),
            'EXECMODE'     => __('Agent installé comme service Windows.', 'printgestion'),
            'QUICKINSTALL' => __('Assistant sans les écrans de configuration détaillée.', 'printgestion'),
        ];
        echo "<div class='table-responsive'><table class='table table-sm mb-0'><thead><tr><th>" . $esc(__('Propriété', 'printgestion')) . "</th><th>" . $esc(__('Valeur', 'printgestion')) . "</th><th>" . $esc(__('Pourquoi', 'printgestion')) . "</th></tr></thead><tbody>";
        foreach (self::getWindowsProperties($tag) as $name => $value) {
            echo "<tr><td><code>" . $esc($name) . "</code></td><td><code>" . $esc($value !== '' ? $value : '—') . "</code></td><td class='small'>" . $esc($reasons[$name] ?? '') . "</td></tr>";
        }
        echo "</tbody></table></div>";
        echo "</div></div>";

        echo "<p class='text-muted small'>" . $esc(__('3. Assistant de raccordement des imprimantes (adresses IP, lieu, commentaire) : phase 2.', 'printgestion')) . "</p>";
        echo "</div>";
    }

    /** Page « Installeur GLPI Agent » : installeur servi, adresses, prérequis ; actions avec le droit de configuration. */
    public static function showPage(): void {
        global $CFG_GLPI;

        $esc       = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $can_edit  = Session::haveRight('plugin_printgestion_config', UPDATE);
        $page      = self::getPageURL();
        $version   = self::getServedVersion();
        $installer = self::getCachedInstaller();
        $config    = PluginPrintgestionConfig::getInstance();

        echo "<p class='text-muted small'>" . $esc(__('Installeur GLPI Agent servi aux techniciens depuis l\'onglet « Déploiement Agent » des entités. Le fichier est récupéré par ce serveur et vérifié : les postes des clients ne téléchargent jamais rien depuis GitHub.', 'printgestion')) . "</p>";

        // Installeur servi.
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(sprintf(__('Installeur servi : GLPI Agent %s pour Windows', 'printgestion'), $version)) . "</h3></div><div class='card-body'>";
        if ($installer !== null) {
            echo "<div class='alert alert-success'><i class='ti ti-circle-check me-1'></i>" . $esc(sprintf(
                __('%1$s vérifié : %2$s octets, SHA-256 %3$s, %4$s le %5$s.', 'printgestion'),
                $installer['file'],
                number_format((int) $installer['size'], 0, ',', ' '),
                $installer['sha256'],
                $installer['source'] === 'github' ? __('récupéré sur GitHub', 'printgestion') : __('déposé à la main', 'printgestion'),
                Html::convDateTime((string) $installer['date'])
            )) . "</div>";
        } else {
            echo "<div class='alert alert-warning'><i class='ti ti-alert-triangle me-1'></i>" . $esc(sprintf(__('%s pas encore disponible sur ce serveur : aucun paquet ne peut être téléchargé.', 'printgestion'), self::getMsiName($version))) . "</div>";
        }
        if ($can_edit) {
            echo "<form method='post' action='" . $esc($page) . "' class='mb-3'>";
            echo "<button type='submit' name='fetch_github' value='1' class='btn btn-primary'><i class='ti ti-cloud-download me-1'></i>"
                . $esc(sprintf(__('Récupérer GLPI Agent %s depuis GitHub (empreinte vérifiée)', 'printgestion'), $version)) . "</button>";
            Html::closeForm();

            echo "<p class='small mb-1'>" . $esc(sprintf(
                __('Serveur sans accès à GitHub : déposer %1$s dans le dossier %2$s, puis coller l\'empreinte SHA-256 publiée avec la release (fichier glpi-agent-%3$s.sha256).', 'printgestion'),
                self::getMsiName($version),
                self::getCacheDir(),
                $version
            )) . "</p>";
            echo "<form method='post' action='" . $esc($page) . "' class='row g-2 align-items-end'>";
            echo "<div class='col-md-8'><input type='text' class='form-control' name='sha256' maxlength='64' pattern='[0-9a-fA-F]{64}' placeholder='" . $esc(__('Empreinte SHA-256 (64 caractères)', 'printgestion')) . "' required></div>";
            echo "<div class='col-md-4'><button type='submit' name='verify_deposit' value='1' class='btn btn-outline-primary'><i class='ti ti-file-check me-1'></i>" . $esc(__('Vérifier le fichier déposé', 'printgestion')) . "</button></div>";
            Html::closeForm();
        } else {
            echo "<p class='text-muted small mb-0'>" . $esc(__('Récupération et vérification de l\'installeur : droit de configuration du plugin.', 'printgestion')) . "</p>";
        }
        echo "</div></div>";

        // Adresses et version.
        $server   = self::getServerUrl();
        $sources  = [
            'config'        => __('réglée ici', 'printgestion'),
            'glpiinventory' => __('automatique : point d\'entrée du plugin GLPI Inventory', 'printgestion'),
            'glpi'          => __('automatique : URL de GLPI (plugin GLPI Inventory absent, aucune tâche réseau)', 'printgestion'),
        ];
        $host     = (string) parse_url((string) ($CFG_GLPI['url_base'] ?? ''), PHP_URL_HOST);
        $resolved = $host !== '' ? gethostbyname($host) : '';
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('Paramètres transmis à l\'installation', 'printgestion')) . "</h3></div><div class='card-body'>";
        echo "<ul class='mb-3'>";
        echo "<li>" . $esc(__('Adresse du serveur (SERVER) :', 'printgestion')) . " <code>" . $esc($server['url'] !== '' ? $server['url'] : '—') . "</code> <span class='text-muted small'>(" . $esc($sources[$server['source']]) . ")</span>"
            . ($server['error'] !== '' ? " <span class='text-danger'>" . $esc($server['error']) . "</span>" : '') . "</li>";
        echo "<li>" . $esc(__('Adresses autorisées sur l\'interface de l\'agent (HTTPD_TRUST) :', 'printgestion')) . " <code>" . $esc(self::getHttpdTrust()) . "</code></li>";
        echo "<li>" . $esc(__('Fonctions (ADDLOCAL), réessais SNMP, mode :', 'printgestion')) . " <code>" . $esc(self::ADDLOCAL) . "</code>, <code>SNMP_RETRIES=" . (int) self::SNMP_RETRIES . "</code>, <code>RUNNOW=1 EXECMODE=1 QUICKINSTALL=1</code></li>";
        echo "</ul>";
        echo "<p class='text-muted small'>" . $esc(__('Le serveur GLPI ne peut réveiller une sonde (statut, inventaire à la demande) que s\'il la joint sur son port 62354 : impossible derrière le NAT d\'un client, sauf VPN. L\'accès depuis le poste lui-même (127.0.0.1) reste toujours ouvert.', 'printgestion'))
            . ($resolved !== '' && $resolved !== $host ? ' ' . $esc(sprintf(__('Adresse du serveur GLPI selon le DNS : %s.', 'printgestion'), $resolved)) : '') . "</p>";
        if ($can_edit) {
            echo "<form method='post' action='" . $esc($page) . "' class='row g-3 align-items-end'>";
            echo "<div class='col-md-3'><label class='form-label'>" . $esc(__('Version épinglée (vide : dernière vérifiée)', 'printgestion')) . "</label>"
                . "<input type='text' class='form-control' name='agent_version' value='" . $esc($config->fields['agent_version'] ?? '') . "' placeholder='" . $esc(self::DEFAULT_VERSION) . "'></div>";
            echo "<div class='col-md-5'><label class='form-label'>" . $esc(__('URL du serveur (vide : automatique)', 'printgestion')) . "</label>"
                . "<input type='text' class='form-control' name='agent_server_url' value='" . $esc($config->fields['agent_server_url'] ?? '') . "' placeholder='https://…'></div>";
            echo "<div class='col-md-4'><label class='form-label'>" . $esc(__('Adresses autorisées en plus du poste (IPv4, CIDR)', 'printgestion')) . "</label>"
                . "<input type='text' class='form-control' name='agent_httpd_trust' value='" . $esc($config->fields['agent_httpd_trust'] ?? '') . "' placeholder='203.0.113.10'></div>";
            echo "<div class='col-12'><button type='submit' name='save_settings' value='1' class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>" . $esc(__('Enregistrer', 'printgestion')) . "</button></div>";
            Html::closeForm();
        }
        echo "</div></div>";

        // Prérequis communs à tous les clients.
        $rule          = self::getTagRuleStatus();
        $inventory_on  = (int) Config::getConfigurationValue('inventory', 'enabled_inventory') === 1;
        $with_tag      = countElementsInTable(Entity::getTable(), ['NOT' => ['tag' => null], ['tag' => ['<>', '']]]);
        $checks        = [
            self::checkItem($inventory_on, __('Inventaire GLPI', 'printgestion'), $esc($inventory_on
                ? __('Activé.', 'printgestion')
                : __('Désactivé (Administration → Inventaire) : aucun inventaire ne peut être reçu.', 'printgestion'))),
            self::checkItem(Plugin::isPluginActive('glpiinventory'), __('Plugin GLPI Inventory', 'printgestion'), $esc(Plugin::isPluginActive('glpiinventory')
                ? __('Actif : tâches de découverte et d\'inventaire réseau disponibles.', 'printgestion')
                : __('Absent ou inactif : à installer avant de déployer les sondes (adresse du serveur des agents).', 'printgestion'))),
            self::checkItem($rule['active'] !== null, __('Règle d\'affectation par TAG', 'printgestion'), $esc($rule['active'] !== null
                ? sprintf(__('Règle « %1$s » active (position %2$d).', 'printgestion'), $rule['active']['name'], (int) $rule['active']['ranking'])
                : sprintf(__('Aucune règle active : critère « %1$s » expression régulière /^(.*)$/, action « %2$s » = #0, à créer à la main.', 'printgestion'), __('Inventory tag'), __('Entity from TAG')))),
            self::checkItem($with_tag > 0, __('Entités avec un TAG', 'printgestion'), $esc(sprintf(__('%d entité(s) ont un TAG renseigné.', 'printgestion'), $with_tag))),
        ];
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('Prérequis', 'printgestion')) . "</h3></div>";
        echo "<div class='card-body'><div class='row g-3'>" . implode('', $checks) . "</div></div></div>";
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
