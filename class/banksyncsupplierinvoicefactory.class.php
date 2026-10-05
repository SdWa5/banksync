<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once __DIR__.'/banksyncbelegstore.class.php';
require_once __DIR__.'/banksyncmatchmanager.class.php';

/**
 * Creates the supplier invoice a queued payment was missing, in one step.
 *
 * This covers the commonest manual case, a purchase paid by PayPal whose invoice nobody entered.
 * The invoice gets one line for the paid amount, is validated, receives the transaction's Belege,
 * and the transaction gets a confirmed match on it, so posting is the only step left.
 */
class BankSyncSupplierInvoiceFactory
{
    /** @var DoliDB */
    private $db;

    /** @var int */
    private $entity;

    public function __construct($db, int $entity)
    {
        $this->db = $db;
        $this->entity = $entity;
    }

    /**
     * @param object $transaction  Row from BankSyncCandidateMatcher::fetchTransaction()
     * @param int    $thirdpartyId Existing supplier, or 0 to create one named $newThirdpartyName
     * @param string $refSupplier  The supplier's invoice number, or a placeholder such as "Eigenbeleg <date>"
     * @param string $label        Line description
     * @param float  $vatRate      VAT rate of the single line, the amount is taken as gross
     *
     * @return FactureFournisseur The validated invoice
     */
    public function createFromTransaction($transaction, int $thirdpartyId, string $newThirdpartyName, string $refSupplier, string $label, float $vatRate, User $user): FactureFournisseur
    {
        if ('debit' !== (string) $transaction->direction) {
            throw new RuntimeException('BankSyncQuickCreateOnlyDebits');
        }
        if ('' === trim($refSupplier) || '' === trim($label)) {
            throw new RuntimeException('BankSyncQuickCreateFieldsRequired');
        }
        $amount = abs((float) $transaction->amount);

        $this->db->begin('BankSync quick create');
        try {
            $thirdparty = $this->thirdparty($thirdpartyId, $newThirdpartyName, $user);

            $invoice = new FactureFournisseur($this->db);
            $invoice->socid = (int) $thirdparty->id;
            $invoice->ref_supplier = trim($refSupplier);
            $invoice->type = FactureFournisseur::TYPE_STANDARD;
            $invoice->date = $this->timestamp((string) $transaction->booking_date);
            $invoice->note_private = sprintf('BankSync #%d | %s %s', (int) $transaction->rowid, (string) $transaction->provider, (string) $transaction->external_transaction_id);
            if ($invoice->create($user) <= 0) {
                throw new RuntimeException($invoice->error ?: implode(', ', (array) $invoice->errors) ?: 'BankSyncQuickCreateInvoiceFailed');
            }
            if ($invoice->addline(trim($label), $amount, $vatRate, 0, 0, 1, 0, 0, '', '', 0, '', 'TTC') <= 0) {
                throw new RuntimeException($invoice->error ?: 'BankSyncQuickCreateLineFailed');
            }
            if ($invoice->validate($user) <= 0) {
                throw new RuntimeException($invoice->error ?: 'BankSyncQuickCreateValidateFailed');
            }
            $invoice->fetch($invoice->id);

            (new BankSyncBelegStore())->copyToSupplierInvoice((int) $transaction->rowid, $invoice);

            $matches = new BankSyncMatchManager($this->db, $this->entity);
            $matches->clearSuggested((int) $transaction->rowid);
            $matches->upsert((int) $transaction->rowid, BankSyncMatchManager::TARGET_SUPPLIER_INVOICE, (int) $invoice->id, $amount, 100, 'manual', 'confirmed', (int) $user->id);

            $this->db->commit('BankSync quick create');

            return $invoice;
        } catch (Throwable $e) {
            $this->db->rollback('BankSync quick create');
            throw $e;
        }
    }

    private function thirdparty(int $thirdpartyId, string $newName, User $user): Societe
    {
        $thirdparty = new Societe($this->db);
        if ($thirdpartyId > 0) {
            if ($thirdparty->fetch($thirdpartyId) <= 0) {
                throw new RuntimeException('BankSyncQuickCreateThirdpartyNotFound');
            }
            if (empty($thirdparty->fournisseur)) {
                $thirdparty->fournisseur = 1;
                $thirdparty->code_fournisseur = -1;
                if ($thirdparty->update($thirdparty->id, $user) <= 0) {
                    throw new RuntimeException($thirdparty->error ?: 'BankSyncQuickCreateThirdpartyFailed');
                }
            }

            return $thirdparty;
        }

        if ('' === trim($newName)) {
            throw new RuntimeException('BankSyncQuickCreateThirdpartyRequired');
        }
        $thirdparty->name = trim($newName);
        $thirdparty->fournisseur = 1;
        $thirdparty->code_fournisseur = -1;
        $thirdparty->client = 0;
        if ($thirdparty->create($user) <= 0) {
            throw new RuntimeException($thirdparty->error ?: implode(', ', (array) $thirdparty->errors) ?: 'BankSyncQuickCreateThirdpartyFailed');
        }

        return $thirdparty;
    }

    private function timestamp(string $date): int
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (false === $parsed) {
            throw new RuntimeException('BankSyncPostingInvalidDate');
        }

        return dol_mktime(12, 0, 0, (int) $parsed->format('m'), (int) $parsed->format('d'), (int) $parsed->format('Y'));
    }
}
