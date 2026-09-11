<?php
/**
 * PluginPrintgestionContractrate — Point 1 : tarifs N&B / Couleur par contrat.
 * Onglet ajouté sur la fiche Contract GLPI.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionContractrate extends CommonDBTM {

    static $rightname = 'contract';

    static function getTypeName($nb = 0) {
        return _n('Tarif Print Gestion', 'Tarifs Print Gestion', $nb, 'printgestion');
    }

    public static function getTable($classname = null) {
        if ($classname === null || $classname === static::class) {
            return 'glpi_plugin_printgestion_contractrates';
        }
        return parent::getTable($classname);
    }

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item->getType() == 'Contract' && Session::haveRight('contract', READ)) {
            $nb = countElementsInTable(self::getTable(), ['contracts_id' => $item->getID()]);
            return self::createTabEntry(__('Tarifs Print Gestion', 'printgestion'), $nb);
        }
        return '';
    }

    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        if ($item->getType() == 'Contract') {
            self::showForContract($item);
        }
        return true;
    }

    static function showForContract(Contract $contract) {
        global $DB;

        $contracts_id = (int)$contract->getID();
        $canedit      = $contract->can($contracts_id, UPDATE);

        $rates = [];
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['contracts_id' => $contracts_id],
            'ORDER' => 'id ASC',
        ]) as $r) {
            $rates[] = $r;
        }

        echo "<div class='card mt-3'>";
        echo "<div class='card-header'><h3 class='card-title mb-0'>"
            . __('Tarifs par page N&B / Couleur', 'printgestion') . "</h3></div>";
        echo "<div class='card-body'>";

        if ($canedit) {
            echo "<form method='post' action='" . PLUGIN_PRINTGESTION_WEBDIR . "/front/contractrate.form.php'>";
            // CSRF auto-injecté par Html::closeForm() en fin de form.
            echo Html::hidden('contracts_id', ['value' => $contracts_id]);
        }

        echo "<table class='tab_cadre_fixehov' style='width:100%'>";
        echo "<thead><tr class='noHover'>";
        echo "<th style='width:25%'>" . __('Type', 'printgestion') . "</th>";
        echo "<th style='width:35%'>" . __('Tarif (€/page)', 'printgestion') . "</th>";
        echo "<th style='width:20%'>" . __('Actif', 'printgestion') . "</th>";
        if ($canedit) echo "<th style='width:20%'>" . __('Action', 'printgestion') . "</th>";
        echo "</tr></thead><tbody>";

        $type_labels = [
            'nb'    => __('N&B', 'printgestion'),
            'color' => __('Couleur', 'printgestion'),
            'both'  => __('Les deux', 'printgestion'),
        ];

        if (empty($rates)) {
            $col = $canedit ? 4 : 3;
            echo "<tr><td colspan='{$col}' class='text-muted text-center'>"
                . __('Aucun tarif défini', 'printgestion') . "</td></tr>";
        }

        foreach ($rates as $rate) {
            $id = (int)$rate['id'];
            echo "<tr>";
            echo "<td>" . htmlspecialchars($type_labels[$rate['type_cout']] ?? $rate['type_cout'], ENT_QUOTES, 'UTF-8') . "</td>";
            echo "<td>" . number_format((float)$rate['rate'], 6, ',', ' ') . " €</td>";
            echo "<td>" . ((int)$rate['actif'] === 1
                ? '<span class="badge bg-success">' . __('Oui', 'printgestion') . '</span>'
                : '<span class="badge bg-secondary">' . __('Non', 'printgestion') . '</span>') . "</td>";
            if ($canedit) {
                echo "<td><button type='submit' class='btn btn-sm btn-outline-danger' name='delete_rate' value='{$id}' "
                    . "onclick='return confirm(\"" . __('Confirmer la suppression ?', 'printgestion') . "\")'>"
                    . _x('button', 'Delete') . "</button></td>";
            }
            echo "</tr>";
        }

        if ($canedit) {
            echo "<tr class='tab_bg_2'><td>";
            Dropdown::showFromArray('type_cout', $type_labels, ['value' => 'both']);
            echo "</td><td><input type='number' step='0.000001' min='0' name='rate' value='0' class='form-control'></td>";
            echo "<td>";
            Dropdown::showYesNo('actif', 1);
            echo "</td>";
            echo "<td><button type='submit' class='btn btn-sm btn-primary' name='add_rate' value='1'>"
                . _x('button', 'Add') . "</button></td>";
            echo "</tr>";
        }

        echo "</tbody></table>";
        if ($canedit) {
            Html::closeForm();
        }
        echo "</div></div>";
    }

    /**
     * Récupère les tarifs actifs d'un contrat sous forme ['nb' => float, 'color' => float].
     * Si type = 'both' et aucun tarif spécifique défini, applique ce tarif aux deux.
     */
    public static function getRatesForContract(int $contracts_id): array {
        global $DB;

        $out = ['nb' => 0.0, 'color' => 0.0];
        if ($contracts_id <= 0) {
            return $out;
        }

        $both = null;
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'contracts_id' => $contracts_id,
                'actif'        => 1,
            ],
        ]) as $row) {
            $rate = (float)$row['rate'];
            switch ($row['type_cout']) {
                case 'nb':
                    $out['nb'] = $rate;
                    break;
                case 'color':
                    $out['color'] = $rate;
                    break;
                case 'both':
                    $both = $rate;
                    break;
            }
        }

        if ($both !== null) {
            if ($out['nb'] == 0.0)    $out['nb']    = $both;
            if ($out['color'] == 0.0) $out['color'] = $both;
        }

        return $out;
    }

    /**
     * ID du contrat EN COURS aujourd'hui lié à une imprimante ; si plusieurs le sont,
     * le plus récemment commencé. 0 si aucun contrat n'est en cours — un contrat
     * terminé n'est plus retenu (voir notInForceReason()).
     */
    public static function getContractIdForPrinter(int $printers_id): int {
        $today = date('Y-m-d');
        foreach (self::getLinkedContracts($printers_id) as $contract) {
            if (self::notInForceReason($contract, $today) === '') {
                return (int)$contract['id'];
            }
        }
        return 0;
    }

    /**
     * Couverture des consommables d'une imprimante, pour une ligne de commande.
     *
     * SOUS CONTRAT : un contrat en cours lié à l'imprimante a un type natif paramétré
     * « consommables inclus » (configuration) ; si plusieurs, le plus récemment
     * commencé est retenu. HORS CONTRAT dans tous les autres cas, y compris quand aucun
     * type n'est paramétré : le doute ne produit jamais un prix à 0.
     *
     * @return array ['under_contract' => bool,
     *                'contracts_id'   => int (contrat retenu, 0 hors contrat),
     *                'contract_name'  => string,
     *                'message'        => string (motif lisible, texte brut)]
     */
    public static function getConsumablesCoverage(int $printers_id): array {
        $out = ['under_contract' => false, 'contracts_id' => 0, 'contract_name' => '', 'message' => ''];

        $types = PluginPrintgestionConfig::getConsumablesContractTypes();
        if (empty($types)) {
            $out['message'] = __('Hors contrat : aucun type de contrat « consommables inclus » n\'est paramétré (Configuration → Print Gestion).', 'printgestion');
            return $out;
        }

        $today          = date('Y-m-d');
        $linked         = self::getLinkedContracts($printers_id);
        $covering_ended = null;
        $other_in_force = null;
        foreach ($linked as $contract) {
            $reason = self::notInForceReason($contract, $today);
            $covers = in_array((int)$contract['contracttypes_id'], $types, true);
            if ($covers && $reason === '') {
                return [
                    'under_contract' => true,
                    'contracts_id'   => (int)$contract['id'],
                    'contract_name'  => (string)$contract['name'],
                    'message'        => sprintf(__('Sous contrat : « %s ».', 'printgestion'), (string)$contract['name']),
                ];
            }
            if ($covers && $covering_ended === null) {
                $covering_ended = [$contract, $reason];
            } elseif (!$covers && $reason === '' && $other_in_force === null) {
                $other_in_force = $contract;
            }
        }

        if ($covering_ended !== null) {
            $out['message'] = sprintf(
                __('Hors contrat : le contrat « %1$s » n\'est pas en cours (%2$s).', 'printgestion'),
                (string)$covering_ended[0]['name'],
                $covering_ended[1]
            );
        } elseif ($other_in_force !== null) {
            $out['message'] = sprintf(
                __('Hors contrat : le contrat en cours « %s » n\'a pas un type « consommables inclus ».', 'printgestion'),
                (string)$other_in_force['name']
            );
        } elseif (empty($linked)) {
            $out['message'] = __('Hors contrat : aucun contrat lié à l\'imprimante.', 'printgestion');
        } else {
            $out['message'] = __('Hors contrat : aucun contrat en cours lié à l\'imprimante.', 'printgestion');
        }
        return $out;
    }

    /**
     * Contrats (ni supprimés ni modèles) liés à une imprimante, du plus récemment
     * commencé au plus ancien ; date de début absente en dernier.
     */
    private static function getLinkedContracts(int $printers_id): array {
        global $DB;

        if ($printers_id <= 0) {
            return [];
        }

        $out = [];
        foreach ($DB->request([
            'SELECT'     => ['c.id', 'c.name', 'c.contracttypes_id', 'c.begin_date', 'c.duration', 'c.renewal'],
            'FROM'       => 'glpi_contracts_items AS ci',
            'INNER JOIN' => [
                'glpi_contracts AS c' => [
                    'ON' => ['ci' => 'contracts_id', 'c' => 'id'],
                ],
            ],
            'WHERE'      => [
                'ci.items_id'   => $printers_id,
                'ci.itemtype'   => 'Printer',
                'c.is_deleted'  => 0,
                'c.is_template' => 0,
            ],
            'ORDER'      => ['c.begin_date DESC', 'c.id DESC'],
        ]) as $row) {
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Motif pour lequel un contrat n'est pas en cours à la date donnée, chaîne vide
     * s'il l'est. Règle alignée sur Contract::getNotExpiredCriteria() du cœur GLPI, avec
     * en plus la date de début : début renseigné et atteint, puis reconduction tacite ou
     * fin (début + durée en mois) strictement postérieure à la date. Sans durée et sans
     * reconduction tacite, GLPI considère le contrat expiré : il n'est pas en cours.
     */
    private static function notInForceReason(array $contract, string $today): string {
        $begin = substr((string)($contract['begin_date'] ?? ''), 0, 10);
        if ($begin === '') {
            return __('date de début non renseignée', 'printgestion');
        }
        if ($begin > $today) {
            return sprintf(__('commence le %s', 'printgestion'), Html::convDate($begin));
        }
        if ((int)$contract['renewal'] === Contract::RENEWAL_TACIT) {
            return '';
        }
        $duration = (int)$contract['duration'];
        if ($duration <= 0) {
            return __('sans durée ni reconduction tacite', 'printgestion');
        }
        $end = self::contractEndDate($begin, $duration);
        if ($end <= $today) {
            return sprintf(__('terminé le %s', 'printgestion'), Html::convDate($end));
        }
        return '';
    }

    /**
     * Fin d'un contrat : début + durée en mois, jour ramené au dernier jour du mois si
     * besoin — même calcul que DATE_ADD(… INTERVAL n MONTH) utilisé par GLPI.
     */
    private static function contractEndDate(string $begin_date, int $months): string {
        $begin  = new DateTimeImmutable($begin_date);
        $target = $begin->modify('first day of this month')->modify('+' . $months . ' months');
        $day    = min((int)$begin->format('j'), (int)$target->format('t'));
        return $target->setDate((int)$target->format('Y'), (int)$target->format('n'), $day)->format('Y-m-d');
    }

    static function install(Migration $migration) {
        // Table créée par le schéma versionné (PluginPrintgestionSchema)
        return true;
    }

    static function uninstall(Migration $migration) {
        // Table supprimée dans PluginPrintgestionConfig::uninstall()
        return true;
    }
}
