<?php
/**
 * Les sondes vues par le module : un type dédié qui réutilise la table native des agents.
 *
 * Même liste, mêmes options de recherche que l'Agent de GLPI (plus les colonnes du plugin), mais une session de
 * recherche, un choix de colonnes et des recherches enregistrées à part : filtrer les sondes ici ne change rien à
 * Administration > Agents. La lecture suit le droit Déploiement du plugin — c'est lui qui montre déjà les sondes au
 * technicien — ; créer, modifier ou supprimer un agent restent des droits natifs. Aucune action massive sur ce type
 * dérivé : elles se font sur l'Agent lui-même, qui porte les mêmes colonnes.
 */
class PluginPrintgestionSonde extends Agent implements Glpi\Search\DefaultSearchRequestInterface {

    public static $rightname = 'plugin_printgestion_deploiement';

    /** La table native des agents (sinon GLPI déduirait « glpi_plugin_printgestion_sondes » du nom de classe). */
    public static function getTable($classname = null) {
        return 'glpi_agents';
    }

    public static function getTypeName($nb = 0) {
        return _n('Sonde', 'Sondes', $nb, 'printgestion');
    }

    public static function canView(): bool {
        return PluginPrintgestionConfig::isFeatureEnabled('deploiement') && Session::haveRight('plugin_printgestion_deploiement', READ);
    }

    // Écrire un agent reste un droit natif, quel que soit le chemin qui y mène.
    public static function canCreate(): bool {
        return Agent::canCreate();
    }

    public static function canUpdate(): bool {
        return Agent::canUpdate();
    }

    public static function canDelete(): bool {
        return Agent::canDelete();
    }

    public static function canPurge(): bool {
        return Agent::canPurge();
    }

    /** La « recherche » de ce type est l'onglet « Sondes » du module. */
    public static function getSearchURL($full = true) {
        return ($full ? PLUGIN_PRINTGESTION_WEBDIR : PLUGIN_PRINTGESTION_NOTFULL_WEBDIR) . '/front/sondes.php';
    }

    /** Les liens de la liste ouvrent la fiche native de l'agent. */
    public static function getFormURL($full = true) {
        return Agent::getFormURL($full);
    }

    public static function getFormURLWithID($id = 0, $full = true) {
        return Agent::getFormURLWithID($id, $full);
    }

    /**
     * Les options de l'Agent, plus celles du plugin juste après l'en-tête « Caractéristiques » : sans groupe à
     * part, les en-têtes de colonnes restent nus (le moteur préfixe du nom du groupe tout ce qui n'est pas dans le
     * premier). Le hook ne les ajoute pas à ce type : elles y seraient en double.
     */
    public function rawSearchOptions() {
        $tab = parent::rawSearchOptions();
        array_splice($tab, 1, 0, PluginPrintgestionAgentview::getOptionsForAgents(false));
        return $tab;
    }

    /** Première ouverture et « réinitialiser » : les sondes seulement, les plus anciennes au contact en premier. */
    public static function getDefaultSearchRequest(): array {
        return [
            'criteria' => [['field' => PluginPrintgestionAgentview::OPTION_PROBE, 'searchtype' => 'equals', 'value' => 1]],
            'sort'     => 4,
            'order'    => 'ASC',
        ];
    }
}
