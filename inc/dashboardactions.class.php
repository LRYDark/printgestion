<?php
/**
 * PluginPrintgestionDashboardactions — assets partagés pour le context-menu
 * des dashboards Print Gestion (Expéditions, Coût à la page). L'écran des alertes toner
 * utilise le moteur de recherche natif et ses actions de masse (PluginPrintgestionAlertview).
 *
 * Fournit :
 *   - Le HTML des modals (modifier expédition, associer des BL)
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
     * Rend le HTML des modals + le context-menu + le JS.
     * À appeler UNE FOIS en fin de page (avant Html::footer()).
     *
     * @param string $context 'expeditions' | 'billing' — détermine quelles actions du
     *   clic-droit sont affichées :
     *     expeditions : modifier l'expédition, BL, fiche imprimante
     *     billing     : uniquement "Ouvrir la fiche imprimante"
     */
    public static function renderSharedAssets(string $context = 'expeditions'): void {
        $can_expedition_update = Session::haveRight('plugin_printgestion_expedition', UPDATE);
        $ajax_base = PLUGIN_PRINTGESTION_WEBDIR . '/ajax';

        // Lien BL : déduit de l'état du plugin Gestion, jamais réglé.
        $bl_enabled = false;
        try {
            $bl_enabled = PluginPrintgestionTracking::isGestionLinkActive();
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
        if ($context === 'expeditions') {
            self::renderEditExpeditionModal();
            if ($bl_enabled) {
                self::renderLinkBlModal();
            }
        }

        self::renderJs($ajax_base, $can_expedition_update, $bl_enabled);
    }

    protected static function renderContextMenu(bool $can_update, bool $bl_enabled = false, string $context = 'expeditions'): void {
        // Actions autorisées par contexte (déjà filtrées côté PHP — pas besoin
        // de tout rendre puis de cacher en JS, ça allège le DOM).
        $allowed = [
            'expeditions' => ['edit-expedition', 'link-bl', 'open-printer'],
            'billing'     => ['open-printer'],
        ];
        $ctx_actions = $allowed[$context] ?? $allowed['billing'];

        $items = [
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
</style>
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
            'installed'   => __('Posée — confirmer la pose (clôt l\'envoi)', 'printgestion'),
            'cancelled'   => __('Annulée (clôt l\'envoi, sans suppression)', 'printgestion'),
        ];
        $statut_html = '';
        foreach ($statut_opts as $val => $lab) {
            $statut_html .= "<option value='{$val}'>" . htmlspecialchars($lab, ENT_QUOTES, 'UTF-8') . "</option>";
        }

        $carrier_opts = ['' => __('—', 'printgestion')] + PluginPrintgestionExpedition::getCarrierLabels();
        $carrier_html = '';
        foreach ($carrier_opts as $val => $lab) {
            $carrier_html .= "<option value='{$val}'>" . htmlspecialchars($lab, ENT_QUOTES, 'UTF-8') . "</option>";
        }
        // Chronopost n'est plus proposé mais une expédition ancienne peut le porter : option cachée, sélectionnable par le script seulement.
        $carrier_html .= "<option value='chronopost' hidden>Chronopost</option>";

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

    protected static function renderLinkBlModal(): void {
        $title   = __('Associer des BL (plugin Gestion)', 'printgestion');
        $close   = _sx('button', 'Close');
        $save    = _sx('button', 'Save');
        $intro   = __('Tape un numéro de BL (ex : BL000123) puis Entrée. Les BL SAGE sont vérifiés automatiquement. Dès qu\'un BL associé est signé côté client, l\'expédition passera en livrée.', 'printgestion');
        $label   = __('Tape le numéro de document (ex : BL000456), puis Entrée', 'printgestion');
        $placeholder = __('Tape au moins 2 caractères — ex: BL203...', 'printgestion');

        $labels = [
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
        ];

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

        echo PluginPrintgestionUi::jsonData('pc-linkbl-labels', $labels);
        echo "<script>
document.addEventListener('DOMContentLoaded', function() {
    var l = JSON.parse(document.getElementById('pc-linkbl-labels').textContent);
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
                        expedition_id: document.getElementById('pc-linkbl-expid')
                            ? (document.getElementById('pc-linkbl-expid').value || '')
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
                data: {
                    q: data.id,
                    expedition_id: document.getElementById('pc-linkbl-expid') ? (document.getElementById('pc-linkbl-expid').value || '') : ''
                },
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
        // Toutes les valeurs passées au JS passent par PluginPrintgestionUi::jsonData().
        global $CFG_GLPI;
        $root_doc = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');

        $js_config = [
            'canUpdate'  => (bool)$can_update,
            'blEnabled'  => (bool)$bl_enabled,
            'ajaxBase'   => $ajax_base,
            'rootDoc'    => $root_doc,
            'csrf'       => Session::getNewCSRFToken(),
            'msg'       => [
                'error'             => __("Erreur lors de l'action", 'printgestion'),
                'no_bl_available'   => __('Aucun BL disponible pour ce client', 'printgestion'),
                'level_label'       => __('Niveau actuel', 'printgestion'),
                'days_label'        => __('Jours restants estimés', 'printgestion'),
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
            ],
        ];

        // Configuration lue par le JS statique ci-dessous : bloc de données, jamais un script exécuté.
        echo PluginPrintgestionUi::jsonData('pc-config', $js_config, 'PC_CONFIG') . "\n";
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

    const hasExp = tr.getAttribute('data-pc-has-expedition') === '1';

    // Helper show/hide : les menu items ont la classe Bootstrap "d-block" qui
    // applique `display: block !important` → setProperty avec priorité important.
    function setShown(el, shown) {
      el.style.setProperty('display', shown ? 'block' : 'none', 'important');
    }

    menu.querySelectorAll('[data-pc-require]').forEach(function(el) {
      setShown(el, el.getAttribute('data-pc-require') === 'has-exp' ? hasExp : true);
    });

    if (!CAN_UPDATE) {
      menu.querySelectorAll('[data-pc-action="edit-expedition"],[data-pc-action="link-bl"]')
        .forEach(function(el) { el.style.setProperty('display', 'none', 'important'); });
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
    const d = {
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
    };

    if (act === 'open-printer') {
      window.location.href = window.PC_CONFIG.rootDoc
        + '/front/printer.form.php?id=' + encodeURIComponent(d.printers_id);
      return;
    }
    if (act === 'edit-expedition') { openEditExpModal(d); return; }
    if (act === 'link-bl')         { openLinkBlModal(d);  return; }
  });

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
          else { alert((data && data.error) ? data.error : MSG.error); }
        })
        .catch(function() { alert(MSG.error); });
    });
  }

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

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
      return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
    });
  }

})();
</script>
HTML;
    }

    static function install(Migration $migration) { return true; }
    static function uninstall(Migration $migration) { return true; }
}
