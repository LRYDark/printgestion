<?php
/**
 * PluginPrintgestionPrinterCostsTab — Point 2 : onglet "Coût à la page" sur fiche imprimante.
 * Lit glpi_printerlogs (itemtype='Printer', items_id=printer_id).
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionPrinterCostsTab extends CommonGLPI {

    static $rightname = 'printer';

    static function getTypeName($nb = 0) {
        return __('Coût à la page', 'printgestion');
    }

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item->getType() == 'Printer' && Session::haveRight('printer', READ)) {
            return __('Coût à la page', 'printgestion');
        }
        return '';
    }

    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        if ($item->getType() == 'Printer') {
            self::showCostTab($item);
        }
        return true;
    }

    static function showCostTab(Printer $printer) {
        $printers_id = (int)$printer->getID();

        // Période (via GET, avec validation stricte)
        $period = $_GET['period'] ?? 'current';
        if (!in_array($period, ['current', 'previous', 'custom'], true)) {
            $period = 'current';
        }
        $today  = new DateTime();

        switch ($period) {
            case 'previous':
                $start = (clone $today)->modify('first day of previous month')->format('Y-m-d');
                $end   = (clone $today)->modify('last day of previous month')->format('Y-m-d');
                break;
            case 'custom':
                $start = (isset($_GET['start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start']))
                    ? $_GET['start'] : date('Y-m-01');
                $end   = (isset($_GET['end']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end']))
                    ? $_GET['end'] : date('Y-m-d');
                break;
            case 'current':
            default:
                $start = (clone $today)->modify('first day of this month')->format('Y-m-d');
                $end   = $today->format('Y-m-d');
                break;
        }

        // Contrat lié + tarifs
        $contracts_id = PluginPrintgestionContractrate::getContractIdForPrinter($printers_id);
        $rates        = PluginPrintgestionContractrate::getRatesForContract($contracts_id);

        $contract_name = '—';
        if ($contracts_id > 0) {
            $c = new Contract();
            if ($c->getFromDB($contracts_id)) {
                $contract_name = (string)$c->fields['name'];
            }
        }

        $counters = self::getCountersForPeriod($printers_id, $start, $end);

        $delta_nb    = max(0, $counters['end_nb']    - $counters['start_nb']);
        $delta_color = max(0, $counters['end_color'] - $counters['start_color']);
        $cost        = $delta_nb * $rates['nb'] + $delta_color * $rates['color'];

        $ajax_url = PLUGIN_PRINTGESTION_WEBDIR . '/ajax/printer_costs.php';
        $uid = 'pc-cost-' . $printers_id;

        echo "<div class='card mt-3' id='{$uid}-card'>";
        echo "<div class='card-header'><h3 class='card-title mb-0'>"
            . __('Coût à la page', 'printgestion') . "</h3></div>";
        echo "<div class='card-body'>";

        // Formulaire période — submit JS (pas de rechargement de page pour ne pas
        // perdre les filtres à cause du système d'onglets AJAX de GLPI)
        echo "<form class='row g-2 mb-3' id='{$uid}-form' onsubmit='return false;'>";

        echo "<div class='col-md-3'><label class='form-label'>" . __('Période', 'printgestion') . "</label>";
        Dropdown::showFromArray('period', [
            'current'  => __('Mois en cours', 'printgestion'),
            'previous' => __('Mois précédent', 'printgestion'),
            'custom'   => __('Période libre', 'printgestion'),
        ], ['value' => $period]);
        echo "</div>";

        $dates_display = ($period === 'custom') ? 'block' : 'none';
        echo "<div class='col-md-3' id='{$uid}-col-start' style='display:{$dates_display}'>"
            . "<label class='form-label'>" . __('Début', 'printgestion') . "</label>";
        echo "<input type='date' class='form-control' name='start' value='"
            . htmlspecialchars($start, ENT_QUOTES, 'UTF-8') . "'></div>";
        echo "<div class='col-md-3' id='{$uid}-col-end' style='display:{$dates_display}'>"
            . "<label class='form-label'>" . __('Fin', 'printgestion') . "</label>";
        echo "<input type='date' class='form-control' name='end' value='"
            . htmlspecialchars($end, ENT_QUOTES, 'UTF-8') . "'></div>";
        echo "<div class='col-md-3 d-flex align-items-end'>"
            . "<button type='submit' class='btn btn-primary' id='{$uid}-submit'>"
            . _sx('button', 'Search') . "</button></div>";
        echo "</form>";

        echo "<table class='tab_cadre_fixehov' style='width:100%' id='{$uid}-table'>";
        echo "<tr><th style='width:40%'>" . __('Contrat lié', 'printgestion')
            . "</th><td data-pc-field='contract_name'>"
            . htmlspecialchars($contract_name, ENT_QUOTES, 'UTF-8') . "</td></tr>";
        echo "<tr><th>" . __('Tarif N&B', 'printgestion')
            . "</th><td data-pc-field='rate_nb'>"
            . number_format($rates['nb'], 6, ',', ' ') . " €/page</td></tr>";
        echo "<tr><th>" . __('Tarif Couleur', 'printgestion')
            . "</th><td data-pc-field='rate_color'>"
            . number_format($rates['color'], 6, ',', ' ') . " €/page</td></tr>";
        echo "<tr><th>" . __('Compteur N&B début → fin', 'printgestion')
            . "</th><td data-pc-field='counter_nb'>"
            . number_format($counters['start_nb'], 0, '', ' ') . " → "
            . number_format($counters['end_nb'], 0, '', ' ')
            . " <strong>(+" . number_format($delta_nb, 0, '', ' ') . ")</strong></td></tr>";
        echo "<tr><th>" . __('Compteur Couleur début → fin', 'printgestion')
            . "</th><td data-pc-field='counter_color'>"
            . number_format($counters['start_color'], 0, '', ' ') . " → "
            . number_format($counters['end_color'], 0, '', ' ')
            . " <strong>(+" . number_format($delta_color, 0, '', ' ') . ")</strong></td></tr>";
        echo "<tr class='tab_bg_2'><th>" . __('Coût total sur la période', 'printgestion')
            . "</th><td data-pc-field='cost'><strong style='font-size:1.3em;color:#1F4E79'>"
            . number_format($cost, 2, ',', ' ') . " €</strong></td></tr>";
        echo "</table>";
        echo "</div></div>";

        // ── Seuils personnalisés par imprimante (card dédiée) ─────────────
        global $DB;
        $th_row = $DB->request([
            'FROM'  => 'glpi_plugin_printgestion_printer_thresholds',
            'WHERE' => ['printers_id' => $printers_id],
            'LIMIT' => 1,
        ])->current();
        $config_plugin = PluginPrintgestionConfig::getInstance();
        $def_level     = (int)($config_plugin->fields['threshold_level'] ?? 15);
        $def_days      = (int)($config_plugin->fields['threshold_days']  ?? 30);
        $def_yield     = (int)($config_plugin->fields['default_pages_per_cartridge'] ?? 5000);
        $cur_level     = is_array($th_row) ? $th_row['threshold_level']     : null;
        $cur_days      = is_array($th_row) ? $th_row['threshold_days']      : null;
        $cur_yield     = is_array($th_row) ? $th_row['pages_per_cartridge'] : null;

        echo "<div class='card mt-3'>";
        echo "<div class='card-header'><h3 class='card-title mb-0'>"
            . "<i class='fa-solid fa-sliders me-2'></i>"
            . __('Seuils d\'alerte personnalisés', 'printgestion') . "</h3></div>";
        echo "<div class='card-body'>";
        echo "<p class='text-muted small mb-3'>"
            . __("Ces valeurs surchargent la configuration globale du plugin pour cette imprimante uniquement. "
                . "Laisse un champ vide pour utiliser la valeur par défaut (affichée en placeholder).", 'printgestion')
            . "</p>";
        echo "<form class='row g-3 align-items-start' id='{$uid}-th-form' onsubmit='return false;'>";

        echo "<div class='col-md-3'><label class='form-label mb-1'>"
            . __('Seuil niveau (%)', 'printgestion') . "</label>";
        echo "<input type='number' min='0' max='100' class='form-control' name='threshold_level' "
            . "value='" . htmlspecialchars((string)($cur_level ?? ''), ENT_QUOTES, 'UTF-8') . "' "
            . "placeholder='" . $def_level . "'>";
        echo "<small class='text-muted'>" . sprintf(__('Défaut global : %d', 'printgestion'), $def_level) . "</small></div>";

        echo "<div class='col-md-3'><label class='form-label mb-1'>"
            . __('Seuil jours estimés', 'printgestion') . "</label>";
        echo "<input type='number' min='0' class='form-control' name='threshold_days' "
            . "value='" . htmlspecialchars((string)($cur_days ?? ''), ENT_QUOTES, 'UTF-8') . "' "
            . "placeholder='" . $def_days . "'>";
        echo "<small class='text-muted'>" . sprintf(__('Défaut global : %d', 'printgestion'), $def_days) . "</small></div>";

        echo "<div class='col-md-3'><label class='form-label mb-1'>"
            . __('Yield (pages/cartouche)', 'printgestion') . "</label>";
        echo "<input type='number' min='100' class='form-control' name='pages_per_cartridge' "
            . "value='" . htmlspecialchars((string)($cur_yield ?? ''), ENT_QUOTES, 'UTF-8') . "' "
            . "placeholder='" . $def_yield . "'>";
        echo "<small class='text-muted'>" . sprintf(__('Défaut global : %d', 'printgestion'), $def_yield) . "</small></div>";

        // Bouton aligné sur la ligne des inputs via un label fantôme de même hauteur
        echo "<div class='col-md-3'>"
            . "<label class='form-label mb-1' style='visibility:hidden'>&nbsp;</label>"
            . "<button type='submit' class='btn btn-primary w-100' id='{$uid}-th-submit'>"
            . "<i class='fa-solid fa-save me-1'></i>" . _sx('button', 'Save') . "</button>"
            . "</div>";

        echo "</form>";
        echo "</div></div>";

        // JS : toggle dates selon période + submit AJAX + restauration sessionStorage
        $js_config = json_encode([
            'uid'            => $uid,
            'printers_id'    => $printers_id,
            'ajaxUrl'        => $ajax_url,
            'thresholdsUrl'  => PLUGIN_PRINTGESTION_WEBDIR . '/ajax/printer_thresholds.php',
            'initPeriod'     => $period,
            'initStart'      => $start,
            'initEnd'        => $end,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        echo "<script>window.PC_COST_INIT = {$js_config};</script>";
        echo <<<'HTML'
<script>
(function() {
  const cfg = window.PC_COST_INIT;
  if (!cfg) return;
  const card = document.getElementById(cfg.uid + '-card');
  if (!card) return;

  const form     = document.getElementById(cfg.uid + '-form');
  const periodEl = form.querySelector('[name=period]');
  const startEl  = form.querySelector('[name=start]');
  const endEl    = form.querySelector('[name=end]');
  const colStart = document.getElementById(cfg.uid + '-col-start');
  const colEnd   = document.getElementById(cfg.uid + '-col-end');
  const table    = document.getElementById(cfg.uid + '-table');

  const STORAGE_KEY = 'pc_cost_filter_' + cfg.printers_id;

  function toggleDates() {
    const p = periodEl.value;
    const show = (p === 'custom');
    colStart.style.display = show ? 'block' : 'none';
    colEnd.style.display   = show ? 'block' : 'none';
  }

  function computeBounds(period) {
    const now = new Date();
    if (period === 'previous') {
      const firstPrev = new Date(now.getFullYear(), now.getMonth() - 1, 1);
      const lastPrev  = new Date(now.getFullYear(), now.getMonth(), 0);
      return { start: fmt(firstPrev), end: fmt(lastPrev) };
    }
    if (period === 'current') {
      const first = new Date(now.getFullYear(), now.getMonth(), 1);
      return { start: fmt(first), end: fmt(now) };
    }
    return { start: startEl.value, end: endEl.value };
  }
  function fmt(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + dd;
  }

  function fmtInt(n)  { return Number(n || 0).toLocaleString('fr-FR'); }
  function fmtCost(n) { return Number(n || 0).toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' €'; }
  function fmtRate(n) { return Number(n || 0).toLocaleString('fr-FR', {minimumFractionDigits: 6, maximumFractionDigits: 6}) + ' €/page'; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

  function setField(name, html) {
    const el = table.querySelector('[data-pc-field="' + name + '"]');
    if (el) el.innerHTML = html;
  }

  function fetchAndRender() {
    const bounds = computeBounds(periodEl.value);
    if (periodEl.value === 'custom') {
      bounds.start = startEl.value;
      bounds.end   = endEl.value;
    } else {
      // Sync les inputs cachés pour cohérence lors du submit
      startEl.value = bounds.start;
      endEl.value   = bounds.end;
    }
    // Sauvegarde en sessionStorage pour restauration à la prochaine visite du tab
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
        period: periodEl.value, start: bounds.start, end: bounds.end,
      }));
    } catch (e) {}

    const url = cfg.ajaxUrl + '?printers_id=' + encodeURIComponent(cfg.printers_id)
              + '&start=' + encodeURIComponent(bounds.start)
              + '&end='   + encodeURIComponent(bounds.end);

    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(r => r.json())
      .then(d => {
        if (!d || !d.ok) return;
        setField('contract_name', esc(d.contract_name));
        setField('rate_nb',       esc(fmtRate(d.rate_nb)));
        setField('rate_color',    esc(fmtRate(d.rate_color)));
        setField('counter_nb',
          fmtInt(d.start_nb) + ' → ' + fmtInt(d.end_nb)
          + ' <strong>(+' + fmtInt(d.delta_nb) + ')</strong>');
        setField('counter_color',
          fmtInt(d.start_color) + ' → ' + fmtInt(d.end_color)
          + ' <strong>(+' + fmtInt(d.delta_color) + ')</strong>');
        setField('cost',
          '<strong style="font-size:1.3em;color:#1F4E79">' + fmtCost(d.cost) + '</strong>');
      })
      .catch(() => {});
  }

  // Restauration depuis sessionStorage si disponible
  try {
    const saved = sessionStorage.getItem(STORAGE_KEY);
    if (saved) {
      const s = JSON.parse(saved);
      if (s && s.period) {
        periodEl.value = s.period;
        if (s.period === 'custom') {
          if (s.start) startEl.value = s.start;
          if (s.end)   endEl.value   = s.end;
        }
        toggleDates();
        fetchAndRender();
      }
    }
  } catch (e) {}

  periodEl.addEventListener('change', toggleDates);
  if (typeof jQuery !== 'undefined') {
    jQuery(periodEl).on('change', toggleDates);
  }
  form.addEventListener('submit', function(e) {
    e.preventDefault();
    fetchAndRender();
  });
  toggleDates();

  // ── Seuils personnalisés : submit AJAX silencieux + reload (GLPI flash message) ──
  const thForm = document.getElementById(cfg.uid + '-th-form');
  if (thForm) {
    thForm.addEventListener('submit', function(e) {
      e.preventDefault();
      const fd = new FormData(thForm);
      fd.append('printers_id', cfg.printers_id);
      const qs = new URLSearchParams(fd);
      const btn = document.getElementById(cfg.uid + '-th-submit');
      if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>'; }

      fetch(cfg.thresholdsUrl + '?' + qs.toString(), {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })
        .then(r => r.json())
        .then(d => {
          if (d && d.ok) {
            // Reload immédiat : GLPI affichera la popup flash depuis la session
            window.location.reload();
          } else {
            alert('Erreur lors de la sauvegarde');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-save me-1"></i>Sauvegarder'; }
          }
        })
        .catch(function() {
          alert('Erreur réseau');
          if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-save me-1"></i>Sauvegarder'; }
        });
    });
  }
})();
</script>
HTML;
    }

    /**
     * Compteurs N&B / Couleur début et fin de période pour une imprimante.
     * GLPI 11 : glpi_printerlogs utilise `itemtype`='Printer' + `items_id`.
     */
    public static function getCountersForPeriod(int $printers_id, string $start, string $end): array {
        global $DB;

        $out = [
            'start_nb'    => 0,
            'end_nb'      => 0,
            'start_color' => 0,
            'end_color'   => 0,
        ];

        if ($printers_id <= 0) {
            return $out;
        }

        // Compteurs multi-sources : GLPI stocke brut ce que remonte l'agent, sans fusion.
        // Selon la marque : bw_pages direct (Canon, certains Ricoh) OU sous-compteurs
        // bw_prints+bw_copies (HP, Xerox) OU juste total_pages (inventaires partiels).
        // Logique prioritaire ci-dessous dans extract().
        $select = [
            'total_pages', 'bw_pages', 'color_pages',
            'bw_prints',   'color_prints',
            'bw_copies',   'color_copies',
            'date',
        ];

        // Compteur début : dernier log strictement avant start (fallback : premier log >= start)
        $rowStart = $DB->request([
            'SELECT' => $select,
            'FROM'   => 'glpi_printerlogs',
            'WHERE'  => [
                'itemtype' => 'Printer',
                'items_id' => $printers_id,
                'date'     => ['<', $start],
            ],
            'ORDER'  => ['date DESC'],
            'LIMIT'  => 1,
        ])->current();

        if (!is_array($rowStart)) {
            $rowStart = $DB->request([
                'SELECT' => $select,
                'FROM'   => 'glpi_printerlogs',
                'WHERE'  => [
                    'itemtype' => 'Printer',
                    'items_id' => $printers_id,
                    'date'     => ['>=', $start],
                ],
                'ORDER'  => ['date ASC'],
                'LIMIT'  => 1,
            ])->current();
        }

        // Compteur fin : dernier log <= fin de journée du end (inclut le dernier jour)
        $rowEnd = $DB->request([
            'SELECT' => $select,
            'FROM'   => 'glpi_printerlogs',
            'WHERE'  => [
                'itemtype' => 'Printer',
                'items_id' => $printers_id,
                'date'     => ['<=', $end . ' 23:59:59'],
            ],
            'ORDER'  => ['date DESC'],
            'LIMIT'  => 1,
        ])->current();

        // Extraction compteurs N&B + Couleur — logique universelle pour toutes marques :
        //
        // 1. Détermination du TOTAL authoritative (max entre toutes les sources pour ne
        //    rien rater) :
        //      total = max(total_pages, bw_pages + color_pages, somme prints + copies)
        //
        // 2. Détermination de COULEUR (max entre color_pages et color_prints+color_copies)
        //
        // 3. N&B = TOTAL - COULEUR (garantit toujours total = bw + color, pas de double
        //    comptage, fonctionne que le constructeur remonte bw_pages direct, prints/copies
        //    séparés, ou total_pages seul).
        $extract = function ($row) {
            if (!is_array($row)) {
                return ['nb' => 0, 'color' => 0];
            }
            $total_pages  = (int)($row['total_pages']  ?? 0);
            $bw_pages     = (int)($row['bw_pages']     ?? 0);
            $color_pages  = (int)($row['color_pages']  ?? 0);
            $bw_prints    = (int)($row['bw_prints']    ?? 0);
            $color_prints = (int)($row['color_prints'] ?? 0);
            $bw_copies    = (int)($row['bw_copies']    ?? 0);
            $color_copies = (int)($row['color_copies'] ?? 0);

            // Somme des sous-compteurs prints+copies (si détaillés par l'agent)
            $sum_granular = $bw_prints + $color_prints + $bw_copies + $color_copies;
            // Somme des compteurs agrégés bw+color (si présents)
            $sum_aggregate = $bw_pages + $color_pages;

            // TOTAL authoritative : le plus grand des 3
            $total = max($total_pages, $sum_aggregate, $sum_granular);

            // COULEUR : max entre color_pages direct et agrégation prints+copies couleur
            $color = max($color_pages, $color_prints + $color_copies);

            // N&B = TOTAL - COULEUR (garantit total = bw + color)
            $bw = max(0, $total - $color);

            return ['nb' => $bw, 'color' => $color];
        };

        $s = $extract($rowStart);
        $e = $extract($rowEnd);
        $out['start_nb']    = $s['nb'];
        $out['start_color'] = $s['color'];
        $out['end_nb']      = $e['nb'];
        $out['end_color']   = $e['color'];
        return $out;
    }

    static function install(Migration $migration) { return true; }
    static function uninstall(Migration $migration) { return true; }
}
