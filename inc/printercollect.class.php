<?php
/**
 * Les imprimantes vues par la collecte : un type dédié qui réutilise la table native des imprimantes.
 *
 * Même liste, mêmes options de recherche que l'Imprimante de GLPI (plus l'état de la collecte), mais une session
 * de recherche, un choix de colonnes et des recherches enregistrées à part : filtrer ici ne change rien à
 * Parc > Imprimantes. La lecture suit le droit Déploiement du plugin ; créer, modifier ou supprimer une imprimante
 * restent des droits natifs. Aucune action massive sur ce type dérivé : elles se font sur l'Imprimante elle-même,
 * qui porte les mêmes colonnes.
 */
class PluginPrintgestionPrintercollect extends Printer implements Glpi\Search\DefaultSearchRequestInterface {

    public static $rightname = 'plugin_printgestion_deploiement';

    /** La table native des imprimantes (sinon GLPI déduirait « glpi_plugin_printgestion_printercollects »). */
    public static function getTable($classname = null) {
        return 'glpi_printers';
    }

    public static function getTypeName($nb = 0) {
        return _n('Imprimante collectée', 'Imprimantes collectées', $nb, 'printgestion');
    }

    public static function canView(): bool {
        return PluginPrintgestionConfig::isFeatureEnabled('deploiement') && Session::haveRight('plugin_printgestion_deploiement', READ);
    }

    // Écrire une imprimante reste un droit natif, quel que soit le chemin qui y mène.
    public static function canCreate(): bool {
        return Printer::canCreate();
    }

    public static function canUpdate(): bool {
        return Printer::canUpdate();
    }

    public static function canDelete(): bool {
        return Printer::canDelete();
    }

    public static function canPurge(): bool {
        return Printer::canPurge();
    }

    /** La « recherche » de ce type est la vue « Imprimantes collectées » de l'écran « Sondes & remontée ». */
    public static function getSearchURL($full = true) {
        return ($full ? PLUGIN_PRINTGESTION_WEBDIR : PLUGIN_PRINTGESTION_NOTFULL_WEBDIR) . '/front/sondes.php?vue=imprimantes';
    }

    /** Les liens de la liste ouvrent la fiche native de l'imprimante. */
    public static function getFormURL($full = true) {
        return Printer::getFormURL($full);
    }

    public static function getFormURLWithID($id = 0, $full = true) {
        return Printer::getFormURLWithID($id, $full);
    }

    /** Première ouverture et « réinitialiser » : les imprimantes à surveiller, hors collecte normale. */
    public static function getDefaultSearchRequest(): array {
        return [
            'criteria' => [['field' => PluginPrintgestionCollectview::OPTION_STATE, 'searchtype' => 'notequals', 'value' => PluginPrintgestionCollect::STATE_OK]],
        ];
    }
}
