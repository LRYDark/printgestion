<?php
/**
 * PluginPrintgestionCarrierexception — échec d'un appel transporteur, typé pour que l'appelant décide : réseau,
 * authentification, serveur et requête comptent pour le disjoncteur ; quota (429) arrête la journée ; budget
 * et disjoncteur arrêtent le cycle. Le message ne contient jamais un secret ni un jeton.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionCarrierexception extends RuntimeException {

    const KIND_NETWORK = 'network';
    const KIND_AUTH    = 'auth';
    const KIND_SERVER  = 'server';
    const KIND_REQUEST = 'request';
    const KIND_QUOTA   = 'quota';
    const KIND_BUDGET  = 'budget';
    const KIND_BREAKER = 'breaker';

    private string $kind;

    public function __construct(string $kind, string $message) {
        parent::__construct($message);
        $this->kind = $kind;
    }

    public function getKind(): string {
        return $this->kind;
    }

    /** Échec technique : compte pour le disjoncteur (pas un quota atteint, pas un arrêt volontaire). */
    public function isTechnical(): bool {
        return in_array($this->kind, [self::KIND_NETWORK, self::KIND_AUTH, self::KIND_SERVER, self::KIND_REQUEST], true);
    }
}
