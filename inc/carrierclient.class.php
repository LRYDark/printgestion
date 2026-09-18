<?php
/**
 * PluginPrintgestionCarrierclient — ce qu'un transporteur sait faire pour Print Gestion : une interface, une classe
 * qui l'implémente (GLS), rien de plus. Pas de registre, pas de fabrique : le jour où un second transporteur
 * arrive, on écrit une seconde classe.
 *
 * Le suivi transporteur est de l'information affichée : il ne ferme jamais le cycle anti-doublon, ne change jamais
 * le statut d'une expédition, ne décide de rien.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

interface PluginPrintgestionCarrierclient {

    /** Clés saisies dans la configuration : sans elles, rien n'est appelé et rien n'est affiché. */
    public function isConfigured(): bool;

    /**
     * Demande un jeton et le jette. Jamais le jeton dans le message.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public function testConnection(): array;

    /**
     * Interroge des clés (dix au plus par appel) et rend les entrées « parcels » de la réponse, telles quelles
     * (tableaux), erreurs par colis comprises. Une seule exception pour tout échec technique.
     *
     * @param string[] $keys
     * @return array
     * @throws PluginPrintgestionCarrierexception
     */
    public function track(array $keys): array;
}
