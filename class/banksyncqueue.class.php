<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

/**
 * The one definition of the queue: what waits for a person.
 *
 * A transaction is in the queue when the auto-poster's latest decision kept it for a person and it
 * is neither posted nor ignored, or when it is an outgoing payment with a supplier invoice but
 * without any Beleg. A Beleg counts once Dolibarr's file index (`ecm_files`) holds a file of the
 * transaction's Belege directory or an uploaded, not generated, file of the matched supplier
 * invoice, so an upload on the invoice card or through the core documents API counts as well.
 *
 * The SQL fragments expect the transaction as `$t` and a LEFT JOIN of `banksync_autopost` as `$a`.
 */
class BankSyncQueue
{
    public const REASON_BELEG_MISSING = 'BankSyncQueueBelegMissing';

    /**
     * @param string $prefix Table prefix, $db->prefix()
     */
    public static function condition(string $prefix, string $t = 't', string $a = 'a'): string
    {
        return '('.self::decided($t, $a).' OR ('.self::belegMissing($prefix, $t).'))';
    }

    /**
     * The reason to show: the auto-poster's, or that the Beleg is missing.
     */
    public static function reason(string $t = 't', string $a = 'a'): string
    {
        return 'CASE WHEN '.self::decided($t, $a)." THEN $a.reason ELSE '".self::REASON_BELEG_MISSING."' END";
    }

    public static function join(string $prefix, string $t = 't', string $a = 'a'): string
    {
        return ' LEFT JOIN '.$prefix."banksync_autopost AS $a ON $a.fk_transaction = $t.rowid AND $a.entity = $t.entity";
    }

    /**
     * @param DoliDB $db
     * @param string $extra Further SQL condition, for example on `a.notified`
     */
    public static function count($db, int $entity, string $extra = '1 = 1'): int
    {
        $sql = 'SELECT COUNT(*) AS n FROM '.$db->prefix().'banksync_transaction AS t'.self::join($db->prefix());
        $sql .= ' WHERE t.entity = '.$entity.' AND '.self::condition($db->prefix()).' AND ('.$extra.')';
        $resql = $db->query($sql);
        if (!$resql) {
            throw new RuntimeException($db->lasterror());
        }
        $obj = $db->fetch_object($resql);
        $db->free($resql);

        return (int) $obj->n;
    }

    /**
     * @param DoliDB $db
     *
     * @return array<int, array{id: int, booking_date: string, counterparty_name: string, amount: string, currency: string, reason: string}> Oldest first
     */
    public static function items($db, int $entity, int $limit = 100): array
    {
        $sql = 'SELECT t.rowid, t.booking_date, t.counterparty_name, t.amount, t.currency, '.self::reason().' AS reason';
        $sql .= ' FROM '.$db->prefix().'banksync_transaction AS t'.self::join($db->prefix());
        $sql .= ' WHERE t.entity = '.$entity.' AND '.self::condition($db->prefix());
        $sql .= ' ORDER BY t.booking_date ASC, t.rowid ASC'.$db->plimit(max(1, $limit), 0);
        $resql = $db->query($sql);
        if (!$resql) {
            throw new RuntimeException($db->lasterror());
        }
        $items = [];
        while ($obj = $db->fetch_object($resql)) {
            $items[] = [
                'id' => (int) $obj->rowid,
                'booking_date' => (string) $obj->booking_date,
                'counterparty_name' => (string) $obj->counterparty_name,
                'amount' => (string) $obj->amount,
                'currency' => (string) $obj->currency,
                'reason' => (string) $obj->reason,
            ];
        }
        $db->free($resql);

        return $items;
    }

    private static function decided(string $t, string $a): string
    {
        return "($t.status NOT IN ('posted', 'ignored') AND $a.decision IN ('queued', 'error'))";
    }

    private static function belegMissing(string $prefix, string $t): string
    {
        $invoiceMatch = "m.fk_transaction = $t.rowid AND m.entity = $t.entity AND m.target_type = 'supplier_invoice' AND m.status IN ('confirmed', 'posted')";

        return "$t.direction = 'debit' AND $t.status <> 'ignored'"
            .' AND EXISTS (SELECT 1 FROM '.$prefix.'banksync_match AS m WHERE '.$invoiceMatch.')'
            .' AND NOT EXISTS (SELECT 1 FROM '.$prefix."ecm_files AS e WHERE e.entity = $t.entity AND e.filepath = CONCAT('".self::belegPath()."/transaction/', $t.rowid))"
            .' AND NOT EXISTS (SELECT 1 FROM '.$prefix.'ecm_files AS e INNER JOIN '.$prefix.'banksync_match AS m ON m.target_id = e.src_object_id'
            ." WHERE $invoiceMatch AND e.src_object_type = 'facture_fourn' AND COALESCE(e.gen_or_uploaded, '') <> 'generated')";
    }

    /**
     * The module's document directory as the file index stores it, relative to the data root.
     */
    private static function belegPath(): string
    {
        global $conf;

        $dir = isset($conf->banksync->dir_output) ? (string) $conf->banksync->dir_output : '';
        if ('' === $dir || !\defined('DOL_DATA_ROOT')) {
            return 'banksync';
        }

        return str_replace("'", "''", trim((string) preg_replace('/^'.preg_quote(DOL_DATA_ROOT, '/').'/', '', $dir), '/'));
    }
}
