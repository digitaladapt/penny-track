<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\ApiKey;
use App\Entity\Receipt;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Targets the dashboard endpoints/branches not exercised by the existing
 * suites: monthly-breakdown, spending-over-time, and the counter-factual
 * branches of summary and insights.
 */
class DashboardEndpointsTest extends WebTestCase
{
    private KernelBrowser $client; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private string $apiKey; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->apiKey = bin2hex(random_bytes(32));
        $apiKeyEntity = new ApiKey();
        $apiKeyEntity->setKeyHash(password_hash($this->apiKey, \PASSWORD_BCRYPT));
        $this->em->persist($apiKeyEntity);
        $this->em->flush();
    }

    /* ----------------------------- summary ----------------------------- */

    public function test_summary_computes_month_over_month_change_when_last_month_has_spending(): void
    {
        $now = new DateTimeImmutable();
        $this->persistReceipt('100.00', 'LastMonth', 'Food', $now->modify('first day of last month midnight')->modify('+1 day'));
        $this->persistReceipt('150.00', 'ThisMonth', 'Food', $now);

        $this->getJson('/api/dashboard/summary');

        $this->assertResponseIsSuccessful();
        $data = $this->json();
        $this->assertEqualsWithDelta(150.00, $data['this_month_total'], 0.001);
        $this->assertEqualsWithDelta(100.00, $data['last_month_total'], 0.001);
        $this->assertEqualsWithDelta(50.0, $data['month_over_month_change_percent'], 0.01);
        $this->assertSame(1, $data['this_month_count']);
        $this->assertSame(1, $data['last_month_count']);
    }

    /* ------------------------ monthly-breakdown ------------------------ */

    public function test_monthly_breakdown_builds_datasets_for_every_month(): void
    {
        $now = new DateTimeImmutable();
        $this->persistReceipt('30.00', 'A', 'Food', $now);
        $this->persistReceipt('70.00', 'B', 'Food', $now);
        $this->persistReceipt('40.00', 'C', 'Transport', $now);

        $this->getJson('/api/dashboard/monthly-breakdown?months=2');

        $this->assertResponseIsSuccessful();
        $data = $this->json();

        $this->assertCount(2, $data['months']);
        $this->assertSame($now->format('Y-m'), $data['months'][1]);
        $this->assertNull($data['budget_goal']);

        $datasets = [];
        foreach ($data['datasets'] as $dataset) {
            $datasets[$dataset['category']] = $dataset['data'];
        }

        $this->assertArrayHasKey('Food', $datasets);
        $this->assertArrayHasKey('Transport', $datasets);
        // Food total (100) is higher than Transport (40), so it must sort first.
        $this->assertSame('Food', $data['datasets'][0]['category']);
        $this->assertEqualsWithDelta(100.0, $datasets['Food'][1], 0.001);
        $this->assertEqualsWithDelta(0.0, $datasets['Food'][0], 0.001);
        $this->assertEqualsWithDelta(40.0, $datasets['Transport'][1], 0.001);
    }

    public function test_monthly_breakdown_clamps_months_parameter(): void
    {
        $this->getJson('/api/dashboard/monthly-breakdown?months=99');

        $this->assertResponseIsSuccessful();
        $this->assertCount(12, $this->json()['months']);
    }

    /* ----------------------- spending-over-time ------------------------ */

    public function test_spending_over_time_fills_missing_days_with_zeros(): void
    {
        $now = new DateTimeImmutable();
        $this->persistReceipt('10.00', 'A', 'Food', $now->modify('-2 days midnight')->modify('+10 hours'));
        $this->persistReceipt('5.00', 'B', 'Food', $now->modify('-2 days midnight')->modify('+12 hours'));

        $this->getJson('/api/dashboard/spending-over-time?days=7');

        $this->assertResponseIsSuccessful();
        $data = $this->json();

        $this->assertCount(8, $data);
        $this->assertSame($now->modify('-7 days')->format('Y-m-d'), $data[0]['date']);
        $this->assertSame($now->format('Y-m-d'), $data[array_key_last($data)]['date']);

        $byDate = [];
        foreach ($data as $row) {
            $byDate[$row['date']] = $row['total'];
        }
        $this->assertEqualsWithDelta(15.0, $byDate[$now->modify('-2 days')->format('Y-m-d')], 0.001);
        $this->assertEqualsWithDelta(0.0, $byDate[$now->modify('-3 days')->format('Y-m-d')], 0.001);
    }

    public function test_spending_over_time_clamps_days_parameter(): void
    {
        $this->getJson('/api/dashboard/spending-over-time?days=1');

        $this->assertResponseIsSuccessful();
        // days=1 clamps up to the minimum of 7, producing 8 inclusive buckets.
        $this->assertCount(8, $this->json());
    }

    /* ---------------------------- insights ----------------------------- */

    public function test_insights_flags_spending_spike_over_twenty_percent(): void
    {
        $now = new DateTimeImmutable();
        $this->persistReceipt('100.00', 'LastMonth', 'Food', $now->modify('first day of last month midnight')->modify('+1 day'));
        $this->persistReceipt('100.00', 'LastMonth2', 'Food', $now->modify('first day of last month midnight')->modify('+2 days'));
        $this->persistReceipt('300.00', 'ThisMonth', 'Food', $now);

        $this->getJson('/api/dashboard/insights');

        $this->assertResponseIsSuccessful();
        $messages = array_column($this->json(), 'message');
        $this->assertNotEmpty(array_filter($messages, static fn (string $m) => str_contains($m, 'more than last month')));
        $this->assertNotEmpty(array_filter($messages, static fn (string $m) => str_contains($m, 'Top spender this month')));
        $this->assertNotEmpty(array_filter($messages, static fn (string $m) => str_contains($m, 'On track to spend between')));
    }

    public function test_insights_reports_new_category_and_high_spending(): void
    {
        $now = new DateTimeImmutable();

        // History: Food in the last three months at ~50/month.
        foreach ([1, 2, 3] as $monthsAgo) {
            $month = $now->modify("first day of -{$monthsAgo} months midnight")->modify('+2 days');
            $this->persistReceipt('50.00', 'Historic'.$monthsAgo, 'Food', $month);
        }

        // This month: a brand-new category with a large amount.
        $this->persistReceipt('400.00', 'Gadget Store', 'Electronics', $now);

        $this->getJson('/api/dashboard/insights');

        $this->assertResponseIsSuccessful();
        $messages = array_column($this->json(), 'message');
        $this->assertNotEmpty(array_filter($messages, static fn (string $m) => str_contains($m, 'New category this month')));
    }

    public function test_insights_without_history_still_returns_projections(): void
    {
        $now = new DateTimeImmutable();
        $this->persistReceipt('42.00', 'OnlyReceipt', 'Food', $now);

        $this->getJson('/api/dashboard/insights');

        $this->assertResponseIsSuccessful();
        $messages = array_column($this->json(), 'message');
        $this->assertNotEmpty(array_filter($messages, static fn (string $m) => str_contains($m, 'On track to spend between')));
    }

    /* ---------------------------- helpers ------------------------------ */

    private function getJson(string $uri): void
    {
        $this->client->request('GET', $uri, [], [], ['HTTP_X_API_KEY' => $this->apiKey]);
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);

        return $data;
    }

    private function persistReceipt(string $amount, string $business, string $category, DateTimeImmutable $createdAt): Receipt
    {
        $receipt = new Receipt();
        $receipt->setAmount($amount);
        $receipt->setBusiness($business);
        $receipt->setCategory($category);
        $receipt->setCreatedAt($createdAt);

        $this->em->persist($receipt);
        $this->em->flush();

        return $receipt;
    }
}
