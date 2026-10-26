<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

require_once __DIR__.'/banksyncqueue.class.php';

/**
 * Mails the queue's recipients when transactions need a person, without nagging.
 *
 * A mail goes out at once when new items entered the queue. Items that stay open trigger a
 * reminder whose interval doubles after every reminder, from three days up to thirty, and a mail
 * about new items resets it. An empty queue sends nothing, so there is no all-clear mail either.
 */
class BankSyncQueueNotifier
{
    public const SEND_NEW = 'new';
    public const SEND_REMINDER = 'reminder';

    public const FIRST_INTERVAL_DAYS = 3;
    public const MAX_INTERVAL_DAYS = 30;

    /** @var DoliDB */
    private $db;

    /** @var int */
    private $entity;

    public function __construct($db, int $entity)
    {
        $this->db = $db;
        $this->entity = $entity;
    }

    /**
     * Decides whether to mail, and which reminder interval holds afterwards.
     *
     * @param int $lastSent     Unix time of the last mail, 0 when none was sent yet
     * @param int $intervalDays Current reminder interval
     *
     * @return array{send: string|null, interval: int}
     */
    public static function plan(int $newCount, int $openCount, int $lastSent, int $intervalDays, int $now): array
    {
        $intervalDays = max(self::FIRST_INTERVAL_DAYS, min(self::MAX_INTERVAL_DAYS, $intervalDays));

        if ($newCount > 0) {
            return ['send' => self::SEND_NEW, 'interval' => self::FIRST_INTERVAL_DAYS];
        }
        if (0 === $openCount) {
            return ['send' => null, 'interval' => self::FIRST_INTERVAL_DAYS];
        }
        if ($now - $lastSent >= $intervalDays * 86400) {
            return ['send' => self::SEND_REMINDER, 'interval' => min(self::MAX_INTERVAL_DAYS, $intervalDays * 2)];
        }

        return ['send' => null, 'interval' => $intervalDays];
    }

    /**
     * Sends the mail the plan asks for and remembers the state in Dolibarr constants.
     *
     * @return string|null What was sent
     */
    public function notify(string $recipients, string $queueUrl): ?string
    {
        global $conf;

        if ('' === trim($recipients)) {
            return null;
        }
        // dolibarr_set_const() lives here, and a scheduled run does not load it.
        require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

        $new = $this->count('a.notified = 0');
        $open = $this->count('1 = 1');
        $plan = self::plan($new, $open, getDolGlobalInt('BANKSYNC_NOTIFY_LAST_SENT'), getDolGlobalInt('BANKSYNC_NOTIFY_INTERVAL_DAYS', self::FIRST_INTERVAL_DAYS), dol_now());

        if (null !== $plan['send']) {
            $this->send($recipients, $plan['send'], $new, $open, $queueUrl);
            dolibarr_set_const($this->db, 'BANKSYNC_NOTIFY_LAST_SENT', (string) dol_now(), 'chaine', 0, '', $conf->entity);
            $this->markNotified();
        }
        dolibarr_set_const($this->db, 'BANKSYNC_NOTIFY_INTERVAL_DAYS', (string) $plan['interval'], 'chaine', 0, '', $conf->entity);

        return $plan['send'];
    }

    /**
     * Counts queue items as BankSyncQueue defines them. An item is new while its decision is unnotified.
     */
    private function count(string $condition): int
    {
        return BankSyncQueue::count($this->db, $this->entity, $condition);
    }

    private function markNotified(): void
    {
        $prefix = $this->db->prefix();
        $sql = 'UPDATE '.$prefix.'banksync_autopost AS a INNER JOIN '.$prefix.'banksync_transaction AS t ON t.rowid = a.fk_transaction AND t.entity = a.entity';
        $sql .= ' SET a.notified = 1 WHERE a.entity = '.$this->entity.' AND a.notified = 0 AND '.BankSyncQueue::condition($prefix);
        if (!$this->db->query($sql)) {
            throw new RuntimeException($this->db->lasterror());
        }
    }

    private function send(string $recipients, string $kind, int $new, int $open, string $queueUrl): void
    {
        global $langs;

        require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
        $langs->load('banksync@banksync');

        $subject = self::SEND_NEW === $kind ? $langs->transnoentities('BankSyncMailNewSubject', $new) : $langs->transnoentities('BankSyncMailReminderSubject', $open);
        $body = $langs->transnoentities('BankSyncMailBody', $open, $queueUrl);
        $from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');

        $mail = new CMailFile($subject, $recipients, $from, $body);
        if (!$mail->sendfile()) {
            throw new RuntimeException('Queue notification failed: '.$mail->error);
        }
    }
}
