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

    // ── Deux publics, deux niveaux d'information ─────────────────────────────
    // Le technicien voit l'état et l'action, rien d'autre : les détails techniques (commandes, propriétés,
    // chemins de menu, noms de règles, versions) sont ABSENTS de sa page, pas repliés. L'administrateur (droit
    // de configuration du plugin) les a derrière un chevron fermé par défaut ou un bouton « i ». Tout élément
    // réservé porte data-pg-admin : le harnais vérifie qu'aucun n'arrive jusqu'à un compte technicien.

    /** Administrateur du plugin : droit de configuration. */
    public static function isAdmin(): bool {
        return Session::haveRight('plugin_printgestion_config', READ);
    }

    /**
     * Ligne d'état colorée, texte brut. $details_html : détail replié derrière un chevron fermé, rendu pour
     * l'administrateur seulement (absent de la page sinon).
     *
     * @param string $level ok, warning, error ou info
     */
    /**
     * Écran sans rien à montrer : une phrase qui dit par quoi commencer, et les liens pour y aller. Jamais un tableau
     * vide sans explication : pendant la première heure d'un nouvel utilisateur, l'écran vide est le produit.
     *
     * @param array $links [libellé => URL]
     */
    public static function emptyState(string $text, array $links = []): string {
        $esc  = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $html = "<div class='alert alert-info d-flex align-items-start gap-2 mb-3' data-pg-empty='1'><i class='ti ti-info-circle fs-2 mt-1'></i><div>" . $esc($text);
        if (!empty($links)) {
            $html .= "<div class='mt-2 d-flex flex-wrap gap-2'>";
            foreach ($links as $label => $url) {
                $html .= "<a class='btn btn-sm btn-outline-primary' href='" . $esc($url) . "'>" . $esc($label) . "</a>";
            }
            $html .= "</div>";
        }
        return $html . "</div></div>";
    }

    /**
     * Repli ouvrable par tous les profils, contrairement a adminDetails() et au chevron de statusLine().
     *
     * Ce qui se replie ici n'est pas un detail technique reserve a l'administrateur : c'est le geste d'appoint que
     * le technicien fera s'il se trouve devant la machine. Il doit pouvoir l'ouvrir. <details> natif : ni JavaScript,
     * ni classe Bootstrap a tenir a jour.
     */
    public static function foldedNote(string $label, string $html): string {
        if ($html === '') {
            return '';
        }
        return "<details class='mt-2'><summary class='text-muted' style='cursor:pointer'>"
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</summary>"
            . "<div class='border rounded p-3 my-2'>{$html}</div></details>";
    }

    // ── Listes d'objets GLPI : le gabarit natif ──────────────────────────────
    //
    // Les lignes de ces listes sont de vrais objets GLPI : Agent, Printer, raccordements. Le tableau, ses cases et sa
    // barre d'actions massives sont ceux du cœur (components/datatable.html.twig, l'appel de Certificate_Item) :
    // GLPI déduit l'itemtype du nom des cases et n'offre que les actions permises par les droits de l'utilisateur.
    // Le plugin n'y ajoute que deux gestes, par la classe pg-datatable (printgestion.css et printgestion.js) : la
    // barre reste cachée tant que rien n'est coché — GLPI déduit l'itemtype DES CASES COCHÉES, un menu ouvert sans
    // sélection serait vide — et toute la ligne ouvre la page de son premier lien.

    /**
     * Liste rendue par le gabarit natif.
     *
     * @param array       $columns    clé => libellé, dans l'ordre d'affichage
     * @param array       $entries    lignes : une valeur par clé de colonne ; 'itemtype' et 'id' pour la case à cocher
     * @param array       $formatters clé => formateur du gabarit (raw_html pour du HTML déjà échappé par l'appelant)
     * @param string|null $massive    itemtype des actions massives ; null : pas de cases
     * @param bool        $can_edit   droit de modification sur cet itemtype
     * @param array       $options    options natives du gabarit, transmises telles quelles : datatable_id (id du
     *                                <table>, pour un script qui le retrouve), no_header (pas de ligne d'en-tête),
     *                                super_header, row_class (classe de toutes les lignes), datatable_aria_label ;
     *                                table_class : classes ajoutées au <table> (ex. card-table dans une carte).
     *                                Par ligne, le gabarit lit aussi entry['row_class'] et entry['<colonne>_colspan'].
     */
    public static function datatable(array $columns, array $entries, array $formatters = [], ?string $massive = null, bool $can_edit = false, array $options = []): string {
        $massive_on = $massive !== null && $can_edit && !empty($entries);
        if ($massive_on) {
            // getMassiveActionCheckBox() et le menu relisent cette sélection de session : gardée d'une page à
            // l'autre, elle proposait des actions sur des lignes qu'on n'avait pas cochées ici.
            unset($_SESSION['glpimassiveactionselected'][$massive]);
        }
        $allowed = array_intersect_key($options, array_flip(['datatable_id', 'no_header', 'super_header', 'row_class', 'datatable_aria_label']));
        return \Glpi\Application\View\TemplateRenderer::getInstance()->render('components/datatable.html.twig', $allowed + [
            'is_tab'              => true,
            'nofilter'            => true,
            'nosort'              => true,
            'container_class'     => 'pg-datatable',
            'table_class_style'   => trim('table-sm table-hover align-middle mb-0 ' . (string) ($options['table_class'] ?? '')),
            'columns'             => $columns,
            'formatters'          => $formatters,
            'entries'             => $entries,
            'total_number'        => count($entries),
            'filtered_number'     => count($entries),
            'showmassiveactions'  => $massive_on,
            'massiveactionparams' => [
                'num_displayed' => count($entries),
                'container'     => 'mass' . str_replace('\\', '', (string) $massive) . mt_rand(),
            ],
        ]);
    }

    public static function statusLine(string $level, string $text, string $details_html = ''): string {
        $styles = [
            'ok'      => ['ti-circle-check', 'text-success'],
            'warning' => ['ti-alert-triangle', 'text-warning'],
            'error'   => ['ti-alert-octagon', 'text-danger'],
            'info'    => ['ti-info-circle', 'text-info'],
        ];
        [$icon, $color] = $styles[$level] ?? $styles['info'];
        $details = $details_html !== '' && self::isAdmin();
        $id      = 'pg-detail-' . bin2hex(random_bytes(5));
        $out     = "<div class='d-flex align-items-center gap-2 py-1'><i class='ti {$icon} {$color} fs-2'></i>"
            . "<span class='fw-bold'>" . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . "</span>";
        if ($details) {
            $out .= self::chevron($id);
        }
        $out .= "</div>";
        if ($details) {
            $out .= "<div class='collapse' id='{$id}' data-pg-admin='1'><div class='border rounded p-3 my-2'>{$details_html}</div></div>";
        }
        return $out;
    }

    /** Section repliée (fermée) pour l'administrateur seulement ; chaîne vide pour les autres. */
    public static function adminDetails(string $label, string $html): string {
        if (!self::isAdmin() || $html === '') {
            return '';
        }
        $id = 'pg-detail-' . bin2hex(random_bytes(5));
        return "<div class='mt-2' data-pg-admin='1'><div class='d-flex align-items-center gap-2'>"
            . "<span class='text-muted'>" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</span>" . self::chevron($id) . "</div>"
            . "<div class='collapse' id='{$id}'><div class='border rounded p-3 my-2'>{$html}</div></div></div>";
    }

    /** Bouton « i » ouvrant une fenêtre d'explications, pour l'administrateur seulement ; chaîne vide sinon. */
    public static function infoButton(string $title, string $html): string {
        if (!self::isAdmin() || $html === '') {
            return '';
        }
        $id    = 'pg-info-' . bin2hex(random_bytes(5));
        $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        return "<span data-pg-admin='1'><button type='button' class='btn btn-sm btn-ghost-secondary' data-bs-toggle='modal' data-bs-target='#{$id}' title='{$title}' aria-label='{$title}'>"
            . "<i class='ti ti-info-circle'></i></button>"
            . "<div class='modal fade' id='{$id}' tabindex='-1' aria-hidden='true'><div class='modal-dialog modal-lg modal-dialog-scrollable'><div class='modal-content'>"
            . "<div class='modal-header'><h5 class='modal-title'>{$title}</h5><button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='" . htmlspecialchars(__('Fermer', 'printgestion'), ENT_QUOTES, 'UTF-8') . "'></button></div>"
            . "<div class='modal-body'>{$html}</div></div></div></div></span>";
    }

    /**
     * Carte réservée à l'administrateur : titre et chevron dans l'en-tête, contenu replié (fermé par défaut).
     * Chaîne vide pour les autres profils.
     */
    /**
     * Carte réservée à l'administrateur.
     *
     * $collapsible : un chevron a sa place devant un détail qu'on consulte rarement ; devant des réglages qu'on
     * vient modifier, il ajoute un clic à chaque visite et cache ce qu'on est venu chercher.
     */
    public static function adminCard(string $title, string $html, bool $collapsible = true): string {
        if (!self::isAdmin() || $html === '') {
            return '';
        }
        $titre = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        if (!$collapsible) {
            return "<div class='card mb-3' data-pg-admin='1'><div class='card-header'>"
                . "<h3 class='card-title mb-0'>{$titre}</h3></div><div class='card-body'>{$html}</div></div>";
        }
        $id = 'pg-detail-' . bin2hex(random_bytes(5));
        return "<div class='card mb-3' data-pg-admin='1'><div class='card-header d-flex align-items-center'>"
            . "<h3 class='card-title mb-0'>{$titre}</h3><div class='ms-auto'>" . self::chevron($id) . "</div></div>"
            . "<div class='collapse' id='{$id}'><div class='card-body'>{$html}</div></div></div>";
    }

    private static function chevron(string $target): string {
        $label = htmlspecialchars(__('Détail', 'printgestion'), ENT_QUOTES, 'UTF-8');
        // pg-chevron : pivote à l'ouverture (aria-expanded posé par Bootstrap, printgestion.css).
        return "<button type='button' class='btn btn-sm btn-ghost-secondary pg-chevron' data-pg-admin='1' data-bs-toggle='collapse' data-bs-target='#{$target}' aria-expanded='false' aria-controls='{$target}' title='{$label}' aria-label='{$label}'>"
            . "<i class='ti ti-chevron-down'></i></button>";
    }

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
     * @param string $id      Id du conteneur (facultatif, pour le ciblage JS).
     * @param string $actions HTML déjà échappé des boutons de l'écran (petits, btn-sm), posés à droite de la barre et
     *                        centrés sur sa hauteur (facultatif).
     */
    public static function statsBar(array $cards, string $id = '', string $actions = ''): void {
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

        if ($actions !== '') {
            echo "<div class='ms-auto ps-3 py-1 d-flex align-items-center gap-2 flex-shrink-0'>{$actions}</div>";
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
