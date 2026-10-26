<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

/*
 * Symfony coding standards for the files this fork adds. Upstream files keep their own style,
 * so rebasing onto upstream stays cheap.
 */
$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('vendor')
    ->path(['#^class/provider/paypal#', '#^class/banksyncautopost#', '#^class/banksyncqueuenotifier#', '#^class/banksyncpaypalsync#', '#^class/banksyncbelegstore#', '#^class/banksyncsupplierinvoicefactory#', '#^class/banksyncpurchase#', '#^class/banksyncqueue\.#', '#^class/banksynctransactionignorer#', '#^class/api_banksync#', '#^core/boxes/box_banksync#', '#^belege\.php$#', '#^tests/Unit/#', '#^tests/bootstrap\.php$#']);

return (new PhpCsFixer\Config())
    // Restler only demands authentication for an API class tagged @access protected, so the tag stays.
    ->setRules(['@Symfony' => true, 'phpdoc_no_access' => false])
    ->setFinder($finder);
