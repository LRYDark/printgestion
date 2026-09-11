<?php
/**
 * PluginPrintgestionPrint — sous-onglet 2 : création/association souple
 * imprimante ↔ contrat.
 *
 * Couvre les 4 scénarios (contrat nouveau/existant × imprimante nouvelle/existante)
 * avec les classes NATIVES GLPI (Contract, Printer, Contract_Item, Location…),
 * en transaction, avec cohérence d'entité et anti-doublon de liaison.
 *
 * L'autorisation est portée par le bit CREATE du droit du plugin (vérifié côté
 * affichage ET côté traitement dans front/print.form.php).
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionPrint extends CommonGLPI {

    static $rightname = 'plugin_printgestion_contrats';

    static function getTypeName($nb = 0) {
        return __('Créer Print', 'printgestion');
    }

    // ── Helpers de rendu ──────────────────────────────────────────────────────

    private static function label(string $text, bool $required = false): string {
        $out = "<label class='form-label'>" . htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        if ($required) {
            $out .= " <span class='text-danger'>*</span>";
        }
        return $out . "</label>";
    }

    /**
     * Rend le formulaire dynamique « Créer Print ».
     */
    public static function showForm(): void {
        $active_entity   = $_SESSION['glpiactive_entity'] ?? 0;
        $active_entities = $_SESSION['glpiactiveentities'] ?? [$active_entity];

        echo "<form method='post' id='printgestion-create-form' action='"
            . htmlspecialchars(PLUGIN_PRINTGESTION_WEBDIR . '/front/print.form.php', ENT_QUOTES, 'UTF-8')
            . "'>";

        // ── Card MODES ───────────────────────────────────────────────────────
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . __('Mode', 'printgestion') . "</h3></div><div class='card-body'>";
        echo "<div class='row g-3'>";

        echo "<div class='col-md-6'>";
        echo self::label(__('Contrat', 'printgestion'));
        self::renderModeRadios('contract_mode', 'new', [
            'new'      => __('Nouveau contrat', 'printgestion'),
            'existing' => __('Contrat existant', 'printgestion'),
        ]);
        echo "</div>";

        echo "<div class='col-md-6'>";
        echo self::label(__('Imprimante', 'printgestion'));
        self::renderModeRadios('printer_mode', 'new', [
            'new'      => __('Nouvelle imprimante', 'printgestion'),
            'existing' => __('Imprimante existante', 'printgestion'),
        ]);
        echo "</div>";

        echo "</div>"; // row

        // Entité commune (garantit la cohérence d'entité — cf. §3.5c).
        echo "<div class='row g-3 mt-1'>";
        echo "<div class='col-md-6'>";
        echo self::label(__('Entité', 'printgestion'));
        echo "<div>";
        Entity::dropdown([
            'name'  => 'entities_id',
            'value' => $active_entity,
        ]);
        echo "</div>";
        echo "<div class='form-text'>"
            . __("Entité des éléments créés. Si un côté est « existant », son entité prime (cohérence de liaison).", 'printgestion')
            . "</div>";
        echo "</div>";
        echo "</div>";

        echo "</div></div>"; // card body / card

        // ── Card CONTRAT ─────────────────────────────────────────────────────
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . _n('Contrat', 'Contrats', 1, 'printgestion') . "</h3></div><div class='card-body'>";

        // Bloc : contrat existant (masqué par défaut, mode initial = nouveau).
        echo "<div id='gp-contract-existing' class='gp-block' style='display:none'>";
        echo "<div class='row g-3'><div class='col-md-6'>";
        echo self::label(__('Contrat existant', 'printgestion'), true);
        echo "<div>";
        Contract::dropdown([
            'name'   => 'contracts_id',
            'entity' => $active_entities,
            'width'  => '100%', // bloc initialement masqué → évite un select2 trop étroit
        ]);
        echo "</div></div></div>";
        echo "</div>";

        // Bloc : nouveau contrat
        echo "<div id='gp-contract-new' class='gp-block'>";
        echo "<div class='row g-3'>";

        echo "<div class='col-md-6'>" . self::label(__('Nom', 'printgestion'), true)
            . "<input type='text' class='form-control' name='c_name' value=''></div>";

        echo "<div class='col-md-6'>" . self::label(__('Statut', 'printgestion')) . "<div>";
        State::dropdown(['name' => 'c_states_id', 'entity' => $active_entities]);
        echo "</div></div>";

        echo "<div class='col-md-6'>" . self::label(_n('Type', 'Types', 1, 'printgestion')) . "<div>";
        Dropdown::show('ContractType', ['name' => 'contracttypes_id']);
        echo "</div></div>";

        echo "<div class='col-md-6'>" . self::label(__('Date de début', 'printgestion')) . "<div>";
        Html::showDateField('c_begin_date', ['value' => '']);
        echo "</div></div>";

        echo "<div class='col-md-3'>" . self::label(__('Durée initiale (mois)', 'printgestion'))
            . "<input type='number' min='0' class='form-control' name='c_duration' value='0'></div>";

        echo "<div class='col-md-3'>" . self::label(__('Préavis (mois)', 'printgestion'))
            . "<input type='number' min='0' class='form-control' name='c_notice' value='0'></div>";

        echo "</div>"; // row
        echo "</div>"; // gp-contract-new

        echo "</div></div>"; // card

        // ── Card IMPRIMANTE ──────────────────────────────────────────────────
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . _n('Imprimante', 'Imprimantes', 1, 'printgestion') . "</h3></div><div class='card-body'>";

        // Bloc : imprimante existante (masqué par défaut, mode initial = nouvelle).
        echo "<div id='gp-printer-existing' class='gp-block' style='display:none'>";
        echo "<div class='row g-3'><div class='col-md-6'>";
        echo self::label(__('Imprimante existante', 'printgestion'), true);
        echo "<div>";
        Printer::dropdown([
            'name'   => 'printers_id',
            'entity' => $active_entities,
            'width'  => '100%', // bloc initialement masqué → évite un select2 trop étroit
        ]);
        echo "</div></div></div>";
        echo "</div>";

        // Bloc : nouvelle imprimante
        echo "<div id='gp-printer-new' class='gp-block'>";
        echo "<div class='row g-3'>";

        echo "<div class='col-md-6'>" . self::label(__('Nom', 'printgestion'), true)
            . "<input type='text' class='form-control' name='p_name' value=''></div>";

        echo "<div class='col-md-6'>" . self::label(__('Fabricant / Marque', 'printgestion')) . "<div>";
        Manufacturer::dropdown(['name' => 'manufacturers_id']);
        echo "</div></div>";

        echo "<div class='col-md-6'>" . self::label(__('Modèle', 'printgestion')) . "<div>";
        Dropdown::show('PrinterModel', ['name' => 'printermodels_id']);
        echo "</div></div>";

        echo "<div class='col-md-6'>" . self::label(__('Numéro de série', 'printgestion'))
            . "<input type='text' class='form-control' name='p_serial' value=''></div>";

        echo "<div class='col-md-6'>" . self::label(__('Statut', 'printgestion')) . "<div>";
        State::dropdown(['name' => 'p_states_id', 'entity' => $active_entities]);
        echo "</div></div>";

        echo "<div class='col-md-6'>" . self::label(__('Lieu (adresse de livraison)', 'printgestion')) . "<div>";
        Location::dropdown([
            'name'    => 'locations_id',
            'entity'  => $active_entities,
            'addicon' => true, // création de lieu à la volée
        ]);
        echo "</div></div>";

        echo "<div class='col-12'>" . self::label(_n('Commentaire', 'Commentaires', 1, 'printgestion'))
            . "<textarea class='form-control' name='p_comment' rows='2'></textarea></div>";

        echo "</div>"; // row
        echo "</div>"; // gp-printer-new

        echo "</div></div>"; // card

        // ── Submit ───────────────────────────────────────────────────────────
        echo "<div class='d-flex justify-content-end mb-4'>";
        echo Html::submit(__('Créer / Lier', 'printgestion'), [
            'name'  => 'add',
            'icon'  => 'fa-solid fa-link',
            'class' => 'btn btn-primary',
        ]);
        echo "</div>";

        Html::closeForm(); // ajoute le jeton CSRF + </form>
    }

    /**
     * Rend un groupe de boutons radio « mode » (Bootstrap form-check).
     */
    private static function renderModeRadios(string $name, string $default, array $options): void {
        echo "<div class='mt-1'>";
        foreach ($options as $value => $libelle) {
            $id = 'gp-' . $name . '-' . $value;
            $checked = ($value === $default) ? " checked" : "";
            echo "<div class='form-check form-check-inline'>";
            echo "<input class='form-check-input' type='radio' name='" . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                . "' id='" . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . "' value='"
                . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . "'{$checked}>";
            echo "<label class='form-check-label' for='" . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . "'>"
                . htmlspecialchars($libelle, ENT_QUOTES, 'UTF-8') . "</label>";
            echo "</div>";
        }
        echo "</div>";
    }

    // ── Traitement ────────────────────────────────────────────────────────────

    /**
     * Traite la soumission du formulaire.
     *
     * Ordre garanti (cf. §3.5b) : création éventuelle du contrat et de l'imprimante,
     * puis liaison Contract_Item EN DERNIER. Cohérence d'entité forcée (§3.5c) :
     * un nouvel élément hérite de l'entité du côté « existant » s'il y en a un,
     * sinon de l'entité choisie dans le formulaire. Le tout en transaction.
     *
     * @param array $input Données POST.
     * @return string|false URL de redirection (fiche contrat) en cas de succès, sinon false.
     */
    public static function processForm(array $input) {
        global $DB;

        $contract_mode = $input['contract_mode'] ?? '';
        $printer_mode  = $input['printer_mode'] ?? '';

        if (!in_array($contract_mode, ['new', 'existing'], true)
            || !in_array($printer_mode, ['new', 'existing'], true)) {
            Session::addMessageAfterRedirect(
                __('Modes de saisie invalides.', 'printgestion'), false, ERROR
            );
            return false;
        }

        // ── 1) Résoudre les éléments existants + leur entité (avant transaction) ──
        $contract        = new Contract();
        $printer         = new Printer();
        $contracts_id    = 0;
        $printers_id     = 0;
        $contract_entity = null;
        $printer_entity  = null;

        if ($contract_mode === 'existing') {
            $contracts_id = (int) ($input['contracts_id'] ?? 0);
            if ($contracts_id <= 0 || !$contract->getFromDB($contracts_id)) {
                Session::addMessageAfterRedirect(
                    __('Veuillez sélectionner un contrat existant valide.', 'printgestion'), false, ERROR
                );
                return false;
            }
            $contract_entity = (int) $contract->fields['entities_id'];
        }

        if ($printer_mode === 'existing') {
            $printers_id = (int) ($input['printers_id'] ?? 0);
            if ($printers_id <= 0 || !$printer->getFromDB($printers_id)) {
                Session::addMessageAfterRedirect(
                    __('Veuillez sélectionner une imprimante existante valide.', 'printgestion'), false, ERROR
                );
                return false;
            }
            $printer_entity = (int) $printer->fields['entities_id'];
        }

        // Entité des nouveaux éléments : héritée du côté existant si présent,
        // sinon celle du formulaire → garantit la compatibilité Contract_Item.
        if ($contract_mode === 'new' && $printer_mode === 'existing') {
            $new_entity = $printer_entity;
        } elseif ($printer_mode === 'new' && $contract_mode === 'existing') {
            $new_entity = $contract_entity;
        } else {
            $new_entity = (int) ($input['entities_id'] ?? ($_SESSION['glpiactive_entity'] ?? 0));
        }

        // Validation des champs requis selon le mode (ne pas se fier au JS).
        if ($contract_mode === 'new' && trim((string) ($input['c_name'] ?? '')) === '') {
            Session::addMessageAfterRedirect(
                __('Le nom du contrat est obligatoire.', 'printgestion'), false, ERROR
            );
            return false;
        }
        if ($printer_mode === 'new' && trim((string) ($input['p_name'] ?? '')) === '') {
            Session::addMessageAfterRedirect(
                __("Le nom de l'imprimante est obligatoire.", 'printgestion'), false, ERROR
            );
            return false;
        }

        // ── 2) Transaction : créations puis liaison (toujours en dernier) ───────
        $DB->beginTransaction();
        try {
            if ($contract_mode === 'new') {
                $contract_input = [
                    'name'             => $input['c_name'],
                    'states_id'        => (int) ($input['c_states_id'] ?? 0),
                    'contracttypes_id' => (int) ($input['contracttypes_id'] ?? 0),
                    'duration'         => (int) ($input['c_duration'] ?? 0),
                    'notice'           => (int) ($input['c_notice'] ?? 0),
                    'entities_id'      => $new_entity,
                    'is_recursive'     => 0,
                ];
                // Date de début : omise si vide → la colonne garde son défaut NULL.
                $begin_date = trim((string) ($input['c_begin_date'] ?? ''));
                if ($begin_date !== '') {
                    $contract_input['begin_date'] = $begin_date;
                }

                $contracts_id = (int) $contract->add($contract_input);
                if ($contracts_id <= 0) {
                    throw new RuntimeException('contract_add_failed');
                }
            }

            if ($printer_mode === 'new') {
                $printers_id = (int) $printer->add([
                    'name'             => $input['p_name'],
                    'manufacturers_id' => (int) ($input['manufacturers_id'] ?? 0),
                    'printermodels_id' => (int) ($input['printermodels_id'] ?? 0),
                    'serial'           => (string) ($input['p_serial'] ?? ''),
                    'states_id'        => (int) ($input['p_states_id'] ?? 0),
                    'locations_id'     => (int) ($input['locations_id'] ?? 0),
                    'comment'          => (string) ($input['p_comment'] ?? ''),
                    'entities_id'      => $new_entity,
                    'is_recursive'     => 0,
                ]);
                if ($printers_id <= 0) {
                    throw new RuntimeException('printer_add_failed');
                }
            }

            // Liaison Contract_Item — EN DERNIER, anti-doublon.
            $already = countElementsInTable('glpi_contracts_items', [
                'contracts_id' => $contracts_id,
                'itemtype'     => 'Printer',
                'items_id'     => $printers_id,
            ]) > 0;

            if (!$already) {
                $contract_item = new Contract_Item();
                $link_id = $contract_item->add([
                    'contracts_id' => $contracts_id,
                    'itemtype'     => 'Printer',
                    'items_id'     => $printers_id,
                ]);
                if (!$link_id) {
                    // Échec typique : incompatibilité d'entité contrat/imprimante.
                    throw new RuntimeException('link_failed');
                }
            }

            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            PluginPrintgestionLogger::error(
                'Print::processForm',
                sprintf('Création / liaison contrat ↔ imprimante annulée (modes : contrat %s, imprimante %s).', $contract_mode, $printer_mode),
                $e
            );
            $msg = __("Échec de l'enregistrement.", 'printgestion');
            if ($e->getMessage() === 'link_failed') {
                $msg = __("Échec de la liaison contrat ↔ imprimante (entités incompatibles ?).", 'printgestion');
            } elseif ($e->getMessage() === 'contract_add_failed') {
                $msg = __('Échec de la création du contrat.', 'printgestion');
            } elseif ($e->getMessage() === 'printer_add_failed') {
                $msg = __("Échec de la création de l'imprimante.", 'printgestion');
            }
            Session::addMessageAfterRedirect($msg, false, ERROR);
            return false;
        }

        // ── 3) Succès : message + redirection vers la fiche contrat ─────────────
        $detail = self::successDetail($contract_mode, $printer_mode);
        Session::addMessageAfterRedirect(
            __('Imprimante et contrat liés.', 'printgestion') . ' ' . $detail,
            false,
            INFO
        );

        return Contract::getFormURLWithID($contracts_id);
    }

    /**
     * Précise « créé vs associé » dans le message de succès.
     */
    private static function successDetail(string $contract_mode, string $printer_mode): string {
        $c = $contract_mode === 'new' ? __('contrat créé', 'printgestion') : __('contrat associé', 'printgestion');
        $p = $printer_mode === 'new' ? __('imprimante créée', 'printgestion') : __('imprimante associée', 'printgestion');
        return '(' . $c . ', ' . $p . ')';
    }

    static function install(Migration $migration) {
        return true;
    }

    static function uninstall(Migration $migration) {
        return true;
    }
}
