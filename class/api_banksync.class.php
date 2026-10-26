<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

use Luracast\Restler\RestException;

require_once __DIR__.'/banksyncautoposter.class.php';
require_once __DIR__.'/banksyncbelegstore.class.php';
require_once __DIR__.'/banksynccandidatematcher.class.php';
require_once __DIR__.'/banksyncmatchmanager.class.php';
require_once __DIR__.'/banksyncpurchase.class.php';
require_once __DIR__.'/banksyncqueue.class.php';
require_once __DIR__.'/banksyncsupplierinvoicefactory.class.php';
require_once __DIR__.'/banksynctransactionignorer.class.php';
require_once __DIR__.'/provider/paypaltransactionmapper.class.php';

/**
 * BankSync endpoints: the queue, a staged transaction and the decisions a person makes on it.
 *
 * Belege of a supplier invoice are uploaded and downloaded through the core documents API
 * (`modulepart=supplier_invoice`, `ref`), which indexes them, so the queue sees them at once.
 *
 * @access protected
 *
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Banksync extends DolibarrApi
{
    public function __construct()
    {
        global $db;
        $this->db = $db;
    }

    /**
     * List the queue, the transactions that wait for a person, oldest first.
     *
     * @param int $limit Maximum number of items
     *
     * @return array<int, array<string, mixed>> id, booking_date, counterparty_name, amount, currency and reason (a translation key)
     *
     * @url GET queue
     *
     * @throws RestException 403
     */
    public function getQueue($limit = 100)
    {
        return $this->call([['banksync', 'read']], function (int $entity) use ($limit) {
            return BankSyncQueue::items($this->db, $entity, (int) $limit);
        });
    }

    /**
     * Get a staged transaction with its decision, matches, Belege, supplier invoice and the values
     * a supplier invoice for it would get.
     *
     * @param int $id transaction id (rowid of llx_banksync_transaction)
     *
     * @return array<string, mixed>
     *
     * @url GET transactions/{id}
     *
     * @throws RestException 403
     * @throws RestException 404
     */
    public function getTransaction($id)
    {
        return $this->call([['banksync', 'read']], function (int $entity) use ($id) {
            $tx = $this->transaction($entity, (int) $id);
            $raw = json_decode((string) $tx->raw_data, true);
            $email = \is_array($raw) ? PayPalTransactionMapper::counterpartyEmail($raw) : '';

            $matches = [];
            $invoice = null;
            foreach ((new BankSyncMatchManager($this->db, $entity))->getForTransaction((int) $tx->rowid) as $match) {
                $matches[] = [
                    'id' => (int) $match->rowid,
                    'target_type' => (string) $match->target_type,
                    'target_id' => (int) $match->target_id,
                    'allocated_amount' => (string) $match->allocated_amount,
                    'confidence' => (int) $match->confidence,
                    'method' => (string) $match->match_method,
                    'status' => (string) $match->status,
                ];
                if (null === $invoice && BankSyncMatchManager::TARGET_SUPPLIER_INVOICE === (string) $match->target_type && \in_array((string) $match->status, ['confirmed', 'posted'], true)) {
                    $supplierInvoice = new FactureFournisseur($this->db);
                    if ($supplierInvoice->fetch((int) $match->target_id) > 0) {
                        $invoice = ['id' => (int) $supplierInvoice->id, 'ref' => (string) $supplierInvoice->ref];
                    }
                }
            }

            $decision = null;
            $resql = $this->db->query('SELECT decision, reason, detail FROM '.$this->db->prefix().'banksync_autopost WHERE entity = '.$entity.' AND fk_transaction = '.(int) $tx->rowid);
            if ($resql && ($row = $this->db->fetch_object($resql))) {
                $decision = ['decision' => (string) $row->decision, 'reason' => (string) $row->reason, 'detail' => (string) $row->detail];
            }

            $belege = [];
            foreach ((new BankSyncBelegStore())->files(BankSyncBelegStore::transactionPart((int) $tx->rowid)) as $file) {
                $belege[] = (string) $file['name'];
            }

            return [
                'id' => (int) $tx->rowid,
                'provider' => (string) $tx->provider,
                'booking_date' => (string) $tx->booking_date,
                'direction' => (string) $tx->direction,
                'amount' => (string) $tx->amount,
                'currency' => (string) $tx->currency,
                'transaction_code' => (string) $tx->transaction_code,
                'counterparty_name' => (string) $tx->counterparty_name,
                'counterparty_email' => $email,
                'reference' => (string) $tx->reference,
                'external_transaction_id' => (string) $tx->external_transaction_id,
                'bank_event_type' => (string) $tx->bank_event_type,
                'status' => (string) $tx->status,
                'decision' => $decision,
                'matches' => $matches,
                'belege' => $belege,
                'invoice' => $invoice,
                'invoice_defaults' => BankSyncPurchase::invoiceDefaults($tx),
                'supplier' => 'debit' === (string) $tx->direction ? (new BankSyncSupplierInvoiceFactory($this->db, $entity))->resolveSupplier((string) $tx->counterparty_name, $email) : null,
            ];
        });
    }

    /**
     * Create the supplier invoice of an outgoing payment, as the quick-create form does, and post
     * the payment when asked. Omitted fields get the values GET transactions/{id} shows.
     *
     * @param int   $id           transaction id
     * @param array $request_data {@from body} socid, new_thirdparty, email, ref_supplier, label, vat_rate, post (all optional)
     *
     * @return array<string, mixed> invoice_id, invoice_ref, posted and bank_line_id
     *
     * @url POST transactions/{id}/supplierinvoice
     *
     * @throws RestException 400
     * @throws RestException 403
     * @throws RestException 404
     * @throws RestException 409
     */
    public function createSupplierInvoice($id, $request_data = null)
    {
        $input = (array) $request_data;
        $rights = [['banksync', 'import'], ['fournisseur', 'facture', 'creer']];
        if (!empty($input['post'])) {
            $rights[] = ['banksync', 'post'];
        }

        return $this->call($rights, function (int $entity) use ($id, $input) {
            $tx = $this->transaction($entity, (int) $id);

            return (new BankSyncSupplierInvoiceFactory($this->db, $entity))->createForTransaction($tx, $input, DolibarrApiAccess::$user);
        });
    }

    /**
     * Run the auto-post policy on one transaction now and return its decision. A dry run, the
     * default, records what would happen. Without it the transaction is posted where the policy
     * allows, whether or not automatic posting is switched on, because a person asks for it.
     *
     * @param int   $id           transaction id
     * @param array $request_data {@from body} dry_run (bool, default true)
     *
     * @return array<string, mixed> decision, reason and detail
     *
     * @url POST transactions/{id}/autopost
     *
     * @throws RestException 403
     * @throws RestException 404
     */
    public function autopostTransaction($id, $request_data = null)
    {
        $input = (array) $request_data;
        $dryRun = !\array_key_exists('dry_run', $input) || filter_var($input['dry_run'], FILTER_VALIDATE_BOOLEAN);

        return $this->call([['banksync', 'post']], function (int $entity) use ($id, $dryRun) {
            return (new BankSyncAutoPoster($this->db, $entity))->processOne((int) $id, DolibarrApiAccess::$user, $dryRun);
        });
    }

    /**
     * Mark a staged transaction as ignored, so it is never booked. Ignoring an ignored
     * transaction changes nothing.
     *
     * @param int   $id           transaction id (rowid of llx_banksync_transaction)
     * @param array $request_data {@from body} note, why the transaction is ignored (required)
     *
     * @return array<string, mixed> action (ignored or unchanged), id and status
     *
     * @url POST transactions/{id}/ignore
     *
     * @throws RestException 400
     * @throws RestException 403
     * @throws RestException 404
     * @throws RestException 409
     */
    public function ignoreTransaction($id, $request_data = null)
    {
        return $this->call([['banksync', 'post']], function (int $entity) use ($id, $request_data) {
            return (new BankSyncTransactionIgnorer($this->db, $entity))
                ->ignore((int) $id, DolibarrApiAccess::$user, (string) (((array) $request_data)['note'] ?? ''));
        });
    }

    /**
     * @throws OutOfBoundsException when the transaction does not exist in this entity
     */
    private function transaction(int $entity, int $id)
    {
        $tx = (new BankSyncCandidateMatcher($this->db, $entity))->fetchTransaction($id);
        if (null === $tx) {
            throw new OutOfBoundsException(sprintf('Transaction %d not found.', $id));
        }

        return $tx;
    }

    /**
     * Checks the rights, runs the operation and maps its exceptions onto HTTP status codes.
     *
     * @param array<int, string[]> $rights Arguments for User::hasRight(), all required
     */
    private function call(array $rights, callable $operation)
    {
        global $conf;

        if (!isModEnabled('banksync')) {
            throw new RestException(501, 'Module BankSync is not enabled');
        }
        foreach ($rights as $right) {
            if (!DolibarrApiAccess::$user->hasRight(...$right)) {
                throw new RestException(403, 'Missing right '.implode(' ', $right));
            }
        }

        try {
            return $operation((int) $conf->entity);
        } catch (OutOfBoundsException $e) {
            throw new RestException(404, $e->getMessage());
        } catch (DomainException $e) {
            throw new RestException(409, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            throw new RestException(400, $e->getMessage());
        }
    }
}
