<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Entity\Receipt;
use App\Repository\ReceiptRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Override;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ReceiptRepositoryAdditionalTest extends KernelTestCase
{
    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $em = $this->em();
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function test_find_recent_orders_by_created_at_desc_and_applies_limit(): void
    {
        $now = new DateTimeImmutable();
        $this->persistReceipt('1.00', 'Oldest', $now->modify('-2 hours'));
        $this->persistReceipt('2.00', 'Middle', $now->modify('-1 hours'));
        $this->persistReceipt('3.00', 'Newest', $now);

        $result = $this->repository()->findRecent(2);

        $this->assertCount(2, $result);
        $this->assertSame('Newest', $result[0]->getBusiness());
        $this->assertSame('Middle', $result[1]->getBusiness());
    }

    public function test_find_recent_defaults_to_ten(): void
    {
        $this->assertSame([], $this->repository()->findRecent());
    }

    public function test_get_spending_over_time_groups_by_day(): void
    {
        $now = new DateTimeImmutable();
        $this->persistReceipt('10.00', 'A', $now->modify('-1 day'));
        $this->persistReceipt('15.00', 'B', $now->modify('-1 day'));
        $this->persistReceipt('5.00', 'C', $now);

        $rows = $this->repository()->getSpendingOverTime($now->modify('-3 days'), $now);

        $this->assertCount(2, $rows);
        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row['date']] = (float) $row['total'];
        }

        $yesterday = $now->modify('-1 day')->format('Y-m-d');
        $today = $now->format('Y-m-d');

        $this->assertEqualsWithDelta(25.00, $byDate[$yesterday], 0.001);
        $this->assertEqualsWithDelta(5.00, $byDate[$today], 0.001);
    }

    public function test_get_monthly_category_breakdown_groups_per_month_and_category(): void
    {
        $now = new DateTimeImmutable();
        $lastMonth = $now->modify('first day of last month midnight')->modify('+2 days');

        $this->persistReceipt('40.00', 'A', $lastMonth, 'Food');
        $this->persistReceipt('60.00', 'B', $now, 'Food');
        $this->persistReceipt('25.00', 'C', $now, 'Transport');

        $result = $this->repository()->getMonthlyCategoryBreakdown(2);

        $thisMonthKey = $now->format('Y-m');
        $lastMonthKey = $now->modify('first day of last month midnight')->format('Y-m');

        $this->assertArrayHasKey($thisMonthKey, $result);
        $this->assertArrayHasKey($lastMonthKey, $result);

        $thisMonth = [];
        foreach ($result[$thisMonthKey] as $row) {
            $thisMonth[$row['category']] = $row['total'];
        }
        $this->assertEqualsWithDelta(60.00, $thisMonth['Food'], 0.001);
        $this->assertEqualsWithDelta(25.00, $thisMonth['Transport'], 0.001);

        $lastMonthRows = [];
        foreach ($result[$lastMonthKey] as $row) {
            $lastMonthRows[$row['category']] = $row['total'];
        }
        $this->assertEqualsWithDelta(40.00, $lastMonthRows['Food'], 0.001);
    }

    private function persistReceipt(string $amount, string $business, DateTimeImmutable $createdAt, string $category = 'Food'): Receipt
    {
        $receipt = new Receipt();
        $receipt->setAmount($amount);
        $receipt->setBusiness($business);
        $receipt->setCategory($category);
        $receipt->setCreatedAt($createdAt);

        $em = $this->em();
        $em->persist($receipt);
        $em->flush();

        return $receipt;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function repository(): ReceiptRepository
    {
        return static::getContainer()->get(ReceiptRepository::class);
    }
}
