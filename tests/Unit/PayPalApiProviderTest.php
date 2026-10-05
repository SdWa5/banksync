<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

use PHPUnit\Framework\TestCase;

final class PayPalApiProviderTest extends TestCase
{
    public function testBuildsAStatementAndCountsWhatItSkipped(): void
    {
        $tx = static function (string $id, string $status, string $currency, string $value, string $fee = '0.00'): array {
            return ['transaction_info' => [
                'transaction_id' => $id,
                'transaction_event_code' => 'T0006',
                'transaction_initiation_date' => '2026-09-10T10:00:00+0000',
                'transaction_amount' => ['currency_code' => $currency, 'value' => $value],
                'fee_amount' => ['currency_code' => $currency, 'value' => $fee],
                'transaction_status' => $status,
            ]];
        };
        $page = json_encode(['total_pages' => 1, 'transaction_details' => [
            $tx('A', 'S', 'EUR', '25.00', '-0.85'),
            $tx('B', 'P', 'EUR', '-5.00'),
            $tx('C', 'S', 'USD', '-9.99'),
            ['transaction_info' => ['transaction_initiation_date' => '2026-09-01T10:00:00+0000'] + $tx('D', 'S', 'EUR', '-1.00')['transaction_info']],
        ]]);
        $responses = [['status' => 200, 'body' => '{"access_token":"tok"}'], ['status' => 200, 'body' => $page]];
        $client = new PayPalApiClient('id', 'secret', 'https://api.example', static function () use (&$responses): array {
            return array_shift($responses);
        });

        $statement = (new PayPalApiProvider())->fetch([
            'client' => $client,
            'from' => new DateTimeImmutable('2026-09-08T22:00:00Z'),
            'to' => new DateTimeImmutable('2026-09-20T22:00:00Z'),
            'account_number' => 'paypal@sdwa5.org',
        ]);

        self::assertSame('paypal_api', $statement->provider);
        self::assertSame('paypal@sdwa5.org', $statement->accountNumber);
        self::assertSame('EUR', $statement->currency);
        self::assertSame('2026-09-09', $statement->periodStart);
        self::assertSame('2026-09-21', $statement->periodEnd);
        self::assertSame(['A', 'A/fee'], array_map(static function (BankTransaction $t): string {
            return $t->externalEntryId;
        }, $statement->transactions));
        self::assertSame(['skipped_other_currency' => 1, 'skipped_pending_or_denied' => 1, 'skipped_outside_range' => 1, 'paired_currency_conversions' => 0], $statement->metadata);
    }

    public function testPairsTheConversionOfAForeignCurrencyPayment(): void
    {
        $tx = static function (string $id, string $code, string $currency, string $value, string $ref = ''): array {
            $info = [
                'transaction_id' => $id,
                'transaction_event_code' => $code,
                'transaction_initiation_date' => '2026-09-08T03:24:17Z',
                'transaction_amount' => ['currency_code' => $currency, 'value' => $value],
                'transaction_status' => 'S',
            ];
            if ('' !== $ref) {
                $info += ['paypal_reference_id' => $ref, 'paypal_reference_id_type' => 'TXN'];
            }

            return ['transaction_info' => $info, 'payer_info' => 'PAY' === $id ? ['payer_name' => ['alternate_full_name' => 'Elecbee']] : []];
        };
        $statement = $this->fetch([
            $tx('PAY', 'T0006', 'USD', '-30.54'),
            $tx('FXEUR', 'T0200', 'EUR', '-27.42', 'PAY'),
            $tx('FXUSD', 'T0200', 'USD', '30.54', 'PAY'),
            // A conversion of the balance on its own, without a payment, stays unpaired.
            $tx('LONE', 'T0200', 'EUR', '-5.00', 'MISSING'),
        ]);

        self::assertCount(2, $statement->transactions);
        [$paired, $lone] = $statement->transactions;
        self::assertSame('FXEUR', $paired->externalEntryId);
        self::assertSame('PAY', $paired->externalTransactionId);
        self::assertSame('Elecbee', $paired->counterpartyName);
        self::assertSame(BankSyncTransactionClassifier::TYPE_TRANSFER, $paired->bankEventType);
        self::assertSame('LONE', $lone->externalTransactionId);
        self::assertSame(BankSyncTransactionClassifier::TYPE_OTHER, $lone->bankEventType);
        self::assertSame(1, $statement->metadata['paired_currency_conversions']);
        self::assertSame(2, $statement->metadata['skipped_other_currency']);
    }

    /**
     * @param array<int, array<string, mixed>> $details
     */
    private function fetch(array $details): BankStatement
    {
        $responses = [['status' => 200, 'body' => '{"access_token":"tok"}'], ['status' => 200, 'body' => json_encode(['total_pages' => 1, 'transaction_details' => $details])]];
        $client = new PayPalApiClient('id', 'secret', 'https://api.example', static function () use (&$responses): array {
            return array_shift($responses);
        });

        return (new PayPalApiProvider())->fetch([
            'client' => $client,
            'from' => new DateTimeImmutable('2026-09-07T22:00:00Z'),
            'to' => new DateTimeImmutable('2026-09-08T22:00:00Z'),
            'account_number' => 'paypal@sdwa5.org',
        ]);
    }

    public function testRequiresItsContext(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PayPalApiProvider())->fetch(['account_number' => 'x']);
    }

    public function testIdentifiesItself(): void
    {
        $provider = new PayPalApiProvider();

        self::assertSame('paypal_api', $provider->getKey());
        self::assertSame('api', $provider->getSourceType());
    }
}
