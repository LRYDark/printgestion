<?php
/**
 * PluginPrintgestionDelivery — le seul endroit où une expédition passe « livrée ».
 *
 * Quatre sources peuvent constater une livraison, et elles ne se valent pas. La hiérarchie, du plus fort au plus
 * faible :
 *
 *   1. la pose détectée par relevé → « posée », et elle seule clôt l'envoi (hors de cette classe : Collect) ;
 *   2. le BL signé dans le plugin Gestion → preuve écrite, signée par le client, immédiate ;
 *   3. l'événement de livraison du transporteur (GLS) → constat du terrain ;
 *   4. le statut MBE « DELIVERED » → résumé de l'intermédiaire, le plus en retard des trois.
 *
 * Deux règles tiennent tout :
 *
 *   - **« livrée » n'est pas la fin.** Une expédition livrée reste un envoi en cours (ACTIVE_STATUSES la contient) :
 *     le verrou anti-doublon n'est pas levé, les rappels d'installation continuent. C'est ce qui rend acceptable
 *     qu'un suivi transporteur écrive ce statut — il constate une remise, pas une pose.
 *   - **jamais de rétrogradation.** Une livraison constatée ne se reprend pas : une expédition livrée, posée ou
 *     annulée n'est plus touchée ici, et deux sources qui se contredisent laissent gagner celle qui a vu la
 *     livraison. Un transporteur qui repasse « en transit » après avoir annoncé une remise ne défait rien.
 *
 * Chaque passage est écrit dans le journal du plugin avec sa source : six mois plus tard, on sait pourquoi cette
 * expédition est livrée. Rien d'autre n'écrit `statut = 'delivered'` : ni Glstracking, ni Mbetracking, ni Tracking.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionDelivery {

    const TABLE = 'glpi_plugin_printgestion_expeditions';

    /** Sources de constat, du plus fort au plus faible (l'ordre du tableau EST la hiérarchie). */
    const SOURCE_BL     = 'bl';
    const SOURCE_GLS    = 'gls';
    const SOURCE_MBE    = 'mbe';
    const SOURCES       = [self::SOURCE_BL, self::SOURCE_GLS, self::SOURCE_MBE];

    /**
     * Statuts depuis lesquels une livraison peut être constatée : un envoi parti. « pending » est volontairement
     * exclu — un colis livré alors que personne n'a marqué l'envoi comme parti est une incohérence de saisie, pas
     * une livraison à enregistrer en silence ; elle est journalisée pour être regardée.
     */
    const FROM_STATUSES = ['shipped', 'transit'];

    /** @return string source lisible, pour les journaux et les messages. */
    public static function describeSource(string $source): string {
        return match ($source) {
            self::SOURCE_BL  => __('BL signé (plugin Gestion)', 'printgestion'),
            self::SOURCE_GLS => __('événement de livraison GLS', 'printgestion'),
            self::SOURCE_MBE => __('statut MBE', 'printgestion'),
            default          => $source,
        };
    }

    /**
     * Constate la livraison d'une expédition. Sans effet — et c'est voulu — si elle est déjà livrée, posée,
     * annulée, ou pas encore partie.
     *
     * @param int         $expeditions_id l'expédition
     * @param string      $source         SOURCE_BL, SOURCE_GLS ou SOURCE_MBE
     * @param string|null $delivered_at   date de livraison donnée par la source ('Y-m-d H:i:s'), maintenant sinon
     * @param string      $detail         ce que la source a dit (statut brut, signataire…), pour le journal
     * @return bool vrai si l'expédition vient de passer « livrée »
     */
    public static function markDelivered(int $expeditions_id, string $source, ?string $delivered_at = null, string $detail = ''): bool {
        global $DB;

        if ($expeditions_id <= 0 || !in_array($source, self::SOURCES, true)) {
            return false;
        }
        $row = $DB->request([
            'SELECT' => ['id', 'statut'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['id' => $expeditions_id],
        ])->current();
        if (!is_array($row)) {
            return false;
        }
        $statut = (string) $row['statut'];
        if ($statut === PluginPrintgestionExpedition::STATUS_DELIVERED) {
            return false; // déjà constatée : la première source qui a vu la livraison garde la main
        }
        if (!in_array($statut, self::FROM_STATUSES, true)) {
            // Posée ou annulée : rien à reprendre. En attente : la saisie et le terrain se contredisent, et ce n'est
            // pas à une tâche automatique de trancher — elle le dit et n'y touche pas.
            if ($statut === PluginPrintgestionExpedition::STATUS_PENDING) {
                PluginPrintgestionLogger::warning('livraison', sprintf(
                    'Expédition #%d donnée livrée par %s alors qu\'elle n\'est pas marquée partie : statut laissé « en attente », à vérifier.%s',
                    $expeditions_id,
                    self::describeSource($source),
                    $detail !== '' ? ' (' . $detail . ')' : ''
                ));
            }
            return false;
        }

        $date = self::normalizeDate($delivered_at);
        if (!$DB->update(self::TABLE, [
            'statut'         => PluginPrintgestionExpedition::STATUS_DELIVERED,
            'date_delivered' => $date,
        ], ['id' => $expeditions_id])) {
            PluginPrintgestionLogger::error('livraison', sprintf('Expédition #%d : passage en « livrée » refusé par la base.', $expeditions_id));
            return false;
        }
        PluginPrintgestionLogger::warning('livraison', sprintf(
            'Expédition #%d livrée le %s, constaté par %s.%s',
            $expeditions_id,
            $date,
            self::describeSource($source),
            $detail !== '' ? ' ' . $detail : ''
        ));
        return true;
    }

    /**
     * Plusieurs constats d'un coup, puis la propagation aux demandes d'envoi une seule fois : chaque appelant
     * (BL signé, suivi GLS, suivi MBE) passe par ici et n'a pas à connaître la suite.
     *
     * @param array $constats [['id' => int, 'source' => string, 'date' => ?string, 'detail' => string], …]
     * @return int nombre d'expéditions passées « livrée »
     */
    public static function markDeliveredBatch(array $constats): int {
        $done      = 0;
        $delivered = [];
        foreach ($constats as $constat) {
            if (self::markDelivered(
                (int) ($constat['id'] ?? 0),
                (string) ($constat['source'] ?? ''),
                $constat['date'] ?? null,
                (string) ($constat['detail'] ?? '')
            )) {
                $done++;
                $delivered[] = (int) $constat['id'];
            }
        }
        if ($done > 0) {
            // Avancement des lignes de demande d'envoi : exporté → expédié → livré. Seulement les demandes des
            // expéditions qui viennent de passer « livrée » : ce chemin est aussi pris à l'affichage (tableau de
            // bord, rapprochement des BL), et le passage complet sur toutes les demandes reste à la tâche automatique.
            PluginPrintgestionDemande::syncFromExpeditions(null, $delivered);
            // L'écran des alertes lit une table matérialisée qui garde le motif de verrou de chaque cartouche. Le
            // statut de l'expédition vient de changer : ce qu'elle affiche ne reflète plus l'état, et elle doit le
            // dire (bandeau « recalculer ») plutôt que montrer un verrou d'avant la livraison.
            PluginPrintgestionAlert::invalidateCache();
        }
        return $done;
    }

    /**
     * Délai au-delà duquel une expédition partie et non livrée est en retard, en **jours ouvrés** et par transporteur.
     * Un colis n'avance pas le dimanche : compter en jours calendaires ferait crier au retard chaque lundi matin.
     *
     * Aucun réglage : ces délais sont ceux des transporteurs, pas une préférence maison. Et pas de distinction
     * Express — Print Gestion ne connaît que le transporteur (`transport_carrier`), jamais le service souscrit, donc
     * un seuil plus court pour Express serait une supposition.
     */
    const DELAY_DAYS = ['gls' => 3, 'ups' => 6, 'chronopost' => 4];
    const DELAY_DAYS_DEFAULT = 4;

    public static function delayThreshold(string $carrier): int {
        return self::DELAY_DAYS[strtolower(trim($carrier))] ?? self::DELAY_DAYS_DEFAULT;
    }

    /** Le plus court des seuils : sert de pré-filtre en base avant le décompte exact en jours ouvrés. */
    public static function shortestDelay(): int {
        return min(array_merge(array_values(self::DELAY_DAYS), [self::DELAY_DAYS_DEFAULT]));
    }

    /**
     * Jours ouvrés écoulés depuis une date (samedi et dimanche exclus ; les jours fériés ne le sont pas — les
     * connaître demanderait un calendrier à tenir, et une journée d'écart ne change pas la lecture d'un retard).
     */
    public static function businessDaysSince(string $from, ?string $to = null): int {
        $start = strtotime($from);
        $end   = strtotime($to ?? date('Y-m-d H:i:s'));
        if ($start === false || $end === false || $end <= $start) {
            return 0;
        }
        $days = 0;
        for ($day = strtotime('+1 day', strtotime(date('Y-m-d', $start))); $day <= $end; $day = strtotime('+1 day', $day)) {
            if ((int) date('N', $day) <= 5) {
                $days++;
            }
        }
        return $days;
    }

    /** Date de livraison utilisable : celle de la source si elle est lisible et pas dans le futur, maintenant sinon. */
    private static function normalizeDate(?string $given): string {
        $now = date('Y-m-d H:i:s');
        $raw = trim((string) $given);
        if ($raw === '') {
            return $now;
        }
        $stamp = strtotime($raw);
        if ($stamp === false || $stamp <= 0 || $stamp > time() + HOUR_TIMESTAMP) {
            return $now;
        }
        return date('Y-m-d H:i:s', $stamp);
    }
}
