<?php
/**
 * PluginPrintgestionSage — ce que le fichier Gesconso attend de GLPI, sans table de correspondance :
 *   - code client Sage (colonne B) : le nom de l'entité s'il a la forme d'un code client (majuscules,
 *     chiffres, « . _ - », 17 caractères au plus, sans espace), sinon celui de l'entité parente la
 *     plus proche dont le nom a cette forme ;
 *   - intitulé de livraison (colonne C) : première ligne du champ « Commentaires » de l'entité de
 *     l'imprimante, jamais hérité — vide, la ligne est bloquée à l'export.
 * Les référentiels importés par fichier (adresses de livraison, articles) ne décident de rien : ils
 * servent à vérifier (article inconnu : bloquant ; adresse inconnue : avertissement).
 *
 * Aucune connexion, aucun appel vers Sage : le référentiel est alimenté UNIQUEMENT par dépôt de
 * fichier (PluginPrintgestionSageimport).
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionSage extends CommonGLPI {

    static $rightname = 'plugin_printgestion_sage';

    /** Forme d'un code client Sage (CT_Num) : majuscules et chiffres, « . _ - », 17 caractères au plus. */
    const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9._-]{0,16}$/';

    static function getTypeName($nb = 0) {
        return __('Référentiel Sage', 'printgestion');
    }

    // ── Règle Gesconso ────────────────────────────────────────────────────────

    /** Un nom d'entité a-t-il la forme d'un code client Sage ? */
    public static function isClientCode(string $name): bool {
        return preg_match(self::CODE_PATTERN, trim($name)) === 1;
    }

    /**
     * Code client Sage d'une entité : son nom s'il a la forme d'un code, sinon celui de l'ancêtre
     * le plus proche dont le nom a cette forme. null si aucun.
     *
     * @return ?array ['code', 'entities_id' (entité portant le code), 'inherited' (bool)]
     */
    public static function getClientForEntity(int $entities_id): ?array {
        global $DB;

        $chain = array_merge([$entities_id], array_map('intval', array_keys(getAncestorsOf('glpi_entities', $entities_id))));
        $best  = null;
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'level'],
            'FROM'   => 'glpi_entities',
            'WHERE'  => ['id' => array_values(array_unique($chain))],
        ]) as $row) {
            if (!self::isClientCode((string) $row['name'])) {
                continue;
            }
            // L'entité elle-même prime ; sinon l'ancêtre de niveau le plus élevé (le plus proche).
            $rank = (int) $row['id'] === $entities_id ? PHP_INT_MAX : (int) $row['level'];
            if ($best === null || $rank > $best['rank']) {
                $best = ['code' => trim((string) $row['name']), 'entities_id' => (int) $row['id'], 'rank' => $rank];
            }
        }
        if ($best === null) {
            return null;
        }
        return [
            'code'        => $best['code'],
            'entities_id' => $best['entities_id'],
            'inherited'   => $best['entities_id'] !== $entities_id,
        ];
    }

    /** Intitulé de livraison d'une entité : première ligne non vide de ses commentaires, '' sinon. Jamais hérité. */
    public static function getDeliveryLabelForEntity(int $entities_id): string {
        $entity = new Entity();
        if (!$entity->getFromDB($entities_id)) {
            return '';
        }
        return self::firstLine((string) $entity->fields['comment']);
    }

    /** Première ligne non vide d'un texte, espaces intérieurs réduits. */
    public static function firstLine(string $text): string {
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($line !== '') {
                return $line;
            }
        }
        return '';
    }

    /**
     * L'intitulé est-il une adresse du client présente au dernier import des adresses ?
     * null = référentiel des adresses jamais importé (rien n'est vérifié).
     */
    public static function isDeliveryKnown(string $client_code, string $label): ?bool {
        if (!self::hasReferential(PluginPrintgestionSageimport::TABLE_DELIVERIES)) {
            return null;
        }
        return countElementsInTable(PluginPrintgestionSageimport::TABLE_DELIVERIES, [
            'client_code'       => $client_code,
            'label'             => $label,
            'is_in_last_import' => 1,
        ]) > 0;
    }

    /**
     * Règle appliquée à une entité, pour les contrôles et l'affichage.
     *
     * @return array ['entity_name' => string, 'client' => ?array (getClientForEntity), 'label' => string,
     *                'known' => ?bool (isDeliveryKnown, null sans référentiel ou sans code/intitulé)]
     */
    public static function describeRule(int $entities_id): array {
        $client = self::getClientForEntity($entities_id);
        $label  = self::getDeliveryLabelForEntity($entities_id);
        return [
            'entity_name' => Dropdown::getDropdownName('glpi_entities', $entities_id),
            'client'      => $client,
            'label'       => $label,
            'known'       => ($client !== null && $label !== '') ? self::isDeliveryKnown($client['code'], $label) : null,
        ];
    }

    /** La règle, lisible : d'où viennent le code client et l'intitulé, et ce qui bloque. HTML. */
    public static function renderRule(int $entities_id): string {
        $esc   = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $rule  = self::describeRule($entities_id);
        $parts = [];
        if ($rule['client'] === null) {
            $parts[] = "<span class='text-danger'><i class='ti ti-ban me-1'></i>"
                . $esc(__('Code client : aucun — ni le nom de l\'entité ni celui d\'un parent n\'a la forme d\'un code client Sage (majuscules et chiffres, sans espace) : export bloqué.', 'printgestion'))
                . '</span>';
        } else {
            $parts[] = $esc(__('Code client :', 'printgestion')) . ' <strong>' . $esc($rule['client']['code']) . '</strong> '
                . "<span class='text-muted'>" . $esc($rule['client']['inherited']
                    ? sprintf(__('(nom de l\'entité parente « %s »)', 'printgestion'), Dropdown::getDropdownName('glpi_entities', $rule['client']['entities_id']))
                    : __('(nom de l\'entité)', 'printgestion')) . '</span>';
        }
        if ($rule['label'] === '') {
            $parts[] = "<span class='text-danger'><i class='ti ti-ban me-1'></i>"
                . $esc(__('Intitulé de livraison : aucun — champ « Commentaires » de l\'entité vide : export bloqué.', 'printgestion'))
                . '</span>';
        } else {
            $parts[] = $esc(__('Intitulé de livraison :', 'printgestion')) . ' <strong>' . $esc($rule['label']) . '</strong> '
                . "<span class='text-muted'>" . $esc(__('(première ligne des commentaires de l\'entité)', 'printgestion')) . '</span>'
                . ($rule['known'] === false
                    ? " <span class='text-warning'><i class='ti ti-alert-triangle me-1'></i>"
                        . $esc(__('absent du référentiel des adresses importé : Sage peut refuser la ligne', 'printgestion')) . '</span>'
                    : '');
        }
        if (Entity::canView() && Session::haveAccessToEntity($entities_id)) {
            $parts[] = "<a class='small' href='" . $esc(Entity::getFormURLWithID($entities_id)) . "'>"
                . $esc(__('Fiche de l\'entité', 'printgestion')) . '</a>';
        }
        return implode('<br>', $parts);
    }

    // ── Référentiels importés ─────────────────────────────────────────────────

    /** Référence article présente au dernier import des articles. */
    public static function isArticleActive(string $ref): bool {
        return countElementsInTable(PluginPrintgestionSageimport::TABLE_ARTICLES, [
            'ref'               => $ref,
            'is_in_last_import' => 1,
        ]) > 0;
    }

    /** Un référentiel (table sage*) a-t-il déjà été importé ? */
    public static function hasReferential(string $table): bool {
        return countElementsInTable($table) > 0;
    }
}
