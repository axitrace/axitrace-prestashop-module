<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Purchase;

use Axitrace\PrestaShop\Purchase\OrderRowRepository;
use Axitrace\PrestaShop\Purchase\PurchaseDecision;
use Axitrace\PrestaShop\Purchase\PurchaseLedger;
use Axitrace\PrestaShop\Tests\Unit\Support\InMemoryModuleDb;
use Axitrace\PrestaShop\Tests\Unit\Support\RecordingDb;
use PHPUnit\Framework\TestCase;

final class PurchaseLedgerTest extends TestCase
{
    private InMemoryModuleDb $db;
    private PurchaseLedger $ledger;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->db = new InMemoryModuleDb();
        $this->ledger = new PurchaseLedger($this->db);
        $this->now = new \DateTimeImmutable('2026-11-03T22:41:00+01:00');
        (new OrderRowRepository($this->db))->insertNew(23, 1, 1, 'YSLTBEZII', 'e5c1c5a2-0000-5000-8000-000000000000', null, $this->now);
    }

    public function testTheFirstClaimWinsAndEveryLaterOneLoses(): void
    {
        self::assertTrue($this->ledger->claim(23, PurchaseDecision::SendPaid, $this->now));
        self::assertFalse($this->ledger->claim(23, PurchaseDecision::SendPaid, $this->now));
        self::assertFalse($this->ledger->claim(23, PurchaseDecision::SendCod, $this->now));

        $row = $this->db->orders[23];
        self::assertSame('2026-11-03 21:41:00', $row['purchase_claimed_at'], 'stored in UTC');
        self::assertSame('paid', $row['trigger']);
        self::assertTrue(PurchaseLedger::isClaimed($row));
    }

    public function testClaimIsOneAtomicConditionalUpdate(): void
    {
        $db = new RecordingDb();
        (new PurchaseLedger($db))->claim(7, PurchaseDecision::SendCod, $this->now);

        self::assertSame(
            ["UPDATE `ps_axitrace_order` SET `purchase_claimed_at` = '2026-11-03 21:41:00', `trigger` = 'cod' WHERE `id_order` = 7 AND `purchase_claimed_at` IS NULL"],
            $db->executed,
        );
    }

    public function testAnOrderWithoutARowCannotBeClaimed(): void
    {
        self::assertFalse($this->ledger->claim(999, PurchaseDecision::SendPaid, $this->now));
    }

    public function testOnlySendDecisionsClaim(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ledger->claim(23, PurchaseDecision::Void, $this->now);
    }

    public function testMarkSentOnce(): void
    {
        $this->ledger->claim(23, PurchaseDecision::SendPaid, $this->now);
        $this->ledger->markSent(23, $this->now);
        $this->ledger->markSent(23, $this->now->modify('+1 hour'));

        self::assertSame('2026-11-03 21:41:00', $this->db->orders[23]['purchase_sent_at']);
    }

    public function testVoidIsIgnoredOnceClaimedAndAVoidOrderCanStillBeClaimed(): void
    {
        $this->ledger->markVoid(23);
        self::assertTrue(PurchaseLedger::isVoid($this->db->orders[23]));

        self::assertTrue($this->ledger->claim(23, PurchaseDecision::SendPaid, $this->now));
        self::assertFalse(PurchaseLedger::isVoid($this->db->orders[23]));

        $this->ledger->markVoid(23);
        self::assertSame('paid', $this->db->orders[23]['trigger']);
    }

    public function testReleaseGivesTheClaimBackOnlyBeforeASend(): void
    {
        $this->ledger->claim(23, PurchaseDecision::SendPaid, $this->now);
        $this->ledger->releaseClaim(23);
        self::assertFalse(PurchaseLedger::isClaimed($this->db->orders[23]));

        $this->ledger->claim(23, PurchaseDecision::SendPaid, $this->now);
        $this->ledger->markSent(23, $this->now);
        $this->ledger->releaseClaim(23);
        self::assertTrue(PurchaseLedger::isClaimed($this->db->orders[23]));
    }
}
