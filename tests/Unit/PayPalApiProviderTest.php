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
        self::assertSame(['skipped_other_currency' => 1, 'skipped_pending_or_denied' => 1], $statement->metadata);
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
