<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

/*
 * Belege of the queue. With ?id=<transaction> it shows that transaction's Belege and offers to create
 * the missing supplier invoice from it. Without an id it shows the inbox of Belege whose payment is
 * not known yet.
 */

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
    $res = @include str_replace('..', '', $_SERVER['CONTEXT_DOCUMENT_ROOT']).'/main.inc.php';
}
if (!$res && file_exists('../main.inc.php')) {
    $res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
    $res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
    $res = @include '../../../main.inc.php';
}
if (!$res) {
    exit('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once __DIR__.'/class/banksyncschema.class.php';
require_once __DIR__.'/class/banksyncbelegstore.class.php';
require_once __DIR__.'/class/banksynccandidatematcher.class.php';
require_once __DIR__.'/class/banksyncmatchmanager.class.php';
require_once __DIR__.'/class/banksyncpostingservice.class.php';
require_once __DIR__.'/class/banksyncsupplierinvoicefactory.class.php';

$langs->loadLangs(['banksync@banksync', 'bills', 'companies', 'other']);

if (!isModEnabled('banksync')) {
    accessforbidden('BankSync module is not enabled.');
}
if (!$user->hasRight('banksync', 'read')) {
    accessforbidden();
}

BankSyncSchema::ensure($db);

$entity = (int) $conf->entity;
$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$store = new BankSyncBelegStore();
$matcher = new BankSyncCandidateMatcher($db, $entity);

$transaction = null;
if ($id > 0) {
    $transaction = $matcher->fetchTransaction($id);
    if (null === $transaction) {
        accessforbidden('Transaction not found.');
    }
}

$part = null !== $transaction ? BankSyncBelegStore::transactionPart($id) : BankSyncBelegStore::INBOX;
$selfUrl = dol_buildpath('/banksync/belege.php', 1).'?mainmenu=bank&leftmenu=banksync_belege'.($id > 0 ? '&id='.$id : '');
$canUpload = $user->hasRight('banksync', 'import');
$canPost = $user->hasRight('banksync', 'post');
$canCreateInvoice = $canUpload && $user->hasRight('fournisseur', 'facture', 'creer');

// Upload and delete through Dolibarr's own handler.
$upload_dir = $store->dir($part);
$permissiontoadd = $canUpload;
$object = null;
include DOL_DOCUMENT_ROOT.'/core/actions_linkedfiles.inc.php';

if ('assign' === $action && $canUpload) {
    try {
        $target = GETPOSTINT('transaction_id');
        if (null === $matcher->fetchTransaction($target)) {
            throw new RuntimeException('BankSyncBelegTransactionNotFound');
        }
        $store->assignFromInbox(GETPOST('file', 'alphanohtml'), $target);
        setEventMessages($langs->trans('BankSyncBelegAssigned', $target), null, 'mesgs');
    } catch (Throwable $e) {
        setEventMessages($langs->trans($e->getMessage()), null, 'errors');
    }
}

$invoiceMatch = null;
if (null !== $transaction) {
    foreach ((new BankSyncMatchManager($db, $entity))->getForTransaction($id) as $match) {
        if (BankSyncMatchManager::TARGET_SUPPLIER_INVOICE === (string) $match->target_type && in_array((string) $match->status, ['confirmed', 'posted'], true)) {
            $invoiceMatch = $match;
            break;
        }
    }
}

if ('attach_to_invoice' === $action && $canUpload && null !== $invoiceMatch) {
    $invoice = new FactureFournisseur($db);
    $invoice->fetch((int) $invoiceMatch->target_id);
    $copied = $store->copyToSupplierInvoice($id, $invoice);
    setEventMessages($langs->trans('BankSyncBelegCopied', $copied, $invoice->ref), null, 'mesgs');
}

if ('quick_create' === $action && $canCreateInvoice && null !== $transaction && null === $invoiceMatch) {
    try {
        $invoice = (new BankSyncSupplierInvoiceFactory($db, $entity))->createFromTransaction(
            $transaction,
            GETPOSTINT('socid'),
            GETPOST('new_thirdparty', 'alphanohtml'),
            GETPOST('ref_supplier', 'alphanohtml'),
            GETPOST('label', 'alphanohtml'),
            (float) price2num(GETPOST('vat_rate', 'alphanohtml')),
            $user
        );
        $message = $langs->trans('BankSyncQuickCreateDone', $invoice->ref);
        if ($canPost && GETPOST('post_now', 'alpha')) {
            $result = (new BankSyncPostingService($db, $entity))->post($matcher->fetchTransaction($id), $user);
            $message .= ' '.$langs->trans('BankSyncPostingSuccess', $result['native_object_id'], $result['bank_line_id']);
        }
        setEventMessages($message, null, 'mesgs');
        header('Location: '.$selfUrl);
        exit;
    } catch (Throwable $e) {
        setEventMessages($langs->trans('BankSyncQuickCreateFailed', $langs->trans($e->getMessage())), null, 'errors');
    }
}

$form = new Form($db);
$formfile = new FormFile($db);

llxHeader('', $langs->trans('BankSyncBelege'));
echo load_fiche_titre($langs->trans(null !== $transaction ? 'BankSyncBelegeOfTransaction' : 'BankSyncBelegInbox', $id), '', 'bank');

if (null !== $transaction) {
    $raw = json_decode((string) $transaction->raw_data, true);
    echo '<table class="border centpercent tableforfield">';
    echo '<tr><td class="titlefield">'.$langs->trans('BankSyncBookingDate').'</td><td>'.dol_escape_htmltag((string) $transaction->booking_date).'</td></tr>';
    echo '<tr><td>'.$langs->trans('BankSyncCounterparty').'</td><td>'.dol_escape_htmltag((string) $transaction->counterparty_name).'</td></tr>';
    echo '<tr><td>'.$langs->trans('BankSyncReference').'</td><td>'.dol_escape_htmltag((string) $transaction->reference).'</td></tr>';
    echo '<tr><td>'.$langs->trans('Amount').'</td><td>'.price($transaction->amount).' '.dol_escape_htmltag((string) $transaction->currency).'</td></tr>';
    echo '<tr><td>'.$langs->trans('BankSyncExternalId').'</td><td>'.dol_escape_htmltag((string) $transaction->external_transaction_id).'</td></tr>';
    echo '</table>';
    echo '<div class="tabsAction"><a class="butAction" href="'.dol_buildpath('/banksync/reconcile.php', 1).'?mainmenu=bank&leftmenu=banksync_transactions&id='.$id.'">'.$langs->trans('BankSyncReview').'</a></div>';
}

$files = $store->files($part);
$formfile->form_attach_new_file($selfUrl, '', 0, 0, $canUpload ? 1 : 0, 50, null, '', 1, '', 0);
$formfile->list_of_documents($files, null, 'banksync', '&id='.$id, 0, $part.'/', $canUpload ? 1 : 0, 0, $langs->trans('BankSyncNoBelege'), 0, '', $selfUrl, 0, -1, $upload_dir);

if (null === $transaction && $canUpload && [] !== $files) {
    echo '<br>'.load_fiche_titre($langs->trans('BankSyncBelegAssign'), '', '');
    echo '<table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('File').'</th><th>'.$langs->trans('BankSyncTransactionNumber').'</th><th></th></tr>';
    foreach ($files as $file) {
        echo '<tr class="oddeven"><td>'.dol_escape_htmltag($file['name']).'</td><td colspan="2">';
        echo '<form method="POST" action="'.dol_escape_htmltag($selfUrl).'" class="inline-block">';
        echo '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="assign">';
        echo '<input type="hidden" name="file" value="'.dol_escape_htmltag($file['name']).'">';
        echo '<input type="number" min="1" class="width75" name="transaction_id" required> ';
        echo '<button type="submit" class="button smallpaddingimp">'.$langs->trans('BankSyncBelegAssignButton').'</button></form></td></tr>';
    }
    echo '</table>';
}

if (null !== $transaction && null !== $invoiceMatch) {
    $invoice = new FactureFournisseur($db);
    $invoice->fetch((int) $invoiceMatch->target_id);
    echo '<br><div class="info">'.$langs->trans('BankSyncBelegInvoiceKnown', $invoice->getNomUrl(1)).'</div>';
    if ($canUpload && [] !== $files) {
        echo '<form method="POST" action="'.dol_escape_htmltag($selfUrl).'"><input type="hidden" name="token" value="'.newToken().'">';
        echo '<input type="hidden" name="action" value="attach_to_invoice"><button type="submit" class="button">'.$langs->trans('BankSyncBelegAttachToInvoice').'</button></form>';
    }
} elseif (null !== $transaction && 'debit' === (string) $transaction->direction && 'posted' !== (string) $transaction->status && $canCreateInvoice) {
    $refDefault = is_array($raw) ? trim((string) ($raw['transaction_info']['invoice_id'] ?? '')) : '';
    echo '<br>'.load_fiche_titre($langs->trans('BankSyncQuickCreate'), '', '');
    echo '<div class="opacitymedium">'.$langs->trans('BankSyncQuickCreateHelp').'</div><br>';
    echo '<form method="POST" action="'.dol_escape_htmltag($selfUrl).'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="quick_create">';
    echo '<table class="border centpercent">';
    echo '<tr><td class="titlefield">'.$langs->trans('Supplier').'</td><td>'.$form->select_company(GETPOSTINT('socid'), 'socid', '(s.fournisseur:=:1)', 'SelectThirdParty', 0, 0, [], 0, 'minwidth300');
    echo ' '.$langs->trans('BankSyncOrNewSupplier').' <input type="text" class="minwidth200" name="new_thirdparty" value="'.dol_escape_htmltag((string) $transaction->counterparty_name).'"></td></tr>';
    echo '<tr><td>'.$langs->trans('RefSupplier').'</td><td><input type="text" class="minwidth200" name="ref_supplier" required value="'.dol_escape_htmltag('' !== $refDefault ? $refDefault : 'Eigenbeleg '.$transaction->booking_date).'"></td></tr>';
    echo '<tr><td>'.$langs->trans('Label').'</td><td><input type="text" class="minwidth400" name="label" required value="'.dol_escape_htmltag((string) $transaction->reference).'"></td></tr>';
    echo '<tr><td>'.$langs->trans('VATRate').'</td><td><input type="text" class="width50" name="vat_rate" value="0"> %</td></tr>';
    echo '<tr><td>'.$langs->trans('AmountTTC').'</td><td>'.price(abs((float) $transaction->amount)).' '.dol_escape_htmltag((string) $transaction->currency).'</td></tr>';
    if ($canPost) {
        echo '<tr><td>'.$langs->trans('BankSyncPostNow').'</td><td><input type="checkbox" name="post_now" value="1" checked></td></tr>';
    }
    echo '</table><div class="center"><button type="submit" class="button button-save">'.$langs->trans('BankSyncQuickCreateButton').'</button></div></form>';
}

llxFooter();
$db->close();
