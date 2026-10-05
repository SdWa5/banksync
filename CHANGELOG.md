# Changelog

All notable changes to this fork are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

This fork `SdWa5/banksync` starts at upstream `vanyolai/dolibarr-banksync` commit `6c3001b`
(0.5.1, 2026-09-16). Upstream's history before that point lives in its own git log.

## [Unreleased]

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
- PHPUnit suite in `tests/Unit`, run with `composer test`.
- php-cs-fixer config applying Symfony rules to the files this fork adds.

### Changed

- Bank lines posted from a PayPal provider carry the PayPal transaction ID in the cheque number
  field instead of the payer's free-text reference.

## [0.5.1] - 2026-09-16

### Added

- Upstream base of this fork.
