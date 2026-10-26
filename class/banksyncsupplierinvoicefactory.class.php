<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once __DIR__.'/banksyncbelegstore.class.php';
require_once __DIR__.'/banksyncmatchmanager.class.php';
require_once __DIR__.'/banksyncpurchase.class.php';
require_once __DIR__.'/provider/paypaltransactionmapper.class.php';

/**
 * Creates the supplier invoice a queued payment was missing, in one step.
 *
 * This covers the commonest manual case, a purchase paid by PayPal whose invoice nobody entered.
 * The invoice gets one line for the paid amount, is validated, receives the transaction's Belege,
 * and the transaction gets a confirmed match on it, so posting is the only step left. The quick-create
 * form, the REST API and the auto-poster all create through this class.
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
     * Creates the invoice from what a person or an API client gave, filling the rest.
     *
     * Omitted fields come from BankSyncPurchase::invoiceDefaults() and, for the supplier, from
     * resolveSupplier() on the counterparty's name and PayPal e-mail address.
     *
     * @param object               $transaction Row from BankSyncCandidateMatcher::fetchTransaction()
     * @param array<string, mixed> $input       socid, new_thirdparty, email, ref_supplier, label, vat_rate, post; all optional
     *
     * @return array{invoice_id: int, invoice_ref: string, posted: bool, bank_line_id: int}
     *
     * @throws DomainException          when the transaction is no debit or already has a supplier invoice
     * @throws InvalidArgumentException when the supplier is unclear and no socid was given
     */
    public function createForTransaction($transaction, array $input, User $user): array
    {
        if ('debit' !== (string) $transaction->direction) {
            throw new DomainException('Only an outgoing payment gets a supplier invoice.');
        }
        foreach ((new BankSyncMatchManager($this->db, $this->entity))->getForTransaction((int) $transaction->rowid) as $match) {
            if (BankSyncMatchManager::TARGET_SUPPLIER_INVOICE === (string) $match->target_type && \in_array((string) $match->status, ['confirmed', 'posted'], true)) {
                throw new DomainException(sprintf('Transaction %d already has supplier invoice #%d.', (int) $transaction->rowid, (int) $match->target_id));
            }
        }

        $raw = json_decode((string) $transaction->raw_data, true);
        $email = trim((string) ($input['email'] ?? (\is_array($raw) ? PayPalTransactionMapper::counterpartyEmail($raw) : '')));
        $socid = (int) ($input['socid'] ?? 0);
        $newName = trim((string) ($input['new_thirdparty'] ?? ''));
        if ($socid <= 0 && '' === $newName) {
            $supplier = $this->resolveSupplier((string) $transaction->counterparty_name, $email);
            if (BankSyncPurchase::SUPPLIER_UNCLEAR === $supplier['status']) {
                throw new InvalidArgumentException(sprintf('The supplier is unclear (%s), pass socid.', $supplier['detail']));
            }
            $socid = $supplier['id'];
            $newName = (string) $transaction->counterparty_name;
        }

        $defaults = BankSyncPurchase::invoiceDefaults($transaction);
        $invoice = $this->createFromTransaction(
            $transaction,
            $socid,
            $newName,
            (string) ($input['ref_supplier'] ?? $defaults['ref_supplier']),
            (string) ($input['label'] ?? $defaults['label']),
            (float) ($input['vat_rate'] ?? $defaults['vat_rate']),
            $user,
            $email
        );

        $result = ['invoice_id' => (int) $invoice->id, 'invoice_ref' => (string) $invoice->ref, 'posted' => false, 'bank_line_id' => 0];
        if (!empty($input['post'])) {
            require_once __DIR__.'/banksynccandidatematcher.class.php';
            require_once __DIR__.'/banksyncpostingservice.class.php';
            $posting = (new BankSyncPostingService($this->db, $this->entity))->post((new BankSyncCandidateMatcher($this->db, $this->entity))->fetchTransaction((int) $transaction->rowid), $user);
            $result['posted'] = true;
            $result['bank_line_id'] = (int) $posting['bank_line_id'];
        }

        return $result;
    }

    /**
     * Finds the supplier of a payment by e-mail address, then by exact name.
     *
     * @return array{status: string, id: int, detail: string} See BankSyncPurchase::supplierDecision()
     */
    public function resolveSupplier(string $name, string $email): array
    {
        $byEmail = BankSyncPurchase::NOT_LOOKED_UP;
        if ('' !== trim($email)) {
            $byEmail = $this->lookup('', trim($email));
        }
        $byName = BankSyncPurchase::NOT_LOOKED_UP;
        if ($byEmail <= 0 && -2 !== $byEmail && '' !== trim($name)) {
            $byName = $this->lookup(trim($name), '');
        }

        return BankSyncPurchase::supplierDecision($byEmail, $byName, $name, $email);
    }

    /**
     * @param object $transaction  Row from BankSyncCandidateMatcher::fetchTransaction()
     * @param int    $thirdpartyId Existing supplier, or 0 to create one named $newThirdpartyName
     * @param string $refSupplier  The supplier's invoice number, or a placeholder such as "Eigenbeleg <date>"
     * @param string $label        Line description
     * @param float  $vatRate      VAT rate of the single line, the amount is taken as gross
     * @param string $email        E-mail address of a new supplier, also filled into an existing one without
     * @param string $matchMethod  Method recorded on the confirmed match, `manual` for a person
     *
     * @return FactureFournisseur The validated invoice
     */
    public function createFromTransaction($transaction, int $thirdpartyId, string $newThirdpartyName, string $refSupplier, string $label, float $vatRate, User $user, string $email = '', string $matchMethod = 'manual'): FactureFournisseur
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
            $thirdparty = $this->thirdparty($thirdpartyId, $newThirdpartyName, trim($email), $user);

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
            $matches->upsert((int) $transaction->rowid, BankSyncMatchManager::TARGET_SUPPLIER_INVOICE, (int) $invoice->id, $amount, 100, $matchMethod, 'confirmed', (int) $user->id);

            $this->db->commit('BankSync quick create');

            return $invoice;
        } catch (Throwable $e) {
            $this->db->rollback('BankSync quick create');
            throw $e;
        }
    }

    /**
     * Fetches a thirdparty by exact name or e-mail address.
     *
     * @return int The ID, 0 when none exists, -2 when several do
     */
    private function lookup(string $name, string $email): int
    {
        $thirdparty = new Societe($this->db);
        $result = $thirdparty->fetch(0, $name, '', '', '', '', '', '', '', '', $email);
        if (-2 === $result) {
            return -2;
        }
        if ($result < 0) {
            throw new RuntimeException($thirdparty->error ?: 'BankSyncQuickCreateThirdpartyFailed');
        }

        return $result > 0 ? (int) $thirdparty->id : 0;
    }

    private function thirdparty(int $thirdpartyId, string $newName, string $email, User $user): Societe
    {
        $thirdparty = new Societe($this->db);
        if ($thirdpartyId > 0) {
            if ($thirdparty->fetch($thirdpartyId) <= 0) {
                throw new RuntimeException('BankSyncQuickCreateThirdpartyNotFound');
            }
            $changed = false;
            if (empty($thirdparty->fournisseur)) {
                $thirdparty->fournisseur = 1;
                $thirdparty->code_fournisseur = -1;
                $changed = true;
            }
            if ('' !== $email && '' === trim((string) $thirdparty->email)) {
                $thirdparty->email = $email;
                $changed = true;
            }
            if ($changed) {
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
        $thirdparty->email = $email;
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
