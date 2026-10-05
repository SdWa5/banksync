<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

/**
 * Small forward-only schema migrator for BankSync development releases.
 *
 * Dolibarr's module installer creates fresh tables from sql/, while this class keeps
 * already-enabled development installations compatible when new staging columns/tables
 * are introduced. The current target database is MariaDB/MySQL, matching the deployment.
 */
class BankSyncSchema
{
    const VERSION = '1.0.0';

    /**
     * @param DoliDB $db Database handler
     * @return void
     * @throws Exception
     */
    public static function ensure($db)
    {
        $prefix = $db->prefix();

        self::query($db, "CREATE TABLE IF NOT EXISTS {$prefix}banksync_account (\n"
            ." rowid INTEGER AUTO_INCREMENT PRIMARY KEY,\n"
            ." entity INTEGER DEFAULT 1 NOT NULL,\n"
            ." provider VARCHAR(64) NOT NULL,\n"
            ." source_account_key VARCHAR(191) NOT NULL,\n"
            ." source_account_number VARCHAR(128) NOT NULL,\n"
            ." conversion_account_number VARCHAR(128),\n"
            ." source_account_label VARCHAR(255),\n"
            ." currency VARCHAR(8) NOT NULL,\n"
            ." fk_bank_account INTEGER,\n"
            ." suggested_fk_bank_account INTEGER,\n"
            ." mapping_status VARCHAR(32) DEFAULT 'unmapped' NOT NULL,\n"
            ." mapping_method VARCHAR(64),\n"
            ." date_creation DATETIME NOT NULL,\n"
            ." fk_user_create INTEGER,\n"
            ." fk_user_modif INTEGER,\n"
            ." tms TIMESTAMP\n"
            .") ENGINE=innodb");

        self::query($db, "CREATE TABLE IF NOT EXISTS {$prefix}banksync_match (\n"
            ." rowid INTEGER AUTO_INCREMENT PRIMARY KEY,\n"
            ." entity INTEGER DEFAULT 1 NOT NULL,\n"
            ." fk_transaction INTEGER NOT NULL,\n"
            ." target_type VARCHAR(64) NOT NULL,\n"
            ." target_id INTEGER NOT NULL,\n"
            ." allocated_amount DECIMAL(24,8) NOT NULL,\n"
            ." confidence INTEGER DEFAULT 0 NOT NULL,\n"
            ." match_method VARCHAR(64),\n"
            ." status VARCHAR(32) DEFAULT 'suggested' NOT NULL,\n"
            ." note TEXT,\n"
            ." date_creation DATETIME NOT NULL,\n"
            ." fk_user_create INTEGER,\n"
            ." fk_user_modif INTEGER,\n"
            ." tms TIMESTAMP\n"
            .") ENGINE=innodb");

        self::query($db, "CREATE TABLE IF NOT EXISTS {$prefix}banksync_posting (\n"
            ." rowid INTEGER AUTO_INCREMENT PRIMARY KEY,\n"
            ." entity INTEGER DEFAULT 1 NOT NULL,\n"
            ." fk_transaction INTEGER NOT NULL,\n"
            ." posting_kind VARCHAR(64) NOT NULL,\n"
            ." native_object_type VARCHAR(64),\n"
            ." native_object_id INTEGER,\n"
            ." fk_bank INTEGER,\n"
            ." status VARCHAR(32) DEFAULT 'processing' NOT NULL,\n"
            ." error_message TEXT,\n"
            ." date_creation DATETIME NOT NULL,\n"
            ." date_posted DATETIME,\n"
            ." fk_user_create INTEGER,\n"
            ." fk_user_post INTEGER,\n"
            ." tms TIMESTAMP\n"
            .") ENGINE=innodb");

        self::query($db, "CREATE TABLE IF NOT EXISTS {$prefix}banksync_posting_item (\n"
            ." rowid INTEGER AUTO_INCREMENT PRIMARY KEY,\n"
            ." entity INTEGER DEFAULT 1 NOT NULL,\n"
            ." fk_posting INTEGER NOT NULL,\n"
            ." target_type VARCHAR(64),\n"
            ." target_id INTEGER,\n"
            ." native_object_type VARCHAR(64) NOT NULL,\n"
            ." native_object_id INTEGER NOT NULL,\n"
            ." fk_bank INTEGER,\n"
            ." amount DECIMAL(24,8) NOT NULL,\n"
            ." date_creation DATETIME NOT NULL\n"
            .") ENGINE=innodb");

        self::query($db, "CREATE TABLE IF NOT EXISTS {$prefix}banksync_tax_account_map (\n"
            ." rowid INTEGER AUTO_INCREMENT PRIMARY KEY,\n"
            ." entity INTEGER DEFAULT 1 NOT NULL,\n"
            ." target_account_number VARCHAR(128) NOT NULL,\n"
            ." fk_charge_type INTEGER NOT NULL,\n"
            ." label VARCHAR(255),\n"
            ." active INTEGER DEFAULT 1 NOT NULL,\n"
            ." date_creation DATETIME NOT NULL,\n"
            ." fk_user_create INTEGER,\n"
            ." fk_user_modif INTEGER,\n"
            ." tms TIMESTAMP\n"
            .") ENGINE=innodb");

        // VAT is a separate Dolibarr business domain (`tva` / `PaymentVAT`), not a
        // c_chargesociales type, so destination accounts are kept in their own map.
        self::query($db, "CREATE TABLE IF NOT EXISTS {$prefix}banksync_vat_account_map (\n"
            ." rowid INTEGER AUTO_INCREMENT PRIMARY KEY,\n"
            ." entity INTEGER DEFAULT 1 NOT NULL,\n"
            ." target_account_number VARCHAR(128) NOT NULL,\n"
            ." label VARCHAR(255),\n"
            ." active INTEGER DEFAULT 1 NOT NULL,\n"
            ." date_creation DATETIME NOT NULL,\n"
            ." fk_user_create INTEGER,\n"
            ." fk_user_modif INTEGER,\n"
            ." tms TIMESTAMP\n"
            .") ENGINE=innodb");

        // Latest automatic decision per transaction: the queue reason or the audit of an automatic posting.
        self::query($db, "CREATE TABLE IF NOT EXISTS {$prefix}banksync_autopost (\n"
            ." rowid INTEGER AUTO_INCREMENT PRIMARY KEY,\n"
            ." entity INTEGER DEFAULT 1 NOT NULL,\n"
            ." fk_transaction INTEGER NOT NULL,\n"
            ." decision VARCHAR(32) NOT NULL,\n"
            ." reason VARCHAR(64) NOT NULL,\n"
            ." detail TEXT,\n"
            ." notified INTEGER DEFAULT 0 NOT NULL,\n"
            ." date_creation DATETIME NOT NULL,\n"
            ." date_decision DATETIME NOT NULL,\n"
            ." fk_user INTEGER,\n"
            ." tms TIMESTAMP\n"
            .") ENGINE=innodb");
        self::ensureIndex($db, $prefix.'banksync_autopost', 'uk_banksync_autopost_transaction',
            'UNIQUE KEY uk_banksync_autopost_transaction (entity, fk_transaction)');

        self::ensureColumn($db, $prefix.'banksync_import', 'fk_banksync_account', 'INTEGER NULL');
        self::ensureColumn($db, $prefix.'banksync_transaction', 'fk_banksync_account', 'INTEGER NULL');
        self::ensureColumn($db, $prefix.'banksync_transaction', 'bank_event_type', 'VARCHAR(32) NULL');
        self::ensureColumn($db, $prefix.'banksync_transaction', 'dolibarr_payment_code', 'VARCHAR(16) NULL');
        self::ensureColumn($db, $prefix.'banksync_transaction', 'classification_confidence', 'INTEGER DEFAULT 0 NOT NULL');
        self::ensureColumn($db, $prefix.'banksync_transaction', 'classification_method', 'VARCHAR(64) NULL');
        self::ensureColumn($db, $prefix.'banksync_transaction', 'fk_bank', 'INTEGER NULL');

        self::ensureIndex($db, $prefix.'banksync_account', 'uk_banksync_account_source',
            'UNIQUE KEY uk_banksync_account_source (entity, provider, source_account_key, currency)');
        self::ensureIndex($db, $prefix.'banksync_account', 'idx_banksync_account_bank',
            'KEY idx_banksync_account_bank (fk_bank_account)');
        self::ensureIndex($db, $prefix.'banksync_match', 'uk_banksync_match_target',
            'UNIQUE KEY uk_banksync_match_target (fk_transaction, target_type, target_id)');
        self::ensureIndex($db, $prefix.'banksync_match', 'idx_banksync_match_transaction',
            'KEY idx_banksync_match_transaction (fk_transaction)');
        self::ensureIndex($db, $prefix.'banksync_transaction', 'idx_banksync_transaction_source_account',
            'KEY idx_banksync_transaction_source_account (fk_banksync_account)');
        self::ensureIndex($db, $prefix.'banksync_transaction', 'idx_banksync_transaction_event_type',
            'KEY idx_banksync_transaction_event_type (bank_event_type)');
        self::ensureIndex($db, $prefix.'banksync_posting', 'uk_banksync_posting_transaction',
            'UNIQUE KEY uk_banksync_posting_transaction (entity, fk_transaction)');
        self::ensureIndex($db, $prefix.'banksync_posting', 'idx_banksync_posting_native',
            'KEY idx_banksync_posting_native (native_object_type, native_object_id)');
        self::ensureIndex($db, $prefix.'banksync_posting', 'idx_banksync_posting_bank',
            'KEY idx_banksync_posting_bank (fk_bank)');
        self::ensureIndex($db, $prefix.'banksync_posting_item', 'idx_banksync_posting_item_posting',
            'KEY idx_banksync_posting_item_posting (fk_posting)');
        self::ensureIndex($db, $prefix.'banksync_posting_item', 'idx_banksync_posting_item_native',
            'KEY idx_banksync_posting_item_native (native_object_type, native_object_id)');

        // 0.5.0 originally allowed one contribution type per destination account. Keep
        // development installations forward-compatible when upgrading to the more general
        // account+type mapping model.
        self::dropIndexIfExists($db, $prefix.'banksync_tax_account_map', 'uk_banksync_tax_account');
        self::ensureIndex($db, $prefix.'banksync_tax_account_map', 'uk_banksync_tax_account_type',
            'UNIQUE KEY uk_banksync_tax_account_type (entity, target_account_number, fk_charge_type)');
        self::ensureIndex($db, $prefix.'banksync_tax_account_map', 'idx_banksync_tax_charge_type',
            'KEY idx_banksync_tax_charge_type (fk_charge_type)');
        self::ensureIndex($db, $prefix.'banksync_vat_account_map', 'uk_banksync_vat_account',
            'UNIQUE KEY uk_banksync_vat_account (entity, target_account_number)');

        // A bank fee is already fully reconciled once it has been classified as such:
        // it has no invoice/salary/tax business object to allocate against. Posting remains
        // a separate explicit workflow, but reconciliation must not leave it in the "new" queue.
        self::query($db, "UPDATE {$prefix}banksync_transaction SET status = 'matched'"
            ." WHERE bank_event_type = 'bank_fee' AND status = 'new'");
    }

    private static function ensureColumn($db, $table, $column, $definition)
    {
        $resql = $db->query("SHOW COLUMNS FROM {$table} LIKE '".$db->escape($column)."'");
        if (!$resql) throw new RuntimeException($db->lasterror());
        $exists = $db->num_rows($resql) > 0;
        $db->free($resql);
        if (!$exists) self::query($db, "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }

    private static function ensureIndex($db, $table, $index, $definition)
    {
        $resql = $db->query("SHOW INDEX FROM {$table} WHERE Key_name = '".$db->escape($index)."'");
        if (!$resql) throw new RuntimeException($db->lasterror());
        $exists = $db->num_rows($resql) > 0;
        $db->free($resql);
        if (!$exists) self::query($db, "ALTER TABLE {$table} ADD {$definition}");
    }

    private static function dropIndexIfExists($db, $table, $index)
    {
        $resql = $db->query("SHOW INDEX FROM {$table} WHERE Key_name = '".$db->escape($index)."'");
        if (!$resql) throw new RuntimeException($db->lasterror());
        $exists = $db->num_rows($resql) > 0;
        $db->free($resql);
        if ($exists) self::query($db, "ALTER TABLE {$table} DROP INDEX {$index}");
    }

    private static function query($db, $sql)
    {
        if (!$db->query($sql)) throw new RuntimeException($db->lasterror());
    }
}
