<?php
/**
 * Ordre des cartes « Modules » de l'accueil du plugin, propre à chaque utilisateur.
 *
 * L'utilisateur range les cartes par glisser-déposer, avec html5sortable : la bibliothèque que GLPI livre et charge
 * sur toutes ses pages, celle du tri des recherches enregistrées et des règles. Une ligne par utilisateur qui a
 * rangé ses cartes ; sans ligne, l'ordre par défaut (celui de PluginPrintgestionMenu::categories()).
 *
 * Une carte que l'utilisateur ne voit pas (module coupé, droit retiré) garde sa place quand il range les autres ; une
 * carte inconnue de l'ordre enregistré (module ajouté depuis) se range après les autres, dans l'ordre par défaut.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionHubpref {

    const TABLE = 'glpi_plugin_printgestion_hubprefs';

    private static bool $table_checked = false;

    /** La table, créée si elle manque (installation antérieure à la fonction). */
    public static function ensureTable(): void {
        if (self::$table_checked) {
            return;
        }
        PluginPrintgestionSchema::createIfMissing(self::TABLE);
        self::$table_checked = true;
    }

    /** Clés des cartes dans l'ordre enregistré par l'utilisateur ; tableau vide s'il n'a rien rangé. */
    public static function getOrder(int $users_id): array {
        global $DB;

        if ($users_id <= 0) {
            return [];
        }
        self::ensureTable();
        $row = $DB->request([
            'SELECT' => ['card_order'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['users_id' => $users_id],
            'LIMIT'  => 1,
        ])->current();
        $keys = json_decode((string) ($row['card_order'] ?? ''), true);
        return is_array($keys) && array_is_list($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    /** Les catégories rangées : celles de l'ordre enregistré d'abord, les autres ensuite dans l'ordre par défaut. */
    public static function sort(array $categories, array $order): array {
        $sorted = [];
        foreach ($order as $key) {
            if (isset($categories[$key])) {
                $sorted[$key] = $categories[$key];
            }
        }
        return $sorted + $categories;
    }

    /**
     * Enregistre le nouvel ordre des cartes affichées. Seules les clés de catégories connues comptent, une fois
     * chacune ; les cartes absentes de l'écran gardent leur place dans l'ordre complet.
     *
     * @return bool faux quand il n'y a rien d'utilisable à enregistrer
     */
    public static function saveOrder(int $users_id, array $keys): bool {
        global $DB;

        $known   = PluginPrintgestionMenu::categories();
        $visible = [];
        foreach ($keys as $key) {
            if (is_string($key) && isset($known[$key]) && !in_array($key, $visible, true)) {
                $visible[] = $key;
            }
        }
        if ($users_id <= 0 || $visible === []) {
            return false;
        }

        // Les cartes affichées reprennent, dans leur nouvel ordre, les places qu'elles occupaient.
        $queue = $visible;
        $order = [];
        foreach (array_keys(self::sort($known, self::getOrder($users_id))) as $key) {
            $order[] = in_array($key, $visible, true) ? array_shift($queue) : $key;
        }

        return (bool) $DB->updateOrInsert(self::TABLE, [
            'card_order' => json_encode($order),
            'date_mod'   => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ], ['users_id' => $users_id]);
    }

    /** Revient à l'ordre par défaut : la ligne de l'utilisateur est effacée. */
    public static function reset(int $users_id): bool {
        global $DB;

        if ($users_id <= 0) {
            return false;
        }
        self::ensureTable();
        return (bool) $DB->delete(self::TABLE, ['users_id' => $users_id]);
    }
}
