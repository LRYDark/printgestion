<?php
/*
 * ============================================================================
 *  SIMULATION DE TEST — fausse API Sage pour le harnais de Print Gestion
 *
 *  N'a rien à faire sur un serveur de production. Aucun appel réseau : un
 *  document « existe » si son numéro commence par BLOK, sinon il est inconnu.
 * ============================================================================
 */
function documentExiste(string $docId, ?int &$httpStatus = null): bool
{
    $httpStatus = str_starts_with($docId, 'BLOK') ? 200 : 404;
    return $httpStatus === 200;
}

function parseDocument(string $docId): array
{
    return ['client' => 'CLIENT TEST', 'tracker' => null, 'relatedInvoiceToBL' => null];
}
