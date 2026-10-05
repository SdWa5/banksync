<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

/**
 * Decides which staged transactions may be posted without a person looking at them.
 *
 * The policy is deliberately narrow. It posts a fee, a payment that the matcher confirmed against
 * exactly one supplier invoice, and a reimbursement whose free-text reference names supplier
 * invoices that add up to the amount. Everything else stays in the queue with a reason code, which
 * the queue shows and translates.
 *
 * This class touches neither the database nor Dolibarr. BankSyncAutoPoster gathers the facts and
 * carries out the decision.
 */
class BankSyncAutoPostPolicy
{
    public const ACTION_POST = 'post';
    public const ACTION_POST_ALLOCATION = 'post_allocation';
    public const ACTION_QUEUE = 'queue';

    public const REASON_FEE = 'BankSyncAutoFee';
    public const REASON_CONFIRMED_MATCH = 'BankSyncAutoConfirmedMatch';
    public const REASON_REIMBURSEMENT = 'BankSyncAutoReimbursement';
    public const REASON_INCOMING = 'BankSyncQueueIncoming';
    public const REASON_EVENT_TYPE = 'BankSyncQueueEventType';
    public const REASON_NO_MATCH = 'BankSyncQueueNoMatch';
    public const REASON_AMBIGUOUS_MATCH = 'BankSyncQueueAmbiguousMatch';
    public const REASON_INVOICE_UNKNOWN = 'BankSyncQueueInvoiceUnknown';
    public const REASON_INVOICE_NOT_OPEN = 'BankSyncQueueInvoiceNotOpen';
    public const REASON_MIXED_THIRDPARTIES = 'BankSyncQueueMixedThirdparties';
    public const REASON_COUNTERPARTY_MISMATCH = 'BankSyncQueueCounterpartyMismatch';
    public const REASON_SUM_MISMATCH = 'BankSyncQueueSumMismatch';

    /** Dolibarr's default numbering for supplier invoices, e.g. SI2610-0003. */
    public const DEFAULT_REFERENCE_PATTERN = '/\bSI\d{4}-\d{4,}\b/';

    /** @var string */
    private $referencePattern;

    public function __construct(string $referencePattern = self::DEFAULT_REFERENCE_PATTERN)
    {
        if (false === @preg_match($referencePattern, '')) {
            throw new InvalidArgumentException(sprintf('Invalid supplier invoice reference pattern "%s".', $referencePattern));
        }
        $this->referencePattern = $referencePattern;
    }

    /**
     * Returns the distinct supplier invoice references named in a free-text reference, in order.
     *
     * @return string[]
     */
    public function extractInvoiceRefs(string $reference): array
    {
        preg_match_all($this->referencePattern, strtoupper($reference), $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * @param array{direction: string, bank_event_type: string, amount: string|float, counterparty_name?: string, counterparty_email?: string}                            $transaction
     * @param array<int, array{target_type: string, allocated_amount: string|float}>                                                                                      $confirmedMatches   Confirmed matches the matcher left on the transaction
     * @param array<string, array{id: int, ref: string, open: bool, remaining: string|float, thirdparty_id: int, thirdparty_name: string, thirdparty_email: string}|null> $referencedInvoices Lookups for extractInvoiceRefs(), keyed by reference, null when not found
     *
     * @return array{action: string, reason: string, allocations: array<int, string>, detail: string}
     */
    public function decide(array $transaction, array $confirmedMatches, array $referencedInvoices): array
    {
        $eventType = (string) $transaction['bank_event_type'];

        if ('bank_fee' === $eventType) {
            return self::decision(self::ACTION_POST, self::REASON_FEE);
        }
        if ('debit' !== (string) $transaction['direction']) {
            return self::decision(self::ACTION_QUEUE, self::REASON_INCOMING);
        }
        if ('transfer' !== $eventType) {
            return self::decision(self::ACTION_QUEUE, self::REASON_EVENT_TYPE, [], $eventType);
        }

        $amount = abs(self::cents($transaction['amount']));

        if (\count($confirmedMatches) > 1) {
            return self::decision(self::ACTION_QUEUE, self::REASON_AMBIGUOUS_MATCH);
        }
        if (1 === \count($confirmedMatches)) {
            $match = reset($confirmedMatches);
            if ('supplier_invoice' === (string) $match['target_type'] && self::cents($match['allocated_amount']) === $amount) {
                return self::decision(self::ACTION_POST, self::REASON_CONFIRMED_MATCH);
            }

            return self::decision(self::ACTION_QUEUE, self::REASON_AMBIGUOUS_MATCH);
        }

        if ([] === $referencedInvoices) {
            return self::decision(self::ACTION_QUEUE, self::REASON_NO_MATCH);
        }

        return $this->decideReimbursement($transaction, $amount, $referencedInvoices);
    }

    /**
     * @param array<string, mixed>                         $transaction
     * @param array<int|string, array<string, mixed>|null> $invoices
     *
     * @return array{action: string, reason: string, allocations: array<int, string>, detail: string}
     */
    private function decideReimbursement(array $transaction, int $amount, array $invoices): array
    {
        $allocations = [];
        $thirdparty = null;
        $sum = 0;

        foreach ($invoices as $ref => $invoice) {
            if (null === $invoice) {
                return self::decision(self::ACTION_QUEUE, self::REASON_INVOICE_UNKNOWN, [], (string) $ref);
            }
            if (empty($invoice['open']) || self::cents($invoice['remaining']) <= 0) {
                return self::decision(self::ACTION_QUEUE, self::REASON_INVOICE_NOT_OPEN, [], (string) $invoice['ref']);
            }
            if (null !== $thirdparty && $thirdparty['thirdparty_id'] !== (int) $invoice['thirdparty_id']) {
                return self::decision(self::ACTION_QUEUE, self::REASON_MIXED_THIRDPARTIES);
            }
            $thirdparty = $thirdparty ?? ['thirdparty_id' => (int) $invoice['thirdparty_id'], 'name' => (string) $invoice['thirdparty_name'], 'email' => (string) $invoice['thirdparty_email']];

            $remaining = self::cents($invoice['remaining']);
            $allocations[(int) $invoice['id']] = self::format($remaining);
            $sum += $remaining;
        }

        if (!self::sameParty($transaction, $thirdparty)) {
            return self::decision(self::ACTION_QUEUE, self::REASON_COUNTERPARTY_MISMATCH, [], (string) ($transaction['counterparty_name'] ?? ''));
        }
        if ($sum !== $amount) {
            return self::decision(self::ACTION_QUEUE, self::REASON_SUM_MISMATCH, [], self::format($sum).' != '.self::format($amount));
        }

        return self::decision(self::ACTION_POST_ALLOCATION, self::REASON_REIMBURSEMENT, $allocations);
    }

    /**
     * The recipient must be the thirdparty the invoices belong to, recognised by e-mail address or,
     * failing that, by name.
     *
     * @param array<string, mixed> $transaction
     * @param array<string, mixed> $thirdparty
     */
    private static function sameParty(array $transaction, array $thirdparty): bool
    {
        $email = self::normalize((string) ($transaction['counterparty_email'] ?? ''));
        if ('' !== $email && $email === self::normalize($thirdparty['email'])) {
            return true;
        }
        $name = self::normalize((string) ($transaction['counterparty_name'] ?? ''));

        return '' !== $name && $name === self::normalize($thirdparty['name']);
    }

    private static function normalize(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
    }

    /**
     * Converts a decimal amount to integer cents. Dolibarr returns DECIMAL(24,8) columns as strings
     * such as "49.90000000", so this rounds rather than truncates.
     *
     * @param string|float|int $amount
     */
    public static function cents($amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private static function format(int $cents): string
    {
        return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv(abs($cents), 100), abs($cents) % 100);
    }

    /**
     * @param array<int, string> $allocations
     *
     * @return array{action: string, reason: string, allocations: array<int, string>, detail: string}
     */
    private static function decision(string $action, string $reason, array $allocations = [], string $detail = ''): array
    {
        return ['action' => $action, 'reason' => $reason, 'allocations' => $allocations, 'detail' => $detail];
    }
}
