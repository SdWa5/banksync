<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
    $res = @include str_replace('..', '', $_SERVER['CONTEXT_DOCUMENT_ROOT']).'/main.inc.php';
}
if (!$res && file_exists('../main.inc.php')) $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__.'/class/banksyncschema.class.php';
require_once __DIR__.'/class/banksynccandidatematcher.class.php';

$langs->load('banksync@banksync');
if (!isModEnabled('banksync')) accessforbidden('BankSync module is not enabled.');
if (!$user->hasRight('banksync', 'read')) accessforbidden();

try {
    BankSyncSchema::ensure($db);
} catch (Exception $e) {
    setEventMessages($langs->trans('BankSyncSchemaMigrationFailed', $e->getMessage()), null, 'errors');
}

function banksyncAllowedEventTypes()
{
    return array('transfer', 'card', 'direct_debit', 'bank_fee', 'cash', 'conversion', 'internal_transfer', 'refund', 'other');
}

function banksyncAllowedStatuses()
{
    return array('queue', 'new', 'partially_matched', 'matched', 'posted', 'ignored', 'error');
}

function banksyncFilterKeys()
{
    return array('filter_date_from', 'filter_date_to', 'filter_event_type', 'filter_code', 'filter_counterparty', 'filter_reference', 'filter_status');
}

function banksyncNormalizeReturnFilters($filters)
{
    $clean = array();
    if (!is_array($filters)) return $clean;
    foreach (banksyncFilterKeys() as $key) {
        if (!isset($filters[$key])) continue;
        $value = trim((string) $filters[$key]);
        if ($value === '') continue;
        if (($key === 'filter_date_from' || $key === 'filter_date_to') && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) continue;
        if ($key === 'filter_event_type' && !in_array($value, banksyncAllowedEventTypes(), true)) continue;
        if ($key === 'filter_status' && !in_array($value, banksyncAllowedStatuses(), true)) continue;
        $clean[$key] = substr($value, 0, 120);
    }
    return $clean;
}

function banksyncListUrl($page, array $filters)
{
    $params = array('mainmenu' => 'bank', 'leftmenu' => 'banksync_transactions');
    if ((int) $page > 0) $params['page'] = (int) $page;
    foreach (banksyncNormalizeReturnFilters($filters) as $key => $value) $params[$key] = $value;
    return dol_buildpath('/banksync/transactions.php', 1).'?'.http_build_query($params);
}

function banksyncEncodeReturnState($page, $row, array $filters)
{
    $payload = json_encode(array(
        'page' => max(0, (int) $page),
        'row' => max(0, (int) $row),
        'filters' => banksyncNormalizeReturnFilters($filters),
    ));
    return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
}

function banksyncListConfidencePresentation($langs, $confidence)
{
    $confidence = (int) $confidence;
    if ($confidence >= 100) return array('class' => 'badge-status4', 'label' => $langs->trans('BankSyncConfidenceCertain'));
    if ($confidence >= 80) return array('class' => 'badge-status1', 'label' => $langs->trans('BankSyncConfidenceStrong'));
    if ($confidence >= 60) return array('class' => 'badge-status1', 'label' => $langs->trans('BankSyncConfidencePossible'));
    return array('class' => 'badge-status0', 'label' => $langs->trans('BankSyncConfidenceWeak'));
}

function banksyncTransactionStatusPresentation($langs, $status)
{
    switch ((string) $status) {
        case 'matched': return array('class' => 'badge-status4', 'label' => $langs->trans('BankSyncTransactionStatus_matched'));
        case 'partially_matched': return array('class' => 'badge-status1', 'label' => $langs->trans('BankSyncTransactionStatus_partially_matched'));
        case 'posted': return array('class' => 'badge-status6', 'label' => $langs->trans('BankSyncTransactionStatus_posted'));
        case 'ignored': return array('class' => 'badge-status0', 'label' => $langs->trans('BankSyncTransactionStatus_ignored'));
        case 'error': return array('class' => 'badge-status8', 'label' => $langs->trans('BankSyncTransactionStatus_error'));
        case 'queue': return array('class' => 'badge-status1', 'label' => $langs->trans('BankSyncTransactionStatus_queue'));
        default: return array('class' => 'badge-status0', 'label' => $langs->trans('BankSyncTransactionStatus_new'));
    }
}

function banksyncCompactPagination($langs, $page, $totalPages, $totalRows, array $filters)
{
    if ($totalRows <= 0 || $totalPages <= 1) return '';

    $html = '<div class="center" style="margin:12px 0 4px 0;white-space:nowrap">';
    $itemStyle = 'display:inline-block;min-width:22px;padding:3px 6px;margin:0 1px;text-align:center;text-decoration:none;border-radius:3px;';
    $linkStyle = $itemStyle.'border:1px solid transparent;';
    $currentStyle = $itemStyle.'border:1px solid #bbb;background:rgba(128,128,128,.12);font-weight:bold;';

    if ($page > 0) {
        $html .= '<a title="'.dol_escape_htmltag($langs->trans('Previous')).'" style="'.$linkStyle.'" href="'.dol_escape_htmltag(banksyncListUrl($page - 1, $filters)).'">&#8249;</a>';
    } else {
        $html .= '<span class="opacitymedium" style="'.$linkStyle.'">&#8249;</span>';
    }

    $windowStart = max(0, $page - 2);
    $windowEnd = min($totalPages - 1, $page + 2);
    if ($windowStart > 0) {
        $html .= '<a style="'.$linkStyle.'" href="'.dol_escape_htmltag(banksyncListUrl(0, $filters)).'">1</a>';
        if ($windowStart > 1) $html .= '<span class="opacitymedium" style="'.$itemStyle.'">…</span>';
    }
    for ($p = $windowStart; $p <= $windowEnd; $p++) {
        if ($p === $page) $html .= '<span style="'.$currentStyle.'">'.($p + 1).'</span>';
        else $html .= '<a style="'.$linkStyle.'" href="'.dol_escape_htmltag(banksyncListUrl($p, $filters)).'">'.($p + 1).'</a>';
    }
    if ($windowEnd < $totalPages - 1) {
        if ($windowEnd < $totalPages - 2) $html .= '<span class="opacitymedium" style="'.$itemStyle.'">…</span>';
        $html .= '<a style="'.$linkStyle.'" href="'.dol_escape_htmltag(banksyncListUrl($totalPages - 1, $filters)).'">'.$totalPages.'</a>';
    }

    if ($page < $totalPages - 1) {
        $html .= '<a title="'.dol_escape_htmltag($langs->trans('Next')).'" style="'.$linkStyle.'" href="'.dol_escape_htmltag(banksyncListUrl($page + 1, $filters)).'">&#8250;</a>';
    } else {
        $html .= '<span class="opacitymedium" style="'.$linkStyle.'">&#8250;</span>';
    }

    $html .= '<span class="opacitymedium small" style="margin-left:8px">'.$langs->trans('BankSyncTransactionCount', $totalRows).'</span>';
    $html .= '</div>';
    return $html;
}

// Backward-compatible fallback for older reconciliation links: restore list state from the short-lived cookie.
if (!isset($_GET['page']) && !isset($_POST['page']) && !empty($_COOKIE['banksync_return'])) {
    $encoded = strtr((string) $_COOKIE['banksync_return'], '-_', '+/');
    $padding = strlen($encoded) % 4;
    if ($padding) $encoded .= str_repeat('=', 4 - $padding);
    $decoded = base64_decode($encoded, true);
    $state = $decoded !== false ? json_decode($decoded, true) : null;
    setcookie('banksync_return', '', time() - 3600, '/');
    if (is_array($state)) {
        $returnPage = isset($state['page']) ? max(0, (int) $state['page']) : 0;
        $returnRow = isset($state['row']) ? max(0, (int) $state['row']) : 0;
        $returnFilters = banksyncNormalizeReturnFilters(isset($state['filters']) ? $state['filters'] : array());
        $returnUrl = banksyncListUrl($returnPage, $returnFilters);
        if ($returnRow > 0) $returnUrl .= '#banksync-tx-'.$returnRow;
        header('Location: '.$returnUrl);
        exit;
    }
}

$page = max(0, GETPOSTINT('page'));
$limit = 50;
$entity = (int) $conf->entity;
$action = GETPOST('action', 'aZ09');

$filters = banksyncNormalizeReturnFilters(array(
    'filter_date_from' => GETPOST('filter_date_from', 'alphanohtml'),
    'filter_date_to' => GETPOST('filter_date_to', 'alphanohtml'),
    'filter_event_type' => GETPOST('filter_event_type', 'alpha'),
    'filter_code' => GETPOST('filter_code', 'alphanohtml'),
    'filter_counterparty' => GETPOST('filter_counterparty', 'alphanohtml'),
    'filter_reference' => GETPOST('filter_reference', 'alphanohtml'),
    'filter_status' => GETPOST('filter_status', 'alpha'),
));

if ($action === 'scan_candidates') {
    if (!$user->hasRight('banksync', 'import')) accessforbidden();
    try {
        $matcher = new BankSyncCandidateMatcher($db, $entity);
        $scanned = $matcher->refreshOpenTransactions($user->id, 100);
        setEventMessages($langs->trans('BankSyncTransactionsScanned', $scanned), null, 'mesgs');
    } catch (Exception $e) {
        setEventMessages($langs->trans('BankSyncCandidateSearchFailed', $e->getMessage()), null, 'errors');
    }
}

$where = array('t.entity = '.$entity);
if (!empty($filters['filter_date_from'])) $where[] = "t.booking_date >= '".$db->escape($filters['filter_date_from'])."'";
if (!empty($filters['filter_date_to'])) $where[] = "t.booking_date <= '".$db->escape($filters['filter_date_to'])."'";
if (!empty($filters['filter_event_type'])) $where[] = "t.bank_event_type = '".$db->escape($filters['filter_event_type'])."'";
if (!empty($filters['filter_code'])) $where[] = "t.transaction_code LIKE '%".$db->escape($filters['filter_code'])."%'";
if (!empty($filters['filter_counterparty'])) $where[] = "t.counterparty_name LIKE '%".$db->escape($filters['filter_counterparty'])."%'";
if (!empty($filters['filter_reference'])) $where[] = "t.reference LIKE '%".$db->escape($filters['filter_reference'])."%'";
// "queue" is not a stored status: it is every open transaction the auto-poster left for a person.
if (!empty($filters['filter_status']) && $filters['filter_status'] === 'queue') {
    $where[] = "t.status IN ('new', 'matched', 'partially_matched') AND EXISTS (SELECT 1 FROM ".$db->prefix()."banksync_autopost AS apq WHERE apq.fk_transaction = t.rowid AND apq.entity = t.entity AND apq.decision IN ('queued', 'error'))";
} elseif (!empty($filters['filter_status'])) {
    $where[] = "t.status = '".$db->escape($filters['filter_status'])."'";
}
$whereSql = implode(' AND ', $where);

$totalRows = 0;
$countSql = 'SELECT COUNT(*) AS nb FROM '.$db->prefix().'banksync_transaction AS t WHERE '.$whereSql;
$countRes = $db->query($countSql);
if ($countRes) {
    $countObj = $db->fetch_object($countRes);
    $totalRows = (int) $countObj->nb;
    $db->free($countRes);
}
$totalPages = max(1, (int) ceil($totalRows / $limit));
if ($page >= $totalPages) $page = $totalPages - 1;
$offset = $page * $limit;

$sql = 'SELECT t.rowid, t.booking_date, t.value_date, t.direction, t.amount, t.currency, t.transaction_type, t.transaction_code,';
$sql .= ' t.counterparty_name, t.counterparty_account, t.reference, t.external_transaction_id, t.status, t.fk_import,';
$sql .= ' t.bank_event_type, t.dolibarr_payment_code, t.classification_confidence, t.classification_method, t.fk_bank,';
$sql .= ' a.source_account_number, a.mapping_status, a.fk_bank_account,';
$sql .= ' ba.label AS bank_account_label, ba.ref AS bank_account_ref,';
$sql .= ' bm.rowid AS match_id, bm.target_type AS match_target_type, bm.confidence AS match_confidence, bm.status AS match_status, bm.match_method AS match_method,';
$sql .= ' ap.decision AS autopost_decision, ap.reason AS autopost_reason, ap.detail AS autopost_detail';
$sql .= ' FROM '.$db->prefix().'banksync_transaction AS t';
$sql .= ' LEFT JOIN '.$db->prefix().'banksync_account AS a ON a.rowid = t.fk_banksync_account';
$sql .= ' LEFT JOIN '.$db->prefix().'bank_account AS ba ON ba.rowid = a.fk_bank_account';
$sql .= ' LEFT JOIN '.$db->prefix().'banksync_autopost AS ap ON ap.fk_transaction = t.rowid AND ap.entity = t.entity';
$sql .= ' LEFT JOIN '.$db->prefix().'banksync_match AS bm ON bm.rowid = (';
$sql .= ' SELECT bm2.rowid FROM '.$db->prefix().'banksync_match AS bm2';
$sql .= ' WHERE bm2.entity = t.entity AND bm2.fk_transaction = t.rowid';
$sql .= " AND bm2.status IN ('confirmed', 'posted', 'suggested')";
$sql .= " ORDER BY CASE bm2.status WHEN 'confirmed' THEN 0 WHEN 'posted' THEN 1 ELSE 2 END, bm2.confidence DESC, bm2.rowid ASC LIMIT 1)";
$sql .= ' WHERE '.$whereSql;
$sql .= ' ORDER BY t.booking_date DESC, t.rowid DESC';
$sql .= $db->plimit($limit, $offset);
$resql = $db->query($sql);

llxHeader('', $langs->trans('BankSyncTransactions'));
print load_fiche_titre($langs->trans('BankSyncTransactions'), '', 'bank');

if ($user->hasRight('banksync', 'import')) {
    print '<div class="tabsAction"><form method="POST" action="'.dol_escape_htmltag(banksyncListUrl($page, $filters)).'" class="inline-block">';
    print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="scan_candidates">';
    print '<button type="submit" class="butAction">'.$langs->trans('BankSyncScanCandidates').'</button></form></div>';
}

print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="mainmenu" value="bank"><input type="hidden" name="leftmenu" value="banksync_transactions">';
print '<table class="noborder centpercent" style="margin-bottom:12px">';
print '<tr class="liste_titre"><th colspan="8">'.$langs->trans('BankSyncFilters').'</th></tr>';
print '<tr class="oddeven">';
print '<td><label>'.$langs->trans('BankSyncDateFrom').'<br><input type="date" name="filter_date_from" value="'.dol_escape_htmltag(isset($filters['filter_date_from']) ? $filters['filter_date_from'] : '').'"></label></td>';
print '<td><label>'.$langs->trans('BankSyncDateTo').'<br><input type="date" name="filter_date_to" value="'.dol_escape_htmltag(isset($filters['filter_date_to']) ? $filters['filter_date_to'] : '').'"></label></td>';
print '<td><label>'.$langs->trans('BankSyncBankEventType').'<br><select name="filter_event_type" class="flat"><option value="">'.$langs->trans('BankSyncAll').'</option>';
foreach (banksyncAllowedEventTypes() as $type) {
    $selected = isset($filters['filter_event_type']) && $filters['filter_event_type'] === $type ? ' selected' : '';
    print '<option value="'.dol_escape_htmltag($type).'"'.$selected.'>'.dol_escape_htmltag($langs->trans('BankSyncEventType_'.$type)).'</option>';
}
print '</select></label></td>';
print '<td><label>'.$langs->trans('BankSyncTransactionCode').'<br><input class="width100" type="text" name="filter_code" value="'.dol_escape_htmltag(isset($filters['filter_code']) ? $filters['filter_code'] : '').'"></label></td>';
print '<td><label>'.$langs->trans('BankSyncCounterparty').'<br><input class="minwidth200" type="text" name="filter_counterparty" value="'.dol_escape_htmltag(isset($filters['filter_counterparty']) ? $filters['filter_counterparty'] : '').'"></label></td>';
print '<td><label>'.$langs->trans('BankSyncReference').'<br><input class="minwidth200" type="text" name="filter_reference" value="'.dol_escape_htmltag(isset($filters['filter_reference']) ? $filters['filter_reference'] : '').'"></label></td>';
print '<td><label>'.$langs->trans('Status').'<br><select name="filter_status" class="flat"><option value="">'.$langs->trans('BankSyncAll').'</option>';
foreach (banksyncAllowedStatuses() as $status) {
    $selected = isset($filters['filter_status']) && $filters['filter_status'] === $status ? ' selected' : '';
    print '<option value="'.dol_escape_htmltag($status).'"'.$selected.'>'.dol_escape_htmltag(banksyncTransactionStatusPresentation($langs, $status)['label']).'</option>';
}
print '</select></label></td>';
print '<td class="right valignbottom nowrap"><button type="submit" class="button">'.$langs->trans('BankSyncApplyFilters').'</button> <a class="button" href="'.dol_buildpath('/banksync/transactions.php', 1).'?mainmenu=bank&leftmenu=banksync_transactions">'.$langs->trans('BankSyncClearFilters').'</a></td>';
print '</tr></table></form>';

print banksyncCompactPagination($langs, $page, $totalPages, $totalRows, $filters);

print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('BankSyncBookingDate').'</th><th>'.$langs->trans('BankSyncBankEventType').'</th><th>'.$langs->trans('BankSyncTransactionCode').'</th><th>'.$langs->trans('BankSyncCounterparty').'</th><th>'.$langs->trans('BankSyncReference').'</th><th>'.$langs->trans('BankSyncDolibarrBankAccount').'</th><th class="right">'.$langs->trans('Amount').'</th><th>'.$langs->trans('BankSyncReconciliation').'</th><th>'.$langs->trans('Status').'</th></tr>';

$num = 0;
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $num++;
        print '<tr id="banksync-tx-'.(int) $obj->rowid.'" class="oddeven"><td>'.dol_escape_htmltag((string) $obj->booking_date).'</td><td>';
        $eventType = !empty($obj->bank_event_type) ? $obj->bank_event_type : 'other';
        print dol_escape_htmltag($langs->trans('BankSyncEventType_'.$eventType));
        if (!empty($obj->dolibarr_payment_code)) print '<br><span class="opacitymedium small">'.dol_escape_htmltag($obj->dolibarr_payment_code).'</span>';
        print '</td><td title="'.dol_escape_htmltag($obj->transaction_type).'">'.dol_escape_htmltag($obj->transaction_code).'</td>';
        print '<td>'.dol_escape_htmltag($obj->counterparty_name);
        if (!empty($obj->counterparty_account)) print '<br><span class="opacitymedium small">'.dol_escape_htmltag($obj->counterparty_account).'</span>';
        print '</td><td>'.dol_escape_htmltag($obj->reference).'</td><td>';
        if (!empty($obj->fk_bank_account)) {
            $bankLabel = trim((string) $obj->bank_account_label);
            if ($bankLabel === '') $bankLabel = trim((string) $obj->bank_account_ref);
            print dol_escape_htmltag($bankLabel);
        } else {
            print '<a href="'.dol_buildpath('/banksync/accounts.php', 1).'?mainmenu=bank&leftmenu=banksync_accounts" class="error">'.$langs->trans('BankSyncUnmapped').'</a>';
        }
        if (!empty($obj->source_account_number)) print '<br><span class="opacitymedium small">'.dol_escape_htmltag($obj->source_account_number).'</span>';
        print '</td><td class="right nowrap">'.price($obj->amount).' '.dol_escape_htmltag($obj->currency).'</td><td>';

        $returnState = banksyncEncodeReturnState($page, (int) $obj->rowid, $filters);
        $reconcileUrl = dol_buildpath('/banksync/reconcile.php', 1).'?mainmenu=bank&leftmenu=banksync_transactions&id='.(int) $obj->rowid.'&return_state='.rawurlencode($returnState);
        $rememberReturn = "document.cookie='banksync_return=".$returnState."; path=/; max-age=1800; SameSite=Lax';";
        if ($eventType === 'bank_fee') {
            print '<span class="badge badge-status4">'.$langs->trans('BankSyncTarget_bank_fee').'</span><br><a class="small" onclick="'.dol_escape_htmltag($rememberReturn).'" href="'.dol_escape_htmltag($reconcileUrl).'">'.$langs->trans('BankSyncReview').'</a>';
        } elseif (!empty($obj->match_id)) {
            $targetKey = 'BankSyncTarget_'.(string) $obj->match_target_type;
            $targetLabel = $langs->trans($targetKey);
            if ($targetLabel === $targetKey) $targetLabel = (string) $obj->match_target_type;
            $confidence = (int) $obj->match_confidence;
            $cp = banksyncListConfidencePresentation($langs, $confidence);
            $manual = ((string) $obj->match_method === 'manual');
            if ((string) $obj->match_status === 'confirmed') {
                print '<span class="badge badge-status4">'.dol_escape_htmltag($targetLabel).' — '.($manual ? $langs->trans('BankSyncManual') : $confidence.'%').'</span>';
                print '<br><span class="small opacitymedium">'.$langs->trans('BankSyncMatchConfirmedStatus').'</span>';
            } elseif ((string) $obj->match_status === 'posted') {
                print '<span class="badge badge-status6">'.dol_escape_htmltag($targetLabel).' — '.($manual ? $langs->trans('BankSyncManual') : $confidence.'%').'</span>';
                print '<br><span class="small opacitymedium">'.$langs->trans('BankSyncMatchPostedStatus').'</span>';
            } else {
                print '<span class="badge '.dol_escape_htmltag($cp['class']).'">'.dol_escape_htmltag($targetLabel).' — '.$confidence.'%</span>';
                print '<br><span class="small opacitymedium">'.dol_escape_htmltag($cp['label']).'</span>';
            }
            print '<br><a class="small" onclick="'.dol_escape_htmltag($rememberReturn).'" href="'.dol_escape_htmltag($reconcileUrl).'">'.$langs->trans('BankSyncReview').'</a>';
        } else {
            print '<a onclick="'.dol_escape_htmltag($rememberReturn).'" href="'.dol_escape_htmltag($reconcileUrl).'">'.$langs->trans('BankSyncFindCandidates').'</a>';
        }
        print '<br><a class="small" href="'.dol_escape_htmltag(dol_buildpath('/banksync/belege.php', 1).'?mainmenu=bank&leftmenu=banksync_transactions&id='.(int) $obj->rowid).'">'.$langs->trans('BankSyncBelege').'</a>';
        print '</td><td>';
        $sp = banksyncTransactionStatusPresentation($langs, (string) $obj->status);
        print '<span class="badge '.dol_escape_htmltag($sp['class']).'">'.dol_escape_htmltag($sp['label']).'</span>';
        if (!empty($obj->autopost_reason)) {
            print '<br><span class="small'.(in_array((string) $obj->autopost_decision, array('queued', 'error'), true) ? ' warning' : ' opacitymedium').'" title="'.dol_escape_htmltag((string) $obj->autopost_detail).'">';
            print dol_escape_htmltag($langs->trans('BankSyncAutoDecision_'.$obj->autopost_decision).': '.$langs->trans((string) $obj->autopost_reason)).'</span>';
        }
        print '</td></tr>';
    }
    $db->free($resql);
}

if ($num === 0) print '<tr><td colspan="9"><span class="opacitymedium">'.$langs->trans('BankSyncNoTransactions').'</span></td></tr>';
print '</table></div>';

print banksyncCompactPagination($langs, $page, $totalPages, $totalRows, $filters);

llxFooter();
$db->close();