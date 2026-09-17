<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\ApiKey;
use App\Entity\Receipt;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for the v1.2 Dashboard API changes:
 * - spending-by-category stays backward compatible (flat array)
 * - top-businesses with configurable limit
 * - insights tweaks (e.g. "spent X% less" insight removal)
 */
final class DashboardControllerV12Test extends WebTestCase
{
    private KernelBrowser $client;
    private string $apiKey;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Recreate schema for each test
        $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        if (!empty($metadata)) {
            $schemaTool->dropSchema($metadata);
        }
        $schemaTool->createSchema($metadata);

        // Create API key
        $this->apiKey = bin2hex(random_bytes(32));
        $apiKeyEntity = new ApiKey();
        $apiKeyEntity->setKeyHash(password_hash($this->apiKey, PASSWORD_BCRYPT));
        $this->em->persist($apiKeyEntity);
        $this->em->flush();
    }

    /* ------------------------------------------------------------------ */
    /* Spending by Category — Backward Compatibility                      */
    /* ------------------------------------------------------------------ */

    public function testSpendingByCategoryBackwardCompatibleWithoutComparisonParam(): void
    {
        // Seed one receipt this month
        $now = new \DateTimeImmutable();
        $r = new Receipt();
        $r->setAmount('42.00');
        $r->setBusiness('TestBiz');
        $r->setCategory('Food');
        $r->setCreatedAt($now);
        $this->em->persist($r);
        $this->em->flush();

        // Call without comparison param — should return flat array (current-month-only)
        $this->client->request('GET', '/api/dashboard/spending-by-category', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // Should be a flat array of {category, total} objects
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('Food', $data[0]['category']);
        $this->assertEqualsWithDelta(42.00, (float)$data[0]['total'], 0.01);
    }

    public function testSpendingByCategoryComparisonFalseReturnsFlatArray(): void
    {
        $now = new \DateTimeImmutable();
        $r = new Receipt();
        $r->setAmount('15.00');
        $r->setBusiness('X');
        $r->setCategory('Shopping');
        $r->setCreatedAt($now);
        $this->em->persist($r);
        $this->em->flush();

        $this->client->request('GET', '/api/dashboard/spending-by-category?comparison=false', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // comparison=false should behave like default: flat array of current month only
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
    }

    /* ------------------------------------------------------------------ */
    /* Top Businesses — Configurable Limit                                */
    /* ------------------------------------------------------------------ */

    public function testTopBusinessesDefaultLimitOf5(): void
    {
        // Create 8 businesses with receipts this month
        for ($i = 1; $i <= 8; $i++) {
            $r = new Receipt();
            $r->setAmount((string)($i * 10));
            $r->setBusiness("TopBiz$i");
            $r->setCategory('Food');
            $this->em->persist($r);
        }
        $this->em->flush();

        // No limit param — should default to 5
        $this->client->request('GET', '/api/dashboard/top-businesses', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertCount(5, $data);
    }

    public function testTopBusinessesLimit10ReturnsTen(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $r = new Receipt();
            $r->setAmount((string)($i * 5));
            $r->setBusiness("Biz$i");
            $r->setCategory('Food');
            $this->em->persist($r);
        }
        $this->em->flush();

        $this->client->request('GET', '/api/dashboard/top-businesses?limit=10', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // We have 12 businesses, limit is 10 — should return exactly 10
        $this->assertCount(10, $data);
    }

    public function testTopBusinessesLimit15ReturnsFifteen(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $r = new Receipt();
            $r->setAmount((string)($i * 3));
            $r->setBusiness("Biz$i");
            $r->setCategory('Food');
            $this->em->persist($r);
        }
        $this->em->flush();

        $this->client->request('GET', '/api/dashboard/top-businesses?limit=15', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertCount(15, $data);
    }

    public function testTopBusinessesLimit25ReturnsUpToTwentyFive(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $r = new Receipt();
            $r->setAmount((string)($i * 2));
            $r->setBusiness("Biz$i");
            $r->setCategory('Food');
            $this->em->persist($r);
        }
        $this->em->flush();

        $this->client->request('GET', '/api/dashboard/top-businesses?limit=25', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // We have 30 businesses, limit is 25 — should return exactly 25
        $this->assertCount(25, $data);
    }

    public function testTopBusinessesInvalidLimitFallsBackToDefault(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $r = new Receipt();
            $r->setAmount((string)($i * 10));
            $r->setBusiness("Biz$i");
            $r->setCategory('Food');
            $this->em->persist($r);
        }
        $this->em->flush();

        // Invalid limit (99 is not in allowed set) should default to 5
        $this->client->request('GET', '/api/dashboard/top-businesses?limit=99', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // Should fall back to default of 5
        $this->assertCount(5, $data);
    }

    /* ------------------------------------------------------------------ */
    /* Insights — Improvements & New Behaviors                            */
    /* ------------------------------------------------------------------ */

    public function testInsightsNoSpentLessInsightAfterRemoval(): void
    {
        // Last month: very high spending ($1000)
        // This month: much lower ($200) — the "spent X% less" insight was intentionally removed
        $now = new \DateTimeImmutable();
        $startOfLastMonth = (clone $now)->modify('first day of last month midnight');
        $endOfLastMonth = (clone $now)->modify('first day of this month midnight')->modify('-1 second');

        for ($i = 0; $i < 5; $i++) {
            $r = new Receipt();
            $r->setAmount('200.00');
            $r->setBusiness("LastMonth$i");
            $r->setCategory('Food');
            $date = clone $startOfLastMonth;
            $date = $date->modify("+{$i} days midnight");
            $r->setCreatedAt($date);
            $this->em->persist($r);
        }

        for ($i = 0; $i < 2; $i++) {
            $r = new Receipt();
            $r->setAmount('100.00');
            $r->setBusiness("ThisMonth$i");
            $r->setCategory('Food');
            $date = clone $now;
            $date = $date->modify("+{$i} days midnight");
            $r->setCreatedAt($date);
            $this->em->persist($r);
        }

        $this->em->flush();

        $this->client->request('GET', '/api/dashboard/insights', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // Last month: 5 * 200 = 1000, This month: 2 * 100 = 200 → -80% change
        // The "spent X% less" insight was removed since it duplicates the "This Month" box.
        // Verify no "less than last month" insight is present.
        $foundLessInsight = false;
        foreach ($data as $insight) {
            if (str_contains(strtolower($insight['message'] ?? ''), 'less than last month')) {
                $foundLessInsight = true;
                break;
            }
        }

        $this->assertFalse($foundLessInsight, 'The "spent less than last month" insight should no longer be emitted');
    }

    public function testInsightsReturnsArray(): void
    {
        // Basic sanity: empty data still returns valid array
        $this->client->request('GET', '/api/dashboard/insights', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }
}
