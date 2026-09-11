<?php
/**
 * PluginPrintgestionDashboardactions — assets partagés pour le context-menu
 * des dashboards Print Gestion (Alertes toner et Expéditions).
 *
 * Fournit :
 *   - Le HTML des 4 modals (stock, edit expedition, snooze, send recap)
 *   - Le div du menu contextuel
 *   - Le JS qui gère le right-click, les appels AJAX et la logique des modals
 *
 * Utilisation côté dashboard :
 *   - Ajouter data-pc-row + data-pc-* attributes sur chaque <tr> actionnable
 *   - Appeler PluginPrintgestionDashboardactions::renderSharedAssets() en fin de page
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionDashboardactions extends CommonGLPI {

    static $rightname = 'plugin_printgestion_dashboard';

    static function getTypeName($nb = 0) {
        return __('Actions dashboard', 'printgestion');
    }

    /**
     * Produit les attributs data-pc-* à injecter sur un <tr> de dashboard_alerts.
     * $row est une ligne de PluginPrintgestionAlert::listAll().
     */
    public static function rowDataAttributesForAlert(array $row): string {
        $exp = $row['expedition'] ?? null;
        $attrs = [
            'data-pc-row'            => '1',
            'data-pc-source'         => 'alert',
            'data-pc-printers-id'    => (int)$row['printers_id'],
            'data-pc-printer-name'   => (string)($row['printer_name'] ?? ''),
            'data-pc-entity-name'    => (string)($row['entity_name'] ?? ''),
            'data-pc-property'       => (string)$row['property'],
            'data-pc-level'          => (int)($row['level'] ?? 0),
            'data-pc-days'           => (int)($row['days_remaining'] ?? 0),
            'data-pc-cartridge'      => (string)($row['cartridge_type'] ?? ''),
            'data-pc-has-expedition' => is_array($exp) ? '1' : '0',
            'data-pc-expedition-id'  => is_array($exp) ? (int)$exp['id'] : 0,
            'data-pc-exp-statut'     => is_array($exp) ? (string)$exp['statut'] : '',
        ];
        return self::serializeAttrs($attrs);
    }

    /**
     * Produit les attributs data-pc-* à injecter sur un <tr> de dashboard_expeditions.
     * $exp est une ligne de SELECT sur glpi_plugin_printgestion_expeditions.
     */
    public static function rowDataAttributesForExpedition(array $exp): string {
        $attrs = [
            'data-pc-row'            => '1',
            'data-pc-source'         => 'expedition',
            'data-pc-printers-id'    => (int)$exp['printers_id'],
            'data-pc-printer-name'   => (string)($exp['printer_name'] ?? ''),
            'data-pc-entity-name'    => (string)($exp['entity_name'] ?? ''),
            'data-pc-property'       => (string)$exp['toner_property'],
            'data-pc-level'          => (int)($exp['level_at_alert'] ?? 0),
            'data-pc-days'           => 0,
            'data-pc-cartridge'      => '',
            'data-pc-has-expedition' => '1',
            'data-pc-expedition-id'  => (int)$exp['id'],
            'data-pc-exp-statut'     => (string)$exp['statut'],
            'data-pc-exp-carrier'    => (string)($exp['transport_carrier'] ?? ''),
            'data-pc-exp-tracking'   => (string)($exp['transport_number'] ?? ''),
        ];
        return self::serializeAttrs($attrs);
    }

    protected static function serializeAttrs(array $attrs): string {
        $out = '';
        foreach ($attrs as $k => $v) {
            $out .= ' ' . $k . "='" . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . "'";
        }
        return $out;
    }

    /**
     * Rend le HTML des modals + le context-menu + le JS.
     * À appeler UNE FOIS en fin de page (avant Html::footer()).
     *
     * @param string $context 'alerts' | 'expeditions' | 'billing' — détermine
     *   quelles actions du clic-droit sont affichées :
     *     alerts      : toutes les actions (stock, envoi, modifier, snooze, BL, fiche)
     *     expeditions : stock, modifier, snooze, BL, fiche (pas d'envoi — déjà envoyée)
     *     billing     : uniquement "Ouvrir la fiche imprimante"
     */
    public static function renderSharedAssets(string $context = 'alerts'): void {
        $can_expedition_update = Session::haveRight('plugin_printgestion_expedition', UPDATE);
        $ajax_base = PLUGIN_PRINTGESTION_WEBDIR . '/ajax';

        // Vérifie si le plugin Gestion est actif ET que l'intégration est activée
        // côté plugin printgestion (config plugin_gestion_enabled = 1)
        $bl_enabled = false;
        try {
            $plugin = new Plugin();
            if ($plugin->isInstalled('gestion') && $plugin->isActivated('gestion')) {
                $config = PluginPrintgestionConfig::getInstance();
                $bl_enabled = (int)($config->fields['plugin_gestion_enabled'] ?? 0) === 1;
            }
        } catch (Throwable $e) {
            // Liaison BL désactivée pour cet affichage, mais la cause est tracée.
            PluginPrintgestionLogger::error(
                'Dashboardactions::renderSharedAssets',
                "Lecture de l'état du plugin Gestion impossible : liaison BL désactivée pour cet affichage.",
                $e
            );
        }

        self::renderContextMenu($can_expedition_update, $bl_enabled, $context);

        // Les modals ne sont rendus que si au moins une action du menu les utilise.
        // Pour billing, seule "Ouvrir la fiche imprimante" est active → aucun modal.
        if ($context !== 'billing') {
            self::renderStockModal();
            self::renderSnoozeModal();
            self::renderSnoozeGroupModal();
            self::renderUnsnoozeModal();
        }
        if ($context === 'alerts' || $context === 'expeditions') {
            self::renderEditExpeditionModal();
            if ($bl_enabled) {
                self::renderLinkBlModal();
            }
        }
        // Envoi / commande : fenêtre de commande de l'écran des alertes uniquement
        // (dashboard_alerts.php → send_purchase.php), pas de modale ici.

        self::renderJs($ajax_base, $can_expedition_update, $bl_enabled);
    }

    /**
     * Rend la barre de recherche + tri + pagination JS pour un tableau.
     * À appeler AVANT le <table>, avec une table qui a `id="$table_id"`.
     *
     * Les colonnes triables doivent avoir la classe 'pc-sortable' sur le <th>.
     * La recherche est full-text sur toutes les cellules visibles de chaque row.
     */
    public static function renderTableToolbar(string $table_id): void {
        $search_placeholder = __('Rechercher dans le tableau…', 'printgestion');
        $per_page_label     = __('Par page', 'printgestion');
        $count_label        = __('résultats', 'printgestion');

        echo "<div class='card mb-3'><div class='card-body'>";
        echo "<div class='row g-2 mb-3 align-items-center'>";
        echo "<div class='col-md-6'>";
        echo "<div class='input-group input-group-sm'>";
        echo "<span class='input-group-text'><i class='fa-solid fa-magnifying-glass'></i></span>";
        echo "<input type='text' class='form-control pc-table-search' "
            . "data-pc-table-target='{$table_id}' "
            . "placeholder='" . htmlspecialchars($search_placeholder, ENT_QUOTES, 'UTF-8') . "'>";
        echo "</div>";
        echo "</div>";
        echo "<div class='col-md-3'>";
        echo "<div class='input-group input-group-sm'>";
        echo "<span class='input-group-text'>" . htmlspecialchars($per_page_label, ENT_QUOTES, 'UTF-8') . "</span>";
        echo "<select class='form-select pc-table-perpage' data-pc-table-target='{$table_id}'>";
        foreach ([10, 25, 50, 100, 250] as $n) {
            $sel = ($n === 25) ? ' selected' : '';
            echo "<option value='{$n}'{$sel}>{$n}</option>";
        }
        echo "</select>";
        echo "</div>";
        echo "</div>";
        echo "<div class='col-md-3 text-end'>";
        echo "<span class='text-muted small pc-table-count' data-pc-table-target='{$table_id}'></span>";
        echo "</div>";
        echo "</div>";
    }

    /**
     * Rend la barre de pagination sous le tableau.
     */
    public static function renderTablePagination(string $table_id): void {
        echo "<nav class='mt-3 d-flex justify-content-center'><ul class='pagination pagination-sm mb-0 pc-table-pagination' "
            . "data-pc-table-target='{$table_id}'></ul></nav>";
        echo "</div></div>"; // close card-body + card opened in renderTableToolbar
    }

    protected static function renderContextMenu(bool $can_update, bool $bl_enabled = false, string $context = 'alerts'): void {
        // Actions autorisées par contexte (déjà filtrées côté PHP — pas besoin
        // de tout rendre puis de cacher en JS, ça allège le DOM).
        $allowed = [
            'alerts'      => ['view-stock', 'send-cartridge', 'edit-expedition', 'link-bl', 'snooze', 'unsnooze', 'open-printer'],
            'expeditions' => ['view-stock', 'edit-expedition', 'link-bl', 'open-printer'],
            'billing'     => ['open-printer'],
        ];
        $ctx_actions = $allowed[$context] ?? $allowed['alerts'];

        $items = [
            'view-stock' => [
                'icon'    => 'fa-boxes-stacked',
                'label'   => 'Voir le stock',
                'require' => null,
            ],
            'send-cartridge' => [
                'icon'    => 'fa-paper-plane',
                'label'   => 'Envoyer cartouche…',
                'require' => 'no-exp',
            ],
            'edit-expedition' => [
                'icon'    => 'fa-pen',
                'label'   => 'Modifier expédition…',
                'require' => 'has-exp',
            ],
            'link-bl' => [
                'icon'    => 'fa-file-signature',
                'label'   => 'Associer des BL (plugin Gestion)…',
                'require' => 'has-exp',
            ],
            'snooze' => [
                'icon'    => 'fa-bell-slash',
                'label'   => 'Ne plus alerter pendant…',
                'require' => null,
            ],
            'unsnooze' => [
                'icon'    => 'fa-bell',
                'label'   => 'Réactiver les alertes',
                'require' => 'has-snoozed',
            ],
            'open-printer' => [
                'icon'    => 'fa-print',
                'label'   => 'Ouvrir la fiche imprimante',
                'require' => null,
            ],
        ];

        $html_items = '';
        foreach ($ctx_actions as $act) {
            if ($act === 'link-bl' && !$bl_enabled) {
                continue;
            }
            if (!isset($items[$act])) {
                continue;
            }
            $it  = $items[$act];
            $req = $it['require'] ? " data-pc-require='{$it['require']}'" : '';
            $html_items .= '<a href="#" class="d-block px-3 py-2 text-decoration-none text-body" '
                . "data-pc-action='{$act}'{$req}>"
                . "<i class='fa-solid {$it['icon']} me-2'></i>"
                . htmlspecialchars($it['label'], ENT_QUOTES, 'UTF-8')
                . "</a>\n";
        }

        echo <<<HTML
<div id="pc-ctx-menu" class="shadow border rounded bg-white"
     style="position:absolute;display:none;z-index:9999;min-width:260px;padding:4px 0">
{$html_items}</div>
<style>
#pc-ctx-menu a:hover { background:#f1f3f5; }
tr[data-pc-row="1"] { cursor: context-menu; }
table[data-pc-sortable="1"] th { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
table[data-pc-sortable="1"] td { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pc-col-resizer {
  position: absolute;
  top: 0;
  right: 0;
  width: 6px;
  height: 100%;
  cursor: col-resize;
  user-select: none;
  z-index: 5;
}
.pc-col-resizer:hover { background: rgba(13,110,253,0.3); }
.pc-table-wrap { min-height: 120px; }
.pc-table-spinner {
  position: absolute;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(255,255,255,0.75);
  display: none;
  align-items: flex-start;
  justify-content: center;
  /* Décale le spinner sous la thead du tableau (hauteur typique ~48px + marge) */
  padding-top: 56px;
  z-index: 50;
}
.pc-table-spinner .pc-spinner-inner { text-align: center; }
</style>
HTML;
    }

    protected static function renderStockModal(): void {
        $title = __('Stock cartouche', 'printgestion');
        $loading = __('Chargement…', 'printgestion');
        $close = _sx('button', 'Close');
        echo <<<HTML
<div class="modal fade" id="pc-modal-stock" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-boxes-stacked me-2"></i>{$title}</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="pc-modal-stock-body">
        <div class="text-center text-muted">{$loading}</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{$close}</button>
      </div>
    </div>
  </div>
</div>
HTML;
    }

    protected static function renderEditExpeditionModal(): void {
        $title = __('Modifier expédition', 'printgestion');
        $close = _sx('button', 'Close');
        $save  = _sx('button', 'Save');
        $statut_label = __('Statut', 'printgestion');
        $carrier_label = __('Transporteur', 'printgestion');
        $tracking_label = __('N° de suivi', 'printgestion');

        $statut_opts = [
            'pending'     => __('En attente', 'printgestion'),
            'shipped'     => __('Expédiée', 'printgestion'),
            'transit'     => __('En transit', 'printgestion'),
            'delivered'   => __('Livrée (non posée)', 'printgestion'),
            'stock_empty' => __('Stock vide', 'printgestion'),
            'installed'   => __('Posée — confirmer la pose (clôt l\'envoi)', 'printgestion'),
            'cancelled'   => __('Annulée (clôt l\'envoi, sans suppression)', 'printgestion'),
        ];
        $statut_html = '';
        foreach ($statut_opts as $val => $lab) {
            $statut_html .= "<option value='{$val}'>" . htmlspecialchars($lab, ENT_QUOTES, 'UTF-8') . "</option>";
        }

        $carrier_opts = [
            ''           => __('—', 'printgestion'),
            'ups'        => 'UPS',
            'gls'        => 'GLS',
            'chronopost' => 'Chronopost',
            'other'      => __('Autre', 'printgestion'),
        ];
        $carrier_html = '';
        foreach ($carrier_opts as $val => $lab) {
            $carrier_html .= "<option value='{$val}'>" . htmlspecialchars($lab, ENT_QUOTES, 'UTF-8') . "</option>";
        }

        echo <<<HTML
<div class="modal fade" id="pc-modal-editexp" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="pc-form-editexp">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fa-solid fa-pen me-2"></i>{$title}</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="expedition_id" id="pc-editexp-id">
          <div class="mb-3">
            <div class="text-muted small mb-2" id="pc-editexp-header"></div>
          </div>
          <div class="mb-3">
            <label class="form-label">{$statut_label}</label>
            <select name="statut" id="pc-editexp-statut" class="form-select">{$statut_html}</select>
          </div>
          <div class="mb-3">
            <label class="form-label">{$carrier_label}</label>
            <select name="carrier" id="pc-editexp-carrier" class="form-select">{$carrier_html}</select>
          </div>
          <div class="mb-3">
            <label class="form-label">{$tracking_label}</label>
            <input type="text" name="tracking" id="pc-editexp-tracking" class="form-control">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{$close}</button>
          <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save me-1"></i>{$save}</button>
        </div>
      </form>
    </div>
  </div>
</div>
HTML;
    }

    protected static function renderSnoozeModal(): void {
        $title = __('Ne plus alerter pendant…', 'printgestion');
        $close = _sx('button', 'Close');
        $confirm = __('Confirmer', 'printgestion');
        $label = __('Durée', 'printgestion');
        $header = __('Cette ligne sera masquée du dashboard pendant la durée choisie. Les alertes reprendront automatiquement ensuite.', 'printgestion');

        echo <<<HTML
<div class="modal fade" id="pc-modal-snooze" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="pc-form-snooze">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fa-solid fa-bell-slash me-2"></i>{$title}</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small">{$header}</p>
          <input type="hidden" name="printers_id" id="pc-snooze-printer">
          <input type="hidden" name="property"    id="pc-snooze-property">
          <div class="mb-3">
            <div class="text-muted small mb-2" id="pc-snooze-header"></div>
          </div>
          <div class="mb-3">
            <label class="form-label">{$label}</label>
            <select name="days" class="form-select">
              <option value="1">1 jour</option>
              <option value="3">3 jours</option>
              <option value="7" selected>7 jours</option>
              <option value="14">14 jours</option>
              <option value="30">30 jours</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{$close}</button>
          <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check me-1"></i>{$confirm}</button>
        </div>
      </form>
    </div>
  </div>
</div>
HTML;
    }

    protected static function renderSnoozeGroupModal(): void {
        $title   = __('Ne plus alerter pendant…', 'printgestion');
        $close   = _sx('button', 'Cancel');
        $confirm = __('Appliquer', 'printgestion');
        $intro   = __('Sélectionne les cartouches à snoozer et la durée. Les cartouches déjà snoozées sont désactivées.', 'printgestion');
        $duration= __('Durée', 'printgestion');

        $title_h    = htmlspecialchars($title,    ENT_QUOTES, 'UTF-8');
        $close_h    = htmlspecialchars($close,    ENT_QUOTES, 'UTF-8');
        $confirm_h  = htmlspecialchars($confirm,  ENT_QUOTES, 'UTF-8');
        $intro_h    = htmlspecialchars($intro,    ENT_QUOTES, 'UTF-8');
        $duration_h = htmlspecialchars($duration, ENT_QUOTES, 'UTF-8');

        echo <<<HTML
<div class="modal fade" id="pc-modal-snoozegroup" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="pc-form-snoozegroup">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fa-solid fa-bell-slash me-2"></i>{$title_h}</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mb-2">{$intro_h}</p>
          <div class="text-muted small mb-3" id="pc-snoozegroup-header"></div>
          <input type="hidden" name="printers_id" id="pc-snoozegroup-printers-id">
          <div class="mb-3">
            <label class="form-label">{$duration_h}</label>
            <select name="days" id="pc-snoozegroup-days" class="form-select">
              <option value="1">1 jour</option>
              <option value="3">3 jours</option>
              <option value="7" selected>7 jours</option>
              <option value="14">14 jours</option>
              <option value="30">30 jours</option>
            </select>
          </div>
          <table class="table table-sm table-bordered align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th style="width:40px">&nbsp;</th>
                <th>Toner</th>
              </tr>
            </thead>
            <tbody id="pc-snoozegroup-tbody"></tbody>
          </table>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{$close_h}</button>
          <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-bell-slash me-1"></i>{$confirm_h}
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
HTML;
    }

    protected static function renderUnsnoozeModal(): void {
        $title   = __('Réactiver les alertes', 'printgestion');
        $close   = _sx('button', 'Cancel');
        $confirm = __('Réactiver la sélection', 'printgestion');
        $intro   = __('Sélectionne les cartouches pour lesquelles tu veux réactiver les alertes. Le snooze actif sera supprimé.', 'printgestion');

        $title_h   = htmlspecialchars($title,   ENT_QUOTES, 'UTF-8');
        $close_h   = htmlspecialchars($close,   ENT_QUOTES, 'UTF-8');
        $confirm_h = htmlspecialchars($confirm, ENT_QUOTES, 'UTF-8');
        $intro_h   = htmlspecialchars($intro,   ENT_QUOTES, 'UTF-8');

        echo <<<HTML
<div class="modal fade" id="pc-modal-unsnooze" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="pc-form-unsnooze">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fa-solid fa-bell me-2"></i>{$title_h}</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mb-2">{$intro_h}</p>
          <div class="text-muted small mb-3" id="pc-unsnooze-header"></div>
          <input type="hidden" name="printers_id" id="pc-unsnooze-printers-id">
          <table class="table table-sm table-bordered align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th style="width:40px">&nbsp;</th>
                <th>Toner</th>
              </tr>
            </thead>
            <tbody id="pc-unsnooze-tbody"></tbody>
          </table>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{$close_h}</button>
          <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-bell me-1"></i>{$confirm_h}
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
HTML;
    }

    protected static function renderLinkBlModal(): void {
        $title   = __('Associer des BL (plugin Gestion)', 'printgestion');
        $close   = _sx('button', 'Close');
        $save    = _sx('button', 'Save');
        $intro   = __('Tape un numéro de BL (ex : BL203846) puis Entrée. Les BL SAGE sont vérifiés automatiquement. Dès qu\'un BL associé est signé côté client, l\'expédition passera en livrée.', 'printgestion');
        $label   = __('Tape le numéro de document (ex : BL154869), puis Entrée', 'printgestion');
        $placeholder = __('Tape au moins 2 caractères — ex: BL203...', 'printgestion');

        $labels = json_encode([
            'title'       => $title,
            'intro'       => $intro,
            'label'       => $label,
            'close'       => $close,
            'save'        => $save,
            'placeholder' => $placeholder,
            'verifying'   => __('Vérification…', 'printgestion'),
            'verified'    => __('Document "%s" vérifié et ajouté.', 'printgestion'),
            'not_found'   => __('Document "%s" non trouvé dans l\'API SAGE ni en local. Il a été retiré de la sélection.', 'printgestion'),
            'verify_err'  => __('Erreur lors de la vérification du document "%s". Il a été retiré de la sélection.', 'printgestion'),
        ], JSON_UNESCAPED_UNICODE);

        echo <<<'HTML'
<div class="modal fade" id="pc-modal-linkbl" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form id="pc-form-linkbl">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fa-solid fa-file-signature me-2"></i><span id="pc-linkbl-title"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <!-- Zone messages (success/warning/danger) -->
          <div id="pc-linkbl-messages" style="display:none; margin-bottom: 15px;">
            <div class="alert alert-dismissible fade show" role="alert" id="pc-linkbl-alert">
              <span id="pc-linkbl-message-text"></span>
              <button type="button" class="btn-close" aria-label="Close" onclick="document.getElementById('pc-linkbl-messages').style.display='none';"></button>
            </div>
          </div>

          <p class="text-muted small" id="pc-linkbl-intro"></p>
          <input type="hidden" name="expedition_id" id="pc-linkbl-expid">
          <div class="mb-3">
            <div class="text-muted small mb-2" id="pc-linkbl-header"></div>
          </div>
          <div class="mb-3">
            <label class="form-label" id="pc-linkbl-label"></label>
            <select name="bls[]" id="pc-linkbl-select" multiple="multiple" style="width:100%;"></select>
          </div>
          <!-- Tableau détaillé des BL associés (date + entité) -->
          <div id="pc-linkbl-details-wrap" class="mt-3" style="display:none;">
            <label class="form-label small text-muted">Détail des BL associés</label>
            <table class="table table-sm table-bordered mb-0">
              <thead class="table-light">
                <tr>
                  <th>BL</th>
                  <th>Date</th>
                  <th>Entité</th>
                  <th>Source</th>
                </tr>
              </thead>
              <tbody id="pc-linkbl-details-tbody"></tbody>
            </table>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="pc-linkbl-close"></button>
          <button type="submit" class="btn btn-primary" id="pc-linkbl-save"></button>
        </div>
      </form>
    </div>
  </div>
</div>
HTML;

        echo "<script>
document.addEventListener('DOMContentLoaded', function() {
    var l = {$labels};
    function setTxt(id, t) { var e = document.getElementById(id); if (e) e.textContent = t; }
    setTxt('pc-linkbl-title',  l.title);
    setTxt('pc-linkbl-intro',  l.intro);
    setTxt('pc-linkbl-label',  l.label);
    setTxt('pc-linkbl-close',  l.close);
    setTxt('pc-linkbl-save',   l.save);

    function showModalMsg(msg, type) {
        type = type || 'danger';
        var txt = document.getElementById('pc-linkbl-message-text');
        var al  = document.getElementById('pc-linkbl-alert');
        var wrap= document.getElementById('pc-linkbl-messages');
        if (!txt || !al || !wrap) return;
        txt.textContent = msg;
        al.className = 'alert alert-dismissible fade show alert-' + type;
        wrap.style.display = 'block';
        if (type === 'success') {
            setTimeout(function(){ wrap.style.display = 'none'; }, 5000);
        }
    }
    function hideModalMsg() {
        var wrap = document.getElementById('pc-linkbl-messages');
        if (wrap) wrap.style.display = 'none';
    }

    if (typeof jQuery !== 'undefined' && typeof jQuery.fn.select2 !== 'undefined') {
        var ajaxUrl = window.PC_CONFIG ? (window.PC_CONFIG.ajaxBase + '/search_bls.php') : '';
        var \$sel = jQuery('#pc-linkbl-select');
        \$sel.select2({
            placeholder: l.placeholder,
            multiple: true,
            minimumInputLength: 2,
            minimumResultsForSearch: 0,
            allowClear: false,
            tags: true,
            tokenSeparators: [',', ' '],
            createTag: function(params) {
                var t = jQuery.trim(params.term);
                return t === '' ? null : { id: t, text: t, newTag: true };
            },
            language: { inputTooShort: function() { return l.placeholder; } },
            dropdownParent: jQuery('#pc-modal-linkbl'),
            ajax: {
                delay: 300,
                url: ajaxUrl,
                dataType: 'json',
                data: function(params) {
                    return {
                        q: params.term,
                        printers_id: document.getElementById('pc-linkbl-select')
                            ? (document.getElementById('pc-linkbl-select').getAttribute('data-pc-printers-id') || '')
                            : ''
                    };
                },
                processResults: function(data) {
                    return { results: (data && data.bls) ? data.bls.map(function(b) {
                        return { id: b.id, text: b.label };
                    }) : [] };
                },
                cache: true
            }
        });

        // Vérification SAGE au select d'un tag (valeur tapée manuellement).
        \$sel.off('select2:select.verify').on('select2:select.verify', function(e) {
            var data = e.params.data;
            if (!data || !data.newTag) return;
            hideModalMsg();

            // Appel de search_bls pour vérifier si le BL existe (local ou SAGE)
            jQuery.ajax({
                url: ajaxUrl,
                method: 'GET',
                data: { q: data.id },
                dataType: 'json',
                timeout: 15000
            })
            .done(function(resp) {
                var found = null;
                if (resp && resp.bls && resp.bls.length) {
                    for (var i = 0; i < resp.bls.length; i++) {
                        var b = resp.bls[i];
                        // Match strict sur le numéro de BL (label contient le BL + badges)
                        var blStr = String(b.label || '').toUpperCase();
                        if (blStr.indexOf(String(data.id).toUpperCase()) !== -1) {
                            found = b;
                            break;
                        }
                    }
                }
                if (!found) {
                    // Retire le tag de la sélection
                    var vals = \$sel.val() || [];
                    var idx = vals.indexOf(data.id);
                    if (idx > -1) { vals.splice(idx, 1); \$sel.val(vals).trigger('change'); }
                    \$sel.find('option[value=\"' + data.id + '\"]').remove();
                    // API Sage en panne : l'absence de résultat ne prouve PAS que le BL n'existe pas.
                    if (resp && resp.sage_error) {
                        showModalMsg(l.verify_err.replace('%s', data.id), 'danger');
                    } else {
                        showModalMsg(l.not_found.replace('%s', data.id), 'warning');
                    }
                    return;
                }
                // Trouvé : on remplace la valeur du tag par l'id réel retourné par search_bls
                // (soit un id numérique pour un BL local, soit 'sage:XXX' pour un BL SAGE).
                var newId = String(found.id);
                if (newId !== data.id) {
                    var vals = \$sel.val() || [];
                    var idx = vals.indexOf(data.id);
                    if (idx > -1) { vals[idx] = newId; }
                    // Supprime l'ancienne option tag et ajoute la nouvelle avec label complet
                    \$sel.find('option[value=\"' + data.id + '\"]').remove();
                    if (\$sel.find('option[value=\"' + newId + '\"]').length === 0) {
                        var opt = new Option(found.label || newId, newId, true, true);
                        \$sel.append(opt);
                    }
                    \$sel.val(vals).trigger('change');
                } else {
                    \$sel.find('option[value=\"' + data.id + '\"]').removeAttr('data-select2-tag');
                }
                showModalMsg(l.verified.replace('%s', data.id), 'success');
            })
            .fail(function() {
                var vals = \$sel.val() || [];
                var idx = vals.indexOf(data.id);
                if (idx > -1) { vals.splice(idx, 1); \$sel.val(vals).trigger('change'); }
                \$sel.find('option[value=\"' + data.id + '\"]').remove();
                showModalMsg(l.verify_err.replace('%s', data.id), 'danger');
            });
        });
    }
});
</script>";
    }

    protected static function renderJs(string $ajax_base, bool $can_update, bool $bl_enabled = false): void {
        // Toutes les valeurs injectées dans le JS passent par json_encode() pour
        // gérer correctement les apostrophes, accents et caractères spéciaux.
        global $CFG_GLPI, $GLPI_CACHE;
        $root_doc = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');

        // Charge les préférences colonnes depuis le cache GLPI (fichier dans files/_cache/)
        $table_prefs = [];
        $users_id = (int)(Session::getLoginUserID() ?: 0);
        if ($users_id > 0 && isset($GLPI_CACHE)) {
            $cached = $GLPI_CACHE->get('plugin_printgestion_table_prefs_' . $users_id);
            if (is_array($cached)) {
                $table_prefs = $cached;
            }
        }

        $js_config = json_encode([
            'canUpdate'  => (bool)$can_update,
            'blEnabled'  => (bool)$bl_enabled,
            'ajaxBase'   => $ajax_base,
            'rootDoc'    => $root_doc,
            'tablePrefs' => $table_prefs,
            'csrf'       => Session::getNewCSRFToken(),
            'msg'       => [
                'error'             => __("Erreur lors de l'action", 'printgestion'),
                'no_stock_info'     => __('Aucune information de stock', 'printgestion'),
                'no_bl_available'   => __('Aucun BL disponible pour ce client', 'printgestion'),
                'stock_label'       => __('Stock disponible', 'printgestion'),
                'cartridge_label'   => __('Cartouche', 'printgestion'),
                'level_label'       => __('Niveau actuel', 'printgestion'),
                'days_label'        => __('Jours restants estimés', 'printgestion'),
                'client_label'      => __('Client', 'printgestion'),
                'printer_label'     => __('Imprimante', 'printgestion'),
                'property_label'    => __('Propriété SNMP', 'printgestion'),
                'location_label'    => __('Emplacement', 'printgestion'),
                'toner_label'       => __('Toner', 'printgestion'),
                'nothing_selected'  => __('Aucune cartouche sélectionnée', 'printgestion'),
                'stable'            => __('Stable', 'printgestion'),
                'jour'              => __('jour', 'printgestion'),
                'jours'              => __('jours', 'printgestion'),
                'status_ok'         => __('Bon', 'printgestion'),
                'exp_pending'       => __('En attente', 'printgestion'),
                'exp_shipped'       => __('Expédiée', 'printgestion'),
                'exp_transit'       => __('En transit', 'printgestion'),
                'exp_delivered'     => __('Livrée', 'printgestion'),
                'exp_stock_empty'   => __('Stock vide', 'printgestion'),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Script JS : une seule interpolation via json_encode = pas de risque d'apostrophe
        // On place PC_CONFIG en global, le reste du JS est statique et lit cette config.
        echo "<script>window.PC_CONFIG = {$js_config};</script>\n";
        echo <<<'HTML'
<script>
(function() {
  const CAN_UPDATE = window.PC_CONFIG.canUpdate;
  const AJAX_BASE  = window.PC_CONFIG.ajaxBase;
  const CSRF_TOKEN = window.PC_CONFIG.csrf;
  const MSG        = window.PC_CONFIG.msg;

  // ── Fix a11y Bootstrap Modal ──
  // Quand un modal se cache, Bootstrap met aria-hidden=true dessus. Si le
  // focus est encore sur un bouton intérieur (ex: "Close"), console warning.
  // On blur l'élément focussé juste avant la fermeture.
  document.addEventListener('hide.bs.modal', function(e) {
    const active = document.activeElement;
    if (active && e.target && e.target.contains(active)) {
      active.blur();
    }
  }, true);

  // ── Helper POST avec CSRF correct pour GLPI 11 ──
  // GLPI 11 CheckCsrfListener pour AJAX (XmlHttpRequest) :
  //   - lit le token dans le header "X-Glpi-Csrf-Token" (pas dans le body)
  //   - preserve_token=true → le token reste valide pour les requêtes suivantes
  // Donc un seul token (celui de PC_CONFIG) suffit pour toutes les AJAX.
  function pcPost(url, fd) {
    return fetch(url, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: {
        'X-Requested-With':  'XMLHttpRequest',
        'X-Glpi-Csrf-Token': CSRF_TOKEN,
      }
    });
  }

  // ── Bouton "Rafraîchir" (invalide les caches dashboards) ──
  // POST + jeton CSRF en en-tête (pcPost) : l'action recalcule toute la table des
  // alertes, elle ne doit pas être déclenchable par un simple lien.
  const btnRefresh = document.getElementById('pc-refresh-cache');
  if (btnRefresh) {
    btnRefresh.addEventListener('click', function() {
      const orig = btnRefresh.innerHTML;
      btnRefresh.disabled = true;
      btnRefresh.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>';
      pcPost(AJAX_BASE + '/refresh_cache.php', new FormData())
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data && data.ok) { window.location.reload(); }
          else {
            btnRefresh.innerHTML = orig;
            btnRefresh.disabled  = false;
            alert(MSG.error);
          }
        })
        .catch(function() {
          btnRefresh.innerHTML = orig;
          btnRefresh.disabled  = false;
          alert(MSG.error);
        });
    });
  }

  const menu = document.getElementById('pc-ctx-menu');
  if (!menu) return;

  let currentRow = null;

  // ── Right-click sur les lignes ──
  document.addEventListener('contextmenu', function(e) {
    const tr = e.target.closest('tr[data-pc-row="1"]');
    if (!tr) return;
    e.preventDefault();
    currentRow = tr;

    const hasExp  = tr.getAttribute('data-pc-has-expedition') === '1';
    const grouped = tr.getAttribute('data-pc-grouped') === '1';
    // Détecte si au moins une cartouche de la ligne est snoozée
    let hasSnoozed = false;
    const cartridgesJson = tr.getAttribute('data-pc-cartridges');
    if (cartridgesJson) {
      try {
        const cs = JSON.parse(cartridgesJson) || [];
        hasSnoozed = cs.some(function(c) { return c && c.snoozed; });
      } catch (err) { hasSnoozed = false; }
    }

    // Helper show/hide : les menu items ont la classe Bootstrap "d-block" qui
    // applique `display: block !important`. Un inline style sans !important
    // ne peut pas override → on passe via setProperty avec priority important.
    function setShown(el, shown) {
      el.style.setProperty('display', shown ? 'block' : 'none', 'important');
    }

    menu.querySelectorAll('[data-pc-require]').forEach(function(el) {
      const req = el.getAttribute('data-pc-require');
      const act = el.getAttribute('data-pc-action');
      // En mode groupé : "Envoyer cartouches" est toujours accessible, la
      // logique (cartouches déjà expédiées → checkbox disabled) est portée
      // par la modale groupée.
      if (grouped && act === 'send-cartridge') {
        setShown(el, true);
        return;
      }
      if (req === 'has-exp')          setShown(el, hasExp);
      else if (req === 'no-exp')      setShown(el, !hasExp);
      else if (req === 'has-snoozed') setShown(el, hasSnoozed);
      else                            setShown(el, true);
    });

    if (!CAN_UPDATE) {
      menu.querySelectorAll(
        '[data-pc-action="edit-expedition"],[data-pc-action="send-cartridge"],[data-pc-action="snooze"],[data-pc-action="unsnooze"],[data-pc-action="link-bl"]'
      ).forEach(function(el) { el.style.setProperty('display', 'none', 'important'); });
    }

    menu.style.left = e.pageX + 'px';
    menu.style.top  = e.pageY + 'px';
    menu.style.display = 'block';
  });

  document.addEventListener('click', function(e) {
    if (!e.target.closest('#pc-ctx-menu')) menu.style.display = 'none';
  });
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') menu.style.display = 'none';
  });

  // ── Handlers des actions ──
  menu.addEventListener('click', function(e) {
    const a = e.target.closest('a[data-pc-action]');
    if (!a || !currentRow) return;
    e.preventDefault();
    menu.style.display = 'none';
    const act = a.getAttribute('data-pc-action');
    let cartridges = [];
    const cartridgesJson = currentRow.getAttribute('data-pc-cartridges');
    if (cartridgesJson) {
      try { cartridges = JSON.parse(cartridgesJson) || []; }
      catch (err) { cartridges = []; }
    }
    const d = {
      grouped:       currentRow.getAttribute('data-pc-grouped') === '1',
      printers_id:   currentRow.getAttribute('data-pc-printers-id'),
      printer_name:  currentRow.getAttribute('data-pc-printer-name'),
      entity_name:   currentRow.getAttribute('data-pc-entity-name'),
      property:      currentRow.getAttribute('data-pc-property'),
      level:         currentRow.getAttribute('data-pc-level'),
      days:          currentRow.getAttribute('data-pc-days'),
      cartridge:     currentRow.getAttribute('data-pc-cartridge'),
      expedition_id: currentRow.getAttribute('data-pc-expedition-id'),
      exp_statut:    currentRow.getAttribute('data-pc-exp-statut'),
      exp_carrier:   currentRow.getAttribute('data-pc-exp-carrier') || '',
      exp_tracking:  currentRow.getAttribute('data-pc-exp-tracking') || '',
      cartridges:    cartridges,
    };

    if (act === 'open-printer') {
      window.location.href = window.PC_CONFIG.rootDoc
        + '/front/printer.form.php?id=' + encodeURIComponent(d.printers_id);
      return;
    }
    if (act === 'view-stock') {
      if (d.grouped && d.cartridges.length > 0) openStockGroupModal(d);
      else openStockModal(d);
      return;
    }
    if (act === 'send-cartridge') {
      // Commande de cartouches (mail Achats + Excel joint), mono OU multi-sélection :
      // seul parcours d'envoi, porté par l'écran des alertes (PG_openPurchaseModal).
      if (typeof window.PG_openPurchaseModal === 'function') { window.PG_openPurchaseModal(d); }
      return;
    }
    if (act === 'edit-expedition') { openEditExpModal(d); return; }
    if (act === 'snooze') {
      if (d.grouped && d.cartridges.length > 0) openSnoozeGroupModal(d);
      else openSnoozeModal(d);
      return;
    }
    if (act === 'unsnooze') { handleUnsnooze(d); return; }
    if (act === 'link-bl')         { openLinkBlModal(d);  return; }
  });

  // ── Stock modal (AJAX GET) ──
  function openStockModal(d) {
    const body = document.getElementById('pc-modal-stock-body');
    body.innerHTML = '<div class="text-center text-muted">...</div>';
    const modal = new bootstrap.Modal(document.getElementById('pc-modal-stock'));
    modal.show();
    fetch(AJAX_BASE + '/cartridge_stock.php?printers_id=' + encodeURIComponent(d.printers_id)
          + '&property=' + encodeURIComponent(d.property), {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data || !data.ok) {
          body.innerHTML = '<div class="alert alert-warning mb-0">' + escapeHtml(MSG.no_stock_info) + '</div>';
          return;
        }
        let html = '<table class="table table-sm mb-0">';
        html += '<tr><th>' + escapeHtml(MSG.printer_label) + '</th><td>' + escapeHtml(d.printer_name) + '</td></tr>';
        if (d.entity_name) html += '<tr><th>' + escapeHtml(MSG.client_label) + '</th><td>' + escapeHtml(d.entity_name) + '</td></tr>';
        html += '<tr><th>' + escapeHtml(MSG.property_label) + '</th><td>' + escapeHtml(d.property) + '</td></tr>';
        html += '<tr><th>' + escapeHtml(MSG.cartridge_label) + '</th><td>' + escapeHtml(data.cartridge_name || '—') + '</td></tr>';
        html += '<tr><th>' + escapeHtml(MSG.stock_label) + '</th><td><strong class="'
             + (data.stock > 0 ? 'text-success' : 'text-danger') + '">' + data.stock + '</strong></td></tr>';
        if (data.location) html += '<tr><th>' + escapeHtml(MSG.location_label) + '</th><td>' + escapeHtml(data.location) + '</td></tr>';
        html += '</table>';
        body.innerHTML = html;
      })
      .catch(function() { body.innerHTML = '<div class="alert alert-danger mb-0">' + escapeHtml(MSG.error) + '</div>'; });
  }

  // ── Edit expedition modal ──
  function openEditExpModal(d) {
    document.getElementById('pc-editexp-id').value = d.expedition_id || '';
    document.getElementById('pc-editexp-statut').value = d.exp_statut || 'shipped';
    document.getElementById('pc-editexp-carrier').value = d.exp_carrier || '';
    document.getElementById('pc-editexp-tracking').value = d.exp_tracking || '';
    document.getElementById('pc-editexp-header').innerHTML =
      escapeHtml(d.printer_name) + (d.entity_name ? ' — ' + escapeHtml(d.entity_name) : '')
      + '<br>' + escapeHtml(MSG.toner_label) + ' : ' + escapeHtml(d.property);
    const modal = new bootstrap.Modal(document.getElementById('pc-modal-editexp'));
    modal.show();
  }

  const editExpForm = document.getElementById('pc-form-editexp');
  if (editExpForm) {
    editExpForm.addEventListener('submit', function(e) {
      e.preventDefault();
      const fd = new FormData(this);
      pcPost(AJAX_BASE + '/edit_expedition.php', fd)
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data && data.ok) { window.location.reload(); }
          else { alert(MSG.error); }
        })
        .catch(function() { alert(MSG.error); });
    });
  }

  // ── Snooze modal ──
  function openSnoozeModal(d) {
    document.getElementById('pc-snooze-printer').value = d.printers_id;
    document.getElementById('pc-snooze-property').value = d.property;
    document.getElementById('pc-snooze-header').innerHTML =
      escapeHtml(d.printer_name) + (d.entity_name ? ' — ' + escapeHtml(d.entity_name) : '')
      + '<br>' + escapeHtml(MSG.toner_label) + ' : ' + escapeHtml(d.property);
    const modal = new bootstrap.Modal(document.getElementById('pc-modal-snooze'));
    modal.show();
  }

  const snoozeForm = document.getElementById('pc-form-snooze');
  if (snoozeForm) snoozeForm.addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    pcPost(AJAX_BASE + '/snooze_alert.php', fd)
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data && data.ok) { window.location.reload(); }
        else { alert(MSG.error); }
      })
      .catch(function() { alert(MSG.error); });
  });

  // ── Link BL modal (plugin Gestion) ──
  // Le select2 ajax est déjà initialisé au DOMContentLoaded dans renderLinkBlModal().
  // Ici on ne fait que : remettre à zéro, mémoriser l'imprimante courante,
  // puis afficher la modal.
  function openLinkBlModal(d) {
    if (!window.PC_CONFIG.blEnabled) return;
    const modalEl = document.getElementById('pc-modal-linkbl');
    if (!modalEl) return;

    document.getElementById('pc-linkbl-expid').value = d.expedition_id || '';
    document.getElementById('pc-linkbl-header').innerHTML =
      escapeHtml(d.printer_name) + (d.entity_name ? ' — ' + escapeHtml(d.entity_name) : '')
      + '<br>' + escapeHtml(MSG.toner_label) + ' : ' + escapeHtml(d.property);

    // Mémorise l'imprimante courante pour le filtre entité côté search_bls.php
    const sel = document.getElementById('pc-linkbl-select');
    if (sel) sel.setAttribute('data-pc-printers-id', d.printers_id || '');

    // Reset select2 + purge des options tags résiduelles d'ouvertures précédentes
    if (typeof jQuery !== 'undefined' && typeof jQuery.fn.select2 !== 'undefined') {
      jQuery('#pc-linkbl-select').empty().val(null).trigger('change');
    }
    const msgWrap = document.getElementById('pc-linkbl-messages');
    if (msgWrap) msgWrap.style.display = 'none';
    const detailsWrap = document.getElementById('pc-linkbl-details-wrap');
    const detailsBody = document.getElementById('pc-linkbl-details-tbody');
    if (detailsWrap) detailsWrap.style.display = 'none';
    if (detailsBody) detailsBody.innerHTML = '';

    // Pré-remplit le select2 avec les BL déjà associés à cette expédition
    fetch(AJAX_BASE + '/expedition_bls.php?expedition_id=' + encodeURIComponent(d.expedition_id || 0), {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data || !data.ok || !Array.isArray(data.bls) || data.bls.length === 0) return;
        if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 === 'undefined') return;
        const $s = jQuery('#pc-linkbl-select');
        const ids = [];
        data.bls.forEach(function(b) {
          const id = String(b.id);
          const signedTag = b.signed ? ' [signé]' : '';
          const label = (b.bl || id) + signedTag;
          if ($s.find('option[value="' + id + '"]').length === 0) {
            $s.append(new Option(label, id, true, true));
          }
          ids.push(id);
        });
        $s.val(ids).trigger('change');

        // Tableau détaillé
        if (detailsWrap && detailsBody) {
          detailsBody.innerHTML = '';
          data.bls.forEach(function(b) {
            const tr = document.createElement('tr');
            const signedBadge = b.signed
              ? ' <span class="badge bg-success ms-1">signé</span>'
              : '';
            tr.innerHTML = '<td>' + escapeHtml(b.bl || '—') + signedBadge + '</td>'
              + '<td class="text-muted small">' + escapeHtml((b.date_creation || '').substring(0, 10)) + '</td>'
              + '<td class="text-muted small">' + escapeHtml(b.entity_name || '—') + '</td>'
              + '<td class="text-muted small">' + escapeHtml(b.save || '—') + '</td>';
            detailsBody.appendChild(tr);
          });
          detailsWrap.style.display = 'block';
        }
      })
      .catch(function() { /* silent */ });

    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  }

  const blForm = document.getElementById('pc-form-linkbl');
  if (blForm) {
    blForm.addEventListener('submit', function(e) {
      e.preventDefault();
      const expedition_id = document.getElementById('pc-linkbl-expid').value;
      // Select2 multiple expose un array via jQuery.val()
      let bls = [];
      if (typeof jQuery !== 'undefined') {
        bls = jQuery('#pc-linkbl-select').val() || [];
      }
      if (!bls.length) {
        alert(MSG.nothing_selected);
        return;
      }
      const fd = new FormData();
      fd.append('expedition_id', expedition_id);
      fd.append('bls', JSON.stringify(bls));
      pcPost(AJAX_BASE + '/link_bls.php', fd)
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data && data.ok) { window.location.reload(); }
          else {
            const msg = (data && data.errors && data.errors.length)
              ? data.errors.map(function(e) { return e.bl + ' : ' + e.error; }).join('\n')
              : ((data && data.error) ? data.error : MSG.error);
            alert(msg);
          }
        })
        .catch(function() { alert(MSG.error); });
    });
  }

  // ══════════════════════════════════════════════════════════════
  //  Modales « groupées » : 1 ligne imprimante = N cartouches.
  // ══════════════════════════════════════════════════════════════

  function fetchStock(printers_id, property) {
    return fetch(AJAX_BASE + '/cartridge_stock.php?printers_id=' + encodeURIComponent(printers_id)
          + '&property=' + encodeURIComponent(property), {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function(r) { return r.json(); })
      .catch(function() { return { ok: false }; });
  }

  function renderGroupHeader(d) {
    return escapeHtml(d.printer_name)
      + (d.entity_name ? ' — ' + escapeHtml(d.entity_name) : '');
  }

  // ── Voir stock groupé : tableau multi-lignes ──
  function openStockGroupModal(d) {
    const body = document.getElementById('pc-modal-stock-body');
    if (!body) return;
    body.innerHTML = '<div class="text-center text-muted">...</div>';
    const modal = new bootstrap.Modal(document.getElementById('pc-modal-stock'));
    modal.show();

    const header = '<div class="text-muted small mb-2">' + renderGroupHeader(d) + '</div>';
    // Pré-remplit la table avec skeleton, puis replace le stock au fur et à mesure
    let tbl = '<table class="table table-sm mb-0"><thead><tr>'
      + '<th>' + escapeHtml(MSG.toner_label) + '</th>'
      + '<th>' + escapeHtml(MSG.cartridge_label) + '</th>'
      + '<th>' + escapeHtml(MSG.stock_label) + '</th>'
      + '</tr></thead><tbody>';
    d.cartridges.forEach(function(c, i) {
      tbl += '<tr data-pc-stock-row="' + i + '">'
        + '<td>' + escapeHtml(c.property) + '</td>'
        + '<td class="pc-cart-name">...</td>'
        + '<td class="pc-cart-stock text-muted">...</td></tr>';
    });
    tbl += '</tbody></table>';
    body.innerHTML = header + tbl;

    d.cartridges.forEach(function(c, i) {
      fetchStock(d.printers_id, c.property).then(function(data) {
        const row = body.querySelector('[data-pc-stock-row="' + i + '"]');
        if (!row) return;
        const nameCell  = row.querySelector('.pc-cart-name');
        const stockCell = row.querySelector('.pc-cart-stock');
        if (data && data.ok) {
          if (nameCell)  nameCell.textContent  = data.cartridge_name || c.cartridge_type || '—';
          if (stockCell) {
            const n = data.stock || 0;
            stockCell.innerHTML = '<strong class="' + (n > 0 ? 'text-success' : 'text-danger') + '">'
              + n + '</strong>';
          }
        } else {
          if (nameCell)  nameCell.textContent = c.cartridge_type || '—';
          if (stockCell) stockCell.textContent = '?';
        }
      });
    });
  }

  // ── Réactiver alertes (unsnooze) ──
  // 1 cartouche snoozée → réactive directement.
  // 2+ cartouches snoozées → modal avec checkboxes pour choisir.
  function handleUnsnooze(d) {
    const snoozed = (d.cartridges || []).filter(function(c) { return c && c.snoozed; });
    // Mode unitaire (fallback quand data-pc-grouped n'est pas set)
    if (!d.grouped && d.property) {
      // Dashboard alerts mode legacy (pas utilisé actuellement mais safe)
      unsnoozeApply(d.printers_id, [d.property]);
      return;
    }
    if (snoozed.length === 0) {
      // Pas censé arriver puisqu'on cache l'action via has-snoozed require
      return;
    }
    if (snoozed.length === 1) {
      unsnoozeApply(d.printers_id, [snoozed[0].property]);
      return;
    }
    openUnsnoozeGroupModal(d, snoozed);
  }

  function unsnoozeApply(printers_id, properties) {
    const fd = new FormData();
    fd.append('printers_id', printers_id);
    fd.append('properties',  JSON.stringify(properties));
    pcPost(AJAX_BASE + '/unsnooze_group.php', fd)
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data && data.ok) { window.location.reload(); }
        else { alert(MSG.error); }
      })
      .catch(function() { alert(MSG.error); });
  }

  function openUnsnoozeGroupModal(d, snoozedCartridges) {
    const tbody  = document.getElementById('pc-unsnooze-tbody');
    const hdr    = document.getElementById('pc-unsnooze-header');
    const hidden = document.getElementById('pc-unsnooze-printers-id');
    if (!tbody || !hdr || !hidden) {
      // Fallback si le modal n'est pas rendu : applique à toutes
      unsnoozeApply(d.printers_id, snoozedCartridges.map(function(c) { return c.property; }));
      return;
    }
    hdr.innerHTML   = renderGroupHeader(d);
    hidden.value    = d.printers_id;
    tbody.innerHTML = '';

    snoozedCartridges.forEach(function(c, i) {
      tbody.insertAdjacentHTML('beforeend',
          '<tr data-pc-us-row="' + i + '" data-pc-us-property="' + escapeAttr(c.property) + '">'
        + '<td class="text-center"><input type="checkbox" class="form-check-input pc-us-chk" checked></td>'
        + '<td><span class="pc-color-dot color-' + escapeAttr(c.toner_color || 'other') + '"></span>'
        + escapeHtml(c.property) + '</td>'
        + '</tr>'
      );
    });

    const modal = new bootstrap.Modal(document.getElementById('pc-modal-unsnooze'));
    modal.show();
  }

  const usForm = document.getElementById('pc-form-unsnooze');
  if (usForm) {
    usForm.addEventListener('submit', function(e) {
      e.preventDefault();
      const printers_id = document.getElementById('pc-unsnooze-printers-id').value;
      const rows = document.querySelectorAll('#pc-unsnooze-tbody tr[data-pc-us-row]');
      const properties = [];
      rows.forEach(function(row) {
        const chk = row.querySelector('.pc-us-chk');
        if (chk && chk.checked) {
          properties.push(row.getAttribute('data-pc-us-property'));
        }
      });
      if (properties.length === 0) {
        alert(MSG.nothing_selected);
        return;
      }
      unsnoozeApply(printers_id, properties);
    });
  }

  // ── Snooze sur ligne groupée — routing intelligent ──
  // Si 1 seule cartouche non-snoozée → modal simple (juste la durée).
  // Sinon (2+ cartouches, ou 0 non-snoozées) → modal avec checkboxes + durée.
  function openSnoozeGroupModal(d) {
    const notSnoozed = (d.cartridges || []).filter(function(c) { return c && !c.snoozed; });

    // Cas "une seule à snoozer" → modal unitaire classique
    if (notSnoozed.length === 1) {
      openSnoozeModal({
        printers_id:  d.printers_id,
        printer_name: d.printer_name,
        entity_name:  d.entity_name,
        property:     notSnoozed[0].property,
      });
      return;
    }

    // Sinon : modal groupé avec checkboxes
    const tbody  = document.getElementById('pc-snoozegroup-tbody');
    const hdr    = document.getElementById('pc-snoozegroup-header');
    const hidden = document.getElementById('pc-snoozegroup-printers-id');
    if (!tbody || !hdr || !hidden) return;

    hdr.innerHTML   = renderGroupHeader(d);
    hidden.value    = d.printers_id;
    tbody.innerHTML = '';

    d.cartridges.forEach(function(c, i) {
      const disabled = c.snoozed ? 'disabled' : '';
      const checked  = c.snoozed ? ''         : 'checked';
      const badge    = c.snoozed
        ? ' <i class="fa-solid fa-bell-slash text-muted ms-1" title="Déjà snoozé"></i>'
        : '';

      tbody.insertAdjacentHTML('beforeend',
          '<tr data-pc-sn-row="' + i + '" data-pc-sn-property="' + escapeAttr(c.property) + '">'
        + '<td class="text-center"><input type="checkbox" class="form-check-input pc-sn-chk" '
        + checked + ' ' + disabled + '></td>'
        + '<td><span class="pc-color-dot color-' + escapeAttr(c.toner_color || 'other') + '"></span>'
        + escapeHtml(c.property) + badge + '</td>'
        + '</tr>'
      );
    });

    const modal = new bootstrap.Modal(document.getElementById('pc-modal-snoozegroup'));
    modal.show();
  }

  const snForm = document.getElementById('pc-form-snoozegroup');
  if (snForm) {
    snForm.addEventListener('submit', function(e) {
      e.preventDefault();
      const printers_id = document.getElementById('pc-snoozegroup-printers-id').value;
      const days        = document.getElementById('pc-snoozegroup-days').value;
      const rows = document.querySelectorAll('#pc-snoozegroup-tbody tr[data-pc-sn-row]');
      const properties = [];
      rows.forEach(function(row) {
        const chk = row.querySelector('.pc-sn-chk');
        if (chk && chk.checked && !chk.disabled) {
          properties.push(row.getAttribute('data-pc-sn-property'));
        }
      });
      if (properties.length === 0) {
        alert(MSG.nothing_selected);
        return;
      }
      const fd = new FormData();
      fd.append('printers_id', printers_id);
      fd.append('days',        days);
      fd.append('properties',  JSON.stringify(properties));
      pcPost(AJAX_BASE + '/snooze_group.php', fd)
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data && data.ok) { window.location.reload(); }
          else { alert(MSG.error); }
        })
        .catch(function() { alert(MSG.error); });
    });
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
      return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
    });
  }
  function escapeAttr(s) { return escapeHtml(s); }

  // ══════════════════════════════════════════════════════════════
  //  Tableau AJAX : fetch serveur + spinner + col width persist
  // ══════════════════════════════════════════════════════════════
  //
  //  Chaque dashboard déclare sa config avant le chargement de ce JS :
  //    window.PC_TABLE_CONFIG['pc-tbl-xxx'] = {
  //       endpoint:    '/ajax/list_xxx.php',
  //       extraParams: function() { return { filter1: 'x', filter2: 'y' }; },
  //       renderRow:   function(row) { return <tr>; },
  //       onMetrics:   function(m)  { /* update cards */ },   (optionnel)
  //       emptyColspan: N,
  //       emptyLabel:  'Aucune donnée',
  //    };
  //
  //  Le JS pilote : fetch au démarrage, à chaque changement (search/perPage/
  //  sort/pagination/filtre externe), avec un spinner overlay pendant l'AJAX.

  window.PC_TABLE_CONFIG = window.PC_TABLE_CONFIG || {};
  const tableStates = {};

  function getTable(id) { return document.getElementById(id); }

  function showSpinner(tableId) {
    const st = tableStates[tableId];
    if (!st || !st.spinner) return;
    st.spinner.style.display = 'flex';
  }
  function hideSpinner(tableId) {
    const st = tableStates[tableId];
    if (!st || !st.spinner) return;
    st.spinner.style.display = 'none';
  }

  function ensureSpinner(tableId) {
    const table = getTable(tableId);
    if (!table) return null;
    // Wrap la table dans un conteneur relatif si pas déjà fait
    let wrap = table.parentNode;
    if (!wrap.classList.contains('pc-table-wrap')) {
      const newWrap = document.createElement('div');
      newWrap.className = 'pc-table-wrap';
      newWrap.style.position = 'relative';
      table.parentNode.insertBefore(newWrap, table);
      newWrap.appendChild(table);
      wrap = newWrap;
    }
    let sp = wrap.querySelector('.pc-table-spinner');
    if (!sp) {
      sp = document.createElement('div');
      sp.className = 'pc-table-spinner';
      sp.innerHTML = '<div class="pc-spinner-inner">'
        + '<div class="spinner-border text-primary" role="status">'
        + '<span class="visually-hidden">Chargement…</span></div></div>';
      sp.style.display = 'none';
      wrap.appendChild(sp);
    }
    return sp;
  }

  function buildQueryString(tableId) {
    const st = tableStates[tableId];
    const cfg = window.PC_TABLE_CONFIG[tableId] || {};
    const params = new URLSearchParams();
    params.set('page', String(st.page));
    params.set('per_page', String(st.perPage));
    if (st.search) params.set('search', st.search);
    if (st.sortCol) params.set('sort_col', st.sortCol);
    if (st.sortDir) params.set('sort_dir', st.sortDir);
    // Extra params du dashboard (filtres GET)
    if (typeof cfg.extraParams === 'function') {
      const extra = cfg.extraParams() || {};
      Object.keys(extra).forEach(function(k) {
        if (extra[k] !== null && extra[k] !== undefined && extra[k] !== '') {
          params.set(k, String(extra[k]));
        }
      });
    }
    return params.toString();
  }

  function fetchPage(tableId) {
    const st = tableStates[tableId];
    const cfg = window.PC_TABLE_CONFIG[tableId];
    if (!st || !cfg || !cfg.endpoint) return;

    // Annule un fetch en cours si l'utilisateur enchaîne les clics
    if (st.abortCtrl) { try { st.abortCtrl.abort(); } catch (e) {} }
    st.abortCtrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;

    showSpinner(tableId);

    const url = AJAX_BASE + '/' + cfg.endpoint + '?' + buildQueryString(tableId);
    fetch(url, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      signal: st.abortCtrl ? st.abortCtrl.signal : undefined,
    })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data || !data.ok) {
          st.tbody.innerHTML = '<tr><td colspan="' + (cfg.emptyColspan || 10) + '" class="text-center text-danger py-3">'
            + 'Erreur de chargement</td></tr>';
          return;
        }
        st.total = data.total || 0;
        // Vider puis re-remplir le tbody
        st.tbody.innerHTML = '';
        if (!data.rows || data.rows.length === 0) {
          const colspan = cfg.emptyColspan || 10;
          st.tbody.innerHTML = '<tr><td colspan="' + colspan + '" class="text-center text-muted py-3">'
            + (cfg.emptyLabel || 'Aucune donnée') + '</td></tr>';
        } else {
          data.rows.forEach(function(row) {
            const tr = cfg.renderRow ? cfg.renderRow(row) : null;
            if (tr) st.tbody.appendChild(tr);
          });
        }
        // Métriques
        if (typeof cfg.onMetrics === 'function' && data.metrics) {
          cfg.onMetrics(data.metrics);
        }
        // Compteur + pagination
        updateCountAndPagination(tableId);
      })
      .catch(function(err) {
        if (err && err.name === 'AbortError') return;
        st.tbody.innerHTML = '<tr><td colspan="' + (cfg.emptyColspan || 10) + '" class="text-center text-danger py-3">'
          + 'Erreur réseau</td></tr>';
      })
      .finally(function() {
        hideSpinner(tableId);
      });
  }

  function updateCountAndPagination(tableId) {
    const st = tableStates[tableId];
    if (!st) return;
    const total = st.total || 0;
    const totalPages = Math.max(1, Math.ceil(total / st.perPage));
    if (st.page > totalPages) st.page = totalPages;

    const countEl = document.querySelector('.pc-table-count[data-pc-table-target="' + tableId + '"]');
    if (countEl) countEl.textContent = total + ' résultat(s)';

    const pagEl = document.querySelector('.pc-table-pagination[data-pc-table-target="' + tableId + '"]');
    if (!pagEl) return;
    pagEl.innerHTML = '';
    if (totalPages <= 1) return;

    const cur = st.page;
    const addItem = function(label, page, opts) {
      opts = opts || {};
      const li = document.createElement('li');
      li.className = 'page-item'
        + (opts.active ? ' active' : '')
        + (opts.disabled ? ' disabled' : '');
      const a = document.createElement('a');
      a.className = 'page-link';
      a.href = '#';
      a.innerHTML = label;
      if (!opts.disabled && !opts.active && page !== null) {
        a.addEventListener('click', function(ev) {
          ev.preventDefault();
          st.page = page;
          fetchPage(tableId);
        });
      } else {
        a.addEventListener('click', function(ev) { ev.preventDefault(); });
      }
      li.appendChild(a);
      pagEl.appendChild(li);
    };

    addItem('&laquo;',  1,           { disabled: cur === 1 });
    addItem('&lsaquo;', cur - 1,     { disabled: cur === 1 });
    const pages = new Set([1, totalPages, cur, cur - 1, cur + 1, cur - 2, cur + 2]);
    const sorted = Array.from(pages).filter(function(p) { return p >= 1 && p <= totalPages; })
                        .sort(function(a, b) { return a - b; });
    let prev = 0;
    sorted.forEach(function(p) {
      if (p - prev > 1) addItem('&hellip;', null, { disabled: true });
      addItem(String(p), p, { active: p === cur });
      prev = p;
    });
    addItem('&rsaquo;', cur + 1,     { disabled: cur === totalPages });
    addItem('&raquo;',  totalPages,  { disabled: cur === totalPages });
  }

  function initTable(tableId) {
    const table = getTable(tableId);
    if (!table) return;
    const tbody = table.tBodies[0];
    if (!tbody) return;
    const perPageSel = document.querySelector('.pc-table-perpage[data-pc-table-target="' + tableId + '"]');
    const initialPerPage = perPageSel ? (parseInt(perPageSel.value, 10) || 25) : 25;

    tableStates[tableId] = {
      table: table,
      tbody: tbody,
      spinner: ensureSpinner(tableId),
      search: '',
      perPage: initialPerPage,
      page: 1,
      sortCol: null,
      sortDir: 'asc',
      total: 0,
      abortCtrl: null,
    };

    // Tri : clic sur les headers pc-sortable
    const ths = table.querySelectorAll('thead th.pc-sortable');
    ths.forEach(function(th) {
      const col = th.getAttribute('data-pc-sort-col') || '';
      if (!col) return;
      th.style.cursor = 'pointer';
      th.innerHTML = th.innerHTML + ' <i class="fa-solid fa-sort text-muted ms-1 pc-sort-icon"></i>';
      th.addEventListener('click', function(e) {
        if (e.target && e.target.classList && e.target.classList.contains('pc-col-resizer')) return;
        const st = tableStates[tableId];
        if (st.sortCol === col) {
          st.sortDir = st.sortDir === 'asc' ? 'desc' : 'asc';
        } else {
          st.sortCol = col;
          st.sortDir = 'asc';
        }
        ths.forEach(function(t) {
          const i = t.querySelector('.pc-sort-icon');
          if (i) i.className = 'fa-solid fa-sort text-muted ms-1 pc-sort-icon';
        });
        const icon = th.querySelector('.pc-sort-icon');
        if (icon) icon.className = 'fa-solid fa-sort-' + (st.sortDir === 'asc' ? 'up' : 'down')
          + ' text-primary ms-1 pc-sort-icon';
        st.page = 1;
        fetchPage(tableId);
      });
    });

    // Largeur colonnes : applique les préférences user si disponibles
    table.style.tableLayout = 'fixed';
    const allThs = table.querySelectorAll('thead th');
    const savedWidths = (PC_CONFIG.tablePrefs && PC_CONFIG.tablePrefs[tableId] && PC_CONFIG.tablePrefs[tableId].widths) || {};
    allThs.forEach(function(th, idx) {
      const saved = savedWidths[idx];
      if (saved && parseInt(saved, 10) > 0) {
        th.style.width = parseInt(saved, 10) + 'px';
      } else if (!th.style.width) {
        th.style.width = th.offsetWidth + 'px';
      }
      th.style.position = 'relative';
      const handle = document.createElement('span');
      handle.className = 'pc-col-resizer';
      handle.innerHTML = '&nbsp;';
      th.appendChild(handle);

      let startX = 0, startW = 0;
      handle.addEventListener('mousedown', function(e) {
        e.preventDefault();
        e.stopPropagation();
        startX = e.pageX;
        startW = th.offsetWidth;
        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';

        function onMove(ev) {
          const w = Math.max(40, startW + (ev.pageX - startX));
          th.style.width = w + 'px';
        }
        function onUp() {
          document.removeEventListener('mousemove', onMove);
          document.removeEventListener('mouseup', onUp);
          document.body.style.cursor = '';
          document.body.style.userSelect = '';
          // Persiste les largeurs
          saveColumnWidths(tableId);
        }
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
      });
      handle.addEventListener('click', function(e) { e.stopPropagation(); });
    });

    // Fetch initial
    fetchPage(tableId);
  }

  function saveColumnWidths(tableId) {
    const table = getTable(tableId);
    if (!table) return;
    const widths = {};
    table.querySelectorAll('thead th').forEach(function(th, idx) {
      widths[idx] = parseInt(th.offsetWidth, 10) || 0;
    });
    // POST + jeton CSRF en en-tête (pcPost). Pour les requêtes AJAX, GLPI 11 garde
    // le jeton valide (preserve_token) : il n'est pas consommé par cet appel.
    const fd = new FormData();
    fd.append('table_id', tableId);
    fd.append('prefs', JSON.stringify({ widths: widths }));
    pcPost(AJAX_BASE + '/save_table_prefs.php', fd).catch(function(err) {
      console.warn('Print Gestion : préférences de colonnes non enregistrées', err);
    });
  }

  // Debounce helper pour la recherche
  function debounce(fn, ms) {
    let t = null;
    return function() {
      const args = arguments;
      if (t) clearTimeout(t);
      t = setTimeout(function() { fn.apply(null, args); }, ms);
    };
  }

  // Handlers globaux search + perPage
  document.querySelectorAll('.pc-table-search').forEach(function(input) {
    const tableId = input.getAttribute('data-pc-table-target');
    const debouncedFetch = debounce(function() {
      const st = tableStates[tableId];
      if (!st) return;
      st.search = input.value;
      st.page = 1;
      fetchPage(tableId);
    }, 300);
    input.addEventListener('input', debouncedFetch);
  });

  document.querySelectorAll('.pc-table-perpage').forEach(function(select) {
    const tableId = select.getAttribute('data-pc-table-target');
    select.addEventListener('change', function() {
      const st = tableStates[tableId];
      if (!st) return;
      st.perPage = parseInt(this.value, 10) || 25;
      st.page = 1;
      fetchPage(tableId);
    });
  });

  function initAllTables() {
    document.querySelectorAll('table[data-pc-ajax="1"]').forEach(function(t) {
      if (window.PC_TABLE_CONFIG[t.id] && !tableStates[t.id]) {
        initTable(t.id);
      }
    });
  }

  // Init différée : les scripts de config des dashboards sont inline APRÈS
  // ce script partagé, donc on attend DOMContentLoaded pour que PC_TABLE_CONFIG
  // soit peuplé avant de lancer les fetch.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAllTables);
  } else {
    // DOM déjà prêt → laisse tourner la pile d'événements pour que les <script>
    // inline qui suivent aient fini d'exécuter.
    setTimeout(initAllTables, 0);
  }

  // Exposé : permet aux dashboards de forcer un refetch + init à la demande.
  window.PC_TABLE = {
    refresh: function(tableId) {
      const st = tableStates[tableId];
      if (!st) { initTable(tableId); return; }
      st.page = 1;
      fetchPage(tableId);
    },
    init: function(tableId) {
      if (!tableStates[tableId]) initTable(tableId);
    },
  };

})();
</script>
HTML;
    }

    static function install(Migration $migration) { return true; }
    static function uninstall(Migration $migration) { return true; }
}
