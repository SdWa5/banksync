<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

use PHPUnit\Framework\TestCase;

final class PayPalTransactionMapperTest extends TestCase
{
    public function testMapsAPurchaseWithoutFeeToOneDebitEntry(): void
    {
        $entries = $this->mapper()->map($this->detail([
            'transaction_event_code' => 'T0006',
            'transaction_amount' => ['currency_code' => 'EUR', 'value' => '-119.00'],
            'fee_amount' => ['currency_code' => 'EUR', 'value' => '0.00'],
            'transaction_subject' => 'Thomann order 4711',
            'invoice_id' => 'RE-1001',
        ], ['payer_name' => ['alternate_full_name' => 'Thomann GmbH']]), 'paypal@sdwa5.org');

        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame('paypal_api', $entry->provider);
        self::assertSame('paypal@sdwa5.org', $entry->accountNumber);
        self::assertSame('1AB23456CD789012E', $entry->externalTransactionId);
        self::assertSame('1AB23456CD789012E', $entry->externalEntryId);
        self::assertSame('debit', $entry->direction);
        self::assertSame('-119.00', $entry->amount);
        self::assertSame('EUR', $entry->currency);
        self::assertSame('Thomann GmbH', $entry->counterpartyName);
        self::assertSame('Thomann order 4711', $entry->reference);
        self::assertSame('', $entry->counterpartyAccount);
        self::assertSame(BankSyncTransactionClassifier::TYPE_TRANSFER, $entry->bankEventType);
    }

    public function testSplitsTheFeeIntoASecondEntry(): void
    {
        $entries = $this->mapper()->map($this->detail([
            'transaction_event_code' => 'T0011',
            'transaction_amount' => ['currency_code' => 'EUR', 'value' => '50.00'],
            'fee_amount' => ['currency_code' => 'EUR', 'value' => '-1.10'],
            'transaction_note' => 'Mitgliedsbeitrag 2026',
        ]), 'paypal@sdwa5.org');

        self::assertCount(2, $entries);
        [$gross, $fee] = $entries;
        self::assertSame('credit', $gross->direction);
        self::assertSame('50.00', $gross->amount);
        self::assertSame('Mitgliedsbeitrag 2026', $gross->reference);

        self::assertSame('1AB23456CD789012E', $fee->externalTransactionId);
        self::assertSame('1AB23456CD789012E/fee', $fee->externalEntryId);
        self::assertSame('debit', $fee->direction);
        self::assertSame('-1.10', $fee->amount);
        self::assertSame('PayPal', $fee->counterpartyName);
        self::assertSame(BankSyncTransactionClassifier::TYPE_BANK_FEE, $fee->bankEventType);
        self::assertSame('PPL', $fee->dolibarrPaymentCode);
        self::assertSame('paypal_fee:T0011', $fee->classificationMethod);
    }

    /**
     * @dataProvider skippedStatuses
     */
    public function testSkipsMoneyThatDidNotMove(string $status): void
    {
        self::assertSame([], $this->mapper()->map($this->detail(['transaction_status' => $status]), 'x'));
    }

    public function skippedStatuses(): iterable
    {
        yield 'pending' => ['P'];
        yield 'denied' => ['D'];
    }

    public function testKeepsReversals(): void
    {
        self::assertCount(1, $this->mapper()->map($this->detail(['transaction_status' => 'V']), 'x'));
    }

    public function testBookingDateFollowsTheLocalTimezone(): void
    {
        $entries = $this->mapper()->map($this->detail(['transaction_initiation_date' => '2026-09-08T22:30:00+0000']), 'x');

        self::assertSame('2026-09-09', $entries[0]->bookingDate);
        self::assertSame('2026-09-09', $entries[0]->valueDate);
    }

    public function testAcceptsAtomDates(): void
    {
        $entries = $this->mapper()->map($this->detail(['transaction_initiation_date' => '2026-09-10T08:00:00+02:00']), 'x');

        self::assertSame('2026-09-10', $entries[0]->bookingDate);
    }

    public function testRejectsAnUnreadableDate(): void
    {
        $this->expectException(RuntimeException::class);
        $this->mapper()->map($this->detail(['transaction_initiation_date' => 'yesterday']), 'x');
    }

    public function testRejectsAMissingTransactionId(): void
    {
        $this->expectException(RuntimeException::class);
        $this->mapper()->map(['transaction_info' => []], 'x');
    }

    public function testCounterpartyNameFallsBack(): void
    {
        $mapper = $this->mapper();

        $joined = $mapper->map($this->detail([], ['payer_name' => ['given_name' => 'Andreas', 'surname' => 'Bernauer']]), 'x');
        self::assertSame('Andreas Bernauer', $joined[0]->counterpartyName);

        $email = $mapper->map($this->detail([], ['email_address' => 'Member@Example.org']), 'x');
        self::assertSame('member@example.org', $email[0]->counterpartyName);
    }

    public function testCounterpartyEmail(): void
    {
        self::assertSame('member@example.org', PayPalTransactionMapper::counterpartyEmail(['payer_info' => ['email_address' => ' Member@Example.org ']]));
        self::assertSame('', PayPalTransactionMapper::counterpartyEmail([]));
    }

    /**
     * @dataProvider amounts
     */
    public function testNormalizeAmount(string $input, string $expected): void
    {
        self::assertSame($expected, PayPalTransactionMapper::normalizeAmount($input));
    }

    public function amounts(): iterable
    {
        yield 'integer' => ['12', '12.00'];
        yield 'one decimal' => ['-3.5', '-3.50'];
        yield 'leading zeros' => ['007.10', '7.10'];
        yield 'negative zero' => ['-0.00', '0.00'];
        yield 'trailing zeros' => ['1.2300', '1.23'];
        yield 'zero' => ['0', '0.00'];
    }

    /**
     * @dataProvider badAmounts
     */
    public function testNormalizeAmountRejects(string $input): void
    {
        $this->expectException(RuntimeException::class);
        PayPalTransactionMapper::normalizeAmount($input);
    }

    public function badAmounts(): iterable
    {
        yield 'three decimals' => ['1.234'];
        yield 'text' => ['abc'];
        yield 'comma' => ['1,50'];
    }

    public function testCartItemsAreTheLastReferenceFallback(): void
    {
        $detail = $this->detail();
        $detail['cart_info'] = ['item_details' => [['item_name' => 'DC adapter 5.5x2.1'], ['item_name' => 'DC adapter 5.5x2.1'], ['item_name' => 'XLR cable']]];

        self::assertSame('DC adapter 5.5x2.1; XLR cable', $this->mapper()->map($detail, 'paypal@sdwa5.org')[0]->reference);

        $detail['transaction_info']['invoice_id'] = 'RE-1';
        self::assertSame('RE-1', $this->mapper()->map($detail, 'paypal@sdwa5.org')[0]->reference);
    }

    /**
     * The shape PayPal returned on 2026-09-08 for a purchase paid in USD from the EUR balance.
     */
    public function testMapsAConversionLegWithThePaymentsCounterparty(): void
    {
        $payment = $this->detail([
            'transaction_id' => '12244039YK826734X',
            'transaction_event_code' => 'T0006',
            'transaction_initiation_date' => '2026-09-08T03:24:17Z',
            'transaction_amount' => ['currency_code' => 'USD', 'value' => '-30.54'],
        ], ['email_address' => 'Shop@Example.com', 'payer_name' => ['given_name' => 'Zhenghong', 'surname' => 'He']]);
        $payment['cart_info'] = ['item_details' => [['item_name' => 'DC Power Adapter']]];
        $conversion = $this->detail([
            'transaction_id' => '9T753293AG839630H',
            'transaction_event_code' => 'T0200',
            'transaction_initiation_date' => '2026-09-08T03:24:17Z',
            'transaction_amount' => ['currency_code' => 'EUR', 'value' => '-27.42'],
            'paypal_reference_id' => '12244039YK826734X',
            'paypal_reference_id_type' => 'TXN',
        ]);

        $entries = $this->mapper()->mapConversion($conversion, $payment, 'paypal@sdwa5.org');

        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame('9T753293AG839630H', $entry->externalEntryId);
        self::assertSame('12244039YK826734X', $entry->externalTransactionId);
        self::assertSame('-27.42', $entry->amount);
        self::assertSame('EUR', $entry->currency);
        self::assertSame('debit', $entry->direction);
        self::assertSame('2026-09-08', $entry->bookingDate);
        self::assertSame('Zhenghong He', $entry->counterpartyName);
        self::assertSame('DC Power Adapter', $entry->reference);
        self::assertSame('T0006', $entry->transactionCode);
        self::assertSame(BankSyncTransactionClassifier::TYPE_TRANSFER, $entry->bankEventType);
        self::assertSame('PPL', $entry->dolibarrPaymentCode);
        self::assertSame('paypal_fx:T0006', $entry->classificationMethod);
        self::assertSame('shop@example.com', PayPalTransactionMapper::counterpartyEmail($entry->rawData));
        self::assertSame('9T753293AG839630H', $entry->rawData['fx_conversion']['transaction_info']['transaction_id']);
    }

    public function testAConversionLegOfAPendingConversionIsSkipped(): void
    {
        $conversion = $this->detail(['transaction_event_code' => 'T0200', 'transaction_status' => 'P']);

        self::assertSame([], $this->mapper()->mapConversion($conversion, $this->detail(), 'paypal@sdwa5.org'));
    }

    public function testRecognisesCurrencyConversions(): void
    {
        self::assertTrue(PayPalTransactionMapper::isCurrencyConversion($this->detail(['transaction_event_code' => 'T0200'])));
        self::assertFalse(PayPalTransactionMapper::isCurrencyConversion($this->detail(['transaction_event_code' => 'T0006'])));
    }

    private function mapper(): PayPalTransactionMapper
    {
        return new PayPalTransactionMapper(new PayPalEventMapper(), 'Europe/Vienna');
    }

    /**
     * @param array<string, mixed> $info
     * @param array<string, mixed> $payer
     *
     * @return array<string, mixed>
     */
    private function detail(array $info = [], array $payer = []): array
    {
        return [
            'transaction_info' => $info + [
                'transaction_id' => '1AB23456CD789012E',
                'transaction_event_code' => 'T0006',
                'transaction_initiation_date' => '2026-09-10T10:00:00+0000',
                'transaction_amount' => ['currency_code' => 'EUR', 'value' => '-10.00'],
                'transaction_status' => 'S',
            ],
            'payer_info' => $payer,
        ];
    }
}
