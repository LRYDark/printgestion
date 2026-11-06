<?php
/**
 * PluginPrintgestionDeliverylocation — le lieu de livraison d'une cartouche, et ce qu'il décide.
 *
 * Le lieu de livraison est le lieu de l'imprimante, ou l'un de ses sous-lieux (à tout niveau) choisi à la commande.
 * Il décide, en lisant le lieu puis ses parents jusqu'à la racine — le premier qui porte l'information l'emporte :
 *   - l'intitulé de livraison Sage (colonne C du fichier Gesconso) : champ natif « Code » du lieu ; aucun lieu n'en
 *     porte → commentaires de l'entité (PluginPrintgestionSage::describeRule()) ;
 *   - le complément de livraison (colonne I) : commentaire du lieu, adresses mail retirées, sur une seule ligne ;
 *   - les destinataires du mail de courtoisie : les adresses mail trouvées dans le commentaire du lieu (le
 *     commentaire peut porter des contacts complets — noms, téléphones, plusieurs personnes : seules les adresses
 *     sont prises) ; aucune → utilisateur de la fiche imprimante → adresse de messagerie de l'entité
 *     (PluginPrintgestionExpedition::resolveCourtesyRecipients()).
 *
 * Seuls les lieux du client sont lus : ceux rattachés à l'entité de l'imprimante ou à l'un de ses parents jusqu'à
 * l'entité qui porte le code client. Un lieu créé plus haut, partagé entre plusieurs clients, est sauté et signalé :
 * son code ou son adresse mail enverrait la cartouche ou la courtoisie d'un client chez un autre.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionDeliverylocation {

    /** Lieux déjà lus : id => ligne, null si introuvable. */
    private static array $locations = [];

    /** Entités du client déjà calculées : entities_id => int[]. */
    private static array $client_entities = [];

    /** Une adresse mail dans un texte libre ; la validité est confirmée ensuite par filter_var(). */
    const EMAIL_PATTERN = '/[A-Z0-9._%+\'-]+@[A-Z0-9-]+(?:\.[A-Z0-9-]+)*\.[A-Z]{2,}/iu';

    /** Une adresse mail avec ce qui l'annonce : « Mail : », « E-mail - », « Courriel », « Mél », « mailto: ». */
    const LABELLED_EMAIL_PATTERN = '/(?:\b(?:e-?mail|mail|courriel|m[eé]l)\s*[:=\-–—]?\s*)?(?:mailto:\s*)?[A-Z0-9._%+\'-]+@[A-Z0-9-]+(?:\.[A-Z0-9-]+)*\.[A-Z]{2,}/iu';

    /**
     * Ancêtres d'un élément d'arbre GLPI (lieux, entités), par getAncestorsOf() et son cache natif, sans l'élément
     * lui-même : GLPI le renvoie pour les identifiants les plus bas (0 ou 1), qui n'ont pas d'ancêtre.
     *
     * @return int[]
     */
    public static function ancestors(string $table, int $id): array {
        return array_values(array_filter(
            array_map('intval', array_keys(getAncestorsOf($table, $id))),
            static fn(int $ancestor) => $ancestor !== $id
        ));
    }

    /** Lieux lus en une requête, mis en cache : id => ligne (id, name, completename, locations_id, level, entities_id, code, comment). */
    public static function loadLocations(array $ids): void {
        global $DB;

        $missing = array_values(array_filter(
            array_unique(array_map('intval', $ids)),
            static fn(int $id) => $id > 0 && !array_key_exists($id, self::$locations)
        ));
        if (empty($missing)) {
            return;
        }
        foreach ($missing as $id) {
            self::$locations[$id] = null;
        }
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'completename', 'locations_id', 'level', 'entities_id', 'code', 'comment'],
            'FROM'   => Location::getTable(),
            'WHERE'  => ['id' => $missing],
        ]) as $row) {
            self::$locations[(int) $row['id']] = $row;
        }
    }

    /** Un lieu (voir loadLocations()), null si introuvable. */
    public static function getLocation(int $locations_id): ?array {
        if ($locations_id <= 0) {
            return null;
        }
        self::loadLocations([$locations_id]);
        return self::$locations[$locations_id];
    }

    /**
     * Entités du client d'une imprimante, de la plus proche à la plus haute : la sienne, puis ses parents jusqu'à
     * l'entité qui porte le code client (incluse). Sans code client, ou code porté par l'entité elle-même : la
     * sienne seule.
     *
     * @return int[]
     */
    public static function clientEntities(int $entities_id): array {
        global $DB;

        if (isset(self::$client_entities[$entities_id])) {
            return self::$client_entities[$entities_id];
        }
        $out    = [$entities_id];
        $client = PluginPrintgestionSage::getClientForEntity($entities_id);
        if ($client !== null && $client['entities_id'] !== $entities_id) {
            $ancestors = self::ancestors(Entity::getTable(), $entities_id);
            $levels    = [];
            if (!empty($ancestors)) {
                foreach ($DB->request([
                    'SELECT' => ['id', 'level'],
                    'FROM'   => Entity::getTable(),
                    'WHERE'  => ['id' => $ancestors],
                ]) as $row) {
                    $levels[(int) $row['id']] = (int) $row['level'];
                }
            }
            if (isset($levels[$client['entities_id']])) {
                $floor = $levels[$client['entities_id']];
                $above = array_filter($levels, static fn(int $level) => $level >= $floor);
                arsort($above);
                $out = array_merge($out, array_map('intval', array_keys($above)));
            }
        }
        return self::$client_entities[$entities_id] = $out;
    }

    /**
     * Le lieu et ses parents, du lieu vers la racine (getAncestorsOf(), niveau décroissant), réduits aux lieux du
     * client (clientEntities()).
     *
     * @return array ['chain' => lieux retenus (lignes de getLocation()), 'skipped' => noms complets des lieux sautés]
     */
    public static function chain(int $locations_id, int $entities_id): array {
        $out = ['chain' => [], 'skipped' => []];
        if (self::getLocation($locations_id) === null) {
            return $out;
        }
        $ids = array_merge([$locations_id], self::ancestors(Location::getTable(), $locations_id));
        self::loadLocations($ids);
        $rows = array_values(array_filter(array_map(static fn(int $id) => self::$locations[$id] ?? null, $ids)));
        usort($rows, static fn(array $a, array $b) => (int) $b['level'] <=> (int) $a['level']);

        $allowed = array_flip(self::clientEntities($entities_id));
        foreach ($rows as $location) {
            if (isset($allowed[(int) $location['entities_id']])) {
                $out['chain'][] = $location;
            } else {
                $out['skipped'][] = (string) $location['completename'];
            }
        }
        return $out;
    }

    /** Vrai si $locations_id est $ancestor_id lui-même ou l'un de ses sous-lieux, à tout niveau (getAncestorsOf()). */
    public static function isWithin(int $locations_id, int $ancestor_id): bool {
        if ($locations_id <= 0 || $ancestor_id <= 0) {
            return false;
        }
        return $locations_id === $ancestor_id
            || in_array($ancestor_id, self::ancestors(Location::getTable(), $locations_id), true);
    }

    /** Sous-lieux d'un lieu, à tout niveau, sans lui-même (getSonsOf() et son cache natif). */
    public static function subLocations(int $locations_id): array {
        if ($locations_id <= 0) {
            return [];
        }
        return array_values(array_filter(
            array_map('intval', array_values(getSonsOf(Location::getTable(), $locations_id))),
            static fn(int $id) => $id !== $locations_id
        ));
    }

    /**
     * Lieu de livraison retenu pour une imprimante : le lieu demandé s'il est le lieu de l'imprimante ou l'un de ses
     * sous-lieux, sinon le lieu de l'imprimante (0 si elle n'en a pas). Un lieu demandé hors de là est ignoré :
     * jamais une livraison ailleurs que sous le lieu de la machine.
     */
    public static function resolveForPrinter(int $printer_locations_id, int $requested = 0): int {
        if ($requested > 0 && $printer_locations_id > 0 && self::isWithin($requested, $printer_locations_id)
            && self::getLocation($requested) !== null) {
            return $requested;
        }
        return max(0, $printer_locations_id);
    }

    /**
     * Ce que décide un lieu de livraison pour une imprimante de l'entité donnée.
     *
     * @return array [
     *   'locations_id'    => int (0 : aucun lieu),
     *   'name'            => string (nom propre, dernier niveau),
     *   'completename'    => string,
     *   'sage_label'      => string ('' : aucun lieu de la chaîne ne porte de code),
     *   'sage_label_from' => string (nom complet du lieu qui le porte),
     *   'complement'      => string (commentaire sans les adresses mail, sur une ligne),
     *   'emails'          => string[] (adresses du lieu le plus proche qui en porte),
     *   'emails_from'     => string (nom complet de ce lieu),
     *   'skipped'         => string[] (lieux sautés : rattachés hors du client),
     * ]
     */
    public static function describe(int $locations_id, int $entities_id): array {
        $out = [
            'locations_id'    => 0,
            'name'            => '',
            'completename'    => '',
            'sage_label'      => '',
            'sage_label_from' => '',
            'complement'      => '',
            'emails'          => [],
            'emails_from'     => '',
            'skipped'         => [],
        ];
        $location = self::getLocation($locations_id);
        if ($location === null) {
            return $out;
        }
        $out['locations_id'] = (int) $location['id'];
        $out['name']         = trim((string) $location['name']);
        $out['completename'] = (string) $location['completename'];

        $chain          = self::chain($locations_id, $entities_id);
        $out['skipped'] = $chain['skipped'];
        foreach ($chain['chain'] as $link) {
            $code = trim((string) ($link['code'] ?? ''));
            if ($out['sage_label'] === '' && $code !== '') {
                $out['sage_label']      = $code;
                $out['sage_label_from'] = (string) $link['completename'];
            }
            $comment = (string) ($link['comment'] ?? '');
            if ($out['complement'] === '') {
                $out['complement'] = self::stripEmails($comment);
            }
            if (empty($out['emails'])) {
                $emails = self::extractEmails($comment);
                if (!empty($emails)) {
                    $out['emails']      = $emails;
                    $out['emails_from'] = (string) $link['completename'];
                }
            }
        }
        return $out;
    }

    /**
     * Adresses de messagerie de l'entité d'une imprimante, sinon de son parent le plus proche qui en porte une,
     * jusqu'à l'entité qui porte le code client.
     *
     * @return array ['emails' => string[], 'entities_id' => int (0 : aucune)]
     */
    public static function entityEmails(int $entities_id): array {
        global $DB;

        $ids    = self::clientEntities($entities_id);
        $emails = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'email'],
            'FROM'   => 'glpi_entities',
            'WHERE'  => ['id' => $ids],
        ]) as $row) {
            $emails[(int) $row['id']] = (string) $row['email'];
        }
        foreach ($ids as $id) {
            $found = self::extractEmails($emails[$id] ?? '');
            if (!empty($found)) {
                return ['emails' => $found, 'entities_id' => $id];
            }
        }
        return ['emails' => [], 'entities_id' => 0];
    }

    /**
     * Adresses mail d'un texte libre (commentaire d'un lieu : noms, téléphones, plusieurs contacts), dans l'ordre,
     * une seule fois chacune (sans tenir compte de la casse). Seules les adresses valides sont rendues.
     *
     * @return string[]
     */
    public static function extractEmails(string $text): array {
        if ($text === '' || !preg_match_all(self::EMAIL_PATTERN, $text, $matches)) {
            return [];
        }
        $out = [];
        foreach ($matches[0] as $email) {
            $email = trim($email, ".-'");
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && !isset($out[strtolower($email)])) {
                $out[strtolower($email)] = $email;
            }
        }
        return array_values($out);
    }

    /**
     * Le texte sans ses adresses mail, sur une seule ligne. Seules les lignes qui portaient une adresse sont
     * nettoyées : l'adresse et ce qui l'annonce (« Mail : », « mailto: ») retirés, chevrons et parenthèses restés
     * vides supprimés, séparateurs orphelins (« . , ; / | - : ») ôtés aux extrémités. Les autres lignes restent telles
     * quelles. Lignes vides retirées, les autres jointes par « / » : les noms et téléphones restent, c'est le
     * complément de livraison.
     */
    public static function stripEmails(string $text): string {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match(self::EMAIL_PATTERN, $line)) {
                $line = (string) preg_replace(self::LABELLED_EMAIL_PATTERN, '', $line);
                $line = (string) preg_replace('/<\s*>|\(\s*\)|\[\s*\]/u', '', $line);
                $line = (string) preg_replace('/\s*([,;\/|])(?:\s*[,;\/|])+/u', '$1', $line);
                $line = (string) preg_replace('/^[\s.,;:\/|\-–—]+|[\s.,;:\/|\-–—]+$/u', '', $line);
            }
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return implode(' / ', $lines);
    }
}
