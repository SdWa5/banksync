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

        $skippedCurrency = 0;
        $skippedStatus = 0;
        foreach ($client->transactions($from, $to) as $detail) {
            $entries = $mapper->map($detail, $accountNumber);
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
        ];

        return $statement;
    }
}
