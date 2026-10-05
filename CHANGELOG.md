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
- Pending and denied PayPal transactions and other currencies are skipped and counted.
- PHPUnit suite in `tests/Unit`, run with `composer test`.
- php-cs-fixer config applying Symfony rules to the files this fork adds.

### Changed

- Bank lines posted from a PayPal provider carry the PayPal transaction ID in the cheque number
  field instead of the payer's free-text reference.

## [0.5.1] - 2026-09-16

### Added

- Upstream base of this fork.
