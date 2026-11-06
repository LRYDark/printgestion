<?php
/**
 * Enregistre le tarif à la page propre à une imprimante (onglet « Coût à la page » de la fiche imprimante), puis
 * revient à la fiche. Jeton CSRF vérifié par GLPI avant ce fichier.
 */
include('../../../inc/includes.php');

Session::checkLoginUser();

if (!PluginPrintgestionConfig::isFeatureEnabled('cout')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

$printer = new Printer();
if (!isset($_POST['save_rate']) || !$printer->getFromDB((int) ($_POST['printers_id'] ?? 0))
    || !PluginPrintgestionPrinterrate::canEditFor($printer)) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$errors = PluginPrintgestionPrinterrate::saveFor($printer, (string) ($_POST['rate_nb'] ?? ''), (string) ($_POST['rate_color'] ?? ''));
if (empty($errors)) {
    Session::addMessageAfterRedirect(htmlspecialchars(__('Tarif de l\'imprimante enregistré.', 'printgestion'), ENT_QUOTES, 'UTF-8'));
} else {
    foreach ($errors as $error) {
        Session::addMessageAfterRedirect(htmlspecialchars($error, ENT_QUOTES, 'UTF-8'), false, ERROR);
    }
}
Html::back();
