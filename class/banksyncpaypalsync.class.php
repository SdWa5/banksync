<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

require_once __DIR__.'/provider/paypalapiprovider.class.php';

/**
 * Scheduled job that fetches the PayPal account, stages new transactions, applies the auto-post
 * policy and mails the queue's recipients.
 *
 * Settings, all Dolibarr constants:
 * - BANKSYNC_PAYPAL_CREDENTIALS_FILE, the JSON file with client_id and client_secret
 * - BANKSYNC_PAYPAL_ACCOUNT_NUMBER, the source account key, for example the account's e-mail
 * - BANKSYNC_PAYPAL_CUTOVER_DATE (Y-m-d), nothing before this day is ever fetched
 * - BANKSYNC_PAYPAL_LOOKBACK_DAYS, how far back each run looks, 14 by default
 * - BANKSYNC_AUTOPOST_ENABLED, posts only when set, otherwise decisions are recorded as would_post
 * - BANKSYNC_NOTIFY_EMAIL, comma-separated recipients of queue mails
 */
class BankSyncPayPalSync
{
    public const DEFAULT_CREDENTIALS_FILE = '/run/secrets/paypal.json';
    public const DEFAULT_LOOKBACK_DAYS = 14;
    public const TIMEZONE = 'Europe/Vienna';

    /** @var DoliDB */
    public $db;

    /** @var string Read by Dolibarr's cron runner */
    public $output = '';

    /** @var string Read by Dolibarr's cron runner */
    public $error = '';

    /** @var string[] Read by Dolibarr's cron runner */
    public $errors = [];

    /** @var callable|null Test seam for the PayPal HTTP transport */
    private $http;

    public function __construct($db, ?callable $http = null)
    {
        $this->db = $db;
        $this->http = $http;
    }

    /**
     * Entry point for Dolibarr's scheduled jobs.
     *
     * @return int 0 on success, as the cron runner expects
     */
    public function runScheduled(): int
    {
        global $conf, $user;

        try {
            $this->output = $this->sync($user, (int) $conf->entity, new DateTimeImmutable('now'));

            return 0;
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            $this->errors = [$e->getMessage()];
            dol_syslog('BankSyncPayPalSync: '.$e->getMessage(), LOG_ERR);

            return 1;
        }
    }

    public function sync(User $user, int $entity, DateTimeImmutable $now): string
    {
        $accountNumber = trim(getDolGlobalString('BANKSYNC_PAYPAL_ACCOUNT_NUMBER'));
        $cutover = trim(getDolGlobalString('BANKSYNC_PAYPAL_CUTOVER_DATE'));
        if ('' === $accountNumber || '' === $cutover) {
            throw new RuntimeException('BANKSYNC_PAYPAL_ACCOUNT_NUMBER and BANKSYNC_PAYPAL_CUTOVER_DATE must be set.');
        }

        [$from, $to] = self::range($cutover, getDolGlobalInt('BANKSYNC_PAYPAL_LOOKBACK_DAYS', self::DEFAULT_LOOKBACK_DAYS), $now);
        if (null === $from) {
            return 'Cutover '.$cutover.' lies in the future, nothing fetched.';
        }

        // Loaded here because they need a Dolibarr bootstrap, which range() and statementHash() do not.
        require_once __DIR__.'/banksyncimporter.class.php';
        require_once __DIR__.'/banksyncautoposter.class.php';
        require_once __DIR__.'/banksyncqueuenotifier.class.php';
        require_once __DIR__.'/banksyncschema.class.php';

        BankSyncSchema::ensure($this->db);

        $client = PayPalApiClient::fromCredentialsFile(getDolGlobalString('BANKSYNC_PAYPAL_CREDENTIALS_FILE', self::DEFAULT_CREDENTIALS_FILE), $this->http);
        $statement = (new PayPalApiProvider())->fetch([
            'client' => $client,
            'from' => $from,
            'to' => $to,
            'account_number' => $accountNumber,
            'timezone' => self::TIMEZONE,
        ]);

        $importer = new BankSyncImporter($this->db, (int) $user->id, $entity);
        $import = $importer->importStatement($statement, 'paypal-api '.$from->format('Y-m-d').'..'.$to->format('Y-m-d'), self::statementHash($statement));

        $dryRun = !getDolGlobalInt('BANKSYNC_AUTOPOST_ENABLED');
        $counts = (new BankSyncAutoPoster($this->db, $entity))->run($user, 'paypal', $accountNumber, $dryRun);

        // A failed mail must not fail the run, because the sync itself already happened. The items
        // stay unnotified, so the next run tries again.
        try {
            $notified = (new BankSyncQueueNotifier($this->db, $entity))->notify(
                getDolGlobalString('BANKSYNC_NOTIFY_EMAIL'),
                dol_buildpath('/banksync/transactions.php', 2)
            );
        } catch (Throwable $e) {
            $notified = 'failed ('.$e->getMessage().')';
            dol_syslog('BankSyncPayPalSync: '.$e->getMessage(), LOG_WARNING);
        }

        return sprintf(
            'PayPal %s..%s: %d fetched, %d staged, %d already known, %d pending or denied, %d in another currency, %d outside the window. Auto-post%s: %d posted, %d would post, %d queued, %d errors. Mail: %s.',
            $from->format('Y-m-d H:i'),
            $to->format('Y-m-d H:i'),
            \count($statement->transactions),
            $import['imported_count'],
            $import['skipped_count'],
            $statement->metadata['skipped_pending_or_denied'] ?? 0,
            $statement->metadata['skipped_other_currency'] ?? 0,
            $statement->metadata['skipped_outside_range'] ?? 0,
            $dryRun ? ' (dry run)' : '',
            $counts[BankSyncAutoPoster::DECISION_POSTED],
            $counts[BankSyncAutoPoster::DECISION_WOULD_POST],
            $counts[BankSyncAutoPoster::DECISION_QUEUED],
            $counts[BankSyncAutoPoster::DECISION_ERROR],
            $notified ?? 'none'
        );
    }

    /**
     * The window a run fetches. It never reaches before the cutover's local midnight.
     *
     * @return array{0: DateTimeImmutable|null, 1: DateTimeImmutable}
     */
    public static function range(string $cutover, int $lookbackDays, DateTimeImmutable $now): array
    {
        $local = new DateTimeZone(self::TIMEZONE);
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $cutover, $local);
        if (false === $start) {
            throw new InvalidArgumentException(sprintf('Cutover date "%s" is not Y-m-d.', $cutover));
        }

        $lookback = $now->modify('-'.max(1, $lookbackDays).' days');
        $from = $lookback > $start ? $lookback : $start;

        return [$from < $now ? $from : null, $now];
    }

    /**
     * Identifies a fetch by the entries it returned, so an identical repeat is recognised as a
     * duplicate before any entry gets compared.
     */
    public static function statementHash(BankStatement $statement): string
    {
        $ids = array_map(static function (BankTransaction $t): string {
            return $t->externalEntryId;
        }, $statement->transactions);
        sort($ids);

        return hash('sha256', $statement->provider."\n".$statement->accountNumber."\n".implode("\n", $ids));
    }
}
