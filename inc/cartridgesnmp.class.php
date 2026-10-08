<?php
/**
 * PluginPrintgestionCartridgesnmp — onglet "Print Gestion" sur la fiche CartridgeItem.
 *
 * Permet de binder directement une cartouche à une ou plusieurs propriétés SNMP
 * remontées par les imprimantes compatibles (déclarées dans l'onglet "Modèles
 * d'imprimantes compatibles" de la cartouche).
 *
 * Workflow utilisateur :
 *   1. Créer une cartouche dans Assets → Cartouches
 *   2. Onglet "Modèles d'imprimantes compatibles" → ajouter les modèles d'imprimante
 *   3. Onglet "Print Gestion" (celui-ci) → cocher les propriétés SNMP correspondantes
 *   4. Save → la cartouche est directement liée aux propriétés SNMP, sans avoir
 *      à créer de types intermédiaires dans GLPI
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionCartridgesnmp extends CommonDBTM {

    static $rightname = 'cartridge';

    public static function getTable($classname = null) {
        if ($classname === null || $classname === static::class) {
            return 'glpi_plugin_printgestion_cartridge_snmp';
        }
        return parent::getTable($classname);
    }

    static function getTypeName($nb = 0) {
        return __('Propriétés SNMP', 'printgestion');
    }

    /**
     * Onglet et formulaire : droit natif sur les cartouches ET droit de configuration du plugin, le même que le
     * mapping SNMP de secours (la liaison règle la résolution des cartouches commandées).
     */
    public static function canViewBindings(): bool {
        return Session::haveRight('cartridge', READ) && Session::haveRight('plugin_printgestion_config', READ);
    }

    public static function canEditBindings(CartridgeItem $item): bool {
        return Session::haveRight('plugin_printgestion_config', UPDATE) && $item->can((int) $item->getID(), UPDATE);
    }

    /**
     * Icône de l'onglet. Onglet « Print Gestion » de la fiche Cartouche : sa liaison aux propriétés SNMP des imprimantes.
     *
     * Sans cette méthode, GLPI retombe sur l'icône par défaut de CommonDBTM, qui vaut « fa-empty-icon » et
     * que createTabEntry() remplace alors par rien : le libellé reste nu à côté des onglets natifs.
     */
    static function getIcon() {
        return 'ti ti-printer';
    }

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item->getType() == 'CartridgeItem' && self::canViewBindings()) {
            $nb = countElementsInTable(self::getTable(), ['cartridgeitems_id' => $item->getID()]);
            return self::createTabEntry(__('Print Gestion', 'printgestion'), $nb);
        }
        return '';
    }

    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        // Droits revérifiés à l'affichage : l'onglet peut être appelé directement.
        if ($item->getType() == 'CartridgeItem' && self::canViewBindings() && $item->can((int) $item->getID(), READ)) {
            self::showForCartridge($item);
        }
        return true;
    }

    /**
     * Liste les propriétés SNMP disponibles pour une cartouche donnée.
     *
     * Filtre : seules les propriétés remontées par des imprimantes dont le modèle
     * est déclaré compatible avec cette cartouche (via glpi_cartridgeitems_printermodels).
     * Toutes les valeurs comptent (pourcentage, OK / WARNING, pages restantes…) : une
     * cartouche dont l'imprimante ne remonte qu'un état reste commandable, donc liable.
     * Les états bruts (…max, …used, …remaining) ne sont pas des emplacements : c'est leur
     * emplacement de base qui est proposé.
     */
    public static function getAvailableSnmpProperties(int $cartridgeitems_id): array {
        global $DB;

        if ($cartridgeitems_id <= 0) {
            return [];
        }

        $rows = $DB->request([
            'SELECT'     => ['ci.property'],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_printers_cartridgeinfos AS ci',
            'INNER JOIN' => [
                'glpi_printers AS p' => [
                    'ON' => ['ci' => 'printers_id', 'p' => 'id'],
                ],
                'glpi_cartridgeitems_printermodels AS cpm' => [
                    'ON' => ['p' => 'printermodels_id', 'cpm' => 'printermodels_id'],
                ],
            ],
            'WHERE' => [
                'cpm.cartridgeitems_id' => $cartridgeitems_id,
                'p.is_deleted'          => 0,
                'p.is_template'         => 0,
            ],
            'ORDER' => ['ci.property'],
        ]);

        $properties = [];
        foreach ($rows as $r) {
            $prop  = (string)$r['property'];
            $state = PluginPrintgestionSnmpadapter::getStateBase($prop);
            if ($state !== null) {
                $prop = (string)$state['base'];
            }
            if ($prop !== '' && !in_array($prop, $properties, true)) {
                $properties[] = $prop;
            }
        }

        sort($properties);
        return $properties;
    }

    /**
     * Retourne les propriétés SNMP actuellement bindées à une cartouche.
     */
    public static function getBoundProperties(int $cartridgeitems_id): array {
        global $DB;

        if ($cartridgeitems_id <= 0) {
            return [];
        }

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['snmp_property'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['cartridgeitems_id' => $cartridgeitems_id],
        ]) as $r) {
            $out[] = (string)$r['snmp_property'];
        }
        return $out;
    }

    /**
     * Affiche l'onglet Print Gestion sur la fiche CartridgeItem.
     */
    static function showForCartridge(CartridgeItem $item): void {
        $cartridgeitems_id = (int)$item->getID();
        $canedit = self::canEditBindings($item);

        // Vérifie qu'au moins un modèle d'imprimante est déclaré compatible
        global $DB;
        $has_compat = $DB->request([
            'FROM'  => 'glpi_cartridgeitems_printermodels',
            'WHERE' => ['cartridgeitems_id' => $cartridgeitems_id],
            'LIMIT' => 1,
        ])->count() > 0;

        echo "<div class='card mt-3'>";
        echo "<div class='card-header'><h3 class='card-title mb-0'>"
            . __('Liaison aux propriétés SNMP', 'printgestion') . "</h3></div>";
        echo "<div class='card-body'>";

        if (!$has_compat) {
            echo "<div class='alert alert-warning mb-0'>";
            echo "<i class='fa-solid fa-triangle-exclamation me-1'></i>";
            echo __("Aucun modèle d'imprimante compatible n'est déclaré sur cette cartouche. "
                . "Ajoute d'abord les modèles via l'onglet <strong>Modèles d'imprimantes compatibles</strong> "
                . "ci-dessus, puis reviens ici pour choisir les propriétés SNMP correspondantes.", 'printgestion');
            echo "</div>";
            echo "</div></div>";
            return;
        }

        $available = self::getAvailableSnmpProperties($cartridgeitems_id);
        $bound     = self::getBoundProperties($cartridgeitems_id);
        // Liaisons existantes toujours affichées, même si aucune imprimante compatible ne
        // remonte la propriété en ce moment : elles restent visibles et modifiables.
        $properties = array_values(array_unique(array_merge($available, $bound)));
        sort($properties);

        if (empty($properties)) {
            echo "<div class='alert alert-info mb-0'>";
            echo "<i class='fa-solid fa-circle-info me-1'></i>";
            echo __("Aucune propriété SNMP détectée pour les modèles d'imprimantes compatibles. "
                . "Vérifie que l'agent GLPI a bien inventorié au moins une imprimante de ces modèles, "
                . "puis reviens.", 'printgestion');
            echo "</div>";
            echo "</div></div>";
            return;
        }

        echo "<p class='text-muted small'>"
            . __("Coche les propriétés SNMP qui correspondent à cette cartouche. Le plugin "
                . "l'utilisera automatiquement pour peupler l'onglet Cartouches natif des imprimantes.", 'printgestion')
            . "</p>";

        echo "<form method='post' action='" . PLUGIN_PRINTGESTION_WEBDIR . "/front/cartridgesnmp.form.php'>";
        echo Html::hidden('cartridgeitems_id', ['value' => $cartridgeitems_id]);

        $entries = [];
        foreach ($properties as $prop) {
            $checked = in_array($prop, $bound, true) ? 'checked' : '';
            $prop_h  = htmlspecialchars($prop, ENT_QUOTES, 'UTF-8');
            if ($canedit) {
                // Propriétés affichées : seules celles-ci sont modifiées à l'enregistrement.
                $linked = "<input type='hidden' name='shown[]' value='{$prop_h}'>"
                    . "<input type='checkbox' class='form-check-input' "
                    . "name='bound[]' value='{$prop_h}' {$checked}>";
            } else {
                $linked = $checked ? '✓' : '—';
            }
            $property = $prop_h;
            if (!in_array($prop, $available, true)) {
                $property .= " <span class='badge bg-light text-muted border ms-1'>"
                    . __('non remontée actuellement par les imprimantes compatibles', 'printgestion') . "</span>";
            }
            $entries[] = [
                'bound'    => "<div class='text-center'>{$linked}</div>",
                'property' => $property,
            ];
        }

        // Cases bound[] et champs shown[] du formulaire, lus par front/cartridgesnmp.form.php : pas des actions
        // massives, $massive à null (des cases natives posteraient item[…][id]). data-pg-noclick : la ligne ne
        // s'ouvre pas d'un clic, comme avant. mb-3 : la marge sous le tableau, que le gabarit retire (mb-0).
        echo "<div class='mb-3' data-pg-noclick='1'>" . PluginPrintgestionUi::datatable([
            'bound'    => ['label' => "<span class='d-block text-center'>" . htmlspecialchars(__('Lié', 'printgestion'), ENT_QUOTES, 'UTF-8') . "</span>", 'raw_header' => true],
            'property' => __('Propriété SNMP', 'printgestion'),
        ], $entries, ['bound' => 'raw_html', 'property' => 'raw_html']) . "</div>";

        if ($canedit) {
            echo "<div class='text-center mt-3'>";
            echo "<button type='submit' name='save_bindings' value='1' class='btn btn-primary'>"
                . "<i class='fa-solid fa-save me-1'></i>" . _sx('button', 'Save') . "</button>";
            echo "</div>";
        }

        Html::closeForm();
        echo "</div></div>";
    }

    // ── Association rapide depuis la commande de toner ────────────────────────

    /**
     * Associer une référence sans quitter la commande : droit de configuration du plugin (la liaison règle la
     * résolution des cartouches, comme l'onglet) et droit natif de créer ou de modifier une cartouche.
     */
    public static function canQuickLink(): bool {
        return Session::haveRight('plugin_printgestion_config', UPDATE)
            && (Session::haveRight('cartridge', CREATE) || Session::haveRight('cartridge', UPDATE));
    }

    /**
     * Toners commandés sans cartouche faute de cartouche liée et compatible (REF_NOT_FOUND), regroupés par modèle
     * d'imprimante et propriété : une seule référence à saisir pour deux imprimantes du même modèle. Les autres
     * échecs (modèle non renseigné, plusieurs cartouches possibles) ne se règlent pas en créant une cartouche.
     *
     * @param array $items [['printers_id', 'property']]
     * @return array md5(modèle|propriété) => ['printermodels_id', 'model_name', 'property', 'manufacturers_id',
     *               'cartridgeitemtypes_id', 'printers' => [id => nom]]
     */
    public static function getMissingReferences(array $items): array {
        global $DB;

        $groups = [];
        foreach ($items as $item) {
            $printers_id = (int) $item['printers_id'];
            $property    = (string) $item['property'];
            if (PluginPrintgestionSnmpmapping::resolveCartridge($printers_id, $property)['error'] !== PluginPrintgestionSnmpmapping::REF_NOT_FOUND) {
                continue;
            }
            $printer = $DB->request([
                'SELECT'    => ['p.name', 'p.printermodels_id', 'p.manufacturers_id', 'pm.name AS model_name'],
                'FROM'      => 'glpi_printers AS p',
                'LEFT JOIN' => [
                    'glpi_printermodels AS pm' => ['ON' => ['p' => 'printermodels_id', 'pm' => 'id']],
                ],
                'WHERE'     => ['p.id' => $printers_id],
                'LIMIT'     => 1,
            ])->current();
            if (!is_array($printer) || (int) $printer['printermodels_id'] <= 0) {
                continue;
            }
            $key = md5((int) $printer['printermodels_id'] . '|' . $property);
            if (!isset($groups[$key])) {
                $map = PluginPrintgestionSnmpmapping::resolveForPrinter($printers_id, $property);
                $groups[$key] = [
                    'printermodels_id'      => (int) $printer['printermodels_id'],
                    'model_name'            => (string) $printer['model_name'],
                    'property'              => $property,
                    'manufacturers_id'      => (int) $printer['manufacturers_id'],
                    'cartridgeitemtypes_id' => is_array($map) ? (int) ($map['cartridgeitemtypes_id'] ?? 0) : 0,
                    'printers'              => [],
                ];
            }
            $groups[$key]['printers'][$printers_id] = (string) $printer['name'];
        }
        return $groups;
    }

    /** Nom retenu quand le champ reste vide et que Sage ne donne pas de libellé : « Toner jaune iR-ADV C5550 ». */
    private static function suggestName(array $group): string {
        $colors = [
            'black'   => __('noir', 'printgestion'),
            'cyan'    => __('cyan', 'printgestion'),
            'magenta' => __('magenta', 'printgestion'),
            'yellow'  => __('jaune', 'printgestion'),
        ];
        $color = PluginPrintgestionSnmpmapping::detectColor($group['property']);
        $label = str_starts_with(mb_strtolower($group['property']), 'toner') && isset($colors[$color])
            ? sprintf(__('Toner %s', 'printgestion'), $colors[$color])
            : $group['property'];
        return trim($label . ' ' . $group['model_name']);
    }

    /**
     * Sous-formulaire « Commander » : une case par modèle et propriété sans cartouche, qui déplie les champs utiles
     * d'une cartouche (référence, nom, fabricant, type). Rien n'est écrit avant l'envoi de la commande.
     */
    public static function renderQuickLink(array $groups): string {
        if (empty($groups) || !self::canQuickLink()) {
            return '';
        }
        $esc  = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $html = "<div class='text-start mb-3'>";
        foreach ($groups as $key => $group) {
            $id    = 'pg-newcart-' . $key;
            $name  = 'pg_newcart[' . $key . ']';
            $html .= "<div class='border rounded px-3 py-2 mb-2'>"
                . "<div class='form-check mb-0'>"
                . "<input type='checkbox' class='form-check-input' name='{$name}[enabled]' value='1' id='{$id}-on'"
                . " data-bs-toggle='collapse' data-bs-target='#{$id}' aria-controls='{$id}' aria-expanded='false'>"
                . "<label class='form-check-label' for='{$id}-on'><i class='ti ti-link me-1'></i>"
                . $esc(sprintf(__('Associer une réf cartouche : %1$s — %2$s', 'printgestion'), $group['model_name'], $group['property']))
                . " <span class='text-muted small'>(" . $esc(implode(', ', $group['printers'])) . ")</span></label></div>"
                . "<div class='collapse' id='{$id}'><div class='row g-2 mt-1'>"
                . "<div class='col-md-6'><label class='form-label small mb-1' for='{$id}-ref'>" . $esc(__('Référence', 'printgestion'))
                . " <span class='text-danger'>*</span></label>"
                . "<input type='text' class='form-control' name='{$name}[ref]' id='{$id}-ref' maxlength='255' autocomplete='off'></div>"
                . "<div class='col-md-6'><label class='form-label small mb-1' for='{$id}-name'>" . $esc(__('Nom', 'printgestion')) . "</label>"
                . "<input type='text' class='form-control' name='{$name}[name]' id='{$id}-name' maxlength='255'"
                . " placeholder='" . $esc(self::suggestName($group)) . "'></div>"
                . "<div class='col-md-6'><label class='form-label small mb-1'>" . $esc(Manufacturer::getTypeName(1)) . "</label>"
                . Manufacturer::dropdown([
                    'name'    => "{$name}[manufacturers_id]",
                    'value'   => $group['manufacturers_id'],
                    'width'   => '100%',
                    'display' => false,
                ])
                . "</div><div class='col-md-6'><label class='form-label small mb-1'>" . $esc(CartridgeItemType::getTypeName(1)) . "</label>"
                . CartridgeItemType::dropdown([
                    'name'    => "{$name}[cartridgeitemtypes_id]",
                    'value'   => $group['cartridgeitemtypes_id'],
                    'width'   => '100%',
                    'display' => false,
                ])
                . "</div></div><div class='form-text'>" . $esc(sprintf(
                    __('À l\'envoi de la commande : cartouche créée (nom vide = libellé Sage de la référence, sinon le nom grisé), déclarée compatible avec « %1$s » et liée à « %2$s ». Une cartouche qui porte déjà cette référence est reprise, pas dupliquée.', 'printgestion'),
                    $group['model_name'],
                    $group['property']
                )) . "</div></div></div>";
        }
        return $html . "</div>";
    }

    /**
     * Contrôle d'une saisie (référence, référentiel Sage, droits, doublons), sans rien écrire : le même pour l'aperçu
     * en direct et pour la création à l'envoi.
     *
     * @return array ['error' => ?string, 'plan' => ?array (ce qui sera créé ou repris), 'note' => string (lisible)]
     */
    private static function planQuickLink(array $group, array $data): array {
        global $DB;

        $fail = static fn(string $message) => ['error' => $message, 'plan' => null, 'note' => ''];
        if (!self::canQuickLink()) {
            return $fail(__('droit de créer une cartouche ou de configurer Print Gestion manquant.', 'printgestion'));
        }
        $ref = trim((string) ($data['ref'] ?? ''));
        if ($ref === '') {
            return $fail(__('référence de la cartouche à saisir.', 'printgestion'));
        }
        if (mb_strlen($ref) > 255) {
            return $fail(__('référence trop longue (255 caractères au plus).', 'printgestion'));
        }
        // Une référence inconnue de Sage ferait refuser la commande : la cartouche n'est pas créée pour rien.
        if (PluginPrintgestionSage::hasReferential(PluginPrintgestionSageimport::TABLE_ARTICLES)
            && !PluginPrintgestionSage::isArticleActive($ref)) {
            return $fail(sprintf(__('référence « %s » absente du référentiel articles Sage.', 'printgestion'), $ref));
        }

        $entities_id = Session::getActiveEntity();
        $existing    = self::findByRef($ref);
        $ci          = new CartridgeItem();
        if (count($existing) > 1) {
            return $fail(sprintf(
                __('plusieurs cartouches portent la référence « %1$s » (%2$s) : déclarez la bonne compatible depuis sa fiche.', 'printgestion'),
                $ref,
                implode(', ', $existing)
            ));
        }
        if (!empty($existing) && !$ci->can((int) array_key_first($existing), UPDATE)) {
            return $fail(sprintf(__('la cartouche « %s » porte déjà cette référence mais vous ne pouvez pas la modifier.', 'printgestion'), reset($existing)));
        }
        $new_input = ['entities_id' => $entities_id];
        if (empty($existing) && !$ci->can(-1, CREATE, $new_input)) {
            return $fail(__('droit de créer une cartouche dans l\'entité active manquant.', 'printgestion'));
        }

        if (!empty($existing)) {
            $name = (string) reset($existing);
            $note = sprintf(__('cartouche existante « %1$s » (réf. %2$s) reprise à l\'envoi.', 'printgestion'), $name, $ref);
        } else {
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                $sage = $DB->request([
                    'SELECT' => ['label'],
                    'FROM'   => PluginPrintgestionSageimport::TABLE_ARTICLES,
                    'WHERE'  => ['ref' => $ref],
                    'LIMIT'  => 1,
                ])->current();
                $name = is_array($sage) ? trim((string) $sage['label']) : '';
            }
            $name = $name !== '' ? $name : self::suggestName($group);
            $note = sprintf(__('cartouche « %1$s » (réf. %2$s) créée à l\'envoi.', 'printgestion'), $name, $ref);
        }
        return [
            'error' => null,
            'note'  => $note,
            'plan'  => [
                'group'                 => $group,
                'label'                 => sprintf('%1$s — %2$s', $group['model_name'], $group['property']),
                'ref'                   => $ref,
                'name'                  => $name,
                'entities_id'           => $entities_id,
                'manufacturers_id'      => max(0, (int) ($data['manufacturers_id'] ?? 0)),
                'cartridgeitemtypes_id' => max(0, (int) ($data['cartridgeitemtypes_id'] ?? 0)),
            ],
        ];
    }

    /** Groupes cochés dans le formulaire, présents parmi ceux à régler : clé => saisie. */
    private static function checkedGroups(array $groups, $input): array {
        $out = [];
        foreach (is_array($input) ? $input : [] as $key => $data) {
            // Ligne résolue entre-temps (autre onglet, autre utilisateur) : plus rien à associer.
            if (is_array($data) && !empty($data['enabled']) && isset($groups[(string) $key])) {
                $out[(string) $key] = $data;
            }
        }
        return $out;
    }

    /**
     * Aperçu en direct des saisies, par ligne de commande (« printers_id|property ») : la cartouche telle qu'elle
     * sera écrite dans le fichier Gesconso, ou pourquoi la saisie est refusée. Une case cochée sans référence reste
     * hors aperçu (la ligne garde son motif d'origine).
     *
     * @return array ['cartridges' => clé => ['ref', 'name'], 'notes' => clé => string, 'errors' => clé => string]
     */
    public static function previewQuickLink(array $groups, $input): array {
        $out = ['cartridges' => [], 'notes' => [], 'errors' => []];
        foreach (self::checkedGroups($groups, $input) as $key => $data) {
            if (trim((string) ($data['ref'] ?? '')) === '') {
                continue;
            }
            $check = self::planQuickLink($groups[$key], $data);
            foreach (array_keys($groups[$key]['printers']) as $printers_id) {
                $line = $printers_id . '|' . $groups[$key]['property'];
                if ($check['error'] !== null) {
                    $out['errors'][$line] = $check['error'];
                    continue;
                }
                $out['cartridges'][$line] = ['ref' => $check['plan']['ref'], 'name' => $check['plan']['name']];
                $out['notes'][$line]      = $check['note'];
            }
        }
        return $out;
    }

    /**
     * Crée ou reprend les cartouches cochées dans le sous-formulaire « Commander », juste avant la commande. Tout
     * est vérifié d'abord (planQuickLink()) : une seule erreur et rien n'est écrit.
     *
     * @param array $groups getMissingReferences() des lignes commandées, recalculé côté serveur
     * @param mixed $input  champ pg_newcart du formulaire
     * @return array ['ok' => bool, 'done' => bool (au moins une cartouche créée ou liée), 'messages' => string[]]
     */
    public static function applyQuickLink(array $groups, $input): array {
        global $DB;

        $out   = ['ok' => true, 'done' => false, 'messages' => []];
        $plans = [];
        foreach (self::checkedGroups($groups, $input) as $key => $data) {
            $check = self::planQuickLink($groups[$key], $data);
            if ($check['error'] !== null) {
                $out['ok']         = false;
                $out['messages'][] = sprintf('%1$s — %2$s', $groups[$key]['model_name'], $groups[$key]['property']) . ' : ' . $check['error'];
                continue;
            }
            $plans[] = $check['plan'];
        }
        if (!$out['ok'] || empty($plans)) {
            return $out;
        }

        $DB->beginTransaction();
        try {
            foreach ($plans as $plan) {
                $group = $plan['group'];
                // Relu ici : la même référence pour deux modèles reprend la cartouche créée à la ligne précédente.
                $existing = self::findByRef($plan['ref']);
                $ci       = new CartridgeItem();
                if (!empty($existing)) {
                    $cartridgeitems_id = (int) array_key_first($existing);
                    $ci->getFromDB($cartridgeitems_id);
                    $message = __('Cartouche existante « %1$s » (réf. %2$s) déclarée compatible avec « %3$s » et liée à « %4$s ».', 'printgestion');
                } else {
                    $cartridgeitems_id = (int) $ci->add([
                        'name'                  => $plan['name'],
                        'ref'                   => $plan['ref'],
                        'entities_id'           => $plan['entities_id'],
                        'is_recursive'          => 1,
                        'manufacturers_id'      => $plan['manufacturers_id'],
                        'cartridgeitemtypes_id' => $plan['cartridgeitemtypes_id'],
                        'alarm_threshold'       => Entity::getUsedConfig('cartridges_alert_repeat', $plan['entities_id'], 'default_cartridges_alarm_threshold', 10),
                        'comment'               => __('Créée depuis la commande de toner Print Gestion.', 'printgestion'),
                    ]);
                    if ($cartridgeitems_id <= 0) {
                        throw new RuntimeException($plan['label'] . ' : ' . __('création de la cartouche refusée par GLPI.', 'printgestion'));
                    }
                    $message = __('Cartouche « %1$s » (réf. %2$s) créée, compatible avec « %3$s » et liée à « %4$s ».', 'printgestion');
                }

                $compat = ['cartridgeitems_id' => $cartridgeitems_id, 'printermodels_id' => $group['printermodels_id']];
                if (countElementsInTable('glpi_cartridgeitems_printermodels', $compat) === 0
                    && !(new CartridgeItem_PrinterModel())->add($compat)) {
                    throw new RuntimeException($plan['label'] . ' : ' . __('compatibilité avec le modèle non enregistrée.', 'printgestion'));
                }
                $binding = ['cartridgeitems_id' => $cartridgeitems_id, 'snmp_property' => $group['property']];
                if (countElementsInTable(self::getTable(), $binding) === 0) {
                    $DB->insert(self::getTable(), $binding);
                }
                $out['messages'][] = sprintf($message, (string) $ci->fields['name'], $plan['ref'], $group['model_name'], $group['property']);
            }
            $DB->commit();
        } catch (\Throwable $e) {
            $DB->rollBack();
            return [
                'ok'       => false,
                'done'     => false,
                'messages' => [$e instanceof RuntimeException ? $e->getMessage() : __('Association des cartouches annulée : erreur à l\'enregistrement.', 'printgestion')],
            ];
        }

        PluginPrintgestionSnmpmapping::clearResolved();
        $out['done'] = true;
        return $out;
    }

    /** Cartouches non supprimées portant cette référence (casse ignorée par la collation) : id => nom. */
    private static function findByRef(string $ref): array {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => 'glpi_cartridgeitems',
            'WHERE'  => ['ref' => $ref, 'is_deleted' => 0],
            'ORDER'  => ['id'],
        ]) as $row) {
            $out[(int) $row['id']] = (string) $row['name'];
        }
        return $out;
    }

    static function install(Migration $migration) { return true; }
    static function uninstall(Migration $migration) { return true; }
}
