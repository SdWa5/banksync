<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

require_once __DIR__.'/../banksynctransactionclassifier.class.php';

/**
 * Maps PayPal transaction event codes (T-codes) to BankSync event types and Dolibarr payment codes.
 *
 * The groups follow PayPal's "Transaction event codes" reference. Only groups whose meaning is
 * unambiguous for an organisation account are mapped. Everything else becomes `other` with an empty
 * payment code, which keeps it out of native posting until a person decides.
 */
class PayPalEventMapper
{
    public const PAYMENT_CODE_PAYPAL = 'PPL';
    public const PAYMENT_CODE_TRANSFER = 'VIR';

    /**
     * @var array<string, array{0: string, 1: string}> Event code group => [event type, payment code]
     */
    private const GROUPS = [
        'T00' => [BankSyncTransactionClassifier::TYPE_TRANSFER, self::PAYMENT_CODE_PAYPAL],
        'T01' => [BankSyncTransactionClassifier::TYPE_BANK_FEE, self::PAYMENT_CODE_PAYPAL],
        'T03' => [BankSyncTransactionClassifier::TYPE_INTERNAL_TRANSFER, self::PAYMENT_CODE_TRANSFER],
        'T04' => [BankSyncTransactionClassifier::TYPE_INTERNAL_TRANSFER, self::PAYMENT_CODE_TRANSFER],
        'T11' => [BankSyncTransactionClassifier::TYPE_REFUND, self::PAYMENT_CODE_PAYPAL],
        'T20' => [BankSyncTransactionClassifier::TYPE_INTERNAL_TRANSFER, self::PAYMENT_CODE_PAYPAL],
    ];

    /**
     * Applies the mapping to a transaction in place.
     *
     * A classification set here is preserved by BankSyncTransactionClassifier, because it only fills
     * an empty event type for providers other than BinX.
     *
     * @param BankTransaction $transaction Transaction whose transactionCode holds the PayPal event code
     */
    public function apply(BankTransaction $transaction): BankTransaction
    {
        $code = strtoupper(trim((string) $transaction->transactionCode));
        $group = substr($code, 0, 3);

        if (1 === preg_match('/^T\d{4}$/', $code) && isset(self::GROUPS[$group])) {
            [$eventType, $paymentCode] = self::GROUPS[$group];
            $transaction->bankEventType = $eventType;
            $transaction->dolibarrPaymentCode = $paymentCode;
            $transaction->classificationConfidence = 100;
            $transaction->classificationMethod = 'paypal_code:'.$code;

            return $transaction;
        }

        $transaction->bankEventType = BankSyncTransactionClassifier::TYPE_OTHER;
        $transaction->dolibarrPaymentCode = '';
        $transaction->classificationConfidence = 0;
        $transaction->classificationMethod = '' === $code ? 'paypal_code:missing' : 'paypal_code:unknown:'.$code;

        return $transaction;
    }

    /**
     * Tells whether an event code belongs to a mapped group.
     */
    public function isKnown(string $code): bool
    {
        $code = strtoupper(trim($code));

        return 1 === preg_match('/^T\d{4}$/', $code) && isset(self::GROUPS[substr($code, 0, 3)]);
    }
}
