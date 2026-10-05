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
  -> posted                   native PaiementFourn or PaymentVarious, PayPal ID in num_chq
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
| anything else | `other` | none, never posted |

Only status `S` (completed) and `V` (reversed) is staged. A pending transaction (`P`) is staged by
a later run once it completes, and a denied one (`D`) never moved money. Transactions in another
currency than the configured one are skipped and counted.

The booking date is the local date in Europe/Vienna of `transaction_initiation_date`. The
reference is the first non-empty field of `transaction_note` (what the payer typed),
`transaction_subject` and `invoice_id`. The counterparty is taken from `payer_info`.

## The auto-post policy

| Transaction | What happens |
|---|---|
| PayPal fee | posted as `PaymentVarious` |
| outgoing, the matcher confirmed exactly one supplier invoice for the full amount | posted as `PaiementFourn` |
| outgoing, the reference names supplier invoices such as `SI2610-0003 SI2610-0007` that are validated, unpaid, belong to one thirdparty, whose remaining amounts add up exactly to the payment, and whose thirdparty is the recipient by e-mail or name | posted as one `PaiementFourn` allocated across these invoices |
| incoming money, withdrawals, refunds, unknown codes, anything ambiguous | queued with its reason |

The matcher confirms a single invoice only at confidence 100 with amount, reference and partner
or account all matching. With dry-run on, which is the default, the job records `would_post`
instead of posting, so a first run can be reviewed.

Every decision lands in `llx_banksync_autopost`, one row per transaction holding the latest
decision, its reason code and detail. For a posted transaction that row is the audit record of
the automatic posting, next to the `llx_banksync_posting` record BankSync writes for every posting.

The reference pattern defaults to Dolibarr's supplier invoice numbering (`SI` + `yymm-nnnn`). The
recipient check is deliberately strict. Which party PayPal reports in `payer_info` for an outgoing
payment has not been measured against the live API yet. If it turns out to be the paying account
itself, reimbursements stay in the queue until that is adjusted.

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
| `post` | posting, including the "post now" option of the quick-create form |

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
4. "Run PayPal sync now" stages everything since the cutover date. The new source account appears
   under BankSync accounts and has to be mapped to the Dolibarr bank account once. Run it again,
   then review the `would_post` decisions in the transaction list.
5. Switch on "Post automatically", and enable the job `BankSyncPayPalDailyJob` under Setup →
   Scheduled jobs. It is created disabled.

Nothing before the cutover date is ever fetched, and the provider drops anything outside the
requested window even if the API returns it. PayPal makes transactions available within about
three hours, and each run looks back 14 days by default, so a late transaction is picked up by a
later run.

## Settings

| Constant | Meaning | Default |
|---|---|---|
| `BANKSYNC_PAYPAL_CREDENTIALS_FILE` | path of the credentials JSON | `/run/secrets/paypal.json` |
| `BANKSYNC_PAYPAL_ACCOUNT_NUMBER` | source account key | none, required |
| `BANKSYNC_PAYPAL_CUTOVER_DATE` | first day ever fetched, `Y-m-d` | none, required |
| `BANKSYNC_PAYPAL_LOOKBACK_DAYS` | days each run looks back | 14 |
| `BANKSYNC_AUTOPOST_ENABLED` | post instead of recording `would_post` | off |
| `BANKSYNC_NOTIFY_EMAIL` | comma-separated queue mail recipients | none, no mail |

## Limits

- One PayPal account and one currency per installation.
- Incoming money is never posted automatically in this version.
- The balance check against PayPal (`PayPalApiClient::balances()`) is used by the connection test
  only. A month-end comparison is done by hand.
- PayPal's Transaction Search reaches back three years.
