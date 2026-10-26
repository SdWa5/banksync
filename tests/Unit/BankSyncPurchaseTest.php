<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

use PHPUnit\Framework\TestCase;

final class BankSyncPurchaseTest extends TestCase
{
    /**
     * @dataProvider codes
     */
    public function testRecognisesCheckoutCodes(string $code, bool $purchase): void
    {
        self::assertSame($purchase, BankSyncPurchase::isPurchaseCode($code));
    }

    public function codes(): iterable
    {
        yield 'checkout API' => ['T0006', true];
        yield 'website payment' => [' t0007 ', true];
        yield 'money sent' => ['T0000', false];
        yield 'hold release' => ['T1105', false];
        yield 'empty' => ['', false];
    }

    public function testDefaultsUsePayPalsInvoiceId(): void
    {
        $defaults = BankSyncPurchase::invoiceDefaults($this->tx(['raw_data' => json_encode(['transaction_info' => ['invoice_id' => 'capture-59968406']])]));

        self::assertSame(['ref_supplier' => 'capture-59968406', 'label' => 'Kabel', 'vat_rate' => 0.0], $defaults);
    }

    public function testDefaultsFallBackToThePayPalTransactionId(): void
    {
        $defaults = BankSyncPurchase::invoiceDefaults($this->tx(['reference' => '']));

        self::assertSame('PayPal 2D897223L17709259', $defaults['ref_supplier']);
        self::assertSame('Thomann GmbH', $defaults['label']);
    }

    public function testDefaultsOfAnotherProviderAreAnEigenbeleg(): void
    {
        self::assertSame('Eigenbeleg 2026-10-16', BankSyncPurchase::invoiceDefaults($this->tx(['provider' => 'binx_csv']))['ref_supplier']);
    }

    /**
     * @dataProvider suppliers
     */
    public function testDecidesTheSupplier(int $byEmail, int $byName, string $name, string $status, int $id): void
    {
        $decision = BankSyncPurchase::supplierDecision($byEmail, $byName, $name, 'buchhaltung@thomann.de');

        self::assertSame($status, $decision['status']);
        self::assertSame($id, $decision['id']);
    }

    public function suppliers(): iterable
    {
        yield 'found by e-mail' => [7, BankSyncPurchase::NOT_LOOKED_UP, 'Thomann GmbH', 'found', 7];
        yield 'e-mail beats name' => [7, 9, 'Thomann GmbH', 'found', 7];
        yield 'several by e-mail' => [-2, BankSyncPurchase::NOT_LOOKED_UP, 'Thomann GmbH', 'unclear', 0];
        yield 'found by name' => [0, 9, 'Thomann GmbH', 'found', 9];
        yield 'several by name' => [0, -2, 'Thomann GmbH', 'unclear', 0];
        yield 'new' => [0, 0, 'Thomann GmbH', 'new', 0];
        yield 'no e-mail, new' => [BankSyncPurchase::NOT_LOOKED_UP, 0, 'Thomann GmbH', 'new', 0];
        yield 'no name' => [0, BankSyncPurchase::NOT_LOOKED_UP, ' ', 'unclear', 0];
    }

    public function testANewSupplierNamesItsAddress(): void
    {
        self::assertSame('new supplier Thomann GmbH <buchhaltung@thomann.de>', BankSyncPurchase::supplierDecision(0, 0, 'Thomann GmbH', 'buchhaltung@thomann.de')['detail']);
    }

    private function tx(array $override = []): object
    {
        return (object) ($override + [
            'provider' => 'paypal_api',
            'external_transaction_id' => '2D897223L17709259',
            'booking_date' => '2026-10-16',
            'reference' => 'Kabel',
            'counterparty_name' => 'Thomann GmbH',
            'raw_data' => '{}',
        ]);
    }
}
