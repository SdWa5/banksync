# Dolibarr BankSync

BankSync is a Dolibarr external module for importing, classifying, reconciling and posting bank transactions from multiple data sources through a provider-neutral staging layer.

## This fork

`SdWa5/banksync` is a fork of [`vanyolai/dolibarr-banksync`](https://github.com/vanyolai/dolibarr-banksync), based on upstream commit `6c3001b` (0.5.1). It adds:

- a **PayPal provider** reading the Transaction Search API, with fees as entries of their own and the PayPal transaction ID on every bank line;
- a **daily scheduled job** that fetches PayPal from a cutover date, stages, matches and applies a narrow **auto-post policy** (fees, single confirmed supplier invoices, reimbursements whose reference names the invoices), with a dry-run default;
- a **queue** for everything else, with the reason per transaction, Belege per transaction, a Belege inbox, "create supplier invoice from transaction", a home-page box and mail with backoff reminders;
- a PHPUnit suite.

Setup, the policy and the queue are described in [docs/paypal.md](docs/paypal.md). Changes are listed in [CHANGELOG.md](CHANGELOG.md). The fork's own files follow Symfony coding standards (`.php-cs-fixer.dist.php`), and upstream files keep their style so rebases stay cheap.

## Current status: 0.4.1

The first provider is **BinX CSV**. The module currently:

- installs as a standard Dolibarr external module;
- parses BinX transaction-history CSV exports including their metadata block;
- normalizes transactions into provider-neutral DTOs;
- stores imports and transactions in dedicated staging tables;
- prevents duplicate imports both at file level and transaction-entry level;
- preserves the BinX transaction identifier for grouping;
- creates a separate deterministic entry identifier because one BinX transaction ID can occur on multiple accounting rows (for example a transfer and its fee);
- discovers provider source accounts and maps them explicitly to native Dolibarr bank accounts;
- proposes exact account-number/IBAN matches but requires manual confirmation before posting;
- classifies bank events separately from reconciliation targets (`transfer`, `card`, `direct_debit`, `bank_fee`, `cash`, `conversion`, etc.);
- maps known BinX codes (`CDPT`, `DMCT`, `CAPA`, `CHRG`, `PPCF`) to provider-neutral event types and compatible native Dolibarr payment codes;
- searches native Dolibarr customer invoices, supplier invoices, salaries and social-contribution/tax entries for reconciliation candidates;
- scores candidates using amount, document reference, counterparty bank account, normalized name and date proximity;
- uses merchant-aware matching for card transactions where provider merchant text differs from the legal partner name;
- stores suggestions and human decisions in a generic N:N reconciliation/allocation model;
- supports partial payments and one bank transaction allocated across multiple invoices;
- provides manual reconciliation search when automatic matching produces no useful candidate;
- provides filtering, numbered pagination and list-position restoration during sequential reconciliation;
- provides a read-only native posting preview before any Dolibarr core mutation;
- can explicitly post confirmed company-currency customer/supplier invoice payments through native `Paiement` / `PaiementFourn` APIs;
- can explicitly post bank fees through native `PaymentVarious`, which creates its linked Dolibarr bank line;
- stores a BankSync posting audit record with a unique transaction boundary so the same bank transaction cannot be posted twice;
- never writes directly to Dolibarr core business tables when a native domain API exists.

Native posting is deliberately **manual and explicit**: reconciliation does not automatically create payments. A user reviews the posting preview and then confirms the real Dolibarr operation separately. The only exception is this fork's auto-post policy for PayPal, which is off until it is switched on in setup.

## Repository and development model

This repository is the **canonical development source** of the BankSync module. Development, commits, tests and releases must be made here first.

The module is embedded into `vanyolai/dolibarr` as a squash-merged Git subtree:

```text
repository: vanyolai/dolibarr-banksync
branch:     main
consumer:   vanyolai/dolibarr
branch:     23.0
prefix:     htdocs/custom/banksync
```

A local Dolibarr checkout can configure the source repository as a remote once:

```bash
git remote add banksync https://github.com/vanyolai/dolibarr-banksync.git
git fetch banksync
```

Update the Dolibarr integration with:

```bash
git fetch banksync
git subtree pull \
  --prefix=htdocs/custom/banksync \
  banksync main \
  --squash
```

Normal development direction is **BankSync repository → Dolibarr subtree**. If a change is ever made inside the Dolibarr subtree first, export it back before continuing normal development:

```bash
git subtree split --prefix=htdocs/custom/banksync -b banksync-export
git push banksync banksync-export:main
```

## Installation and development upgrades

In the integrated `vanyolai/dolibarr` checkout the module is located at:

```text
htdocs/custom/banksync/
```

For an independent installation:

```bash
cd /path/to/dolibarr/htdocs/custom
git clone https://github.com/vanyolai/dolibarr-banksync.git banksync
```

Then open **Home → Setup → Modules/Applications**, find **Bank Sync**, and enable it.

Development schema changes are migrated lazily by `BankSyncSchema` on BankSync page access. Releases that introduce new Dolibarr permissions should also have the module reinitialized (disable/enable in a development installation) so Dolibarr creates the new permission definitions.

Version 0.4.1 adds a dedicated `post` permission for native posting. Grant it only to users who should be allowed to create real Dolibarr payments/bank entries.

## BinX CSV format

The provider expects a BinX transaction export with the metadata block followed by the transaction header, for example:

```text
Konverziós számla száma,...
E-pénz rendelkezési számla száma,...
Időszak kezdete,2026.08.02
Időszak vége,2026.08.31
Devizanem,HUF
,
Értéknap,Könyvelés napja,Terhelés vagy jóváírás (T/J),Összeg,...
```

Required transaction columns are validated by name, so malformed or incompatible exports fail before anything is written to staging.

## Account mapping

```text
provider source account
       │
       ▼
llx_banksync_account
       │ explicit confirmed mapping
       ▼
llx_bank_account
```

If exactly one open Dolibarr bank account has the same normalized account number or IBAN and a compatible currency, BankSync stores it as a **suggestion only**. A user must confirm the mapping before posting may use it.

## Transaction classification

Bank-event classification is deliberately separate from business reconciliation. `transfer` or `card` describes how money moved; reconciliation describes what business object the movement settles.

Current normalized event types include:

```text
transfer
card
direct_debit
bank_fee
cash
conversion
internal_transfer
refund
other
```

Current exact BinX mappings:

```text
CDPT -> transfer -> VIR
DMCT -> transfer -> VIR
CAPA -> card     -> CB
CHRG -> bank_fee
PPCF -> bank_fee / card fee -> CB when posted
```

For a `CHRG` companion fee, native posting may inherit the compatible payment mode from another BinX row sharing the same external transaction ID; otherwise it falls back to `VIR`.

## Reconciliation and allocation

`llx_banksync_match` is an N:N allocation table between one bank transaction and one or more Dolibarr targets. Target types include:

```text
customer_invoice
supplier_invoice
salary
social_contribution
expense_report
tax
bank_fee
internal_transfer
other
```

Candidate confidence is calculated from independent signals such as exact/near amount, invoice reference in the bank reference, partner bank-account match, normalized partner/employee name and date proximity. Suggestions are advisory and require explicit confirmation, including 100% candidates.

Transaction reconciliation states are:

```text
new
partially_matched
matched
posted
```

HUF reconciliation tolerates up to ±1 HUF for matching status; other currencies currently tolerate ±0.01. A non-zero tolerated difference remains visible and **blocks native posting** until an accountant-approved rounding rule is implemented.

## Native posting

Posting is a separate explicit workflow:

```text
import
  -> classification
  -> candidate matching
  -> human-confirmed allocations
  -> posting preview
  -> explicit confirmation
  -> native Dolibarr objects
```

Current native targets:

```text
customer invoice
  -> Paiement::create()
  -> Paiement::addPaymentToBank()

supplier invoice
  -> PaiementFourn::create()
  -> PaiementFourn::addPaymentToBank()

bank fee
  -> PaymentVarious::create()
  -> native linked bank line
```

BankSync does not issue raw `INSERT`/`UPDATE` statements against Dolibarr core business tables for these operations. Core mutations are performed by Dolibarr's own domain objects. BankSync SQL writes are confined to its own staging, reconciliation and audit tables.

Posting is wrapped in an outer DoliDB transaction together with the BankSync audit/status update. Dolibarr's nested transaction depth keeps the native object operations inside the same atomic database transaction.

### Idempotency

`llx_banksync_posting` has a unique constraint on:

```text
(entity, fk_transaction)
```

It stores the native object type/id and the created native bank-line id. A second submit for an already-posted BankSync transaction is rejected and the posting preview becomes an audit view linked to the created native object.

### Current posting restrictions

The first native-posting milestone intentionally blocks:

- foreign-currency transactions (until an explicit exchange-rate workflow exists);
- credit-note settlement (until signed allocations are implemented);
- mixed native target types in one transaction;
- invoice allocations spanning multiple third parties in one native payment;
- non-zero rounding differences even if they are within reconciliation tolerance;
- allocations that exceed the invoice's current outstanding amount.

If Dolibarr Accounting is enabled, bank-fee posting requires an explicit **Bank fee accounting account** in BankSync setup. The value must be agreed with the accountant; BankSync does not guess an accounting account.

## Deduplication model

BinX `Tranzakció azonosító` is stored as `external_transaction_id`, but is **not assumed to be unique per accounting row**.

BankSync derives `external_entry_id` as a SHA-256 fingerprint from the normalized accounting entry. The staging uniqueness constraint is:

```text
(entity, provider, account_number, external_entry_id)
```

This allows overlapping statement periods to be imported safely while keeping related BinX rows grouped under their original transaction ID.

## Architecture

```text
BinX CSV ───────┐
Wise API ───────┤
CAMT.053 ───────┤   BankDataProviderInterface
MT940 ──────────┘              │
                               ▼
                         BankStatement
                               │
                         BankTransaction[]
                               │
                               ▼
                       source-account mapping
                               │
                               ▼
                       event classification
                               │
                               ▼
                        BankSync staging
                               │
                               ▼
                  scored reconciliation candidates
                               │
                         human confirmation
                               │
                               ▼
                         posting preview
                               │
                      explicit confirmation
                               │
                               ▼
                  native Dolibarr payment/posting
```

Provider-specific structures must not leak into reconciliation logic. The raw source row is retained as JSON for diagnostics and future migrations.

## Development

Unit tests that do not require a Dolibarr runtime, run in Docker:

```bash
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD:/app" -w /app composer:2 sh -c 'composer install && composer test'
```

Smoke tests from upstream:

```bash
php tests/binx_parser_smoke.php
php tests/classifier_smoke.php
```

Code style of the fork's own files:

```bash
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD:/app" -w /app ghcr.io/php-cs-fixer/php-cs-fixer:3-php8.2 fix --dry-run --diff
```

GitHub Actions runs `php -l` against every PHP file and PHPUnit on PHP 7.4 and 8.2 on pushes and pull requests.

## Roadmap

1. Validate native customer/supplier invoice posting against real full and partial payments.
2. Validate `PaymentVarious` bank-fee posting and settle the accounting account with the accountant.
3. Implement signed credit-note settlement components.
4. Implement native salary posting through `PaymentSalary`.
5. Implement native social-contribution/tax posting through the corresponding Dolibarr workflow.
6. Add controlled internal-transfer and unmatched-generic-bank workflows.
7. Add explicit reversal/unposting assistance while preserving audit history.
8. Add a Wise API provider.
9. Add CAMT.053 / MT940 statement providers.

## License

GPL-3.0-or-later, matching Dolibarr's licensing model.
