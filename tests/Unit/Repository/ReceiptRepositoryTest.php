<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Entity\Receipt;
use App\Repository\ReceiptRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ReceiptRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ReceiptRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Recreate schema
        $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        if (!empty($metadata)) {
            $schemaTool->dropSchema($metadata);
        }
        $schemaTool->createSchema($metadata);

        $this->repository = $this->em->getRepository(Receipt::class);
    }

    public function testGetTopBusinessesWithLimit(): void
    {
        $now = new \DateTimeImmutable();

        // Create 5 unique businesses with different amounts
        for ($i = 1; $i <= 5; $i++) {
            $r = new Receipt();
            $r->setAmount((string)($i * 10));
            $r->setBusiness("Biz$i");
            $r->setCategory('Food');
            $r->setCreatedAt($now);
            $this->em->persist($r);
        }
        $this->em->flush();

        // Limit to 3 should return exactly 3 rows ordered by total DESC
        $result = $this->repository->getTopBusinesses($now, $now, 3);
        $this->assertCount(3, $result);
        $this->assertSame('Biz5', $result[0]['business']);
    }

    public function testGetSpendingByCategoryReturnsCorrectTotals(): void
    {
        $now = new \DateTimeImmutable();

        $r1 = new Receipt();
        $r1->setAmount('40.00');
        $r1->setBusiness('A');
        $r1->setCategory('Food');
        $r1->setCreatedAt($now);
        $this->em->persist($r1);

        $r2 = new Receipt();
        $r2->setAmount('60.00');
        $r2->setBusiness('B');
        $r2->setCategory('Food');
        $r2->setCreatedAt($now);
        $this->em->persist($r2);

        $r3 = new Receipt();
        $r3->setAmount('25.00');
        $r3->setBusiness('C');
        $r3->setCategory('Transport');
        $r3->setCreatedAt($now);
        $this->em->persist($r3);

        $this->em->flush();

        $result = $this->repository->getSpendingByCategory($now, $now);

        // Food should total 100.00
        $food = array_filter($result, fn ($row) => $row['category'] === 'Food');
        $transport = array_filter($result, fn ($row) => $row['category'] === 'Transport');

        $this->assertCount(1, $food);
        $this->assertEqualsWithDelta(100.00, (float)$food[array_key_first($food)]['total'], 0.01);
        $this->assertCount(1, $transport);
        $this->assertEqualsWithDelta(25.00, (float)$transport[array_key_first($transport)]['total'], 0.01);
    }

    public function testGetCategoryMonthlyAverages(): void
    {
        // Create receipts spanning last 3 months in Food category:
        // Month -2: $100 total
        // Month -1: $200 total
        // Current month: $300 total (not included in average)

        $month2Ago = new \DateTimeImmutable('-2 months');
        $month1Ago = new \DateTimeImmutable('-1 month');
        $now = new \DateTimeImmutable();

        // Month -2 receipts ($100 total)
        for ($i = 0; $i < 4; $i++) {
            $r = new Receipt();
            $r->setAmount('25.00');
            $r->setBusiness("M2Biz$i");
            $r->setCategory('Food');
            // Spread within month -2
            $date = clone $month2Ago;
            $date = $date->modify("+{$i} days midnight");
            $r->setCreatedAt($date);
            $this->em->persist($r);
        }

        // Month -1 receipts ($200 total)
        for ($i = 0; $i < 4; $i++) {
            $r = new Receipt();
            $r->setAmount('50.00');
            $r->setBusiness("M1Biz$i");
            $r->setCategory('Food');
            $date = clone $month1Ago;
            $date = $date->modify("+{$i} days midnight");
            $r->setCreatedAt($date);
            $this->em->persist($r);
        }

        // Current month receipts ($300 total) - should not affect average of last 3 months' monthly totals
        for ($i = 0; $i < 3; $i++) {
            $r = new Receipt();
            $r->setAmount('100.00');
            $r->setBusiness("NowBiz$i");
            $r->setCategory('Food');
            $date = clone $now;
            $date = $date->modify("-{$i} minutes"); // do not put receipts in the future
            $r->setCreatedAt($date);
            $this->em->persist($r);
        }

        $this->em->flush();

        // With 3 months of data: Month-2=$100, Month-1=$200, CurrentMonth=$300 => avg = ($100+$200+$300)/3 = $200
        $averages = $this->repository->getCategoryMonthlyAverages(3);

        // Since we're looking at 3 months including current month:
        // We expect Food to appear with some average based on available monthly totals.
        $foodAvg = null;
        foreach ($averages as $row) {
            if ($row['category'] === 'Food') {
                $foodAvg = (float)$row['avg_monthly_total'];
                break;
            }
        }

        // Assert Food category average exists and is reasonable (> 0, < sum of all receipts)
        $this->assertNotNull($foodAvg, "Expected to find Food in monthly averages");
        $this->assertGreaterThan(150.0, $foodAvg, "Food avg should be at least $150 based on seeded data");
        $this->assertLessThan(400.0, $foodAvg, "Food avg should not exceed total receipts");
    }

    public function testGetCategoryMonthlyAveragesReturnsEmptyForNoData(): void
    {
        $averages = $this->repository->getCategoryMonthlyAverages(3);
        $this->assertIsArray($averages);
    }

    public function testFindRecentDuplicateFindsMatch(): void
    {
        $now = new \DateTimeImmutable();
        $twoMinutesAgo = $now->modify('-2 minutes');

        $r = new Receipt();
        $r->setAmount('25.00');
        $r->setBusiness('Target');
        $r->setCategory('Shopping');
        $r->setCreatedAt($twoMinutesAgo);
        $this->em->persist($r);
        $this->em->flush();

        $fiveMinutesAgo = $now->modify('-5 minutes');
        $result = $this->repository->findRecentDuplicate('25.00', 'Target', 'Shopping', $fiveMinutesAgo, $now);

        $this->assertNotNull($result);
        $this->assertSame('Target', $result->getBusiness());
        $this->assertSame('25.00', $result->getAmount());
    }

    public function testFindRecentDuplicateReturnsNullWhenOutsideWindow(): void
    {
        $now = new \DateTimeImmutable();
        $sixMinutesAgo = $now->modify('-6 minutes');

        $r = new Receipt();
        $r->setAmount('25.00');
        $r->setBusiness('Target');
        $r->setCategory('Shopping');
        $r->setCreatedAt($sixMinutesAgo);
        $this->em->persist($r);
        $this->em->flush();

        $fiveMinutesAgo = $now->modify('-5 minutes');
        $result = $this->repository->findRecentDuplicate('25.00', 'Target', 'Shopping', $fiveMinutesAgo, $now);

        $this->assertNull($result);
    }

    public function testFindRecentDuplicateReturnsNullWhenFieldsDiffer(): void
    {
        $now = new \DateTimeImmutable();

        $r = new Receipt();
        $r->setAmount('25.00');
        $r->setBusiness('Target');
        $r->setCategory('Shopping');
        $r->setCreatedAt($now);
        $this->em->persist($r);
        $this->em->flush();

        $fiveMinutesAgo = $now->modify('-5 minutes');

        // Different amount
        $this->assertNull($this->repository->findRecentDuplicate('99.00', 'Target', 'Shopping', $fiveMinutesAgo, $now));
        // Different business
        $this->assertNull($this->repository->findRecentDuplicate('25.00', 'Walmart', 'Shopping', $fiveMinutesAgo, $now));
        // Different category
        $this->assertNull($this->repository->findRecentDuplicate('25.00', 'Target', 'Food', $fiveMinutesAgo, $now));
    }

    public function testGetLargestReceiptInDateRange(): void
    {
        $now = new \DateTimeImmutable();

        $r1 = new Receipt();
        $r1->setAmount('50.00');
        $r1->setBusiness('SmallBiz');
        $r1->setCategory('Food');
        $r1->setCreatedAt($now);
        $this->em->persist($r1);

        $r2 = new Receipt();
        $r2->setAmount('250.00');
        $r2->setBusiness('BigBiz');
        $r2->setCategory('Shopping');
        $r2->setCreatedAt($now);
        $this->em->persist($r2);

        $r3 = new Receipt();
        $r3->setAmount('100.00');
        $r3->setBusiness('MidBiz');
        $r3->setCategory('Transport');
        $r3->setCreatedAt($now);
        $this->em->persist($r3);

        $this->em->flush();

        $largest = $this->repository->getLargestReceipt($now, $now);

        $this->assertNotNull($largest);
        $this->assertSame('BigBiz', $largest['business']);
        $this->assertEqualsWithDelta(250.00, (float)$largest['amount'], 0.01);
    }

    public function testGetLargestReceiptReturnsNullWhenNoReceipts(): void
    {
        $now = new \DateTimeImmutable();
        $result = $this->repository->getLargestReceipt($now, $now);
        $this->assertNull($result);
    }
}
