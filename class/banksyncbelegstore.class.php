<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

/**
 * Where Belege of the queue live, and how they move on.
 *
 * A Beleg belongs either to a staged transaction (`transaction/<id>/`) or, while nobody knows its
 * payment yet, to the inbox (`inbox/`). Both sit under the module's document directory, so
 * Dolibarr's document.php serves them with the module's read right. Once a supplier invoice exists,
 * the Belege are copied into that invoice's own document directory, which is where an auditor
 * looks first.
 */
class BankSyncBelegStore
{
    public const INBOX = 'inbox';

    /** @var string */
    private $root;

    public function __construct(?string $root = null)
    {
        global $conf;

        $this->root = rtrim($root ?? (string) $conf->banksync->dir_output, '/');
    }

    /**
     * Relative path used with modulepart=banksync, for example "transaction/12".
     */
    public static function transactionPart(int $transactionId): string
    {
        return 'transaction/'.$transactionId;
    }

    public function dir(string $part): string
    {
        return $this->root.'/'.$part;
    }

    /**
     * @return array<int, array<string, mixed>> dol_dir_list() rows, newest first
     */
    public function files(string $part): array
    {
        $dir = $this->dir($part);
        if (!is_dir($dir)) {
            return [];
        }

        return dol_dir_list($dir, 'files', 0, '', '(\.meta|_preview.*\.png)$', 'date', SORT_DESC, 1);
    }

    public function count(string $part): int
    {
        return \count($this->files($part));
    }

    /**
     * Moves one file from the inbox to a transaction.
     */
    public function assignFromInbox(string $filename, int $transactionId): void
    {
        $filename = dol_sanitizeFileName(basename($filename));
        $source = $this->dir(self::INBOX).'/'.$filename;
        if ('' === $filename || !is_file($source)) {
            throw new RuntimeException('BankSyncBelegNotFound');
        }
        $targetDir = $this->dir(self::transactionPart($transactionId));
        dol_mkdir($targetDir);
        if (!dol_move($source, $targetDir.'/'.$filename, '0', 0)) {
            throw new RuntimeException('BankSyncBelegMoveFailed');
        }
    }

    /**
     * Copies a transaction's Belege into a supplier invoice's document directory.
     *
     * @return int Number of files copied; files already present there are left alone
     */
    public function copyToSupplierInvoice(int $transactionId, FactureFournisseur $invoice): int
    {
        global $conf;

        $target = $conf->fournisseur->facture->dir_output.'/'.get_exdir($invoice->id, 2, 0, 0, $invoice, 'invoice_supplier').dol_sanitizeFileName($invoice->ref);
        dol_mkdir($target);

        $copied = 0;
        foreach ($this->files(self::transactionPart($transactionId)) as $file) {
            $destination = $target.'/'.$file['name'];
            if (is_file($destination)) {
                continue;
            }
            if (dol_copy($file['fullname'], $destination, '0', 0) < 0) {
                throw new RuntimeException('BankSyncBelegCopyFailed');
            }
            ++$copied;
        }

        return $copied;
    }
}
