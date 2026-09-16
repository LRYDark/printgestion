<?php
/**
 * PluginPrintgestionPurchaseorder — transmission d'une commande aux Achats (étape 1.6.7).
 *
 * Une commande (commande directe ou export de demandes validées) est d'abord ENREGISTRÉE : expéditions,
 * fichier Gesconso archivé et ligne de transmission « en attente », dans une seule transaction. Le mail
 * aux Achats part ensuite. Un serveur SMTP peut signaler une erreur après avoir réellement remis le
 * message : la commande enregistrée n'est donc jamais annulée ni renvoyée automatiquement. Elle reste
 * « non transmise », verrou anti-double-envoi posé, affichée sur les écrans Expéditions et Demandes avec
 * « Renvoyer aux Achats », qui renvoie le MÊME fichier archivé et les mêmes lignes (jamais régénérés).
 * Au-delà de STALE_HOURS, la tâche horaire émet une notification native (une fois par commande).
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionPurchaseorder extends CommonDBTM {

    static $rightname = 'plugin_printgestion_validation';

    const STATUS_PENDING = 'pending';
    const STATUS_SENDING = 'sending';
    const STATUS_SENT    = 'sent';
    const STATUS_FAILED  = 'failed';

    const SOURCE_DIRECT = 'direct';
    const SOURCE_EXPORT = 'export';

    /** Commande non transmise depuis plus de STALE_HOURS heures : notification. */
    const STALE_HOURS = 4;

    /** Envoi interrompu (processus arrêté pendant l'envoi) : renvoi possible après ce délai. */
    const SENDING_TIMEOUT_MINUTES = 15;

    static function getTypeName($nb = 0) {
        return _n('Transmission aux Achats', 'Transmissions aux Achats', $nb, 'printgestion');
    }

    public static function getTable($classname = null) {
        if ($classname === null || $classname === static::class) {
            return 'glpi_plugin_printgestion_purchaseorders';
        }
        return parent::getTable($classname);
    }

    /**
     * Enregistre la transmission d'une commande, statut « en attente ». À appeler DANS la transaction qui crée
     * les expéditions et archive le fichier : une commande n'existe jamais sans sa ligne de transmission.
     *
     * @param array $rows lignes du mail (Expedition::buildPurchaseRowData), gardées pour un renvoi identique
     */
    public static function record(string $group_id, string $source, int $documents_id, array $rows): int {
        global $DB;

        $DB->insert(self::getTable(), [
            'group_id'      => $group_id,
            'source'        => $source,
            'documents_id'  => $documents_id,
            'users_id'      => (int) Session::getLoginUserID(),
            'nb_lines'      => count($rows),
            'mail_rows'     => json_encode(array_values($rows), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'status'        => self::STATUS_PENDING,
            'attempts'      => 0,
            'date_creation' => self::now(),
        ]);
        $id = (int) $DB->insertId();
        if ($id <= 0) {
            throw new RuntimeException('Transmission aux Achats non enregistrée.');
        }
        return $id;
    }

    /**
     * Envoie (ou renvoie) le mail aux Achats d'une commande enregistrée : même fichier archivé, mêmes lignes.
     * Réservation atomique de l'envoi : deux clics ou deux écrans simultanés n'envoient jamais deux fois ; une
     * commande déjà transmise n'est jamais renvoyée.
     *
     * @return array ['ok' => bool, 'error' => string, 'already' => bool]
     */
    public static function send(int $id): array {
        global $DB;

        $now     = self::now();
        $timeout = date('Y-m-d H:i:s', time() - self::SENDING_TIMEOUT_MINUTES * MINUTE_TIMESTAMP);
        $DB->update(self::getTable(), [
            'status'            => self::STATUS_SENDING,
            'attempts'          => new QueryExpression('`attempts` + 1'),
            'date_last_attempt' => $now,
        ], [
            'id' => $id,
            'OR' => [
                ['status' => [self::STATUS_PENDING, self::STATUS_FAILED]],
                ['status' => self::STATUS_SENDING, 'date_last_attempt' => ['<', $timeout]],
            ],
        ]);
        if ($DB->affectedRows() !== 1) {
            return ['ok' => false, 'already' => true, 'error' => __('Commande déjà transmise aux Achats ou envoi en cours : rien n\'a été renvoyé.', 'printgestion')];
        }

        $order = new self();
        $order->getFromDB($id);
        $error = '';
        $rows     = json_decode((string) $order->fields['mail_rows'], true);
        $document = new Document();
        $path     = self::getArchivedFilePath((int) $order->fields['documents_id']);
        $name     = $path !== null && $document->getFromDB((int) $order->fields['documents_id']) ? (string) $document->fields['filename'] : null;
        if (!is_array($rows) || empty($rows)) {
            $error = __('lignes de la commande illisibles', 'printgestion');
        } elseif ($path === null) {
            $error = __('fichier Gesconso archivé introuvable ou illisible', 'printgestion');
        } else {
            try {
                $mail  = PluginPrintgestionExpedition::sendPurchaseOrderMail($rows, $path, (int) $order->fields['users_id'] ?: null, $name);
                $error = $mail['ok'] ? '' : (string) $mail['error'];
            } catch (Throwable $e) {
                PluginPrintgestionLogger::error('commande', sprintf('Transmission #%d : erreur pendant l\'envoi du mail aux Achats.', $id), $e);
                $error = __('erreur technique pendant l\'envoi (détail dans le journal printgestion)', 'printgestion');
            }
        }

        if ($error === '') {
            $DB->update(self::getTable(), ['status' => self::STATUS_SENT, 'date_sent' => self::now(), 'last_error' => null], ['id' => $id]);
            if ((string) $order->fields['source'] === self::SOURCE_EXPORT) {
                foreach (self::getDemandesIds((string) $order->fields['group_id']) as $demandes_id) {
                    PluginPrintgestionDemande::raiseEventFor('demande_exported', $demandes_id);
                }
            }
            return ['ok' => true, 'already' => false, 'error' => ''];
        }

        $DB->update(self::getTable(), ['status' => self::STATUS_FAILED, 'last_error' => mb_substr($error, 0, 1000)], ['id' => $id]);
        PluginPrintgestionLogger::error('commande', sprintf(
            'Commande enregistrée (transmission #%d, %d ligne(s)) mais NON transmise aux Achats : %s. Renvoi depuis l\'écran Expéditions ou Demandes d\'envoi.',
            $id,
            (int) $order->fields['nb_lines'],
            $error
        ));
        return ['ok' => false, 'already' => false, 'error' => $error];
    }

    /** Message affiché quand une commande est enregistrée mais pas transmise. */
    public static function getNotSentMessage(string $error): string {
        return sprintf(
            __('Commande ENREGISTRÉE mais NON TRANSMISE aux Achats (%s). Les cartouches restent verrouillées : ne recommandez pas, utilisez « Renvoyer aux Achats » dans « Commandes non transmises aux Achats » (écrans Expéditions et Demandes d\'envoi).', 'printgestion'),
            $error
        );
    }

    /** Chemin du fichier archivé d'un document, s'il est lisible. */
    private static function getArchivedFilePath(int $documents_id): ?string {
        $document = new Document();
        if ($documents_id <= 0 || !$document->getFromDB($documents_id) || (string) $document->fields['filepath'] === '') {
            return null;
        }
        $path = GLPI_DOC_DIR . '/' . $document->fields['filepath'];
        return is_readable($path) && filesize($path) > 0 ? $path : null;
    }

    /** Demandes dont une ligne a été exportée dans cette commande. */
    private static function getDemandesIds(string $group_id): array {
        global $DB;

        $ids = [];
        foreach ($DB->request([
            'SELECT'     => ['l.plugin_printgestion_demandes_id'],
            'DISTINCT'   => true,
            'FROM'       => PluginPrintgestionDemandeline::getTable() . ' AS l',
            'INNER JOIN' => [PluginPrintgestionExpedition::getTable() . ' AS e' => ['ON' => ['l' => 'expeditions_id', 'e' => 'id']]],
            'WHERE'      => ['e.group_id' => $group_id],
        ]) as $row) {
            $ids[] = (int) $row['plugin_printgestion_demandes_id'];
        }
        return $ids;
    }

    /**
     * Commandes non transmises visibles de l'utilisateur : toutes leurs expéditions dans son périmètre (entité
     * de chaque expédition, figée à sa création).
     */
    public static function getNotSentForSession(): array {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['NOT' => ['status' => self::STATUS_SENT]],
            'ORDER' => ['date_creation ASC'],
        ]) as $order) {
            $expeditions = iterator_to_array($DB->request([
                'SELECT' => ['entities_id', 'is_recursive'],
                'FROM'   => PluginPrintgestionExpedition::getTable(),
                'WHERE'  => ['group_id' => (string) $order['group_id']],
            ]), false);
            if (!empty($expeditions) && count(array_filter($expeditions, [PluginPrintgestionSecurity::class, 'canAccessRow'])) === count($expeditions)) {
                $out[] = $order;
            }
        }
        return $out;
    }

    /** Carte « Commandes non transmises aux Achats » : rien s'il n'y en a pas. */
    public static function showNotSentCard(): void {
        if (!Session::haveRight(self::$rightname, READ)) {
            return;
        }
        $orders = self::getNotSentForSession();
        if (empty($orders)) {
            return;
        }
        $esc      = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $can_send = Session::haveRight(self::$rightname, UPDATE);
        $sources  = [self::SOURCE_DIRECT => __('Commande directe', 'printgestion'), self::SOURCE_EXPORT => __('Export de demandes', 'printgestion')];

        echo "<div class='card border-danger mb-3'><div class='card-header bg-danger-lt'><h3 class='card-title mb-0'><i class='ti ti-mail-off me-1'></i>"
            . $esc(sprintf(__('Commandes non transmises aux Achats (%d)', 'printgestion'), count($orders))) . "</h3></div><div class='card-body'>";
        echo "<p class='mb-2'>" . $esc(__('Enregistrées, cartouches verrouillées, mais le mail aux Achats n\'est pas parti. Ne recommandez pas : renvoyez le fichier d\'origine.', 'printgestion')) . "</p>";
        echo "<div class='table-responsive'><table class='table table-sm table-vcenter mb-0'><thead><tr>"
            . "<th>" . $esc(__('Enregistrée le', 'printgestion')) . "</th><th>" . $esc(__('Origine', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Par', 'printgestion')) . "</th><th class='text-end'>" . $esc(__('Lignes', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Dernière erreur', 'printgestion')) . "</th><th>" . $esc(__('Fichier', 'printgestion')) . "</th><th></th></tr></thead><tbody>";
        foreach ($orders as $order) {
            $document = new Document();
            $file     = $document->getFromDB((int) $order['documents_id'])
                ? "<a href='" . $esc($document->getLinkURL()) . "'>" . $esc($document->fields['name']) . "</a>"
                : '—';
            echo "<tr><td>" . $esc(Html::convDateTime((string) $order['date_creation'])) . "</td>"
                . "<td>" . $esc($sources[(string) $order['source']] ?? (string) $order['source']) . "</td>"
                . "<td>" . $esc(getUserName((int) $order['users_id'])) . "</td>"
                . "<td class='text-end'>" . (int) $order['nb_lines'] . "</td>"
                . "<td class='small'>" . $esc((string) ($order['last_error'] ?? '') !== '' ? $order['last_error'] : __('envoi non terminé', 'printgestion'))
                . ' ' . $esc(sprintf(__('(%d tentative(s))', 'printgestion'), (int) $order['attempts'])) . "</td>"
                . "<td>" . $file . "</td><td class='text-end'>";
            if ($can_send) {
                echo "<form method='post' action='" . $esc(PLUGIN_PRINTGESTION_WEBDIR . '/front/purchaseorder.form.php') . "' class='d-inline'>"
                    . Html::hidden('id', ['value' => (int) $order['id']])
                    . "<button type='submit' name='resend' value='1' class='btn btn-sm btn-danger' data-pg-submit-once='1'><i class='ti ti-send me-1'></i>"
                    . $esc(__('Renvoyer aux Achats', 'printgestion')) . "</button>"
                    . Html::closeForm(false);
            }
            echo "</td></tr>";
        }
        echo "</tbody></table></div></div></div>";
    }

    /**
     * Commandes non transmises depuis plus de STALE_HOURS heures, pas encore notifiées : notification native
     * (événement purchaseorder_not_sent), une fois par commande.
     *
     * @return array ['stale' => int, 'notified' => int, 'errors' => int]
     */
    public static function notifyStale(): array {
        global $DB;

        $stats  = ['stale' => 0, 'notified' => 0, 'errors' => 0];
        $cutoff = date('Y-m-d H:i:s', time() - self::STALE_HOURS * HOUR_TIMESTAMP);
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['NOT' => ['status' => self::STATUS_SENT], 'date_creation' => ['<', $cutoff], 'date_notified' => null],
        ]) as $row) {
            $stats['stale']++;
            $order = new self();
            if (!$order->getFromDB((int) $row['id'])) {
                continue;
            }
            try {
                $raised = NotificationEvent::raiseEvent('purchaseorder_not_sent', $order);
            } catch (Throwable $e) {
                $raised = false;
                PluginPrintgestionLogger::error('commande', sprintf('Transmission #%d : notification « commande non transmise » non émise.', $order->getID()), $e);
            }
            if ($raised) {
                $DB->update(self::getTable(), ['date_notified' => self::now()], ['id' => (int) $order->getID()]);
                $stats['notified']++;
            } else {
                $stats['errors']++;
                PluginPrintgestionLogger::error('commande', sprintf(
                    'Commande non transmise aux Achats depuis plus de %d h (transmission #%d) : aucune notification émise (notification « Print Gestion - Commande non transmise aux Achats » inactive ou sans destinataire).',
                    self::STALE_HOURS,
                    $order->getID()
                ));
            }
        }
        return $stats;
    }

    private static function now(): string {
        return $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    }

    static function install(Migration $migration) {
        return true;
    }

    static function uninstall(Migration $migration) {
        return true;
    }
}
