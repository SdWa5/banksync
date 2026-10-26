# TODO

Open work on this fork. Operating steps on erp.sdwa5.org, such as switching on automatic posting, are tracked in
[`sdwa5-vps/TODO.md`](https://github.com/SdWa5/vps/blob/main/TODO.md).

1. **Read the invoice number and the items from an uploaded Beleg** (ca. 6 Stunden). A supplier invoice the
   auto-poster creates carries PayPal's `invoice_id` as supplier reference and the PayPal reference as line label,
   for Thomann `capture-59968406` instead of the invoice number `92814823` and the items. When a Beleg arrives for
   such an invoice, a Composer PDF parser such as `smalot/pdfparser` would read the number and set `ref_supplier`,
   which Dolibarr's invoice card allows on a validated invoice (`fourn/facture/card.php:408`). The card offers line
   edits on a draft only, while `FactureFournisseur::updateline()` checks no status, so whether the line label of a
   validated, paid invoice can be changed safely has to be measured first; the fallback is the note. Thomann's
   invoice of 2026-10-16 is a text PDF; scanned receipts would need OCR, for example from Paperless.
2. **Receive Belege by mail** (ca. 8 Stunden, plus deciding on a mailbox). Dolibarr's email collector accepts module
   operations through the hook `doCollectImapOneCollector`, so a BankSync operation can put attachments into the
   Belege inbox. `mail@sdwa5.org` is a Google Group and cannot be read over IMAP, so a real mailbox or a service
   account with OAuth is needed, for example a Workspace user such as `belege@` that is a member of the group.
   Thomann mails its invoice PDF on its own ("Versandbenachrichtigung und Rechnung" to `mail@sdwa5.org`, measured
   on 2026-10-16 at 13:30, the day of the payment), so together with items 1 and 3 a Thomann purchase needs nobody.
   Invoices also come as shop downloads (Amazon), to members' private addresses and through the Finanzen WhatsApp
   group, so mail covers only part of them.
3. **Assign inbox Belege to their payments automatically** (ca. 6 Stunden). Match the amount and the supplier name
   in the PDF text against open outgoing payments, with the parser from item 1, and leave every ambiguous Beleg to
   a person. Worth it only together with item 2.
4. **REST API for the remaining UI actions** (ca. 16 Stunden). Uploading on the Belege page and to the inbox,
   assigning from the inbox, reconciliation (suggestions, confirm, reject), posting, the import and the source
   account mapping have no endpoint yet. Belege of a supplier invoice already go through the core documents API.
5. **Give the module a more general name** (ca. 16 Stunden, once a name is chosen). It now covers PayPal,
   automatic posting, Belege and an API. A rename touches the module folder, the rights, the `BANKSYNC_*`
   settings, the `llx_banksync_*` tables, the API path and the scheduled job, so it needs a migration and is a
   2.0.0 release.
6. **Refresh `composer.lock`** (ca. 10 Minuten). `composer install` warns that the lock file is not up to date with
   `composer.json`, measured on 2026-10-26 with PHP 8.5.
7. **Translate the fork's language keys into Hungarian** (ca. 1 Stunde). `langs/hu_HU` holds upstream's keys only.
