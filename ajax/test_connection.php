<?php
/**
 * Test de connexion d'une intégration (GLS, MBE), appelé en AJAX depuis la carte de configuration.
 *
 * Le résultat s'affiche dans une fenêtre, sans recharger la page : un test se relance, se compare, se relit. En
 * AJAX, GLPI vérifie le jeton CSRF de l'en-tête `X-Glpi-Csrf-Token` et le **conserve** (preserve_token), donc on
 * peut réessayer autant de fois qu'on veut sans recharger.
 *
 * Ce point d'entrée ne fait qu'appeler le `testConnection()` du client concerné — le même que le bouton envoyé en
 * formulaire, qui reste le chemin de repli quand le JS n'est pas là. Rien n'est enregistré ici : le test lit et
 * jette, seul le mémo de santé (dernier appel réussi, échecs consécutifs) est tenu à jour par le client lui-même.
 *
 * Le message rendu ne porte jamais un identifiant, une passphrase, un en-tête Basic ni un lien signé : chaque client
 * masque ces valeurs avant de les rendre.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Plugin not active']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// Même droit que la page de configuration : tester une intégration, c'est la configurer.
if (!Session::haveRight('plugin_printgestion_config', UPDATE)) {
    PluginPrintgestionSecurity::denyJson();
}

$result = match ((string) ($_POST['action'] ?? '')) {
    'test_gls' => (new PluginPrintgestionGlsclient())->testConnection(),
    'test_mbe' => (new PluginPrintgestionMbeclient())->testConnection(),
    default    => null,
};
if ($result === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => __('Test inconnu.', 'printgestion')]);
    exit;
}

echo json_encode([
    'ok'      => (bool) $result['ok'],
    'message' => (string) $result['message'],
], JSON_UNESCAPED_UNICODE);
