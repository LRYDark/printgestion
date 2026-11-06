<?php
/**
 * PluginPrintgestionPriorityalert — onglet « Alertes » du module toner : ce qui demande une action, regroupé à un seul
 * endroit au lieu d'alourdir les écrans de travail (Expéditions, Demandes d'envoi, Export).
 *
 *   - commandes enregistrées mais non transmises aux Achats (PluginPrintgestionPurchaseorder::showNotSentCard()) ;
 *   - alertes prioritaires (PluginPrintgestionAlert::listPriorityAlerts()) : mauvaise imprimante, livraison en retard,
 *     cartouche expédiée ou livrée mais toujours pas changée (interrupteur de la configuration).
 *
 * Chaque alerte est une carte encadrée de rouge ; l'onglet porte le nombre d'alertes en pastille rouge, visible depuis
 * tous les écrans du module (PluginPrintgestionMenu::showTabBar()).
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionPriorityalert {

    /** Nombre d'alertes de l'onglet, pour sa pastille, calculé une fois par requête. */
    private static ?int $count = null;

    public static function count(): int {
        if (self::$count === null) {
            self::$count = (Session::haveRight(PluginPrintgestionPurchaseorder::$rightname, READ)
                    ? count(PluginPrintgestionPurchaseorder::getNotSentForSession()) : 0)
                + (Session::haveRight('plugin_printgestion_expedition', READ)
                    ? count(PluginPrintgestionAlert::listPriorityAlerts()) : 0);
        }
        return self::$count;
    }

    /** Contenu de l'onglet : commandes non transmises, puis alertes prioritaires. Vrai s'il y avait quelque chose. */
    public static function show(?int $entities_id = null): bool {
        PluginPrintgestionPurchaseorder::showNotSentCard();
        $shown = !empty(PluginPrintgestionPurchaseorder::getNotSentForSession())
            && Session::haveRight(PluginPrintgestionPurchaseorder::$rightname, READ);
        if (!Session::haveRight('plugin_printgestion_expedition', READ)) {
            return $shown;
        }
        $alerts = PluginPrintgestionAlert::listPriorityAlerts($entities_id);
        if (empty($alerts)) {
            return $shown;
        }
        $can_update = Session::haveRight('plugin_printgestion_expedition', UPDATE);

        foreach ($alerts as $pa) {
            echo "<div class='card border-danger border-2 mb-2'><div class='card-body py-2'>";

            if ($pa['kind'] === 'wrong_printer') {
                $intended_url = Printer::getFormURLWithID($pa['intended_printers_id']);
                $detected_url = Printer::getFormURLWithID($pa['detected_printers_id']);

                echo "<div class='d-flex align-items-start'>";
                echo "<i class='fa-solid fa-triangle-exclamation fa-lg me-3 mt-1 text-danger'></i>";
                echo "<div class='flex-grow-1'>";
                echo "<strong>" . __('Mauvaise imprimante', 'printgestion') . "</strong>";
                if (!empty($pa['entity_name'])) {
                    echo " — " . htmlspecialchars($pa['entity_name'], ENT_QUOTES, 'UTF-8');
                }
                echo "<br>";
                // Proposition à confirmer, jamais appliquée seule : l'envoi, les deux machines et
                // les dates, pour vérifier sur place avant de réattribuer.
                $sent_label = !empty($pa['date_delivered'])
                    ? sprintf(__('livré le %s', 'printgestion'), Html::convDateTime((string) $pa['date_delivered']))
                    : sprintf(__('expédié le %s', 'printgestion'), Html::convDateTime((string) $pa['date_shipped']));
                echo "<span class='text-muted small'>"
                    . sprintf(
                        __('Envoi #%1$d « %2$s » (%3$s) prévu pour %4$s ; pose de la même référence détectée sur %5$s le %6$s. À vérifier sur place avant de réattribuer.', 'printgestion'),
                        (int) $pa['expeditions_id'],
                        htmlspecialchars($pa['toner_property'], ENT_QUOTES, 'UTF-8'),
                        htmlspecialchars($sent_label, ENT_QUOTES, 'UTF-8'),
                        "<a href='" . htmlspecialchars($intended_url, ENT_QUOTES, 'UTF-8') . "'><strong>"
                            . htmlspecialchars($pa['intended_name'], ENT_QUOTES, 'UTF-8') . "</strong></a>",
                        "<a href='" . htmlspecialchars($detected_url, ENT_QUOTES, 'UTF-8') . "'><strong>"
                            . htmlspecialchars($pa['detected_name'], ENT_QUOTES, 'UTF-8') . "</strong></a>",
                        htmlspecialchars(Html::convDateTime((string) $pa['date_detected']), ENT_QUOTES, 'UTF-8')
                    )
                    . "</span>";
                echo "</div>";

                if ($can_update) {
                    echo "<div class='ms-3 d-flex gap-1'>";
                    // Confirmation portée par un attribut data-* entièrement échappé et
                    // lue par un gestionnaire délégué (script en fin de bloc) : aucun
                    // texte dynamique (nom d'imprimante issu du SNMP) dans un attribut on*.
                    $confirm_msg = sprintf(
                        __('Réattribuer l\'envoi #%1$d de %2$s à %3$s ? Il sera marqué posé sur %3$s.', 'printgestion'),
                        (int) $pa['expeditions_id'],
                        $pa['intended_name'],
                        $pa['detected_name']
                    );
                    echo "<form method='post' action='" . PLUGIN_PRINTGESTION_WEBDIR . "/ajax/reassign_expedition.php' class='d-inline'"
                        . " data-pg-confirm=\"" . htmlspecialchars($confirm_msg, ENT_QUOTES, 'UTF-8') . "\">";
                    echo Html::hidden('expedition_id', ['value' => $pa['expeditions_id']]);
                    echo Html::hidden('new_printers_id', ['value' => $pa['detected_printers_id']]);
                    echo "<button type='submit' class='btn btn-sm btn-primary'>"
                        . "<i class='fa-solid fa-exchange-alt me-1'></i>"
                        . htmlspecialchars(sprintf(__('Réattribuer à %s', 'printgestion'), $pa['detected_name']), ENT_QUOTES, 'UTF-8')
                        . "</button>";
                    Html::closeForm();

                    echo "<form method='post' action='" . PLUGIN_PRINTGESTION_WEBDIR . "/ajax/resolve_alert.php' class='d-inline'>";
                    echo Html::hidden('alert_id', ['value' => $pa['alert_id']]);
                    echo "<button type='submit' class='btn btn-sm btn-outline-secondary'>"
                        . "<i class='fa-solid fa-xmark me-1'></i>" . __('Ignorer', 'printgestion') . "</button>";
                    Html::closeForm();
                    echo "</div>";
                }
                echo "</div>";
            } elseif ($pa['kind'] === 'late_delivery') {
                // Partie et toujours pas livrée au-delà du délai du transporteur. Aucun délai ne fait passer une
                // expédition « livrée » : cette ligne signale, elle ne conclut pas.
                $printer_url = Printer::getFormURLWithID($pa['printers_id']);
                echo "<div class='d-flex align-items-start'>";
                echo "<i class='fa-solid fa-truck-fast fa-lg me-3 mt-1 text-danger'></i>";
                echo "<div class='flex-grow-1'>";
                echo "<strong>" . __('Livraison en retard', 'printgestion') . "</strong>";
                if (!empty($pa['entity_name'])) {
                    echo " — " . htmlspecialchars($pa['entity_name'], ENT_QUOTES, 'UTF-8');
                }
                echo "<br>";
                echo "<span class='text-muted small'>"
                    . sprintf(
                        __('Cartouche "%1$s" pour %2$s : partie depuis %3$d jour(s) ouvré(s), aucune livraison constatée (délai %4$s : %5$d jour(s) ouvré(s))', 'printgestion'),
                        htmlspecialchars($pa['toner_property'], ENT_QUOTES, 'UTF-8'),
                        "<a href='" . htmlspecialchars($printer_url, ENT_QUOTES, 'UTF-8') . "'><strong>"
                            . htmlspecialchars($pa['printer_name'], ENT_QUOTES, 'UTF-8') . "</strong></a>",
                        (int) $pa['days_since'],
                        $pa['carrier'] !== '' ? strtoupper(htmlspecialchars($pa['carrier'], ENT_QUOTES, 'UTF-8')) : __('par défaut', 'printgestion'),
                        (int) $pa['threshold']
                    );
                if (!empty($pa['tracking'])) {
                    echo " — <code>" . htmlspecialchars($pa['tracking'], ENT_QUOTES, 'UTF-8') . "</code>";
                }
                echo "</span>";
                echo PluginPrintgestionGlstracking::renderLineFor((int) ($pa['expedition_id'] ?? 0));
                echo "</div>";
                echo "</div>";
            } else {
                $printer_url = Printer::getFormURLWithID($pa['printers_id']);
                echo "<div class='d-flex align-items-start'>";
                echo "<i class='fa-solid fa-clock fa-lg me-3 mt-1 text-warning'></i>";
                echo "<div class='flex-grow-1'>";
                echo "<strong>" . __('Expédition en retard', 'printgestion') . "</strong>";
                if (!empty($pa['entity_name'])) {
                    echo " — " . htmlspecialchars($pa['entity_name'], ENT_QUOTES, 'UTF-8');
                }
                echo "<br>";
                echo "<span class='text-muted small'>"
                    . sprintf(
                        __('Cartouche "%s" expédiée vers %s depuis %d jour(s) (pas encore installée)', 'printgestion'),
                        htmlspecialchars($pa['toner_property'], ENT_QUOTES, 'UTF-8'),
                        "<a href='" . htmlspecialchars($printer_url, ENT_QUOTES, 'UTF-8') . "'><strong>"
                            . htmlspecialchars($pa['printer_name'], ENT_QUOTES, 'UTF-8') . "</strong></a>",
                        $pa['days_since']
                    );
                if (!empty($pa['tracking'])) {
                    echo " — " . strtoupper(htmlspecialchars($pa['carrier'], ENT_QUOTES, 'UTF-8'))
                        . " <code>" . htmlspecialchars($pa['tracking'], ENT_QUOTES, 'UTF-8') . "</code>";
                }
                echo "</span>";
                // Ligne de suivi GLS (rien sans clés, rien pour un autre transporteur).
                echo PluginPrintgestionGlstracking::renderLineFor((int) ($pa['expedition_id'] ?? 0));
                echo "</div>";
                echo "</div>";
            }

            echo "</div></div>";
        }

        // Confirmation des formulaires portant data-pg-confirm : le message est lu via
        // getAttribute(), jamais interprété comme du code ou du HTML.
        echo "<script>
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form instanceof HTMLFormElement && form.hasAttribute('data-pg-confirm')
            && !window.confirm(form.getAttribute('data-pg-confirm'))) {
            e.preventDefault();
        }
    }, true);
    </script>";

        return true;
    }
}
