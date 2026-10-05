<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

use PHPUnit\Framework\TestCase;

final class PayPalEventMapperTest extends TestCase
{
    /**
     * @dataProvider knownCodes
     */
    public function testMapsKnownGroups(string $code, string $eventType, string $paymentCode): void
    {
        $transaction = new BankTransaction();
        $transaction->transactionCode = $code;

        (new PayPalEventMapper())->apply($transaction);

        self::assertSame($eventType, $transaction->bankEventType);
        self::assertSame($paymentCode, $transaction->dolibarrPaymentCode);
        self::assertSame(100, $transaction->classificationConfidence);
        self::assertSame('paypal_code:'.$code, $transaction->classificationMethod);
    }

    public function knownCodes(): iterable
    {
        yield 'payment' => ['T0006', BankSyncTransactionClassifier::TYPE_TRANSFER, 'PPL'];
        yield 'fee' => ['T0106', BankSyncTransactionClassifier::TYPE_BANK_FEE, 'PPL'];
        yield 'bank deposit' => ['T0300', BankSyncTransactionClassifier::TYPE_INTERNAL_TRANSFER, 'VIR'];
        yield 'bank withdrawal' => ['T0400', BankSyncTransactionClassifier::TYPE_INTERNAL_TRANSFER, 'VIR'];
        yield 'refund' => ['T1107', BankSyncTransactionClassifier::TYPE_REFUND, 'PPL'];
        yield 'transfer' => ['T2001', BankSyncTransactionClassifier::TYPE_INTERNAL_TRANSFER, 'PPL'];
    }

    /**
     * @dataProvider unknownCodes
     */
    public function testLeavesUnknownCodesUnpostable(string $code, string $method): void
    {
        $transaction = new BankTransaction();
        $transaction->transactionCode = $code;

        (new PayPalEventMapper())->apply($transaction);

        self::assertSame(BankSyncTransactionClassifier::TYPE_OTHER, $transaction->bankEventType);
        self::assertSame('', $transaction->dolibarrPaymentCode);
        self::assertSame(0, $transaction->classificationConfidence);
        self::assertSame($method, $transaction->classificationMethod);
    }

    public function unknownCodes(): iterable
    {
        yield 'unmapped group' => ['T1201', 'paypal_code:unknown:T1201'];
        yield 'malformed' => ['T00', 'paypal_code:unknown:T00'];
        yield 'missing' => ['', 'paypal_code:missing'];
    }

    public function testIsKnown(): void
    {
        $mapper = new PayPalEventMapper();

        self::assertTrue($mapper->isKnown('t0006'));
        self::assertFalse($mapper->isKnown('T1201'));
        self::assertFalse($mapper->isKnown('T0'));
    }

    public function testClassifierKeepsTheMapping(): void
    {
        $transaction = new BankTransaction();
        $transaction->provider = PayPalTransactionMapper::PROVIDER_KEY;
        $transaction->transactionCode = 'T0106';
        (new PayPalEventMapper())->apply($transaction);

        (new BankSyncTransactionClassifier())->classify($transaction);

        self::assertSame(BankSyncTransactionClassifier::TYPE_BANK_FEE, $transaction->bankEventType);
        self::assertSame('PPL', $transaction->dolibarrPaymentCode);
    }
}
