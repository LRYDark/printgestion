/* Print Gestion — scripts client */
/* global $ */

(function () {
    'use strict';

    // Les actions ponctuelles de la carte de santé ne doivent pas soumettre tout le grand formulaire de
    // configuration. Construit un POST minimal hors de ce formulaire avec le jeton standalone de GLPI.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-pg-post-action]');
        if (!(button instanceof HTMLButtonElement) || event.defaultPrevented) {
            return;
        }

        var csrf = document.querySelector('meta[property="glpi:csrf_token"]');
        var action = button.getAttribute('data-pg-post-action');
        var url = button.getAttribute('data-pg-post-url');
        if (!(csrf instanceof HTMLMetaElement) || !action || !url) {
            return;
        }

        var form = document.createElement('form');
        form.method = 'post';
        form.action = url;
        form.hidden = true;

        var actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = action;
        actionInput.value = '1';
        form.appendChild(actionInput);

        var csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = '_glpi_csrf_token';
        csrfInput.value = csrf.content;
        form.appendChild(csrfInput);

        button.classList.add('disabled');
        button.setAttribute('aria-busy', 'true');
        document.body.appendChild(form);
        form.submit();
    });

    // Test de connexion (GLS, MBE) : la réponse s'affiche dans une fenêtre, la page n'est pas rechargée et la
    // saisie en cours n'est pas perdue. L'appel part en AJAX, où GLPI vérifie le jeton CSRF de l'en-tête et le
    // **conserve** : on peut réessayer autant de fois qu'on veut sans recharger.
    //
    // Sans fenêtre dans la page ou sans Bootstrap, on ne fait rien : le bouton reste un bouton d'envoi et le
    // formulaire part comme avant, avec le résultat en message. Le confort est une amélioration, jamais un passage
    // obligé.
    document.addEventListener('click', function (event) {
        if (!(event.target instanceof Element)) {
            return;
        }
        var button = event.target.closest('[data-pg-test]');
        if (!(button instanceof HTMLButtonElement) || event.defaultPrevented) {
            return;
        }
        var modalElement = document.getElementById('pg-test-modal');
        var corps = document.getElementById('pg-test-modal-body');
        var csrf = document.querySelector('meta[property="glpi:csrf_token"]');
        // Tout doit être là AVANT de retenir le clic : sinon on laisse le bouton faire son envoi de formulaire,
        // qui rend le même résultat en message. Retenir le clic puis échouer laisserait l'écran muet.
        if (!modalElement || !corps || !csrf || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            return;
        }
        event.preventDefault();

        // Aucun texte venu du serveur n'est inséré en HTML, ici pas plus qu'ailleurs : tout passe par textContent.
        var afficher = function (texte, classe) {
            var alerte = document.createElement('div');
            alerte.className = classe;
            alerte.setAttribute('role', 'alert');
            alerte.textContent = texte;
            corps.replaceChildren(alerte);
        };

        var titre = document.getElementById('pg-test-modal-title');
        var carte = button.closest('.card');
        var nom = carte ? (carte.querySelector('.card-title') || {}).textContent : null;
        if (titre && nom) {
            titre.textContent = nom.trim();
        }

        var attente = document.createElement('div');
        attente.className = 'd-flex align-items-center gap-2';
        var roue = document.createElement('span');
        roue.className = 'spinner-border spinner-border-sm';
        roue.setAttribute('role', 'status');
        roue.setAttribute('aria-hidden', 'true');
        var texte = document.createElement('span');
        texte.textContent = button.getAttribute('data-pg-test-wait') || '…';
        attente.appendChild(roue);
        attente.appendChild(texte);
        corps.replaceChildren(attente);
        bootstrap.Modal.getOrCreateInstance(modalElement).show();

        fetch(button.getAttribute('data-pg-test-url'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': csrf.content
            },
            body: 'action=' + encodeURIComponent(button.getAttribute('data-pg-test'))
        }).then(function (reponse) {
            // Une réponse qui n'est pas du JSON (page d'erreur, accès refusé) ne doit pas se confondre avec un
            // serveur muet : les deux cas ne se cherchent pas au même endroit.
            return reponse.text().then(function (brut) {
                try {
                    return { donnees: JSON.parse(brut), statut: reponse.status };
                } catch (e) {
                    return { donnees: null, statut: reponse.status };
                }
            });
        }).then(function (resultat) {
            if (resultat.donnees === null) {
                afficher(
                    (button.getAttribute('data-pg-test-http') || 'Réponse inattendue de GLPI') + ' (HTTP ' + resultat.statut + ').',
                    'alert alert-danger mb-0'
                );
                return;
            }
            afficher(
                resultat.donnees.message || '',
                'alert mb-0 ' + (resultat.donnees.ok === true ? 'alert-success' : 'alert-danger')
            );
        }).catch(function () {
            afficher(button.getAttribute('data-pg-test-error') || '', 'alert alert-danger mb-0');
        });
    });

    // Envoi unique : un bouton [data-pg-submit-once] (action lente : téléchargement GitHub, import, renvoi aux
    // Achats) ne part qu'une fois. Un second clic réutiliserait le jeton CSRF déjà consommé et afficherait « Accès
    // refusé » alors que le premier envoi a abouti.
    //
    // Le garde-fou suit le BOUTON CLIQUÉ, jamais le formulaire qui le contient. La distinction n'est pas théorique :
    // le formulaire de configuration porte à la fois des actions lentes (« J'ai vérifié », création de la règle TAG)
    // et le bouton « Enregistrer » ordinaire. Protéger le formulaire entier revenait à poser la classe `disabled`
    // sur « Enregistrer » — et Bootstrap coupe les clics sur `disabled` (pointer-events: none). Le bouton devenait
    // définitivement mort, sans message et sans rien dans les journaux du serveur, puisque aucune requête ne partait.
    var dernierClic = null;
    document.addEventListener('click', function (event) {
        // Ce gestionnaire voit TOUS les clics de la page : une exception ici casserait le reste. La cible est
        // vérifiée avant d'appeler closest(), qui n'existe que sur un Element.
        if (!(event.target instanceof Element)) {
            dernierClic = null;
            return;
        }
        dernierClic = event.target.closest('button, input[type=submit], input[type=image]');
    }, true);

    document.addEventListener('submit', function (event) {
        if (!(event.target instanceof HTMLFormElement)) {
            return;
        }
        // event.submitter est la référence ; le dernier clic sert de repli pour les navigateurs qui ne le donnent pas.
        var button = event.submitter || dernierClic;
        if (!button || !button.hasAttribute || !button.hasAttribute('data-pg-submit-once')) {
            return;
        }
        if (button.getAttribute('data-pg-submitted') === '1') {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }
        // Après les autres gestionnaires (confirmation) : un envoi annulé ne verrouille pas le bouton.
        setTimeout(function () {
            if (event.defaultPrevented) {
                return;
            }
            button.setAttribute('data-pg-submitted', '1');
            button.classList.add('disabled');
            button.setAttribute('aria-busy', 'true');
        }, 0);
    }, true);

    // Liste native (components/datatable.html.twig, classe pg-datatable) : sa barre d'actions massives est cachee
    // par printgestion.css tant que rien n'est coche, pg-coche la montre. Ce n'est pas qu'une question d'allure —
    // GLPI deduit l'itemtype DES CASES COCHEES, donc ouvrir le menu sans selection ne donnerait qu'une liste vide.
    function pgBarreMassive(liste) {
        // Les cases des lignes seulement : « tout cocher » est dans l'en-tete.
        liste.classList.toggle('pg-coche', liste.querySelectorAll('tbody .massive_action_checkbox:checked').length > 0);
    }

    document.addEventListener('change', function (event) {
        var coche = event.target.closest ? event.target.closest('.massive_action_checkbox') : null;
        var liste = coche ? coche.closest('.pg-datatable') : null;
        if (!liste) {
            return;
        }
        // « change » part avant que le onclick de « tout cocher » ait fini son travail : compter maintenant
        // donnerait le compte d'avant.
        window.setTimeout(function () {
            pgBarreMassive(liste);
        }, 0);
    });

    // A l'arrivee sur la page, et au retour arriere du navigateur qui restitue les cases cochees.
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.pg-datatable').forEach(pgBarreMassive);
    });
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('.pg-datatable').forEach(pgBarreMassive);
    });

    // Ligne de tableau cliquable : [data-pg-href], ou toute ligne d'une liste native pg-datatable (elle ouvre son
    // premier lien), ouvre sa page depuis n'importe quel point de la ligne. Le lien visible reste en place — c'est
    // lui qui sert au clavier et aux lecteurs d'écran.
    document.addEventListener('click', function (event) {
        var ligne = event.target.closest('[data-pg-href], .pg-datatable tbody tr');
        if (!ligne || event.defaultPrevented || event.button !== 0) {
            return;
        }
        // Ce qui a deja un comportement propre le garde. data-pg-noclick et la cellule de la case a cocher : viser
        // a cote d'une case ne doit pas faire partir la page alors qu'on est en train de selectionner.
        if (event.target.closest('a, button, input, select, textarea, label, [data-bs-toggle], [data-pg-noclick]')) {
            return;
        }
        var cellule = event.target.closest('td');
        if (cellule && cellule.querySelector('.massive_action_checkbox')) {
            return;
        }
        // Selection de texte a la souris : on ne navigue pas en relachant.
        var selection = window.getSelection();
        if (selection && selection.toString().length > 0) {
            return;
        }
        var lien = ligne.hasAttribute('data-pg-href') ? null : ligne.querySelector('a[href]');
        var url = lien ? lien.getAttribute('href') : ligne.getAttribute('data-pg-href');
        if (!url) {
            return;
        }
        if (event.ctrlKey || event.metaKey || event.shiftKey) {
            window.open(url, '_blank');
            return;
        }
        window.location.href = url;
    });

    // Retour arrière (cache de page) : boutons de nouveau utilisables.
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('[data-pg-submitted]').forEach(function (button) {
            button.removeAttribute('data-pg-submitted');
            button.classList.remove('disabled');
            button.removeAttribute('aria-busy');
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

// Sous-formulaire « Commander » des alertes toner : aperçu Gesconso recalculé pendant la saisie d'une référence de
// cartouche, et confirmation à l'envoi quand des cartouches restent impossibles à écrire dans le fichier (elles sont
// alors retirées de la commande, les autres partent). Appelé par le script du sous-formulaire (Alertview).
window.pgOrderPreview = function (config) {
    'use strict';
    var box    = document.getElementById(config.box);
    var button = document.getElementById(config.button);
    var form   = box ? box.closest('form') : null;
    var csrf   = document.querySelector('meta[property="glpi:csrf_token"]');
    if (!box || !button || !form) {
        return;
    }
    var state     = { blocked: config.blocked || {}, total: config.total || 0 };
    var timer     = null;
    var pending   = null;
    var seq       = 0;
    var confirmed = false;

    function refresh() {
        timer = null;
        var body = new URLSearchParams();
        (config.ids || []).forEach(function (id) {
            body.append('ids[]', id);
        });
        new FormData(form).forEach(function (value, name) {
            if (name.indexOf('pg_newcart[') === 0) {
                body.append(name, value);
            }
        });
        var mine = ++seq;
        box.style.opacity = '0.6';
        pending = fetch(config.url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': csrf ? csrf.content : ''
            },
            body: body.toString()
        }).then(function (reponse) {
            return reponse.json();
        }).then(function (donnees) {
            // Seule la dernière saisie compte : une réponse plus ancienne arrivée en retard est ignorée.
            if (mine === seq && donnees && donnees.ok === true) {
                box.innerHTML = donnees.html;
                state = { blocked: donnees.blocked || {}, total: donnees.total || 0 };
            }
        }).catch(function () {
            // Aperçu indisponible : l'état précédent reste, le serveur revérifie tout à l'envoi.
        }).finally(function () {
            if (mine === seq) {
                pending = null;
                box.style.opacity = '';
            }
        });
        return pending;
    }

    function schedule(event) {
        var name = event.target && event.target.name ? event.target.name : '';
        if (name.indexOf('pg_newcart[') !== 0) {
            return;
        }
        confirmed = false;
        if (timer) {
            clearTimeout(timer);
        }
        timer = setTimeout(refresh, 400);
    }
    form.addEventListener('input', schedule);
    form.addEventListener('change', schedule);

    button.addEventListener('click', function (event) {
        if (confirmed) {
            return;
        }
        // Saisie pas encore contrôlée : on attend l'aperçu à jour, puis on rejoue le clic.
        if (timer || pending) {
            event.preventDefault();
            if (timer) {
                clearTimeout(timer);
                refresh();
            }
            (pending || Promise.resolve()).then(function () {
                button.click();
            });
            return;
        }
        form.querySelectorAll('input[name="pg_exclude[]"]').forEach(function (champ) {
            champ.remove();
        });
        var keys = Object.keys(state.blocked);
        if (keys.length === 0) {
            return;
        }
        if (keys.length >= state.total) {
            event.preventDefault();
            window.alert(config.messages.none);
            return;
        }
        var lignes = keys.map(function (key) {
            return '- ' + state.blocked[key];
        }).join('\n');
        var question = config.messages.head + '\n' + lignes + '\n\n'
            + config.messages.confirm.replace('%d', String(state.total - keys.length));
        if (!window.confirm(question)) {
            event.preventDefault();
            return;
        }
        keys.forEach(function (key) {
            var champ = document.createElement('input');
            champ.type  = 'hidden';
            champ.name  = 'pg_exclude[]';
            champ.value = key;
            form.appendChild(champ);
        });
        confirmed = true;
    });
};
