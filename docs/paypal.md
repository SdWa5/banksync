# PayPal provider, auto-posting and the queue

This fork adds a PayPal source to BankSync, a scheduled job that fetches it every day, a narrow
policy that posts the unambiguous cases on its own, and a queue where a person settles the rest
together with its Belege.

## How a PayPal transaction travels

```text
PayPal Transaction Search API
  -> PayPalApiClient          31-day windows, 500 rows per page, OAuth client credentials
  -> PayPalTransactionMapper  one entry for the gross amount, a second one for a fee
  -> BankSyncImporter         staging, duplicate check per entry
  -> BankSyncAutoPoster       matcher, then BankSyncAutoPostPolicy
  -> posted                   native PaiementFourn, PaymentVarious or transfer, PayPal ID in num_chq
  -> or queued                with a reason, mailed to the queue's recipients
```

The PayPal transaction ID is the key throughout. It is the staging entry's
`external_transaction_id`, the fee entry carries it with the suffix `/fee`, and every bank line the
module creates holds it in the cheque number field (`num_chq`). A bank line in Dolibarr can thus be
looked up in PayPal's activity list and the other way round.

## Mapping

| PayPal event code group | BankSync event type | Payment code |
|---|---|---|
| `T00xx` payments | `transfer` | `PPL` |
| `T01xx` fees | `bank_fee` | `PPL` |
| `T03xx` bank deposits, `T04xx` bank withdrawals | `internal_transfer` | `VIR` |
| `T11xx` reversals and refunds | `refund` | `PPL` |
| `T20xx` transfers between balances | `internal_transfer` | `PPL` |
| `T02xx` currency conversion of a foreign-currency payment | that of the payment | that of the payment |
| anything else, including a conversion without its payment | `other` | none, never posted |

Only status `S` (completed) and `V` (reversed) is staged. A pending transaction (`P`) is staged by
a later run once it completes, and a denied one (`D`) never moved money. Transactions in another
currency than the configured one are skipped and counted.

The booking date is the local date in Europe/Vienna of `transaction_initiation_date`. The
reference is the first non-empty field of `transaction_note` (what the payer typed),
`transaction_subject` and `invoice_id`, and failing all three the item names of `cart_info`, which is
all a shop purchase usually carries. The counterparty is taken from `payer_info`, which for an
outgoing payment is the recipient, as measured against the live API on 2026-10-05.

### Payments in another currency

PayPal books a purchase in USD paid from the EUR balance as three transactions with one timestamp,
measured on 2026-10-05 for a purchase of 2026-09-08.

| Event code | Amount | `paypal_reference_id` |
|---|---|---|
| `T0006` payment | -30.54 USD | |
| `T0200` conversion | -27.42 EUR | the payment's ID |
| `T0200` conversion | +30.54 USD | the payment's ID |

Only the EUR conversion moves money the books see, but it carries neither counterparty nor
reference. The provider therefore stages it as one entry with the conversion's amount and date and
the payment's counterparty, reference and event type. Its entry ID is the conversion's ID, so it is
recognised as a duplicate whether a run paired it or not, and its transaction ID, which ends up in
`num_chq`, is the payment's, the one PayPal's activity list shows. The two USD transactions are
skipped as another currency. A conversion whose payment is not among the fetched transactions, such
as a manual conversion of the balance, is staged as `other` and waits in the queue.

## The auto-post policy

| Transaction | What happens |
|---|---|
| PayPal fee | posted as `PaymentVarious` |
| outgoing, the matcher confirmed exactly one supplier invoice for the full amount | posted as `PaiementFourn` |
| outgoing, a person confirmed a transfer to another bank account for the full amount | posted as a transfer |
| outgoing, the recipient's e-mail address is listed under "Recipients paid by transfer" | posted as a transfer to the bank account listed for it |
| outgoing, the reference names supplier invoices such as `SI2610-0003 SI2610-0007` that are validated, unpaid, belong to one thirdparty, whose remaining amounts add up exactly to the payment, and whose thirdparty is the recipient by e-mail or name | posted as one `PaiementFourn` allocated across these invoices |
| incoming money, withdrawals, refunds, unknown codes, anything ambiguous | queued with its reason |

The matcher confirms a single invoice only at confidence 100 with amount, reference and partner
or account all matching. With dry-run on, which is the default, the job records `would_post`
instead of posting, so a first run can be reviewed.

Every decision lands in `llx_banksync_autopost`, one row per transaction holding the latest
decision, its reason code and detail. For a posted transaction that row is the audit record of
the automatic posting, next to the `llx_banksync_posting` record BankSync writes for every posting.

A listed recipient wins over invoice references in the PayPal Mitteilung, and a confirmed match wins
over the list.

The reference pattern defaults to Dolibarr's supplier invoice numbering (`SI` + `yymm-nnnn`). The
recipient check is deliberately strict, and it works because PayPal reports the recipient of an
outgoing payment in `payer_info`.

## Transfers to the books' own accounts

Some payments move money between two accounts the books keep, rather than paying a third party.
The case this was built for is an association whose members advance purchases. Each member gets a
bank account in Dolibarr that holds what the association owes them. A purchase the member paid is a
supplier invoice of the shop, paid from that account, which goes negative. A reimbursement by PayPal
moves money from the PayPal account into the member's account, back towards zero, and money the
member collected for the association, such as donations, is booked into it as well. Its balance is
therefore what is open between the association and that member at any time, and an advance can be
set off against a claim without any money moving.

"Recipients paid by transfer" in setup maps an e-mail address to a Dolibarr bank account ID, one
entry per line, for example `member@example.org=3`. An outgoing PayPal payment to a listed address
is posted as a transfer to that account. Incoming money from a listed address stays in the queue,
because it may as well be a membership fee. A person settles it under reconciliation with the
target "Internal transfer", which lists the other open bank accounts.

A transfer is booked like Dolibarr's own transfer screen does it, as one line on each account,
linked to each other. Both lines carry the PayPal transaction ID in `num_chq`. A transfer to or from
a cash account is booked as cash (`LIQ`), because Dolibarr's cash accounts accept nothing else.

## The queue

The transaction list has a status filter "Queue (needs a person)", which shows every open
transaction the auto-poster left behind, with the reason under its status. A home-page box lists
the oldest ones and counts the Belege inbox.

**Belege of a transaction.** Each transaction has a Belege page. Files uploaded there are served
by Dolibarr's `document.php` with the BankSync read right. Once the transaction is matched to a
supplier invoice, one button copies them into that invoice's own documents.

**Create supplier invoice from transaction.** For an outgoing payment without an invoice, the
Belege page offers to create one. It takes an existing supplier or creates a new one, uses the
supplier's invoice number or a placeholder such as `Eigenbeleg 2026-10-03`, creates one line for
the paid amount at the given VAT rate, validates the invoice, attaches the Belege and confirms the
match. A user with the `post` right can post the payment in the same step.

**Belege inbox.** A Beleg whose payment is not known yet, such as a cash receipt, goes into the
inbox and is assigned to a transaction later.

**Mail.** When new items entered the queue, the job mails the configured recipients once. Items
that stay open trigger a reminder after 3 days, then after 6, 12, 24 and from then on every 30
days. A mail about new items resets that interval, and an empty queue sends nothing.

## Rights

| Right | Allows |
|---|---|
| `read` | the lists, the Belege and the queue box |
| `import` | uploading Belege, confirming matches, creating supplier invoices (also needs Dolibarr's own right to create supplier invoices) |
| `post` | posting, including the "post now" option of the quick-create form. Posting a transfer by hand also needs Dolibarr's own right to make transfers |

## Setup

1. Create a REST app in the PayPal developer dashboard under the account's business login, with
   the feature "Transaction Search" enabled.
2. Put its credentials into a JSON file that only the web server can read, and mount it read-only
   into every Dolibarr container that runs the module, including the one running scheduled jobs.

   ```json
   {"client_id": "...", "client_secret": "...", "base_url": "https://api-m.paypal.com"}
   ```

   `base_url` is optional and defaults to the live API. The sandbox is
   `https://api-m.sandbox.paypal.com`.
3. In BankSync setup, fill in the path of that file, the account key (for example the account's
   e-mail address), the cutover date, the look-back days and the mail recipients. Use
   "Test PayPal connection", which shows the current balance on success.
4. "Run PayPal sync now" stages everything since the cutover date, because the first run starts
   there. The new source account appears
   under BankSync accounts and has to be mapped to the Dolibarr bank account once. Run it again,
   then review the `would_post` decisions in the transaction list.
5. Switch on "Post automatically", and enable the job `BankSyncPayPalDailyJob` under Setup →
   Scheduled jobs. It is created disabled.

Nothing before the cutover date is ever fetched, and the provider drops anything outside the
requested window even if the API returns it. Each run records the end of its window in
`BANKSYNC_PAYPAL_SYNCED_UNTIL`, and the next run starts the look-back days (14 by default) before
that point. PayPal makes transactions available within about three hours, so a late transaction is
picked up by a later run, and a job that was down for weeks catches up rather than skipping the
gap. Moving the cutover to an earlier day needs `BANKSYNC_PAYPAL_SYNCED_UNTIL` deleted as well, or
the next run will not reach back that far.

## Settings

| Constant | Meaning | Default |
|---|---|---|
| `BANKSYNC_PAYPAL_CREDENTIALS_FILE` | path of the credentials JSON | `/run/secrets/paypal.json` |
| `BANKSYNC_PAYPAL_ACCOUNT_NUMBER` | source account key | none, required |
| `BANKSYNC_PAYPAL_CUTOVER_DATE` | first day ever fetched, `Y-m-d` | none, required |
| `BANKSYNC_PAYPAL_LOOKBACK_DAYS` | days before the end of the last run where the next run starts | 14 |
| `BANKSYNC_PAYPAL_SYNCED_UNTIL` | end of the last staged window, written by the job | none, the first run starts at the cutover |
| `BANKSYNC_AUTOPOST_ENABLED` | post instead of recording `would_post` | off |
| `BANKSYNC_NOTIFY_EMAIL` | comma-separated queue mail recipients | none, no mail |
| `BANKSYNC_TRANSFER_ACCOUNTS` | recipients paid by transfer, `e-mail=bank account ID` per line | none |

## Limits

- One PayPal account and one currency per installation.
- Incoming money is never posted automatically in this version.
- The balance check against PayPal (`PayPalApiClient::balances()`) is used by the connection test
  only. A month-end comparison is done by hand.
- PayPal's Transaction Search reaches back three years.
