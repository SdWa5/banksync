# Changelog

All notable changes to this fork are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

This fork `SdWa5/banksync` starts at upstream `vanyolai/dolibarr-banksync` commit `6c3001b`
(0.5.1, 2026-09-16). Upstream's history before that point lives in its own git log.

## [Unreleased]

## [1.2.0] - 2026-10-05

### Added

- A transaction can be posted as a transfer to another bank account of the books, booked as two
  linked bank lines with the PayPal transaction ID on both, and as cash when either side is a cash
  account.
- The manual reconciliation offers the books' other open bank accounts as the target "Internal
  transfer".
- The setting "Recipients paid by transfer" (`BANKSYNC_TRANSFER_ACCOUNTS`) maps an e-mail address to
  a bank account, and the auto-post policy posts an outgoing payment to a listed address as a
  transfer to that account.
- The auto-post policy posts a transfer a person confirmed for the full amount.

## [1.1.0] - 2026-10-05

### Added

- A payment in another currency is staged through its conversion into the account's currency, with
  the payment's counterparty, reference and event type and the payment's transaction ID.
- The item names of a PayPal cart are the reference when note, subject and invoice ID are empty.
- The run summary counts paired currency conversions.

### Changed

- The look-back setting is labelled as counting from the end of the last run.

### Fixed

- A run starts the look-back days before the end of the last successful run, recorded in
  `BANKSYNC_PAYPAL_SYNCED_UNTIL`, instead of before now, so the first run reaches the cutover and a
  job that was down longer than the look-back loses nothing.

## [1.0.0] - 2026-10-05

### Added

- PayPal provider `paypal_api` reading the Transaction Search API in 31-day windows of 500 rows per
  page, with the balances endpoint for balance checks.
- Credentials are read from a JSON file such as a read-only Docker secret, never from the database.
- PayPal event codes map to BankSync event types and payment codes. Unknown codes stay unpostable.
- A PayPal fee becomes its own entry with the suffix `/fee`, so it posts as a bank fee.
- Pending and denied PayPal transactions, other currencies and entries outside the requested
  window are skipped and counted.
- A PayPal connection error names curl's reason instead of HTTP status 0.
- Auto-post policy that posts PayPal fees, payments the matcher confirmed against exactly one
  supplier invoice, and reimbursements whose reference names supplier invoices adding up to the
  amount. Everything else stays queued with a reason.
- `banksync_autopost` table holding the latest automatic decision per transaction, which is both
  the queue reason and the audit of automatic postings.
- Daily scheduled job `BankSyncPayPalDailyJob`, created disabled, which fetches PayPal from the
  cutover date, stages, applies the policy and mails the queue's recipients.
- Dry-run mode, on by default, that records what would be posted without posting.
- Queue mail for new items with reminders at doubling intervals from 3 to 30 days.
- PayPal settings, a connection test and a manual run on the setup page.
- Queue filter on the transaction list and the automatic decision with its reason under each
  status.
- Belege page per transaction with upload, download and attaching them to the matched supplier
  invoice.
- "Create supplier invoice from transaction", which creates and validates the invoice, attaches the
  Belege, confirms the match and can post the payment in the same step.
- Belege inbox for receipts whose payment is not known yet, with assignment to a transaction.
- Home-page box listing the queue and the inbox count.
- PHPUnit suite in `tests/Unit`, run with `composer test`, and a GitHub workflow running it on
  PHP 7.4 and 8.2. Composer resolves dependencies for PHP 7.4, the module's minimum.
- `docs/paypal.md` and a section on this fork in `README.md`.
- php-cs-fixer config applying Symfony rules to the files this fork adds.
- German translation `langs/de_DE`.

### Changed

- Bank lines posted from a PayPal provider carry the PayPal transaction ID in the cheque number
  field instead of the payer's free-text reference.

## [0.5.1] - 2026-09-16

### Added

- Upstream base of this fork.
