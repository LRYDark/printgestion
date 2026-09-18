<?php
/**
 * Génère docs/apercu_gabarits.html à partir des définitions
 * réelles de hook.php — aucun accès BDD, exécutable en CLI :
 *
 *   php tools/generate_apercu.php
 *
 * Chaque gabarit est rendu avec des balises d'exemple (variante simple et/ou
 * multi selon les usages réels du code).
 */

require __DIR__ . '/../hook.php';

$templates = plugin_printgestion_template_definitions();

// ── Jeux de balises d'exemple ────────────────────────────────────────────────
$li = function (array $lines, string $suffix = ''): string {
    $html = '<ul>';
    foreach ($lines as $l) {
        $html .= '<li>' . $l . '</li>';
    }
    if ($suffix !== '') {
        $html .= '<li>' . $suffix . '</li>';
    }
    return $html . '</ul>';
};

$variants = [
    'gabarit_planif' => [
        [
            'title'   => 'Envoi SIMPLE (1 cartouche, 1 client) — « Envoyer cartouche », groupe de 1, commande de 1',
            'balises' => [
                '##printgestion.client##'    => 'Client test &gt; Site test A',
                '##printgestion.printer##'   => 'Canon iR-ADV C3530i',
                '##printgestion.toner##'     => 'tonerblack',
                '##printgestion.level##'     => '8',
                '##printgestion.days##'      => '4',
                '##printgestion.cartridge##' => 'C-EXV 49 Noir',
                '##printgestion.contract##'  => 'CONTRAT-TEST-2026',
                '##printgestion.count##'     => '1',
            ],
        ],
    ],
    'gabarit_planif_group' => [
        [
            'title'      => 'Envoi MULTI (plusieurs cartouches / plusieurs clients) — liste plafonnée à 20 lignes, détail complet dans l\'Excel joint',
            'attachment' => 'Commande_cartouches_10062026_1430_a1b2c3d4.xlsx',
            'balises'    => [
                '##printgestion.client##'          => '9 clients',
                '##printgestion.printer##'         => '12 imprimantes',
                '##printgestion.contract##'        => '',
                '##printgestion.count##'           => '23',
                '##printgestion.cartridges_list##' => $li([
                    '<strong>C-EXV 49 Noir</strong> — ACME SARL — Canon iR-ADV C3530i',
                    '<strong>Toner test cyan</strong> — Collectivité test — Konica C258',
                    '<strong>Toner test noir A</strong> — Client test A — TST-A-01',
                    '<strong>Toner test magenta B</strong> — Client test B — TST-B-01',
                    '<strong>Toner test jaune C</strong> — Site test C1 — TST-SITEC1-01',
                ], '… et 18 autres — détail complet dans le fichier Excel joint'),
            ],
        ],
        [
            'title'   => 'Envoi groupé même imprimante (3 toners d\'un copieur) — mono-client',
            'attachment' => 'Commande_cartouches_10062026_1430_e5f6a7b8.xlsx',
            'balises' => [
                '##printgestion.client##'          => 'Client test &gt; Site test A',
                '##printgestion.printer##'         => 'Canon iR-ADV C3530i',
                '##printgestion.contract##'        => 'CONTRAT-TEST-2026',
                '##printgestion.count##'           => '3',
                '##printgestion.cartridges_list##' => $li([
                    '<strong>C-EXV 49 Noir</strong> — tonerblack — 8% — 4 j',
                    '<strong>C-EXV 49 Cyan</strong> — tonercyan — 12% — 6 j',
                    '<strong>C-EXV 49 Magenta</strong> — tonermagenta — 15% — 9 j',
                ]),
            ],
        ],
    ],
    'gabarit_achat' => [
        [
            'title'      => 'Commande achats — corps synthétique, TOUT le détail est dans l\'Excel joint (format Gesconso)',
            'attachment' => 'Commande_cartouches_10062026_1430_c9d0e1f2.xlsx',
            'balises'    => [
                '##printgestion.count##'  => '12',
                '##printgestion.client##' => '5 clients',
            ],
        ],
    ],
    'gabarit_commercial' => [
        [
            'title'   => 'Envoi SIMPLE (info après expédition marquée envoyée — avec transporteur)',
            'balises' => [
                '##printgestion.client##'   => 'Client test &gt; Site test A',
                '##printgestion.printer##'  => 'Canon iR-ADV C3530i',
                '##printgestion.toner##'    => 'tonerblack',
                '##printgestion.level##'    => '8',
                '##printgestion.days##'     => '4',
                '##printgestion.carrier##'  => 'GLS',
                '##printgestion.tracking##' => '00TSTA1X',
            ],
        ],
        [
            'title'   => 'DIGEST cron toner bas (1 seul mail pour toutes les alertes du run)',
            'balises' => [
                '##printgestion.client##'          => '4 clients',
                '##printgestion.printer##'         => '6 imprimantes',
                '##printgestion.toner##'           => '7 toners bas',
                '##printgestion.level##'           => '5',
                '##printgestion.days##'            => '2',
                '##printgestion.cartridges_list##' => $li([
                    '<strong>Canon iR-ADV C3530i</strong> — ACME SARL — tonerblack — 8% — 4 j',
                    '<strong>Konica C258</strong> — Collectivité test — tonercyan — 5% — 2 j',
                    '<strong>TST-A-01</strong> — Client test A — tonerblack — 11% — 6 j',
                    '<strong>TST-B-01</strong> — Client test B — tonermagenta — 14% — 8 j',
                ], '… et 3 autres'),
                '##printgestion.count##'           => '7',
            ],
        ],
    ],
    'gabarit_rappel' => [
        [
            'title'   => 'DIGEST cron rappel installation (1 seul mail pour toutes les expéditions en retard)',
            'balises' => [
                '##printgestion.count##'           => '3',
                '##printgestion.printer##'         => '3 imprimantes',
                '##printgestion.client##'          => '2 clients',
                '##printgestion.days##'            => '12',
                '##printgestion.cartridges_list##' => $li([
                    '<strong>Canon iR-ADV C3530i</strong> — ACME SARL — tonerblack — expédiée il y a 12 j',
                    '<strong>Konica C258</strong> — Collectivité test — tonercyan — expédiée il y a 9 j',
                    '<strong>HP E87640</strong> — ACME SARL — tonerblack — expédiée il y a 8 j',
                ]),
            ],
        ],
    ],
    'gabarit_courtoisie' => [
        [
            'title'   => 'Courtoisie client — REGROUPÉ par destinataire (3 imprimantes du même contact = 1 seul mail)',
            'balises' => [
                '##printgestion.client##'        => 'Client test &gt; Site test A',
                '##printgestion.printer##'       => 'Canon iR-ADV C3530i, Konica C258, HP E87640',
                '##printgestion.count##'         => '3',
                '##printgestion.printers_list##' => $li([
                    '<strong>Canon iR-ADV C3530i</strong>',
                    '<strong>Konica C258</strong>',
                    '<strong>HP E87640</strong>',
                ]),
            ],
        ],
    ],
];

// ── Rendu ────────────────────────────────────────────────────────────────────
$apply = function (string $text, array $balises): string {
    // Balises non fournies → vides (même comportement que sendMail)
    $text = str_replace(array_keys($balises), array_values($balises), $text);
    return (string)preg_replace('/##printgestion\.[a-z_]+##/', '', $text);
};

$out = '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
    . '<title>Print Gestion — Aperçu des gabarits mail</title>'
    . '<style>'
    . 'body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#e9ebee;margin:0;padding:32px;}'
    . 'h1{font-size:22px;color:#1f2937;} h2{font-size:17px;color:#1f2937;margin:40px 0 4px;border-bottom:2px solid #cbd5e1;padding-bottom:6px;}'
    . '.variant{margin:18px 0 30px;} .vtitle{font-size:13px;font-weight:600;color:#475569;margin:0 0 6px;}'
    . '.subject{font-size:13px;background:#fff;border:1px solid #d6dae0;border-radius:6px;padding:8px 12px;margin:0 0 10px;color:#111;}'
    . '.subject b{color:#64748b;font-weight:600;}'
    . '.attach{font-size:12px;color:#155e75;background:#ecfeff;border:1px solid #a5f3fc;border-radius:6px;display:inline-block;padding:4px 10px;margin:0 0 10px;}'
    . '.frame{border:1px dashed #b6bdc7;border-radius:8px;overflow:hidden;}'
    . '.note{font-size:12px;color:#6b7280;margin-bottom:24px;}'
    . '</style></head><body>'
    . '<h1>Print Gestion — Aperçu des gabarits mail</h1>'
    . '<p class="note">Généré par <code>tools/generate_apercu.php</code> à partir des définitions réelles de <code>hook.php</code> ('
    . date('d/m/Y H:i') . '). Les listes des corps de mail sont plafonnées à 20 lignes (« … et N autres ») ; '
    . 'le détail complet part toujours dans le fichier Excel joint quand indiqué.</p>';

foreach ($templates as $field => $tpl) {
    $out .= '<h2>' . htmlspecialchars($tpl['name'], ENT_QUOTES, 'UTF-8')
        . ' <span style="font-weight:400;color:#64748b;font-size:13px;">(' . $field . ')</span></h2>';

    foreach ($variants[$field] ?? [['title' => 'Aperçu', 'balises' => []]] as $v) {
        $subject = $apply($tpl['subject'], $v['balises']);
        $html    = $apply($tpl['html'], $v['balises']);

        $out .= '<div class="variant">'
            . '<p class="vtitle">' . $v['title'] . '</p>'
            . '<div class="subject"><b>Objet :</b> ' . $subject . '</div>';
        if (!empty($v['attachment'])) {
            $out .= '<div class="attach">Pièce jointe : ' . $v['attachment'] . '</div>';
        }
        $out .= '<div class="frame">' . $html . '</div></div>';
    }
}

$out .= '</body></html>';

$target = dirname(__DIR__) . '/docs/apercu_gabarits.html';
file_put_contents($target, $out);
echo "OK — écrit : $target\n";
