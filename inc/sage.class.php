<?php
/**
 * PluginPrintgestionSage — référentiel Sage vu depuis GLPI : correspondances et onglet
 * « Print Gestion — Sage » sur la fiche Entité.
 *
 * Le référentiel est alimenté UNIQUEMENT par dépôt de fichier (PluginPrintgestionSageimport) :
 * aucune connexion, aucun appel vers Sage.
 *
 * Code client d'une entité : correspondance propre à l'entité, sinon celle de l'ancêtre le
 * plus proche (une sous-entité hérite du client de son parent, comme les réglages GLPI).
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionSage extends CommonGLPI {

    static $rightname = 'plugin_printgestion_config';

    static function getTypeName($nb = 0) {
        return __('Référentiel Sage', 'printgestion');
    }

    // ── Correspondances ───────────────────────────────────────────────────────

    /**
     * Client Sage d'une entité : correspondance propre, sinon celle de l'ancêtre le plus
     * proche. null si aucune.
     *
     * @return ?array ['id', 'code', 'name', 'is_in_last_import',
     *                 'entities_id' (entité portant la correspondance), 'inherited' (bool)]
     */
    public static function getClientForEntity(int $entities_id): ?array {
        global $DB;

        $chain = array_merge([$entities_id], array_map('intval', array_keys(getAncestorsOf('glpi_entities', $entities_id))));

        $best = null;
        foreach ($DB->request([
            'SELECT'     => ['c.id', 'c.code', 'c.name', 'c.is_in_last_import', 'm.entities_id', 'e.level'],
            'FROM'       => PluginPrintgestionSageimport::TABLE_MAPPING . ' AS m',
            'INNER JOIN' => [
                PluginPrintgestionSageimport::TABLE_CLIENTS . ' AS c' => [
                    'ON' => ['m' => 'plugin_printgestion_sageclients_id', 'c' => 'id'],
                ],
            ],
            'LEFT JOIN'  => [
                'glpi_entities AS e' => ['ON' => ['m' => 'entities_id', 'e' => 'id']],
            ],
            'WHERE'      => ['m.entities_id' => array_values(array_unique($chain))],
        ]) as $row) {
            // L'entité elle-même prime ; sinon l'ancêtre de niveau le plus élevé (le plus proche).
            $rank = (int) $row['entities_id'] === $entities_id ? PHP_INT_MAX : (int) $row['level'];
            if ($best === null || $rank > $best['rank']) {
                $best = $row + ['rank' => $rank];
            }
        }
        if ($best === null) {
            return null;
        }

        return [
            'id'                => (int) $best['id'],
            'code'              => (string) $best['code'],
            'name'              => (string) $best['name'],
            'is_in_last_import' => (int) $best['is_in_last_import'] === 1,
            'entities_id'       => (int) $best['entities_id'],
            'inherited'         => (int) $best['entities_id'] !== $entities_id,
        ];
    }

    /**
     * Lie une entité à un client Sage (0 = retirer la correspondance propre : l'entité
     * hérite alors de son parent). Journalisé dans l'historique de l'entité.
     *
     * @return string Message d'erreur, chaîne vide en cas de succès.
     */
    public static function setEntityClient(int $entities_id, int $sageclients_id): string {
        global $DB;

        $entity = new Entity();
        if (!$entity->getFromDB($entities_id)) {
            return __('Entité introuvable.', 'printgestion');
        }

        $client = null;
        if ($sageclients_id > 0) {
            $client = $DB->request([
                'FROM'  => PluginPrintgestionSageimport::TABLE_CLIENTS,
                'WHERE' => ['id' => $sageclients_id],
                'LIMIT' => 1,
            ])->current();
            if (!is_array($client)) {
                return __('Client Sage introuvable dans le référentiel.', 'printgestion');
            }
        }

        $current = $DB->request([
            'SELECT'    => ['m.id', 'm.plugin_printgestion_sageclients_id', 'c.code'],
            'FROM'      => PluginPrintgestionSageimport::TABLE_MAPPING . ' AS m',
            'LEFT JOIN' => [
                PluginPrintgestionSageimport::TABLE_CLIENTS . ' AS c' => [
                    'ON' => ['m' => 'plugin_printgestion_sageclients_id', 'c' => 'id'],
                ],
            ],
            'WHERE'     => ['m.entities_id' => $entities_id],
            'LIMIT'     => 1,
        ])->current();

        $now = $_SESSION['glpi_currenttime'];
        if ($client === null) {
            if (!is_array($current)) {
                return '';
            }
            $DB->delete(PluginPrintgestionSageimport::TABLE_MAPPING, ['id' => (int) $current['id']]);
            self::logOnEntity($entities_id, sprintf(
                __('Print Gestion : correspondance avec le client Sage %s retirée.', 'printgestion'),
                (string) $current['code']
            ));
            return '';
        }

        if (is_array($current)) {
            if ((int) $current['plugin_printgestion_sageclients_id'] === $sageclients_id) {
                return '';
            }
            $DB->update(
                PluginPrintgestionSageimport::TABLE_MAPPING,
                ['plugin_printgestion_sageclients_id' => $sageclients_id, 'date_mod' => $now],
                ['id' => (int) $current['id']]
            );
        } else {
            $DB->insert(PluginPrintgestionSageimport::TABLE_MAPPING, [
                'entities_id'                        => $entities_id,
                'plugin_printgestion_sageclients_id' => $sageclients_id,
                'date_creation'                      => $now,
                'date_mod'                           => $now,
            ]);
        }
        self::logOnEntity($entities_id, sprintf(
            __('Print Gestion : liée au client Sage %1$s (%2$s).', 'printgestion'),
            (string) $client['code'],
            (string) $client['name']
        ));
        return '';
    }

    /** Message dans l'historique natif de l'entité. */
    public static function logOnEntity(int $entities_id, string $message): void {
        Log::history($entities_id, Entity::class, [0, '', $message], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
    }

    // ── Onglet sur la fiche Entité ────────────────────────────────────────────

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item instanceof Entity && Session::haveRight(self::$rightname, READ)) {
            return __('Print Gestion — Sage', 'printgestion');
        }
        return '';
    }

    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        if ($item instanceof Entity) {
            self::showForEntity($item);
        }
        return true;
    }

    private static function showForEntity(Entity $entity): void {
        global $DB;

        $esc         = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $entities_id = (int) $entity->getID();
        $client      = self::getClientForEntity($entities_id);
        $canedit     = Session::haveRight(self::$rightname, UPDATE);

        echo "<div class='card mt-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . $esc(__('Client Sage', 'printgestion')) . "</h3></div><div class='card-body'>";

        if ($client === null) {
            echo "<div class='alert alert-warning'>"
                . $esc(__('Aucun code client Sage (ni propre à l\'entité, ni hérité d\'une entité parente) : les demandes de cette entité ne peuvent pas être exportées.', 'printgestion'))
                . "</div>";
        } else {
            echo "<p class='mb-2'><strong>" . $esc($client['code']) . "</strong> — " . $esc($client['name']);
            if ($client['inherited']) {
                echo " <span class='text-muted'>(" . $esc(sprintf(
                    __('hérité de %s', 'printgestion'),
                    Dropdown::getDropdownName('glpi_entities', $client['entities_id'])
                )) . ")</span>";
            }
            if (!$client['is_in_last_import']) {
                echo " <span class='badge bg-red text-red-fg'>" . $esc(__('Absent du dernier import clients', 'printgestion')) . "</span>";
            }
            echo "</p>";
        }

        if ($canedit) {
            $own = $DB->request([
                'SELECT' => ['plugin_printgestion_sageclients_id'],
                'FROM'   => PluginPrintgestionSageimport::TABLE_MAPPING,
                'WHERE'  => ['entities_id' => $entities_id],
                'LIMIT'  => 1,
            ])->current();
            $own_id = is_array($own) ? (int) $own['plugin_printgestion_sageclients_id'] : 0;

            $choices = [0 => __('— Aucune correspondance propre (hérite du parent) —', 'printgestion')];
            foreach ($DB->request([
                'SELECT' => ['id', 'code', 'name', 'is_in_last_import'],
                'FROM'   => PluginPrintgestionSageimport::TABLE_CLIENTS,
                'WHERE'  => ['OR' => ['is_in_last_import' => 1, 'id' => $own_id]],
                'ORDER'  => ['code'],
            ]) as $row) {
                $choices[(int) $row['id']] = $row['code'] . ' — ' . $row['name']
                    . ((int) $row['is_in_last_import'] === 1 ? '' : ' ' . __('(absent du dernier import)', 'printgestion'));
            }

            echo "<form method='post' action='" . $esc(PLUGIN_PRINTGESTION_WEBDIR . '/front/sage.form.php') . "' class='d-flex flex-wrap gap-2 align-items-center'>";
            echo Html::hidden('entities_id', ['value' => $entities_id]);
            Dropdown::showFromArray('plugin_printgestion_sageclients_id', $choices, ['value' => $own_id, 'width' => '40rem']);
            echo "<button type='submit' name='save_entity_client' value='1' class='btn btn-primary'>"
                . $esc(_sx('button', 'Save')) . "</button>";
            Html::closeForm();
        }
        echo "</div></div>";

        if ($client === null) {
            return;
        }

        // Adresses de livraison du client et lieux GLPI rapprochés par Location.code.
        $addresses = iterator_to_array($DB->request([
            'FROM'  => PluginPrintgestionSageimport::TABLE_DELIVERIES,
            'WHERE' => ['client_code' => $client['code']],
            'ORDER' => ['label'],
        ]), false);
        $locations_by_code = [];
        if (!empty($addresses)) {
            foreach ($DB->request([
                'SELECT' => ['id', 'code', 'completename'],
                'FROM'   => 'glpi_locations',
                'WHERE'  => ['code' => array_values(array_unique(array_column($addresses, 'address_key')))],
            ]) as $location) {
                $locations_by_code[mb_strtoupper((string) $location['code'])][] = $location;
            }
        }

        echo "<div class='card mt-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . $esc(sprintf(__('Adresses de livraison Sage du client %s', 'printgestion'), $client['code']))
            . "</h3></div>";
        if (empty($addresses)) {
            echo "<div class='card-body text-muted'>" . $esc(__('Aucune adresse de livraison importée pour ce client.', 'printgestion')) . "</div></div>";
            return;
        }
        echo "<div class='table-responsive'><table class='table table-sm card-table'><thead><tr>"
            . "<th>" . $esc(__('Intitulé livraison', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Code adresse (Location.code)', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Adresse', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Lieux GLPI', 'printgestion')) . "</th>"
            . "</tr></thead><tbody>";
        foreach ($addresses as $address) {
            $matches = $locations_by_code[mb_strtoupper((string) $address['address_key'])] ?? [];
            $cell    = empty($matches)
                ? "<span class='text-warning'>" . $esc(__('Aucun lieu avec ce code', 'printgestion')) . "</span>"
                : implode('<br>', array_map(
                    static fn(array $l) => "<a href='" . $esc(Location::getFormURLWithID((int) $l['id'])) . "'>" . $esc($l['completename']) . '</a>',
                    $matches
                ));
            echo "<tr" . ((int) $address['is_in_last_import'] === 1 ? '' : " class='text-muted'") . ">"
                . "<td>" . $esc($address['label'])
                . ((int) $address['is_in_last_import'] === 1 ? '' : ' (' . $esc(__('absente du dernier import', 'printgestion')) . ')') . "</td>"
                . "<td><code>" . $esc($address['address_key']) . "</code></td>"
                . "<td>" . $esc(trim($address['address'] . ' ' . $address['postcode'] . ' ' . $address['town'])) . "</td>"
                . "<td>{$cell}</td></tr>";
        }
        echo "</tbody></table></div></div>";
    }
}
