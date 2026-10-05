<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

use PHPUnit\Framework\TestCase;

final class BankSyncQueueNotifierTest extends TestCase
{
    private const DAY = 86400;
    private const NOW = 1790000000;

    public function testMailsNewItemsAtOnceAndResetsTheInterval(): void
    {
        self::assertSame(['send' => 'new', 'interval' => 3], BankSyncQueueNotifier::plan(2, 5, self::NOW - 60, 24, self::NOW));
    }

    public function testStaysSilentWhenTheQueueIsEmpty(): void
    {
        self::assertSame(['send' => null, 'interval' => 3], BankSyncQueueNotifier::plan(0, 0, 0, 12, self::NOW));
    }

    public function testWaitsUntilTheIntervalPassed(): void
    {
        self::assertSame(['send' => null, 'interval' => 3], BankSyncQueueNotifier::plan(0, 4, self::NOW - 2 * self::DAY, 3, self::NOW));
    }

    public function testRemindersBackOff(): void
    {
        $last = self::NOW;
        $interval = 3;
        $sent = [];
        for ($day = 1; $day <= 120; ++$day) {
            $plan = BankSyncQueueNotifier::plan(0, 4, $last, $interval, self::NOW + $day * self::DAY);
            $interval = $plan['interval'];
            if (null !== $plan['send']) {
                $sent[] = $day;
                $last = self::NOW + $day * self::DAY;
            }
        }

        self::assertSame([3, 9, 21, 45, 75, 105], $sent);
    }

    public function testClampsAStoredInterval(): void
    {
        self::assertSame(['send' => 'reminder', 'interval' => 30], BankSyncQueueNotifier::plan(0, 1, 0, 999, self::NOW));
    }
}
