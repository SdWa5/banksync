<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

require_once __DIR__.'/../bankdataproviderinterface.class.php';
require_once __DIR__.'/../bankstatement.class.php';
require_once __DIR__.'/paypalapiclient.class.php';
require_once __DIR__.'/paypaleventmapper.class.php';
require_once __DIR__.'/paypaltransactionmapper.class.php';

/**
 * BankSync provider that reads a PayPal account through the Transaction Search API.
 *
 * Context keys for fetch():
 * - `client` (PayPalApiClient, required)
 * - `from` and `to` (DateTimeImmutable, required)
 * - `account_number` (string, required), the key under which BankSync maps the PayPal account to
 *   a Dolibarr bank account, for example the account's e-mail address
 * - `currency` (string, optional, default EUR), the only currency staged; other currencies are
 *   skipped and counted in the statement metadata
 * - `timezone` (string, optional, default Europe/Vienna), which decides the booking date
 */
class PayPalApiProvider implements BankDataProviderInterface
{
    public function getKey()
    {
        return PayPalTransactionMapper::PROVIDER_KEY;
    }

    public function getLabel()
    {
        return 'PayPal (Transaction Search API)';
    }

    public function getSourceType()
    {
        return 'api';
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return BankStatement
     */
    public function fetch(array $context)
    {
        $client = $context['client'] ?? null;
        $from = $context['from'] ?? null;
        $to = $context['to'] ?? null;
        $accountNumber = trim((string) ($context['account_number'] ?? ''));
        if (!$client instanceof PayPalApiClient || !$from instanceof DateTimeImmutable || !$to instanceof DateTimeImmutable || '' === $accountNumber) {
            throw new InvalidArgumentException('PayPalApiProvider needs client, from, to and account_number.');
        }

        $currency = strtoupper(trim((string) ($context['currency'] ?? 'EUR')));
        $timezone = (string) ($context['timezone'] ?? 'Europe/Vienna');
        $mapper = new PayPalTransactionMapper(new PayPalEventMapper(), $timezone);

        $statement = new BankStatement();
        $statement->provider = $this->getKey();
        $statement->accountNumber = $accountNumber;
        $statement->currency = $currency;
        $local = new DateTimeZone($timezone);
        $statement->periodStart = $from->setTimezone($local)->format('Y-m-d');
        $statement->periodEnd = $to->setTimezone($local)->format('Y-m-d');

        $details = $client->transactions($from, $to);
        $byId = [];
        foreach ($details as $detail) {
            $id = trim((string) ($detail['transaction_info']['transaction_id'] ?? ''));
            if ('' !== $id) {
                $byId[$id] = $detail;
            }
        }

        $skippedCurrency = 0;
        $skippedStatus = 0;
        $skippedRange = 0;
        $pairedConversions = 0;
        foreach ($details as $detail) {
            // PayPal filters by window already. Checking again keeps anything before the cutover out
            // even if the API ever returns more than was asked for.
            if (!self::withinRange($detail, $from, $to)) {
                ++$skippedRange;
                continue;
            }
            $payment = self::convertedPayment($detail, $byId, $currency);
            if (null !== $payment) {
                ++$pairedConversions;
                $entries = $mapper->mapConversion($detail, $payment, $accountNumber);
            } else {
                $entries = $mapper->map($detail, $accountNumber);
            }
            if ([] === $entries) {
                ++$skippedStatus;
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry->currency !== $currency) {
                    ++$skippedCurrency;
                    continue;
                }
                $statement->addTransaction($entry);
            }
        }

        $statement->metadata = [
            'skipped_other_currency' => $skippedCurrency,
            'skipped_pending_or_denied' => $skippedStatus,
            'skipped_outside_range' => $skippedRange,
            'paired_currency_conversions' => $pairedConversions,
        ];

        return $statement;
    }

    /**
     * Returns the foreign-currency payment a conversion in the account's currency belongs to, or
     * null when the transaction is no such conversion or its payment is not among the fetched ones.
     * An unpaired conversion is staged as it is and waits in the queue as `other`.
     *
     * @param array<string, mixed>                $detail
     * @param array<string, array<string, mixed>> $byId
     *
     * @return array<string, mixed>|null
     */
    private static function convertedPayment(array $detail, array $byId, string $currency): ?array
    {
        $info = $detail['transaction_info'] ?? [];
        if (!PayPalTransactionMapper::isCurrencyConversion($detail)
            || $currency !== strtoupper((string) ($info['transaction_amount']['currency_code'] ?? ''))
            || 'TXN' !== strtoupper((string) ($info['paypal_reference_id_type'] ?? ''))) {
            return null;
        }

        $payment = $byId[trim((string) ($info['paypal_reference_id'] ?? ''))] ?? null;
        if (null === $payment || PayPalTransactionMapper::isCurrencyConversion($payment)
            || $currency === strtoupper((string) ($payment['transaction_info']['transaction_amount']['currency_code'] ?? ''))) {
            return null;
        }

        return $payment;
    }

    /**
     * @param array<string, mixed> $detail
     */
    private static function withinRange(array $detail, DateTimeImmutable $from, DateTimeImmutable $to): bool
    {
        $timestamp = (string) ($detail['transaction_info']['transaction_initiation_date'] ?? '');
        $at = DateTimeImmutable::createFromFormat(DATE_ATOM, $timestamp) ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sO', $timestamp);

        // An unreadable date is left to the mapper, which rejects it loudly.
        return false === $at || ($at >= $from && $at <= $to);
    }
}
