/* Print Gestion — scripts client */
/* global $ */

(function () {
    'use strict';

    // Envoi unique : un formulaire qui contient un bouton [data-pg-submit-once] (action lente : téléchargement
    // GitHub, import, renvoi aux Achats) ne part qu'une fois. Un second clic réutiliserait le jeton CSRF déjà
    // consommé et afficherait « Accès refusé » alors que le premier envoi a abouti. Les boutons ne sont pas
    // désactivés (leur nom et leur valeur doivent partir avec le formulaire) : le second envoi est ignoré.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.querySelector('[data-pg-submit-once]')) {
            return;
        }
        if (form.getAttribute('data-pg-submitted') === '1') {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }
        // Après les autres gestionnaires (confirmation) : un envoi annulé ne verrouille pas le formulaire.
        setTimeout(function () {
            if (event.defaultPrevented) {
                return;
            }
            form.setAttribute('data-pg-submitted', '1');
            form.querySelectorAll('button[type=submit], input[type=submit]').forEach(function (button) {
                button.classList.add('disabled');
                button.setAttribute('aria-busy', 'true');
            });
        }, 0);
    }, true);

    // Retour arrière (cache de page) : formulaire de nouveau utilisable.
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('form[data-pg-submitted]').forEach(function (form) {
            form.removeAttribute('data-pg-submitted');
            form.querySelectorAll('[aria-busy=true]').forEach(function (button) {
                button.classList.remove('disabled');
                button.removeAttribute('aria-busy');
            });
        });
    });
})();

/* ===== Intégré depuis gestionprint (contrats : tuiles, graphes, formulaire) ===== */
/*
 * Gestion Print — JS propre au plugin (chargé globalement par GLPI).
 *
 *   Liste : on reformate la colonne « Temps restant » (jours → « X mois/jours »)
 *           et on colore UNIQUEMENT cette valeur : rouge si dépassé, orange si
 *           préavis en cours (jeu calculé côté serveur, exposé en JSON dans
 *           #printgestion-dashboard[data-notice]). Aucun fond de ligne.
 *
 *   [ÉTAPE c] Toggle des blocs du formulaire « Créer Print » selon les modes
 *             (Nouveau / Existant) pour le contrat et l'imprimante.
 *
 * Chaque bloc s'auto-désactive si son contexte n'est pas présent sur la page.
 */

// Init robuste quel que soit le moment de chargement du script : GLPI charge les
// JS de plugin dans le <head> ; si le DOM est déjà prêt, on exécute tout de suite
// (un écouteur DOMContentLoaded posé trop tard ne se déclencherait jamais).
(function () {
    function printgestionInit() {
        printgestionInitNoticeOverlay();
        printgestionInitCreateForm();
        printgestionInitCharts();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', printgestionInit);
    } else {
        printgestionInit();
    }
})();

// ── Graphiques (ECharts, lib bundlée GLPI chargée via Html::requireJs('charts')) ──

var printgestionChartRetries = 0;

/**
 * Initialise les graphiques du Dashboard. ECharts est chargé en pied de page :
 * si la lib n'est pas encore prête, on réessaie (jusqu'à ~5 s).
 */
function printgestionInitCharts() {
    var pie = document.getElementById('printgestion-chart-status');
    var bar = document.getElementById('printgestion-chart-byyear');
    if (!pie && !bar) {
        return; // pas sur le Dashboard
    }
    if (typeof echarts === 'undefined') {
        if (printgestionChartRetries++ < 50) {
            setTimeout(printgestionInitCharts, 100);
        }
        return;
    }
    if (pie) {
        printgestionRenderPie(pie);
    }
    if (bar) {
        printgestionRenderBar(bar);
    }
}

function printgestionParseChart(el) {
    try {
        return JSON.parse(el.getAttribute('data-chart') || 'null');
    } catch (e) {
        return null;
    }
}

function printgestionNoData(el) {
    el.innerHTML = '<div class="text-muted text-center p-4">'
        + "Aucune donnée" + '</div>';
}

function printgestionRenderPie(el) {
    var data = printgestionParseChart(el);
    if (!Array.isArray(data) || data.length === 0) {
        printgestionNoData(el);
        return;
    }
    var chart = echarts.init(el);
    chart.setOption({
        tooltip: { trigger: 'item', formatter: '{b} : {c} ({d}%)' },
        legend: { bottom: 0, left: 'center' },
        color: data.map(function (d) { return d.color; }),
        series: [{
            type: 'pie',
            radius: ['45%', '70%'],
            avoidLabelOverlap: true,
            itemStyle: { borderColor: '#fff', borderWidth: 2 },
            label: { formatter: '{b}\n{c}' },
            data: data.map(function (d) { return { name: d.name, value: d.value }; })
        }]
    });
    printgestionBindResize(chart);
}

function printgestionRenderBar(el) {
    var data = printgestionParseChart(el);
    if (!data || !data.years || data.years.length === 0 || !data.series) {
        printgestionNoData(el);
        return;
    }

    var chart = echarts.init(el);
    var series = data.series.map(function (s) {
        return {
            name: s.name,
            type: 'bar',
            data: s.counts,
            barMaxWidth: 28,
            cursor: 'pointer',
            itemStyle: { color: s.color, borderRadius: [4, 4, 0, 0] },
            label: { show: true, position: 'top' }
        };
    });

    chart.setOption({
        tooltip: { trigger: 'axis' },
        legend: { bottom: 0 },
        grid: { left: 36, right: 16, top: 16, bottom: 40 },
        xAxis: { type: 'category', data: data.years },
        yAxis: { type: 'value', minInterval: 1 },
        series: series
    });

    // Clic sur une barre → Liste filtrée sur l'année, selon la série cliquée
    // (date de début ou date de fin).
    chart.on('click', function (params) {
        var s = data.series[params.seriesIndex];
        if (s && s.urls) {
            var url = s.urls[params.name];
            if (url) {
                window.location.href = url;
            }
        }
    });

    printgestionBindResize(chart);
}

function printgestionBindResize(chart) {
    window.addEventListener('resize', function () { chart.resize(); });
}

/**
 * Affiche/masque dynamiquement les blocs du formulaire « Créer Print » selon les
 * modes choisis (Nouveau / Existant) pour le contrat et l'imprimante.
 * Validation réelle faite côté serveur — ceci n'est que l'UX.
 */
function printgestionInitCreateForm() {
    var form = document.getElementById('printgestion-create-form');
    if (!form) {
        return; // pas sur la page Créer Print → no-op
    }

    var bind = function (radioName, blockPrefix) {
        var apply = function () {
            var checked = form.querySelector('input[name="' + radioName + '"]:checked');
            var mode = checked ? checked.value : null;
            ['new', 'existing'].forEach(function (m) {
                var block = document.getElementById(blockPrefix + '-' + m);
                if (block) {
                    block.style.display = (m === mode) ? '' : 'none';
                }
            });
        };
        form.querySelectorAll('input[type="radio"][name="' + radioName + '"]').forEach(function (r) {
            r.addEventListener('change', apply);
        });
        apply(); // état initial
    };

    bind('contract_mode', 'gp-contract');
    bind('printer_mode', 'gp-printer');
}

/**
 * Surcouche du tableau de la Liste :
 *   - reformate la colonne calculée « Temps restant » (jours bruts → libellé
 *     humain « X mois / X jours »), en rouge si dépassé ;
 *   - colore en orange les lignes des contrats « en préavis » (jeu calculé côté
 *     serveur, exposé en JSON).
 * Le tri / filtre restent sur la valeur numérique native (jours).
 */
function printgestionInitNoticeOverlay() {
    var holder = document.getElementById('printgestion-dashboard');
    if (!holder) {
        return; // pas sur la Liste → no-op
    }

    var notice = {};
    try {
        notice = JSON.parse(holder.getAttribute('data-notice') || '{}') || {};
    } catch (e) {
        notice = {};
    }

    // id de la search option « Temps restant » (cf. PluginPrintgestionContract).
    var DAYS_COL = '9001';

    var apply = function () {
        document.querySelectorAll('table.search-results tbody > tr').forEach(function (tr) {
            var cell = tr.querySelector('td[data-searchopt-content-id="' + DAYS_COL + '"]');
            if (!cell || cell.hasAttribute('data-gp-formatted')) {
                return;
            }
            // GLPI formate le nombre avec un séparateur de milliers (espace ou
            // virgule selon la locale) → on ne garde que les chiffres et le signe
            // avant de parser (sinon « -1 928 » serait lu « -1 »).
            var raw = (cell.textContent || '').trim();
            var cleaned = raw.replace(/−/g, '-').replace(/[^0-9-]/g, '');
            var days = parseInt(cleaned, 10);
            if (cleaned === '' || isNaN(days)) {
                return;
            }
            cell.setAttribute('data-gp-formatted', '1');
            cell.classList.add('printgestion-timeleft');

            if (days < 0) {
                // Contrat expiré → rouge.
                cell.classList.add('printgestion-overdue');
                cell.textContent = 'Expiré depuis ' + printgestionHumanDuration(-days);
            } else if (days === 0) {
                cell.classList.add('printgestion-overdue');
                cell.textContent = "Expire aujourd'hui";
            } else {
                // Préavis en cours → texte orange (PAS de fond de ligne).
                var id = printgestionExtractContractId(tr);
                if (id !== null && Object.prototype.hasOwnProperty.call(notice, id)) {
                    cell.classList.add('printgestion-notice-cell');
                }
                cell.textContent = printgestionHumanDuration(days) + ' restant';
            }
        });
    };

    apply();

    // Réapplique après un rafraîchissement AJAX de la liste (tri/pagination/MA).
    if (window.jQuery) {
        jQuery(document).on('search_refresh', 'table.search-results', function () {
            setTimeout(apply, 50);
        });
    }
}

/**
 * Extrait l'ID du contrat d'une ligne de résultat Search, de façon robuste
 * (indépendante de l'ordre/visibilité des colonnes) :
 *   1) lien itemlink vers la fiche native  contract.form.php?id=ID  (ou /Contract/ID)
 *   2) case à cocher d'action de masse      name="item[...][ID]"
 *
 * Le lien « Contrat » pointe vers la fiche Contrat native (résolution par table),
 * tandis que la case à cocher porte l'itemtype dédié — d'où l'extraction générique.
 *
 * @returns {string|null} l'ID (chaîne, pour matcher les clés JSON) ou null.
 */
function printgestionExtractContractId(tr) {
    var link = tr.querySelector('a[href*="contract.form.php?id="], a[href*="/Contract/"]');
    if (link) {
        var href = link.getAttribute('href') || '';
        var mm = href.match(/[?&]id=(\d+)/) || href.match(/\/Contract\/(\d+)/);
        if (mm) {
            return mm[1];
        }
    }
    var cb = tr.querySelector('input.massive_action_checkbox[name^="item["]');
    if (cb) {
        var m = (cb.getAttribute('name') || '').match(/\]\[(\d+)\]/);
        if (m) {
            return m[1];
        }
    }
    return null;
}

/**
 * Convertit un nombre de jours (positif) en libellé humain :
 *   < 60 j → « N jour(s) » ; < 12 mois → « N mois » ; sinon « N an(s) M mois ».
 * (Approximation 30 j/mois, 12 mois/an — affichage seul ; tri/filtre = jours exacts.)
 */
function printgestionHumanDuration(days) {
    days = Math.abs(parseInt(days, 10)) || 0;
    if (days === 0) {
        return "aujourd'hui";
    }
    if (days < 60) {
        return days + (days > 1 ? ' jours' : ' jour');
    }
    var months = Math.round(days / 30);
    if (months < 12) {
        return months + ' mois';
    }
    var years = Math.floor(months / 12);
    var rem   = months % 12;
    return years + (years > 1 ? ' ans' : ' an') + (rem > 0 ? ' ' + rem + ' mois' : '');
}
