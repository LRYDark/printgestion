// Print Gestion — fenêtre d'installation et de retrait de GLPI Agent sur macOS.
//
// JavaScript for Automation (osascript -l JavaScript) et le pont vers Cocoa : une vraie fenêtre, avec des pages,
// comme sous Windows. Le fichier de l'entité la dépose dans un dossier privé, précédée d'une ligne « var T = {...}; »
// qui porte tous les textes, puis l'ouvre sous le compte de la personne connectée — root n'a pas le droit
// d'afficher une fenêtre sur sa session.
//
// Elle ne fait rien d'autre que montrer et demander. Deux fichiers la relient au script, qui tourne en root :
//   reponses  écrit par la fenêtre au clic : « annule », ou « ok » suivi de « ips=… », « snmp=… », « freq=… »,
//             « mode=glpi|local », « maj=oui|non » (seulement quand la case existe), « glpi=0|1|2 » ;
//   etat      écrit par le script, lu ici en continu, une ligne par événement :
//               dire|texte               ce qui se passe en ce moment
//               pct|nombre               avancement, de 0 à 100
//               note|texte               sous la barre : « 12 Mo sur 22 Mo », « Environ une minute »… (vide : effacée)
//               etape|clé|état|note      état : encours, ok, echec ou saute
//               fin|OK ou ECHEC|message  « ¶ » marque un retour à la ligne
//   vivant    battement de la sentinelle du script (secondes depuis 1970), toutes les deux secondes : s'il
//             s'arrête plus de quinze secondes sans fin donnée, le script est mort — la fenêtre le dit et se
//             laisse fermer, au lieu d'attendre pour toujours sans aucun bouton.
//
// Arguments : le dossier privé, puis le chemin du journal.
//
// Deux pièges du pont JavaScript, constatés sur macOS 26 et 27, et qui rendaient la fenêtre inerte :
//   - $.NSEventMaskAny vaut « tous les bits » en Objective-C, mais JavaScript le reçoit arrondi à 2^63 : repassé à
//     Cocoa, il ne garde que ce bit-là, et la boucle n'obtient plus jamais un événement — ni clic, ni croix.
//     Le masque est donc un nombre posé ici (MASQUE_EVENEMENTS), jamais la constante.
//   - dans le rappel d'un clic, bouton.tag arrive comme une CHAÎNE (« 1 »), et « 1 » === 1 est faux : chaque tag est
//     relu par parseInt avant d'être comparé.

ObjC.import('Cocoa');

// Tous les types d'événements tiennent sous le bit 40 ; 2^53 - 1 est le plus grand entier que JavaScript porte
// exactement, et il revient à Cocoa tel quel. Voir l'en-tête.
var MASQUE_EVENEMENTS = Math.pow(2, 53) - 1;
var TOUCHE_ENFONCEE = 10;  // NSEventTypeKeyDown
var CODE_ECHAP = 53;

var W = 600;
var BANDE = 76;
var PIED = 64;
var H = 0;

var DOSSIER = '';
var JOURNAL = '';
var etat = { choix: '', lues: 0, glpi: 0, occupe: false, fini: false };
// p1 est commune aux trois pages de réglages (la carte « pour qui », les notes, les boutons) ; p1a, p1b et p1c sont
// les trois pages elles-mêmes — l'agent, les imprimantes, le scan. Sans formulaire (retrait), tout tient dans p1.
var pages = { p1: [], p1a: [], p1b: [], p1c: [], p2: [], p3: [] };
var page = 1;
var lignes = {};
// Chaque vue avec sa place comptée depuis le HAUT, comme sous Windows ; les cadres Cocoa (comptés depuis le bas)
// sont posés une fois la hauteur de la fenêtre connue — elle dépend de ce que les textes occupent.
var poses = [];

function couleur(r, v, b) {
    return $.NSColor.colorWithSRGBRedGreenBlueAlpha(r / 255, v / 255, b / 255, 1);
}

function police(taille, gras) {
    return gras ? $.NSFont.boldSystemFontOfSize(taille) : $.NSFont.systemFontOfSize(taille);
}

// Cocoa compte les ordonnées depuis le bas ; ici, comme sous Windows, on place depuis le haut.
function cadre(x, y, l, h) {
    return $.NSMakeRect(x, H - y - h, l, h);
}

// Hauteur qu'un texte occupe sur une largeur donnée : chaque bloc se dimensionne lui-même et le suivant se pose
// dessous, comme les libellés AutoSize de Windows. Une traduction plus longue ou un nom de client à rallonge ne
// passent plus sous un bouton.
var metre = $.NSTextField.alloc.initWithFrame($.NSMakeRect(0, 0, 100, 20));
metre.bezeled = false;
metre.drawsBackground = false;
metre.editable = false;
metre.cell.wraps = true;
function hauteur(texte, l, taille, gras) {
    metre.font = police(taille, gras);
    metre.stringValue = texte;
    var mesure = metre.cell.cellSizeForBounds($.NSMakeRect(0, 0, l, 100000));
    return Math.ceil(Number(mesure.height)) + 2;
}

// Une vue et sa place depuis le haut. Le cadre réel est posé par placer(), quand H est connue.
function poser(vue, x, y, l, h, page) {
    poses.push({ vue: vue, x: x, y: y, l: l, h: h, bas: false });
    if (page) {
        pages[page].push(vue);
    }
    return vue;
}

function etiquette(texte, x, y, l, taille, gras, teinte, page, h) {
    var t = $.NSTextField.alloc.initWithFrame($.NSMakeRect(0, 0, l, 20));
    t.stringValue = texte;
    t.bezeled = false;
    t.drawsBackground = false;
    t.editable = false;
    t.selectable = false;
    t.font = police(taille, gras);
    // Sans couleur donnée, une couleur sombre quand même : la couleur par défaut de macOS devient blanche en
    // thème sombre, et le texte disparaissait sur nos cartes claires.
    t.textColor = teinte ? teinte : couleur(32, 38, 46);
    t.cell.wraps = true;
    if (h === undefined) {
        h = hauteur(texte, l, taille, gras);
    }
    poser(t, x, y, l, h, page);
    return { vue: t, bas: y + h };
}

function boite(x, y, l, h, teinte, page) {
    var b = $.NSBox.alloc.initWithFrame($.NSMakeRect(0, 0, l, h));
    b.boxType = $.NSBoxCustom;
    b.borderWidth = 0;
    b.fillColor = teinte;
    b.titlePosition = $.NSNoTitle;
    return poser(b, x, y, l, h, page);
}

function montrer(page, visible) {
    pages[page].forEach(function (v) {
        v.hidden = !visible;
    });
}

function lire(chemin) {
    var s = $.NSString.stringWithContentsOfFileEncodingError(chemin, $.NSUTF8StringEncoding, null);
    return s.isNil() ? null : s.js;
}

function ecrire(chemin, texte) {
    // Écriture atomique : le script ne lit jamais un fichier à moitié écrit.
    $(texte).writeToFileAtomicallyEncodingError(chemin, true, $.NSUTF8StringEncoding, null);
}

// Le tag d'un bouton, relu comme un nombre : le pont le livre en chaîne dans les rappels (voir l'en-tête).
function numero(valeur) {
    var n = parseInt(String(valeur), 10);
    return isNaN(n) ? -1 : n;
}

// Les boutons appellent un objet Objective-C : la seule façon, en JavaScript for Automation, de recevoir un clic.
ObjC.registerSubclass({
    name: 'PGCible',
    methods: {
        'clic:': {
            types: ['void', ['id']],
            implementation: function (bouton) {
                clic(numero(bouton.tag));
            }
        },
        // Les boutons radio du choix de suppression : un seul coché à la fois.
        'choix:': {
            types: ['void', ['id']],
            implementation: function (bouton) {
                choisir(numero(bouton.tag));
            }
        }
    }
});
var cible = $.PGCible.alloc.init;

// ── Le bandeau et la carte « pour qui », communs à toutes les pages ──
boite(0, 0, W, BANDE, couleur(T.bande[0], T.bande[1], T.bande[2]), null);
etiquette(T.titre, 24, 14, W - 48, 18, true, $.NSColor.whiteColor, null, 28);
etiquette(T.sous_titre, 26, 46, W - 52, 12, false, couleur(210, 220, 235), null, 18);

// ── Page 1 : les réglages, ou la confirmation du retrait ──
var hInfos = hauteur(T.infos, W - 84, 12, false);
boite(24, BANDE + 20, W - 48, hInfos + 28, couleur(244, 246, 249), 'p1');
etiquette(T.infos, 42, BANDE + 34, W - 84, 12, false, null, 'p1', hInfos);
var suite = BANDE + 20 + hInfos + 28 + 14;
if (T.message) {
    // Ce que « Installer » va faire, ou ce que le retrait enlève : visible sur chaque page, comme sous Windows.
    suite = etiquette(T.message, 26, suite, W - 52, 12, false, couleur(70, 78, 90), 'p1').bas + 16;
}
// Ce qu'on supprime dans GLPI : rien, la sonde, ou tout. Le premier choix est pris, et l'exclusivité est tenue
// à la main (des boutons radio ne se groupent tout seuls que s'ils partagent leur action, déjà prise ici par
// les boutons de la fenêtre). Absents quand le fichier n'a pas ce pouvoir.
var radiosGlpi = [];
if (T.choix_glpi && T.choix_glpi.length) {
    T.choix_glpi.forEach(function (libelle, rang) {
        var r = $.NSButton.alloc.initWithFrame($.NSMakeRect(0, 0, W - 48, 42));
        r.setButtonType(4); // NSButtonTypeRadio
        r.title = libelle;
        r.cell.wraps = true;
        r.target = cible;
        r.action = 'choix:';
        r.tag = rang;
        r.state = rang === 0 ? 1 : 0;
        radiosGlpi.push(poser(r, 24, suite + rang * 46, W - 48, 42, 'p1'));
    });
    suite += T.choix_glpi.length * 46 + 8;
}

var maj = null, ips = null, snmp = null, freq = null, mode = null;
if (T.formulaire) {
    // Trois pages posées au même endroit : chacune repart de la même ordonnée, et une seule est montrée. La
    // fenêtre prend la hauteur de la plus chargée : elle ne bouge pas d'une page à l'autre.
    var yPage = suite;
    var bas = [];

    // Page 1 : l'agent. La mise à jour automatique — une case si le technicien choisit, une phrase si le serveur a
    // tranché — et, sous la case, ce que « décochée » veut dire. NSButtonTypeSwitch vaut 3.
    var y = yPage;
    if (T.maj_impose) {
        y = etiquette(T.maj_impose, 26, y, W - 52, 11, false, couleur(90, 98, 110), 'p1a').bas;
    } else if (T.lib_maj) {
        maj = $.NSButton.alloc.initWithFrame($.NSMakeRect(0, 0, W - 48, 24));
        maj.setButtonType(3);
        maj.title = T.lib_maj;
        maj.state = 0;
        poser(maj, 24, y, W - 48, 24, 'p1a');
        y += 30;
        if (T.aide_maj) {
            y = etiquette(T.aide_maj, 44, y, W - 72, 11, false, couleur(120, 128, 140), 'p1a').bas;
        }
    }
    bas.push(y);

    // Page 2 : les imprimantes.
    y = yPage;
    etiquette(T.lib_ips, 24, y, W - 48, 12, true, null, 'p1b', 18);
    y += 22;
    ips = poser($.NSTextField.alloc.initWithFrame($.NSMakeRect(0, 0, W - 48, 24)), 24, y, W - 48, 24, 'p1b');
    ips.placeholderString = T.exemple_ips;
    y += 28;
    y = etiquette(T.aide_ips, 24, y, W - 48, 11, false, couleur(120, 128, 140), 'p1b').bas + 10;
    etiquette(T.lib_snmp, 24, y, W - 48, 12, true, null, 'p1b', 18);
    y += 22;
    snmp = poser($.NSTextField.alloc.initWithFrame($.NSMakeRect(0, 0, 200, 24)), 24, y, 200, 24, 'p1b');
    snmp.stringValue = 'public';
    y += 24;
    bas.push(y);

    // Page 3 : le scan.
    y = yPage;
    etiquette(T.lib_freq, 24, y, W - 48, 12, true, null, 'p1c', 18);
    y += 22;
    freq = poser($.NSPopUpButton.alloc.initWithFramePullsDown($.NSMakeRect(0, 0, 300, 26), false), 24, y, 300, 26, 'p1c');
    freq.addItemsWithTitles($(T.frequences));
    freq.selectItemAtIndex(numero(T.freq_defaut) > 0 ? numero(T.freq_defaut) : 0);
    y += 30;
    if (T.aide_freq) {
        y = etiquette(T.aide_freq, 24, y, W - 48, 11, false, couleur(120, 128, 140), 'p1c').bas + 10;
    }
    // Qui pilote le scan : un menu quand GLPI Inventory est là, une simple phrase sinon — il n'y a alors qu'un
    // seul chemin possible, et proposer un choix serait mentir.
    if (T.modes && T.modes.length) {
        etiquette(T.lib_mode, 24, y, W - 48, 12, true, null, 'p1c', 18);
        y += 22;
        mode = poser($.NSPopUpButton.alloc.initWithFramePullsDown($.NSMakeRect(0, 0, W - 48, 26), false), 24, y, W - 48, 26, 'p1c');
        mode.addItemsWithTitles($(T.modes));
        mode.selectItemAtIndex(0);
        y += 30;
        if (T.aide_mode) {
            y = etiquette(T.aide_mode, 24, y, W - 48, 11, false, couleur(120, 128, 140), 'p1c').bas;
        }
    } else if (T.sans_mode) {
        y = etiquette(T.sans_mode, 24, y, W - 48, 11, false, couleur(120, 128, 140), 'p1c').bas;
    }
    bas.push(y);

    suite = Math.max.apply(null, bas);
}

// ── Page 2 : les étapes, cochées une à une ──
var enCours = etiquette('', 24, BANDE + 20, W - 48, 15, true, couleur(31, 58, 95), 'p2', 22).vue;
var yEtape = BANDE + 54;
T.etapes.forEach(function (e) {
    lignes[e[0]] = { nom: e[1], vue: etiquette('·   ' + e[1], 32, yEtape, W - 64, 13, false, couleur(150, 157, 168), 'p2', 20).vue };
    yEtape += 26;
});
var barre = $.NSProgressIndicator.alloc.initWithFrame($.NSMakeRect(0, 0, W - 48, 20));
barre.style = $.NSProgressIndicatorStyleBar;
barre.indeterminate = false;
barre.minValue = 0;
barre.maxValue = 100;
poser(barre, 24, yEtape + 14, W - 48, 20, 'p2');
var detail = etiquette('', 24, yEtape + 40, W - 48, 11, false, couleur(90, 98, 110), 'p2', 18).vue;

// La fenêtre prend la hauteur de la page la plus chargée, plus le pied de page : rien ne peut passer dessous.
H = Math.max(suite, yEtape + 58) + 18 + PIED;

// ── Page 3 : le résultat, à la place de la barre ──
var resultat = etiquette('', 24, yEtape + 12, W - 48, 12, false, null, 'p3', H - PIED - (yEtape + 12) - 10).vue;

// ── Pied de page : les boutons. Ceux-là suivent le bas de la fenêtre quand elle grandit pour le résultat. ──
function bouton(texte, x, l, tag, page) {
    var b = $.NSButton.alloc.initWithFrame($.NSMakeRect(0, 0, l, 32));
    b.title = texte;
    b.bezelStyle = $.NSBezelStyleRounded;
    b.target = cible;
    b.action = 'clic:';
    b.tag = tag;
    poser(b, x, H - 48, l, 32, page);
    poses[poses.length - 1].bas = true;
    return b;
}
boite(0, H - PIED, W, PIED, couleur(241, 243, 246), null);
poses[poses.length - 1].bas = true;
var action = bouton(T.bouton, W - 24 - 120 - 12 - 170, 170, 1, 'p1');
var annuler = bouton(T.annuler, W - 24 - 120, 120, 2, 'p1');
// Navigation entre les trois pages de réglages : seulement quand il y a trois pages à parcourir.
var suivant = null, precedent = null;
if (T.formulaire) {
    suivant = bouton(T.suivant, W - 24 - 120 - 12 - 170, 170, 5, 'p1');
    precedent = bouton(T.precedent, 24, 170, 6, 'p1');
}
var ouvrir = bouton(T.ouvrir, 24, 170, 3, 'p3');
var fermer = bouton(T.fermer, W - 24 - 120, 120, 4, 'p3');

// ── La fenêtre, à la hauteur voulue, puis chaque vue à sa place ──
var app = $.NSApplication.sharedApplication;
app.setActivationPolicy($.NSApplicationActivationPolicyRegular);

var fenetre = $.NSWindow.alloc.initWithContentRectStyleMaskBackingDefer(
    $.NSMakeRect(0, 0, W, H),
    $.NSWindowStyleMaskTitled | $.NSWindowStyleMaskClosable,
    $.NSBackingStoreBuffered,
    false
);
fenetre.title = T.fenetre;
fenetre.releasedWhenClosed = false;
// Au-dessus des autres fenêtres, comme sous Windows (TopMost) : lancée depuis le Terminal, elle ne doit pas passer
// derrière lui. Niveau 3 = NSFloatingWindowLevel.
fenetre.level = 3;
// Dessinée en clair, quel que soit le thème du Mac. Toutes les couleurs de cette fenêtre sont posées en dur
// (bandeau, cartes, vert et rouge des étapes) ; laisser le thème sombre décider du fond et des libellés donnait
// du texte blanc sur des cartes blanches, et des boutons dont on ne lisait plus le nom.
try {
    var clair = ($.NSAppearanceNameAqua !== undefined) ? $.NSAppearanceNameAqua : $('NSAppearanceNameAqua');
    fenetre.appearance = $.NSAppearance.appearanceNamed(clair);
} catch (e) {
    // Trop vieux macOS pour choisir son apparence : les couleurs explicites ci-dessus suffisent à rester lisible.
}
fenetre.backgroundColor = couleur(255, 255, 255);

function placer(p) {
    p.vue.setFrame(cadre(p.x, p.y, p.l, p.h));
    // 8 = NSViewMinYMargin : la vue reste à la même distance du haut quand la fenêtre grandit. Le pied de page et
    // ses boutons gardent le masque nul et suivent le bas.
    p.vue.autoresizingMask = p.bas ? 0 : 8;
}
poses.forEach(function (p) {
    placer(p);
    fenetre.contentView.addSubview(p.vue);
});

montrer('p2', false);
montrer('p3', false);
montrerPage(1);

// Pendant le travail, la croix ne ferme rien : un agent à moitié installé est pire qu'une minute d'attente.
function croix(active) {
    var c = fenetre.standardWindowButton($.NSWindowCloseButton);
    if (!c.isNil()) {
        c.enabled = active;
    }
}

// Exclusivité des boutons radio, tenue à la main : un seul coché, et son rang est ce qui partira au serveur.
function choisir(rang) {
    radiosGlpi.forEach(function (r, i) {
        r.state = i === rang ? 1 : 0;
    });
    etat.glpi = rang;
}

// Entrée et Échap suivent les boutons visibles, comme sous Windows : Entrée fait « Suivant » puis « Installer »
// (jamais depuis la première page), Échap fait « Annuler ». Le retrait n'a pas de bouton par défaut : on ne retire
// pas un agent parce qu'on a appuyé sur une touche. Les boutons cachés perdent leur touche : un bouton invisible
// ne doit rien pouvoir déclencher.
function touches() {
    if (etat.occupe) {
        action.keyEquivalent = '';
        annuler.keyEquivalent = '';
        if (suivant) {
            suivant.keyEquivalent = '';
        }
        fermer.keyEquivalent = etat.fini ? '\r' : '';
        return;
    }
    annuler.keyEquivalent = '';
    if (T.formulaire) {
        action.keyEquivalent = page === 3 ? '\r' : '';
        suivant.keyEquivalent = page === 3 ? '' : '\r';
    }
}

// Une page de réglages à la fois. « Installer » n'apparaît qu'à la dernière : on n'installe pas depuis la
// première page par un appui sur Entrée.
function montrerPage(n) {
    if (!T.formulaire) {
        touches();
        return;
    }
    page = n < 1 ? 1 : (n > 3 ? 3 : n);
    montrer('p1a', page === 1);
    montrer('p1b', page === 2);
    montrer('p1c', page === 3);
    action.hidden = page !== 3;
    suivant.hidden = page === 3;
    precedent.hidden = page === 1;
    touches();
}

function pageEtapes() {
    etat.occupe = true;
    montrer('p1', false);
    montrer('p1a', false);
    montrer('p1b', false);
    montrer('p1c', false);
    montrer('p2', true);
    enCours.stringValue = T.preparation;
    touches();
    croix(false);
}

function clic(tag) {
    if (tag === 1) {
        var r = ['ok'];
        if (T.formulaire) {
            r.push('ips=' + ips.stringValue.js.replace(/\s+/g, ' ').trim());
            r.push('snmp=' + snmp.stringValue.js.trim());
            r.push('freq=' + freq.titleOfSelectedItem.js.split(' ')[0]);
            // Le rang suffit : le premier choix est « piloté par GLPI », le second « en local ».
            r.push('mode=' + (mode && numero(mode.indexOfSelectedItem) === 1 ? 'local' : 'glpi'));
            // Sans case — le serveur a tranché —, on n'écrit rien : le script garde sa valeur.
            if (maj) {
                r.push('maj=' + (numero(maj.state) === 1 ? 'oui' : 'non'));
            }
        }
        if (radiosGlpi.length) {
            r.push('glpi=' + etat.glpi);
        }
        ecrire(DOSSIER + '/reponses', r.join('\n') + '\n');
        etat.choix = 'ok';
        pageEtapes();
    } else if (tag === 2) {
        ecrire(DOSSIER + '/reponses', 'annule\n');
        etat.choix = 'annule';
        fenetre.close;
    } else if (tag === 5) {
        montrerPage(page + 1);
    } else if (tag === 6) {
        montrerPage(page - 1);
    } else if (tag === 3) {
        // L'éditeur passerait derrière une fenêtre toujours au premier plan.
        fenetre.level = 0;
        $.NSWorkspace.sharedWorkspace.openFile(JOURNAL);
    } else if (tag === 4) {
        fenetre.close;
    }
}

function marquer(cle, quoi, note) {
    var l = lignes[cle];
    if (!l) {
        return;
    }
    var suite = note ? '  (' + note + ')' : '';
    var modele = {
        encours: ['▶', couleur(31, 58, 95), true],
        ok: ['✓', couleur(22, 128, 60), false],
        echec: ['✗', couleur(190, 30, 45), false],
        saute: ['–', couleur(120, 128, 140), false]
    }[quoi] || ['–', couleur(120, 128, 140), false];
    l.vue.stringValue = modele[0] + '   ' + l.nom + (quoi === 'encours' ? '' : suite);
    l.vue.textColor = modele[1];
    l.vue.font = police(13, modele[2]);
}

function finir(reussi, message) {
    // Une fin qui arrive avant le clic (le script est mort sur la première page) : la page des étapes d'abord.
    if (!etat.occupe) {
        pageEtapes();
    }
    etat.fini = true;
    barre.hidden = true;
    detail.hidden = true;
    enCours.stringValue = reussi ? T.termine : T.interrompu;
    enCours.textColor = reussi ? couleur(22, 128, 60) : couleur(190, 30, 45);
    var texte = message.replace(/¶/g, '\n') + '\n\n' + T.journal + ' ' + JOURNAL;
    // Le résultat prend la place qu'il lui faut, et la fenêtre grandit s'il le faut — comme sous Windows, où le
    // libellé se dimensionne et la fenêtre suit. Le haut reste en place, le pied de page descend.
    var h = hauteur(texte, W - 48, 12, false);
    var besoin = yEtape + 12 + h + 18 + PIED;
    if (besoin > H) {
        H = besoin;
        fenetre.setContentSize($.NSMakeSize(W, H));
        fenetre.center;
    }
    resultat.setFrame(cadre(24, yEtape + 12, W - 48, h));
    resultat.stringValue = texte;
    montrer('p3', true);
    touches();
    croix(true);
    fenetre.makeKeyAndOrderFront(null);
    app.activateIgnoringOtherApps(true);
}

// Une ligne « a|b|c|reste » coupée en n morceaux au plus : un message peut contenir « | ».
function couper(ligne, n) {
    var p = ligne.split('|');
    var debut = p.slice(0, n - 1);
    debut.push(p.slice(n - 1).join('|'));
    return debut;
}

function lireEtat() {
    var texte = lire(DOSSIER + '/etat');
    if (texte === null) {
        return;
    }
    var l = texte.split('\n');
    // La dernière ligne peut être en cours d'écriture : elle attendra le tour suivant.
    for (var i = etat.lues; i < l.length - 1; i++) {
        var p = couper(l[i], 4);
        if (p[0] === 'dire') {
            enCours.stringValue = couper(l[i], 2)[1];
        } else if (p[0] === 'pct') {
            barre.doubleValue = parseFloat(p[1]) || 0;
        } else if (p[0] === 'note') {
            detail.stringValue = couper(l[i], 2)[1];
        } else if (p[0] === 'etape') {
            marquer(p[1], p[2], p[3]);
        } else if (p[0] === 'fin') {
            var f = couper(l[i], 3);
            finir(f[1] === 'OK', f[2]);
        }
    }
    etat.lues = Math.max(etat.lues, l.length - 1);
}

// Échap sur la page de fin ferme la fenêtre, comme Entrée : sous Windows, « Fermer » répond aux deux touches.
// Un bouton Cocoa n'a qu'une touche ; celle-ci est lue ici. Vrai si l'événement a été consommé.
function toucheEchap(ev) {
    if (numero(ev.type) !== TOUCHE_ENFONCEE) {
        return false;
    }
    if (numero(ev.keyCode) === CODE_ECHAP && etat.fini) {
        clic(4);
        return true;
    }
    return false;
}

// La sentinelle du script bat dans « vivant » ; quinze secondes de silence sans fin donnée, c'est un script mort.
var DELAI_MORT = 15;
var dernierBattement = 0;
function surveiller() {
    if (etat.fini || Date.now() - dernierBattement < 1000) {
        return;
    }
    dernierBattement = Date.now();
    var v = lire(DOSSIER + '/vivant');
    if (v === null) {
        return;
    }
    var t = parseInt(v, 10);
    if (!isNaN(t) && Date.now() / 1000 - t > DELAI_MORT) {
        finir(false, T.mort || '');
    }
}

function run(argv) {
    DOSSIER = argv[0];
    JOURNAL = argv[1];
    app.finishLaunching;
    fenetre.center;
    fenetre.makeKeyAndOrderFront(null);
    app.activateIgnoringOtherApps(true);
    var modeBoucle = ($.NSDefaultRunLoopMode !== undefined) ? $.NSDefaultRunLoopMode : $('kCFRunLoopDefaultMode');
    for (;;) {
        var ev = app.nextEventMatchingMaskUntilDateInModeDequeue(MASQUE_EVENEMENTS, $.NSDate.dateWithTimeIntervalSinceNow(0.15), modeBoucle, true);
        if (!ev.isNil()) {
            if (!toucheEchap(ev)) {
                app.sendEvent(ev);
            }
            app.updateWindows;
        }
        if (!fenetre.isVisible) {
            // Fermée par la croix avant d'avoir choisi : c'est une annulation, et le script doit le savoir.
            if (etat.choix === '') {
                ecrire(DOSSIER + '/reponses', 'annule\n');
            }
            break;
        }
        // Lu même avant le clic : une fin peut arriver si le script meurt sur la première page.
        lireEtat();
        surveiller();
    }
}
