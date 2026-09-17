<?php
/**
 * PluginPrintgestionGlsnumber — numéro de suivi GLS : nettoyage de la saisie, repli documenté et interrogation par lots.
 *
 * Aucun appel réseau ici : l'interrogation est une fonction fournie par l'appelant (le client GLS en production, des
 * réponses inventées dans le harnais). Utilisé seulement pour les expéditions dont le transporteur est GLS ; sans
 * suivi GLS, rien de ce fichier ne sert et le plugin fonctionne à l'identique.
 *
 * Formats de référence acceptés par GLS Track And Trace V1 (spécification OpenAPI 1.0.7) : numéro de colis 11 ou 12
 * chiffres ; Track ID 8 caractères ; Notification Card ID 6 caractères ; Digital Notification Card ID 14 caractères.
 * Le code à 10 caractères imprimé sur l'étiquette et repris dans les mails n'est AUCUN de ces formats : GLS répond
 * « E_404_01 ». Ses 8 premiers caractères sont le Track ID. D'où le repli ci-dessous, qui ramène une saisie vers un
 * format publié. Ne pas le « simplifier » en tronquant d'emblée : un numéro déjà correct serait cassé en silence.
 * Les 2 caractères retirés ne correspondent à aucun format documenté : conservés à part, on n'en déduit rien.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionGlsnumber {

    /** Codes demandés au plus par requête (spécification : au-delà, E_400_02). */
    const MAX_PER_REQUEST = 10;

    /** Seule erreur de colis qui déclenche le repli : ressource introuvable. Jamais une panne (E_500_01) ni une autre. */
    const ERROR_NOT_FOUND = 'E_404_01';

    /** Saisie à 10 caractères alphanumériques (étiquette) → Track ID à 8 caractères. */
    const LABEL_LENGTH    = 10;
    const TRACK_ID_LENGTH = 8;

    /**
     * Clé d'interrogation tirée d'une saisie : si la saisie est une URL, le code qu'elle porte (valeur de paramètre,
     * sinon dernier segment de chemin) ; puis espaces, tirets et points retirés, majuscules. Rien d'autre n'est retiré.
     * La saisie brute n'est jamais réécrite : cette clé est stockée à part.
     */
    public static function clean(string $raw): string {
        $raw = trim($raw);
        if (preg_match('~^https?://~i', $raw)) {
            $raw = self::extractFromUrl($raw);
        }
        return strtoupper((string) preg_replace('/[\s.\-]+/u', '', $raw));
    }

    /**
     * Code porté par une URL collée depuis un mail : première valeur de paramètre qui a la forme d'un code, sinon
     * dernier segment de chemin qui l'a. Aucun code reconnaissable : l'URL entière est rendue (GLS répondra inconnu).
     */
    private static function extractFromUrl(string $url): string {
        $parts      = parse_url($url);
        $candidates = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $params);
            foreach ($params as $value) {
                if (is_string($value)) {
                    $candidates[] = $value;
                }
            }
        }
        $candidates = array_merge($candidates, array_reverse(array_filter(explode('/', (string) ($parts['path'] ?? '')))));
        foreach ($candidates as $candidate) {
            $candidate = (string) preg_replace('/[\s.\-]+/u', '', rawurldecode($candidate));
            if (preg_match('/^[A-Za-z0-9_]{6,14}$/', $candidate) && preg_match('/\d/', $candidate)) {
                return $candidate;
            }
        }
        return $url;
    }

    /**
     * Repli applicable à une clé nettoyée : exactement 10 caractères alphanumériques → ['key' => 8 premiers,
     * 'suffix' => 2 derniers] ; sinon null (8 caractères, colis de test « QAS_… », numéros de colis, etc.).
     */
    public static function fallback(string $key): ?array {
        if (strlen($key) !== self::LABEL_LENGTH || !ctype_alnum($key)) {
            return null;
        }
        return ['key' => substr($key, 0, self::TRACK_ID_LENGTH), 'suffix' => substr($key, self::TRACK_ID_LENGTH)];
    }

    /**
     * Interroge des clés par lots de 10 et applique le repli, une seule fois, clé par clé.
     *
     * 1. Chaque clé est d'abord interrogée telle quelle.
     * 2. Une clé dont toutes les entrées portent E_404_01 et qui a la forme à 10 caractères est réinterrogée avec ses
     *    8 premiers caractères, dans une seconde série de lots. Jamais de troisième tentative.
     * Les résultats sont rapprochés par « requested », jamais par « unitno » ; une même clé peut rendre plusieurs
     * colis (envoi multi-colis) : le résultat est une liste.
     *
     * @param string[] $keys  Clés nettoyées (doublons fusionnés).
     * @param callable $query fn(string[] $keys): array — entrées « parcels » de la réponse (ParcelDTO en tableaux),
     *                        10 clés au plus ; lève une exception en cas d'échec technique (propagée telle quelle).
     * @return array [clé demandée => ['key' => clé qui a répondu en dernier, 'suffix' => ?string (repli réussi),
     *               'parcels' => ParcelDTO sans erreur, 'error' => ?string (code d'erreur GLS si aucun colis),
     *               'fell_back' => bool (seconde tentative faite)]]
     */
    public static function lookup(array $keys, callable $query): array {
        $keys    = array_values(array_unique(array_filter(array_map('strval', $keys), static fn(string $k) => $k !== '')));
        $first   = self::queryInBatches($keys, $query);
        $results = [];
        $retry   = [];
        foreach ($keys as $key) {
            $results[$key] = self::summarize($key, $first[$key] ?? []);
            $fallback      = self::fallback($key);
            if ($results[$key]['error'] === self::ERROR_NOT_FOUND && $fallback !== null) {
                $retry[$fallback['key']][] = $key;
            }
        }
        if (empty($retry)) {
            return $results;
        }
        $second = self::queryInBatches(array_keys($retry), $query);
        foreach ($retry as $short => $originals) {
            $summary = self::summarize((string) $short, $second[$short] ?? []);
            foreach ($originals as $key) {
                $results[$key] = [
                    'key'       => $summary['error'] === null ? (string) $short : $key,
                    'suffix'    => $summary['error'] === null ? self::fallback($key)['suffix'] : null,
                    'parcels'   => $summary['parcels'],
                    'error'     => $summary['error'],
                    'fell_back' => true,
                ];
            }
        }
        return $results;
    }

    /** Appels par lots de 10 ; entrées regroupées par « requested ». */
    private static function queryInBatches(array $keys, callable $query): array {
        $by_requested = [];
        foreach (array_chunk($keys, self::MAX_PER_REQUEST) as $batch) {
            foreach ((array) $query($batch) as $parcel) {
                if (is_array($parcel) && isset($parcel['requested'])) {
                    $by_requested[(string) $parcel['requested']][] = $parcel;
                }
            }
        }
        return $by_requested;
    }

    /**
     * Colis trouvés, ou code d'erreur si aucun. « MISSING » : GLS n'a rendu aucune entrée pour la clé demandée ;
     * « MIXED » : plusieurs codes d'erreur différents. Ni l'un ni l'autre ne déclenche le repli.
     */
    private static function summarize(string $key, array $entries): array {
        $parcels = array_values(array_filter($entries, static fn(array $p) => empty($p['errorCode'])));
        $error   = null;
        if (empty($parcels)) {
            $codes = array_values(array_unique(array_map(static fn(array $p) => (string) $p['errorCode'], $entries)));
            $error = match (count($codes)) {
                0       => 'MISSING',
                1       => $codes[0],
                default => 'MIXED',
            };
        }
        return ['key' => $key, 'suffix' => null, 'parcels' => $parcels, 'error' => $error, 'fell_back' => false];
    }
}
