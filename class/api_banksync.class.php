<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

use Luracast\Restler\RestException;

require_once __DIR__.'/banksynctransactionignorer.class.php';

/**
 * BankSync endpoints for decisions a person makes on staged transactions.
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
        global $conf;

        if (!isModEnabled('banksync')) {
            throw new RestException(501, 'Module BankSync is not enabled');
        }
        if (!DolibarrApiAccess::$user->hasRight('banksync', 'post')) {
            throw new RestException(403, 'Posting bank transactions is not permitted');
        }

        try {
            return (new BankSyncTransactionIgnorer($this->db, (int) $conf->entity))
                ->ignore((int) $id, DolibarrApiAccess::$user, (string) (((array) $request_data)['note'] ?? ''));
        } catch (OutOfBoundsException $e) {
            throw new RestException(404, $e->getMessage());
        } catch (DomainException $e) {
            throw new RestException(409, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            throw new RestException(400, $e->getMessage());
        }
    }
}
