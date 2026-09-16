<?php
/**
 * PluginPrintgestionPrinterThresholdsTab — onglet « Seuils d'alerte » de la fiche imprimante :
 * seuil de niveau, seuil de jours et rendement propres à l'imprimante, qui surchargent la
 * configuration du plugin (module Gestion toner & expéditions).
 * Voir : droit Alertes toner en lecture ; enregistrer : Alertes toner en modification.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionPrinterThresholdsTab extends CommonGLPI {

    static $rightname = 'plugin_printgestion_dashboard';

    static function getTypeName($nb = 0) {
        return __('Seuils d\'alerte', 'printgestion');
    }

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item instanceof Printer && self::canViewThresholds($item)) {
            return self::getTypeName();
        }
        return '';
    }

    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        // Contenu joignable par l'URL de l'onglet : module, droit et accès à l'imprimante revérifiés.
        if (!$item instanceof Printer || !self::canViewThresholds($item)) {
            return false;
        }
        self::showForPrinter($item);
        return true;
    }

    /** Module toner actif, droit Alertes toner en lecture, imprimante visible. */
    public static function canViewThresholds(Printer $printer): bool {
        return PluginPrintgestionConfig::isFeatureEnabled('toner')
            && Session::haveRight(self::$rightname, READ)
            && $printer->canViewItem();
    }

    static function showForPrinter(Printer $printer): void {
        global $DB;

        $printers_id = (int) $printer->getID();
        $can_edit    = Session::haveRight(self::$rightname, UPDATE);
        $uid         = 'pc-th-' . $printers_id;
        $disabled    = $can_edit ? '' : ' disabled';

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
        echo "<form class='row g-3 align-items-start' id='{$uid}-form' onsubmit='return false;'>";

        echo "<div class='col-md-3'><label class='form-label mb-1'>"
            . __('Seuil niveau (%)', 'printgestion') . "</label>";
        echo "<input type='number' min='0' max='100' class='form-control' name='threshold_level' "
            . "value='" . htmlspecialchars((string)($cur_level ?? ''), ENT_QUOTES, 'UTF-8') . "' "
            . "placeholder='" . $def_level . "'{$disabled}>";
        echo "<small class='text-muted'>" . sprintf(__('Défaut global : %d', 'printgestion'), $def_level) . "</small></div>";

        echo "<div class='col-md-3'><label class='form-label mb-1'>"
            . __('Seuil jours estimés', 'printgestion') . "</label>";
        echo "<input type='number' min='0' class='form-control' name='threshold_days' "
            . "value='" . htmlspecialchars((string)($cur_days ?? ''), ENT_QUOTES, 'UTF-8') . "' "
            . "placeholder='" . $def_days . "'{$disabled}>";
        echo "<small class='text-muted'>" . sprintf(__('Défaut global : %d', 'printgestion'), $def_days) . "</small></div>";

        echo "<div class='col-md-3'><label class='form-label mb-1'>"
            . __('Yield (pages/cartouche)', 'printgestion') . "</label>";
        echo "<input type='number' min='100' class='form-control' name='pages_per_cartridge' "
            . "value='" . htmlspecialchars((string)($cur_yield ?? ''), ENT_QUOTES, 'UTF-8') . "' "
            . "placeholder='" . $def_yield . "'{$disabled}>";
        echo "<small class='text-muted'>" . sprintf(__('Défaut global : %d', 'printgestion'), $def_yield) . "</small></div>";

        if ($can_edit) {
            // Bouton aligné sur la ligne des inputs via un label fantôme de même hauteur
            echo "<div class='col-md-3'>"
                . "<label class='form-label mb-1' style='visibility:hidden'>&nbsp;</label>"
                . "<button type='submit' class='btn btn-primary w-100' id='{$uid}-submit'>"
                . "<i class='fa-solid fa-save me-1'></i>" . _sx('button', 'Save') . "</button>"
                . "</div>";
        }

        echo "</form>";
        echo "</div></div>";

        if (!$can_edit) {
            return;
        }

        echo PluginPrintgestionUi::jsonData($uid . '-init', [
            'uid'           => $uid,
            'printers_id'   => $printers_id,
            'thresholdsUrl' => PLUGIN_PRINTGESTION_WEBDIR . '/ajax/printer_thresholds.php',
            // Jeton CSRF envoyé en en-tête X-Glpi-Csrf-Token (requête AJAX POST).
            'csrf'          => Session::getNewCSRFToken(),
        ], 'PC_THRESHOLDS_INIT');
        echo <<<'HTML'
<script>
(function() {
  const cfg = window.PC_THRESHOLDS_INIT;
  if (!cfg) return;
  const thForm = document.getElementById(cfg.uid + '-form');
  if (!thForm) return;

  // Seuils personnalisés : submit AJAX silencieux + reload (GLPI flash message)
  thForm.addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(thForm);
    fd.append('printers_id', cfg.printers_id);
    const btn = document.getElementById(cfg.uid + '-submit');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>'; }

    // POST + jeton CSRF en en-tête : écriture protégée par le contrôle du cœur GLPI 11.
    fetch(cfg.thresholdsUrl, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': cfg.csrf },
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
})();
</script>
HTML;
    }

    static function install(Migration $migration) { return true; }
    static function uninstall(Migration $migration) { return true; }
}
