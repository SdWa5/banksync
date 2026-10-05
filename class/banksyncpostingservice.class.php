<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/paymentvarious.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/sociales/class/chargesociales.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/sociales/class/paymentsocialcontribution.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/tva/class/tva.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/tva/class/paymentvat.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingaccount.class.php';
require_once __DIR__.'/banksyncmatchmanager.class.php';
require_once __DIR__.'/banksyncpostingmanager.class.php';

/**
 * Builds a posting preview and, after explicit confirmation, creates native
 * Dolibarr payment objects through Dolibarr domain APIs.
 *
 * IMPORTANT: no direct SQL writes to Dolibarr core business tables are made here.
 * SQL in this class is limited to BankSync-owned staging/audit metadata.
 */
class BankSyncPostingService
{
    /** @var DoliDB */
    private $db;
    /** @var int */
    private $entity;
    /** @var BankSyncMatchManager */
    private $matchManager;
    /** @var BankSyncPostingManager */
    private $postingManager;

    public function __construct($db, $entity)
    {
        $this->db = $db;
        $this->entity = (int) $entity;
        $this->matchManager = new BankSyncMatchManager($db, $entity);
        $this->postingManager = new BankSyncPostingManager($db, $entity);
    }

    /** @return array<string,mixed> */
    public function buildPreview($transaction)
    {
        global $conf;

        $preview = array(
            'postable' => false,
            'kind' => '',
            'payment_code' => '',
            'payment_mode_id' => 0,
            'bank_account_id' => !empty($transaction->fk_bank_account) ? (int) $transaction->fk_bank_account : 0,
            'bank_account_label' => trim((string) (!empty($transaction->bank_account_label) ? $transaction->bank_account_label : $transaction->bank_account_ref)),
            'bank_amount' => abs((float) $transaction->amount,
            ),
            'signed_bank_amount' => (float) $transaction->amount,
            'currency' => (string) $transaction->currency,
            'booking_date' => (string) $transaction->booking_date,
            'reference' => (string) $transaction->reference,
            'counterparty' => (string) $transaction->counterparty_name,
            'rows' => array(),
            'warnings' => array(),
            'errors' => array(),
            'rounding_difference' => 0.0,
            'thirdparty_id' => 0,
            'bank_fee_accountancy_code' => '',
            'existing_posting' => null,
            'posting_items' => array(),
        );

        $existingPosting = $this->postingManager->getForTransaction((int) $transaction->rowid);
        $existingIsPosted = $existingPosting && (string) $existingPosting->status === 'posted';
        if ($existingPosting) {
            $preview['existing_posting'] = $existingPosting;
            $preview['posting_items'] = $this->postingManager->getItemsForPosting((int) $existingPosting->rowid);
            $preview['errors'][] = $existingIsPosted ? 'BankSyncPostingAlreadyPosted' : 'BankSyncPostingAlreadyInProgress';
        }

        if ($preview['bank_account_id'] <= 0) {
            $preview['errors'][] = 'BankSyncPostingMissingBankAccount';
        } else {
            $account = new Account($this->db);
            if ($account->fetch($preview['bank_account_id']) <= 0) {
                $preview['errors'][] = 'BankSyncPostingBankAccountNotFound';
            } else {
                $preview['bank_account_label'] = trim((string) $account->label) !== '' ? (string) $account->label : (string) $account->ref;
                $accountCurrency = trim((string) $account->currency_code);
                if (!$existingIsPosted && $accountCurrency !== '' && strtoupper($accountCurrency) !== strtoupper($preview['currency'])) {
                    $preview['errors'][] = 'BankSyncPostingBankCurrencyMismatch';
                }
            }
        }

        if (!$existingIsPosted && strtoupper($preview['currency']) !== strtoupper((string) $conf->currency)) {
            $preview['errors'][] = 'BankSyncPostingForeignCurrencyNotSupportedYet';
        }

        if ((string) $transaction->bank_event_type === 'bank_fee') {
            $preview['kind'] = BankSyncMatchManager::TARGET_BANK_FEE;
            $preview['payment_code'] = $this->inferBankFeePaymentCode($transaction);
            $preview['payment_mode_id'] = (int) dol_getIdFromCode($this->db, $preview['payment_code'], 'c_paiement', 'code', 'id', 1);
            if (!$existingIsPosted && $preview['payment_mode_id'] <= 0) $preview['errors'][] = 'BankSyncPostingPaymentCodeNotFound';

            $preview['bank_fee_accountancy_code'] = getDolGlobalString('BANKSYNC_BANK_FEE_ACCOUNTANCY_CODE');
            if (!$existingIsPosted && isModEnabled('accounting')) {
                if (trim($preview['bank_fee_accountancy_code']) === '') {
                    $preview['errors'][] = 'BankSyncPostingBankFeeAccountancyCodeRequired';
                } else {
                    $accountingAccount = new AccountingAccount($this->db);
                    $accountResult = $accountingAccount->fetch(0, (string) $preview['bank_fee_accountancy_code'], 1);
                    if ($accountResult <= 0 || empty($accountingAccount->active)) $preview['errors'][] = 'BankSyncPostingBankFeeAccountancyCodeInvalid';
                }
            }

            $preview['rows'][] = array(
                'target_type' => BankSyncMatchManager::TARGET_BANK_FEE,
                'target_id' => 0,
                'ref' => (string) $transaction->transaction_code,
                'label' => trim((string) $transaction->transaction_type) !== '' ? (string) $transaction->transaction_type : (string) $transaction->counterparty_name,
                'thirdparty' => (string) $transaction->counterparty_name,
                'allocated_amount' => abs((float) $transaction->amount),
                'remaining_before' => null,
                'remaining_after' => null,
                'url' => '',
            );
            $preview['postable'] = empty($preview['errors']);
            return $preview;
        }

        $summary = $this->matchManager->getAllocationSummary((int) $transaction->rowid);
        $preview['rounding_difference'] = (float) $summary['rounding_difference'];
        if (!$existingIsPosted && empty($summary['balanced'])) $preview['errors'][] = 'BankSyncPostingTransactionNotBalanced';
        if (!$existingIsPosted && abs((float) $summary['rounding_difference']) > 0.00001) $preview['errors'][] = 'BankSyncPostingRoundingPolicyRequired';

        $settlements = array();
        foreach ($this->matchManager->getForTransaction((int) $transaction->rowid) as $match) {
            if ((string) $match->status === 'confirmed' || ($existingIsPosted && (string) $match->status === 'posted')) {
                $settlements[] = $match;
            } elseif ((string) $match->status === 'posted') {
                $preview['errors'][] = 'BankSyncPostingAlreadyPosted';
            }
        }
        if (empty($settlements)) {
            if (!$existingIsPosted) $preview['errors'][] = 'BankSyncPostingNoConfirmedMatches';
            return $preview;
        }

        $targetType = (string) $settlements[0]->target_type;
        if (!in_array($targetType, array(
            BankSyncMatchManager::TARGET_CUSTOMER_INVOICE,
            BankSyncMatchManager::TARGET_SUPPLIER_INVOICE,
            BankSyncMatchManager::TARGET_SOCIAL_CONTRIBUTION,
            BankSyncMatchManager::TARGET_VAT,
        ), true)) {
            $preview['errors'][] = 'BankSyncPostingTargetNotSupportedYet';
            return $preview;
        }
        $preview['kind'] = $targetType;
        $preview['payment_code'] = trim((string) $transaction->dolibarr_payment_code);
        if (!$existingIsPosted) {
            if ($preview['payment_code'] === '') {
                $preview['errors'][] = 'BankSyncPostingMissingPaymentCode';
            } else {
                $preview['payment_mode_id'] = (int) dol_getIdFromCode($this->db, $preview['payment_code'], 'c_paiement', 'code', 'id', 1);
                if ($preview['payment_mode_id'] <= 0) $preview['errors'][] = 'BankSyncPostingPaymentCodeNotFound';
            }
        } elseif ($preview['payment_code'] !== '') {
            $preview['payment_mode_id'] = (int) dol_getIdFromCode($this->db, $preview['payment_code'], 'c_paiement', 'code', 'id', 1);
        }

        $thirdpartyId = 0;
        foreach ($settlements as $match) {
            if ((string) $match->target_type !== $targetType) {
                $preview['errors'][] = 'BankSyncPostingMixedTargetTypes';
                continue;
            }
            $allocated = (float) $match->allocated_amount;
            if ($allocated <= 0) {
                $preview['errors'][] = 'BankSyncPostingInvalidAllocation';
                continue;
            }

            $invoiceThirdpartyId = 0;
            if ($targetType === BankSyncMatchManager::TARGET_SOCIAL_CONTRIBUTION) {
                $charge = new ChargeSociales($this->db);
                if ($charge->fetch((int) $match->target_id) <= 0) {
                    $preview['errors'][] = 'BankSyncPostingSocialContributionNotFound';
                    continue;
                }
                $remaining = (float) $charge->amount - (float) $charge->getSommePaiement();
                $ref = (string) $charge->ref;
                $period = !empty($charge->period) ? dol_print_date($charge->period, 'day') : '';
                $label = trim((string) $charge->label);
                if (trim((string) $charge->type_label) !== '') $label .= ($label !== '' ? ' — ' : '').(string) $charge->type_label;
                if ($period !== '') $label .= ' ['.$period.']';
                $url = '/compta/sociales/card.php?id='.(int) $charge->id;
            } elseif ($targetType === BankSyncMatchManager::TARGET_VAT) {
                $vat = new Tva($this->db);
                if ($vat->fetch((int) $match->target_id) <= 0) {
                    $preview['errors'][] = 'BankSyncPostingVatNotFound';
                    continue;
                }
                $remaining = (float) $vat->amount - (float) $vat->getSommePaiement();
                $ref = '#'.(int) $vat->id;
                $label = trim((string) $vat->label) !== '' ? (string) $vat->label : 'VAT';
                $url = '/compta/tva/card.php?id='.(int) $vat->id;
            } elseif ($targetType === BankSyncMatchManager::TARGET_CUSTOMER_INVOICE) {
                $invoice = new Facture($this->db);
                if ($invoice->fetch((int) $match->target_id) <= 0) {
                    $preview['errors'][] = 'BankSyncPostingInvoiceNotFound';
                    continue;
                }
                $invoice->fetch_thirdparty();
                $invoiceThirdpartyId = !empty($invoice->thirdparty->id) ? (int) $invoice->thirdparty->id : (int) $invoice->socid;
                $remaining = (float) $invoice->total_ttc - (float) $invoice->getSommePaiement();
                $ref = (string) $invoice->ref;
                $url = '/compta/facture/card.php?facid='.(int) $invoice->id;
                $label = !empty($invoice->thirdparty->name) ? (string) $invoice->thirdparty->name : '';
            } else {
                $invoice = new FactureFournisseur($this->db);
                if ($invoice->fetch((int) $match->target_id) <= 0) {
                    $preview['errors'][] = 'BankSyncPostingInvoiceNotFound';
                    continue;
                }
                $invoice->fetch_thirdparty();
                $invoiceThirdpartyId = !empty($invoice->thirdparty->id) ? (int) $invoice->thirdparty->id : (int) $invoice->socid;
                $remaining = (float) $invoice->total_ttc - (float) $invoice->getSommePaiement();
                $ref = trim((string) $invoice->ref_supplier) !== '' ? (string) $invoice->ref_supplier : (string) $invoice->ref;
                $url = '/fourn/facture/card.php?facid='.(int) $invoice->id;
                $label = !empty($invoice->thirdparty->name) ? (string) $invoice->thirdparty->name : '';
            }

            if (in_array($targetType, array(BankSyncMatchManager::TARGET_CUSTOMER_INVOICE, BankSyncMatchManager::TARGET_SUPPLIER_INVOICE), true)) {
                if ($thirdpartyId === 0) $thirdpartyId = $invoiceThirdpartyId;
                elseif ($invoiceThirdpartyId !== $thirdpartyId) $preview['errors'][] = 'BankSyncPostingMultipleThirdparties';

                if (($targetType === BankSyncMatchManager::TARGET_CUSTOMER_INVOICE && (int) $invoice->type === Facture::TYPE_CREDIT_NOTE)
                    || ($targetType === BankSyncMatchManager::TARGET_SUPPLIER_INVOICE && (int) $invoice->type === FactureFournisseur::TYPE_CREDIT_NOTE)) {
                    $preview['errors'][] = 'BankSyncPostingCreditNoteNotSupportedYet';
                }
            }

            if (!$existingIsPosted && $allocated - abs($remaining) > 0.00001) $preview['errors'][] = 'BankSyncPostingAllocationExceedsCurrentRemaining';

            $remainingBefore = $existingIsPosted ? $remaining + $allocated : $remaining;
            $remainingAfter = $existingIsPosted ? $remaining : $remaining - $allocated;
            $preview['rows'][] = array(
                'target_type' => $targetType,
                'target_id' => (int) $match->target_id,
                'ref' => $ref,
                'label' => $label,
                'thirdparty' => $label,
                'allocated_amount' => $allocated,
                'remaining_before' => $remainingBefore,
                'remaining_after' => $remainingAfter,
                'url' => $url,
            );
        }

        if (!$existingIsPosted && $targetType === BankSyncMatchManager::TARGET_CUSTOMER_INVOICE && (float) $transaction->amount < 0) $preview['errors'][] = 'BankSyncPostingUnexpectedDirection';
        if (!$existingIsPosted && $targetType === BankSyncMatchManager::TARGET_SUPPLIER_INVOICE && (float) $transaction->amount > 0) $preview['errors'][] = 'BankSyncPostingUnexpectedDirection';
        if (!$existingIsPosted && in_array($targetType, array(BankSyncMatchManager::TARGET_SOCIAL_CONTRIBUTION, BankSyncMatchManager::TARGET_VAT), true) && (float) $transaction->amount > 0) $preview['errors'][] = 'BankSyncPostingUnexpectedDirection';

        if ($targetType === BankSyncMatchManager::TARGET_SOCIAL_CONTRIBUTION && count($preview['rows']) > 1) $preview['warnings'][] = 'BankSyncPostingSocialContributionSplitBankLines';
        if ($targetType === BankSyncMatchManager::TARGET_VAT && count($preview['rows']) > 1) $preview['warnings'][] = 'BankSyncPostingVatSplitBankLines';

        $preview['thirdparty_id'] = $thirdpartyId;
        $preview['postable'] = empty($preview['errors']);
        return $preview;
    }

    /** @return array<string,mixed> */
    public function post($transaction, $user)
    {
        $preview = $this->buildPreview($transaction);
        if (empty($preview['postable'])) {
            throw new RuntimeException(!empty($preview['errors']) ? (string) $preview['errors'][0] : 'BankSyncPostingBlocked');
        }

        $this->db->begin('BankSync native posting');
        try {
            $postingId = $this->postingManager->acquire((int) $transaction->rowid, (string) $preview['kind'], (int) $user->id);

            if ((string) $preview['kind'] === BankSyncMatchManager::TARGET_CUSTOMER_INVOICE
                || (string) $preview['kind'] === BankSyncMatchManager::TARGET_SUPPLIER_INVOICE) {
                $native = $this->postInvoicePayment($transaction, $preview, $user);
            } elseif ((string) $preview['kind'] === BankSyncMatchManager::TARGET_SOCIAL_CONTRIBUTION) {
                $native = $this->postSocialContributions($transaction, $preview, $user);
            } elseif ((string) $preview['kind'] === BankSyncMatchManager::TARGET_VAT) {
                $native = $this->postVatPayments($transaction, $preview, $user);
            } elseif ((string) $preview['kind'] === BankSyncMatchManager::TARGET_BANK_FEE) {
                $native = $this->postBankFee($transaction, $preview, $user);
            } else {
                throw new RuntimeException('BankSyncPostingTargetNotSupportedYet');
            }

            if (!empty($native['items']) && is_array($native['items'])) {
                foreach ($native['items'] as $item) {
                    $this->postingManager->addItem(
                        $postingId,
                        isset($item['target_type']) ? (string) $item['target_type'] : '',
                        isset($item['target_id']) ? (int) $item['target_id'] : 0,
                        (string) $item['native_object_type'],
                        (int) $item['native_object_id'],
                        (int) $item['bank_line_id'],
                        (float) $item['amount']
                    );
                }
            }

            $this->postingManager->markPosted($postingId, $native['native_object_type'], $native['native_object_id'], $native['bank_line_id'], (int) $user->id);
            $this->postingManager->markBankSyncObjectsPosted((int) $transaction->rowid, (int) $user->id);
            $this->db->commit('BankSync native posting');

            return array(
                'posting_id' => $postingId,
                'native_object_type' => $native['native_object_type'],
                'native_object_id' => $native['native_object_id'],
                'bank_line_id' => $native['bank_line_id'],
                'items' => isset($native['items']) ? $native['items'] : array(),
            );
        } catch (Throwable $e) {
            $this->db->rollback('BankSync native posting');
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    private function postInvoicePayment($transaction, $preview, $user)
    {
        global $conf;
        $thirdparty = new Societe($this->db);
        if ((int) $preview['thirdparty_id'] <= 0 || $thirdparty->fetch((int) $preview['thirdparty_id']) <= 0) throw new RuntimeException('BankSyncPostingThirdpartyNotFound');

        $amounts = array(); $multicurrencyCode = array(); $multicurrencyTx = array();
        foreach ($preview['rows'] as $row) {
            $id = (int) $row['target_id'];
            $amounts[$id] = (float) $row['allocated_amount'];
            $invoice = ((string) $preview['kind'] === BankSyncMatchManager::TARGET_CUSTOMER_INVOICE) ? new Facture($this->db) : new FactureFournisseur($this->db);
            if ($invoice->fetch($id) <= 0) throw new RuntimeException('BankSyncPostingInvoiceNotFound');
            $multicurrencyCode[$id] = trim((string) $invoice->multicurrency_code) !== '' ? (string) $invoice->multicurrency_code : (string) $conf->currency;
            $multicurrencyTx[$id] = !empty($invoice->multicurrency_tx) ? (float) $invoice->multicurrency_tx : 1.0;
        }

        if ((string) $preview['kind'] === BankSyncMatchManager::TARGET_CUSTOMER_INVOICE) {
            $payment = new Paiement($this->db); $mode = 'payment'; $label = '(CustomerInvoicePayment)'; $nativeType = 'payment';
        } else {
            $payment = new PaiementFourn($this->db); $mode = 'payment_supplier'; $label = '(SupplierInvoicePayment)'; $nativeType = 'payment_supplier';
        }
        $payment->datepaye = $this->sqlDateToTimestamp((string) $preview['booking_date']);
        $payment->amounts = $amounts;
        $payment->multicurrency_amounts = array();
        $payment->multicurrency_code = $multicurrencyCode;
        $payment->multicurrency_tx = $multicurrencyTx;
        $payment->paiementcode = (string) $preview['payment_code'];
        $payment->paiementid = (int) $preview['payment_mode_id'];
        $payment->num_payment = $this->bankReference($transaction);
        $payment->note_private = $this->auditNote($transaction);
        $payment->fk_account = (int) $preview['bank_account_id'];
        $paymentId = $payment->create($user, 1, $thirdparty);
        if ($paymentId <= 0) throw new RuntimeException($payment->error ? $payment->error : 'BankSyncPostingPaymentCreateFailed');
        $bankLineId = $payment->addPaymentToBank($user, $mode, $label, (int) $preview['bank_account_id'], '', '');
        if ($bankLineId <= 0) throw new RuntimeException($payment->error ? $payment->error : 'BankSyncPostingBankLineCreateFailed');
        return array('native_object_type' => $nativeType, 'native_object_id' => (int) $paymentId, 'bank_line_id' => (int) $bankLineId, 'items' => array());
    }

    /** @return array<string,mixed> */
    private function postSocialContributions($transaction, $preview, $user)
    {
        $items = array();
        foreach ($preview['rows'] as $row) {
            $chargeId = (int) $row['target_id'];
            $amount = (float) $row['allocated_amount'];
            $payment = new PaymentSocialContribution($this->db);
            $payment->chid = $chargeId;
            $payment->fk_charge = $chargeId;
            $payment->datepaye = $this->sqlDateToTimestamp((string) $preview['booking_date']);
            $payment->amounts = array($chargeId => $amount);
            $payment->paiementtype = (int) $preview['payment_mode_id'];
            $payment->fk_typepaiement = (int) $preview['payment_mode_id'];
            $payment->num_payment = $this->bankReference($transaction);
            $payment->note = $this->auditNote($transaction);
            $payment->note_private = $payment->note;

            $paymentId = $payment->create($user, 1);
            if ($paymentId <= 0) throw new RuntimeException($payment->error ? $payment->error : 'BankSyncPostingPaymentCreateFailed');
            $result = $payment->addPaymentToBank($user, 'payment_sc', '(SocialContributionPayment)', (int) $preview['bank_account_id'], '', '');
            if ($result <= 0) throw new RuntimeException($payment->error ? $payment->error : 'BankSyncPostingBankLineCreateFailed');
            if ($payment->fetch((int) $paymentId) <= 0 || (int) $payment->fk_bank <= 0) throw new RuntimeException('BankSyncPostingBankLineCreateFailed');

            $items[] = array(
                'target_type' => BankSyncMatchManager::TARGET_SOCIAL_CONTRIBUTION,
                'target_id' => $chargeId,
                'native_object_type' => 'payment_social',
                'native_object_id' => (int) $paymentId,
                'bank_line_id' => (int) $payment->fk_bank,
                'amount' => $amount,
            );
        }
        if (empty($items)) throw new RuntimeException('BankSyncPostingNoConfirmedMatches');
        $first = $items[0];
        return array(
            'native_object_type' => 'payment_social',
            'native_object_id' => (int) $first['native_object_id'],
            'bank_line_id' => (int) $first['bank_line_id'],
            'items' => $items,
        );
    }

    /** @return array<string,mixed> */
    private function postVatPayments($transaction, $preview, $user)
    {
        $items = array();
        foreach ($preview['rows'] as $row) {
            $vatId = (int) $row['target_id'];
            $amount = (float) $row['allocated_amount'];
            $payment = new PaymentVAT($this->db);
            $payment->chid = $vatId;
            $payment->fk_tva = $vatId;
            $payment->datepaye = $this->sqlDateToTimestamp((string) $preview['booking_date']);
            $payment->amounts = array($vatId => $amount);
            $payment->paiementtype = (int) $preview['payment_mode_id'];
            $payment->fk_typepaiement = (int) $preview['payment_mode_id'];
            $payment->num_payment = $this->bankReference($transaction);
            $payment->note = $this->auditNote($transaction);
            $payment->note_private = $payment->note;

            $paymentId = $payment->create($user, 1);
            if ($paymentId <= 0) throw new RuntimeException($payment->error ? $payment->error : 'BankSyncPostingPaymentCreateFailed');
            $result = $payment->addPaymentToBank($user, 'payment_vat', '(VATPayment)', (int) $preview['bank_account_id'], '', '');
            if ($result <= 0) throw new RuntimeException($payment->error ? $payment->error : 'BankSyncPostingBankLineCreateFailed');
            if ($payment->fetch((int) $paymentId) <= 0 || (int) $payment->fk_bank <= 0) throw new RuntimeException('BankSyncPostingBankLineCreateFailed');

            $items[] = array(
                'target_type' => BankSyncMatchManager::TARGET_VAT,
                'target_id' => $vatId,
                'native_object_type' => 'payment_vat',
                'native_object_id' => (int) $paymentId,
                'bank_line_id' => (int) $payment->fk_bank,
                'amount' => $amount,
            );
        }
        if (empty($items)) throw new RuntimeException('BankSyncPostingNoConfirmedMatches');
        $first = $items[0];
        return array(
            'native_object_type' => 'payment_vat',
            'native_object_id' => (int) $first['native_object_id'],
            'bank_line_id' => (int) $first['bank_line_id'],
            'items' => $items,
        );
    }

    /** @return array<string,mixed> */
    private function postBankFee($transaction, $preview, $user)
    {
        $payment = new PaymentVarious($this->db);
        $payment->fk_account = (int) $preview['bank_account_id'];
        $payment->accountid = $payment->fk_account;
        $payment->datep = $this->sqlDateToTimestamp((string) $preview['booking_date']);
        $payment->datev = $payment->datep;
        $payment->amount = abs((float) $transaction->amount);
        $payment->label = trim((string) $transaction->transaction_type) !== '' ? (string) $transaction->transaction_type : 'Bank fee';
        $payment->note_private = $this->auditNote($transaction);
        $payment->note = $payment->note_private;
        $payment->type_payment = (int) $preview['payment_mode_id'];
        $payment->num_payment = $this->bankReference($transaction);
        $payment->fk_user_author = (int) $user->id;
        $payment->accountancy_code = (string) $preview['bank_fee_accountancy_code'];
        $payment->subledger_account = '';
        $payment->sens = ((float) $transaction->amount < 0) ? 0 : 1;
        $paymentId = $payment->create($user);
        if ($paymentId <= 0) throw new RuntimeException($payment->error ? $payment->error : 'BankSyncPostingPaymentCreateFailed');
        if ($payment->fetch($paymentId, $user) <= 0) throw new RuntimeException('BankSyncPostingPaymentFetchFailed');
        if ((int) $payment->fk_bank <= 0) throw new RuntimeException('BankSyncPostingBankLineCreateFailed');
        return array('native_object_type' => 'payment_various', 'native_object_id' => (int) $paymentId, 'bank_line_id' => (int) $payment->fk_bank, 'items' => array());
    }

    private function inferBankFeePaymentCode($transaction)
    {
        if (strtoupper(trim((string) $transaction->transaction_code)) === 'PPCF') return 'CB';
        if (trim((string) $transaction->dolibarr_payment_code) !== '') return (string) $transaction->dolibarr_payment_code;
        if (trim((string) $transaction->external_transaction_id) !== '') {
            $sql = 'SELECT dolibarr_payment_code FROM '.$this->db->prefix().'banksync_transaction';
            $sql .= ' WHERE entity = '.$this->entity.' AND rowid <> '.((int) $transaction->rowid);
            $sql .= " AND provider = '".$this->db->escape((string) $transaction->provider)."'";
            $sql .= " AND external_transaction_id = '".$this->db->escape((string) $transaction->external_transaction_id)."'";
            $sql .= " AND dolibarr_payment_code IS NOT NULL AND dolibarr_payment_code <> '' ORDER BY rowid ASC LIMIT 1";
            $resql = $this->db->query($sql);
            if ($resql) {
                $obj = $this->db->fetch_object($resql); $this->db->free($resql);
                if ($obj && trim((string) $obj->dolibarr_payment_code) !== '') return (string) $obj->dolibarr_payment_code;
            }
        }
        return 'VIR';
    }

    private function bankReference($transaction)
    {
        // PayPal references are free text typed by the payer, so the transaction ID is the traceable key there.
        $value = strpos((string) $transaction->provider, 'paypal') === 0 ? trim((string) $transaction->external_transaction_id) : '';
        if ($value === '') $value = trim((string) $transaction->reference);
        if ($value === '') $value = trim((string) $transaction->external_transaction_id);
        if ($value === '') $value = trim((string) $transaction->external_entry_id);
        if (function_exists('mb_substr')) return mb_substr($value, 0, 50, 'UTF-8');
        return substr($value, 0, 50);
    }

    private function auditNote($transaction)
    {
        $parts = array('BankSync #'.((int) $transaction->rowid));
        if (trim((string) $transaction->provider) !== '') $parts[] = 'provider='.(string) $transaction->provider;
        if (trim((string) $transaction->external_entry_id) !== '') $parts[] = 'entry='.(string) $transaction->external_entry_id;
        if (trim((string) $transaction->reference) !== '') $parts[] = 'reference='.(string) $transaction->reference;
        return implode(' | ', $parts);
    }

    private function sqlDateToTimestamp($date)
    {
        $parts = explode('-', trim((string) $date));
        if (count($parts) !== 3) throw new RuntimeException('BankSyncPostingInvalidDate');
        return dol_mktime(12, 0, 0, (int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }
}
