<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\ApiKey;
use App\Entity\Receipt;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DashboardControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $apiKey;
    private EntityManagerInterface $em;

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
        $apiKeyEntity->setKeyHash(password_hash($this->apiKey, PASSWORD_BCRYPT));
        $this->em->persist($apiKeyEntity);
        $this->em->flush();
    }

    public function testSummary(): void
    {
        $receipt = new Receipt();
        $receipt->setAmount('100.00');
        $receipt->setBusiness('Test');
        $receipt->setCategory('Food');
        $this->em->persist($receipt);
        $this->em->flush();

        $this->client->request('GET', '/api/dashboard/summary', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('this_month_total', $data);
        $this->assertArrayHasKey('average_transaction', $data);
    }

    public function testSpendingByCategory(): void
    {
        $r1 = new Receipt();
        $r1->setAmount('50.00');
        $r1->setBusiness('A');
        $r1->setCategory('Food');
        $this->em->persist($r1);

        $r2 = new Receipt();
        $r2->setAmount('30.00');
        $r2->setBusiness('B');
        $r2->setCategory('Transport');
        $this->em->persist($r2);
        $this->em->flush();

        $this->client->request('GET', '/api/dashboard/spending-by-category', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertCount(2, $data);
    }

    public function testInsights(): void
    {
        $this->client->request('GET', '/api/dashboard/insights', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testDashboardPageRenders(): void
    {
        $this->client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#summary-cards');
    }
}
