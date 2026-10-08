<?php
/**
 * Menu clic droit léger par-dessus les tableaux natifs de GLPI (alertes toner, demandes d'envoi, expéditions,
 * facturation) — sans toucher au cœur de GLPI.
 *
 * Trois sortes d'entrées :
 *   - « native » : coche la ligne et ouvre la fenêtre « Actions » de GLPI avec l'action déjà choisie. C'est la même
 *     action de masse que par les cases (mêmes sous-formulaires, mêmes droits, même traitement) : rien n'est
 *     dupliqué. Si GLPI changeait son balisage, la ligne resterait cochée et le bouton « Actions » apparaîtrait ;
 *   - « lien » : ouvre une fiche (imprimante, cartouche, expédition, demande) à l'adresse fabriquée ici, jamais
 *     par le navigateur ;
 *   - « plugin » : signale l'action au script de l'écran (fenêtres « Modifier expédition », « Associer des BL »).
 *
 * La ligne se reconnaît par sa case native (`item[Type][id]`), ou par le marqueur posé dans la colonne de statut
 * quand l'utilisateur n'a aucune action de masse (lecture seule). Le contexte des lignes affichées (adresses,
 * expédition en cours, cartouche résolue, ce qui est possible) est lu en une requête par affichage du tableau
 * (`ajax/rowcontext.php`), dans le périmètre de l'utilisateur seulement : une ligne d'un autre client n'existe pas.
 */
class PluginPrintgestionContextmenu {

    /** Lignes lues par appel AJAX : au plus une page du tableau natif. */
    const MAX_ROWS = 200;

    const MARKER_CLASS = 'pg-ctx-row-marker';

    /** Types pris en charge, avec le module qui les porte. */
    private static function getFeatures(): array {
        return [
            'PluginPrintgestionAlertview'    => 'toner',
            'PluginPrintgestionDemande'      => 'toner',
            'PluginPrintgestionExpedition'   => 'toner',
            'PluginPrintgestionBillingview'  => 'cout',
            'PluginPrintgestionRaccordement' => 'deploiement',
            'Agent'                          => 'deploiement',
            'PluginPrintgestionSonde'        => 'deploiement',
            'PluginPrintgestionPrintercollect' => 'deploiement',
        ];
    }

    public static function isSupported(string $itemtype): bool {
        return isset(self::getFeatures()[$itemtype]);
    }

    /** Lecture de l'écran qui porte ce tableau : même droit que la page elle-même. */
    public static function canRead(string $itemtype): bool {
        $feature = self::getFeatures()[$itemtype] ?? '';
        if ($feature === '' || !PluginPrintgestionConfig::isFeatureEnabled($feature)) {
            return false;
        }
        switch ($itemtype) {
            case 'PluginPrintgestionAlertview':
                return Session::haveRight('plugin_printgestion_dashboard', READ);
            case 'PluginPrintgestionDemande':
                return PluginPrintgestionDemande::canView();
            case 'PluginPrintgestionExpedition':
                return Session::haveRight('plugin_printgestion_expedition', READ);
            case 'PluginPrintgestionBillingview':
                return Session::haveRight('plugin_printgestion_billing', READ);
            case 'PluginPrintgestionRaccordement':
            case 'Agent':
            case 'PluginPrintgestionSonde':
            case 'PluginPrintgestionPrintercollect':
                // Sondes et imprimantes collectées : montrées par le plugin avec le droit Déploiement.
                return Session::haveRight('plugin_printgestion_deploiement', READ);
        }
        return false;
    }

    /** Fichier Gesconso d'une commande : mêmes droits que son téléchargement (Purchaseorder::canDownload()). */
    private static function canDownloadGesconso(): bool {
        return Session::haveRight('plugin_printgestion_expedition', READ)
            || Session::haveRight('plugin_printgestion_validation', READ);
    }

    /** Adresse de téléchargement du fichier Gesconso, par lot d'expéditions : group_id => URL. */
    private static function getGesconsoUrls(array $group_ids): array {
        if (!self::canDownloadGesconso()) {
            return [];
        }
        return array_map(
            [PluginPrintgestionPurchaseorder::class, 'getDownloadURL'],
            PluginPrintgestionPurchaseorder::getIdsForGroups($group_ids)
        );
    }

    /** Liaison BL du plugin Gestion : déduite de son état, jamais réglée ; une erreur la désactive pour l'écran. */
    private static function isBlEnabled(): bool {
        try {
            return PluginPrintgestionTracking::isGestionLinkActive();
        } catch (Throwable $e) {
            PluginPrintgestionLogger::error(
                'Contextmenu::isBlEnabled',
                "Lecture de l'état du plugin Gestion impossible : liaison BL absente du menu.",
                $e
            );
            return false;
        }
    }

    /**
     * Entrées du menu pour un type, déjà filtrées par les droits de l'utilisateur : ce qu'il ne peut pas faire
     * n'est pas rendu puis caché, il n'arrive pas dans sa page.
     *
     * Chaque entrée : key, icon, label, kind (native | link | plugin), puis action (clé de l'action de masse),
     * field (champ du contexte qui porte l'adresse) ou event (nom signalé au script de l'écran), et require
     * (champ du contexte qui doit être vrai pour que l'entrée s'affiche).
     */
    public static function getItems(string $itemtype): array {
        $sep   = MassiveAction::CLASS_ACTION_SEPARATOR;
        $items = [];
        switch ($itemtype) {
            case 'PluginPrintgestionAlertview':
                if (Session::haveRight('plugin_printgestion_validation', UPDATE)) {
                    $items[] = ['key' => 'order', 'icon' => 'ti ti-shopping-cart', 'label' => __('Commander…', 'printgestion'),
                                'kind' => 'native', 'action' => $itemtype . $sep . 'pg_order', 'require' => 'can_order'];
                }
                if (Session::haveRight('plugin_printgestion_dashboard', UPDATE)) {
                    $items[] = ['key' => 'snooze', 'icon' => 'ti ti-bell-off', 'label' => __('Ne plus alerter pendant…', 'printgestion'),
                                'kind' => 'native', 'action' => $itemtype . $sep . 'pg_snooze', 'require' => 'can_snooze'];
                    $items[] = ['key' => 'unsnooze', 'icon' => 'ti ti-bell', 'label' => __('Réactiver les alertes', 'printgestion'),
                                'kind' => 'native', 'action' => $itemtype . $sep . 'pg_unsnooze', 'require' => 'can_unsnooze'];
                }
                if (Printer::canView()) {
                    $items[] = ['key' => 'open-printer', 'icon' => 'ti ti-printer', 'label' => __('Ouvrir la fiche imprimante', 'printgestion'),
                                'kind' => 'link', 'field' => 'printer_url', 'require' => ''];
                }
                if (CartridgeItem::canView()) {
                    $items[] = ['key' => 'open-cartridge', 'icon' => 'ti ti-box', 'label' => __('Voir le stock de la cartouche', 'printgestion'),
                                'kind' => 'link', 'field' => 'cartridge_url', 'require' => 'has_cartridge'];
                }
                if (Session::haveRight('plugin_printgestion_dashboard', UPDATE) && Printer::canView()) {
                    $items[] = ['key' => 'manual-reading', 'icon' => 'ti ti-pencil-plus', 'label' => __('Saisir un relevé manuel…', 'printgestion'),
                                'kind' => 'link', 'field' => 'manual_url', 'require' => ''];
                }
                if (Session::haveRight('plugin_printgestion_expedition', READ)) {
                    $items[] = ['key' => 'open-expedition', 'icon' => 'ti ti-truck', 'label' => __('Ouvrir l\'expédition en cours', 'printgestion'),
                                'kind' => 'link', 'field' => 'expedition_url', 'require' => 'has_expedition'];
                }
                if (self::canDownloadGesconso()) {
                    $items[] = ['key' => 'download-gesconso', 'icon' => 'ti ti-file-spreadsheet', 'label' => __('Télécharger le fichier Gesconso', 'printgestion'),
                                'kind' => 'link', 'field' => 'gesconso_url', 'require' => 'has_gesconso'];
                }
                if (Session::haveRight('plugin_printgestion_expedition', UPDATE)) {
                    $items[] = ['key' => 'edit-expedition', 'icon' => 'ti ti-pencil', 'label' => __('Modifier l\'expédition en cours…', 'printgestion'),
                                'kind' => 'plugin', 'event' => 'edit-expedition', 'require' => 'has_expedition'];
                    if (self::isBlEnabled()) {
                        $items[] = ['key' => 'link-bl', 'icon' => 'ti ti-file-text', 'label' => __('Associer des BL (plugin Gestion)…', 'printgestion'),
                                    'kind' => 'plugin', 'event' => 'link-bl', 'require' => 'has_expedition'];
                    }
                }
                break;

            case 'PluginPrintgestionDemande':
                $items[] = ['key' => 'open-demande', 'icon' => 'ti ti-clipboard-text', 'label' => __('Ouvrir la demande', 'printgestion'),
                            'kind' => 'link', 'field' => 'demande_url', 'require' => ''];
                if (PluginPrintgestionDemande::canUpdate()) {
                    $items[] = ['key' => 'validate', 'icon' => 'ti ti-check', 'label' => __('Valider', 'printgestion'),
                                'kind' => 'native', 'action' => $itemtype . $sep . 'pg_validate', 'require' => 'can_validate'];
                    $items[] = ['key' => 'cancel', 'icon' => 'ti ti-x', 'label' => __('Annuler…', 'printgestion'),
                                'kind' => 'native', 'action' => $itemtype . $sep . 'pg_cancel', 'require' => 'can_cancel'];
                }
                break;

            case 'PluginPrintgestionExpedition':
                $items[] = ['key' => 'open-expedition', 'icon' => 'ti ti-truck', 'label' => __('Ouvrir l\'expédition', 'printgestion'),
                            'kind' => 'link', 'field' => 'expedition_url', 'require' => ''];
                if (self::canDownloadGesconso()) {
                    $items[] = ['key' => 'download-gesconso', 'icon' => 'ti ti-file-spreadsheet', 'label' => __('Télécharger le fichier Gesconso', 'printgestion'),
                                'kind' => 'link', 'field' => 'gesconso_url', 'require' => 'has_gesconso'];
                }
                if (Session::haveRight('plugin_printgestion_expedition', UPDATE)) {
                    $items[] = ['key' => 'edit-expedition', 'icon' => 'ti ti-pencil', 'label' => __('Modifier expédition…', 'printgestion'),
                                'kind' => 'plugin', 'event' => 'edit-expedition', 'require' => ''];
                    if (self::isBlEnabled()) {
                        $items[] = ['key' => 'link-bl', 'icon' => 'ti ti-file-text', 'label' => __('Associer des BL (plugin Gestion)…', 'printgestion'),
                                    'kind' => 'plugin', 'event' => 'link-bl', 'require' => ''];
                    }
                }
                if (Printer::canView()) {
                    $items[] = ['key' => 'open-printer', 'icon' => 'ti ti-printer', 'label' => __('Ouvrir la fiche imprimante', 'printgestion'),
                                'kind' => 'link', 'field' => 'printer_url', 'require' => ''];
                }
                if (PluginPrintgestionDemande::canView()) {
                    $items[] = ['key' => 'open-demande', 'icon' => 'ti ti-clipboard-text', 'label' => __('Ouvrir la demande d\'envoi', 'printgestion'),
                                'kind' => 'link', 'field' => 'demande_url', 'require' => 'has_demande'];
                }
                break;

            case 'PluginPrintgestionBillingview':
                if (Printer::canView()) {
                    $items[] = ['key' => 'open-printer', 'icon' => 'ti ti-printer', 'label' => __('Ouvrir la fiche imprimante', 'printgestion'),
                                'kind' => 'link', 'field' => 'printer_url', 'require' => 'has_printer'];
                }
                break;

            case 'PluginPrintgestionRaccordement':
                $items[] = ['key' => 'open-raccordement', 'icon' => 'ti ti-plug-connected', 'label' => __('Ouvrir le raccordement', 'printgestion'),
                            'kind' => 'link', 'field' => 'raccordement_url', 'require' => ''];
                $items[] = ['key' => 'open-agent', 'icon' => 'ti ti-robot', 'label' => __('Ouvrir la sonde', 'printgestion'),
                            'kind' => 'link', 'field' => 'agent_url', 'require' => 'has_agent'];
                break;

            case 'Agent':
            case 'PluginPrintgestionSonde':
                $items[] = ['key' => 'open-agent', 'icon' => 'ti ti-robot', 'label' => __('Ouvrir la sonde', 'printgestion'),
                            'kind' => 'link', 'field' => 'agent_url', 'require' => ''];
                $items[] = ['key' => 'open-raccordement', 'icon' => 'ti ti-plug-connected', 'label' => __('Reprendre le raccordement en cours', 'printgestion'),
                            'kind' => 'link', 'field' => 'raccordement_url', 'require' => 'has_open_raccordement'];
                $items[] = ['key' => 'history', 'icon' => 'ti ti-history', 'label' => __('Historique des raccordements de cette sonde', 'printgestion'),
                            'kind' => 'link', 'field' => 'history_url', 'require' => 'has_raccordements'];
                break;

            case 'PluginPrintgestionPrintercollect':
                if (Printer::canView()) {
                    $items[] = ['key' => 'open-printer', 'icon' => 'ti ti-printer', 'label' => __('Ouvrir la fiche imprimante', 'printgestion'),
                                'kind' => 'link', 'field' => 'printer_url', 'require' => ''];
                }
                $items[] = ['key' => 'open-agent', 'icon' => 'ti ti-robot', 'label' => __('Ouvrir la sonde qui la relève', 'printgestion'),
                            'kind' => 'link', 'field' => 'agent_url', 'require' => 'has_agent'];
                if (Session::haveRight('plugin_printgestion_dashboard', UPDATE) && Printer::canView()) {
                    $items[] = ['key' => 'manual-reading', 'icon' => 'ti ti-pencil-plus', 'label' => __('Saisir un relevé manuel…', 'printgestion'),
                                'kind' => 'link', 'field' => 'manual_url', 'require' => ''];
                }
                break;
        }
        return $items;
    }

    /**
     * Marqueur caché posé dans une colonne du tableau natif : il porte l'identité de la ligne quand la case native
     * manque (utilisateur sans action de masse). Toujours présent, il ne dépend pas des droits.
     */
    public static function rowMarker(string $itemtype, int $id): string {
        if ($id <= 0) {
            return '';
        }
        return "<span class='" . self::MARKER_CLASS . "' data-itemtype='" . htmlspecialchars($itemtype, ENT_QUOTES, 'UTF-8')
            . "' data-id='" . $id . "' hidden></span>";
    }

    /** Menu, configuration (bloc de données, jamais un script fabriqué) et script, pour le tableau natif de ce type. */
    public static function render(string $itemtype): void {
        if (!self::isSupported($itemtype) || !self::canRead($itemtype)) {
            return;
        }
        $esc   = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $items = self::getItems($itemtype);
        if (empty($items)) {
            return;
        }
        $html = '';
        foreach ($items as $item) {
            $html .= "<a href='#' class='d-block px-3 py-2 text-decoration-none text-body' data-pc-action='" . $esc($item['key']) . "'"
                . ($item['require'] !== '' ? " data-pc-require='" . $esc($item['require']) . "'" : '') . ">"
                . "<i class='" . $esc($item['icon']) . " me-2'></i>" . $esc($item['label']) . "</a>\n";
        }
        $html .= "<div class='px-3 py-2 text-muted small' data-pc-empty style='display:none'>" . $esc(__('Aucune action pour cette ligne', 'printgestion')) . "</div>\n";

        echo "<div id='pc-ctx-menu' class='shadow border rounded bg-white' style='position:absolute;display:none;z-index:9999;min-width:260px;padding:4px 0'>\n"
            . $html . "</div>\n"
            . "<style>#pc-ctx-menu a:hover { background:#f1f3f5; } tr.pg-ctx-row { cursor: context-menu; }</style>\n";

        $config = [
            'itemtype' => $itemtype,
            'ajax'     => PLUGIN_PRINTGESTION_WEBDIR . '/ajax/rowcontext.php',
            'items'    => array_map(static fn(array $item): array => [
                'key'     => $item['key'],
                'kind'    => $item['kind'],
                'action'  => $item['action'] ?? '',
                'field'   => $item['field'] ?? '',
                'event'   => $item['event'] ?? '',
                'require' => $item['require'],
            ], $items),
        ];
        echo PluginPrintgestionUi::jsonData('pg-ctx-config', $config, 'PG_CTX_CONFIG') . "\n";
        echo self::getScript();
    }

    /** Script du menu : lecture des lignes, contexte, entrées, action native / lien / signal au script de l'écran. */
    private static function getScript(): string {
        return <<<'JS'
<script>
(function () {
  var cfg  = window.PG_CTX_CONFIG;
  var menu = document.getElementById('pc-ctx-menu');
  if (!cfg || !menu) { return; }
  var ctx      = {};
  var current  = null;
  var pending  = false;

  // Identité d'une ligne : la case native, le marqueur de la colonne de statut (lecture seule), ou les attributs
  // data-itemtype / data-id des tableaux du plugin (onglet de l'entité).
  function rowId(tr) {
    var boxes = tr.querySelectorAll('input.massive_action_checkbox');
    for (var i = 0; i < boxes.length; i++) {
      var m = /^item\[(.+)\]\[(\d+)\]$/.exec(boxes[i].getAttribute('name') || '');
      if (m && m[1] === cfg.itemtype) { return parseInt(m[2], 10); }
    }
    var marks = tr.querySelectorAll('.pg-ctx-row-marker');
    for (var j = 0; j < marks.length; j++) {
      if (marks[j].getAttribute('data-itemtype') === cfg.itemtype) { return parseInt(marks[j].getAttribute('data-id'), 10) || 0; }
    }
    if (tr.getAttribute('data-itemtype') === cfg.itemtype) { return parseInt(tr.getAttribute('data-id'), 10) || 0; }
    return 0;
  }

  // Lignes affichées : marquées, puis leur contexte lu en une requête (seules les inconnues sont demandées).
  function load() {
    var ids = [];
    var rows = document.querySelectorAll('[data-glpi-search-container] tbody tr, .pg-datatable tbody tr');
    for (var i = 0; i < rows.length; i++) {
      var id = rowId(rows[i]);
      if (id > 0) {
        rows[i].classList.add('pg-ctx-row');
        rows[i].setAttribute('data-pg-ctx-id', String(id));
        if (!Object.prototype.hasOwnProperty.call(ctx, id) && ids.indexOf(id) < 0) { ids.push(id); }
      }
    }
    if (!ids.length || pending) { return; }
    pending = true;
    fetch(cfg.ajax + '?itemtype=' + encodeURIComponent(cfg.itemtype) + '&ids=' + ids.join(','), {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (data && data.ok && data.rows) {
          for (var k in data.rows) { if (Object.prototype.hasOwnProperty.call(data.rows, k)) { ctx[k] = data.rows[k]; } }
        }
      })
      .catch(function () { /* sans contexte, seules les entrées sans condition restent */ })
      .then(function () { pending = false; });
  }

  function hide() { menu.style.display = 'none'; }

  function onContext(e) {
    var tr = e.target.closest('tr.pg-ctx-row');
    if (!tr) { return; }
    var id = parseInt(tr.getAttribute('data-pg-ctx-id'), 10);
    if (!id) { return; }
    e.preventDefault();
    current = { tr: tr, id: id };
    var c = Object.prototype.hasOwnProperty.call(ctx, id) ? ctx[id] : null;
    var shown = 0;
    var links = menu.querySelectorAll('a[data-pc-action]');
    for (var i = 0; i < links.length; i++) {
      var req = links[i].getAttribute('data-pc-require');
      var ok  = !req || (c !== null && !!c[req]);
      // « d-block » de Bootstrap pose display:block !important : la priorité est nécessaire pour cacher.
      links[i].style.setProperty('display', ok ? 'block' : 'none', 'important');
      if (ok) { shown++; }
    }
    var empty = menu.querySelector('[data-pc-empty]');
    if (empty) { empty.style.display = shown ? 'none' : 'block'; }
    // Affiché d'abord pour connaître sa taille, puis placé dans la fenêtre : à gauche du pointeur s'il dépasserait
    // le bord droit, au-dessus s'il dépasserait le bas, et jamais au-delà du bord gauche ou du haut.
    menu.style.visibility = 'hidden';
    menu.style.display = 'block';
    var w = menu.offsetWidth, h = menu.offsetHeight, margin = 4;
    var x = e.clientX + w > window.innerWidth - margin ? e.clientX - w : e.clientX;
    var y = e.clientY + h > window.innerHeight - margin ? e.clientY - h : e.clientY;
    x = Math.max(margin, Math.min(x, window.innerWidth - w - margin));
    y = Math.max(margin, Math.min(y, window.innerHeight - h - margin));
    menu.style.left = (x + window.scrollX) + 'px';
    menu.style.top  = (y + window.scrollY) + 'px';
    menu.style.visibility = '';
  }

  // Un seul jeu d'écouteurs sur le document, même quand un onglet AJAX recharge ce script : ils appellent la
  // dernière instance (menu et configuration du contenu affiché).
  var shared = window.PG_CTX = window.PG_CTX || {};
  shared.onContext = onContext;
  shared.hide      = hide;
  if (!shared.bound) {
    shared.bound = true;
    document.addEventListener('contextmenu', function (e) { shared.onContext(e); });
    document.addEventListener('click', function (e) { if (!e.target.closest('#pc-ctx-menu')) { shared.hide(); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { shared.hide(); } });
  }

  // Action native : cette ligne seule cochée, fenêtre « Actions » de GLPI ouverte, action déjà choisie.
  function nativeAction(tr, actionKey) {
    var box = null;
    var boxes = tr.querySelectorAll('input.massive_action_checkbox');
    for (var i = 0; i < boxes.length; i++) {
      var m = /^item\[(.+)\]\[(\d+)\]$/.exec(boxes[i].getAttribute('name') || '');
      if (m && m[1] === cfg.itemtype) { box = boxes[i]; break; }
    }
    if (!box) { return; }
    var others = document.querySelectorAll('input.massive_action_checkbox:checked');
    for (var j = 0; j < others.length; j++) {
      if (others[j] !== box) { others[j].checked = false; others[j].dispatchEvent(new Event('change', { bubbles: true })); }
    }
    box.checked = true;
    box.dispatchEvent(new Event('change', { bubbles: true }));
    var btn = document.querySelector('.massiveactions-control a[role="button"]');
    if (!btn) { return; }
    // GLPI réutilise la fenêtre et recharge son contenu à chaque ouverture : l'ancien menu déroulant reste affiché
    // le temps du chargement. Marqué ici, il n'est jamais pris pour le nouveau (le choix serait aussitôt écrasé).
    var olds = document.querySelectorAll('select[name="massiveaction"]');
    for (var o = 0; o < olds.length; o++) { olds[o].setAttribute('data-pg-old', '1'); }
    btn.click();
    // La fenêtre charge son formulaire en AJAX : on attend le nouveau menu déroulant des actions, puis on choisit.
    var tries = 0;
    var timer = setInterval(function () {
      var sel = document.querySelector('.modal.show select[name="massiveaction"]:not([data-pg-old])');
      if (sel) {
        clearInterval(timer);
        var found = false;
        for (var k = 0; k < sel.options.length; k++) { if (sel.options[k].value === actionKey) { found = true; break; } }
        if (!found) { return; }
        // GLPI branche son écouteur (chargement du sous-formulaire) au « document prêt », juste après l'insertion :
        // on lui laisse ce temps avant de choisir.
        setTimeout(function () {
          sel.value = actionKey;
          if (window.jQuery) { window.jQuery(sel).trigger('change'); } else { sel.dispatchEvent(new Event('change', { bubbles: true })); }
        }, 150);
      } else if (++tries > 100) {
        clearInterval(timer);
      }
    }, 100);
  }

  menu.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-pc-action]');
    if (!a || !current) { return; }
    e.preventDefault();
    hide();
    var key = a.getAttribute('data-pc-action');
    var item = null;
    for (var i = 0; i < cfg.items.length; i++) { if (cfg.items[i].key === key) { item = cfg.items[i]; break; } }
    if (!item) { return; }
    var c = Object.prototype.hasOwnProperty.call(ctx, current.id) ? ctx[current.id] : {};
    if (item.kind === 'link') {
      var url = c[item.field];
      if (url) { window.location.href = url; }
    } else if (item.kind === 'native') {
      nativeAction(current.tr, item.action);
    } else if (item.kind === 'plugin') {
      document.dispatchEvent(new CustomEvent('pg:contextmenu', { detail: { action: item.event, itemtype: cfg.itemtype, id: current.id, context: c } }));
    }
  });

  function init() {
    load();
    var cont = document.querySelector('[data-glpi-search-container]');
    if (cont && window.MutationObserver) {
      // Tri, pagination, recherche : le tableau natif se recharge en AJAX, les lignes sont relues.
      new MutationObserver(function () { load(); }).observe(cont, { childList: true, subtree: true });
    }
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
</script>
JS;
    }

    // ── Contexte des lignes ───────────────────────────────────────────────

    /**
     * Contexte des lignes demandées, dans le périmètre de l'utilisateur : id => champs. Une ligne absente du
     * résultat n'existe pas pour lui (autre client, imprimante supprimée) : le menu n'en dit rien de plus.
     */
    public static function getContext(string $itemtype, array $ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id) => $id > 0)));
        if (empty($ids) || !self::isSupported($itemtype) || !self::canRead($itemtype)) {
            return [];
        }
        $ids = array_slice($ids, 0, self::MAX_ROWS);
        switch ($itemtype) {
            case 'PluginPrintgestionAlertview':
                return self::alertContext($ids);
            case 'PluginPrintgestionDemande':
                return self::demandeContext($ids);
            case 'PluginPrintgestionExpedition':
                return self::expeditionContext($ids);
            case 'PluginPrintgestionBillingview':
                return self::billingContext($ids);
            case 'PluginPrintgestionRaccordement':
                return self::raccordementContext($ids);
            case 'Agent':
            case 'PluginPrintgestionSonde':
                return self::agentContext($ids);
            case 'PluginPrintgestionPrintercollect':
                return self::printercollectContext($ids);
        }
        return [];
    }

    /** Imprimantes de la vue « Imprimantes collectées » : la fiche, et la sonde qui les relève. */
    private static function printercollectContext(array $ids): array {
        global $DB;

        $view = PluginPrintgestionCollectview::getTable();
        $out  = [];
        foreach ($DB->request([
            'SELECT'    => ['glpi_printers.id AS id', $view . '.agents_id AS agents_id'],
            'FROM'      => 'glpi_printers',
            'LEFT JOIN' => [$view => ['ON' => [$view => 'printers_id', 'glpi_printers' => 'id']]],
            'WHERE'     => array_merge(
                ['glpi_printers.id' => $ids, 'glpi_printers.is_deleted' => 0],
                getEntitiesRestrictCriteria('glpi_printers', '', '', true)
            ),
        ]) as $row) {
            $agents_id             = (int) ($row['agents_id'] ?? 0);
            $out[(int) $row['id']] = [
                'printer_url' => Printer::getFormURLWithID((int) $row['id']),
                'manual_url'  => Printer::getFormURLWithID((int) $row['id']) . '&forcetab=' . urlencode('Cartridge$1'),
                'agent_url'   => $agents_id > 0 ? PluginPrintgestionAgentsetting::getPageURL($agents_id) : '',
                'has_agent'   => $agents_id > 0,
            ];
        }
        return $out;
    }

    private static function raccordementContext(array $ids): array {
        global $DB;

        $table = PluginPrintgestionRaccordement::getTable();
        $out   = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'agents_id'],
            'FROM'   => $table,
            'WHERE'  => array_merge(['id' => $ids], getEntitiesRestrictCriteria($table, '', '', false)),
        ]) as $row) {
            $agents_id             = (int) $row['agents_id'];
            $out[(int) $row['id']] = [
                'raccordement_url' => PluginPrintgestionRaccordement::getPageURL((int) $row['id']),
                'agent_url'        => $agents_id > 0 ? PluginPrintgestionAgentsetting::getPageURL($agents_id) : '',
                'has_agent'        => $agents_id > 0,
            ];
        }
        return $out;
    }

    /** Sondes de l'onglet Déploiement Agent d'une entité : la sonde, son dernier raccordement, son historique. */
    private static function agentContext(array $ids): array {
        global $DB;

        $table = Agent::getTable();
        $rows  = iterator_to_array($DB->request([
            'SELECT' => ['id', 'entities_id'],
            'FROM'   => $table,
            'WHERE'  => array_merge(['id' => $ids], getEntitiesRestrictCriteria($table, '', '', true)),
        ]), false);
        // Dernier raccordement de chaque sonde, dans le périmètre de l'utilisateur.
        $last  = [];
        $count = [];
        if (!empty($rows)) {
            $racc_table = PluginPrintgestionRaccordement::getTable();
            foreach ($DB->request([
                'SELECT' => ['id', 'agents_id', 'status'],
                'FROM'   => $racc_table,
                'WHERE'  => array_merge(
                    ['agents_id' => array_map(static fn(array $r): int => (int) $r['id'], $rows)],
                    getEntitiesRestrictCriteria($racc_table, '', '', false)
                ),
                'ORDER'  => ['id DESC'],
            ]) as $racc) {
                $agents_id         = (int) $racc['agents_id'];
                $count[$agents_id] = ($count[$agents_id] ?? 0) + 1;
                if (!isset($last[$agents_id])) {
                    $last[$agents_id] = $racc;
                }
            }
        }
        $open_statuses = [
            PluginPrintgestionRaccordement::STATUS_OPEN,
            PluginPrintgestionRaccordement::STATUS_CONFIGURED,
            PluginPrintgestionRaccordement::STATUS_TRIGGERED,
        ];
        $out = [];
        foreach ($rows as $row) {
            $agents_id = (int) $row['id'];
            $racc      = $last[$agents_id] ?? null;
            $open      = $racc !== null && in_array((string) $racc['status'], $open_statuses, true);
            $out[$agents_id] = [
                'agent_url'             => PluginPrintgestionAgentsetting::getPageURL($agents_id),
                'raccordement_url'      => $open ? PluginPrintgestionRaccordement::getPageURL((int) $racc['id']) : '',
                'has_open_raccordement' => $open,
                'history_url'           => PluginPrintgestionRaccordement::getListURL(null, $agents_id),
                'has_raccordements'     => ($count[$agents_id] ?? 0) > 0,
            ];
        }
        return $out;
    }

    /** Expéditions en cours (verrou actif) des imprimantes données : « imprimante|toner » => ligne. */
    private static function getOpenExpeditions(array $printer_ids): array {
        global $DB;

        if (empty($printer_ids)) {
            return [];
        }
        $open = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'printers_id', 'toner_property', 'statut', 'transport_carrier', 'transport_number', 'group_id'],
            'FROM'   => PluginPrintgestionExpedition::getTable(),
            'WHERE'  => ['printers_id' => $printer_ids, 'active_lock' => 1],
        ]) as $row) {
            $open[(int) $row['printers_id'] . '|' . (string) $row['toner_property']] = $row;
        }
        return $open;
    }

    private static function alertContext(array $ids): array {
        global $DB;

        $table = PluginPrintgestionAlertview::getTable();
        $rows  = iterator_to_array($DB->request([
            'SELECT'     => [
                $table . '.id AS id',
                $table . '.printers_id AS printers_id',
                $table . '.toner_property AS property',
                $table . '.level_percent AS level',
                $table . '.days_remaining AS days',
                $table . '.status AS status',
                $table . '.cartridge_label AS cartridge',
                $table . '.is_snoozed AS is_snoozed',
                $table . '.ref_error AS ref_error',
                'glpi_printers.name AS printer_name',
                'glpi_entities.completename AS entity_name',
            ],
            'FROM'       => $table,
            'INNER JOIN' => ['glpi_printers' => ['ON' => ['glpi_printers' => 'id', $table => 'printers_id']]],
            'LEFT JOIN'  => ['glpi_entities' => ['ON' => ['glpi_entities' => 'id', $table => 'entities_id']]],
            'WHERE'      => array_merge(
                [$table . '.id' => $ids, 'glpi_printers.is_deleted' => 0],
                getEntitiesRestrictCriteria($table, '', '', false)
            ),
        ]), false);
        $open          = self::getOpenExpeditions(array_values(array_unique(array_map(static fn(array $r): int => (int) $r['printers_id'], $rows))));
        $gesconso      = self::getGesconsoUrls(array_column($open, 'group_id'));
        $can_cartridge = CartridgeItem::canView();
        $out           = [];
        foreach ($rows as $row) {
            $printers_id = (int) $row['printers_id'];
            $property    = (string) $row['property'];
            $exp         = $open[$printers_id . '|' . $property] ?? null;
            // La cartouche n'est cherchée que si la référence est résolue (sinon l'alerte le dit déjà) et si
            // l'utilisateur peut ouvrir une cartouche.
            $cartridge_id = 0;
            if ($can_cartridge && (string) ($row['ref_error'] ?? '') === '') {
                $cartridge_id = PluginPrintgestionSnmpmapping::resolveCartridgeItemForSnmp($printers_id, $property);
            }
            $out[(int) $row['id']] = [
                'printers_id'    => $printers_id,
                'printer_name'   => (string) $row['printer_name'],
                'entity_name'    => (string) ($row['entity_name'] ?? ''),
                'property'       => $property,
                'level'          => $row['level'],
                'days'           => $row['days'],
                'cartridge'      => (string) ($row['cartridge'] ?? ''),
                'printer_url'    => Printer::getFormURLWithID($printers_id),
                'manual_url'     => Printer::getFormURLWithID($printers_id) . '&forcetab=' . urlencode('Cartridge$1'),
                'cartridge_url'  => $cartridge_id > 0 ? CartridgeItem::getFormURLWithID($cartridge_id) : '',
                'expedition_id'  => $exp !== null ? (int) $exp['id'] : 0,
                'expedition_url' => $exp !== null ? PluginPrintgestionExpedition::getFormURLWithID((int) $exp['id']) : '',
                'exp_statut'     => $exp !== null ? (string) $exp['statut'] : '',
                'exp_carrier'    => $exp !== null ? (string) ($exp['transport_carrier'] ?? '') : '',
                'exp_tracking'   => $exp !== null ? (string) ($exp['transport_number'] ?? '') : '',
                'can_order'      => (string) $row['status'] !== PluginPrintgestionAlert::STATUS_OK,
                'can_snooze'     => empty($row['is_snoozed']),
                'can_unsnooze'   => !empty($row['is_snoozed']),
                'has_expedition' => $exp !== null,
                'has_cartridge'  => $cartridge_id > 0,
                // Fichier de la commande en cours pour ce toner (expédition ouverte).
                'gesconso_url'   => $exp !== null ? ($gesconso[(string) $exp['group_id']] ?? '') : '',
                'has_gesconso'   => $exp !== null && isset($gesconso[(string) $exp['group_id']]),
            ];
        }
        return $out;
    }

    private static function demandeContext(array $ids): array {
        global $DB;

        $table = PluginPrintgestionDemande::getTable();
        $out   = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'statut'],
            'FROM'   => $table,
            'WHERE'  => array_merge(['id' => $ids], getEntitiesRestrictCriteria($table, '', '', true)),
        ]) as $row) {
            $statut             = (string) $row['statut'];
            $out[(int) $row['id']] = [
                'name'         => (string) $row['name'],
                'statut'       => $statut,
                'demande_url'  => PluginPrintgestionDemande::getFormURLWithID((int) $row['id']),
                'can_validate' => $statut === PluginPrintgestionDemande::STATUS_PROPOSED,
                'can_cancel'   => in_array($statut, PluginPrintgestionDemande::OPEN_STATUSES, true),
            ];
        }
        return $out;
    }

    private static function expeditionContext(array $ids): array {
        global $DB;

        $table = PluginPrintgestionExpedition::getTable();
        $rows  = iterator_to_array($DB->request([
            'SELECT'     => [
                $table . '.id AS id',
                $table . '.printers_id AS printers_id',
                $table . '.toner_property AS property',
                $table . '.statut AS statut',
                $table . '.transport_carrier AS carrier',
                $table . '.transport_number AS tracking',
                $table . '.group_id AS group_id',
                'glpi_printers.name AS printer_name',
                'glpi_entities.completename AS entity_name',
            ],
            'FROM'       => $table,
            'INNER JOIN' => ['glpi_printers' => ['ON' => ['glpi_printers' => 'id', $table => 'printers_id']]],
            'LEFT JOIN'  => ['glpi_entities' => ['ON' => ['glpi_entities' => 'id', $table => 'entities_id']]],
            'WHERE'      => array_merge(
                [$table . '.id' => $ids, 'glpi_printers.is_deleted' => 0],
                getEntitiesRestrictCriteria($table, '', '', true)
            ),
        ]), false);
        // Demande d'envoi d'origine : la ligne de demande qui porte cette expédition, s'il y en a une.
        $demandes = [];
        if (!empty($rows)) {
            foreach ($DB->request([
                'SELECT' => ['expeditions_id', 'plugin_printgestion_demandes_id'],
                'FROM'   => PluginPrintgestionDemandeline::getTable(),
                'WHERE'  => ['expeditions_id' => array_map(static fn(array $r): int => (int) $r['id'], $rows)],
            ]) as $line) {
                $demandes[(int) $line['expeditions_id']] = (int) $line['plugin_printgestion_demandes_id'];
            }
        }
        $gesconso = self::getGesconsoUrls(array_column($rows, 'group_id'));
        $out      = [];
        foreach ($rows as $row) {
            $id          = (int) $row['id'];
            $printers_id = (int) $row['printers_id'];
            $demande     = $demandes[$id] ?? 0;
            $file_url    = $gesconso[(string) ($row['group_id'] ?? '')] ?? '';
            $out[$id]    = [
                'printers_id'    => $printers_id,
                'printer_name'   => (string) $row['printer_name'],
                'entity_name'    => (string) ($row['entity_name'] ?? ''),
                'property'       => (string) $row['property'],
                'expedition_id'  => $id,
                'expedition_url' => PluginPrintgestionExpedition::getFormURLWithID($id),
                'printer_url'    => Printer::getFormURLWithID($printers_id),
                'exp_statut'     => (string) $row['statut'],
                'exp_carrier'    => (string) ($row['carrier'] ?? ''),
                'exp_tracking'   => (string) ($row['tracking'] ?? ''),
                'demande_url'    => $demande > 0 ? PluginPrintgestionDemande::getFormURLWithID($demande) : '',
                'has_demande'    => $demande > 0,
                'gesconso_url'   => $file_url,
                'has_gesconso'   => $file_url !== '',
            ];
        }
        return $out;
    }

    private static function billingContext(array $ids): array {
        global $DB;

        // La vue de facturation est calculée par utilisateur : ses lignes à lui, rien d'autre.
        $out = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'printers_id'],
            'FROM'   => PluginPrintgestionBillingview::getTable(),
            'WHERE'  => ['id' => $ids, 'users_id' => (int) Session::getLoginUserID()],
        ]) as $row) {
            $printers_id = (int) $row['printers_id'];
            if ($printers_id > 0 && !PluginPrintgestionSecurity::canAccessPrinter($printers_id)) {
                $printers_id = 0;
            }
            $out[(int) $row['id']] = [
                'printers_id' => $printers_id,
                'printer_url' => $printers_id > 0 ? Printer::getFormURLWithID($printers_id) : '',
                'has_printer' => $printers_id > 0,
            ];
        }
        return $out;
    }
}
