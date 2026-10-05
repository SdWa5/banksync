<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

require_once __DIR__.'/banksyncmatchmanager.class.php';

/**
 * Broad manual target search for reconciliation.
 *
 * Automatic matching is intentionally conservative. This search is the escape hatch for
 * cases where a human knows the relation but bank text/merchant data is insufficient.
 */
class BankSyncManualSearch
{
    /** @var DoliDB */
    private $db;
    /** @var int */
    private $entity;

    public function __construct($db, $entity)
    {
        $this->db = $db;
        $this->entity = (int) $entity;
    }

    /** @return array<int,array<string,mixed>> */
    public function search($transaction, $targetType, $query = '', $limit = 30)
    {
        $limit = max(1, min(100, (int) $limit));
        switch ((string) $targetType) {
            case BankSyncMatchManager::TARGET_CUSTOMER_INVOICE:
                return $this->searchCustomerInvoices($transaction, $query, $limit);
            case BankSyncMatchManager::TARGET_SUPPLIER_INVOICE:
                return $this->searchSupplierInvoices($transaction, $query, $limit);
            case BankSyncMatchManager::TARGET_SALARY:
                return $this->searchSalaries($transaction, $query, $limit);
            case BankSyncMatchManager::TARGET_SOCIAL_CONTRIBUTION:
                return $this->searchSocialContributions($transaction, $query, $limit);
            case BankSyncMatchManager::TARGET_VAT:
                return $this->searchVatDeclarations($transaction, $query, $limit);
            case BankSyncMatchManager::TARGET_INTERNAL_TRANSFER:
                return $this->bankAccountRows($transaction, $this->textFilter($query, array('ref', 'label')), $limit);
            default:
                return array();
        }
    }

    /** @return array<string,mixed>|null */
    public function fetchTarget($targetType, $targetId)
    {
        $targetId = (int) $targetId;
        if ($targetId <= 0) return null;

        switch ((string) $targetType) {
            case BankSyncMatchManager::TARGET_CUSTOMER_INVOICE:
                $sql = 'SELECT f.rowid, f.ref, f.ref_client, f.datef AS target_date, f.total_ttc, f.fk_soc, s.nom AS target_label,';
                $sql .= ' COALESCE((SELECT SUM(pf.amount) FROM '.$this->db->prefix().'paiement_facture AS pf WHERE pf.fk_facture = f.rowid), 0) AS paid_amount';
                $sql .= ' FROM '.$this->db->prefix().'facture AS f INNER JOIN '.$this->db->prefix().'societe AS s ON s.rowid = f.fk_soc';
                $sql .= ' WHERE f.entity = '.$this->entity.' AND f.rowid = '.$targetId.' LIMIT 1';
                return $this->fetchInvoiceTarget($sql, BankSyncMatchManager::TARGET_CUSTOMER_INVOICE, '/compta/facture/card.php?facid=', 'ref_client');

            case BankSyncMatchManager::TARGET_SUPPLIER_INVOICE:
                $sql = 'SELECT f.rowid, f.ref, f.ref_supplier, f.datef AS target_date, f.total_ttc, f.fk_soc, s.nom AS target_label,';
                $sql .= ' COALESCE((SELECT SUM(pf.amount) FROM '.$this->db->prefix().'paiementfourn_facturefourn AS pf WHERE pf.fk_facturefourn = f.rowid), 0) AS paid_amount';
                $sql .= ' FROM '.$this->db->prefix().'facture_fourn AS f INNER JOIN '.$this->db->prefix().'societe AS s ON s.rowid = f.fk_soc';
                $sql .= ' WHERE f.entity = '.$this->entity.' AND f.rowid = '.$targetId.' LIMIT 1';
                return $this->fetchInvoiceTarget($sql, BankSyncMatchManager::TARGET_SUPPLIER_INVOICE, '/fourn/facture/card.php?facid=', 'ref_supplier');

            case BankSyncMatchManager::TARGET_INTERNAL_TRANSFER:
                $rows = $this->bankAccountRows(null, ' AND rowid = '.$targetId, 1, false);
                return $rows ? $rows[0] : null;

            case BankSyncMatchManager::TARGET_SALARY:
                $sql = 'SELECT s.rowid, s.ref, s.datep AS payment_date, s.datesp AS period_start, s.dateep AS period_end, s.amount, s.label, u.firstname, u.lastname,';
                $sql .= ' COALESCE((SELECT SUM(ps.amount) FROM '.$this->db->prefix().'payment_salary AS ps WHERE ps.fk_salary = s.rowid), 0) AS paid_amount';
                $sql .= ' FROM '.$this->db->prefix().'salary AS s LEFT JOIN '.$this->db->prefix().'user AS u ON u.rowid = s.fk_user';
                $sql .= ' WHERE s.entity = '.$this->entity.' AND s.rowid = '.$targetId.' LIMIT 1';
                $resql = $this->db->query($sql);
                if (!$resql) return null;
                $obj = $this->db->fetch_object($resql); $this->db->free($resql);
                if (!$obj) return null;
                $remaining = max(0, (float) $obj->amount - (float) $obj->paid_amount);
                $name = trim((string) $obj->firstname.' '.(string) $obj->lastname);
                return array(
                    'target_type' => BankSyncMatchManager::TARGET_SALARY,
                    'target_id' => (int) $obj->rowid,
                    'ref' => trim((string) $obj->ref) !== '' ? (string) $obj->ref : '#'.(int) $obj->rowid,
                    'label' => $name.($obj->label ? ' — '.(string) $obj->label : ''),
                    'date' => $this->salaryPeriodLabel((string) $obj->period_start, (string) $obj->period_end, (string) $obj->payment_date),
                    'period_start' => (string) $obj->period_start,
                    'period_end' => (string) $obj->period_end,
                    'remaining_amount' => $this->decimal($remaining),
                    'url' => '/salaries/card.php?id='.(int) $obj->rowid,
                );

            case BankSyncMatchManager::TARGET_SOCIAL_CONTRIBUTION:
                $sql = 'SELECT c.rowid, c.ref, c.date_ech AS target_date, c.amount, c.libelle, cc.libelle AS type_label,';
                $sql .= ' COALESCE((SELECT SUM(pc.amount) FROM '.$this->db->prefix().'paiementcharge AS pc WHERE pc.fk_charge = c.rowid), 0) AS paid_amount';
                $sql .= ' FROM '.$this->db->prefix().'chargesociales AS c LEFT JOIN '.$this->db->prefix().'c_chargesociales AS cc ON cc.id = c.fk_type';
                $sql .= ' WHERE c.entity = '.$this->entity.' AND c.rowid = '.$targetId.' LIMIT 1';
                $resql = $this->db->query($sql);
                if (!$resql) return null;
                $obj = $this->db->fetch_object($resql); $this->db->free($resql);
                if (!$obj) return null;
                $remaining = max(0, (float) $obj->amount - (float) $obj->paid_amount);
                return array(
                    'target_type' => BankSyncMatchManager::TARGET_SOCIAL_CONTRIBUTION,
                    'target_id' => (int) $obj->rowid,
                    'ref' => trim((string) $obj->ref) !== '' ? (string) $obj->ref : '#'.(int) $obj->rowid,
                    'label' => trim((string) $obj->libelle.($obj->type_label ? ' — '.(string) $obj->type_label : '')),
                    'date' => (string) $obj->target_date,
                    'remaining_amount' => $this->decimal($remaining),
                    'url' => '/compta/sociales/card.php?id='.(int) $obj->rowid,
                );

            case BankSyncMatchManager::TARGET_VAT:
                $sql = 'SELECT v.rowid, v.datev AS target_date, v.amount, v.label,';
                $sql .= ' COALESCE((SELECT SUM(pv.amount) FROM '.$this->db->prefix().'payment_vat AS pv WHERE pv.fk_tva = v.rowid), 0) AS paid_amount';
                $sql .= ' FROM '.$this->db->prefix().'tva AS v';
                $sql .= ' WHERE v.entity = '.$this->entity.' AND v.rowid = '.$targetId.' LIMIT 1';
                $resql = $this->db->query($sql);
                if (!$resql) return null;
                $obj = $this->db->fetch_object($resql); $this->db->free($resql);
                if (!$obj) return null;
                $remaining = max(0, (float) $obj->amount - (float) $obj->paid_amount);
                return array(
                    'target_type' => BankSyncMatchManager::TARGET_VAT,
                    'target_id' => (int) $obj->rowid,
                    'ref' => '#'.(int) $obj->rowid,
                    'label' => trim((string) $obj->label) !== '' ? (string) $obj->label : 'VAT',
                    'date' => (string) $obj->target_date,
                    'remaining_amount' => $this->decimal($remaining),
                    'url' => '/compta/tva/card.php?id='.(int) $obj->rowid,
                );
        }
        return null;
    }

    private function searchCustomerInvoices($transaction, $query, $limit)
    {
        $sql = 'SELECT f.rowid, f.ref, f.ref_client, f.datef AS target_date, f.total_ttc, f.fk_soc, s.nom AS target_label,';
        $sql .= ' COALESCE((SELECT SUM(pf.amount) FROM '.$this->db->prefix().'paiement_facture AS pf WHERE pf.fk_facture = f.rowid), 0) AS paid_amount';
        $sql .= ' FROM '.$this->db->prefix().'facture AS f INNER JOIN '.$this->db->prefix().'societe AS s ON s.rowid = f.fk_soc';
        $sql .= ' WHERE f.entity = '.$this->entity.' AND f.paye = 0 AND f.fk_statut = 1';
        $sql .= $this->textFilter($query, array('f.ref', 'f.ref_client', 's.nom'));
        $sql .= ' ORDER BY f.datef DESC'.$this->db->plimit(200, 0);
        return $this->invoiceRows($sql, $transaction, BankSyncMatchManager::TARGET_CUSTOMER_INVOICE, '/compta/facture/card.php?facid=', 'ref_client', $limit);
    }

    private function searchSupplierInvoices($transaction, $query, $limit)
    {
        $sql = 'SELECT f.rowid, f.ref, f.ref_supplier, f.datef AS target_date, f.total_ttc, f.fk_soc, s.nom AS target_label,';
        $sql .= ' COALESCE((SELECT SUM(pf.amount) FROM '.$this->db->prefix().'paiementfourn_facturefourn AS pf WHERE pf.fk_facturefourn = f.rowid), 0) AS paid_amount';
        $sql .= ' FROM '.$this->db->prefix().'facture_fourn AS f INNER JOIN '.$this->db->prefix().'societe AS s ON s.rowid = f.fk_soc';
        $sql .= ' WHERE f.entity = '.$this->entity.' AND f.paye = 0 AND f.fk_statut = 1';
        $sql .= $this->textFilter($query, array('f.ref', 'f.ref_supplier', 's.nom'));
        $sql .= ' ORDER BY f.datef DESC'.$this->db->plimit(200, 0);
        return $this->invoiceRows($sql, $transaction, BankSyncMatchManager::TARGET_SUPPLIER_INVOICE, '/fourn/facture/card.php?facid=', 'ref_supplier', $limit);
    }

    private function invoiceRows($sql, $transaction, $targetType, $urlPrefix, $preferredRefField, $limit)
    {
        $rows = array();
        $resql = $this->db->query($sql);
        if (!$resql) return $rows;
        $bankAmount = abs((float) $transaction->amount);
        while ($obj = $this->db->fetch_object($resql)) {
            $remaining = max(0, (float) $obj->total_ttc - (float) $obj->paid_amount);
            if ($remaining <= 0.00001) continue;
            $preferred = isset($obj->{$preferredRefField}) ? trim((string) $obj->{$preferredRefField}) : '';
            $ref = $preferred !== '' ? $preferred : (string) $obj->ref;
            $rows[] = array(
                'target_type' => $targetType,
                'target_id' => (int) $obj->rowid,
                'ref' => $ref,
                'label' => (string) $obj->target_label,
                'date' => (string) $obj->target_date,
                'remaining_amount' => $this->decimal($remaining),
                'suggested_allocation' => $this->decimal(min($bankAmount, $remaining)),
                'distance' => abs($remaining - $bankAmount),
                'url' => $urlPrefix.(int) $obj->rowid,
            );
        }
        $this->db->free($resql);
        usort($rows, function ($a, $b) {
            if ((float) $a['distance'] == (float) $b['distance']) return strcmp((string) $b['date'], (string) $a['date']);
            return ((float) $a['distance'] < (float) $b['distance']) ? -1 : 1;
        });
        return array_slice($rows, 0, $limit);
    }

    private function fetchInvoiceTarget($sql, $targetType, $urlPrefix, $preferredRefField)
    {
        $resql = $this->db->query($sql);
        if (!$resql) return null;
        $obj = $this->db->fetch_object($resql); $this->db->free($resql);
        if (!$obj) return null;
        $remaining = max(0, (float) $obj->total_ttc - (float) $obj->paid_amount);
        $preferred = isset($obj->{$preferredRefField}) ? trim((string) $obj->{$preferredRefField}) : '';
        return array(
            'target_type' => $targetType,
            'target_id' => (int) $obj->rowid,
            'ref' => $preferred !== '' ? $preferred : (string) $obj->ref,
            'label' => (string) $obj->target_label,
            'date' => (string) $obj->target_date,
            'remaining_amount' => $this->decimal($remaining),
            'url' => $urlPrefix.(int) $obj->rowid,
        );
    }

    /**
     * The books' other bank accounts as transfer targets. A transfer always takes the whole amount,
     * so the remaining amount shown is the transaction's.
     *
     * @return array<int,array<string,mixed>>
     */
    private function bankAccountRows($transaction, $filter, $limit, $openOnly = true)
    {
        $sql = 'SELECT rowid, ref, label FROM '.$this->db->prefix().'bank_account';
        $sql .= ' WHERE entity = '.$this->entity.$filter;
        if ($openOnly) $sql .= ' AND clos = 0';
        if ($transaction !== null && !empty($transaction->fk_bank_account)) $sql .= ' AND rowid <> '.((int) $transaction->fk_bank_account);
        $sql .= ' ORDER BY label ASC'.$this->db->plimit((int) $limit, 0);
        $rows = array();
        $resql = $this->db->query($sql);
        if (!$resql) return $rows;
        $amount = $transaction !== null ? abs((float) $transaction->amount) : 0.0;
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = array(
                'target_type' => BankSyncMatchManager::TARGET_INTERNAL_TRANSFER,
                'target_id' => (int) $obj->rowid,
                'ref' => (string) $obj->ref,
                'label' => (string) $obj->label,
                'date' => '',
                'remaining_amount' => $this->decimal($amount),
                'url' => '/compta/bank/card.php?id='.(int) $obj->rowid,
            );
        }
        $this->db->free($resql);
        return $rows;
    }

    private function searchSalaries($transaction, $query, $limit)
    {
        $sql = 'SELECT s.rowid, s.ref, s.datep AS payment_date, s.datesp AS period_start, s.dateep AS period_end, s.amount, s.label, u.firstname, u.lastname,';
        $sql .= ' COALESCE((SELECT SUM(ps.amount) FROM '.$this->db->prefix().'payment_salary AS ps WHERE ps.fk_salary = s.rowid), 0) AS paid_amount';
        $sql .= ' FROM '.$this->db->prefix().'salary AS s LEFT JOIN '.$this->db->prefix().'user AS u ON u.rowid = s.fk_user';
        $sql .= ' WHERE s.entity = '.$this->entity.' AND s.paye = 0';
        $sql .= $this->textFilter($query, array('s.ref', 's.label', 'u.firstname', 'u.lastname'));
        $sql .= ' ORDER BY COALESCE(s.dateep, s.datesp, s.datep) DESC, s.rowid DESC'.$this->db->plimit(200, 0);
        $resql = $this->db->query($sql);
        if (!$resql) return array();
        $rows = array(); $bankAmount = abs((float) $transaction->amount);
        while ($obj = $this->db->fetch_object($resql)) {
            $remaining = max(0, (float) $obj->amount - (float) $obj->paid_amount);
            if ($remaining <= 0.00001) continue;
            $name = trim((string) $obj->firstname.' '.(string) $obj->lastname);
            $periodDate = trim((string) $obj->period_end) !== '' ? (string) $obj->period_end : (trim((string) $obj->period_start) !== '' ? (string) $obj->period_start : (string) $obj->payment_date);
            $rows[] = array(
                'target_type' => BankSyncMatchManager::TARGET_SALARY,
                'target_id' => (int) $obj->rowid,
                'ref' => trim((string) $obj->ref) !== '' ? (string) $obj->ref : '#'.(int) $obj->rowid,
                'label' => $name.($obj->label ? ' — '.(string) $obj->label : ''),
                'date' => $this->salaryPeriodLabel((string) $obj->period_start, (string) $obj->period_end, (string) $obj->payment_date),
                'period_start' => (string) $obj->period_start,
                'period_end' => (string) $obj->period_end,
                'remaining_amount' => $this->decimal($remaining),
                'suggested_allocation' => $this->decimal(min($bankAmount, $remaining)),
                'distance' => abs($remaining - $bankAmount),
                'date_distance' => $this->dayDistance((string) $transaction->booking_date, $periodDate),
                'url' => '/salaries/card.php?id='.(int) $obj->rowid,
            );
        }
        $this->db->free($resql);
        usort($rows, function ($a, $b) {
            if ((float) $a['distance'] != (float) $b['distance']) return ((float) $a['distance'] < (float) $b['distance']) ? -1 : 1;
            $da = $a['date_distance'] === null ? PHP_INT_MAX : (int) $a['date_distance'];
            $db = $b['date_distance'] === null ? PHP_INT_MAX : (int) $b['date_distance'];
            return $da <=> $db;
        });
        return array_slice($rows, 0, $limit);
    }

    private function searchSocialContributions($transaction, $query, $limit)
    {
        $sql = 'SELECT c.rowid, c.ref, c.date_ech AS target_date, c.amount, c.libelle, cc.libelle AS type_label,';
        $sql .= ' COALESCE((SELECT SUM(pc.amount) FROM '.$this->db->prefix().'paiementcharge AS pc WHERE pc.fk_charge = c.rowid), 0) AS paid_amount';
        $sql .= ' FROM '.$this->db->prefix().'chargesociales AS c LEFT JOIN '.$this->db->prefix().'c_chargesociales AS cc ON cc.id = c.fk_type';
        $sql .= ' WHERE c.entity = '.$this->entity.' AND c.paye = 0';
        $sql .= $this->textFilter($query, array('c.ref', 'c.libelle', 'cc.libelle'));
        $sql .= ' ORDER BY c.date_ech DESC'.$this->db->plimit(200, 0);
        $resql = $this->db->query($sql);
        if (!$resql) return array();
        $rows = array(); $bankAmount = abs((float) $transaction->amount);
        while ($obj = $this->db->fetch_object($resql)) {
            $remaining = max(0, (float) $obj->amount - (float) $obj->paid_amount);
            if ($remaining <= 0.00001) continue;
            $rows[] = array(
                'target_type' => BankSyncMatchManager::TARGET_SOCIAL_CONTRIBUTION,
                'target_id' => (int) $obj->rowid,
                'ref' => trim((string) $obj->ref) !== '' ? (string) $obj->ref : '#'.(int) $obj->rowid,
                'label' => trim((string) $obj->libelle.($obj->type_label ? ' — '.(string) $obj->type_label : '')),
                'date' => (string) $obj->target_date,
                'remaining_amount' => $this->decimal($remaining),
                'suggested_allocation' => $this->decimal(min($bankAmount, $remaining)),
                'distance' => abs($remaining - $bankAmount),
                'url' => '/compta/sociales/card.php?id='.(int) $obj->rowid,
            );
        }
        $this->db->free($resql);
        usort($rows, function ($a, $b) { return $a['distance'] <=> $b['distance']; });
        return array_slice($rows, 0, $limit);
    }

    private function searchVatDeclarations($transaction, $query, $limit)
    {
        $sql = 'SELECT v.rowid, v.datev AS target_date, v.amount, v.label,';
        $sql .= ' COALESCE((SELECT SUM(pv.amount) FROM '.$this->db->prefix().'payment_vat AS pv WHERE pv.fk_tva = v.rowid), 0) AS paid_amount';
        $sql .= ' FROM '.$this->db->prefix().'tva AS v';
        $sql .= ' WHERE v.entity = '.$this->entity.' AND v.paye = 0';
        $query = trim((string) $query);
        if ($query !== '') {
            $escaped = $this->db->escape($query);
            $conditions = array("v.label LIKE '%".$escaped."%'");
            if (ctype_digit($query)) $conditions[] = 'v.rowid = '.((int) $query);
            $sql .= ' AND ('.implode(' OR ', $conditions).')';
        }
        $sql .= ' ORDER BY v.datev DESC, v.rowid DESC'.$this->db->plimit(200, 0);
        $resql = $this->db->query($sql);
        if (!$resql) return array();
        $rows = array(); $bankAmount = abs((float) $transaction->amount);
        while ($obj = $this->db->fetch_object($resql)) {
            $remaining = max(0, (float) $obj->amount - (float) $obj->paid_amount);
            if ($remaining <= 0.00001) continue;
            $rows[] = array(
                'target_type' => BankSyncMatchManager::TARGET_VAT,
                'target_id' => (int) $obj->rowid,
                'ref' => '#'.(int) $obj->rowid,
                'label' => trim((string) $obj->label) !== '' ? (string) $obj->label : 'VAT',
                'date' => (string) $obj->target_date,
                'remaining_amount' => $this->decimal($remaining),
                'suggested_allocation' => $this->decimal(min($bankAmount, $remaining)),
                'distance' => abs($remaining - $bankAmount),
                'date_distance' => $this->dayDistance((string) $transaction->booking_date, (string) $obj->target_date),
                'url' => '/compta/tva/card.php?id='.(int) $obj->rowid,
            );
        }
        $this->db->free($resql);
        usort($rows, function ($a, $b) {
            if ((float) $a['distance'] != (float) $b['distance']) return ((float) $a['distance'] < (float) $b['distance']) ? -1 : 1;
            $da = $a['date_distance'] === null ? PHP_INT_MAX : (int) $a['date_distance'];
            $db = $b['date_distance'] === null ? PHP_INT_MAX : (int) $b['date_distance'];
            return $da <=> $db;
        });
        return array_slice($rows, 0, $limit);
    }

    private function salaryPeriodLabel($start, $end, $fallback = '')
    {
        $start = trim((string) $start); $end = trim((string) $end);
        if ($start !== '' && $end !== '') return $start.' – '.$end;
        if ($start !== '') return $start;
        if ($end !== '') return $end;
        return trim((string) $fallback);
    }

    private function dayDistance($dateA, $dateB)
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $dateA) || !preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $dateB)) return null;
        $a = strtotime(substr((string) $dateA, 0, 10)); $b = strtotime(substr((string) $dateB, 0, 10));
        if ($a === false || $b === false) return null;
        return (int) floor(abs($a - $b) / 86400);
    }

    private function textFilter($query, array $fields)
    {
        $query = trim((string) $query);
        if ($query === '') return '';
        $needle = '%'.$this->db->escape($query).'%';
        $parts = array();
        foreach ($fields as $field) $parts[] = $field." LIKE '".$needle."'";
        return ' AND ('.implode(' OR ', $parts).')';
    }

    private function decimal($value)
    {
        return number_format((float) $value, 8, '.', '');
    }
}
