<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

use PHPUnit\Framework\TestCase;

final class BankSyncPayPalSyncTest extends TestCase
{
    public function testTheFirstRunStartsAtTheCutover(): void
    {
        $now = new DateTimeImmutable('2026-10-05T06:00:00+02:00');

        [$from, $to] = BankSyncPayPalSync::range('2026-09-09', 14, $now);

        self::assertSame('2026-09-09T00:00:00+02:00', $from->format(DATE_ATOM));
        self::assertSame($now, $to);
    }

    public function testADailyRunLooksBackTheConfiguredDays(): void
    {
        $now = new DateTimeImmutable('2026-10-05T06:00:00+02:00');

        [$from] = BankSyncPayPalSync::range('2026-09-09', 14, $now, new DateTimeImmutable('2026-10-04T06:00:00+02:00'));

        self::assertSame('2026-09-20T06:00:00+02:00', $from->format(DATE_ATOM));
    }

    public function testAJobThatWasDownCatchesUp(): void
    {
        $now = new DateTimeImmutable('2026-12-01T06:00:00+01:00');

        [$from] = BankSyncPayPalSync::range('2026-09-09', 14, $now, new DateTimeImmutable('2026-10-04T06:00:00+02:00'));

        self::assertSame('2026-09-20T06:00:00+02:00', $from->format(DATE_ATOM));
    }

    public function testASyncedUntilInTheFutureCountsAsNow(): void
    {
        $now = new DateTimeImmutable('2026-10-05T06:00:00+02:00');

        [$from] = BankSyncPayPalSync::range('2026-09-09', 14, $now, new DateTimeImmutable('2027-01-01T00:00:00+01:00'));

        self::assertSame('2026-09-21T06:00:00+02:00', $from->format(DATE_ATOM));
    }

    public function testNeverReachesBeforeTheCutover(): void
    {
        [$from] = BankSyncPayPalSync::range('2026-09-09', 14, new DateTimeImmutable('2026-09-15T06:00:00+02:00'), new DateTimeImmutable('2026-09-14T06:00:00+02:00'));

        self::assertSame('2026-09-09T00:00:00+02:00', $from->format(DATE_ATOM));
    }

    public function testFetchesNothingBeforeTheCutover(): void
    {
        [$from] = BankSyncPayPalSync::range('2026-11-01', 14, new DateTimeImmutable('2026-10-05T06:00:00+02:00'));

        self::assertNull($from);
    }

    public function testRejectsAMalformedCutover(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BankSyncPayPalSync::range('09.09.2026', 14, new DateTimeImmutable());
    }

    public function testHashIgnoresOrderButNotContent(): void
    {
        $a = $this->statement(['X', 'Y']);
        $b = $this->statement(['Y', 'X']);
        $c = $this->statement(['X', 'Y', 'Y/fee']);

        self::assertSame(BankSyncPayPalSync::statementHash($a), BankSyncPayPalSync::statementHash($b));
        self::assertNotSame(BankSyncPayPalSync::statementHash($a), BankSyncPayPalSync::statementHash($c));
    }

    /**
     * @param string[] $ids
     */
    private function statement(array $ids): BankStatement
    {
        $statement = new BankStatement();
        $statement->provider = 'paypal_api';
        $statement->accountNumber = 'paypal@sdwa5.org';
        foreach ($ids as $id) {
            $t = new BankTransaction();
            $t->externalEntryId = $id;
            $statement->addTransaction($t);
        }

        return $statement;
    }
}
