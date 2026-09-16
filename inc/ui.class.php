<?php
/**
 * PluginPrintgestionUi — fragments d'interface partagés par les écrans du plugin.
 *
 * statsBar() : la barre de vignettes chiffrées. Un seul `card` Tabler (celui de
 * GLPI, aucun CSS maison), une vignette par indicateur séparée par un `vr` —
 * exactement le rendu des barres de stats des plugins gestion et rp, pour que
 * les trois plugins se lisent de la même façon. La rangée de grosses cartes
 * pleines qu'elle remplace poussait les tableaux sous la ligne de flottaison.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionUi {

    /**
     * Rend une barre de statistiques.
     *
     * @param array $cards Vignettes, dans l'ordre d'affichage. Par vignette :
     *   - count       int|string  valeur affichée (déjà formatée si string)
     *   - label       string      libellé sous la valeur
     *   - icon        string      classe d'icône Tabler (ex. 'ti ti-file-text')
     *   - color       string      couleur Tabler de la pastille (primary, red…)
     *   - url         string      cible du lien ; vignette inerte si absente
     *   - tooltip     string      phrase complète quand le libellé est abrégé
     *   - count_attrs array       attributs du compteur (valeur peuplée en JS)
     * @param string $id Id du conteneur (facultatif, pour le ciblage JS).
     */
    public static function statsBar(array $cards, string $id = ''): void {
        if (empty($cards)) {
            return;
        }

        $id_attr = $id !== '' ? " id='" . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . "'" : '';
        echo "<div class='card mb-3'{$id_attr}>";
        echo "<div class='card-body py-2 px-3 d-flex flex-wrap align-items-center'>";

        $first = true;
        foreach ($cards as $c) {
            if (!$first) {
                echo "<div class='vr mx-3 my-1'></div>";
            }
            $first = false;

            /*
             * L'infobulle doit se suffire à elle-même : un libellé abrégé pour
             * tenir dans la barre perd son sens lu isolément. D'où `tooltip`,
             * qui donne la phrase complète, et le libellé en repli quand il est
             * déjà explicite.
             */
            $tooltip = trim((string) ($c['tooltip'] ?? '')) !== '' ? $c['tooltip'] : ($c['label'] ?? '');
            $color   = $c['color'] ?? 'secondary';
            $icon    = $c['icon'] ?? 'ti ti-point';

            // Compteurs peuplés en JS (dashboards AJAX) : on pose leurs attributs.
            $count_attrs = '';
            foreach (($c['count_attrs'] ?? []) as $name => $value) {
                $count_attrs .= ' ' . $name . "='" . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . "'";
            }

            $url  = trim((string) ($c['url'] ?? ''));
            $tag  = $url !== '' ? 'a' : 'div';
            $href = $url !== '' ? " href='" . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . "'" : '';

            echo "<{$tag}{$href} class='d-flex align-items-center gap-2 text-decoration-none text-reset py-1'"
                . " title='" . htmlspecialchars((string) $tooltip, ENT_QUOTES, 'UTF-8') . "'>"
                . "<span class='avatar avatar-sm bg-{$color}-lt'><i class='{$icon}'></i></span>"
                . "<span class='d-flex flex-column lh-sm'>"
                . "<span class='h2 fw-bold mb-0'{$count_attrs}>"
                . htmlspecialchars((string) ($c['count'] ?? ''), ENT_QUOTES, 'UTF-8')
                . "</span>"
                . "<span class='text-muted small'>"
                . htmlspecialchars((string) ($c['label'] ?? ''), ENT_QUOTES, 'UTF-8')
                . "</span>"
                . "</span></{$tag}>";
        }

        echo "</div></div>";
    }

    /**
     * Données PHP pour le JavaScript d'une page, jamais écrites dans un script exécuté : bloc
     * <script type="application/json"> relu par JSON.parse() et, si $global est donné, placé dans window[$global]
     * par un script fixe.
     *
     * Le type application/json n'empêche pas « </script » de fermer la balise pour l'analyseur HTML : les drapeaux
     * JSON_HEX_TAG, JSON_HEX_AMP, JSON_HEX_APOS et JSON_HEX_QUOT encodent < > & ' " (\u003C…), si bien qu'aucune
     * valeur (nom d'imprimante venu du SNMP, n° de suivi, libellé) ne peut sortir du bloc, quelle que soit sa casse
     * ou son séparateur. Tout passage de données PHP vers le JavaScript d'une page passe par cette méthode.
     */
    public static function jsonData(string $id, $data, ?string $global = null): string {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $id) || ($global !== null && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $global))) {
            throw new InvalidArgumentException(sprintf('Bloc de données JSON : identifiant « %s » ou variable « %s » invalide.', $id, (string) $global));
        }
        $json = json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        $out = "<script type=\"application/json\" id=\"{$id}\">{$json}</script>";
        if ($global !== null) {
            $out .= "<script>window.{$global} = JSON.parse(document.getElementById(\"{$id}\").textContent);</script>";
        }
        return $out;
    }
}
