<?php
include('../../../inc/includes.php');

global $DB; // fichier front chargé hors portée globale (LegacyFileLoadController)

Session::checkLoginUser();
Session::checkRight('plugin_printgestion_dashboard', READ);
if (!PluginPrintgestionConfig::isFeatureEnabled('toner')) { Html::displayNotFoundError(); }

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    Html::displayNotFoundError();
}

Html::header(
    __('Print Gestion — Alertes toner', 'printgestion'),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginPrintgestionMenu'
);

$entities_id = (isset($_GET['entities_id']) && $_GET['entities_id'] !== '' && (int)$_GET['entities_id'] >= 0)
    ? (int)$_GET['entities_id']
    : null;

$filter_status = $_GET['status'] ?? 'critical_watch';
if (!in_array($filter_status, ['all', 'critical_watch', 'critical', 'watch', 'expeditions'], true)) {
    $filter_status = 'critical_watch';
}

echo "<div class='container-fluid mt-3'>";

PluginPrintgestionMenu::showTabBar('tn_alerts', true);

// ── Filtres (client + statut) ────────────────────────────────────────────────
echo "<form method='get' class='card mb-3' id='pc-filters-alerts'>";
echo "<div class='card-body'>";
echo "<div class='row g-3 align-items-end'>";

echo "<div class='col-md-4'><label class='form-label mb-1'>" . __('Client', 'printgestion') . "</label>";
Entity::dropdown([
    'name'                => 'entities_id',
    'value'               => $entities_id ?? -1,
    'display_emptychoice' => true,
    'emptylabel'          => __('Tous', 'printgestion'),
]);
echo "</div>";

echo "<div class='col-md-4'><label class='form-label mb-1'>" . __('Statut', 'printgestion') . "</label>";
Dropdown::showFromArray('status', [
    'critical_watch' => __('Critique + Mauvais (défaut)', 'printgestion'),
    'critical'       => __('Critique uniquement', 'printgestion'),
    'watch'          => __('Mauvais uniquement', 'printgestion'),
    'expeditions'    => __('Avec expédition', 'printgestion'),
    'all'            => __('Tous', 'printgestion'),
], ['value' => $filter_status]);
echo "</div>";

echo "<div class='col-md-4'>"
    . "<button type='submit' class='btn btn-primary w-100'><i class='fa-solid fa-magnifying-glass me-1'></i>"
    . _sx('button', 'Search') . "</button></div>";

echo "</div></div></form>";

// ── Barre de stats (compteurs peuplés en JS via onMetrics) ───────────────────
// Le tiret est le repli affiché tant que la première requête AJAX n'a pas répondu.
$metric_cards = [];
foreach ([
    ['critical',    __('Critique', 'printgestion'),              'ti ti-alert-triangle', 'red'],
    ['watch',       __('Mauvais', 'printgestion'),               'ti ti-eye',            'orange'],
    ['active_exp',  __('Expéditions en cours', 'printgestion'),  'ti ti-truck',          'azure'],
    ['stock_empty', __('Stock épuisé', 'printgestion'),          'ti ti-package-off',    'secondary'],
] as $m) {
    $metric_cards[] = [
        'count'       => '—',
        'label'       => $m[1],
        'icon'        => $m[2],
        'color'       => $m[3],
        'count_attrs' => ['data-pc-metric' => $m[0]],
    ];
}
PluginPrintgestionUi::statsBar($metric_cards, 'pc-metrics-alerts');

// ── Tableau groupé (1 ligne / imprimante, toners empilés) — moteur PC_TABLE ───
// Bouton « Commander la sélection » (visible dès qu'au moins 1 imprimante cochée).
echo "<div class='mb-2'><button type='button' class='btn btn-primary btn-sm' id='pg-cmd-btn' style='display:none'>"
    . "<i class='fa-solid fa-cart-shopping me-1'></i>" . __('Commander la sélection', 'printgestion')
    . " (<span class='pg-cmd-count'>0</span>)</button></div>";

PluginPrintgestionDashboardactions::renderTableToolbar('pc-tbl-alerts');

echo "<table id='pc-tbl-alerts' class='tab_cadre_fixehov' data-pc-ajax='1' style='width:100%'>";
echo "<thead><tr class='noHover'>";
echo "<th style='width:34px' class='text-center'><input type='checkbox' id='pg-alerts-selectall' title='" . __('Tout sélectionner', 'printgestion') . "'></th>";
echo "<th class='pc-sortable' data-pc-sort-col='printer_name'>" . __('Imprimante', 'printgestion') . "</th>";
echo "<th class='pc-sortable' data-pc-sort-col='entity_name'>" . __('Client', 'printgestion') . "</th>";
echo "<th>" . __('Toner', 'printgestion') . "</th>";
echo "<th>" . __('Niveau', 'printgestion') . "</th>";
echo "<th class='pc-sortable' data-pc-sort-col='min_days'>" . __('Temps estimé', 'printgestion') . "</th>";
echo "<th class='pc-sortable' data-pc-sort-col='worst_status'>" . __('Statut', 'printgestion') . "</th>";
echo "<th>" . __('Expédition', 'printgestion') . "</th>";
echo "</tr></thead><tbody></tbody></table>";

PluginPrintgestionDashboardactions::renderTablePagination('pc-tbl-alerts');

echo "<p class='text-muted small mt-2'><i class='fa-solid fa-circle-info me-1'></i>"
    . __('Clic droit sur une ligne pour afficher les actions disponibles (envoyer, modifier, snoozer, etc.)', 'printgestion')
    . "</p>";

echo "</div>"; // container-fluid

// Modale récap de commande (cartouches décochables) — alimentée par PG_openPurchaseModal.
echo "<div class='modal fade' id='pg-cmd-modal' tabindex='-1'><div class='modal-dialog modal-lg modal-dialog-scrollable'><div class='modal-content'>"
    . "<div class='modal-header'><h5 class='modal-title'><i class='fa-solid fa-cart-shopping me-2'></i>"
    . __('Commander des cartouches', 'printgestion') . "</h5>"
    . "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button></div>"
    . "<div class='modal-body'><p class='text-muted small mb-2'>"
    . __('Décoche les cartouches à exclure. À la confirmation, un mail part aux achats avec le fichier Excel (toi en copie).', 'printgestion')
    . "</p><div id='pg-cmd-modal-body'></div>"
    . "<hr class='my-2'>"
    . "<div class='form-check'><input type='checkbox' class='form-check-input' id='pg-cmd-planif'>"
    . "<label class='form-check-label' for='pg-cmd-planif'>"
    . __('Prévenir la planification (logistique) — pré-cochée si en stock', 'printgestion') . "</label></div>"
    . "<div class='form-check'><input type='checkbox' class='form-check-input' id='pg-cmd-courtesy' checked>"
    . "<label class='form-check-label' for='pg-cmd-courtesy'>"
    . __('Envoyer un mail de courtoisie au client', 'printgestion') . "</label></div>"
    . "</div>"
    . "<div class='modal-footer'>"
    . "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>" . __('Annuler', 'printgestion') . "</button>"
    . "<button type='button' class='btn btn-primary' id='pg-cmd-confirm'><i class='fa-solid fa-paper-plane me-1'></i>"
    . __('Envoyer la commande', 'printgestion') . "</button>"
    . "</div></div></div></div>";

// Menu clic droit + modales (Voir stock, Envoyer cartouche, Snoozer, Modifier
// expédition, Associer BL) + moteur de tableau PC_TABLE + PC_CONFIG.
PluginPrintgestionDashboardactions::renderSharedAssets('alerts');

$labels = [
    'critical'   => __('Critique', 'printgestion'),
    'watch'      => __('Mauvais', 'printgestion'),
    'ok'         => __('Bon', 'printgestion'),
    'jour'       => __('jour', 'printgestion'),
    'jours'      => __('jours', 'printgestion'),
    'na'         => 'N/A',
    'stable'           => __('Stable', 'printgestion'),
    'tooltip_stable'   => __('Pas d\'activité récente détectée (compteur de pages inchangé)', 'printgestion'),
    'tooltip_estimate' => __('Estimation basée sur le yield par défaut (pas assez d\'historique pour mesurer le yield réel de cette cartouche)', 'printgestion'),
    'tooltip_measured' => __('Estimation basée sur yield mesuré + vitesse d\'impression réelle', 'printgestion'),
    'pending'    => __('En attente', 'printgestion'),
    'shipped'    => __('Expédiée', 'printgestion'),
    'transit'    => __('En transit', 'printgestion'),
    'delivered'  => __('Livrée', 'printgestion'),
    'stock_empty'=> __('Stock vide', 'printgestion'),
    'empty'      => __('Aucune alerte', 'printgestion'),
    'snoozed_tip'=> __('Alertes désactivées (snooze actif)', 'printgestion'),
];
$labels_json = json_encode($labels, JSON_UNESCAPED_UNICODE);

echo <<<HTML
<script>
(function() {
  const L = {$labels_json};
  const rootDoc = window.PC_CONFIG.rootDoc;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }
  function printerUrl(id) { return rootDoc + '/front/printer.form.php?id=' + encodeURIComponent(id); }

  function statusBadge(s) {
    if (s === 'critical') return '<span class="badge bg-danger">' + esc(L.critical) + '</span>';
    if (s === 'watch')    return '<span class="badge bg-warning text-dark">' + esc(L.watch) + '</span>';
    return '<span class="badge bg-success">' + esc(L.ok) + '</span>';
  }

  function expeditionBadge(exp) {
    if (!exp) return '—';
    const labels = { pending: L.pending, shipped: L.shipped, transit: L.transit, delivered: L.delivered, stock_empty: L.stock_empty };
    const cls    = { pending: 'bg-secondary', shipped: 'bg-primary', transit: 'bg-info', delivered: 'bg-success', stock_empty: 'text-bg-dark' };
    const lbl = labels[exp.statut] || exp.statut;
    const cl  = cls[exp.statut] || 'bg-secondary';
    let days = 0;
    if (exp.date_alert) {
      const t = new Date(String(exp.date_alert).replace(' ', 'T'));
      days = Math.max(0, Math.floor((Date.now() - t.getTime()) / 86400000));
    }
    return '<span class="badge ' + cl + '">' + esc(lbl) + '</span> <small class="text-muted">(' + days + 'j)</small>';
  }

  function bar(row) {
    const w = Math.max(0, Math.min(100, parseInt(row.level, 10) || 0));
    const color = esc(row.toner_color || 'other');
    return '<div class="printgestion-bar color-' + color + '" title="' + w + '%">'
         + '<span style="width:' + w + '%"></span></div><small>' + w + '%</small>';
  }

  // SVG inline au lieu de Font Awesome : box carrée parfaite 1em × 1em,
  // cercle qui remplit tout le viewBox → centre SVG = centre cercle.
  const SVG_CHECK = '<svg viewBox="0 0 512 512" width="1em" height="1em" fill="currentColor">'
    + '<path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zM369 209L241 337c-9.4 9.4-24.6 9.4-33.9 0l-64-64c-9.4-9.4-9.4-24.6 0-33.9s24.6-9.4 33.9 0l47 47L335 175c9.4-9.4 24.6-9.4 33.9 0s9.4 24.6 0 33.9z"/>'
    + '</svg>';
  const SVG_INFO = '<svg viewBox="0 0 512 512" width="1em" height="1em" fill="currentColor">'
    + '<path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zM216 336l24 0 0-64-24 0c-13.3 0-24-10.7-24-24s10.7-24 24-24l48 0c13.3 0 24 10.7 24 24l0 88 8 0c13.3 0 24 10.7 24 24s-10.7 24-24 24l-80 0c-13.3 0-24-10.7-24-24s10.7-24 24-24zm40-208a32 32 0 1 1 0 64 32 32 0 1 1 0-64z"/>'
    + '</svg>';

  function tipIcon(kind, colorCls, title, isCritical) {
    const svg = (kind === 'check') ? SVG_CHECK : SVG_INFO;
    const cls = 'pc-tip-icon ' + colorCls + (isCritical ? ' pc-pulse' : '');
    return '<span class="' + cls + '" title="' + esc(title) + '">' + svg + '</span>';
  }

  function daysTxt(row) {
    const isCritical = row.status === 'critical';
    const colorCls = isCritical
      ? 'text-danger'
      : (row.is_estimate ? 'text-warning' : 'text-success');
    const kindWhenOk = row.is_estimate ? 'info' : 'check';
    const kind = isCritical ? 'info' : kindWhenOk;

    if (row.days_remaining == null) {
      return '<span class="text-muted">' + esc(L.stable) + ' '
        + tipIcon('info', colorCls, L.tooltip_stable, isCritical)
        + '</span>';
    }
    const d = parseInt(row.days_remaining, 10);
    const lbl = d + ' ' + (d > 1 ? L.jours : L.jour);
    const prefix = row.is_estimate ? '~ ' : '';
    const tip    = row.is_estimate ? L.tooltip_estimate : L.tooltip_measured;
    return '<span>' + prefix + esc(lbl) + ' '
      + tipIcon(kind, colorCls, tip, isCritical)
      + '</span>';
  }

  // Mini-item d'une cellule empilée (hauteur fixe → alignement entre colonnes).
  function stackItemProperty(c) {
    const snoozeIcon = c.snoozed
      ? ' <i class="fa-solid fa-bell-slash text-muted ms-1" title="' + esc(L.snoozed_tip || 'Alertes désactivées') + '"></i>'
      : '';
    const titleAttr = c.snoozed
      ? (esc(c.property) + ' (snoozé)')
      : esc(c.property);
    return '<div class="pc-stack-item pc-stack-prop text-truncate" title="' + titleAttr + '">'
      + '<span class="pc-color-dot color-' + esc(c.toner_color || 'other') + '"></span>'
      + esc(c.property) + snoozeIcon + '</div>';
  }
  function stackItemLevel(c) {
    return '<div class="pc-stack-item">' + bar(c) + '</div>';
  }
  function stackItemDays(c) {
    return '<div class="pc-stack-item">' + daysTxt(c) + '</div>';
  }
  function stackItemExpedition(c) {
    return '<div class="pc-stack-item">' + expeditionBadge(c.expedition) + '</div>';
  }

  function worstStatusBadge(row) {
    const s = row.worst_status;
    const n = (row.cartridges || []).length;
    let nCrit = 0, nWatch = 0;
    (row.cartridges || []).forEach(function(c) {
      if (c.status === 'critical') nCrit++;
      else if (c.status === 'watch') nWatch++;
    });
    let html = statusBadge(s);
    if (n > 1) {
      const summary = [];
      if (nCrit > 0)  summary.push(nCrit  + ' ' + L.critical);
      if (nWatch > 0) summary.push(nWatch + ' ' + L.watch);
      if (summary.length) {
        html += ' <small class="text-muted d-block">' + esc(summary.join(' · ')) + '</small>';
      }
    }
    return html;
  }

  window.PC_TABLE_CONFIG = window.PC_TABLE_CONFIG || {};
  window.PC_TABLE_CONFIG['pc-tbl-alerts'] = {
    endpoint: 'list_alerts.php',
    emptyColspan: 8,
    emptyLabel: L.empty,
    extraParams: function() {
      const f = document.getElementById('pc-filters-alerts');
      if (!f) return { grouped: 1 };
      return {
        status:      f.querySelector('[name=status]')      ? f.querySelector('[name=status]').value : '',
        entities_id: f.querySelector('[name=entities_id]') ? f.querySelector('[name=entities_id]').value : '',
        grouped:     1,
      };
    },
    renderRow: function(r) {
      const cartridges = r.cartridges || [];
      const tr = document.createElement('tr');
      tr.setAttribute('data-pc-row', '1');
      tr.setAttribute('data-pc-grouped',      '1');
      tr.setAttribute('data-pc-printers-id',  r.printers_id || '0');
      tr.setAttribute('data-pc-printer-name', r.printer_name || '');
      tr.setAttribute('data-pc-entity-name',  r.entity_name  || '');
      tr.setAttribute('data-pc-cartridges',   JSON.stringify(cartridges));
      tr.setAttribute('data-pc-has-expedition', r.has_expedition ? '1' : '0');

      // Compat menu contextuel : expose les champs de la 1ère cartouche au niveau
      // de la ligne pour "Modifier expédition" / "Associer BL" quand il n'y a
      // qu'une seule expédition active.
      const expCartridges = cartridges.filter(function(c) { return c.expedition; });
      if (expCartridges.length === 1) {
        const c = expCartridges[0];
        tr.setAttribute('data-pc-property',      c.property || '');
        tr.setAttribute('data-pc-level',         c.level    || '0');
        tr.setAttribute('data-pc-days',          c.days_remaining != null ? c.days_remaining : '0');
        tr.setAttribute('data-pc-cartridge',     c.cartridge_type || '');
        tr.setAttribute('data-pc-expedition-id', c.expedition.id || '0');
        tr.setAttribute('data-pc-exp-statut',    c.expedition.statut || '');
        tr.setAttribute('data-pc-exp-carrier',   c.expedition.transport_carrier || '');
        tr.setAttribute('data-pc-exp-tracking',  c.expedition.transport_number || '');
      }

      const cellProperty   = cartridges.map(stackItemProperty).join('');
      const cellLevel      = cartridges.map(stackItemLevel).join('');
      const cellDays       = cartridges.map(stackItemDays).join('');
      const cellExpedition = cartridges.map(stackItemExpedition).join('');

      tr.innerHTML =
          '<td class="text-center"><input type="checkbox" class="pg-rowcheck"></td>'
        + '<td><a href="' + esc(printerUrl(r.printers_id)) + '">' + esc(r.printer_name) + '</a></td>'
        + '<td>' + esc(r.entity_name) + '</td>'
        + '<td class="pc-stack-cell">' + cellProperty + '</td>'
        + '<td class="pc-stack-cell">' + cellLevel + '</td>'
        + '<td class="pc-stack-cell">' + cellDays + '</td>'
        + '<td>' + worstStatusBadge(r) + '</td>'
        + '<td class="pc-stack-cell">' + cellExpedition + '</td>';
      return tr;
    },
    onMetrics: function(m) {
      const box = document.getElementById('pc-metrics-alerts');
      if (!box) return;
      Object.keys(m).forEach(function(k) {
        const el = box.querySelector('[data-pc-metric="' + k + '"]');
        if (el) el.textContent = m[k];
      });
    },
  };

  const f = document.getElementById('pc-filters-alerts');
  if (f) {
    f.addEventListener('submit', function(e) {
      e.preventDefault();
      if (window.PC_TABLE) window.PC_TABLE.refresh('pc-tbl-alerts');
    });
  }

  // ── Sélection multi-imprimantes + commande (mail achats + Excel joint) ──────
  const tableEl = document.getElementById('pc-tbl-alerts');
  let lastIndex = -1;

  function rowChecks() {
    if (!tableEl) return [];
    return Array.prototype.slice.call(tableEl.querySelectorAll('tbody .pg-rowcheck'));
  }
  function selectedRows() {
    return rowChecks().filter(function(cb) { return cb.checked; })
      .map(function(cb) { return cb.closest('tr'); })
      .filter(function(tr) { return tr && tr.getAttribute('data-pc-printers-id'); });
  }
  function updateCmdBtn() {
    const n = selectedRows().length;
    const btn = document.getElementById('pg-cmd-btn');
    if (!btn) return;
    btn.style.display = n > 0 ? '' : 'none';
    const c = btn.querySelector('.pg-cmd-count');
    if (c) c.textContent = n;
  }

  if (tableEl) {
    // Clic sur une case → sélection (Maj = plage depuis la dernière).
    tableEl.addEventListener('click', function(e) {
      const cb = e.target.closest('.pg-rowcheck');
      if (!cb) return;
      const checks = rowChecks();
      const idx = checks.indexOf(cb);
      if (e.shiftKey && lastIndex >= 0 && idx >= 0) {
        const a = Math.min(lastIndex, idx), b = Math.max(lastIndex, idx);
        for (let i = a; i <= b; i++) checks[i].checked = cb.checked;
      }
      lastIndex = idx;
      updateCmdBtn();
    });
    // Ctrl/Maj + clic sur la ligne (hors lien/case) → toggle la case.
    tableEl.addEventListener('click', function(e) {
      if (e.target.closest('a, input, button')) return;
      if (!(e.ctrlKey || e.metaKey || e.shiftKey)) return;
      const tr = e.target.closest('tbody tr[data-pc-printers-id]');
      if (!tr) return;
      const cb = tr.querySelector('.pg-rowcheck');
      if (!cb) return;
      cb.checked = !cb.checked;
      lastIndex = rowChecks().indexOf(cb);
      updateCmdBtn();
    });
    // Re-évalue le bouton après chaque rechargement du tableau (lignes recréées).
    const tb = tableEl.querySelector('tbody');
    if (tb && window.MutationObserver) {
      new MutationObserver(updateCmdBtn).observe(tb, { childList: true });
    }
  }

  const selectAll = document.getElementById('pg-alerts-selectall');
  if (selectAll) {
    selectAll.addEventListener('change', function() {
      rowChecks().forEach(function(cb) { cb.checked = selectAll.checked; });
      updateCmdBtn();
    });
  }

  // Collecte les cartouches d'un ensemble de lignes <tr>.
  function collectItems(rows) {
    const items = [];
    rows.forEach(function(tr) {
      const pid = tr.getAttribute('data-pc-printers-id');
      const pname = tr.getAttribute('data-pc-printer-name') || '';
      let carts = [];
      try { carts = JSON.parse(tr.getAttribute('data-pc-cartridges') || '[]') || []; } catch (err) { carts = []; }
      carts.forEach(function(c) {
        if (!c || !c.property) return;
        items.push({
          printers_id:  pid,
          printer_name: pname,
          property:     c.property,
          level:        (c.level != null ? c.level : 0),
          days:         (c.days_remaining != null ? c.days_remaining : null),
          cartridge:    (c.cartridge_type || c.property),
          stock:        (c.stock != null ? c.stock : 0),
        });
      });
    });
    return items;
  }

  // Ouvre la modale récap. `d` = ligne du clic droit (ou null pour le bouton).
  // Si une sélection existe → on prend la sélection ; sinon la ligne `d`.
  window.PG_openPurchaseModal = function(d) {
    if (!tableEl) return;
    let rows = selectedRows();
    if (rows.length === 0 && d && d.printers_id) {
      const tr = tableEl.querySelector('tbody tr[data-pc-printers-id="' + d.printers_id + '"]');
      if (tr) rows = [tr];
    }
    const items = collectItems(rows);

    // Pré-coche « Planif » si au moins une cartouche est en stock ; courtoisie par défaut cochée.
    const planifCb = document.getElementById('pg-cmd-planif');
    if (planifCb) planifCb.checked = items.some(function(it) { return (parseInt(it.stock, 10) || 0) > 0; });
    const courtesyCb = document.getElementById('pg-cmd-courtesy');
    if (courtesyCb) courtesyCb.checked = true;

    const body = document.getElementById('pg-cmd-modal-body');
    if (!body) return;

    if (items.length === 0) {
      body.innerHTML = '<div class="alert alert-warning mb-0">' + esc(L.empty || 'Aucune cartouche') + '</div>';
    } else {
      const byPrinter = {};
      items.forEach(function(it) { (byPrinter[it.printer_name] = byPrinter[it.printer_name] || []).push(it); });
      let html = '';
      Object.keys(byPrinter).forEach(function(pname) {
        html += '<div class="mb-2"><strong>' + esc(pname) + '</strong><ul class="list-unstyled ms-3 mb-1">';
        byPrinter[pname].forEach(function(it) {
          html += '<li><label class="d-flex align-items-center gap-2 mb-1">'
                + '<input type="checkbox" class="form-check-input pg-cmd-item m-0" checked '
                + 'data-pid="' + esc(it.printers_id) + '" data-prop="' + esc(it.property) + '" '
                + 'data-level="' + esc(it.level) + '" data-days="' + (it.days != null ? esc(it.days) : '') + '">'
                + '<span>' + esc(it.cartridge) + ' — ' + esc(it.property) + ' (' + esc(it.level) + '%)</span>'
                + '</label></li>';
        });
        html += '</ul></div>';
      });
      body.innerHTML = html;
    }
    if (typeof bootstrap !== 'undefined') {
      new bootstrap.Modal(document.getElementById('pg-cmd-modal')).show();
    }
  };

  const cmdBtn = document.getElementById('pg-cmd-btn');
  if (cmdBtn) cmdBtn.addEventListener('click', function() { window.PG_openPurchaseModal(null); });

  const confirmBtn = document.getElementById('pg-cmd-confirm');
  if (confirmBtn) {
    confirmBtn.addEventListener('click', function() {
      const checks = Array.prototype.slice.call(document.querySelectorAll('#pg-cmd-modal-body .pg-cmd-item:checked'));
      if (checks.length === 0) return;
      const items = checks.map(function(cb) {
        return {
          printers_id: cb.getAttribute('data-pid'),
          property:    cb.getAttribute('data-prop'),
          level:       cb.getAttribute('data-level'),
          days:        cb.getAttribute('data-days') || null,
        };
      });
      const orig = confirmBtn.innerHTML;
      confirmBtn.disabled = true;
      confirmBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>';
      const planifCb = document.getElementById('pg-cmd-planif');
      const courtesyCb = document.getElementById('pg-cmd-courtesy');
      const fd = new FormData();
      fd.append('items_json', JSON.stringify(items));
      fd.append('send_planif', planifCb && planifCb.checked ? '1' : '0');
      fd.append('send_courtesy', courtesyCb && courtesyCb.checked ? '1' : '0');
      fetch(window.PC_CONFIG.ajaxBase + '/send_purchase.php', {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': window.PC_CONFIG.csrf },
      })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data && data.ok) { window.location.reload(); }
          else { confirmBtn.disabled = false; confirmBtn.innerHTML = orig; alert('Erreur lors de la commande'); }
        })
        .catch(function() { confirmBtn.disabled = false; confirmBtn.innerHTML = orig; alert('Erreur lors de la commande'); });
    });
  }

  updateCmdBtn();
})();
</script>
HTML;

Html::footer();
