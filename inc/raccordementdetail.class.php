<?php
/**
 * PluginPrintgestionRaccordementdetail — lieu, commentaire et contrat des imprimantes d'un raccordement
 * (module Collecte SNMP / Déploiement Agent, phase 3).
 *
 * Étape 2 : déclarés pour chaque adresse et gardés en attente. Lieu et contrat se choisissent avec les
 * sélecteurs natifs de GLPI — celui des lieux porte son bouton « + » qui ouvre le formulaire de création
 * habituel. Un champ texte maison créait des lieux jumeaux à la première faute de frappe.
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

    /** Longueur maximale d'un commentaire. */
    const MAX_COMMENT = 2000;

    /** Au-delà, seules les adresses qui ont une imprimante ou des valeurs sont affichées ligne à ligne. */
    const MAX_ROWS = 64;

    /** Résultats pour lesquels l'imprimante est remontée dans l'entité du raccordement. */
    const APPLICABLE_RESULTS = ['found', 'no_levels', 'waiting_inventory'];

    // ── Lieux ─────────────────────────────────────────────────────────────────

    /**
     * Lieux utilisables depuis cette entité, chemins complets.
     *
     * Même règle que le sélecteur natif : ceux de l'entité, plus ceux d'une entité parente cochés « visible dans
     * les sous-entités ». Filtrer sur le seul entities_id laissait proposer par le sélecteur des lieux que cette
     * liste refusait ensuite à l'enregistrement.
     */
    public static function getEntityLocations(int $entities_id): array {
        global $DB;

        $locations = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'completename'],
            'FROM'   => Location::getTable(),
            'WHERE'  => [getEntitiesRestrictCriteria(Location::getTable(), '', $entities_id, true)],
            'ORDER'  => ['completename'],
        ]) as $row) {
            $locations[(int) $row['id']] = (string) $row['completename'];
        }
        return $locations;
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

    /**
     * Pourquoi la liste des contrats est vide, quand elle l'est ; chaîne vide s'il y a de quoi choisir.
     *
     * GLPI ne montre, dans une entité, que les contrats de cette entité et ceux d'une entité parente cochés
     * « visible dans les sous-entités ». Un menu vide et muet se lit comme une panne, alors que c'est un
     * rattachement à corriger : on dit lequel des deux cas c'est, et où aller.
     */
    public static function getContractHint(int $entities_id): string {
        if (!empty(self::getEntityContracts($entities_id))) {
            return '';
        }
        // Compté dans ce que l'utilisateur a le droit de voir, jamais au-delà.
        $ailleurs = countElementsInTable(Contract::getTable(), [
            'is_deleted'  => 0,
            'is_template' => 0,
            getEntitiesRestrictCriteria(Contract::getTable(), '', '', true),
        ]);
        if ($ailleurs === 0) {
            return __('Aucun contrat dans vos entités : créez-le d\'abord dans Gestion → Contrats.', 'printgestion');
        }
        return sprintf(
            _n(
                '%d contrat existe dans vos entités, mais aucun n\'est visible depuis celle de ce client : ouvrez-le et placez-le dans l\'entité du client, ou cochez « visible dans les sous-entités » s\'il est au niveau au-dessus.',
                '%d contrats existent dans vos entités, mais aucun n\'est visible depuis celle de ce client : ouvrez-en un et placez-le dans l\'entité du client, ou cochez « visible dans les sous-entités » s\'il est au niveau au-dessus.',
                $ailleurs,
                'printgestion'
            ),
            $ailleurs
        );
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

    private static function validate(string $label, int $locations_id, array $locations, string $comment, int $contracts_id, array $contracts, array &$errors): void {
        // Ce qui arrive d'un formulaire ne vaut rien tant qu'on ne l'a pas retrouvé dans une liste établie ici.
        if ($locations_id > 0 && !isset($locations[$locations_id])) {
            $errors[] = sprintf(__('%s : lieu inconnu ou hors de cette entité.', 'printgestion'), $label);
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
        $locations   = self::getEntityLocations($entities_id);
        $contracts   = self::getEntityContracts($entities_id);
        $details     = is_array($post['details'] ?? null) ? $post['details'] : [];
        $default     = is_array($post['default'] ?? null) ? $post['default'] : [];
        $errors      = [];

        $default_location = (int) ($default['locations_id'] ?? 0);
        $default_comment  = trim((string) ($default['comment'] ?? ''));
        $default_contract = (int) ($default['contracts_id'] ?? 0);
        self::validate(__('Valeurs par défaut', 'printgestion'), $default_location, $locations, $default_comment, $default_contract, $contracts, $errors);

        $wanted = [];
        foreach ($racc->getIps() as $row) {
            $id = (int) $row['id'];
            if (isset($details[$id]) && is_array($details[$id])) {
                $location = (int) ($details[$id]['locations_id'] ?? 0);
                $comment  = trim((string) ($details[$id]['comment'] ?? ''));
                $contract = (int) ($details[$id]['contracts_id'] ?? 0);
                self::validate((string) $row['ip'], $location, $locations, $comment, $contract, $contracts, $errors);
                $entry = ['locations_id' => $location, 'comment' => $comment, 'contracts_id' => $contract];
            } else {
                // Adresse non affichée : ses valeurs restent ; seules les valeurs par défaut comblent les vides.
                $entry = ['locations_id' => (int) $row['locations_id'], 'comment' => trim((string) ($row['comment'] ?? '')), 'contracts_id' => (int) $row['contracts_id']];
            }
            if ($entry['locations_id'] === 0 && $default_location > 0) {
                $entry['locations_id'] = $default_location;
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

        $changed = 0;
        $reset   = 0;
        $DB->beginTransaction();
        try {
            foreach ($wanted as $id => $entry) {
                $row          = $entry['row'];
                $locations_id = (int) $entry['locations_id'];
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
        $shown       = count($rows) <= self::MAX_ROWS
            ? $rows
            : array_filter($rows, static fn(array $row): bool => (int) $row['items_id'] > 0 || self::hasValues($row));

        // Les sélecteurs natifs de GLPI : même liste, mêmes droits et mêmes libellés que partout ailleurs dans
        // l'application. Le « + » du sélecteur des lieux ouvre le formulaire de création habituel — mais seulement
        // sur la ligne des valeurs par défaut, sinon il y aurait une fenêtre de création par adresse.
        $lieu_select = static function (string $name, int $value, bool $creer) use ($esc, $locations, $entities_id, $editable): string {
            if (!$editable) {
                return $esc($value > 0 ? ($locations[$value] ?? Dropdown::getDropdownName(Location::getTable(), $value)) : '—');
            }
            return Location::dropdown([
                'name'    => $name,
                'value'   => $value,
                'entity'  => $entities_id,
                'addicon' => $creer,
                'width'   => '100%',
                'display' => false,
            ]);
        };
        $contrat_select = static function (string $name, int $value) use ($esc, $contracts, $entities_id, $editable): string {
            if (!$editable) {
                return $value > 0 && isset($contracts[$value]) ? $esc(self::getContractLabel($contracts[$value])) : '—';
            }
            return Contract::dropdown([
                'name'    => $name,
                'value'   => $value,
                'entity'  => $entities_id,
                'width'   => '100%',
                'display' => false,
            ]);
        };

        echo "<div class='card mb-3'><div class='card-header d-flex align-items-center'><h3 class='card-title mb-0'>"
            . $esc(__('Lieu, commentaire et contrat', 'printgestion')) . "</h3>"
            . "<div class='ms-auto'>" . PluginPrintgestionUi::infoButton(
                __('Lieu, commentaire et contrat', 'printgestion'),
                "<p>" . $esc(__('Ce qui est saisi ici est gardé en attente, puis appliqué à l\'étape 5 — et seulement aux imprimantes réellement remontées dans cette entité. Rien n\'est écrit sur une imprimante avant.', 'printgestion')) . "</p>"
                . "<p class='mb-0'>" . $esc(__('Les listes ne montrent que les lieux et contrats visibles depuis l\'entité du client : les siens, et ceux d\'une entité parente cochés « visible dans les sous-entités ».', 'printgestion')) . "</p>"
            ) . "</div></div><div class='card-body'>";
        $hint = self::getContractHint($entities_id);
        if ($hint !== '') {
            echo PluginPrintgestionUi::statusLine('warning', __('Aucun contrat à choisir pour ce client', 'printgestion'));
            echo "<p class='text-muted small'>" . $esc($hint) . "</p>";
        }
        if ($editable) {
            echo "<form method='post' action='" . $esc(PluginPrintgestionRaccordement::getPageURL()) . "'>" . PluginPrintgestionRaccordement::stepField() . Html::hidden('id', ['value' => (int) $racc->getID()]);
        }
        echo "<div class='table-responsive'><table class='table table-sm align-middle mb-0'><thead><tr>"
            . "<th>" . $esc(__('Adresse', 'printgestion')) . "</th><th>" . $esc(__('Imprimante', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Lieu', 'printgestion')) . "</th><th>" . $esc(__('Commentaire', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Contrat', 'printgestion')) . "</th><th>" . $esc(__('État', 'printgestion')) . "</th></tr></thead><tbody>";
        if ($editable) {
            echo "<tr class='table-light'><td colspan='2' class='small fw-bold'>" . $esc(__('Adresses sans valeur (défaut)', 'printgestion')) . "</td>"
                . "<td>" . $lieu_select('default[locations_id]', 0, true) . "</td>"
                . "<td><input class='form-control form-control-sm' name='default[comment]' maxlength='" . self::MAX_COMMENT . "'></td>"
                . "<td>" . $contrat_select('default[contracts_id]', 0) . "</td>"
                . "<td class='small text-muted'>" . $esc(__('complète les vides', 'printgestion')) . "</td></tr>";
        }
        foreach ($shown as $row) {
            $id      = (int) $row['id'];
            $comment = (string) ($row['comment'] ?? '');
            $state   = !empty($row['date_applied'])
                ? "<span class='badge bg-green text-green-fg'>" . $esc(sprintf(__('Appliqué le %s', 'printgestion'), Html::convDateTime((string) $row['date_applied']))) . "</span>"
                : (self::hasValues($row) ? "<span class='badge bg-secondary text-secondary-fg'>" . $esc(__('En attente', 'printgestion')) . "</span>" : '—');
            echo "<tr><td class='font-monospace'>" . $esc($row['ip']) . "</td><td>" . self::getItemHtml($row) . "</td>";
            echo "<td>" . $lieu_select("details[{$id}][locations_id]", (int) $row['locations_id'], false) . "</td>";
            if ($editable) {
                echo "<td><input class='form-control form-control-sm' name='details[{$id}][comment]' maxlength='" . self::MAX_COMMENT . "' value='" . $esc($comment) . "'></td>";
            } else {
                echo "<td>" . $esc($comment !== '' ? $comment : '—') . "</td>";
            }
            echo "<td>" . $contrat_select("details[{$id}][contracts_id]", (int) $row['contracts_id']) . "</td><td>{$state}</td></tr>";
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

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('Application aux imprimantes', 'printgestion')) . "</h3></div><div class='card-body'>";
        echo "<p class='text-muted small'>" . $esc(__('Seulement aux imprimantes réellement remontées dans cette entité. Le lieu est verrouillé contre l\'inventaire : sans verrou, l\'inventaire réseau le remplace par le lieu SNMP de l\'imprimante. Le commentaire est verrouillé aussi.', 'printgestion')) . "</p>";
        if (empty($rows)) {
            echo "<p class='mb-0'>" . $esc(__('Aucun lieu, commentaire ou contrat déclaré à l\'étape 3 : rien à appliquer.', 'printgestion')) . "</p></div></div>";
            return;
        }
        $entries = [];
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
            $entries[] = [
                'ip'    => "<span class='font-monospace'>" . $esc($row['ip']) . "</span>",
                'item'  => $item,
                'apply' => implode(', ', $parts),
                'state' => $state,
            ];
        }
        // data-pg-noclick : dans l'assistant, la ligne ne quitte pas la page d'un clic (comme avant) ; le lien reste.
        echo "<div data-pg-noclick='1'>" . PluginPrintgestionUi::datatable(
            ['ip' => __('Adresse', 'printgestion'), 'item' => __('Imprimante', 'printgestion'), 'apply' => __('À appliquer', 'printgestion'), 'state' => __('État', 'printgestion')],
            $entries,
            ['ip' => 'raw_html', 'item' => 'raw_html', 'state' => 'raw_html']
        ) . "</div>";
        if (self::canEdit($racc, $can_edit) && $ready > 0) {
            echo "<form method='post' action='" . $esc(PluginPrintgestionRaccordement::getPageURL()) . "' class='mt-3'>" . PluginPrintgestionRaccordement::stepField() . Html::hidden('id', ['value' => (int) $racc->getID()])
                . "<button type='submit' name='apply_details' value='1' class='btn btn-primary'><i class='ti ti-check me-1'></i>"
                . $esc(sprintf(_n('Appliquer à %d imprimante', 'Appliquer à %d imprimantes', $ready, 'printgestion'), $ready)) . "</button>"
                . Html::closeForm(false);
        }
        echo "</div></div>";
    }
}
