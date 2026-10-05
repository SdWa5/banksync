<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

$res = 0;
if (!$res && file_exists('../../../main.inc.php')) {
    $res = @include '../../../main.inc.php';
}
if (!$res && file_exists('../../../../main.inc.php')) {
    $res = @include '../../../../main.inc.php';
}
if (!$res) {
    die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../lib/banksync.lib.php';
require_once __DIR__.'/../class/banksyncschema.class.php';
require_once __DIR__.'/../class/banksyncautopostpolicy.class.php';

$langs->load('banksync@banksync');

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');
if ($action === 'save') {
    $feeAccount = GETPOST('BANKSYNC_BANK_FEE_ACCOUNTANCY_CODE', 'alphanohtml');
    $resconst = dolibarr_set_const($db, 'BANKSYNC_BANK_FEE_ACCOUNTANCY_CODE', trim((string) $feeAccount), 'chaine', 0, '', (int) $conf->entity);
    // PayPal settings. Only the path of the credentials file is stored, never the secret itself.
    $cutover = trim((string) GETPOST('BANKSYNC_PAYPAL_CUTOVER_DATE', 'alphanohtml'));
    if ($cutover !== '' && DateTimeImmutable::createFromFormat('!Y-m-d', $cutover) === false) {
        $resconst = -1;
    }
    $paypalSettings = array(
        'BANKSYNC_PAYPAL_CREDENTIALS_FILE' => trim((string) GETPOST('BANKSYNC_PAYPAL_CREDENTIALS_FILE', 'alphanohtml')),
        'BANKSYNC_PAYPAL_ACCOUNT_NUMBER' => trim((string) GETPOST('BANKSYNC_PAYPAL_ACCOUNT_NUMBER', 'alphanohtml')),
        'BANKSYNC_PAYPAL_CUTOVER_DATE' => $cutover,
        'BANKSYNC_PAYPAL_LOOKBACK_DAYS' => (string) max(1, GETPOSTINT('BANKSYNC_PAYPAL_LOOKBACK_DAYS')),
        'BANKSYNC_AUTOPOST_ENABLED' => GETPOST('BANKSYNC_AUTOPOST_ENABLED', 'alpha') ? '1' : '0',
        'BANKSYNC_NOTIFY_EMAIL' => trim((string) GETPOST('BANKSYNC_NOTIFY_EMAIL', 'alphanohtml')),
    );
    // A malformed entry would stop every automatic run, so it is refused here rather than stored.
    $transferAccounts = trim((string) GETPOST(BankSyncAutoPostPolicy::TRANSFER_ACCOUNTS, 'nohtml'));
    try {
        BankSyncAutoPostPolicy::parseTransferAccounts($transferAccounts);
        $paypalSettings[BankSyncAutoPostPolicy::TRANSFER_ACCOUNTS] = $transferAccounts;
    } catch (InvalidArgumentException $e) {
        setEventMessages($langs->trans('BankSyncTransferAccountsInvalid', $e->getMessage()), null, 'errors');
    }
    foreach ($paypalSettings as $name => $value) {
        if ($resconst > 0) {
            $resconst = dolibarr_set_const($db, $name, $value, 'chaine', 0, '', (int) $conf->entity);
        }
    }
    if ($resconst > 0) {
        setEventMessages($langs->trans('BankSyncSetupSaved'), null, 'mesgs');
    } else {
        setEventMessages($langs->trans('BankSyncSetupSaveFailed'), null, 'errors');
    }
}

if ($action === 'testpaypal') {
    require_once __DIR__.'/../class/provider/paypalapiclient.class.php';
    try {
        $client = PayPalApiClient::fromCredentialsFile(getDolGlobalString('BANKSYNC_PAYPAL_CREDENTIALS_FILE', '/run/secrets/paypal.json'));
        $client->authenticate();
        $message = $langs->trans('BankSyncPayPalConnectionOk');
        foreach ($client->balances(new DateTimeImmutable('now')) as $balance) {
            $message .= ' '.$langs->trans('BankSyncPayPalBalance', (string) ($balance['currency'] ?? ''), (string) ($balance['total_balance']['value'] ?? ''));
        }
        setEventMessages($message, null, 'mesgs');
    } catch (Throwable $e) {
        setEventMessages($langs->trans('BankSyncPayPalConnectionFailed', $e->getMessage()), null, 'errors');
    }
}
if ($action === 'runpaypal') {
    require_once __DIR__.'/../class/banksyncpaypalsync.class.php';
    $job = new BankSyncPayPalSync($db);
    if ($job->runScheduled() === 0) {
        setEventMessages($job->output, null, 'mesgs');
    } else {
        setEventMessages($langs->trans('BankSyncPayPalRunFailed', $job->error), null, 'errors');
    }
}

llxHeader('', $langs->trans('BankSyncSetup'));

$head = banksyncAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('BankSync'), -1, 'bank');

print '<div class="opacitymedium">'.$langs->trans('BankSyncSetupHelp').'</div><br>';
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('Parameter').'</th><th>'.$langs->trans('Value').'</th></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('BankSyncVersion').'</td><td>'.dol_escape_htmltag(BankSyncSchema::VERSION).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('BankSyncAvailableProviders').'</td><td>BinX CSV, PayPal API</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('BankSyncPostingMode').'</td><td>'.$langs->trans('BankSyncNativePostingMode').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('BankSyncBankFeeAccountancyCode').'<br><span class="opacitymedium">'.$langs->trans('BankSyncBankFeeAccountancyCodeHelp').'</span></td>';
print '<td><input type="text" class="minwidth200" name="BANKSYNC_BANK_FEE_ACCOUNTANCY_CODE" value="'.dol_escape_htmltag(getDolGlobalString('BANKSYNC_BANK_FEE_ACCOUNTANCY_CODE')).'"></td></tr>';
$text = function ($name, $default = '', $size = 'minwidth300') {
    return '<input type="text" class="'.$size.'" name="'.$name.'" value="'.dol_escape_htmltag(getDolGlobalString($name, $default)).'">';
};
print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('BankSyncPayPal').'</th></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('BankSyncPayPalCredentialsFile').'<br><span class="opacitymedium">'.$langs->trans('BankSyncPayPalCredentialsFileHelp').'</span></td><td>'.$text('BANKSYNC_PAYPAL_CREDENTIALS_FILE', '/run/secrets/paypal.json').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('BankSyncPayPalAccountNumber').'<br><span class="opacitymedium">'.$langs->trans('BankSyncPayPalAccountNumberHelp').'</span></td><td>'.$text('BANKSYNC_PAYPAL_ACCOUNT_NUMBER').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('BankSyncPayPalCutoverDate').'<br><span class="opacitymedium">'.$langs->trans('BankSyncPayPalCutoverDateHelp').'</span></td><td>'.$text('BANKSYNC_PAYPAL_CUTOVER_DATE', '', 'maxwidth100').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('BankSyncPayPalLookbackDays').'</td><td>'.$text('BANKSYNC_PAYPAL_LOOKBACK_DAYS', '14', 'maxwidth50').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('BankSyncAutoPostEnabled').'<br><span class="opacitymedium">'.$langs->trans('BankSyncAutoPostEnabledHelp').'</span></td>';
print '<td><input type="checkbox" name="BANKSYNC_AUTOPOST_ENABLED" value="1"'.(getDolGlobalInt('BANKSYNC_AUTOPOST_ENABLED') ? ' checked' : '').'></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('BankSyncNotifyEmail').'<br><span class="opacitymedium">'.$langs->trans('BankSyncNotifyEmailHelp').'</span></td><td>'.$text('BANKSYNC_NOTIFY_EMAIL').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('BankSyncTransferAccounts').'<br><span class="opacitymedium">'.$langs->trans('BankSyncTransferAccountsHelp').'</span></td>';
print '<td><textarea class="minwidth300" rows="3" name="'.BankSyncAutoPostPolicy::TRANSFER_ACCOUNTS.'">'.dol_escape_htmltag(getDolGlobalString(BankSyncAutoPostPolicy::TRANSFER_ACCOUNTS)).'</textarea></td></tr>';
print '</table>';
print '<div class="center"><button type="submit" class="button button-save">'.$langs->trans('Save').'</button></div>';
print '</form>';

print '<br><div class="center">';
foreach (array('testpaypal' => 'BankSyncPayPalTestConnection', 'runpaypal' => 'BankSyncPayPalRunNow') as $paypalAction => $label) {
    print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="inline-block">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="'.$paypalAction.'">';
    print '<button type="submit" class="button">'.$langs->trans($label).'</button> ';
    print '</form>';
}
print '</div>';

print dol_get_fiche_end();
llxFooter();
$db->close();