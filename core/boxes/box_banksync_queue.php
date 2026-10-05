<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

include_once DOL_DOCUMENT_ROOT.'/core/boxes/modules_boxes.php';

/**
 * Home-page box listing what waits in the Bank Sync queue: open transactions the auto-poster left for
 * a person, and Belege whose payment is not known yet.
 */
class box_banksync_queue extends ModeleBoxes
{
    public $boxcode = 'banksyncqueue';
    public $boximg = 'bank';
    public $boxlabel = 'BankSyncBoxQueue';
    public $depends = ['banksync'];

    /**
     * @param DoliDB $db    Database handler
     * @param string $param More parameters
     */
    public function __construct($db, $param = '')
    {
        global $user;

        $this->db = $db;
        $this->hidden = !$user->hasRight('banksync', 'read');
    }

    /**
     * @param int $max Maximum number of rows
     *
     * @return void
     */
    public function loadBox($max = 5)
    {
        global $langs;

        require_once dol_buildpath('/banksync/class/banksyncschema.class.php', 0);
        require_once dol_buildpath('/banksync/class/banksyncbelegstore.class.php', 0);
        $langs->load('banksync@banksync');
        BankSyncSchema::ensure($this->db);

        $this->max = $max;
        $listUrl = dol_buildpath('/banksync/transactions.php', 1).'?mainmenu=bank&leftmenu=banksync_transactions&filter_status=queue';

        $from = ' FROM '.$this->db->prefix().'banksync_transaction AS t';
        $from .= ' INNER JOIN '.$this->db->prefix().'banksync_autopost AS a ON a.fk_transaction = t.rowid AND a.entity = t.entity';
        $from .= ' WHERE t.entity IN ('.getEntity('banksync').')';
        $from .= " AND t.status IN ('new', 'matched', 'partially_matched') AND a.decision IN ('queued', 'error')";

        $total = 0;
        $resql = $this->db->query('SELECT COUNT(*) AS n'.$from);
        if ($resql && ($obj = $this->db->fetch_object($resql))) {
            $total = (int) $obj->n;
        }

        $this->info_box_head = [
            'text' => $langs->trans('BankSyncBoxQueue').'<a class="paddingleft valignmiddle" href="'.dol_escape_htmltag($listUrl).'"><span class="badge">'.$total.'</span></a>',
            'limit' => dol_strlen($langs->trans('BankSyncBoxQueue')),
        ];

        $line = 0;
        $resql = $this->db->query('SELECT t.rowid, t.booking_date, t.counterparty_name, t.amount, t.currency, a.reason'.$from.' ORDER BY t.booking_date ASC, t.rowid ASC'.$this->db->plimit($max, 0));
        while ($resql && ($obj = $this->db->fetch_object($resql))) {
            $url = dol_buildpath('/banksync/belege.php', 1).'?mainmenu=bank&leftmenu=banksync_transactions&id='.(int) $obj->rowid;
            $this->info_box_contents[$line][] = ['td' => 'class="nowraponall"', 'text' => dol_print_date($this->db->jdate($obj->booking_date), 'day'), 'url' => $url];
            $this->info_box_contents[$line][] = ['td' => 'class="tdoverflowmax150"', 'text' => (string) $obj->counterparty_name];
            $this->info_box_contents[$line][] = ['td' => 'class="nowraponall right amount"', 'text' => price($obj->amount, 0, $langs, 0, -1, -1, $obj->currency)];
            $this->info_box_contents[$line][] = ['td' => 'class="tdoverflowmax200 small opacitymedium"', 'text' => $langs->trans((string) $obj->reason)];
            ++$line;
        }

        $inbox = (new BankSyncBelegStore())->count(BankSyncBelegStore::INBOX);
        if ($inbox > 0) {
            $this->info_box_contents[$line][] = [
                'td' => 'colspan="4"',
                'text' => $langs->trans('BankSyncBoxInbox', $inbox),
                'url' => dol_buildpath('/banksync/belege.php', 1).'?mainmenu=bank&leftmenu=banksync_belege',
            ];
            ++$line;
        }

        if (0 === $line) {
            $this->info_box_contents[0][0] = ['td' => 'class="center" colspan="4"', 'text' => '<span class="opacitymedium">'.$langs->trans('BankSyncBoxEmpty').'</span>', 'asis' => 1];
        }
    }

    /**
     * @param array<string, mixed>|null $head     Array with properties of box title
     * @param array<int, mixed>|null    $contents Array with properties of box lines
     * @param int                       $nooutput No print, only return string
     *
     * @return string
     */
    public function showBox($head = null, $contents = null, $nooutput = 0)
    {
        return parent::showBox($this->info_box_head, $this->info_box_contents, $nooutput);
    }
}
