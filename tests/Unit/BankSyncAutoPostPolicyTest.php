<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

use PHPUnit\Framework\TestCase;

final class BankSyncAutoPostPolicyTest extends TestCase
{
    public function testPostsAFee(): void
    {
        $decision = (new BankSyncAutoPostPolicy())->decide($this->tx(['bank_event_type' => 'bank_fee']), [], []);

        self::assertSame(BankSyncAutoPostPolicy::ACTION_POST, $decision['action']);
        self::assertSame(BankSyncAutoPostPolicy::REASON_FEE, $decision['reason']);
    }

    public function testQueuesIncomingMoney(): void
    {
        $decision = (new BankSyncAutoPostPolicy())->decide($this->tx(['direction' => 'credit', 'amount' => '50.00']), [], []);

        self::assertSame(BankSyncAutoPostPolicy::ACTION_QUEUE, $decision['action']);
        self::assertSame(BankSyncAutoPostPolicy::REASON_INCOMING, $decision['reason']);
    }

    /**
     * @dataProvider queuedEventTypes
     */
    public function testQueuesOtherEventTypes(string $eventType): void
    {
        $decision = (new BankSyncAutoPostPolicy())->decide($this->tx(['bank_event_type' => $eventType]), [], []);

        self::assertSame(BankSyncAutoPostPolicy::REASON_EVENT_TYPE, $decision['reason']);
        self::assertSame($eventType, $decision['detail']);
    }

    public function queuedEventTypes(): iterable
    {
        yield 'withdrawal' => ['internal_transfer'];
        yield 'refund' => ['refund'];
        yield 'unknown code' => ['other'];
    }

    public function testPostsASingleConfirmedSupplierInvoice(): void
    {
        $decision = (new BankSyncAutoPostPolicy())->decide($this->tx(), [['target_type' => 'supplier_invoice', 'allocated_amount' => '49.90000000']], []);

        self::assertSame(BankSyncAutoPostPolicy::ACTION_POST, $decision['action']);
        self::assertSame(BankSyncAutoPostPolicy::REASON_CONFIRMED_MATCH, $decision['reason']);
    }

    public function testQueuesAPartialConfirmedMatch(): void
    {
        $decision = (new BankSyncAutoPostPolicy())->decide($this->tx(), [['target_type' => 'supplier_invoice', 'allocated_amount' => '40.00']], []);

        self::assertSame(BankSyncAutoPostPolicy::REASON_AMBIGUOUS_MATCH, $decision['reason']);
    }

    public function testQueuesAConfirmedMatchOfAnotherType(): void
    {
        $decision = (new BankSyncAutoPostPolicy())->decide($this->tx(), [['target_type' => 'social_contribution', 'allocated_amount' => '49.90']], []);

        self::assertSame(BankSyncAutoPostPolicy::REASON_AMBIGUOUS_MATCH, $decision['reason']);
    }

    public function testQueuesSeveralConfirmedMatches(): void
    {
        $match = ['target_type' => 'supplier_invoice', 'allocated_amount' => '24.95'];
        $decision = (new BankSyncAutoPostPolicy())->decide($this->tx(), [$match, $match], []);

        self::assertSame(BankSyncAutoPostPolicy::REASON_AMBIGUOUS_MATCH, $decision['reason']);
    }

    public function testQueuesWithoutAnyMatch(): void
    {
        $decision = (new BankSyncAutoPostPolicy())->decide($this->tx(), [], []);

        self::assertSame(BankSyncAutoPostPolicy::REASON_NO_MATCH, $decision['reason']);
    }

    public function testPostsAReimbursementAcrossInvoices(): void
    {
        $decision = (new BankSyncAutoPostPolicy())->decide(
            $this->tx(['amount' => '-130.40', 'counterparty_email' => 'Member@Example.org']),
            [],
            ['SI2610-0003' => $this->invoice(11, 'SI2610-0003', '100.00'), 'SI2610-0007' => $this->invoice(12, 'SI2610-0007', '30.40000000')]
        );

        self::assertSame(BankSyncAutoPostPolicy::ACTION_POST_ALLOCATION, $decision['action']);
        self::assertSame(BankSyncAutoPostPolicy::REASON_REIMBURSEMENT, $decision['reason']);
        self::assertSame([11 => '100.00', 12 => '30.40'], $decision['allocations']);
    }

    public function testRecognisesTheMemberByName(): void
    {
        $decision = (new BankSyncAutoPostPolicy())->decide(
            $this->tx(['amount' => '-100.00', 'counterparty_name' => ' max muster ']),
            [],
            ['SI2610-0003' => $this->invoice(11, 'SI2610-0003', '100.00')]
        );

        self::assertSame(BankSyncAutoPostPolicy::ACTION_POST_ALLOCATION, $decision['action']);
    }

    public function testQueuesAReimbursementToSomeoneElse(): void
    {
        $decision = (new BankSyncAutoPostPolicy())->decide(
            $this->tx(['amount' => '-100.00', 'counterparty_name' => 'Somebody Else', 'counterparty_email' => 'else@example.org']),
            [],
            ['SI2610-0003' => $this->invoice(11, 'SI2610-0003', '100.00')]
        );

        self::assertSame(BankSyncAutoPostPolicy::REASON_COUNTERPARTY_MISMATCH, $decision['reason']);
    }

    public function testQueuesASumMismatch(): void
    {
        $decision = (new BankSyncAutoPostPolicy())->decide(
            $this->tx(['amount' => '-100.01', 'counterparty_email' => 'member@example.org']),
            [],
            ['SI2610-0003' => $this->invoice(11, 'SI2610-0003', '100.00')]
        );

        self::assertSame(BankSyncAutoPostPolicy::REASON_SUM_MISMATCH, $decision['reason']);
        self::assertSame('100.00 != 100.01', $decision['detail']);
    }

    public function testQueuesAnUnknownReference(): void
    {
        $decision = (new BankSyncAutoPostPolicy())->decide($this->tx(), [], ['SI2610-0099' => null]);

        self::assertSame(BankSyncAutoPostPolicy::REASON_INVOICE_UNKNOWN, $decision['reason']);
        self::assertSame('SI2610-0099', $decision['detail']);
    }

    public function testQueuesAPaidInvoice(): void
    {
        $paid = $this->invoice(11, 'SI2610-0003', '0.00');
        $decision = (new BankSyncAutoPostPolicy())->decide($this->tx(), [], ['SI2610-0003' => $paid]);

        self::assertSame(BankSyncAutoPostPolicy::REASON_INVOICE_NOT_OPEN, $decision['reason']);
    }

    public function testQueuesADraftInvoice(): void
    {
        $draft = ['open' => false] + $this->invoice(11, 'SI2610-0003', '49.90');
        $decision = (new BankSyncAutoPostPolicy())->decide($this->tx(), [], ['SI2610-0003' => $draft]);

        self::assertSame(BankSyncAutoPostPolicy::REASON_INVOICE_NOT_OPEN, $decision['reason']);
    }

    public function testQueuesInvoicesOfDifferentThirdparties(): void
    {
        $other = ['thirdparty_id' => 8] + $this->invoice(12, 'SI2610-0007', '30.40');
        $decision = (new BankSyncAutoPostPolicy())->decide(
            $this->tx(['amount' => '-130.40', 'counterparty_email' => 'member@example.org']),
            [],
            ['SI2610-0003' => $this->invoice(11, 'SI2610-0003', '100.00'), 'SI2610-0007' => $other]
        );

        self::assertSame(BankSyncAutoPostPolicy::REASON_MIXED_THIRDPARTIES, $decision['reason']);
    }

    public function testExtractsDistinctReferences(): void
    {
        $refs = (new BankSyncAutoPostPolicy())->extractInvoiceRefs('Auslagen si2610-0003, SI2610-0007 und SI2610-0003; RE-1001');

        self::assertSame(['SI2610-0003', 'SI2610-0007'], $refs);
    }

    public function testAcceptsACustomPattern(): void
    {
        self::assertSame(['LF-12'], (new BankSyncAutoPostPolicy('/\bLF-\d+\b/'))->extractInvoiceRefs('lf-12'));
    }

    public function testRejectsAnInvalidPattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BankSyncAutoPostPolicy('/(/');
    }

    public function testCentsRounds(): void
    {
        self::assertSame(4990, BankSyncAutoPostPolicy::cents('49.90000000'));
        self::assertSame(-11, BankSyncAutoPostPolicy::cents('-0.105'));
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function tx(array $override = []): array
    {
        return $override + ['direction' => 'debit', 'bank_event_type' => 'transfer', 'amount' => '-49.90', 'counterparty_name' => '', 'counterparty_email' => ''];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoice(int $id, string $ref, string $remaining): array
    {
        return ['id' => $id, 'ref' => $ref, 'open' => true, 'remaining' => $remaining, 'thirdparty_id' => 7, 'thirdparty_name' => 'Max Muster', 'thirdparty_email' => 'member@example.org'];
    }
}
