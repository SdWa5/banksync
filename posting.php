<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) $res = @include str_replace('..', '', $_SERVER['CONTEXT_DOCUMENT_ROOT']).'/main.inc.php';
if (!$res && file_exists('../main.inc.php')) $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__.'/class/banksyncschema.class.php';
require_once __DIR__.'/class/banksynccandidatematcher.class.php';
require_once __DIR__.'/class/banksyncpostingservice.class.php';

$langs->load('banksync@banksync');
if (!isModEnabled('banksync')) accessforbidden('BankSync module is not enabled.');
if (!$user->hasRight('banksync', 'read')) accessforbidden();

try { BankSyncSchema::ensure($db); }
catch (Exception $e) { setEventMessages($langs->trans('BankSyncSchemaMigrationFailed', $e->getMessage()), null, 'errors'); }

$transactionId = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$returnState = GETPOST('return_state', 'alphanohtml');
if ($transactionId <= 0) accessforbidden('Missing transaction id.');

$matcher = new BankSyncCandidateMatcher($db, (int) $conf->entity);
$transaction = $matcher->fetchTransaction($transactionId);
if (!$transaction) accessforbidden('BankSync transaction not found.');

$posting = new BankSyncPostingService($db, (int) $conf->entity);
try {
    $preview = $posting->buildPreview($transaction);
} catch (Exception $e) {
    $preview = array(
        'postable' => false, 'kind' => '', 'payment_code' => '', 'payment_mode_id' => 0,
        'bank_account_id' => 0, 'bank_account_label' => '', 'bank_amount' => abs((float) $transaction->amount),
        'signed_bank_amount' => (float) $transaction->amount, 'currency' => (string) $transaction->currency,
        'booking_date' => (string) $transaction->booking_date, 'reference' => (string) $transaction->reference,
        'counterparty' => (string) $transaction->counterparty_name, 'rows' => array(), 'warnings' => array(),
        'errors' => array($e->getMessage()), 'rounding_difference' => 0.0, 'existing_posting' => null,
        'bank_fee_accountancy_code' => '', 'posting_items' => array(),
    );
}

function banksyncPostingMessage($langs, $key)
{
    $translated = $langs->trans((string) $key);
    return $translated === (string) $key ? (string) $key : $translated;
}

function banksyncPostingOperationLabel($langs, $kind)
{
    switch ((string) $kind) {
        case BankSyncMatchManager::TARGET_CUSTOMER_INVOICE: return $langs->trans('BankSyncPostingNativeCustomerPayment');
        case BankSyncMatchManager::TARGET_SUPPLIER_INVOICE: return $langs->trans('BankSyncPostingNativeSupplierPayment');
        case BankSyncMatchManager::TARGET_SOCIAL_CONTRIBUTION: return $langs->trans('BankSyncPostingNativeSocialContribution');
        case BankSyncMatchManager::TARGET_VAT: return $langs->trans('BankSyncPostingNativeVatPayment');
        case BankSyncMatchManager::TARGET_BANK_FEE: return $langs->trans('BankSyncPostingNativeBankFee');
        case BankSyncMatchManager::TARGET_INTERNAL_TRANSFER: return $langs->trans('BankSyncPostingNativeTransfer');
        default: return $langs->trans('BankSyncPostingTargetNotSupportedYet');
    }
}

function banksyncCanNativePost($user, $kind)
{
    if (!$user->hasRight('banksync', 'post')) return false;
    if ((string) $kind === BankSyncMatchManager::TARGET_CUSTOMER_INVOICE) return $user->hasRight('facture', 'paiement');
    if ((string) $kind === BankSyncMatchManager::TARGET_SUPPLIER_INVOICE) return ($user->hasRight('fournisseur', 'facture', 'creer') || $user->hasRight('supplier_invoice', 'creer'));
    if ((string) $kind === BankSyncMatchManager::TARGET_SOCIAL_CONTRIBUTION || (string) $kind === BankSyncMatchManager::TARGET_VAT) return $user->hasRight('tax', 'charges', 'creer');
    if ((string) $kind === BankSyncMatchManager::TARGET_BANK_FEE) return $user->hasRight('banque', 'modifier');
    if ((string) $kind === BankSyncMatchManager::TARGET_INTERNAL_TRANSFER) return $user->hasRight('banque', 'transfer');
    return false;
}

function banksyncNativeObjectUrl($type, $id)
{
    $id = (int) $id;
    if ($id <= 0) return '';
    switch ((string) $type) {
        case 'payment': return '/compta/paiement/card.php?id='.$id;
        case 'payment_supplier': return '/fourn/paiement/card.php?id='.$id;
        case 'payment_social': return '/compta/payment_sc/card.php?id='.$id;
        case 'payment_vat': return '/compta/tva/payments.php?mode=tvaonly';
        case 'payment_various': return '/compta/bank/various_payment/card.php?id='.$id;
        case 'bank_transfer':
        case 'bank_line': return '/compta/bank/line.php?rowid='.$id;
        default: return '';
    }
}

function banksyncPostingSelfUrl($transactionId, $returnState, $extra = '')
{
    $url = dol_buildpath('/banksync/posting.php', 1).'?mainmenu=bank&leftmenu=banksync_transactions&id='.(int) $transactionId;
    if ((string) $returnState !== '') $url .= '&return_state='.rawurlencode((string) $returnState);
    if ($extra !== '') $url .= '&'.$extra;
    return $url;
}

$canPost = banksyncCanNativePost($user, (string) $preview['kind']);

if ($action === 'post' && $confirm === 'yes') {
    if (!$canPost) accessforbidden($langs->trans('BankSyncPostingNoPermission'));
    try {
        $result = $posting->post($transaction, $user);
        if (!empty($result['items']) && count($result['items']) > 1) {
            setEventMessages($langs->trans('BankSyncPostingCompositeSuccess', count($result['items'])), null, 'mesgs');
        } else {
            setEventMessages($langs->trans('BankSyncPostingSuccess', (int) $result['native_object_id'], (int) $result['bank_line_id']), null, 'mesgs');
        }
        header('Location: '.banksyncPostingSelfUrl($transactionId, $returnState));
        exit;
    } catch (Throwable $e) {
        setEventMessages($langs->trans('BankSyncPostingFailed', banksyncPostingMessage($langs, $e->getMessage())), null, 'errors');
        try { $preview = $posting->buildPreview($transaction); } catch (Throwable $ignored) {}
    }
}

$existingPosting = isset($preview['existing_posting']) ? $preview['existing_posting'] : null;
$alreadyPosted = ($existingPosting && (string) $existingPosting->status === 'posted');

llxHeader('', $langs->trans('BankSyncPostingPreview'));
print load_fiche_titre($langs->trans('BankSyncPostingPreview').' #'.$transactionId, '', 'bank');

$backUrl = dol_buildpath('/banksync/reconcile.php', 1).'?mainmenu=bank&leftmenu=banksync_transactions&id='.$transactionId;
if ($returnState !== '') $backUrl .= '&return_state='.rawurlencode($returnState);
print '<div class="tabsAction"><a class="butAction" href="'.dol_escape_htmltag($backUrl).'">'.$langs->trans('Back').'</a></div>';
print '<div class="info">'.$langs->trans('BankSyncPostingPreviewHelp').'</div><br>';

if ($alreadyPosted) {
    $nativeUrl = banksyncNativeObjectUrl((string) $existingPosting->native_object_type, (int) $existingPosting->native_object_id);
    print '<div class="ok">'.$langs->trans('BankSyncPostingAlreadyPostedInfo').' ';
    if ($nativeUrl !== '') print '<a href="'.dol_buildpath($nativeUrl, 1).'">'.$langs->trans('BankSyncPostingOpenNativeObject').' #'.((int) $existingPosting->native_object_id).'</a>';
    elseif ((int) $existingPosting->native_object_id > 0) print '#'.((int) $existingPosting->native_object_id);
    if ((int) $existingPosting->fk_bank > 0) print ' · '.$langs->trans('BankSyncPostingBankLine').' #'.((int) $existingPosting->fk_bank);
    print '</div><br>';

    if (!empty($preview['posting_items']) && count($preview['posting_items']) > 1) {
        print '<div class="div-table-responsive"><table class="noborder centpercent">';
        print '<tr class="liste_titre"><th>'.$langs->trans('BankSyncPostingNativeObject').'</th><th class="right">'.$langs->trans('Amount').'</th><th class="right">'.$langs->trans('BankSyncPostingBankLine').'</th></tr>';
        foreach ($preview['posting_items'] as $item) {
            $url = banksyncNativeObjectUrl((string) $item->native_object_type, (int) $item->native_object_id);
            print '<tr class="oddeven"><td>';
            if ($url !== '') print '<a href="'.dol_buildpath($url, 1).'">'.dol_escape_htmltag((string) $item->native_object_type).' #'.((int) $item->native_object_id).'</a>';
            else print dol_escape_htmltag((string) $item->native_object_type).' #'.((int) $item->native_object_id);
            print '</td><td class="right">'.price($item->amount).' '.dol_escape_htmltag((string) $preview['currency']).'</td>';
            print '<td class="right">#'.((int) $item->fk_bank).'</td></tr>';
        }
        print '</table></div><br>';
    }
}

print '<table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('BankSyncBookingDate').'</td><td>'.dol_escape_htmltag((string) $preview['booking_date']).'</td></tr>';
print '<tr><td>'.$langs->trans('BankSyncBankEventType').'</td><td>'.dol_escape_htmltag($langs->trans('BankSyncEventType_'.(string) $transaction->bank_event_type)).'</td></tr>';
print '<tr><td>'.$langs->trans('BankSyncCounterparty').'</td><td>'.dol_escape_htmltag((string) $preview['counterparty']).'</td></tr>';
print '<tr><td>'.$langs->trans('BankSyncReference').'</td><td>'.dol_escape_htmltag((string) $preview['reference']).'</td></tr>';
print '<tr><td>'.$langs->trans('BankSyncBankAmount').'</td><td><strong>'.price($preview['signed_bank_amount']).' '.dol_escape_htmltag((string) $preview['currency']).'</strong></td></tr>';
print '<tr><td>'.$langs->trans('BankSyncDolibarrBankAccount').'</td><td>'.dol_escape_htmltag((string) $preview['bank_account_label']).'</td></tr>';
print '<tr><td>'.$langs->trans('BankSyncPostingPlannedOperation').'</td><td><strong>'.dol_escape_htmltag(banksyncPostingOperationLabel($langs, $preview['kind'])).'</strong></td></tr>';
if (!empty($preview['payment_code'])) print '<tr><td>'.$langs->trans('BankSyncPostingPaymentCode').'</td><td>'.dol_escape_htmltag((string) $preview['payment_code']).'</td></tr>';
if ((string) $preview['kind'] === BankSyncMatchManager::TARGET_BANK_FEE && trim((string) $preview['bank_fee_accountancy_code']) !== '') {
    print '<tr><td>'.$langs->trans('BankSyncBankFeeAccountancyCode').'</td><td>'.dol_escape_htmltag((string) $preview['bank_fee_accountancy_code']).'</td></tr>';
}
print '<tr><td>'.$langs->trans('Status').'</td><td>';
if ($alreadyPosted) print '<span class="badge badge-status6">'.$langs->trans('BankSyncTransactionStatus_posted').'</span>';
elseif (!empty($preview['postable'])) print '<span class="badge badge-status4">'.$langs->trans('BankSyncPostingReady').'</span>';
else print '<span class="badge badge-status1">'.$langs->trans('BankSyncPostingBlocked').'</span>';
print '</td></tr></table>';

if (!empty($preview['errors'])) {
    $visibleErrors = array();
    foreach (array_unique($preview['errors']) as $message) {
        if ($alreadyPosted && (string) $message === 'BankSyncPostingAlreadyPosted') continue;
        $visibleErrors[] = $message;
    }
    if (!empty($visibleErrors)) {
        print '<br>'.load_fiche_titre($langs->trans('Errors'), '', 'error').'<div class="error">';
        foreach ($visibleErrors as $message) print '<div>'.dol_escape_htmltag(banksyncPostingMessage($langs, $message)).'</div>';
        print '</div>';
    }
}
if (!empty($preview['warnings'])) {
    print '<br>'.load_fiche_titre($langs->trans('Warnings'), '', 'warning').'<div class="warning">';
    foreach (array_unique($preview['warnings']) as $message) print '<div>'.dol_escape_htmltag(banksyncPostingMessage($langs, $message)).'</div>';
    print '</div>';
}

print '<br>'.load_fiche_titre($langs->trans('BankSyncPostingTarget'), '', 'payment');
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('BankSyncMatchTarget').'</th><th>'.$langs->trans('Ref').'</th><th>'.$langs->trans('Label').'</th><th class="right">'.$langs->trans('BankSyncAllocation').'</th><th class="right">'.$langs->trans('BankSyncPostingRemainingBefore').'</th><th class="right">'.$langs->trans('BankSyncPostingRemainingAfter').'</th></tr>';
if (empty($preview['rows'])) {
    print '<tr><td colspan="6"><span class="opacitymedium">'.$langs->trans('BankSyncNoCandidates').'</span></td></tr>';
} else {
    foreach ($preview['rows'] as $row) {
        $targetKey = 'BankSyncTarget_'.(string) $row['target_type'];
        $targetLabel = $langs->trans($targetKey);
        if ($targetLabel === $targetKey) $targetLabel = (string) $row['target_type'];
        print '<tr class="oddeven"><td>'.dol_escape_htmltag($targetLabel).'</td><td>';
        if (!empty($row['url'])) print '<a href="'.dol_buildpath((string) $row['url'], 1).'">'.dol_escape_htmltag((string) $row['ref']).'</a>'; else print dol_escape_htmltag((string) $row['ref']);
        print '</td><td>'.dol_escape_htmltag((string) $row['label']).'</td>';
        print '<td class="right nowrap">'.price($row['allocated_amount']).' '.dol_escape_htmltag((string) $preview['currency']).'</td>';
        print '<td class="right nowrap">'.($row['remaining_before'] === null ? '—' : price($row['remaining_before']).' '.dol_escape_htmltag((string) $preview['currency'])).'</td>';
        print '<td class="right nowrap">'.($row['remaining_after'] === null ? '—' : price($row['remaining_after']).' '.dol_escape_htmltag((string) $preview['currency'])).'</td></tr>';
    }
}
print '</table></div>';

if (abs((float) $preview['rounding_difference']) > 0.00001) {
    print '<br><div class="warning">'.$langs->trans('BankSyncRoundingDifference').': <strong>'.price(abs((float) $preview['rounding_difference'])).' '.dol_escape_htmltag((string) $preview['currency']).'</strong></div>';
}

if ($action === 'ask_post' && !empty($preview['postable']) && !$alreadyPosted) {
    if ($canPost) {
        print '<br><div class="warning"><strong>'.$langs->trans('BankSyncPostConfirmTitle').'</strong><br>'.$langs->trans('BankSyncPostConfirmQuestion').'</div>';
        print '<div class="center"><form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="inline-block">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="mainmenu" value="bank"><input type="hidden" name="leftmenu" value="banksync_transactions">';
        print '<input type="hidden" name="id" value="'.$transactionId.'"><input type="hidden" name="action" value="post"><input type="hidden" name="confirm" value="yes">';
        if ($returnState !== '') print '<input type="hidden" name="return_state" value="'.dol_escape_htmltag($returnState).'">';
        print '<button type="submit" class="button button-save">'.$langs->trans('BankSyncPostConfirmButton').'</button></form> ';
        print '<a class="button" href="'.dol_escape_htmltag(banksyncPostingSelfUrl($transactionId, $returnState)).'">'.$langs->trans('Cancel').'</a></div>';
    } else print '<br><div class="warning">'.$langs->trans('BankSyncPostingNoPermission').'</div>';
} elseif (!empty($preview['postable']) && !$alreadyPosted) {
    print '<br><div class="center">';
    if ($canPost) print '<a class="butAction" href="'.dol_escape_htmltag(banksyncPostingSelfUrl($transactionId, $returnState, 'action=ask_post')).'">'.$langs->trans('BankSyncPostNow').'</a>';
    else print '<span class="opacitymedium">'.$langs->trans('BankSyncPostingNoPermission').'</span>';
    print '</div>';
}

print '<br><div class="opacitymedium">'.$langs->trans('BankSyncPostingPreviewOnly').'</div>';
llxFooter();
$db->close();
