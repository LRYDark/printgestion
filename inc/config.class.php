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

    /** Colonnes chiffrées avec GLPIKey (déclarées au hook secured_fields dans setup.php). */
    /** Secrets chiffrés (GLPIKey), jamais réaffichés : le secret client GLS (suivi des colis, GLS seulement). */
    const SECRET_FIELDS = ['gls_client_secret'];
    /** Jamais rendues par l'API REST (même chiffrées) : GLPI les retire de la réponse via unsetUndisclosedFields(). */
    public static $undisclosedFields = ['gls_client_id', 'gls_client_secret'];

    static private $_instance = null;

    /** Cause du dernier échec de sendMail() ; chaîne vide après un envoi réussi. */
    private static string $last_mail_error = '';

    /** Cause du dernier échec de sendMail(), à afficher ou journaliser par l'appelant. */
    public static function getLastMailError(): string {
        return self::$last_mail_error;
    }

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

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item->getType() == 'Config') {
            return __('Print Gestion', 'printgestion');
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
        $config->showFormHeader(['colspan' => 4]);
        echo '</td></tr></table>';
        // Tout le formulaire passe par un tampon : en lecture seule, chaque contrôle de saisie ressort désactivé.
        ob_start();

        // En tête : état réel de l'environnement (contrôles automatiques, journal du plugin compris).
        PluginPrintgestionConfighealth::showCard($canedit);

        // ── Seuils ────────────────────────────────────────────────
        // Helper : rend un label avec icône d'info et tooltip
        $label_with_tip = function (string $label, string $tip): string {
            $tip_esc = htmlspecialchars($tip, ENT_QUOTES, 'UTF-8');
            return "<label class='form-label'>" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                . " <i class='fa-solid fa-circle-info text-muted ms-1' data-bs-toggle='tooltip'"
                . " title=\"{$tip_esc}\"></i></label>";
        };

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

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . __("Seuils d'alerte", 'printgestion') . "</h3></div><div class='card-body'>";
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

        echo "<div class='col-md-3'>"
            . $label_with_tip(
                __('Imprimante muette après (jours)', 'printgestion'),
                __("Sans inventaire depuis ce nombre de jours, une imprimante (ou l'agent qui l'inventorie) est signalée muette dans l'écran « Contrôle de la remontée » : elle ne peut plus déclencher d'alerte toner.", 'printgestion')
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

        // ── Alertes de contrat natives GLPI ──────────────────────
        PluginPrintgestionContractalert::showConfigCard();

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


        // ── Card unique : Rôles & notifications ───────────────────
        $roles = [
            'planif'     => [
                'label'   => __('Planification (expédition cartouche)', 'printgestion'),
                'gabarit' => 'gabarit_planif',
            ],
            'achat'      => [
                'label'   => __('Achats (commande de cartouches)', 'printgestion'),
                'gabarit' => 'gabarit_achat',
            ],
            'commercial' => [
                'label'   => __('Commercial (information client)', 'printgestion'),
                'gabarit' => 'gabarit_commercial',
            ],
        ];

        echo "<div class='card mb-3'><div class='card-header d-flex justify-content-between align-items-center'>"
            . "<h3 class='card-title mb-0'>" . __('Rôles & notifications', 'printgestion') . "</h3>"
            . "<button type='button' class='btn btn-sm btn-outline-primary' data-bs-toggle='modal' data-bs-target='#pg-notif-modal'>"
            . "<i class='fa-solid fa-eye me-1'></i>" . __('Qui est notifié ?', 'printgestion') . "</button>"
            . "</div><div class='card-body'>";

        echo "<div class='alert alert-info py-2 mb-3'>"
            . "<i class='fa-solid fa-circle-info me-1'></i>"
            . __("L'accès aux dashboards Print Gestion est géré via les droits de profil GLPI "
                . "(Administration → Profils → Print Gestion). Cette section configure uniquement "
                . "où sont envoyées les notifications email.", 'printgestion')
            . "</div>";

        // 3 rôles avec notifications (switch groupe/emails + gabarit)
        foreach ($roles as $role => $cfg) {
            $mode        = (string)($config->fields['mode_' . $role] ?? 'group');
            $group_value = (int)($config->fields['group_' . $role] ?? 0);
            // NB: la colonne emails_X stocke désormais une liste CSV d'IDs users GLPI
            $users_raw   = (string)($config->fields['emails_' . $role] ?? '');
            $users_ids   = array_values(array_filter(
                array_map('intval', preg_split('/[,;\s]+/', $users_raw) ?: []),
                fn($id) => $id > 0
            ));

            $use_users = ($mode === 'emails'); // 'emails' = legacy name, signifie "users directs"
            $group_div = "printgestion-{$role}-group";
            $users_div = "printgestion-{$role}-users";
            $switch_id = "printgestion-{$role}-switch";

            echo "<div class='mb-4 pb-3 border-bottom'>";
            echo "<h5 class='mb-2'>" . htmlspecialchars($cfg['label'], ENT_QUOTES, 'UTF-8') . "</h5>";

            // Gabarit
            echo "<div class='row mb-3 align-items-center'><div class='col-md-4'><label class='form-label mb-0'>"
                . __('Gabarit de notification', 'printgestion') . "</label></div><div class='col-md-8'>";
            Dropdown::show('NotificationTemplate', [
                'name'                => $cfg['gabarit'],
                'value'               => (int)($config->fields[$cfg['gabarit']] ?? 0),
                'display_emptychoice' => true,
                'emptylabel'          => '-----',
            ]);
            echo "</div></div>";
            if ($role === 'planif') {
                // Envoi groupé (plusieurs cartouches d'un coup) : gabarit à part (Expedition), enregistré ici.
                echo "<div class='row mb-3 align-items-center'><div class='col-md-4'><label class='form-label mb-0'>"
                    . __('Gabarit envoi groupé (plusieurs cartouches)', 'printgestion') . "</label></div><div class='col-md-8'>";
                Dropdown::show('NotificationTemplate', [
                    'name'                => 'gabarit_planif_group',
                    'value'               => (int)($config->fields['gabarit_planif_group'] ?? 0),
                    'display_emptychoice' => true,
                    'emptylabel'          => '-----',
                ]);
                echo "</div></div>";
            }

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

            echo "</div></div>"; // row
            echo "</div>"; // rôle
        }

        // Rappel installation : gabarit + destinataires (planif / commercial / les deux)
        echo "<div class='mb-4 pb-3 border-bottom'>";
        echo "<h5 class='mb-2'>" . __('Rappel installation', 'printgestion') . "</h5>";
        echo "<div class='row mb-3 align-items-center'><div class='col-md-4'><label class='form-label mb-0'>"
            . __('Gabarit si cartouche non installée après délai', 'printgestion') . "</label></div><div class='col-md-8'>";
        Dropdown::show('NotificationTemplate', [
            'name'                => 'gabarit_rappel',
            'value'               => (int)($config->fields['gabarit_rappel'] ?? 0),
            'display_emptychoice' => true,
            'emptylabel'          => '-----',
        ]);
        echo "</div></div>";
        echo "<div class='row align-items-center'><div class='col-md-4'><label class='form-label mb-0'>"
            . __('Destinataires du rappel', 'printgestion') . "</label></div><div class='col-md-8'>";
        Dropdown::showFromArray('reminder_recipients', [
            'planif'     => __('Planification seule', 'printgestion'),
            'commercial' => __('Commercial seul', 'printgestion'),
            'both'       => __('Planification + Commercial', 'printgestion'),
        ], ['value' => (string)($config->fields['reminder_recipients'] ?? 'both')]);
        echo "<small class='text-muted d-block mt-1'>"
            . __('Évite que les commerciaux relancent une demande déjà traitée.', 'printgestion')
            . "</small>";
        echo "</div></div>";
        echo "</div>";

        // Courtoisie client : gabarit (destinataire = usager imprimante, sinon entité)
        echo "<div>";
        echo "<h5 class='mb-2'>" . __('Courtoisie client', 'printgestion') . "</h5>";
        echo "<div class='row align-items-center'><div class='col-md-4'><label class='form-label mb-0'>"
            . __('Gabarit envoyé au client lors d\'un envoi de cartouche', 'printgestion') . "</label></div><div class='col-md-8'>";
        Dropdown::show('NotificationTemplate', [
            'name'                => 'gabarit_courtoisie',
            'value'               => (int)($config->fields['gabarit_courtoisie'] ?? 0),
            'display_emptychoice' => true,
            'emptylabel'          => '-----',
        ]);
        echo "<small class='text-muted d-block mt-1'>"
            . __('Destinataire : uniquement l\'usager renseigné sur la fiche imprimante. Sans usager, aucun mail n\'est envoyé pour cette imprimante.', 'printgestion')
            . "</small>";
        echo "</div></div>";
        echo "</div>";

        echo "</div></div>"; // fin card Rôles & notifications

        // ── Modale « Qui est notifié ? » : VUE PAR DESTINATAIRE ─────────────────
        // Pour chaque rôle, on liste TOUTES les notifications qu'il reçoit selon la
        // config (ex : Commercial = info toner bas + rappel SI la cible rappel l'inclut),
        // en signalant les gabarits non configurés (notification inactive).
        $resolveTxt = function (string $role): string {
            $emails = PluginPrintgestionAlert::resolveRecipientsForRole($role);
            return empty($emails)
                ? "<span class='text-danger'>" . __('aucun destinataire', 'printgestion') . "</span>"
                : "<span class='text-success'>" . htmlspecialchars(implode(', ', $emails), ENT_QUOTES, 'UTF-8') . "</span>";
        };
        $rmode = (string)($config->fields['reminder_recipients'] ?? 'both');
        $rappel_to_planif     = in_array($rmode, ['planif', 'both'], true);
        $rappel_to_commercial = in_array($rmode, ['commercial', 'both'], true);
        $gabOk = function (string $field) use ($config): bool {
            return (int)($config->fields[$field] ?? 0) > 0;
        };
        // Rend une liste <ul> de notifications. Chaque item : [texte, gabarit_actif?].
        $notifList = function (array $items): string {
            if (empty($items)) {
                return "<span class='text-muted'>—</span>";
            }
            $html = '<ul style="margin:0;padding-left:18px;">';
            foreach ($items as $it) {
                $warn = $it[1] ? '' : " <span style='color:#b91c1c;'>(" . __('gabarit non configuré', 'printgestion') . ")</span>";
                $html .= '<li>' . htmlspecialchars($it[0], ENT_QUOTES, 'UTF-8') . $warn . '</li>';
            }
            return $html . '</ul>';
        };

        $recipients = [
            [
                __('Commercial', 'printgestion'),
                $notifList(array_merge(
                    [[__('Information toner bas — cron horaire, 1 seul mail digest par run', 'printgestion'), $gabOk('gabarit_commercial')]],
                    $rappel_to_commercial ? [[__('Rappel cartouche non installée — cron, 1 seul mail digest par run', 'printgestion'), $gabOk('gabarit_rappel')]] : []
                )),
                $resolveTxt('commercial'),
            ],
            [
                __('Planification', 'printgestion'),
                $notifList(array_merge(
                    [[__('Expédition cartouche (simple : gabarit unitaire / multi : gabarit groupé avec client par ligne) — « Envoyer cartouche » si la case Planif est cochée', 'printgestion'), $gabOk('gabarit_planif')]],
                    $rappel_to_planif ? [[__('Rappel cartouche non installée — cron, 1 seul mail digest par run', 'printgestion'), $gabOk('gabarit_rappel')]] : []
                )),
                $resolveTxt('planif'),
            ],
            [
                __('Achat', 'printgestion'),
                $notifList([[__('Commande cartouche — fichier Excel joint (détail dans l\'Excel, plus de tableau dans le mail) — dès qu\'une cartouche est à commander', 'printgestion'), true]]),
                $resolveTxt('achat'),
            ],
            [
                __('Client (courtoisie)', 'printgestion'),
                $notifList([[__('Cartouche(s) en cours d\'envoi — regroupé par contact (1 mail listant ses imprimantes) — si la case Courtoisie est cochée', 'printgestion'), $gabOk('gabarit_courtoisie')]]),
                "<em>" . __('Usager renseigné sur la fiche imprimante (aucun envoi sans usager) — varie par imprimante', 'printgestion') . "</em>",
            ],
            [
                __('Demandeur (en copie)', 'printgestion'),
                $notifList([[__('En copie (CC) des mails Achat et Planif', 'printgestion'), true]]),
                "<em>" . __('L\'utilisateur qui déclenche l\'envoi', 'printgestion') . "</em>",
            ],
        ];

        echo "<div class='modal fade' id='pg-notif-modal' tabindex='-1'><div class='modal-dialog modal-lg modal-dialog-scrollable'><div class='modal-content'>";
        echo "<div class='modal-header'><h5 class='modal-title'><i class='fa-solid fa-bell me-2'></i>"
            . __('Qui est notifié ?', 'printgestion') . "</h5>"
            . "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button></div>";
        echo "<div class='modal-body'><p class='text-muted small'>"
            . __('Pour chaque destinataire, les notifications qu\'il reçoit selon la configuration ENREGISTRÉE (enregistre avant de vérifier).', 'printgestion')
            . "</p>";
        echo "<table class='table table-sm align-middle'><thead><tr>"
            . "<th>" . __('Destinataire', 'printgestion') . "</th>"
            . "<th>" . __('Notifications reçues', 'printgestion') . "</th>"
            . "<th>" . __('Emails résolus', 'printgestion') . "</th></tr></thead><tbody>";
        foreach ($recipients as $r) {
            echo "<tr><td><strong>" . htmlspecialchars($r[0], ENT_QUOTES, 'UTF-8') . "</strong></td>"
                . "<td class='small'>" . $r[1] . "</td>"
                . "<td class='small'>" . $r[2] . "</td></tr>";
        }
        echo "</tbody></table></div>";
        echo "<div class='modal-footer'><button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>"
            . __('Fermer', 'printgestion') . "</button></div>";
        echo "</div></div></div>";

        // ── Card : Mapping SNMP → Cartouches GLPI ─────────────────
        self::showSnmpMappingCard();

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

        // ── Suivi GLS (identifiant client et secret, rien d'autre : les URL sont des constantes du code) ──
        $gls_id     = (string) ($config->fields['gls_client_id'] ?? '');
        $gls_set    = (string) ($config->fields['gls_client_secret'] ?? '') !== '';
        $gls_date   = (string) ($config->fields['gls_secret_date'] ?? '');
        $esc        = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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
            if ($gls_set && $gls_id !== '') {
                // Demande un jeton et le jette : « connexion établie » ou l'erreur, jamais le jeton.
                echo " <button type='submit' name='test_gls' value='1' class='btn btn-sm btn-outline-primary' formnovalidate data-pg-submit-once='1'>"
                    . "<i class='ti ti-plug-connected me-1'></i>" . $esc(__('Tester la connexion', 'printgestion')) . "</button>";
            }
        }
        echo "</div></div>";

        // ── Suivi : tâche automatique (lecture seule : la fréquence et le mode appartiennent à GLPI) ──
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('Suivi des expéditions : tâche automatique', 'printgestion')) . "</h3></div><div class='card-body'>";
        $tracking_rows = PluginPrintgestionConfighealth::getTaskRows(['PrintgestionTrackingUpdate']);
        echo PluginPrintgestionConfighealth::renderTaskTable($tracking_rows);
        foreach ($tracking_rows as $row) {
            if ((int) $row['frequency'] > HOUR_TIMESTAMP) {
                echo "<p class='text-warning mb-0'><i class='ti ti-alert-triangle me-1'></i>" . $esc(sprintf(
                    __('Le suivi des colis demande un passage toutes les heures : la fréquence réglée dans GLPI (%s) est plus longue. Elle se règle dans la fiche de la tâche, le plugin ne la corrige pas.', 'printgestion'),
                    PluginPrintgestionConfighealth::formatFrequency((int) $row['frequency'])
                )) . "</p>";
            }
        }
        echo "</div></div>";
        echo "</div></div>";


        self::showOrphansCard();

        $html = (string) ob_get_clean();
        if (!$canedit) {
            // Lecture seule réelle : champs, listes, zones de texte et boutons d'envoi désactivés ; les boutons
            // « type=button » (chevrons, fenêtres d'information, « Qui est notifié ? ») restent utilisables.
            $html = (string) preg_replace('/<(input|select|textarea)\b(?![^>]*\bdisabled\b)/i', '<$1 disabled', $html);
            $html = (string) preg_replace('/<button\b(?![^>]*type=[\'"]button[\'"])(?![^>]*\bdisabled\b)/i', '<button disabled', $html);
        }
        echo $html;

        if ($canedit) {
            // Rouvrir la table/tr/td attendue par showFormButtons avant de fermer
            echo '<table><tr><td>';
            $config->showFormButtons(['candel' => false]);
        } else {
            // Pas de bouton Sauvegarder : le formulaire est fermé tel quel.
            Html::closeForm();
        }
        return true;
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
            . "id='printgestion-mapping-toggle'>";
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

        echo "<table class='tab_cadre_fixehov' style='width:100%' id='printgestion-mapping-table'>";
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
        if (label)   { label.textContent = 'Masquer le tableau'; }
    });
    collapseEl.addEventListener('hide.bs.collapse', function() {
        if (chevron) { chevron.classList.remove('fa-chevron-up'); chevron.classList.add('fa-chevron-down'); }
        if (label)   { label.textContent = 'Afficher le tableau'; }
    });
})();
</script>
HTML;

        // Ferme : collapse, card-body, card
        echo "</div></div></div>";
    }

    // ─────────────────────────────────────────────────────────────
    //  ENVOI DE MAIL PAR GABARIT (pattern plugin Gestion MailSend)
    // ─────────────────────────────────────────────────────────────

    /**
     * Envoie un mail à partir d'un gabarit de notification.
     * GLPI 11 : API Symfony Mailer via GLPIMailer::getEmail().
     *
     * @param string|array $email      Destinataire(s) — premier = TO, suivants = CC.
     * @param int          $gabarit_id ID du gabarit glpi_notificationtemplates.
     * @param array        $balises    ['##printgestion.printer##' => 'valeur', ...]
     * @param string|null  $attachment Chemin fichier à joindre (optionnel).
     * @param string|null  $attachment_name Nom de la pièce jointe (défaut : nom du fichier).
     */
    public static function sendMail($email, int $gabarit_id, array $balises = [], ?string $attachment = null, ?string $attachment_name = null): bool {
        global $DB, $CFG_GLPI;

        self::$last_mail_error = '';

        if ($gabarit_id <= 0) {
            self::$last_mail_error = __('aucun modèle de notification configuré', 'printgestion');
            PluginPrintgestionLogger::warning('Config::sendMail', 'Mail non envoyé : ' . self::$last_mail_error . '.');
            return false;
        }

        // Parsing & validation emails
        $items = is_array($email)
            ? $email
            : preg_split('/[,\s;]+/u', (string)$email, -1, PREG_SPLIT_NO_EMPTY);
        $valid = [];
        foreach ($items as $e) {
            $e = trim((string)$e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $valid[strtolower($e)] = $e;
            }
        }
        if (empty($valid)) {
            self::$last_mail_error = __('aucune adresse email valide parmi les destinataires', 'printgestion');
            PluginPrintgestionLogger::warning(
                'Config::sendMail',
                sprintf('Mail non envoyé (modèle %d) : %s.', $gabarit_id, self::$last_mail_error)
            );
            return false;
        }
        $valid = array_values($valid);
        $to    = array_shift($valid);
        $cc    = $valid;

        // Chargement gabarit avec fallback de langue
        $curLang = $_SESSION['glpilanguage'] ?? ($CFG_GLPI['language'] ?? 'fr_FR');
        $langs   = array_values(array_unique([$curLang, substr($curLang, 0, 2), 'fr_FR']));

        $tpl = null;
        foreach ($langs as $lang) {
            $row = $DB->request([
                'SELECT' => ['subject', 'content_text', 'content_html'],
                'FROM'   => 'glpi_notificationtemplatetranslations',
                'WHERE'  => [
                    'notificationtemplates_id' => $gabarit_id,
                    'language'                 => $lang,
                ],
                'LIMIT'  => 1,
            ])->current();
            if (is_array($row)) {
                $tpl = $row;
                break;
            }
        }
        if ($tpl === null) {
            $tpl = $DB->request([
                'SELECT' => ['subject', 'content_text', 'content_html'],
                'FROM'   => 'glpi_notificationtemplatetranslations',
                'WHERE'  => ['notificationtemplates_id' => $gabarit_id],
                'LIMIT'  => 1,
            ])->current();
        }
        if (!is_array($tpl)) {
            self::$last_mail_error = sprintf(__('modèle de notification %d introuvable ou sans traduction', 'printgestion'), $gabarit_id);
            PluginPrintgestionLogger::warning('Config::sendMail', 'Mail non envoyé : ' . self::$last_mail_error . '.');
            return false;
        }

        $subject  = (string)($tpl['subject'] ?? '');
        $bodyText = isset($tpl['content_text']) ? html_entity_decode((string)$tpl['content_text'], ENT_QUOTES, 'UTF-8') : '';
        $bodyHtml = isset($tpl['content_html']) ? html_entity_decode((string)$tpl['content_html'], ENT_QUOTES, 'UTF-8') : '';

        // Balises disponibles par défaut
        $defaults = [
            '##printgestion.printer##'         => '',
            '##printgestion.client##'          => '',
            '##printgestion.toner##'           => '',
            '##printgestion.level##'           => '',
            '##printgestion.days##'            => '',
            '##printgestion.cartridge##'       => '',
            // Plus de stock GLPI : balise conservée, toujours vide, pour les gabarits existants.
            '##printgestion.stock##'           => '',
            '##printgestion.contract##'        => '',
            '##printgestion.carrier##'         => '',
            '##printgestion.tracking##'        => '',
            '##printgestion.cartridges_list##' => '',
            '##printgestion.printers_list##'   => '',
            '##printgestion.count##'           => '',
            '##printgestion.glpi_url##'        => (string)($CFG_GLPI['url_base'] ?? ''),
        ];
        $all = array_merge($defaults, $balises);

        // Balises dont la valeur est du HTML construit par le plugin (listes <ul>
        // dont chaque valeur dynamique est échappée à la construction). Toutes les
        // autres valeurs — noms d'imprimante, de client, de cartouche… issus de
        // l'inventaire SNMP ou de la saisie — sont du TEXTE, échappé dans le corps HTML.
        $html_tags = ['##printgestion.cartridges_list##', '##printgestion.printers_list##'];

        foreach ($all as $tag => $val) {
            $val     = (string)$val;
            $is_html = in_array($tag, $html_tags, true);

            // Version texte : pour une liste HTML, un élément par ligne, sans balises.
            $plain = $is_html
                ? trim(html_entity_decode(
                    strip_tags((string)preg_replace('#</li>\s*#i', "\n", $val)),
                    ENT_QUOTES,
                    'UTF-8'
                ))
                : $val;

            // Sujet : une seule ligne (aucun retour à la ligne injecté dans l'en-tête).
            $subject  = str_replace($tag, str_replace(["\r", "\n"], ' ', $plain), $subject);
            $bodyText = str_replace($tag, $plain, $bodyText);
            $bodyHtml = str_replace(
                $tag,
                $is_html ? $val : htmlspecialchars($val, ENT_QUOTES, 'UTF-8'),
                $bodyHtml
            );
        }

        // Mailer GLPI 11 (Symfony)
        $mmail = new GLPIMailer();
        $mmail->addCustomHeader("X-Auto-Response-Suppress: OOF, DR, NDR, RN, NRN");

        $fromEmail = !empty($CFG_GLPI['from_email'])
            ? (string)$CFG_GLPI['from_email']
            : (string)($CFG_GLPI['admin_email'] ?? 'no-reply@localhost');
        $fromName  = $CFG_GLPI['from_email_name'] ?? $CFG_GLPI['admin_email_name'] ?? 'GLPI';
        $fromName  = (is_string($fromName) && $fromName !== '') ? $fromName : 'GLPI';

        $emailObj = $mmail->getEmail();
        $emailObj->from(new \Symfony\Component\Mime\Address($fromEmail, $fromName));
        $emailObj->to($to);
        if (!empty($cc)) {
            $emailObj->cc(...$cc);
        }
        // Pièce jointe attendue (fichier Gesconso) : jamais de mail sans elle.
        if ($attachment !== null && $attachment !== '') {
            if (!is_file($attachment) || !is_readable($attachment) || filesize($attachment) === 0) {
                self::$last_mail_error = __('pièce jointe introuvable ou vide, mail non envoyé', 'printgestion');
                PluginPrintgestionLogger::error('Config::sendMail', sprintf('Mail non envoyé (modèle %d) : pièce jointe %s introuvable ou vide.', $gabarit_id, basename($attachment)));
                return false;
            }
            $emailObj->attachFromPath($attachment, $attachment_name); // nom affiché (fichier archivé : nom d'origine)
            if (count($emailObj->getAttachments()) === 0) {
                self::$last_mail_error = __('pièce jointe non attachée au message, mail non envoyé', 'printgestion');
                PluginPrintgestionLogger::error('Config::sendMail', sprintf('Mail non envoyé (modèle %d) : pièce jointe %s non attachée.', $gabarit_id, basename($attachment)));
                return false;
            }
        }

        if ($subject !== '') {
            $mmail->Subject = $subject;
        }
        $mmail->Body    = $bodyHtml;
        $mmail->AltBody = $bodyText;

        $ok = (bool)$mmail->send();
        if (!$ok) {
            self::$last_mail_error = (string)$mmail->getError();
            // Seule trace pour les envois des tâches automatiques (pas de session à l'écran).
            PluginPrintgestionLogger::error(
                'Config::sendMail',
                sprintf(
                    'Échec d\'envoi (modèle %d, destinataire principal %s) : %s',
                    $gabarit_id,
                    $to,
                    self::$last_mail_error
                )
            );
            // Réponse du serveur mail (bannière, nom d'hôte, message du relais) : rendue telle quelle par GLPI
            // (`message|raw`), donc échappée ici comme toute donnée externe.
            Session::addMessageAfterRedirect(
                htmlspecialchars(__('Erreur envoi mail Print Gestion : ', 'printgestion') . self::$last_mail_error, ENT_QUOTES, 'UTF-8'),
                true, ERROR
            );
        }
        return $ok;
    }
}
