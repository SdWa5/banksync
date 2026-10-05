<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

/*
 * Symfony coding standards for the files this fork adds. Upstream files keep their own style,
 * so rebasing onto upstream stays cheap.
 */
$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('vendor')
    ->path(['#^class/provider/paypal#', '#^class/banksyncautopost#', '#^class/banksyncqueuenotifier#', '#^class/banksyncpaypalsync#', '#^tests/Unit/#', '#^tests/bootstrap\.php$#']);

return (new PhpCsFixer\Config())
    ->setRules(['@Symfony' => true])
    ->setFinder($finder);
