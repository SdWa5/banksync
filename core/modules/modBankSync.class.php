<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for BankSync.
 */
class modBankSync extends DolibarrModules
{
    /**
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $conf;

        $this->db = $db;
        $this->numero = 500301;
        $this->rights_class = 'banksync';
        $this->family = 'financial';
        $this->module_position = '90';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = 'BankSyncDescription';
        $this->descriptionlong = 'BankSyncDescriptionLong';
        $this->version = '1.1.0';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->picto = 'bank';

        $this->module_parts = array(
            'triggers' => 0,
            'login' => 0,
            'substitutions' => 0,
            'menus' => 0,
            'tpl' => 0,
            'barcode' => 0,
            'models' => 0,
            'printing' => 0,
            'theme' => 0,
            'css' => array(),
            'js' => array(),
            'hooks' => array(),
            'moduleforexternal' => 0,
        );

        $this->dirs = array('/banksync/temp', '/banksync/inbox', '/banksync/transaction');
        $this->config_page_url = array('setup.php@banksync');
        $this->hidden = false;
        $this->depends = array();
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array('banksync@banksync');
        $this->phpmin = array(7, 4);
        $this->need_dolibarr_version = array(19, 0);
        $this->need_javascript_ajax = 0;
        $this->const = array();

        $this->boxes = array(
            0 => array('file' => 'box_banksync_queue.php@banksync', 'note' => 'BankSyncBoxQueue', 'enabledbydefaulton' => 'Home'),
        );

        // Daily PayPal fetch. Created disabled; it is switched on in Setup > Scheduled jobs once the
        // PayPal settings are filled in and a manual run has been reviewed.
        $this->cronjobs = array(
            0 => array(
                'label' => 'BankSyncPayPalDailyJob',
                'jobtype' => 'method',
                'class' => '/banksync/class/banksyncpaypalsync.class.php',
                'objectname' => 'BankSyncPayPalSync',
                'method' => 'runScheduled',
                'parameters' => '',
                'comment' => 'BankSyncPayPalDailyJobComment',
                'frequency' => 1,
                'unitfrequency' => 86400,
                'status' => 0,
                'test' => "isModEnabled('banksync')",
                'priority' => 50,
            ),
        );

        if (!isModEnabled('banksync')) {
            $conf->banksync = new stdClass();
            $conf->banksync->enabled = 0;
        }

        $this->rights = array();
        $r = 0;
        $this->rights[$r][0] = 5003011;
        $this->rights[$r][1] = 'Read bank synchronization data';
        $this->rights[$r][2] = 'r';
        $this->rights[$r][3] = 1;
        $this->rights[$r][4] = 'read';
        $r++;

        $this->rights[$r][0] = 5003012;
        $this->rights[$r][1] = 'Import bank statements and manage source account mappings';
        $this->rights[$r][2] = 'w';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'import';
        $r++;

        $this->rights[$r][0] = 5003013;
        $this->rights[$r][1] = 'Post reconciled bank transactions into native Dolibarr records';
        $this->rights[$r][2] = 'w';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'post';
        $r++;

        $this->menu = array();
        $r = 0;

        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=bank',
            'type' => 'left',
            'titre' => 'BankSync',
            'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle paddingright"'),
            'mainmenu' => 'bank',
            'leftmenu' => 'banksync',
            'url' => '/banksync/index.php?mainmenu=bank&leftmenu=banksync',
            'langs' => 'banksync@banksync',
            'position' => 90,
            'enabled' => "isModEnabled('banksync')",
            'perms' => '$user->hasRight("banksync", "read")',
            'target' => '',
            'user' => 0,
        );

        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=bank,fk_leftmenu=banksync',
            'type' => 'left',
            'titre' => 'BankSyncDashboard',
            'mainmenu' => 'bank',
            'leftmenu' => 'banksync_dashboard',
            'url' => '/banksync/index.php?mainmenu=bank&leftmenu=banksync_dashboard',
            'langs' => 'banksync@banksync',
            'position' => 91,
            'enabled' => "isModEnabled('banksync')",
            'perms' => '$user->hasRight("banksync", "read")',
            'target' => '',
            'user' => 0,
        );

        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=bank,fk_leftmenu=banksync',
            'type' => 'left',
            'titre' => 'BankSyncAccounts',
            'mainmenu' => 'bank',
            'leftmenu' => 'banksync_accounts',
            'url' => '/banksync/accounts.php?mainmenu=bank&leftmenu=banksync_accounts',
            'langs' => 'banksync@banksync',
            'position' => 92,
            'enabled' => "isModEnabled('banksync')",
            'perms' => '$user->hasRight("banksync", "read")',
            'target' => '',
            'user' => 0,
        );

        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=bank,fk_leftmenu=banksync',
            'type' => 'left',
            'titre' => 'BankSyncTransactions',
            'mainmenu' => 'bank',
            'leftmenu' => 'banksync_transactions',
            'url' => '/banksync/transactions.php?mainmenu=bank&leftmenu=banksync_transactions',
            'langs' => 'banksync@banksync',
            'position' => 93,
            'enabled' => "isModEnabled('banksync')",
            'perms' => '$user->hasRight("banksync", "read")',
            'target' => '',
            'user' => 0,
        );

        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=bank,fk_leftmenu=banksync',
            'type' => 'left',
            'titre' => 'BankSyncImport',
            'mainmenu' => 'bank',
            'leftmenu' => 'banksync_import',
            'url' => '/banksync/import.php?mainmenu=bank&leftmenu=banksync_import',
            'langs' => 'banksync@banksync',
            'position' => 94,
            'enabled' => "isModEnabled('banksync')",
            'perms' => '$user->hasRight("banksync", "import")',
            'target' => '',
            'user' => 0,
        );

        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=bank,fk_leftmenu=banksync',
            'type' => 'left',
            'titre' => 'BankSyncBelegInboxMenu',
            'mainmenu' => 'bank',
            'leftmenu' => 'banksync_belege',
            'url' => '/banksync/belege.php?mainmenu=bank&leftmenu=banksync_belege',
            'langs' => 'banksync@banksync',
            'position' => 95,
            'enabled' => "isModEnabled('banksync')",
            'perms' => '$user->hasRight("banksync", "read")',
            'target' => '',
            'user' => 0,
        );
    }

    public function init($options = '')
    {
        $result = $this->_load_tables('/banksync/sql/');
        if ($result < 0) return -1;
        $sql = array();
        return $this->_init($sql, $options);
    }

    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }
}
