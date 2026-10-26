<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

use PHPUnit\Framework\TestCase;

final class BankSyncTransactionIgnorerTest extends TestCase
{
    /**
     * @dataProvider refusedFacts
     */
    public function testRefusesWhatReachedTheBooks(array $facts): void
    {
        self::assertNotNull(BankSyncTransactionIgnorer::refusal($facts + self::open()));
    }

    public function refusedFacts(): iterable
    {
        yield 'posted' => [['status' => 'posted']];
        yield 'bank line' => [['fk_bank' => 42]];
        yield 'posting in progress' => [['open_postings' => 1]];
        yield 'confirmed match' => [['confirmed_matches' => 1]];
    }

    /**
     * @dataProvider openStatuses
     */
    public function testAllowsAnOpenTransaction(string $status): void
    {
        self::assertNull(BankSyncTransactionIgnorer::refusal(['status' => $status] + self::open()));
    }

    public function openStatuses(): iterable
    {
        yield 'new' => ['new'];
        yield 'matched' => ['matched'];
        yield 'partially matched' => ['partially_matched'];
        yield 'error' => ['error'];
    }

    private static function open(): array
    {
        return ['status' => 'new', 'fk_bank' => 0, 'open_postings' => 0, 'confirmed_matches' => 0];
    }
}
