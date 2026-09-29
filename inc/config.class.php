<?php
/**
 * PluginPrintgestionConfig — configuration singleton id=1 (pattern plugin Gestion).
 * Gère aussi l'installation/désinstallation des tables annexes du plugin.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionConfig extends CommonDBTM {

    static $rightname = 'plugin_printgestion_config';

    /**
     * Identifiant du formulaire de configuration. Le sien, pas « main-form » que GLPI donne déjà au sien : deux
     * éléments du même id dans une page est une erreur, et le JS de GLPI cible `#main-form` par endroits. Il sert
     * aussi à rattacher le bouton « Enregistrer » par l'attribut form=.
     */
    const FORM_ID = 'pg-config-form';

    /** Colonnes chiffrées avec GLPIKey (déclarées au hook secured_fields dans setup.php). */
    /**
     * Colonnes chiffrées (GLPIKey) : le secret client GLS et la passphrase MBE, jamais réaffichés ; l'identifiant
     * MBE, chiffré au repos mais réaffiché dans le formulaire (ce n'est pas un secret).
     */
    const SECRET_FIELDS = ['gls_client_secret', 'mbe_username', 'mbe_passphrase'];
    /** Jamais rendues par l'API REST (même chiffrées) : GLPI les retire de la réponse via unsetUndisclosedFields(). */
    public static $undisclosedFields = ['gls_client_id', 'gls_client_secret', 'mbe_username', 'mbe_passphrase'];

    static private $_instance = null;

    /**
     * Valeur déchiffrée d'une clé API enregistrée. Chaîne vide si aucune clé, ou si
     * elle est indéchiffrable (GLPIKey signale alors lui-même l'échec).
     */
    public static function getSecret(string $field): string {
        if (!in_array($field, self::SECRET_FIELDS, true)) {
            throw new InvalidArgumentException(sprintf('Champ secret inconnu : %s', $field));
        }
        $stored = (string)(self::getInstance()->fields[$field] ?? '');
        if ($stored === '') {
            return '';
        }
        return (string)(new GLPIKey())->decrypt($stored);
    }

    function __construct() {
        global $DB;
        if ($DB->tableExists($this->getTable())) {
            $this->getFromDB(1);
        }
    }

    static function getInstance() {
        if (!isset(self::$_instance)) {
            global $DB;
            self::$_instance = new self();
            // Résilience : si la table config n'existe pas (plugin non installé /
            // désinstallation en cours), getFromDB() lèverait une erreur SQL → on
            // retombe sur une config vide pour ne pas casser plugin_init / les pages.
            if (!$DB->tableExists(self::getTable()) || !self::$_instance->getFromDB(1)) {
                self::$_instance->getEmpty();
            }
        }
        return self::$_instance;
    }

    static function getTypeName($nb = 0) {
        return __('Print Gestion', 'printgestion');
    }

    /**
     * Types de contrat natifs (ContractType) paramétrés « consommables inclus » :
     * une ligne de commande est sous contrat si l'imprimante a un contrat en cours
     * de l'un de ces types. Liste vide = rien n'est sous contrat.
     */
    public static function getConsumablesContractTypes(): array {
        $raw = (string)(self::getInstance()->fields['consumables_contracttypes'] ?? '');
        $ids = array_filter(
            array_map('intval', preg_split('/[,;\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []),
            static fn(int $id) => $id > 0
        );
        return array_values(array_unique($ids));
    }

    /**
     * Interrupteur de feature. Permet de n'activer que ce qu'on utilise
     * (menu + onglets + crons du domaine désactivés sinon → pas de ressources
     * gaspillées). Features : 'contrats' | 'toner' | 'cout'.
     * Défaut activé (1) si la colonne n'existe pas encore (install en cours).
     */
    static function isFeatureEnabled(string $feature): bool {
        global $DB;
        // Table config absente (plugin non installé / uninstall en cours) → aucune
        // feature active : évite que plugin_init interroge des tables supprimées.
        if (!$DB->tableExists(self::getTable())) {
            return false;
        }
        // Fermé par défaut : une colonne absente ou un nom de module mal orthographié désactive le module, il ne
        // l'ouvre jamais en silence. Les colonnes existent avec la valeur 1 dès l'installation (schéma de référence).
        $cfg = self::getInstance();
        $col = 'enable_' . $feature;
        return (int) ($cfg->fields[$col] ?? 0) === 1;
    }

    /**
     * Icône de l'onglet. Onglet « Print Gestion » de la configuration de GLPI : l'icône du plugin, la même que son menu.
     *
     * Sans elle, GLPI retombe sur l'icône par défaut de CommonDBTM, qui vaut « fa-empty-icon » et que
     * createTabEntry() remplace alors par rien.
     */
    static function getIcon() {
        return 'ti ti-printer';
    }

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item->getType() == 'Config') {
            return self::createTabEntry(__('Print Gestion', 'printgestion'));
        }
        return '';
    }

    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        if ($item->getType() == 'Config') {
            self::showConfigForm();
        }
        return true;
    }

    /** Tables et contexte de configuration : PluginPrintgestionSchema. */
    static function uninstall(Migration $migration) {
        PluginPrintgestionSchema::uninstall();
        return true;
    }

    // ─────────────────────────────────────────────────────────────
    //  FORMULAIRE DE CONFIGURATION
    // ─────────────────────────────────────────────────────────────

    static function showConfigForm() {
        global $CFG_GLPI;

        if (!Session::haveRight(self::$rightname, READ)) {
            return false;
        }

        $canedit = Session::haveRight(self::$rightname, UPDATE);
        $config  = self::getInstance();

        // Pattern plugin Gestion : showFormHeader() ouvre un <form> avec
        // action=$this->getFormURL() (→ plugins/printgestion/front/config.form.php)
        // ET inclut automatiquement _glpi_csrf_token. On referme ensuite la ligne
        // de table ouverte par showFormHeader pour rendre nos cards à la place.
        // form_id explicite : showFormHeader() nomme sinon son formulaire « main-form », l'identifiant que GLPI
        // donne déjà au sien. Deux éléments du même id dans une page est une erreur, et le JS de GLPI cible
        // `#main-form` par endroits — il tomberait sur le premier trouvé, pas forcément le nôtre.
        // canedit passé explicitement aux DEUX appels : sans lui, le gabarit d'en-tête décide d'ouvrir le <form>
        // avec item.canEdit(), pendant que celui des boutons émet le jeton CSRF et le </form> avec sa propre valeur
        // par défaut (vrai). Si les deux réponses divergeaient, la page porterait un bouton et un jeton sans
        // formulaire autour — et le clic ne partirait nulle part. Les deux lisent désormais la même chose.
        $config->showFormHeader(['colspan' => 4, 'form_id' => self::FORM_ID, 'canedit' => $canedit]);
        echo '</td></tr></table>';
        // Jeton CSRF posé ici, tout en haut du formulaire.
        //
        // GLPI émet le sien tout en bas, juste avant </form> (components/form/buttons.html.twig), après une longue
        // suite de gabarits et de tables — et sur cette page il n'arrive pas dans le formulaire. Constaté dans le
        // navigateur : `pg-config-form` ne contenait aucun `_glpi_csrf_token`, donc tout envoi partait sans jeton et
        // GLPI le refusait (AccessDeniedHttpException sur checkCSRF), quand il partait.
        //
        // Ici, le jeton est le premier enfant du formulaire, en dehors de toute table : aucun gabarit, aucune
        // relocalisation de l'analyseur HTML ne peut l'en sortir. Un second jeton plus bas ne gêne pas — chacun est
        // valide, et c'est le dernier envoyé que PHP retient.
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        // Tout le formulaire passe par un tampon : en lecture seule, chaque contrôle de saisie ressort désactivé.
        ob_start();

        // En tête : état réel de l'environnement (contrôles automatiques, journal du plugin compris).
        PluginPrintgestionConfighealth::showCard($canedit);

        // ── Sommaire et sections : le lecteur sait où il est, et où aller ──
        $escape   = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $sections = [
            'modules'     => __('Modules', 'printgestion'),
            'contrats'    => __('Gestion contractuelle', 'printgestion'),
            'toner'       => __('Gestion toner & expéditions', 'printgestion'),
            'transport'   => __('Transport', 'printgestion'),
            'collecte'    => __('Collecte SNMP / Déploiement Agent', 'printgestion'),
            'maintenance' => __('Maintenance', 'printgestion'),
        ];
        echo "<div class='card mb-3'><div class='card-body d-flex flex-wrap align-items-center gap-2'>"
            . "<span class='fw-bold me-1'><i class='ti ti-list me-1'></i>" . $escape(__('Sommaire', 'printgestion')) . "</span>";
        foreach ($sections as $anchor => $title) {
            echo "<a class='btn btn-sm btn-outline-secondary' href='#pg-sec-" . $anchor . "'>" . $escape($title) . "</a>";
        }
        echo "</div></div>";
        $section = static function (string $anchor, string $intro) use ($sections, $escape): void {
            echo "<h2 class='mt-4 mb-1' id='pg-sec-" . $anchor . "'>" . $escape($sections[$anchor]) . "</h2>"
                . "<p class='text-muted mb-3'>" . $escape($intro) . "</p>";
        };

        // ── Seuils ────────────────────────────────────────────────
        // Helper : rend un label avec icône d'info et tooltip
        $label_with_tip = function (string $label, string $tip): string {
            $tip_esc = htmlspecialchars($tip, ENT_QUOTES, 'UTF-8');
            return "<label class='form-label'>" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                . " <i class='fa-solid fa-circle-info text-muted ms-1' data-bs-toggle='tooltip'"
                . " title=\"{$tip_esc}\"></i></label>";
        };

        $section('modules', __('Allumez seulement ce que vous utilisez : chaque module a son menu, ses onglets et ses tâches automatiques.', 'printgestion'));

        // ── Activation des modules (interrupteurs de features) ────────
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . __('Activation des modules', 'printgestion') . "</h3></div><div class='card-body'>";
        echo "<p class='text-muted mb-3'>"
            . __("Désactivez les modules non utilisés : leur menu, leurs onglets et leurs tâches planifiées ne seront pas chargés (aucune ressource consommée).", 'printgestion')
            . "</p>";
        echo "<div class='row g-3'>";
        $feature_toggle = function (string $feature, string $label) use ($config) {
            $on = (int) ($config->fields['enable_' . $feature] ?? 1) === 1;
            $id = 'enable_' . $feature;
            echo "<div class='col-md-4'><div class='form-check form-switch'>";
            echo "<input type='hidden' name='" . $id . "' value='0'>";
            echo "<input type='checkbox' class='form-check-input' id='" . $id . "' name='" . $id . "' value='1'"
                . ($on ? ' checked' : '') . ">";
            echo "<label class='form-check-label' for='" . $id . "'>"
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</label>";
            echo "</div></div>";
        };
        $feature_toggle('contrats', __('Gestion contractuelle', 'printgestion'));
        $feature_toggle('toner', __('Gestion toner & expéditions', 'printgestion'));
        $feature_toggle('cout', __('Coût à la page', 'printgestion'));
        $feature_toggle('deploiement', __('Collecte SNMP / Déploiement Agent', 'printgestion'));
        $feature_toggle('sage', __('Référentiel Sage', 'printgestion'));
        echo "</div></div></div>";

        $section('contrats', __('Contrats d\'impression : les alertes natives de fin de contrat et de préavis, réglées d\'ici.', 'printgestion'));
        PluginPrintgestionContractalert::showConfigCard();

        $section('toner', __('Du relevé SNMP à la cartouche livrée : seuils d\'alerte, verrous contre le double envoi, commande aux Achats, demandes d\'envoi, notifications, correspondance des cartouches.', 'printgestion'));

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . __("Seuils d'alerte", 'printgestion') . "</h3></div><div class='card-body'>";
        echo "<p class='text-muted small mb-3'>" . $escape(__('Ce qui fait passer un toner « à surveiller » puis « critique », l\'estimation des jours restants, la détection d\'une cartouche changée.', 'printgestion')) . "</p>";
        echo "<div class='row g-3'>";

        echo "<div class='col-md-3'>"
            . $label_with_tip(
                __('Seuil jours estimés', 'printgestion'),
                __("Nombre de jours restants estimés en-dessous duquel un toner passe en statut « À surveiller ». Utilisé par le dashboard Alertes pour classer les cartouches qui vont bientôt devoir être remplacées.", 'printgestion')
            );
        echo "<input type='number' min='0' class='form-control' name='threshold_days' value='"
            . (int)($config->fields['threshold_days'] ?? 30) . "'></div>";

        echo "<div class='col-md-3'>"
            . $label_with_tip(
                __('Seuil niveau min (%)', 'printgestion'),
                __("Pourcentage de toner en-dessous duquel une cartouche passe en statut « Critique » (alerte immédiate, envoi mail aux commerciaux pour information client). Indépendant du nombre de jours restants.", 'printgestion')
            );
        echo "<input type='number' min='0' max='100' class='form-control' name='threshold_level' value='"
            . (int)($config->fields['threshold_level'] ?? 15) . "'></div>";

        echo "<div class='col-md-3'>"
            . $label_with_tip(
                __('Délai rappel (jours)', 'printgestion'),
                __("Nombre de jours après l'envoi d'une cartouche avant de déclencher un rappel automatique (mail + alerte dashboard) si l'installation n'a pas été détectée. Permet d'éviter les cartouches expédiées mais jamais posées.", 'printgestion')
            );
        echo "<input type='number' min='1' class='form-control' name='reminder_days' value='"
            . max(1, (int)($config->fields['reminder_days'] ?? 7)) . "'></div>";

        echo "<div class='col-md-3'>"
            . $label_with_tip(
                __('Delta détection cartouche (%)', 'printgestion'),
                __("Hausse minimale de niveau toner (en %) entre deux relevés SNMP pour considérer qu'une cartouche a été physiquement remplacée. Ex: 20 signifie qu'une hausse de +20 points (ex: 5% → 80%) déclenche la détection.", 'printgestion')
            );
        echo "<input type='number' min='1' max='100' class='form-control' name='detection_delta' value='"
            . (int)($config->fields['detection_delta'] ?? 20) . "'></div>";

        echo "<div class='col-md-3'>"
            . $label_with_tip(
                __("Fenêtre détection mauvaise imprimante (jours)", 'printgestion'),
                __("Pose détectée sur une imprimante qui n'attendait aucun envoi : le plugin cherche dans les N jours précédents un envoi déjà parti (expédié, en transit ou livré) pour une autre imprimante du même site, même référence de cartouche. Il le signale alors en « mauvaise imprimante » sur l'écran Expéditions, à vérifier et confirmer : aucune réattribution automatique.", 'printgestion')
            );
        echo "<input type='number' min='1' class='form-control' name='wrong_printer_lookback_days' value='"
            . (int)($config->fields['wrong_printer_lookback_days'] ?? 30) . "'></div>";

        echo "<div class='col-md-3'>"
            . $label_with_tip(
                __('Rendement par défaut (pages/cartouche)', 'printgestion'),
                __("Yield par défaut en pages imprimables par cartouche. Utilisé pour estimer les jours restants quand l'historique de consommation du toner est insuffisant pour mesurer le yield réel. Un yield réel est calculé automatiquement dès qu'une baisse ≥ 3 points est observée.", 'printgestion')
            );
        echo "<input type='number' min='100' class='form-control' name='default_pages_per_cartridge' value='"
            . (int)($config->fields['default_pages_per_cartridge'] ?? 5000) . "'></div>";


        echo "</div></div></div>";

        // ── Anti-double-envoi ─────────────────────────────────────
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . __('Anti-double-envoi', 'printgestion') . "</h3></div><div class='card-body'>";
        echo "<p class='text-muted small mb-3'>"
            . __("Un envoi en cours (commandé, expédié, livré mais non posé) bloque toujours une nouvelle commande pour la même machine et le même toner, sans limite de durée. Les réglages ci-dessous ajoutent deux verrous temporaires, contournables en cas de consommation anormale.", 'printgestion')
            . "</p>";
        echo "<div class='row g-3'>";

        echo "<div class='col-md-4'>"
            . $label_with_tip(
                __('Garde après pose (jours)', 'printgestion'),
                __("Après une pose détectée ou confirmée sur une machine et un toner, aucune nouvelle commande n'est proposée pendant ce nombre de jours — y compris quand la cartouche a été posée sur une autre machine que prévu. Protège contre une fausse détection ou un niveau qui oscille. 0 = garde désactivée.", 'printgestion')
            );
        echo "<input type='number' min='0' max='365' class='form-control' name='guard_days' value='"
            . (int)($config->fields['guard_days'] ?? 5) . "'></div>";

        echo "<div class='col-md-4'>"
            . $label_with_tip(
                __('Seuil de contournement (%)', 'printgestion'),
                __("Pendant la garde ou malgré un ticket récent, une commande reste possible si le niveau mesuré du toner est inférieur ou égal à ce seuil (consommation anormale). Ne s'applique jamais à un envoi en cours non posé.", 'printgestion')
            );
        echo "<input type='number' min='0' max='100' class='form-control' name='guard_bypass_level' value='"
            . (int)($config->fields['guard_bypass_level'] ?? 10) . "'></div>";

        echo "<div class='col-md-4'>"
            . $label_with_tip(
                __('Ticket récent (jours)', 'printgestion'),
                __("Aucune nouvelle commande si un ticket non résolu lié à la machine a été ouvert il y a moins de ce nombre de jours. 0 = verrou désactivé.", 'printgestion')
            );
        echo "<input type='number' min='0' max='365' class='form-control' name='guard_ticket_days' value='"
            . (int)($config->fields['guard_ticket_days'] ?? 10) . "'></div>";

        echo "</div></div></div>";

        // ── Contrats : consommables inclus (sous contrat / hors contrat) ──
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . __('Contrats — consommables inclus', 'printgestion') . "</h3></div><div class='card-body'>";
        echo "<p class='text-muted small mb-3'>"
            . __("Une ligne de commande est « sous contrat » (prix 0) si l'imprimante est liée à un contrat en cours dont le type figure ci-dessous. Sinon elle est « hors contrat » : le prix reste vide, jamais 0. Contrat en cours : date de début atteinte, puis reconduction tacite ou date de fin (début + durée) postérieure à aujourd'hui ; si plusieurs contrats sont en cours, le plus récemment commencé est retenu.", 'printgestion')
            . "</p>";
        echo "<div class='row g-3'><div class='col-md-8'>"
            . $label_with_tip(
                __('Types de contrat « consommables inclus »', 'printgestion'),
                __("Types de contrat GLPI (Configuration → Intitulés → Types de contrat) pour lesquels les consommables sont fournis sans facturation. Aucun type sélectionné : toutes les lignes sont hors contrat.", 'printgestion')
            );
        Dropdown::show('ContractType', [
            'name'     => 'consumables_contracttypes',
            'multiple' => true,
            'value'    => self::getConsumablesContractTypes(),
            'width'    => '100%',
        ]);
        echo "</div></div>";
        echo "</div></div>";

        // ── Fichier Gesconso (commande aux Achats) ───────────────
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . __('Fichier Gesconso (commande aux Achats)', 'printgestion') . "</h3></div><div class='card-body'>";
        echo "<p class='text-muted small mb-3'>"
            . __("Colonne Designation : n° de série, lieu et libellé de la cartouche, séparés par le séparateur ci-dessous (espaces compris). Au-delà de la longueur maximale, la désignation est tronquée et un avertissement est affiché.", 'printgestion')
            . "</p>";
        echo "<div class='row g-3'>";
        echo "<div class='col-md-4'>"
            . $label_with_tip(
                __('Séparateur de la désignation', 'printgestion'),
                __("Espaces compris. Le fichier réel importé dans Gesconso utilise « # » entouré d'un espace de chaque côté.", 'printgestion')
            );
        echo "<input type='text' class='form-control' name='gesconso_separator' maxlength='20' value='"
            . htmlspecialchars(PluginPrintgestionGesconso::getSeparator(), ENT_QUOTES, 'UTF-8') . "'></div>";
        echo "<div class='col-md-4'>"
            . $label_with_tip(
                __('Longueur maximale de la désignation', 'printgestion'),
                __("69 par défaut (limite usuelle de Sage ; 67 caractères ont été importés avec succès).", 'printgestion')
            );
        echo "<input type='number' min='10' max='255' class='form-control' name='gesconso_designation_max' value='"
            . PluginPrintgestionGesconso::getDesignationMax() . "'></div>";
        echo "</div></div></div>";

        // ── Lecture des niveaux SNMP : règles par constructeur ────
        PluginPrintgestionSnmprule::showConfigCard();

        // ── Notifications natives des demandes d'envoi ───────────
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . __('Notifications natives des demandes d\'envoi', 'printgestion') . "</h3></div><div class='card-body'>";
        echo "<p class='text-muted small mb-3'>"
            . __("Événements GLPI « Demande d'envoi proposée », « en attente (relance) » et « exportée vers les Achats » (Configuration → Notifications, type Demande d'envoi) : créés inactifs, à activer après avoir choisi les destinataires (profil ou groupe des valideurs, Achats…).", 'printgestion')
            . " <a href='" . htmlspecialchars(Notification::getSearchURL(), ENT_QUOTES, 'UTF-8') . "'>" . __('Ouvrir les notifications', 'printgestion') . "</a></p>";
        echo "<div class='row g-3'><div class='col-md-4'>"
            . $label_with_tip(
                __('Relance d\'une demande en attente (jours)', 'printgestion'),
                __("Une demande proposée non validée, ou validée non exportée, depuis ce nombre de jours déclenche l'événement de relance, au plus une fois par période. 0 = relance désactivée.", 'printgestion')
            );
        echo "<input type='number' min='0' max='90' class='form-control' name='demande_reminder_days' value='"
            . (int)($config->fields['demande_reminder_days'] ?? 2) . "'></div></div>";
        echo "</div></div>";

        // Active les tooltips Bootstrap sur les icônes d'info
        echo "<script>
(function() {
  if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
    document.querySelectorAll('[data-bs-toggle=\"tooltip\"]').forEach(function(el) {
      new bootstrap.Tooltip(el);
    });
  }
})();
</script>";


        // ── Card : Rôles & notifications ──────────────────────────
        // Les mails du plugin sont des notifications natives (Configuration → Notifications) : gabarit, activation
        // et destinataires s'y règlent. Les trois rôles sont proposés là-bas comme destinataires
        // « Rôle … (Print Gestion) » ; c'est ici qu'on dit qui les compose.
        $roles = [
            'planif'     => __('Planification (expédition cartouche)', 'printgestion'),
            'achat'      => __('Achats (commande de cartouches)', 'printgestion'),
            'commercial' => __('Commercial (information client)', 'printgestion'),
        ];

        echo "<div class='card mb-3'><div class='card-header d-flex justify-content-between align-items-center'>"
            . "<h3 class='card-title mb-0'>" . __('Rôles & notifications', 'printgestion') . "</h3>"
            . "<a class='btn btn-sm btn-outline-primary' href='" . htmlspecialchars(Notification::getSearchURL(), ENT_QUOTES, 'UTF-8') . "'>"
            . "<i class='ti ti-bell me-1'></i>" . __('Ouvrir les notifications', 'printgestion') . "</a>"
            . "</div><div class='card-body'>";

        echo "<div class='alert alert-info py-2 mb-3'>"
            . "<i class='fa-solid fa-circle-info me-1'></i>"
            . __("Les mails du plugin sont des notifications natives de GLPI : gabarit, activation et destinataires se règlent dans Configuration → Notifications, où les trois rôles ci-dessous sont proposés comme destinataires « Rôle … (Print Gestion) ». L'accès aux dashboards reste réglé par les droits de profil (Administration → Profils → Print Gestion).", 'printgestion')
            . "</div>";

        // 3 rôles : qui les compose (groupe GLPI ou utilisateurs directs), adresses résolues
        foreach ($roles as $role => $label) {
            $mode        = (string)($config->fields['mode_' . $role] ?? 'group');
            $group_value = (int)($config->fields['group_' . $role] ?? 0);
            // NB: la colonne emails_X stocke une liste CSV d'IDs users GLPI
            $users_raw   = (string)($config->fields['emails_' . $role] ?? '');
            $users_ids   = array_values(array_filter(
                array_map('intval', preg_split('/[,;\s]+/', $users_raw) ?: []),
                fn($id) => $id > 0
            ));

            $use_users = ($mode === 'emails'); // 'emails' = legacy name, signifie "users directs"
            $group_div = "printgestion-{$role}-group";
            $users_div = "printgestion-{$role}-users";
            $switch_id = "printgestion-{$role}-switch";
            $resolved  = PluginPrintgestionAlert::resolveRecipientsForRole($role);

            echo "<div class='mb-4 pb-3 border-bottom'>";
            echo "<h5 class='mb-2'>" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</h5>";

            // Destinataires : label + switch + panneau dynamique
            echo "<div class='row align-items-start'><div class='col-md-4'><label class='form-label mb-0'>"
                . __('Destinataires', 'printgestion') . "</label></div><div class='col-md-8'>";

            echo "<div class='form-check form-switch mb-2'>";
            echo "<input type='hidden' name='mode_{$role}' value='" . ($use_users ? 'emails' : 'group') . "' id='mode_{$role}_hidden'>";
            echo "<input class='form-check-input' type='checkbox' role='switch' id='{$switch_id}'"
                . ($use_users ? ' checked' : '')
                . " onchange=\"printgestionToggleMode('{$role}', this.checked)\">";
            echo "<label class='form-check-label' for='{$switch_id}'>"
                . __('Utiliser des utilisateurs directs (sinon : groupe GLPI)', 'printgestion') . "</label>";
            echo "</div>";

            // Panneau : groupe GLPI
            echo "<div id='{$group_div}' style='display:" . ($use_users ? 'none' : 'block') . "'>";
            Group::dropdown([
                'name'                => 'group_' . $role,
                'value'               => $group_value,
                'display_emptychoice' => true,
                'emptylabel'          => '-----',
            ]);
            echo "<small class='text-muted d-block mt-1'>"
                . __('Les emails par défaut de tous les utilisateurs du groupe seront utilisés.', 'printgestion')
                . "</small>";
            echo "</div>";

            // Panneau : users GLPI directs (User::dropdown multiple — select2 natif)
            // NB: en mode multiple, User::dropdown attend le tableau d'IDs dans `value`
            // (singulier), pas `values` — écrase `values` avec `value ?? []` ligne 4261.
            echo "<div id='{$users_div}' style='display:" . ($use_users ? 'block' : 'none') . "'>";
            User::dropdown([
                'name'                => 'emails_' . $role,
                'value'               => $users_ids,
                'multiple'            => true,
                'right'               => 'all',
                'display_emptychoice' => false,
                'width'               => '100%',
            ]);
            echo "<small class='text-muted d-block mt-1'>"
                . __('Choisir un ou plusieurs utilisateurs GLPI. Leur email par défaut sera utilisé.', 'printgestion')
                . "</small>";
            echo "</div>";

            // Adresses résolues avec la configuration ENREGISTRÉE (enregistrer avant de vérifier).
            echo "<small class='d-block mt-2'>" . __('Adresses résolues :', 'printgestion') . ' '
                . (empty($resolved)
                    ? "<span class='text-danger'>" . __('aucune', 'printgestion') . "</span>"
                    : "<span class='text-success'>" . htmlspecialchars(implode(', ', $resolved), ENT_QUOTES, 'UTF-8') . "</span>")
                . "</small>";

            echo "</div></div>"; // row
            echo "</div>"; // rôle
        }

        // Les circuits : la notification de chacun, son état, son gabarit, ses destinataires — lus dans GLPI.
        echo "<h5 class='mb-2'>" . __('Notifications du plugin', 'printgestion') . "</h5>";
        echo "<p class='text-muted small'>" . __('Chaque envoi du plugin est une notification native : cliquer sur son nom pour changer le gabarit, les destinataires ou l\'activer. Le fichier Gesconso joint aux commandes est le document archivé de la commande.', 'printgestion') . "</p>";
        $entries = [];
        foreach (PluginPrintgestionNotify::describeCircuits() as $circuit) {
            $n         = $circuit['notification'];
            $entries[] = [
                'circuit'      => $circuit['label'],
                'notification' => $n === null
                    ? "<span class='text-danger'>" . __('absente : relancer « Mettre à jour » du plugin', 'printgestion') . "</span>"
                    : "<a href='" . htmlspecialchars(Notification::getFormURLWithID((int) $n['id']), ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars((string) $n['name'], ENT_QUOTES, 'UTF-8') . "</a>",
                'state'        => $n === null ? '' : ((int) $n['is_active'] === 1
                    ? "<span class='badge bg-green text-green-fg'>" . __('Active', 'printgestion') . "</span>"
                    : "<span class='badge bg-secondary text-secondary-fg'>" . __('Inactive', 'printgestion') . "</span>"),
                'template'     => $n === null || empty($n['templates_id'])
                    ? '—'
                    : "<a href='" . htmlspecialchars(NotificationTemplate::getFormURLWithID((int) $n['templates_id']), ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars((string) $n['template'], ENT_QUOTES, 'UTF-8') . "</a>",
                'targets'      => $circuit['targets'] === []
                    ? "<span class='text-danger'>" . __('aucun destinataire', 'printgestion') . "</span>"
                    : htmlspecialchars(implode(', ', $circuit['targets']), ENT_QUOTES, 'UTF-8'),
            ];
        }
        echo "<div data-pg-noclick='1'>" . PluginPrintgestionUi::datatable([
            'circuit'      => __('Circuit', 'printgestion'),
            'notification' => __('Notification', 'printgestion'),
            'state'        => __('État', 'printgestion'),
            'template'     => __('Gabarit', 'printgestion'),
            'targets'      => __('Destinataires', 'printgestion'),
        ], $entries, ['notification' => 'raw_html', 'state' => 'raw_html', 'template' => 'raw_html', 'targets' => 'raw_html']) . "</div>";

        echo "</div></div>"; // fin card Rôles & notifications

        // ── Card : Mapping SNMP → Cartouches GLPI ─────────────────

        // ── JS : toggle switch entre panneau groupe / panneau users ─
        echo <<<'HTML'
<script>
function printgestionToggleMode(role, useUsers) {
    var g = document.getElementById('printgestion-' + role + '-group');
    var u = document.getElementById('printgestion-' + role + '-users');
    var h = document.getElementById('mode_' + role + '_hidden');
    if (g) g.style.display = useUsers ? 'none' : 'block';
    if (u) u.style.display = useUsers ? 'block' : 'none';
    if (h) h.value = useUsers ? 'emails' : 'group';
}
</script>
HTML;

        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        // ── Liaison avec le plugin Gestion (un BL signé vaut preuve de livraison) ──
        // Carte absente d'un GLPI qui n'a pas le plugin Gestion : un réglage qui ne peut rien régler n'a pas à
        // occuper l'écran. Plugin présent mais désactivé : la carte le dit, et l'interrupteur reste réglable.
        if (PluginPrintgestionTracking::isGestionPresent()) {
            $link_on     = PluginPrintgestionTracking::isGestionLinkEnabled();
            $link_usable = PluginPrintgestionTracking::isGestionUsable();
            echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
                . $esc(__('Liaison avec le plugin Gestion', 'printgestion')) . "</h3></div><div class='card-body'>";
            echo "<p class='text-muted small mb-3'>"
                . $esc(__('Un BL signé dans le plugin Gestion vaut preuve de livraison : l\'expédition qui porte ce BL passe « livrée » à la seconde où il est signé. C\'est aussi cette liaison qui permet d\'associer des BL à une expédition. Le passage en « livrée » ne clôt pas l\'envoi : seule la pose de la cartouche, vue par un relevé, le clôt.', 'printgestion'))
                . "</p>";
            // Le témoin caché distingue « décochée » de « carte absente » : sans lui, un écran sans cette carte
            // (plugin Gestion retiré) couperait la liaison au premier enregistrement.
            echo "<div class='form-check form-switch mb-2'>";
            echo "<input type='hidden' name='gestion_link_posted' value='1'>";
            echo "<input type='hidden' name='enable_gestion_link' value='0'>";
            echo "<input type='checkbox' class='form-check-input' id='enable_gestion_link' name='enable_gestion_link' value='1'"
                . ($link_on ? ' checked' : '') . ">";
            echo "<label class='form-check-label' for='enable_gestion_link'>" . $esc(__('Activée', 'printgestion')) . "</label>";
            echo "</div>";
            // L'état réel sous l'interrupteur : ce que la position de l'interrupteur ne peut pas dire à elle seule.
            if (!$link_usable) {
                echo "<p class='text-warning mb-0'><i class='ti ti-alert-triangle me-1'></i>"
                    . $esc(__('Le plugin Gestion est là mais inutilisable en l\'état (plugin désactivé, ou table des BL absente) : rien ne remontera tant qu\'il n\'est pas actif, interrupteur sur « activée » ou non.', 'printgestion')) . "</p>";
            } elseif (!$link_on) {
                echo "<p class='text-muted mb-0'>"
                    . $esc(__('Coupée : aucun BL signé ne fera passer une expédition en « livrée », et l\'association de BL à une expédition est refusée. Les expéditions déjà livrées le restent.', 'printgestion')) . "</p>";
            } else {
                echo "<p class='text-muted mb-0'>"
                    . $esc(__('Active : le plugin Gestion est actif et sa table des BL est là. Le passage en « livrée » part à la signature, sans attendre ; la tâche automatique de Print Gestion rattrape ensuite ce qu\'aucun clic n\'a déclenché (BL importé déjà signé, par exemple).', 'printgestion')) . "</p>";
            }
            echo "</div></div>";
        }

        // ── Proposition automatique des demandes d'envoi ──
        // Un choix de fonctionnement, pas une correction : sa place est ici, avec un interrupteur, et non dans la
        // carte de santé qui ne montre que ce qui est cassé. Absente si le module Toner est coupé (l'automatisation
        // n'aurait rien à proposer) ou si la tâche n'est pas enregistrée.
        $propose = self::isFeatureEnabled('toner') ? PluginPrintgestionConfighealth::getProposeTask() : null;
        if ($propose !== null) {
            $propose_on = (int) $propose['state'] !== CronTask::STATE_DISABLE;
            echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
                . $esc(__('Proposition automatique des demandes d\'envoi', 'printgestion')) . "</h3></div><div class='card-body'>";
            echo "<p class='text-muted small mb-3'>"
                . $esc(__('Coupée, rien ne change à ce qui existe : les commandes se font depuis l\'écran Alertes, en cochant des toners puis « Actions → Commander ». Activée, le plugin fait ce travail tout seul chaque heure — une demande par client et par site de livraison, une ligne par cartouche en alerte, au statut « Proposée ». Il ne commande rien et n\'envoie rien : il remplit l\'écran des demandes, que quelqu\'un valide puis exporte aux Achats.', 'printgestion'))
                . "</p>";
            echo "<p class='text-muted small mb-3'>"
                . $esc(__('Ce qu\'il faut savoir avant d\'activer : une ligne proposée bloque la commande de sa cartouche depuis l\'écran Alertes jusqu\'à son export ou son annulation. Le travail se déplace donc des Alertes vers les Demandes — à n\'activer qu\'une fois le flux d\'export en service et suivi, sinon les commandes se bloquent sans que personne les débloque.', 'printgestion'))
                . "</p>";
            // Le témoin caché distingue « décochée » de « carte absente » (module Toner coupé, tâche absente).
            echo "<div class='form-check form-switch mb-2'>";
            echo "<input type='hidden' name='propose_posted' value='1'>";
            echo "<input type='hidden' name='enable_propose' value='0'>";
            echo "<input type='checkbox' class='form-check-input' id='enable_propose' name='enable_propose' value='1'"
                . ($propose_on ? ' checked' : '') . ">";
            echo "<label class='form-check-label' for='enable_propose'>" . $esc(__('Activée', 'printgestion')) . "</label>";
            echo "</div>";
            if ($propose_on) {
                echo "<p class='text-muted mb-0'>"
                    . $esc(__('La couper arrête les nouvelles propositions, mais n\'efface pas celles déjà faites : elles gardent leur verrou jusqu\'à leur export ou leur annulation.', 'printgestion')) . "</p>";
            } else {
                echo "<p class='text-muted mb-0'>"
                    . $esc(__('Coupée : aucune demande n\'est proposée toute seule. Les demandes déjà proposées, s\'il en reste, gardent leur verrou jusqu\'à leur export ou leur annulation.', 'printgestion')) . "</p>";
            }
            echo "</div></div>";
        }

        $section('transport', __('Suivi des colis par les transporteurs : rien n\'est obligatoire, chaque service se teste ici.', 'printgestion'));

        // ── Suivi GLS (identifiant client et secret, rien d'autre : les URL sont des constantes du code) ──
        $gls_id     = (string) ($config->fields['gls_client_id'] ?? '');
        $gls_set    = (string) ($config->fields['gls_client_secret'] ?? '') !== '';
        $gls_date   = (string) ($config->fields['gls_secret_date'] ?? '');
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . $esc(__('Suivi GLS', 'printgestion')) . "</h3></div><div class='card-body'>";
        echo "<p class='text-muted small mb-3'>"
            . $esc(__('Identifiant client et secret de l\'API GLS (Piste et Trace). Des clés saisies et un dernier appel réussi : le suivi est actif, sans interrupteur. Sans clés, les expéditions GLS s\'affichent comme aujourd\'hui, transporteur et numéro saisis à la main. Le secret est enregistré chiffré et jamais réaffiché.', 'printgestion'))
            . "</p>";
        echo "<div class='row mb-2 align-items-center'><div class='col-md-4'>" . $esc(__('Client ID', 'printgestion')) . "</div><div class='col-md-5'>"
            . "<input type='text' class='form-control' name='gls_client_id' maxlength='255' autocomplete='off' value='" . $esc($gls_id) . "'></div></div>";
        echo "<div class='row mb-2 align-items-center'><div class='col-md-4'>" . $esc(__('Client Secret', 'printgestion')) . "</div><div class='col-md-5'>";
        if ($gls_set) {
            // Jamais réaffiché, même partiellement : un état et un bouton « Remplacer » qui dévoile le champ de saisie.
            echo "<div class='d-flex align-items-center gap-2' id='pg-gls-secret-set'><code>••••••••</code> <span class='text-muted small'>"
                . $esc($gls_date !== '' ? sprintf(__('défini le %s', 'printgestion'), Html::convDate($gls_date)) : __('défini', 'printgestion')) . "</span>"
                . "<button type='button' class='btn btn-sm btn-outline-secondary' onclick=\"document.getElementById('pg-gls-secret-set').classList.add('d-none'); document.getElementById('pg-gls-secret-input').classList.remove('d-none');\">"
                . $esc(__('Remplacer', 'printgestion')) . "</button></div>";
        }
        echo "<input type='password' class='form-control" . ($gls_set ? " d-none" : '') . "' id='pg-gls-secret-input' name='gls_client_secret' value='' autocomplete='new-password' placeholder='"
            . $esc($gls_set ? __('Nouveau secret : saisir pour remplacer', 'printgestion') : __('Aucun secret enregistré', 'printgestion')) . "'>";
        echo "</div></div>";
        if ($gls_set || $gls_id !== '') {
            echo "<button type='submit' name='clear_gls' value='1' class='btn btn-sm btn-outline-danger' formnovalidate onclick=\"return confirm(" . $esc(json_encode(__('Retirer l\'identifiant et le secret GLS ? Les suivis déjà collectés restent en place.', 'printgestion'))) . ");\">"
                . "<i class='ti ti-trash me-1'></i>" . $esc(__('Retirer les clés', 'printgestion')) . "</button>";
        }
        // Demande un jeton et le jette : « connexion établie » ou l'erreur, jamais le jeton.
        $manque = [];
        if ($gls_id === '') {
            $manque[] = __('le Client ID', 'printgestion');
        }
        if (!$gls_set) {
            $manque[] = __('le Client Secret', 'printgestion');
        }
        self::showConnectionTest('test_gls', $manque);
        echo "</div></div>";

        // ── MBE, intermédiaire de transport (identifiant et passphrase, rien d'autre : adresse et système sont des constantes) ──
        $mbe_user = PluginPrintgestionMbeclient::getUsername();
        $mbe_set  = (string) ($config->fields['mbe_passphrase'] ?? '') !== '';
        $mbe_date = (string) ($config->fields['mbe_secret_date'] ?? '');
        $mbe_max  = PluginPrintgestionMbeclient::MAX_CREDENTIAL_LENGTH;
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . $esc(__('MBE (intermédiaire de transport)', 'printgestion')) . "</h3></div><div class='card-body'>";
        echo "<p class='text-muted small mb-3'>"
            . $esc(__('Identifiant et passphrase de l\'API MBE France (e-link, SOAP). MBE n\'est pas un transporteur : c\'est l\'intermédiaire par lequel les cartouches du stock partent chez le client, confiées à GLS ou à UPS ; le transporteur et son numéro se saisissent comme aujourd\'hui. Avec des identifiants, Print Gestion retrouve chez MBE l\'expédition qui porte le numéro de BL de la commande (ou son numéro transporteur) et lit son statut de livraison : une expédition remise passe « livrée ». Deux passages par jour, 500 appels par jour au plus. MBE seul ne suffit pas et le plugin ne s\'y fie jamais seul : son statut reste « en attente de livraison » plusieurs jours après une remise, donc il est toujours recoupé avec ce que GLS a publié. Sans identifiants, rien n\'est appelé et rien ne change. La passphrase API n\'est pas forcément le mot de passe de la console MBE.', 'printgestion'))
            . "</p>";
        echo "<div class='row mb-2 align-items-center'><div class='col-md-4'>" . $esc(__('Adresse et système', 'printgestion')) . "</div><div class='col-md-5'>"
            . "<code>" . $esc(PluginPrintgestionMbeclient::ENDPOINT) . "</code> <span class='text-muted small'>"
            . $esc(sprintf(__('SOAP 1.1, système %s : fixés dans le code, pas un réglage.', 'printgestion'), PluginPrintgestionMbeclient::SYSTEM)) . "</span></div></div>";
        echo "<div class='row mb-2 align-items-center'><div class='col-md-4'>" . $esc(__('Identifiant API', 'printgestion')) . "</div><div class='col-md-5'>"
            . "<input type='text' class='form-control' name='mbe_username' maxlength='" . $mbe_max . "' autocomplete='off' value='" . $esc($mbe_user) . "'></div></div>";
        echo "<div class='row mb-2 align-items-center'><div class='col-md-4'>" . $esc(__('Passphrase API', 'printgestion')) . "</div><div class='col-md-5'>";
        if ($mbe_set) {
            // Jamais réaffichée, même partiellement : un état et un bouton « Remplacer » qui dévoile le champ de saisie.
            echo "<div class='d-flex align-items-center gap-2' id='pg-mbe-secret-set'><code>••••••••</code> <span class='text-muted small'>"
                . $esc($mbe_date !== '' ? sprintf(__('définie le %s', 'printgestion'), Html::convDate($mbe_date)) : __('définie', 'printgestion')) . "</span>"
                . "<button type='button' class='btn btn-sm btn-outline-secondary' onclick=\"document.getElementById('pg-mbe-secret-set').classList.add('d-none'); document.getElementById('pg-mbe-secret-input').classList.remove('d-none');\">"
                . $esc(__('Remplacer', 'printgestion')) . "</button></div>";
        }
        echo "<input type='password' class='form-control" . ($mbe_set ? " d-none" : '') . "' id='pg-mbe-secret-input' name='mbe_passphrase' value='' maxlength='" . $mbe_max . "' autocomplete='new-password' placeholder='"
            . $esc($mbe_set ? __('Nouvelle passphrase : saisir pour remplacer', 'printgestion') : __('Aucune passphrase enregistrée', 'printgestion')) . "'>";
        echo "</div></div>";
        if ($mbe_set || $mbe_user !== '') {
            echo "<button type='submit' name='clear_mbe' value='1' class='btn btn-sm btn-outline-danger' formnovalidate onclick=\"return confirm(" . $esc(json_encode(__('Retirer l\'identifiant et la passphrase MBE ? Plus aucun appel MBE ensuite.', 'printgestion'))) . ");\">"
                . "<i class='ti ti-trash me-1'></i>" . $esc(__('Retirer les identifiants', 'printgestion')) . "</button>";
        }
        // Lit la liste des sept derniers jours, page 1, et la jette : « connexion établie » ou l'erreur, jamais un
        // identifiant. C'est aussi le seul contrôle qui prouve que la lecture du XML de MBE fonctionne pour de vrai.
        $manque = [];
        if ($mbe_user === '') {
            $manque[] = __('l\'identifiant API', 'printgestion');
        }
        if (!$mbe_set) {
            $manque[] = __('la passphrase API', 'printgestion');
        }
        self::showConnectionTest('test_mbe', $manque);
        echo "</div></div>";

        echo "</div></div>";


        $section('collecte', __('Ce qui juge la remontée des imprimantes. Les réglages des sondes elles-mêmes — version des agents, mise à jour automatique, statut des PC — sont sur la page « Installeur GLPI Agent » du module.', 'printgestion'));
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $escape(__('Collecte SNMP', 'printgestion')) . "</h3></div><div class='card-body'>";
        echo "<div class='row g-3'>";
        echo "<div class='col-md-3'>"
            . $label_with_tip(
                __('Imprimante muette après (jours)', 'printgestion'),
                __("Sans inventaire depuis ce nombre de jours, une imprimante (ou l'agent qui l'inventorie) est signalée muette dans l'onglet « Imprimantes collectées » : elle ne peut plus déclencher d'alerte toner.", 'printgestion')
            );
        echo "<input type='number' min='1' max='365' class='form-control' name='silent_days' value='"
            . PluginPrintgestionCollect::getSilentDays() . "'>";
        // Réglage natif voisin, affiché et jamais redéfini : GLPI nettoie (supprime) un agent sans contact après ce délai.
        // L'alerte « sonde muette » doit arriver avant : seuil du plugin plus court que le délai natif.
        $stale_days = (int) Config::getConfigurationValue('inventory', 'stale_agents_delay');
        $inventory_url = $CFG_GLPI['root_doc'] . '/front/inventory.conf.php';
        echo "<div class='form-hint'>" . ($stale_days > 0
            ? sprintf(htmlspecialchars(__('GLPI nettoie un agent sans contact après %1$d jours (%2$s). Ce seuil doit rester plus court.', 'printgestion'), ENT_QUOTES, 'UTF-8'),
                $stale_days, "<a href='" . htmlspecialchars($inventory_url, ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars(__('Administration → Inventaire, nettoyage des agents', 'printgestion'), ENT_QUOTES, 'UTF-8') . "</a>")
            : sprintf(htmlspecialchars(__('GLPI ne nettoie pas les agents sans contact (%s).', 'printgestion'), ENT_QUOTES, 'UTF-8'),
                "<a href='" . htmlspecialchars($inventory_url, ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars(__('Administration → Inventaire, nettoyage des agents', 'printgestion'), ENT_QUOTES, 'UTF-8') . "</a>")) . "</div>";
        if ($stale_days > 0 && PluginPrintgestionCollect::getSilentDays() >= $stale_days) {
            echo "<div class='alert alert-warning mt-2 mb-0'>" . htmlspecialchars(sprintf(
                __('Seuil de %1$d jours ≥ délai de nettoyage de GLPI (%2$d jours) : l\'alerte « sonde muette » arriverait après que GLPI a supprimé l\'agent — et une sonde éteinte pendant des congés serait effacée avant d\'être signalée. Baisser ce seuil, ou allonger le délai dans GLPI.', 'printgestion'),
                PluginPrintgestionCollect::getSilentDays(),
                $stale_days
            ), ENT_QUOTES, 'UTF-8') . "</div>";
        }
        echo "</div>";

        echo "</div>";
        echo "<p class='text-muted small mt-3 mb-0'><i class='ti ti-arrow-right me-1'></i><a href='" . $escape(PLUGIN_PRINTGESTION_WEBDIR . '/front/agentdeploy.php') . "'>"
            . $escape(__('Réglages des sondes : page « Installeur GLPI Agent »', 'printgestion')) . "</a></p>";
        echo "</div></div>";

        $section('maintenance', __('Lignes sans rattachement et correspondances de secours : à regarder quand quelque chose ne colle pas, pas au quotidien.', 'printgestion'));
        self::showOrphansCard();
        self::showSnmpMappingCard();
        // Une seule fenêtre pour les deux tests de connexion, rendue en fin de formulaire.
        self::showTestModal();

        $html = (string) ob_get_clean();
        if (!$canedit) {
            // Lecture seule réelle : champs, listes, zones de texte et boutons d'envoi désactivés ; les boutons
            // « type=button » (chevrons, fenêtres d'information) restent utilisables.
            $html = (string) preg_replace('/<(input|select|textarea)\b(?![^>]*\bdisabled\b)/i', '<$1 disabled', $html);
            $html = (string) preg_replace('/<button\b(?![^>]*type=[\'"]button[\'"])(?![^>]*\bdisabled\b)/i', '<button disabled', $html);
        }
        echo $html;

        if ($canedit) {
            // Rouvrir la table/tr/td attendue par showFormButtons avant de fermer
            echo '<table><tr><td>';
            // « Enregistrer » part avec formnovalidate, et ce n'est pas un contournement de confort.
            //
            // GLPI pose `data-submit-once` sur le formulaire, et son gestionnaire (public/js/common.js) annule
            // l'envoi dès que `form.checkValidity()` est faux — sauf si le bouton cliqué porte `formnovalidate`.
            // Or `validateFormWithBootstrap()` n'affiche quelque chose que si le formulaire porte la classe
            // « needs-validation », que celui-ci n'a pas : un seul champ invalide annule donc l'envoi SANS message,
            // sans requête et sans rien dans les journaux. Un contrôle qui ne sait pas dire ce qu'il reproche coûte
            // plus qu'il ne rapporte : tous les boutons d'action du plugin portent déjà formnovalidate pour cette
            // raison, « Enregistrer » était le seul à ne pas l'avoir.
            //
            // Rien n'est perdu : front/config.form.php borne chaque valeur à l'enregistrement (max(), min(), listes
            // fermées), et c'est lui qui fait foi — le navigateur ne protège pas la base.
            // Deux attributs ajoutés au bouton de GLPI : form= et formnovalidate.
            //
            // form= le rattache au formulaire par son identifiant, quel que soit l'endroit où l'analyseur HTML l'a
            // finalement placé dans l'arbre. Un bouton d'envoi qui se retrouve hors du formulaire ne fait rien du
            // tout au clic — sans message, sans requête, sans trace. C'est le comportement observé.
            ob_start();
            $config->showFormButtons(['candel' => false, 'canedit' => $canedit]);
            echo (string) preg_replace(
                '/<button\b(?=[^>]*\bname="update")(?![^>]*\bformnovalidate\b)/i',
                '$0 formnovalidate form="' . self::FORM_ID . '"',
                (string) ob_get_clean()
            );
        } else {
            // Pas de bouton Sauvegarder : le formulaire est fermé tel quel.
            Html::closeForm();
        }
        return true;
    }

    /**
     * Bouton « Tester la connexion » d'une intégration, **toujours affiché et toujours actif**.
     *
     * Toujours affiché : un bouton absent tant que les clés ne sont pas enregistrées laisse croire qu'il n'y a rien à
     * tester, et on cherche longtemps. Toujours actif : un bouton désactivé est délavé par le thème au point de
     * disparaître sur fond blanc — cliqué sans clés, celui-ci répond « non saisis », ce qui se lit et ne dépend
     * d'aucun style.
     *
     * Une ligne dit ce qui manque encore, et pourquoi saisir ne suffit pas : le test appelle l'API avec ce qui est
     * **enregistré**, jamais avec ce qui est affiché à l'écran.
     *
     * Pas de `data-pg-submit-once` ici, et c'est important : cet attribut fait que le formulaire QUI LE CONTIENT ne
     * part qu'une fois et que ses boutons d'envoi reçoivent la classe `disabled` — sur laquelle Bootstrap coupe les
     * clics. Tant que ce bouton n'existait qu'avec des clés enregistrées, le grand formulaire de configuration n'était
     * pas concerné ; permanent, il y soumettrait « Enregistrer » tout entier. Un test de connexion est un appel court,
     * il n'a pas besoin de ce garde-fou — le formulaire de configuration, lui, a besoin de partir à chaque clic.
     *
     * @param string   $action nom du bouton posté (test_gls, test_mbe)
     * @param string[] $manque ce qui n'est pas encore enregistré, déjà rédigé (« le Client ID », « le secret »…)
     */
    protected static function showConnectionTest(string $action, array $manque): void {
        global $CFG_GLPI;

        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        // Reste un bouton d'envoi : sans JS (ou sans Bootstrap), le formulaire part et config.form.php rend le
        // résultat en message, comme avant. `data-pg-test` dit au JS qu'il peut faire mieux — appeler en AJAX et
        // afficher la réponse dans une fenêtre, sans recharger la page ni perdre la saisie en cours.
        //
        // L'adresse et les deux phrases d'attente et d'échec sont posées ici : le JS ne fabrique aucun texte
        // affiché, il n'a pas accès aux traductions.
        echo "<button type='submit' name='" . $esc($action) . "' value='1' data-pg-test='" . $esc($action) . "'"
            . " data-pg-test-url='" . $esc($CFG_GLPI['root_doc'] . '/plugins/printgestion/ajax/test_connection.php') . "'"
            . " data-pg-test-wait='" . $esc(__('Appel en cours…', 'printgestion')) . "'"
            . " data-pg-test-error='" . $esc(__('Le test n\'a pas abouti : GLPI n\'a pas répondu. Réessayer, ou recharger la page.', 'printgestion')) . "'"
            . " data-pg-test-http='" . $esc(__('Réponse inattendue de GLPI (ce n\'est pas le transporteur qui a répondu)', 'printgestion')) . "'"
            . " class='btn btn-sm btn-outline-primary ms-1' formnovalidate><i class='ti ti-plug-connected me-1'></i>"
            . $esc(__('Tester la connexion', 'printgestion')) . "</button>";
        if (!empty($manque)) {
            $liste = count($manque) > 1
                ? implode(', ', array_slice($manque, 0, -1)) . ' ' . __('et', 'printgestion') . ' ' . end($manque)
                : $manque[0];
            echo "<div class='text-muted small mt-2'><i class='ti ti-info-circle me-1'></i>" . $esc(sprintf(
                __('Encore à enregistrer : %s. Le test appelle l\'API avec ce qui est en base, jamais avec ce qui est saisi à l\'écran.', 'printgestion'),
                $liste
            )) . "</div>";
        }
    }

    /**
     * Fenêtre unique où s'affiche le résultat d'un test de connexion. Rendue une seule fois pour les deux
     * intégrations : c'est le JS qui la remplit et l'ouvre, le titre et le corps changent selon le test.
     */
    protected static function showTestModal(): void {
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        echo "<div class='modal fade' id='pg-test-modal' tabindex='-1' aria-hidden='true'>"
            . "<div class='modal-dialog modal-dialog-centered'><div class='modal-content'>"
            . "<div class='modal-header'><h5 class='modal-title' id='pg-test-modal-title'>"
            . $esc(__('Test de connexion', 'printgestion')) . "</h5>"
            . "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='" . $esc(__('Fermer', 'printgestion')) . "'></button></div>"
            . "<div class='modal-body' id='pg-test-modal-body'></div>"
            . "<div class='modal-footer'><button type='button' class='btn btn-outline-secondary' data-bs-dismiss='modal'>"
            . $esc(__('Fermer', 'printgestion')) . "</button></div>"
            . "</div></div></div>";
    }

    /**
     * Lignes orphelines (objet de rattachement purgé, entité indéterminée) : restées à l'entité racine, non
     * récursives, donc invisibles des comptes clients. Listées ici pour qu'un administrateur tranche ; le
     * plugin ne les supprime ni ne les rattache d'office. Affiché aux seuls comptes qui voient la racine.
     */
    protected static function showOrphansCard(): void {
        if (!Session::haveAccessToEntity(0)) {
            return;
        }
        $orphans = PluginPrintgestionEntityscope::findOrphans();
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . __('Lignes sans objet de rattachement', 'printgestion') . "</h3></div><div class='card-body'>";
        if (empty($orphans)) {
            echo "<p class='text-muted mb-0'>" . __('Aucune : toutes les lignes ont une entité déterminée.', 'printgestion') . "</p>";
        } else {
            echo "<p class='text-muted small'>"
                . __('Imprimante, expédition, demande ou contrat purgé et entité impossible à retrouver : ces lignes restent à l\'entité racine, invisibles des comptes clients. À trancher par un administrateur (voir la documentation de maintenance, « Lignes sans objet de rattachement »).', 'printgestion')
                . "</p>";
            echo "<table class='table table-sm mb-0'><thead><tr><th>" . __('Table', 'printgestion') . "</th><th>"
                . __('Lignes', 'printgestion') . "</th><th>" . __('Identifiants', 'printgestion') . "</th></tr></thead><tbody>";
            foreach ($orphans as $table => $orphan) {
                echo '<tr><td>' . htmlspecialchars($table, ENT_QUOTES, 'UTF-8') . '</td><td>' . (int) $orphan['count'] . '</td><td>'
                    . htmlspecialchars('#' . implode(', #', $orphan['ids']) . ($orphan['count'] > count($orphan['ids']) ? '…' : ''), ENT_QUOTES, 'UTF-8')
                    . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo "</div></div>";
    }

    /**
     * Carte éditable "Mapping SNMP → Cartouches GLPI" — édition inline.
     *
     * Une ligne par propriété SNMP unique (pas de doublon constructeur).
     * Chaque ligne est un mini-formulaire avec dropdown type + couleur + Save + Delete.
     * Ligne d'ajout en bas du tableau.
     */
    protected static function showSnmpMappingCard(): void {
        global $DB;

        echo "<div class='card mb-3'>";
        echo "<div class='card-header'><h3 class='card-title mb-0'>"
            . __('Mapping SNMP → Cartouches GLPI (fallback)', 'printgestion') . "</h3></div>";
        echo "<div class='card-body'>";

        // Bandeau info TOUJOURS visible
        echo "<div class='alert alert-info py-2 mb-2'>"
            . "<i class='fa-solid fa-lightbulb me-1'></i>"
            . __("<strong>Méthode recommandée</strong> : associe directement les propriétés SNMP "
                . "depuis la fiche de chaque cartouche (onglet <strong>Print Gestion</strong> après avoir "
                . "déclaré les modèles d'imprimantes compatibles). Plus simple et sans créer de types. "
                . "Cette section ici reste utile uniquement comme fallback basé sur les types GLPI.", 'printgestion')
            . "</div>";

        // Bouton toggle : flèche qui replie/déploie le tableau en dessous
        echo "<div class='text-center mb-2'>";
        echo "<button type='button' class='btn btn-sm btn-outline-secondary' "
            . "data-bs-toggle='collapse' data-bs-target='#printgestion-mapping-collapse' "
            . "aria-expanded='false' aria-controls='printgestion-mapping-collapse' "
            . "id='printgestion-mapping-toggle' data-label-show='" . htmlspecialchars(__('Afficher le tableau', 'printgestion'), ENT_QUOTES, 'UTF-8')
            . "' data-label-hide='" . htmlspecialchars(__('Masquer le tableau', 'printgestion'), ENT_QUOTES, 'UTF-8') . "'>";
        echo "<i class='fa-solid fa-chevron-down me-1'></i>";
        echo "<span class='printgestion-toggle-label'>" . __('Afficher le tableau', 'printgestion') . "</span>";
        echo "</button>";
        echo "</div>";

        // Section repliable : contient tout le tableau + bouton "Ajouter une ligne"
        echo "<div class='collapse' id='printgestion-mapping-collapse'>";

        echo "<p class='text-muted small mb-3'>"
            . __("Une ligne par propriété SNMP. Modifie plusieurs lignes, coche celles à supprimer, "
                . "ajoute-en de nouvelles via <strong>+ Ajouter une ligne</strong>, puis clique "
                . "<strong>Sauvegarder</strong> en bas de page. La couleur est déduite automatiquement du nom.", 'printgestion')
            . "</p>";

        // Liste des types pour la template JS (plain <select> sur les new rows)
        $cartridge_types = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => 'glpi_cartridgeitemtypes',
            'ORDER'  => ['name'],
        ]) as $t) {
            $cartridge_types[(int)$t['id']] = (string)$t['name'];
        }

        // Pré-charge les lignes pour pouvoir émettre les hidden inputs AVANT le <table>
        // (les <input> directement dans <tbody> sans <td> sont déplacés hors du
        // form par les parseurs HTML des navigateurs → pas envoyés au POST).
        $mapping_rows = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_printgestion_snmp_mapping',
            'ORDER' => ['snmp_property'],
        ]) as $m) {
            $mapping_rows[] = $m;
        }

        // NB: on n'ouvre PAS de <form> ici. Toute la section est rendue à l'intérieur
        // du form principal ouvert par showFormHeader() plus haut dans showConfigForm().
        // Le bouton "Save" natif de showFormButtons() en bas de page déclenchera le POST
        // vers front/config.form.php qui traitera aussi ce batch mapping.
        echo Html::hidden('snmp_mapping_batch', ['value' => '1']);

        // Enumération des IDs à traiter (DOIT être hors du <table> pour survivre
        // au parsing DOM du navigateur)
        foreach ($mapping_rows as $m) {
            echo Html::hidden('existing_ids[]', ['value' => (int)$m['id']]);
        }

        echo "<table class='table table-sm table-hover align-middle' id='printgestion-mapping-table'>";
        echo "<thead><tr class='noHover'>";
        echo "<th style='width:50%'>" . __('Propriété SNMP', 'printgestion') . "</th>";
        echo "<th style='width:40%'>" . __('Type cartouche GLPI', 'printgestion') . "</th>";
        echo "<th style='width:10%'>" . __('Supprimer', 'printgestion') . "</th>";
        echo "</tr></thead><tbody id='printgestion-existing-rows'>";

        $has_rows = !empty($mapping_rows);
        foreach ($mapping_rows as $m) {
            $id  = (int)$m['id'];
            $prop = htmlspecialchars((string)$m['snmp_property'], ENT_QUOTES, 'UTF-8');
            $current_type = (int)($m['cartridgeitemtypes_id'] ?? 0);

            echo "<tr id='row-{$id}' data-row-id='{$id}'>";
            echo "<td><span class='row-property'>{$prop}</span></td>";
            echo "<td>";
            // Dropdown natif GLPI — nom FLAT (pas de syntaxe tableau) +
            // rand numérique unique par ligne pour éviter les collisions de DOM ID
            Dropdown::show('CartridgeItemType', [
                'name'                => "existing_type_{$id}",
                'value'               => $current_type,
                'display_emptychoice' => true,
                'emptylabel'          => '-----',
                'rand'                => $id + 100000,
            ]);
            echo "</td>";
            echo "<td>";
            echo "<div class='form-check'>";
            echo "<input type='checkbox' class='form-check-input' name='delete[{$id}]' value='1' "
                . "id='del-{$id}' onchange=\"printgestionToggleDelete({$id}, this.checked)\">";
            echo "<label class='form-check-label small text-danger' for='del-{$id}'>"
                . "<i class='fa-solid fa-trash'></i></label>";
            echo "</div>";
            echo "</td>";
            echo "</tr>";
        }

        if (!$has_rows) {
            echo "<tr id='printgestion-empty-row'><td colspan='3' class='text-muted text-center'>"
                . __('Aucun mapping défini', 'printgestion') . "</td></tr>";
        }

        echo "</tbody>";
        echo "<tbody id='printgestion-new-rows'></tbody>";
        echo "</table>";

        // Seul le bouton "Ajouter une ligne" reste — la sauvegarde passe par
        // le bouton Save natif GLPI en bas de page (showFormButtons)
        echo "<div class='mt-3'>";
        echo "<button type='button' class='btn btn-sm btn-outline-primary' onclick='printgestionAddMappingRow()'>"
            . "<i class='fa-solid fa-plus'></i> " . __('Ajouter une ligne', 'printgestion') . "</button>";
        echo "<span class='text-muted small ms-2'>"
            . __('Les modifications seront enregistrées avec le bouton Sauvegarder en bas de page.', 'printgestion')
            . "</span>";
        echo "</div>";

        // Template <select> caché pour les nouvelles lignes clonées via JS.
        // Les nouvelles lignes utilisent un <select> natif HTML (pas Dropdown::show)
        // parce qu'on ne peut pas cloner un select2 initialisé côté JS sans casser
        // son binding ajax. Ce n'est pas moche : les options sont chargées à l'avance.
        $tpl_options = "<option value=''>-----</option>";
        foreach ($cartridge_types as $tid => $tlabel) {
            $tpl_options .= "<option value='{$tid}'>"
                . htmlspecialchars($tlabel, ENT_QUOTES, 'UTF-8') . "</option>";
        }
        echo "<template id='printgestion-mapping-template'>";
        echo "<select name='__PLACEHOLDER__' class='form-select form-select-sm'>{$tpl_options}</select>";
        echo "</template>";

        echo <<<'HTML'
<script>
let printgestionNewRowIdx = 0;

function printgestionToggleDelete(rowId, checked) {
    const tr = document.getElementById('row-' + rowId);
    if (!tr) return;
    tr.style.textDecoration = checked ? 'line-through' : '';
    tr.style.opacity = checked ? '0.5' : '';
}

function printgestionAddMappingRow() {
    const tbody = document.getElementById('printgestion-new-rows');
    const idx   = printgestionNewRowIdx++;

    // Récupère le HTML du <select> depuis le <template> et renomme le placeholder
    const tpl = document.getElementById('printgestion-mapping-template');
    const selectHtml = tpl.innerHTML.replace(
        '__PLACEHOLDER__',
        'new[' + idx + '][cartridgeitemtypes_id]'
    );

    const tr = document.createElement('tr');
    tr.className = 'table-warning';
    tr.innerHTML =
        '<td><input type="text" class="form-control form-control-sm" ' +
            'name="new[' + idx + '][snmp_property]" ' +
            'placeholder="tonerblack, drumcyan, fuserkit, …" required></td>' +
        '<td>' + selectHtml + '</td>' +
        '<td><button type="button" class="btn btn-sm btn-outline-danger" ' +
            'onclick="this.closest(\'tr\').remove()">' +
            '<i class="fa-solid fa-xmark"></i></button></td>';
    tbody.appendChild(tr);

    // Cache l'éventuelle ligne "Aucun mapping défini"
    const empty = document.getElementById('printgestion-empty-row');
    if (empty) empty.style.display = 'none';
}

// Rotation du chevron + libellé au toggle du collapse.
// Exécution immédiate (pas DOMContentLoaded) car ce script est injecté via AJAX
// dans l'onglet Config, le DOMContentLoaded a déjà été tiré avant l'injection.
// Les éléments HTML sont juste au-dessus dans le flux → déjà disponibles ici.
(function() {
    const collapseEl = document.getElementById('printgestion-mapping-collapse');
    const toggleBtn  = document.getElementById('printgestion-mapping-toggle');
    if (!collapseEl || !toggleBtn) return;
    const chevron = toggleBtn.querySelector('.fa-chevron-down, .fa-chevron-up');
    const label   = toggleBtn.querySelector('.printgestion-toggle-label');
    collapseEl.addEventListener('show.bs.collapse', function() {
        if (chevron) { chevron.classList.remove('fa-chevron-down'); chevron.classList.add('fa-chevron-up'); }
        if (label)   { label.textContent = toggleBtn.dataset.labelHide; }
    });
    collapseEl.addEventListener('hide.bs.collapse', function() {
        if (chevron) { chevron.classList.remove('fa-chevron-up'); chevron.classList.add('fa-chevron-down'); }
        if (label)   { label.textContent = toggleBtn.dataset.labelShow; }
    });
})();
</script>
HTML;

        // Ferme : collapse, card-body, card
        echo "</div></div></div>";
    }
}
