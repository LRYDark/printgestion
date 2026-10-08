<?php
/**
 * Téléchargement du fichier Gesconso d'une commande (commande directe ou export de demandes) : le fichier archivé à
 * l'envoi, tel que les Achats l'ont reçu. Appelé par la case « Télécharger le fichier Gesconso » de la fenêtre
 * « Commander » et par le clic droit « Télécharger le fichier Gesconso » des alertes et des expéditions.
 *
 * GET id : la commande (glpi_plugin_printgestion_purchaseorders). Droits : Purchaseorder::canDownload().
 */
include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('toner')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

$order = new PluginPrintgestionPurchaseorder();
if (!$order->getFromDB((int) ($_GET['id'] ?? 0))) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
if (!PluginPrintgestionPurchaseorder::canDownload($order)) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$document = new Document();
if (!$document->getFromDB((int) $order->fields['documents_id'])
    || !is_readable(GLPI_DOC_DIR . '/' . (string) $document->fields['filepath'])) {
    Session::addMessageAfterRedirect(
        __('Fichier Gesconso de cette commande introuvable dans les documents GLPI.', 'printgestion'),
        false,
        ERROR
    );
    Html::back();
}

return $document->getAsResponse();
