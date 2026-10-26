<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

/**
 * Rules for turning a shop purchase paid by PayPal into a supplier invoice, without Dolibarr.
 *
 * Only what the PayPal data settles is decided here: whether a payment is a purchase, which values
 * the invoice gets and whether the supplier is known, new or unclear. BankSyncSupplierInvoiceFactory
 * looks the supplier up and creates the invoice, BankSyncAutoPostPolicy decides when that happens.
 */
class BankSyncPurchase
{
    /**
     * PayPal event codes of a checkout at a merchant: `T0006` PayPal Checkout APIs (measured for
     * Thomann and Elecbee) and `T0007` Website Payments Standard, both from PayPal's code reference.
     */
    public const CODES = ['T0006', 'T0007'];

    public const SUPPLIER_FOUND = 'found';
    public const SUPPLIER_NEW = 'new';
    public const SUPPLIER_UNCLEAR = 'unclear';

    /** Result of a lookup that was not made, such as by e-mail when PayPal gave none. */
    public const NOT_LOOKED_UP = -1;

    public static function isPurchaseCode(string $code): bool
    {
        return \in_array(strtoupper(trim($code)), self::CODES, true);
    }

    /**
     * Values a supplier invoice for the transaction gets when nobody typed them.
     *
     * The supplier's own number is on the Beleg only, so the reference is PayPal's `invoice_id`, the
     * merchant's order reference, and failing that the PayPal transaction ID. The VAT rate is 0,
     * because SdWa5 is a Kleinunternehmer under § 6 Abs. 1 Z 27 UStG and books gross amounts.
     *
     * @param object $transaction Row of `banksync_transaction`
     *
     * @return array{ref_supplier: string, label: string, vat_rate: float}
     */
    public static function invoiceDefaults($transaction): array
    {
        $raw = json_decode((string) ($transaction->raw_data ?? ''), true);
        $ref = \is_array($raw) ? trim((string) ($raw['transaction_info']['invoice_id'] ?? '')) : '';
        if ('' === $ref) {
            $ref = 0 === strpos((string) ($transaction->provider ?? ''), 'paypal')
                ? 'PayPal '.(string) $transaction->external_transaction_id
                : 'Eigenbeleg '.(string) $transaction->booking_date;
        }

        $label = trim((string) ($transaction->reference ?? ''));
        if ('' === $label) {
            $label = trim((string) ($transaction->counterparty_name ?? ''));
        }

        return ['ref_supplier' => $ref, 'label' => $label, 'vat_rate' => 0.0];
    }

    /**
     * Decides from two Societe::fetch() results whether the supplier is known, new or unclear.
     *
     * The e-mail address PayPal reports wins over the name. Several thirdparties for either leave
     * the choice to a person, and so does a payment without a counterparty name.
     *
     * @param int $byEmail Result of the lookup by e-mail: an ID, 0 for none, -2 for several, NOT_LOOKED_UP
     * @param int $byName  Result of the lookup by exact name, same values
     *
     * @return array{status: string, id: int, detail: string}
     */
    public static function supplierDecision(int $byEmail, int $byName, string $name, string $email = ''): array
    {
        $name = trim($name);
        $email = trim($email);

        if (-2 === $byEmail) {
            return self::supplier(self::SUPPLIER_UNCLEAR, 0, 'several thirdparties with '.$email);
        }
        if ($byEmail > 0) {
            return self::supplier(self::SUPPLIER_FOUND, $byEmail, 'thirdparty #'.$byEmail);
        }
        if (-2 === $byName) {
            return self::supplier(self::SUPPLIER_UNCLEAR, 0, 'several thirdparties named '.$name);
        }
        if ($byName > 0) {
            return self::supplier(self::SUPPLIER_FOUND, $byName, 'thirdparty #'.$byName);
        }
        if ('' === $name) {
            return self::supplier(self::SUPPLIER_UNCLEAR, 0, 'no counterparty name');
        }

        return self::supplier(self::SUPPLIER_NEW, 0, 'new supplier '.$name.('' !== $email ? ' <'.$email.'>' : ''));
    }

    /**
     * @return array{status: string, id: int, detail: string}
     */
    private static function supplier(string $status, int $id, string $detail): array
    {
        return ['status' => $status, 'id' => $id, 'detail' => $detail];
    }
}
