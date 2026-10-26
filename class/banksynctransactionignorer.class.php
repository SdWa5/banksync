<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

/**
 * Marks a staged transaction as ignored, for rows that must never be booked, such as the release of
 * a PayPal hold that a release before 1.2.1 staged.
 *
 * Ignoring is refused for anything that already reached the books or is about to: a posted row,
 * a row with a bank line, a posting in progress or a confirmed match. Those have to be undone
 * first. The decision is recorded in `banksync_autopost` like an automatic one, with the person and
 * the note, so the transactions list shows why the row was ignored.
 */
class BankSyncTransactionIgnorer
{
    public const STATUS_IGNORED = 'ignored';
    public const DECISION_IGNORED = 'ignored';
    public const REASON = 'BankSyncIgnoredByPerson';

    /** @var DoliDB */
    private $db;

    /** @var int */
    private $entity;

    /**
     * @param DoliDB $db
     */
    public function __construct($db, int $entity)
    {
        $this->db = $db;
        $this->entity = $entity;
    }

    /**
     * @return array{action: string, id: int, status: string} action is `ignored` or `unchanged`
     *
     * @throws OutOfBoundsException     when the transaction does not exist in this entity
     * @throws DomainException          when the transaction cannot be ignored, see refusal()
     * @throws InvalidArgumentException when the note is empty
     */
    public function ignore(int $id, User $user, string $note): array
    {
        $note = trim($note);
        if ('' === $note) {
            throw new InvalidArgumentException('A note saying why the transaction is ignored is required.');
        }

        $facts = $this->facts($id);
        if (null === $facts) {
            throw new OutOfBoundsException(sprintf('Transaction %d not found.', $id));
        }
        if (self::STATUS_IGNORED === $facts['status']) {
            return ['action' => 'unchanged', 'id' => $id, 'status' => self::STATUS_IGNORED];
        }

        $refusal = self::refusal($facts);
        if (null !== $refusal) {
            throw new DomainException($refusal);
        }

        $this->db->begin();
        try {
            // The status in the WHERE clause guards against a posting that started since facts().
            $sql = 'UPDATE '.$this->db->prefix().'banksync_transaction';
            $sql .= " SET status = '".self::STATUS_IGNORED."'";
            $sql .= ' WHERE rowid = '.$id.' AND entity = '.$this->entity;
            $sql .= " AND status = '".$this->db->escape($facts['status'])."' AND (fk_bank IS NULL OR fk_bank = 0)";
            $resql = $this->db->query($sql);
            if (!$resql) {
                throw new RuntimeException($this->db->lasterror());
            }
            if (1 !== (int) $this->db->affected_rows($resql)) {
                throw new DomainException(sprintf('Transaction %d changed while it was being ignored.', $id));
            }

            $now = "'".$this->db->idate(dol_now())."'";
            $sql = 'INSERT INTO '.$this->db->prefix().'banksync_autopost (entity, fk_transaction, decision, reason, detail, notified, date_creation, date_decision, fk_user)';
            $sql .= ' VALUES ('.$this->entity.', '.$id.", '".self::DECISION_IGNORED."', '".self::REASON."', '".$this->db->escape($note)."', 1, ".$now.', '.$now.', '.((int) $user->id).')';
            $sql .= ' ON DUPLICATE KEY UPDATE decision = VALUES(decision), reason = VALUES(reason), detail = VALUES(detail), notified = 1, date_decision = VALUES(date_decision), fk_user = VALUES(fk_user)';
            if (!$this->db->query($sql)) {
                throw new RuntimeException($this->db->lasterror());
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();

            throw $e;
        }

        return ['action' => 'ignored', 'id' => $id, 'status' => self::STATUS_IGNORED];
    }

    /**
     * Tells why a transaction must not be ignored, or null when it may.
     *
     * @param array{status: string, fk_bank: int, open_postings: int, confirmed_matches: int} $facts
     */
    public static function refusal(array $facts): ?string
    {
        if ('posted' === $facts['status'] || $facts['fk_bank'] > 0) {
            return 'The transaction is already posted. Undo the bank line first.';
        }
        if ($facts['open_postings'] > 0) {
            return 'A posting of the transaction exists. Remove it first.';
        }
        if ($facts['confirmed_matches'] > 0) {
            return 'The transaction has confirmed matches. Unconfirm them first.';
        }

        return null;
    }

    /**
     * @return array{status: string, fk_bank: int, open_postings: int, confirmed_matches: int}|null
     */
    private function facts(int $id): ?array
    {
        $prefix = $this->db->prefix();
        $sql = 'SELECT t.status, t.fk_bank,';
        $sql .= " (SELECT COUNT(*) FROM {$prefix}banksync_posting AS p WHERE p.fk_transaction = t.rowid AND p.entity = t.entity AND p.status IN ('processing', 'posted')) AS open_postings,";
        $sql .= " (SELECT COUNT(*) FROM {$prefix}banksync_match AS m WHERE m.fk_transaction = t.rowid AND m.entity = t.entity AND m.status IN ('confirmed', 'posted')) AS confirmed_matches";
        $sql .= " FROM {$prefix}banksync_transaction AS t WHERE t.rowid = ".$id.' AND t.entity = '.$this->entity;

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RuntimeException($this->db->lasterror());
        }
        $row = $this->db->fetch_object($resql);
        $this->db->free($resql);
        if (!$row) {
            return null;
        }

        return [
            'status' => (string) $row->status,
            'fk_bank' => (int) $row->fk_bank,
            'open_postings' => (int) $row->open_postings,
            'confirmed_matches' => (int) $row->confirmed_matches,
        ];
    }
}
