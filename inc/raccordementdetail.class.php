<?php
/**
 * PluginPrintgestionRaccordementdetail — lieu, commentaire et contrat des imprimantes d'un raccordement
 * (module Collecte SNMP / Déploiement Agent, phase 3).
 *
 * Étape 2 : déclarés pour chaque adresse et gardés en attente. Le lieu accepte une saisie hiérarchique
 * (« FC Metz > Bâtiment B > Étage 4 > Bureau 3 ») ou plate ; les niveaux manquants sont créés dans
 * l'entité du client, comme le formulaire natif des lieux. Le contrat est choisi parmi ceux de l'entité.
 *
 * Étape 5 : appliqués seulement aux imprimantes réellement remontées dans l'entité du raccordement,
 * jamais à une adresse sans imprimante ni à une imprimante d'une autre entité. Le champ Lieu est
 * verrouillé contre l'inventaire (verrou natif, glpi_lockedfields) : sans verrou, l'inventaire réseau
 * remplace le lieu par la valeur SNMP sysLocation de l'imprimante (vérifié sur le GLPI de test). Le
 * commentaire est verrouillé aussi, comme GLPI le fait quand un utilisateur modifie la fiche.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionRaccordementdetail {

    /** Niveaux au plus dans un chemin de lieu. */
    const MAX_LEVELS = 10;

    /** Longueur maximale d'un commentaire. */
    const MAX_COMMENT = 2000;

    /** Au-delà, seules les adresses qui ont une imprimante ou des valeurs sont affichées ligne à ligne. */
    const MAX_ROWS = 64;

    /** Résultats pour lesquels l'imprimante est remontée dans l'entité du raccordement. */
    const APPLICABLE_RESULTS = ['found', 'no_levels', 'waiting_inventory'];

    // ── Lieux ─────────────────────────────────────────────────────────────────

    /** Niveaux d'une saisie de lieu « A > B > C » : espaces nettoyés, niveaux vides ignorés. */
    public static function parseLocationPath(string $text): array {
        $levels = [];
        foreach (explode('>', $text) as $level) {
            $level = trim((string) preg_replace('/\s+/u', ' ', $level));
            if ($level !== '') {
                $levels[] = $level;
            }
        }
        return $levels;
    }

    /** Lieux de l'entité (chemins complets), pour l'autocomplétion. */
    public static function getEntityLocations(int $entities_id): array {
        global $DB;

        $locations = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'completename'],
            'FROM'   => Location::getTable(),
            'WHERE'  => ['entities_id' => $entities_id],
            'ORDER'  => ['completename'],
        ]) as $row) {
            $locations[(int) $row['id']] = (string) $row['completename'];
        }
        return $locations;
    }

    /**
     * Lieu de l'entité pour ce chemin ; niveaux manquants créés dans l'entité, non récursifs.
     *
     * @param array $created chemins des lieux créés (complété)
     */
    private static function findOrCreateLocation(int $entities_id, array $levels, array &$created): int {
        global $DB;

        $parent = 0;
        $path   = [];
        foreach ($levels as $name) {
            $path[] = $name;
            $row    = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => Location::getTable(),
                'WHERE'  => ['entities_id' => $entities_id, 'locations_id' => $parent, 'name' => $name],
                'ORDER'  => ['id'],
                'LIMIT'  => 1,
            ])->current();
            if (is_array($row)) {
                $parent = (int) $row['id'];
                continue;
            }
            $location = new Location();
            $id       = (int) $location->add([
                'name'         => $name,
                'locations_id' => $parent,
                'entities_id'  => $entities_id,
                'is_recursive' => 0,
            ]);
            if ($id <= 0) {
                throw new DomainException(sprintf(__('lieu « %s » refusé par GLPI', 'printgestion'), implode(' > ', $path)));
            }
            $created[] = implode(' > ', $path);
            $parent    = $id;
        }
        return $parent;
    }

    // ── Contrats ──────────────────────────────────────────────────────────────

    /** Contrats de l'entité : les siens et ceux, récursifs, de ses entités parentes ; ni supprimés ni modèles. */
    public static function getEntityContracts(int $entities_id): array {
        global $DB;

        $contracts = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'num', 'entities_id'],
            'FROM'   => Contract::getTable(),
            'WHERE'  => [
                'is_deleted'  => 0,
                'is_template' => 0,
                getEntitiesRestrictCriteria(Contract::getTable(), '', $entities_id, true),
            ],
            'ORDER'  => ['name', 'id'],
        ]) as $row) {
            $contracts[(int) $row['id']] = $row;
        }
        return $contracts;
    }

    private static function getContractLabel(array $contract): string {
        $num = trim((string) ($contract['num'] ?? ''));
        return (string) $contract['name'] . ($num !== '' ? ' (' . $num . ')' : '');
    }

    // ── Étape 2 : saisie ──────────────────────────────────────────────────────

    /** Valeurs déclarées d'une adresse. */
    private static function hasValues(array $row): bool {
        return (int) $row['locations_id'] > 0 || trim((string) ($row['comment'] ?? '')) !== '' || (int) $row['contracts_id'] > 0;
    }

    /** Valeurs déclarées, par adresse, avant de remplacer la liste des adresses (étape 2). */
    public static function snapshot(PluginPrintgestionRaccordement $racc): array {
        $values = [];
        foreach ($racc->getIps() as $row) {
            if (self::hasValues($row)) {
                $values[(int) $row['ip_num']] = [
                    'locations_id' => (int) $row['locations_id'],
                    'comment'      => $row['comment'],
                    'contracts_id' => (int) $row['contracts_id'],
                ];
            }
        }
        return $values;
    }

    /** Remet les valeurs déclarées sur les adresses gardées après remplacement de la liste. */
    public static function restore(PluginPrintgestionRaccordement $racc, array $values): void {
        global $DB;

        foreach ($racc->getIps() as $row) {
            if (isset($values[(int) $row['ip_num']])) {
                $DB->update(PluginPrintgestionRaccordement::IPS_TABLE, $values[(int) $row['ip_num']], ['id' => (int) $row['id']]);
            }
        }
    }

    private static function validate(string $label, array $levels, string $comment, int $contracts_id, array $contracts, array &$errors): void {
        if (count($levels) > self::MAX_LEVELS) {
            $errors[] = sprintf(__('%1$s : lieu de %2$d niveaux au plus.', 'printgestion'), $label, self::MAX_LEVELS);
        }
        foreach ($levels as $level) {
            if (mb_strlen($level) > 255) {
                $errors[] = sprintf(__('%s : nom de lieu de 255 caractères au plus.', 'printgestion'), $label);
                break;
            }
        }
        if (mb_strlen($comment) > self::MAX_COMMENT) {
            $errors[] = sprintf(__('%1$s : commentaire de %2$d caractères au plus.', 'printgestion'), $label, self::MAX_COMMENT);
        }
        if ($contracts_id > 0 && !isset($contracts[$contracts_id])) {
            $errors[] = sprintf(__('%s : contrat inconnu ou hors de cette entité.', 'printgestion'), $label);
        }
    }

    /**
     * Enregistre lieu, commentaire et contrat. Adresses affichées : réécrites d'après le formulaire (vide =
     * effacé). Valeurs par défaut : complètent chaque adresse, affichée ou non, là où rien n'est déclaré.
     * Une adresse déjà appliquée qu'on modifie repasse en attente. Tout ou rien.
     *
     * @return array ['ok' => bool, 'events' => [[niveau, message]]]
     */
    public static function save(PluginPrintgestionRaccordement $racc, array $post): array {
        global $DB;

        $entities_id = (int) $racc->fields['entities_id'];
        $contracts   = self::getEntityContracts($entities_id);
        $details     = is_array($post['details'] ?? null) ? $post['details'] : [];
        $default     = is_array($post['default'] ?? null) ? $post['default'] : [];
        $errors      = [];

        $default_levels   = self::parseLocationPath((string) ($default['location'] ?? ''));
        $default_comment  = trim((string) ($default['comment'] ?? ''));
        $default_contract = (int) ($default['contracts_id'] ?? 0);
        self::validate(__('Valeurs par défaut', 'printgestion'), $default_levels, $default_comment, $default_contract, $contracts, $errors);

        $wanted = [];
        foreach ($racc->getIps() as $row) {
            $id = (int) $row['id'];
            if (isset($details[$id]) && is_array($details[$id])) {
                $levels   = self::parseLocationPath((string) ($details[$id]['location'] ?? ''));
                $comment  = trim((string) ($details[$id]['comment'] ?? ''));
                $contract = (int) ($details[$id]['contracts_id'] ?? 0);
                self::validate((string) $row['ip'], $levels, $comment, $contract, $contracts, $errors);
                $entry = ['levels' => $levels, 'keep_location' => false, 'comment' => $comment, 'contracts_id' => $contract];
            } else {
                // Adresse non affichée : ses valeurs restent ; seules les valeurs par défaut comblent les vides.
                $entry = ['levels' => [], 'keep_location' => true, 'comment' => trim((string) ($row['comment'] ?? '')), 'contracts_id' => (int) $row['contracts_id']];
            }
            $no_location = $entry['keep_location'] ? (int) $row['locations_id'] === 0 : empty($entry['levels']);
            if ($no_location && !empty($default_levels)) {
                $entry['levels']        = $default_levels;
                $entry['keep_location'] = false;
            }
            if ($entry['comment'] === '' && $default_comment !== '') {
                $entry['comment'] = $default_comment;
            }
            if ($entry['contracts_id'] === 0 && $default_contract > 0) {
                $entry['contracts_id'] = $default_contract;
            }
            $wanted[$id] = $entry + ['row' => $row];
        }
        if (!empty($errors)) {
            return ['ok' => false, 'events' => [['error', sprintf(__('Rien n\'est enregistré : %s', 'printgestion'), implode(' ', array_unique($errors)))]]];
        }

        $created = [];
        $changed = 0;
        $reset   = 0;
        $DB->beginTransaction();
        try {
            foreach ($wanted as $id => $entry) {
                $row          = $entry['row'];
                $locations_id = $entry['keep_location']
                    ? (int) $row['locations_id']
                    : (empty($entry['levels']) ? 0 : self::findOrCreateLocation($entities_id, $entry['levels'], $created));
                if ((int) $row['locations_id'] === $locations_id
                    && trim((string) ($row['comment'] ?? '')) === $entry['comment']
                    && (int) $row['contracts_id'] === $entry['contracts_id']) {
                    continue;
                }
                $update = [
                    'locations_id' => $locations_id,
                    'comment'      => $entry['comment'] === '' ? null : $entry['comment'],
                    'contracts_id' => $entry['contracts_id'],
                ];
                if (!empty($row['date_applied'])) {
                    $update['date_applied']     = null;
                    $update['applied_items_id'] = 0;
                    $reset++;
                }
                $DB->update(PluginPrintgestionRaccordement::IPS_TABLE, $update, ['id' => $id]);
                $changed++;
            }
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            if (!$e instanceof DomainException) {
                \Glpi\Error\ErrorHandler::logCaughtException($e);
            }
            return ['ok' => false, 'events' => [['error', sprintf(
                __('Rien n\'est enregistré : %s.', 'printgestion'),
                $e instanceof DomainException ? $e->getMessage() : __('erreur technique, détail dans le journal PHP de GLPI (php-errors.log)', 'printgestion')
            )]]];
        }

        if ($changed === 0) {
            return ['ok' => true, 'events' => [['info', __('Lieux, commentaires et contrats : aucun changement.', 'printgestion')]]];
        }
        $events = [['success', sprintf(
            _n('Lieu, commentaire et contrat enregistrés pour %d adresse, en attente jusqu\'à l\'étape 5.', 'Lieux, commentaires et contrats enregistrés pour %d adresses, en attente jusqu\'à l\'étape 5.', $changed, 'printgestion'),
            $changed
        )]];
        if (!empty($created)) {
            $events[] = ['info', sprintf(__('Lieux créés dans l\'entité : %s.', 'printgestion'), implode(' ; ', array_unique($created)))];
        }
        if ($reset > 0) {
            $events[] = ['warning', sprintf(_n('%d adresse déjà appliquée repasse en attente : appliquez à nouveau à l\'étape 5.', '%d adresses déjà appliquées repassent en attente : appliquez à nouveau à l\'étape 5.', $reset, 'printgestion'), $reset)];
        }
        return ['ok' => true, 'events' => $events];
    }

    // ── Étape 5 : application ─────────────────────────────────────────────────

    /** Pourquoi rien n'est appliqué à cette adresse ; vide si l'adresse est prête. */
    public static function getBlockReason(array $row): string {
        if (!self::hasValues($row)) {
            return __('rien de déclaré', 'printgestion');
        }
        if ($row['result'] === 'wrong_entity') {
            return __('imprimante dans une autre entité : rien n\'y est appliqué', 'printgestion');
        }
        if (!in_array($row['result'], self::APPLICABLE_RESULTS, true) || ($row['itemtype'] ?? '') !== Printer::class || (int) $row['items_id'] <= 0) {
            return __('aucune imprimante remontée à cette adresse pour l\'instant', 'printgestion');
        }
        return '';
    }

    /** Verrou natif du champ sur cette imprimante (glpi_lockedfields), s'il n'existe pas déjà. */
    private static function lockField(int $printers_id, string $field): void {
        $lock = ['itemtype' => Printer::class, 'items_id' => $printers_id, 'field' => $field, 'is_global' => 0];
        if (countElementsInTable(Lockedfield::getTable(), $lock) > 0) {
            return;
        }
        $lockedfield = new Lockedfield();
        if (!$lockedfield->add($lock)) {
            throw new DomainException(sprintf(__('verrou du champ %s refusé par GLPI', 'printgestion'), $field));
        }
    }

    /**
     * Applique lieu, commentaire et contrat aux imprimantes remontées dans l'entité, une transaction par
     * imprimante. Adresses sans imprimante, ou dont l'imprimante est ailleurs : rien, avec la raison.
     *
     * @return array ['applied' => int, 'events' => [[niveau, message]]]
     */
    public static function apply(PluginPrintgestionRaccordement $racc): array {
        global $DB;

        $entities_id = (int) $racc->fields['entities_id'];
        $contracts   = self::getEntityContracts($entities_id);
        $events      = [];
        $applied     = 0;
        foreach ($racc->getIps() as $row) {
            if (!empty($row['date_applied'])) {
                continue;
            }
            $reason = self::getBlockReason($row);
            if ($reason !== '') {
                if (self::hasValues($row)) {
                    $events[] = ['warning', sprintf(__('%1$s : %2$s.', 'printgestion'), $row['ip'], $reason)];
                }
                continue;
            }
            $printers_id = (int) $row['items_id'];
            $printer     = new Printer();
            if (!$printer->getFromDB($printers_id) || (int) $printer->fields['is_deleted'] === 1 || (int) $printer->fields['entities_id'] !== $entities_id) {
                $events[] = ['warning', sprintf(__('%s : imprimante introuvable, à la corbeille ou sortie de l\'entité depuis la vérification ; rien n\'est appliqué.', 'printgestion'), $row['ip'])];
                continue;
            }

            $DB->beginTransaction();
            try {
                $input = ['id' => $printers_id];
                $done  = [];
                if ((int) $row['locations_id'] > 0) {
                    $location = new Location();
                    if (!$location->getFromDB((int) $row['locations_id'])) {
                        throw new DomainException(__('le lieu déclaré n\'existe plus', 'printgestion'));
                    }
                    $input['locations_id'] = (int) $row['locations_id'];
                    $done[]                = sprintf(__('lieu « %s »', 'printgestion'), $location->fields['completename']);
                }
                $comment = trim((string) ($row['comment'] ?? ''));
                if ($comment !== '') {
                    $input['comment'] = $comment;
                    $done[]           = __('commentaire', 'printgestion');
                }
                if (count($input) > 1 && !$printer->update($input)) {
                    throw new DomainException(__('fiche de l\'imprimante non modifiée par GLPI', 'printgestion'));
                }
                if (isset($input['locations_id'])) {
                    self::lockField($printers_id, 'locations_id');
                }
                if (isset($input['comment'])) {
                    self::lockField($printers_id, 'comment');
                }
                $contracts_id = (int) $row['contracts_id'];
                if ($contracts_id > 0) {
                    if (!isset($contracts[$contracts_id])) {
                        throw new DomainException(__('le contrat déclaré n\'est plus disponible pour cette entité', 'printgestion'));
                    }
                    $link = ['contracts_id' => $contracts_id, 'itemtype' => Printer::class, 'items_id' => $printers_id];
                    if (countElementsInTable(Contract_Item::getTable(), $link) === 0) {
                        // Contract_Item::add() ne contrôle ni le nombre maximal d'éléments ni l'entité (seul
                        // can() le fait) : entité garantie par la liste des contrats, maximum vérifié ici.
                        $contract = new Contract();
                        $max      = $contract->getFromDB($contracts_id) ? (int) $contract->fields['max_links_allowed'] : 0;
                        if ($max > 0 && countElementsInTable(Contract_Item::getTable(), ['contracts_id' => $contracts_id]) >= $max) {
                            throw new DomainException(sprintf(
                                __('le contrat « %1$s » a déjà son nombre maximal d\'éléments (%2$d)', 'printgestion'),
                                self::getContractLabel($contracts[$contracts_id]),
                                $max
                            ));
                        }
                        $contract_item = new Contract_Item();
                        if (!$contract_item->add($link)) {
                            throw new DomainException(__('rattachement au contrat refusé par GLPI', 'printgestion'));
                        }
                    }
                    $done[] = sprintf(__('contrat « %s »', 'printgestion'), self::getContractLabel($contracts[$contracts_id]));
                }
                $DB->update(PluginPrintgestionRaccordement::IPS_TABLE, [
                    'applied_items_id' => $printers_id,
                    'date_applied'     => Session::getCurrentTime(),
                ], ['id' => (int) $row['id']]);
                $DB->commit();
                $applied++;
                $events[] = ['success', sprintf(
                    __('%1$s → imprimante « %2$s » : %3$s.%4$s', 'printgestion'),
                    $row['ip'],
                    $printer->fields['name'],
                    implode(', ', $done),
                    isset($input['locations_id']) ? ' ' . __('Lieu verrouillé : les inventaires suivants ne le remplaceront pas.', 'printgestion') : ''
                )];
            } catch (Throwable $e) {
                $DB->rollBack();
                if (!$e instanceof DomainException) {
                    \Glpi\Error\ErrorHandler::logCaughtException($e);
                }
                $events[] = ['error', sprintf(
                    __('%1$s : rien n\'est appliqué (%2$s).', 'printgestion'),
                    $row['ip'],
                    $e instanceof DomainException ? $e->getMessage() : __('erreur technique, détail dans le journal PHP de GLPI', 'printgestion')
                )];
            }
        }
        if ($applied === 0 && empty($events)) {
            $events[] = ['info', __('Rien à appliquer : aucune adresse n\'a de lieu, de commentaire ou de contrat en attente.', 'printgestion')];
        }
        return ['applied' => $applied, 'events' => $events];
    }

    /** Adresses prêtes pour l'étape 5. */
    public static function countReady(PluginPrintgestionRaccordement $racc): int {
        $ready = 0;
        foreach ($racc->getIps() as $row) {
            if (empty($row['date_applied']) && self::getBlockReason($row) === '') {
                $ready++;
            }
        }
        return $ready;
    }

    // ── Affichage ─────────────────────────────────────────────────────────────

    private static function canEdit(PluginPrintgestionRaccordement $racc, bool $can_edit): bool {
        return $can_edit && $racc->fields['status'] !== PluginPrintgestionRaccordement::STATUS_ABANDONED;
    }

    private static function getItemHtml(array $row): string {
        $items_id = (int) $row['items_id'];
        if ($items_id <= 0 || ($row['itemtype'] ?? '') !== Printer::class) {
            return '—';
        }
        return "<a href='" . htmlspecialchars(Printer::getFormURLWithID($items_id), ENT_QUOTES, 'UTF-8') . "'>"
            . htmlspecialchars((string) Printer::getFriendlyNameById($items_id), ENT_QUOTES, 'UTF-8') . "</a>";
    }

    /** Carte de l'étape 2 : lieu, commentaire et contrat de chaque adresse, en attente. */
    public static function showDetails(PluginPrintgestionRaccordement $racc, bool $can_edit): void {
        $esc  = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $rows = $racc->getIps();
        if (empty($rows)) {
            return;
        }
        $entities_id = (int) $racc->fields['entities_id'];
        $editable    = self::canEdit($racc, $can_edit);
        $locations   = self::getEntityLocations($entities_id);
        $contracts   = self::getEntityContracts($entities_id);
        $list_id     = 'pg-racc-locations-' . (int) $racc->getID();
        $shown       = count($rows) <= self::MAX_ROWS
            ? $rows
            : array_filter($rows, static fn(array $row): bool => (int) $row['items_id'] > 0 || self::hasValues($row));
        $location_of = static fn(int $id): string => $id > 0 ? ($locations[$id] ?? Dropdown::getDropdownName(Location::getTable(), $id)) : '';

        $contract_select = static function (string $name, int $value) use ($esc, $contracts, $editable): string {
            if (!$editable) {
                return $value > 0 && isset($contracts[$value]) ? $esc(self::getContractLabel($contracts[$value])) : '—';
            }
            $html = "<select class='form-select form-select-sm' name='" . $esc($name) . "'><option value='0'>—</option>";
            foreach ($contracts as $id => $contract) {
                $html .= "<option value='" . (int) $id . "'" . ((int) $id === $value ? ' selected' : '') . ">" . $esc(self::getContractLabel($contract)) . "</option>";
            }
            return $html . "</select>";
        };

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('2 bis. Lieu, commentaire et contrat des imprimantes', 'printgestion')) . "</h3></div><div class='card-body'>";
        echo "<p class='text-muted small'>" . $esc(__('Gardés en attente, puis appliqués à l\'étape 5 seulement aux imprimantes réellement remontées dans cette entité. Lieu : chemin complet (« FC Metz > Bâtiment B > Étage 4 > Bureau 3 ») ou nom simple ; les niveaux manquants sont créés dans l\'entité. Contrat : ceux de l\'entité.', 'printgestion')) . "</p>";
        if ($editable) {
            echo "<form method='post' action='" . $esc(PluginPrintgestionRaccordement::getPageURL()) . "'>" . Html::hidden('id', ['value' => (int) $racc->getID()]);
            echo "<datalist id='" . $esc($list_id) . "'>";
            foreach ($locations as $completename) {
                echo "<option value='" . $esc($completename) . "'></option>";
            }
            echo "</datalist>";
        }
        echo "<div class='table-responsive'><table class='table table-sm align-middle mb-0'><thead><tr>"
            . "<th>" . $esc(__('Adresse', 'printgestion')) . "</th><th>" . $esc(__('Imprimante', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Lieu', 'printgestion')) . "</th><th>" . $esc(__('Commentaire', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Contrat', 'printgestion')) . "</th><th>" . $esc(__('État', 'printgestion')) . "</th></tr></thead><tbody>";
        if ($editable) {
            echo "<tr class='table-light'><td colspan='2' class='small fw-bold'>" . $esc(__('Adresses sans valeur (défaut)', 'printgestion')) . "</td>"
                . "<td><input class='form-control form-control-sm' name='default[location]' list='" . $esc($list_id) . "' maxlength='2600' placeholder='" . $esc(__('Site > Bâtiment > Étage', 'printgestion')) . "'></td>"
                . "<td><input class='form-control form-control-sm' name='default[comment]' maxlength='" . self::MAX_COMMENT . "'></td>"
                . "<td>" . $contract_select('default[contracts_id]', 0) . "</td>"
                . "<td class='small text-muted'>" . $esc(__('complète les vides', 'printgestion')) . "</td></tr>";
        }
        foreach ($shown as $row) {
            $id       = (int) $row['id'];
            $location = $location_of((int) $row['locations_id']);
            $comment  = (string) ($row['comment'] ?? '');
            $state    = !empty($row['date_applied'])
                ? "<span class='badge bg-green text-green-fg'>" . $esc(sprintf(__('Appliqué le %s', 'printgestion'), Html::convDateTime((string) $row['date_applied']))) . "</span>"
                : (self::hasValues($row) ? "<span class='badge bg-secondary text-secondary-fg'>" . $esc(__('En attente', 'printgestion')) . "</span>" : '—');
            echo "<tr><td class='font-monospace'>" . $esc($row['ip']) . "</td><td>" . self::getItemHtml($row) . "</td>";
            if ($editable) {
                echo "<td><input class='form-control form-control-sm' name='details[{$id}][location]' list='" . $esc($list_id) . "' maxlength='2600' value='" . $esc($location) . "'></td>"
                    . "<td><input class='form-control form-control-sm' name='details[{$id}][comment]' maxlength='" . self::MAX_COMMENT . "' value='" . $esc($comment) . "'></td>";
            } else {
                echo "<td>" . $esc($location !== '' ? $location : '—') . "</td><td>" . $esc($comment !== '' ? $comment : '—') . "</td>";
            }
            echo "<td>" . $contract_select("details[{$id}][contracts_id]", (int) $row['contracts_id']) . "</td><td>{$state}</td></tr>";
        }
        echo "</tbody></table></div>";
        if (count($shown) < count($rows)) {
            echo "<p class='text-muted small mt-2 mb-0'>" . $esc(sprintf(__('%d autres adresses, sans imprimante trouvée ni valeur : seules les valeurs par défaut les complètent.', 'printgestion'), count($rows) - count($shown))) . "</p>";
        }
        if ($editable) {
            echo "<button type='submit' name='save_details' value='1' class='btn btn-primary mt-2'><i class='ti ti-device-floppy me-1'></i>" . $esc(__('Enregistrer lieux, commentaires et contrats', 'printgestion')) . "</button>";
            echo Html::closeForm(false);
        }
        echo "</div></div>";
    }

    /** Carte de l'étape 5 : ce qui sera appliqué, à quelle imprimante, ou pourquoi rien. */
    public static function showStep5(PluginPrintgestionRaccordement $racc, bool $can_edit): void {
        $esc       = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $rows      = array_filter($racc->getIps(), static fn(array $row): bool => self::hasValues($row));
        $contracts = self::getEntityContracts((int) $racc->fields['entities_id']);
        $ready     = self::countReady($racc);

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('5. Appliquer lieu, commentaire et contrat', 'printgestion')) . "</h3></div><div class='card-body'>";
        echo "<p class='text-muted small'>" . $esc(__('Seulement aux imprimantes réellement remontées dans cette entité. Le lieu est verrouillé contre l\'inventaire : sans verrou, l\'inventaire réseau le remplace par le lieu SNMP de l\'imprimante. Le commentaire est verrouillé aussi.', 'printgestion')) . "</p>";
        if (empty($rows)) {
            echo "<p class='mb-0'>" . $esc(__('Aucun lieu, commentaire ou contrat déclaré (carte 2 bis) : rien à appliquer.', 'printgestion')) . "</p></div></div>";
            return;
        }
        echo "<div class='table-responsive'><table class='table table-sm align-middle mb-0'><thead><tr>"
            . "<th>" . $esc(__('Adresse', 'printgestion')) . "</th><th>" . $esc(__('Imprimante', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('À appliquer', 'printgestion')) . "</th><th>" . $esc(__('État', 'printgestion')) . "</th></tr></thead><tbody>";
        foreach ($rows as $row) {
            $parts = [];
            if ((int) $row['locations_id'] > 0) {
                $parts[] = sprintf(__('lieu « %s »', 'printgestion'), Dropdown::getDropdownName(Location::getTable(), (int) $row['locations_id']));
            }
            if (trim((string) ($row['comment'] ?? '')) !== '') {
                $parts[] = sprintf(__('commentaire « %s »', 'printgestion'), mb_strimwidth(trim((string) $row['comment']), 0, 80, '…'));
            }
            if ((int) $row['contracts_id'] > 0) {
                $parts[] = sprintf(__('contrat « %s »', 'printgestion'), isset($contracts[(int) $row['contracts_id']]) ? self::getContractLabel($contracts[(int) $row['contracts_id']]) : '#' . (int) $row['contracts_id']);
            }
            $reason = self::getBlockReason($row);
            if (!empty($row['date_applied'])) {
                $state = "<span class='badge bg-green text-green-fg'>" . $esc(sprintf(__('Appliqué le %s', 'printgestion'), Html::convDateTime((string) $row['date_applied']))) . "</span>";
                $item  = "<a href='" . $esc(Printer::getFormURLWithID((int) $row['applied_items_id'])) . "'>" . $esc(Printer::getFriendlyNameById((int) $row['applied_items_id'])) . "</a>";
            } elseif ($reason === '') {
                $state = "<span class='badge bg-blue text-blue-fg'>" . $esc(__('Prêt', 'printgestion')) . "</span>";
                $item  = self::getItemHtml($row);
            } else {
                $state = "<span class='badge " . ($row['result'] === 'wrong_entity' ? 'bg-red text-red-fg' : 'bg-secondary text-secondary-fg') . "'>" . $esc(ucfirst($reason)) . "</span>";
                $item  = self::getItemHtml($row);
            }
            echo "<tr><td class='font-monospace'>" . $esc($row['ip']) . "</td><td>{$item}</td><td class='small'>" . $esc(implode(', ', $parts)) . "</td><td>{$state}</td></tr>";
        }
        echo "</tbody></table></div>";
        if (self::canEdit($racc, $can_edit) && $ready > 0) {
            echo "<form method='post' action='" . $esc(PluginPrintgestionRaccordement::getPageURL()) . "' class='mt-3'>" . Html::hidden('id', ['value' => (int) $racc->getID()])
                . "<button type='submit' name='apply_details' value='1' class='btn btn-primary'><i class='ti ti-check me-1'></i>"
                . $esc(sprintf(_n('Appliquer à %d imprimante', 'Appliquer à %d imprimantes', $ready, 'printgestion'), $ready)) . "</button>"
                . Html::closeForm(false);
        }
        echo "</div></div>";
    }
}
