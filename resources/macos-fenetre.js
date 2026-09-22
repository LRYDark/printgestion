// Print Gestion — fenêtre d'installation et de retrait de GLPI Agent sur macOS.
//
// JavaScript for Automation (osascript -l JavaScript) et le pont vers Cocoa : une vraie fenêtre, avec des pages,
// comme sous Windows. Le fichier de l'entité la dépose dans un dossier privé, précédée d'une ligne « var T = {...}; »
// qui porte tous les textes, puis l'ouvre sous le compte de la personne connectée — root n'a pas le droit
// d'afficher une fenêtre sur sa session.
//
// Elle ne fait rien d'autre que montrer et demander. Deux fichiers la relient au script, qui tourne en root :
//   reponses  écrit par la fenêtre au clic : « annule », ou « ips=… », « snmp=… », « freq=… », « glpi=1|0 » ;
//   etat      écrit par le script, lu ici en continu, une ligne par événement :
//               dire|texte               ce qui se passe en ce moment
//               pct|nombre               avancement, de 0 à 100
//               etape|clé|état|note      état : encours, ok, echec ou saute
//               fin|OK ou ECHEC|message  « ¶ » marque un retour à la ligne
//
// Arguments : le dossier privé, puis le chemin du journal.

ObjC.import('Cocoa');

var W = 600;
var H = T.formulaire ? 560 : 470;
var BANDE = 76;

var DOSSIER = '';
var JOURNAL = '';
var etat = { choix: '', lues: 0 };
var pages = { p1: [], p2: [], p3: [] };
var lignes = {};

function couleur(r, v, b) {
    return $.NSColor.colorWithSRGBRedGreenBlueAlpha(r / 255, v / 255, b / 255, 1);
}

// Cocoa compte les ordonnées depuis le bas ; ici, comme sous Windows, on place depuis le haut.
function cadre(x, y, l, h) {
    return $.NSMakeRect(x, H - y - h, l, h);
}

function etiquette(texte, x, y, l, h, taille, gras, teinte) {
    var t = $.NSTextField.alloc.initWithFrame(cadre(x, y, l, h));
    t.stringValue = texte;
    t.bezeled = false;
    t.drawsBackground = false;
    t.editable = false;
    t.selectable = false;
    t.font = gras ? $.NSFont.boldSystemFontOfSize(taille) : $.NSFont.systemFontOfSize(taille);
    if (teinte) {
        t.textColor = teinte;
    }
    t.cell.wraps = true;
    return t;
}

function boite(x, y, l, h, teinte) {
    var b = $.NSBox.alloc.initWithFrame(cadre(x, y, l, h));
    b.boxType = $.NSBoxCustom;
    b.borderWidth = 0;
    b.fillColor = teinte;
    b.titlePosition = $.NSNoTitle;
    return b;
}

function ajouter(vue, page) {
    fenetre.contentView.addSubview(vue);
    if (page) {
        pages[page].push(vue);
    }
    return vue;
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

// Les boutons appellent un objet Objective-C : la seule façon, en JavaScript for Automation, de recevoir un clic.
ObjC.registerSubclass({
    name: 'PGCible',
    methods: {
        'clic:': {
            types: ['void', ['id']],
            implementation: function (bouton) {
                clic(bouton.tag);
            }
        }
    }
});
var cible = $.PGCible.alloc.init;

function bouton(texte, x, l, tag, page) {
    var b = $.NSButton.alloc.initWithFrame(cadre(x, H - 48, l, 32));
    b.title = texte;
    b.bezelStyle = $.NSBezelStyleRounded;
    b.target = cible;
    b.action = 'clic:';
    b.tag = tag;
    return ajouter(b, page);
}

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

// ── Le bandeau et la carte « pour qui », communs aux trois pages ──
ajouter(boite(0, 0, W, BANDE, couleur(T.bande[0], T.bande[1], T.bande[2])), null);
ajouter(etiquette(T.titre, 24, 14, W - 48, 28, 18, true, $.NSColor.whiteColor), null);
ajouter(etiquette(T.sous_titre, 26, 46, W - 52, 18, 12, false, couleur(210, 220, 235)), null);

// ── Page 1 : les réglages, ou la confirmation du retrait ──
ajouter(boite(24, 96, W - 48, 70, couleur(244, 246, 249)), 'p1');
ajouter(etiquette(T.infos, 42, 106, W - 84, 54, 12, false, null), 'p1');
var y = 180;
if (T.message) {
    ajouter(etiquette(T.message, 26, y, W - 52, 80, 12, false, couleur(70, 78, 90)), 'p1');
    y += 90;
}
// La case « retirer aussi la sonde de GLPI », décochée : seulement quand le fichier en a reçu le droit.
var caseGlpi = null;
if (T.case_glpi) {
    caseGlpi = $.NSButton.alloc.initWithFrame(cadre(24, y, W - 48, 36));
    caseGlpi.setButtonType(3); // NSButtonTypeSwitch : une case à cocher
    caseGlpi.title = T.case_glpi;
    caseGlpi.cell.wraps = true;
    caseGlpi.state = 0;
    ajouter(caseGlpi, 'p1');
    y += 44;
}
var ips = null, snmp = null, freq = null;
if (T.formulaire) {
    ajouter(etiquette(T.lib_ips, 24, y, W - 48, 18, 12, true, null), 'p1');
    ips = ajouter($.NSTextField.alloc.initWithFrame(cadre(24, y + 22, W - 48, 24)), 'p1');
    ips.placeholderString = T.exemple_ips;
    ajouter(etiquette(T.aide_ips, 24, y + 50, W - 48, 32, 11, false, couleur(120, 128, 140)), 'p1');
    y += 92;
    ajouter(etiquette(T.lib_snmp, 24, y, W - 48, 18, 12, true, null), 'p1');
    snmp = ajouter($.NSTextField.alloc.initWithFrame(cadre(24, y + 22, 200, 24)), 'p1');
    snmp.stringValue = 'public';
    y += 58;
    ajouter(etiquette(T.lib_freq, 24, y, W - 48, 18, 12, true, null), 'p1');
    freq = ajouter($.NSPopUpButton.alloc.initWithFramePullsDown(cadre(24, y + 22, 300, 26), false), 'p1');
    freq.addItemsWithTitles($(T.frequences));
    freq.selectItemAtIndex(0);
}
ajouter(boite(0, H - 64, W, 64, couleur(241, 243, 246)), null);
var action = bouton(T.bouton, W - 24 - 120 - 12 - 170, 170, 1, 'p1');
if (T.formulaire) {
    action.keyEquivalent = '\r';
}
bouton(T.annuler, W - 24 - 120, 120, 2, 'p1');

// ── Page 2 : les étapes, cochées une à une ──
var enCours = ajouter(etiquette('', 24, 96, W - 48, 22, 15, true, couleur(31, 58, 95)), 'p2');
var yEtape = 130;
T.etapes.forEach(function (e) {
    lignes[e[0]] = { nom: e[1], vue: ajouter(etiquette('·   ' + e[1], 32, yEtape, W - 64, 20, 13, false, couleur(150, 157, 168)), 'p2') };
    yEtape += 26;
});
var barre = $.NSProgressIndicator.alloc.initWithFrame(cadre(24, yEtape + 14, W - 48, 20));
barre.style = $.NSProgressIndicatorStyleBar;
barre.indeterminate = false;
barre.minValue = 0;
barre.maxValue = 100;
ajouter(barre, 'p2');
var detail = ajouter(etiquette('', 24, yEtape + 40, W - 48, 18, 11, false, couleur(90, 98, 110)), 'p2');

// ── Page 3 : le résultat, à la place de la barre ──
var resultat = ajouter(etiquette('', 24, yEtape + 12, W - 48, H - 64 - (yEtape + 12) - 10, 12, false, null), 'p3');
bouton(T.ouvrir, 24, 170, 3, 'p3');
bouton(T.fermer, W - 24 - 120, 120, 4, 'p3');

montrer('p2', false);
montrer('p3', false);

// Pendant le travail, la croix ne ferme rien : un agent à moitié installé est pire qu'une minute d'attente.
function croix(active) {
    var c = fenetre.standardWindowButton($.NSWindowCloseButton);
    if (!c.isNil()) {
        c.enabled = active;
    }
}

function pageEtapes() {
    montrer('p1', false);
    montrer('p2', true);
    enCours.stringValue = T.preparation;
    croix(false);
}

function clic(tag) {
    if (tag === 1) {
        var r = ['ok'];
        if (T.formulaire) {
            r.push('ips=' + ips.stringValue.js.replace(/\s+/g, ' ').trim());
            r.push('snmp=' + snmp.stringValue.js.trim());
            r.push('freq=' + freq.titleOfSelectedItem.js.split(' ')[0]);
        }
        if (caseGlpi) {
            r.push('glpi=' + (caseGlpi.state === 1 ? '1' : '0'));
        }
        ecrire(DOSSIER + '/reponses', r.join('\n') + '\n');
        etat.choix = 'ok';
        pageEtapes();
    } else if (tag === 2) {
        ecrire(DOSSIER + '/reponses', 'annule\n');
        etat.choix = 'annule';
        fenetre.close;
    } else if (tag === 3) {
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
    l.vue.font = modele[2] ? $.NSFont.boldSystemFontOfSize(13) : $.NSFont.systemFontOfSize(13);
}

function finir(reussi, message) {
    barre.hidden = true;
    detail.hidden = true;
    enCours.stringValue = reussi ? T.termine : T.interrompu;
    enCours.textColor = reussi ? couleur(22, 128, 60) : couleur(190, 30, 45);
    resultat.stringValue = message.replace(/¶/g, '\n') + '\n\n' + T.journal + ' ' + JOURNAL;
    montrer('p3', true);
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
        } else if (p[0] === 'etape') {
            marquer(p[1], p[2], p[3]);
        } else if (p[0] === 'fin') {
            var f = couper(l[i], 3);
            finir(f[1] === 'OK', f[2]);
        }
    }
    etat.lues = Math.max(etat.lues, l.length - 1);
}

function run(argv) {
    DOSSIER = argv[0];
    JOURNAL = argv[1];
    app.finishLaunching;
    fenetre.center;
    fenetre.makeKeyAndOrderFront(null);
    app.activateIgnoringOtherApps(true);
    var masque = ($.NSEventMaskAny !== undefined) ? $.NSEventMaskAny : $.NSAnyEventMask;
    for (;;) {
        var ev = app.nextEventMatchingMaskUntilDateInModeDequeue(masque, $.NSDate.dateWithTimeIntervalSinceNow(0.15), $.NSDefaultRunLoopMode, true);
        if (!ev.isNil()) {
            app.sendEvent(ev);
        }
        if (!fenetre.isVisible) {
            // Fermée par la croix avant d'avoir choisi : c'est une annulation, et le script doit le savoir.
            if (etat.choix === '') {
                ecrire(DOSSIER + '/reponses', 'annule\n');
            }
            break;
        }
        if (etat.choix === 'ok') {
            lireEtat();
        }
    }
}
