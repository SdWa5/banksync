<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

require_once __DIR__.'/../banktransaction.class.php';
require_once __DIR__.'/paypaleventmapper.class.php';

/**
 * Turns one `transaction_details` item of PayPal's Transaction Search API into BankSync entries.
 *
 * A PayPal transaction becomes one entry for its gross amount and, when PayPal charged a fee, a
 * second entry for the fee. Both share the PayPal transaction ID as externalTransactionId, and the
 * fee entry's externalEntryId carries the suffix `/fee`, so the importer's entry-level duplicate
 * check keeps both and never stages either twice.
 *
 * Field names follow PayPal's OpenAPI schema for `/v1/reporting/transactions`.
 */
class PayPalTransactionMapper
{
    public const PROVIDER_KEY = 'paypal_api';
    public const FEE_SUFFIX = '/fee';

    /**
     * Status codes that describe money that actually moved. `P` (pending) shows up again once it
     * settles, and `D` (denied) never moved any money.
     */
    private const IMPORTED_STATUSES = ['S', 'V'];

    /** @var PayPalEventMapper */
    private $eventMapper;

    /** @var DateTimeZone */
    private $timezone;

    /**
     * @param string $timezone Timezone that decides the booking date, e.g. "Europe/Vienna"
     */
    public function __construct(PayPalEventMapper $eventMapper, string $timezone = 'Europe/Vienna')
    {
        $this->eventMapper = $eventMapper;
        $this->timezone = new DateTimeZone($timezone);
    }

    /**
     * @param array<string, mixed> $detail        One element of `transaction_details`
     * @param string               $accountNumber Source account key of the PayPal account
     *
     * @return BankTransaction[] Zero, one or two entries
     */
    public function map(array $detail, string $accountNumber): array
    {
        $info = isset($detail['transaction_info']) && \is_array($detail['transaction_info']) ? $detail['transaction_info'] : [];
        $id = trim((string) ($info['transaction_id'] ?? ''));
        if ('' === $id) {
            throw new RuntimeException('PayPal transaction without transaction_id.');
        }

        $status = strtoupper(trim((string) ($info['transaction_status'] ?? '')));
        if (!\in_array($status, self::IMPORTED_STATUSES, true)) {
            return [];
        }

        $gross = $this->money($info['transaction_amount'] ?? null);
        if (null === $gross) {
            throw new RuntimeException(sprintf('PayPal transaction %s without transaction_amount.', $id));
        }

        $date = $this->localDate((string) ($info['transaction_initiation_date'] ?? ''), $id);
        $entries = [$this->entry($detail, $info, $accountNumber, $id, $id, $gross['value'], $gross['currency'], $date)];

        $fee = $this->money($info['fee_amount'] ?? null);
        if (null !== $fee && !self::isZero($fee['value'])) {
            $feeEntry = $this->entry($detail, $info, $accountNumber, $id, $id.self::FEE_SUFFIX, $fee['value'], $fee['currency'], $date);
            $feeEntry->counterpartyName = 'PayPal';
            $feeEntry->transactionType = 'PayPal fee';
            $feeEntry->bankEventType = BankSyncTransactionClassifier::TYPE_BANK_FEE;
            $feeEntry->dolibarrPaymentCode = PayPalEventMapper::PAYMENT_CODE_PAYPAL;
            $feeEntry->classificationConfidence = 100;
            $feeEntry->classificationMethod = 'paypal_fee:'.$feeEntry->transactionCode;
            $entries[] = $feeEntry;
        }

        return $entries;
    }

    /**
     * Returns the counterparty's e-mail address, which the auto-post policy uses to recognise
     * members. Kept out of counterpartyAccount on purpose, because BankSync compares that field with
     * bank account numbers.
     *
     * @param array<string, mixed> $detail
     */
    public static function counterpartyEmail(array $detail): string
    {
        return strtolower(trim((string) ($detail['payer_info']['email_address'] ?? '')));
    }

    /**
     * @param array<string, mixed> $detail
     * @param array<string, mixed> $info
     */
    private function entry(array $detail, array $info, string $accountNumber, string $id, string $entryId, string $amount, string $currency, string $date): BankTransaction
    {
        $transaction = new BankTransaction();
        $transaction->provider = self::PROVIDER_KEY;
        $transaction->accountNumber = $accountNumber;
        $transaction->externalTransactionId = $id;
        $transaction->externalEntryId = $entryId;
        $transaction->valueDate = $date;
        $transaction->bookingDate = $date;
        $transaction->direction = (!self::isZero($amount) && '-' !== $amount[0]) ? 'credit' : 'debit';
        $transaction->amount = $amount;
        $transaction->currency = $currency;
        $transaction->transactionCode = strtoupper(trim((string) ($info['transaction_event_code'] ?? '')));
        $transaction->transactionType = trim((string) ($info['transaction_subject'] ?? ''));
        $transaction->counterpartyName = $this->counterpartyName($detail);
        $transaction->reference = $this->reference($info);
        $transaction->rawData = $detail;
        $this->eventMapper->apply($transaction);

        return $transaction;
    }

    /**
     * The note is what the payer typed as Mitteilung, so it carries invoice references best.
     *
     * @param array<string, mixed> $info
     */
    private function reference(array $info): string
    {
        foreach (['transaction_note', 'transaction_subject', 'invoice_id'] as $field) {
            $value = trim((string) ($info[$field] ?? ''));
            if ('' !== $value) {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function counterpartyName(array $detail): string
    {
        $name = isset($detail['payer_info']['payer_name']) && \is_array($detail['payer_info']['payer_name']) ? $detail['payer_info']['payer_name'] : [];
        foreach (['alternate_full_name', 'full_name'] as $field) {
            $value = trim((string) ($name[$field] ?? ''));
            if ('' !== $value) {
                return $value;
            }
        }

        $joined = trim(trim((string) ($name['given_name'] ?? '')).' '.trim((string) ($name['surname'] ?? '')));
        if ('' !== $joined) {
            return $joined;
        }

        return self::counterpartyEmail($detail);
    }

    /**
     * @param mixed $money A PayPal money object with currency_code and value
     *
     * @return array{value: string, currency: string}|null
     */
    private function money($money): ?array
    {
        if (!\is_array($money) || !isset($money['value']) || '' === trim((string) $money['value'])) {
            return null;
        }

        return ['value' => self::normalizeAmount((string) $money['value']), 'currency' => strtoupper(trim((string) ($money['currency_code'] ?? '')))];
    }

    /**
     * Normalises a decimal string to exactly two decimals without floating point, because the
     * Dolibarr image ships without bcmath.
     */
    public static function normalizeAmount(string $value): string
    {
        $value = trim($value);
        if (1 !== preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $m)) {
            throw new RuntimeException(sprintf('Unexpected PayPal amount "%s".', $value));
        }

        $fraction = $m[3] ?? '';
        if (\strlen($fraction) > 2 && '' !== trim(substr($fraction, 2), '0')) {
            throw new RuntimeException(sprintf('PayPal amount "%s" has more than two decimals.', $value));
        }

        $integer = ltrim($m[2], '0');
        $normalized = ('' === $integer ? '0' : $integer).'.'.str_pad(substr($fraction, 0, 2), 2, '0');

        return ('-' === $m[1] && !self::isZero($normalized)) ? '-'.$normalized : $normalized;
    }

    private static function isZero(string $amount): bool
    {
        return '' === trim(str_replace(['-', '.'], '', $amount), '0');
    }

    private function localDate(string $timestamp, string $id): string
    {
        $parsed = DateTimeImmutable::createFromFormat(DATE_ATOM, $timestamp)
            ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sO', $timestamp);
        if (false === $parsed) {
            throw new RuntimeException(sprintf('PayPal transaction %s has an unreadable date "%s".', $id, $timestamp));
        }

        return $parsed->setTimezone($this->timezone)->format('Y-m-d');
    }
}
