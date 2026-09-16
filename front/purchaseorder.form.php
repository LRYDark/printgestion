<?php
/**
 * Renvoi aux Achats d'une commande enregistrée mais non transmise (POST resend) : même fichier archivé, mêmes
 * lignes. Droit de validation des demandes en modification, commande dans le périmètre de l'utilisateur.
 */
include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('toner')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
Session::checkRight('plugin_printgestion_validation', UPDATE);

if (!isset($_POST['resend'])) {
    Html::back();
}

// Jeton CSRF déjà validé par CheckCsrfListener avant ce fichier.
$id      = (int) ($_POST['id'] ?? 0);
$visible = array_column(PluginPrintgestionPurchaseorder::getNotSentForSession(), 'id');
if ($id <= 0 || !in_array($id, array_map('intval', $visible), true)) {
    // Inexistante, déjà transmise ou hors périmètre : même réponse.
    Session::addMessageAfterRedirect(__('Commande introuvable ou déjà transmise aux Achats : rien n\'a été renvoyé.', 'printgestion'), false, WARNING);
    Html::back();
}

$sent = PluginPrintgestionPurchaseorder::send($id);
if ($sent['ok']) {
    Session::addMessageAfterRedirect(__('Commande transmise aux Achats (fichier d\'origine renvoyé).', 'printgestion'), false, INFO);
} elseif ($sent['already']) {
    Session::addMessageAfterRedirect(htmlspecialchars($sent['error'], ENT_QUOTES, 'UTF-8'), false, WARNING);
} else {
    Session::addMessageAfterRedirect(htmlspecialchars(sprintf(__('Renvoi aux Achats en échec : %s. La commande reste enregistrée et verrouillée.', 'printgestion'), $sent['error']), ENT_QUOTES, 'UTF-8'), false, ERROR);
}
Html::back();
