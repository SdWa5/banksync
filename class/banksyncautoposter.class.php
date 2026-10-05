<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once __DIR__.'/banksyncautopostpolicy.class.php';
require_once __DIR__.'/banksynccandidatematcher.class.php';
require_once __DIR__.'/banksyncmatchmanager.class.php';
require_once __DIR__.'/banksyncpostingservice.class.php';
require_once __DIR__.'/provider/paypaltransactionmapper.class.php';

/**
 * Applies BankSyncAutoPostPolicy to the staged transactions of one provider.
 *
 * For every open transaction it refreshes the match suggestions, gathers the facts the policy needs,
 * posts what the policy allows through BankSyncPostingService and records the decision in
 * `banksync_autopost`. That table is both the queue's reason column and the audit of every
 * automatic posting. With dry-run on, decisions are recorded but nothing is posted, so a first run
 * can be reviewed before anything reaches the books.
 */
class BankSyncAutoPoster
{
    public const DECISION_POSTED = 'posted';
    public const DECISION_WOULD_POST = 'would_post';
    public const DECISION_QUEUED = 'queued';
    public const DECISION_ERROR = 'error';

    /** Transaction states that still wait for a decision. */
    private const OPEN_STATUSES = ['new', 'matched', 'partially_matched'];

    /** @var DoliDB */
    private $db;

    /** @var int */
    private $entity;

    /** @var BankSyncAutoPostPolicy */
    private $policy;

    /** @var BankSyncCandidateMatcher */
    private $matcher;

    /** @var BankSyncMatchManager */
    private $matchManager;

    /** @var BankSyncPostingService */
    private $poster;

    public function __construct($db, int $entity, ?BankSyncAutoPostPolicy $policy = null)
    {
        $this->db = $db;
        $this->entity = $entity;
        $this->policy = $policy ?? new BankSyncAutoPostPolicy();
        $this->matcher = new BankSyncCandidateMatcher($db, $entity);
        $this->matchManager = new BankSyncMatchManager($db, $entity);
        $this->poster = new BankSyncPostingService($db, $entity);
    }

    /**
     * @param string $providerPrefix Only transactions whose provider starts with this are handled
     * @param string $accountNumber  Only transactions of this source account are handled
     *
     * @return array<string, int> Count per decision
     */
    public function run(User $user, string $providerPrefix, string $accountNumber, bool $dryRun): array
    {
        $counts = [self::DECISION_POSTED => 0, self::DECISION_WOULD_POST => 0, self::DECISION_QUEUED => 0, self::DECISION_ERROR => 0];

        foreach ($this->openTransactionIds($providerPrefix, $accountNumber) as $id) {
            $decision = $this->process($id, $user, $dryRun);
            ++$counts[$decision];
        }

        return $counts;
    }

    private function process(int $id, User $user, bool $dryRun): string
    {
        try {
            $transaction = $this->matcher->fetchTransaction($id);
            if (null === $transaction) {
                return $this->record($id, self::DECISION_ERROR, 'BankSyncAutoTransactionMissing', '', $user);
            }
            if ((int) $transaction->fk_bank_account <= 0) {
                return $this->record($id, self::DECISION_QUEUED, 'BankSyncQueueAccountUnmapped', '', $user);
            }

            if ('bank_fee' !== (string) $transaction->bank_event_type) {
                $this->matcher->refreshSuggestions($id, (int) $user->id);
            }

            $raw = json_decode((string) $transaction->raw_data, true);
            $facts = [
                'direction' => (string) $transaction->direction,
                'bank_event_type' => (string) $transaction->bank_event_type,
                'amount' => (string) $transaction->amount,
                'counterparty_name' => (string) $transaction->counterparty_name,
                'counterparty_email' => \is_array($raw) ? PayPalTransactionMapper::counterpartyEmail($raw) : '',
            ];
            $decision = $this->policy->decide($facts, $this->confirmedMatches($id), $this->referencedInvoices((string) $transaction->reference));

            if (BankSyncAutoPostPolicy::ACTION_QUEUE === $decision['action']) {
                return $this->record($id, self::DECISION_QUEUED, $decision['reason'], $decision['detail'], $user);
            }
            if ($dryRun) {
                return $this->record($id, self::DECISION_WOULD_POST, $decision['reason'], self::describe($decision), $user);
            }

            return $this->post($id, $decision, $user);
        } catch (Throwable $e) {
            return $this->record($id, self::DECISION_ERROR, 'BankSyncAutoException', $e->getMessage(), $user);
        }
    }

    /**
     * @param array{action: string, reason: string, allocations: array<int, string>, detail: string} $decision
     */
    private function post(int $id, array $decision, User $user): string
    {
        if (BankSyncAutoPostPolicy::ACTION_POST_ALLOCATION === $decision['action']) {
            $this->matchManager->clearSuggested($id);
            foreach ($decision['allocations'] as $invoiceId => $amount) {
                $this->matchManager->upsert($id, BankSyncMatchManager::TARGET_SUPPLIER_INVOICE, $invoiceId, $amount, 100, 'autopost_reference', 'confirmed', (int) $user->id);
            }
        }

        $transaction = $this->matcher->fetchTransaction($id);
        $preview = $this->poster->buildPreview($transaction);
        if (empty($preview['postable'])) {
            return $this->record($id, self::DECISION_QUEUED, 'BankSyncQueuePostingBlocked', implode(', ', (array) $preview['errors']), $user);
        }

        $result = $this->poster->post($transaction, $user);

        return $this->record($id, self::DECISION_POSTED, $decision['reason'], sprintf('%s #%d, bank line %d', $result['native_object_type'], $result['native_object_id'], $result['bank_line_id']), $user);
    }

    /**
     * @return int[]
     */
    private function openTransactionIds(string $providerPrefix, string $accountNumber): array
    {
        $statuses = implode(',', array_map(function (string $status): string {
            return "'".$this->db->escape($status)."'";
        }, self::OPEN_STATUSES));

        $sql = 'SELECT rowid FROM '.$this->db->prefix().'banksync_transaction';
        $sql .= ' WHERE entity = '.$this->entity.' AND status IN ('.$statuses.')';
        $sql .= " AND provider LIKE '".$this->db->escape($providerPrefix)."%'";
        $sql .= " AND account_number = '".$this->db->escape($accountNumber)."'";
        $sql .= ' ORDER BY booking_date ASC, rowid ASC';

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RuntimeException($this->db->lasterror());
        }
        $ids = [];
        while ($obj = $this->db->fetch_object($resql)) {
            $ids[] = (int) $obj->rowid;
        }
        $this->db->free($resql);

        return $ids;
    }

    /**
     * @return array<int, array{target_type: string, allocated_amount: string}>
     */
    private function confirmedMatches(int $id): array
    {
        $matches = [];
        foreach ($this->matchManager->getForTransaction($id) as $match) {
            if ('confirmed' === (string) $match->status) {
                $matches[] = ['target_type' => (string) $match->target_type, 'allocated_amount' => (string) $match->allocated_amount];
            }
        }

        return $matches;
    }

    /**
     * Looks up every supplier invoice the reference names, keyed by reference, null where none exists.
     *
     * @return array<string, array<string, mixed>|null>
     */
    private function referencedInvoices(string $reference): array
    {
        $invoices = [];
        foreach ($this->policy->extractInvoiceRefs($reference) as $ref) {
            $invoice = new FactureFournisseur($this->db);
            if ($invoice->fetch(0, $ref) <= 0) {
                $invoices[$ref] = null;
                continue;
            }
            $thirdparty = new Societe($this->db);
            $thirdparty->fetch((int) $invoice->socid);

            $invoices[$ref] = [
                'id' => (int) $invoice->id,
                'ref' => (string) $invoice->ref,
                'open' => FactureFournisseur::STATUS_VALIDATED === (int) $invoice->status && empty($invoice->paye),
                'remaining' => (string) $invoice->getRemainToPay(),
                'thirdparty_id' => (int) $invoice->socid,
                'thirdparty_name' => (string) $thirdparty->name,
                'thirdparty_email' => (string) $thirdparty->email,
            ];
        }

        return $invoices;
    }

    /**
     * Upserts the latest decision. A transaction that stays queued keeps its notified flag, so the
     * notifier reports it as new only once.
     */
    private function record(int $id, string $decision, string $reason, string $detail, User $user): string
    {
        $now = "'".$this->db->idate(dol_now())."'";
        $sql = 'INSERT INTO '.$this->db->prefix().'banksync_autopost (entity, fk_transaction, decision, reason, detail, notified, date_creation, date_decision, fk_user)';
        $sql .= ' VALUES ('.$this->entity.', '.$id.", '".$this->db->escape($decision)."', '".$this->db->escape($reason)."', '".$this->db->escape($detail)."', 0, ".$now.', '.$now.', '.((int) $user->id).')';
        // MySQL applies these left to right, so notified must read the old decision before it changes.
        $sql .= " ON DUPLICATE KEY UPDATE notified = IF(decision = 'queued' AND VALUES(decision) = 'queued', notified, 0)";
        $sql .= ', decision = VALUES(decision), reason = VALUES(reason), detail = VALUES(detail), date_decision = VALUES(date_decision), fk_user = VALUES(fk_user)';

        if (!$this->db->query($sql)) {
            throw new RuntimeException($this->db->lasterror());
        }

        return $decision;
    }

    /**
     * @param array{action: string, reason: string, allocations: array<int, string>, detail: string} $decision
     */
    private static function describe(array $decision): string
    {
        if ([] === $decision['allocations']) {
            return $decision['action'];
        }
        $parts = [];
        foreach ($decision['allocations'] as $invoiceId => $amount) {
            $parts[] = 'supplier invoice #'.$invoiceId.' '.$amount;
        }

        return $decision['action'].': '.implode(', ', $parts);
    }
}
