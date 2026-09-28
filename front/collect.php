<?php
/**
 * Ancienne page « Contrôle de la remontée » : son contenu est la vue « Imprimantes collectées » de l'écran
 * « Sondes & remontée ». L'adresse reste servie (liens anciens, favoris) et y renvoie, filtre d'état compris.
 */
include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('deploiement')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
Session::checkRight('plugin_printgestion_deploiement', READ);

$state = (string) ($_GET['state'] ?? '');
if ($state !== '' && isset(PluginPrintgestionCollect::getStateLabels()[$state])) {
    Html::redirect(PluginPrintgestionCollectview::getStateURL($state));
}
Html::redirect(PluginPrintgestionPrintercollect::getSearchURL());
