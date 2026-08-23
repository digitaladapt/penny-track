<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\ApiKey;
use App\Entity\Receipt;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ReceiptControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $apiKey;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Clear and recreate schema
        $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        // Create API key
        $this->apiKey = bin2hex(random_bytes(32));
        $apiKeyEntity = new ApiKey();
        $apiKeyEntity->setKeyHash(password_hash($this->apiKey, PASSWORD_BCRYPT));
        $this->em->persist($apiKeyEntity);
        $this->em->flush();
    }

    public function testCreateReceipt(): void
    {
        $this->client->request('POST', '/api/receipts', [], [], [
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'amount' => 45.50,
            'business' => 'Chipotle',
            'category' => 'Food',
            'location' => 'Downtown',
            'tags' => ['lunch'],
            'notes' => 'Team lunch',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Chipotle', $data['business']);
        $this->assertSame(45.50, $data['amount']);
        $this->assertSame('Food', $data['category']);
    }

    public function testCreateReceiptWithCustomCreatedAt(): void
    {
        $this->client->request('POST', '/api/receipts', [], [], [
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'amount' => 20.00,
            'business' => 'Old Store',
            'category' => 'Shopping',
            'created_at' => '2023-06-10T15:30:00+00:00',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Old Store', $data['business']);
        $this->assertStringStartsWith('2023-06-10', $data['created_at']);
    }

    public function testCreateReceiptWithoutCreatedAtDefaultsToNow(): void
    {
        $before = new \DateTimeImmutable('-5 seconds');

        $this->client->request('POST', '/api/receipts', [], [], [
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'amount' => 12.00,
            'business' => 'Default Time Store',
            'category' => 'Food',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $createdAt = new \DateTimeImmutable($data['created_at']);
        $this->assertGreaterThanOrEqual($before, $createdAt);
    }

    public function testUpdateReceiptWithCustomCreatedAt(): void
    {
        $receipt = new Receipt();
        $receipt->setAmount('10.00');
        $receipt->setBusiness('Original');
        $receipt->setCategory('Other');
        $receipt->setCreatedAt(new \DateTimeImmutable('2022-01-01'));
        $this->em->persist($receipt);
        $this->em->flush();

        $this->client->request('PUT', '/api/receipts/' . $receipt->getId(), [], [], [
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'created_at' => '2023-07-20T08:00:00+00:00',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertStringStartsWith('2023-07-20', $data['created_at']);
    }

    public function testCreateReceiptValidationFails(): void
    {
        $this->client->request('POST', '/api/receipts', [], [], [
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'amount' => -5,
            'business' => '',
            'category' => '',
        ]));

        $this->assertResponseStatusCodeSame(422);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
    }

    public function testListReceipts(): void
    {
        $receipt = new Receipt();
        $receipt->setAmount('25.00');
        $receipt->setBusiness('Starbucks');
        $receipt->setCategory('Food');
        $this->em->persist($receipt);
        $this->em->flush();

        $this->client->request('GET', '/api/receipts', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $data['data']);
        $this->assertSame('Starbucks', $data['data'][0]['business']);
    }

    public function testDeleteReceipt(): void
    {
        $receipt = new Receipt();
        $receipt->setAmount('10.00');
        $receipt->setBusiness('Test');
        $receipt->setCategory('Other');
        $this->em->persist($receipt);
        $this->em->flush();

        $this->client->request('DELETE', '/api/receipts/' . $receipt->getId(), [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseStatusCodeSame(204);
    }

    public function testUnauthorizedWithoutApiKey(): void
    {
        $this->client->request('GET', '/api/receipts');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testUnauthorizedWithInvalidApiKey(): void
    {
        $this->client->request('GET', '/api/receipts', [], [], ['HTTP_X_API_KEY' => 'invalid-key']);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testCreateDuplicateReceiptWithin5MinutesIsRejected(): void
    {
        // First receipt – should succeed
        $this->client->request('POST', '/api/receipts', [], [], [
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'amount' => 42.50,
            'business' => 'CoffeeShop',
            'category' => 'Food',
        ]));
        $this->assertResponseStatusCodeSame(201);

        // Second identical receipt within 5 minutes – should be rejected as duplicate
        $this->client->request('POST', '/api/receipts', [], [], [
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'amount' => 42.50,
            'business' => 'CoffeeShop',
            'category' => 'Food',
        ]));
        $this->assertResponseStatusCodeSame(409);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('duplicate', $data['error']);
    }

    public function testCreateReceiptAfter5MinutesIsAllowed(): void
    {
        // Seed a receipt 6 minutes ago
        $receipt = new Receipt();
        $receipt->setAmount('55.00');
        $receipt->setBusiness('OldCafe');
        $receipt->setCategory('Food');
        $receipt->setCreatedAt(new \DateTimeImmutable('-6 minutes'));
        $this->em->persist($receipt);
        $this->em->flush();

        // Same details now – should be allowed (outside the 5-minute window)
        $this->client->request('POST', '/api/receipts', [], [], [
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'amount' => 55.00,
            'business' => 'OldCafe',
            'category' => 'Food',
        ]));
        $this->assertResponseStatusCodeSame(201);
    }

    public function testCreateReceiptWithDifferentBusinessIsAllowed(): void
    {
        // First receipt
        $this->client->request('POST', '/api/receipts', [], [], [
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'amount' => 30.00,
            'business' => 'StoreA',
            'category' => 'Shopping',
        ]));
        $this->assertResponseStatusCodeSame(201);

        // Same amount and category but different business – should be allowed
        $this->client->request('POST', '/api/receipts', [], [], [
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'amount' => 30.00,
            'business' => 'StoreB',
            'category' => 'Shopping',
        ]));
        $this->assertResponseStatusCodeSame(201);
    }

    public function testAutocomplete(): void
    {
        $receipt = new Receipt();
        $receipt->setAmount('10.00');
        $receipt->setBusiness('UniqueBiz');
        $receipt->setCategory('UniqueCat');
        $receipt->setLocation('UniqueLoc');
        $this->em->persist($receipt);
        $this->em->flush();

        $this->client->request('GET', '/api/autocomplete/businesses', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertContains('UniqueBiz', $data);

        $this->client->request('GET', '/api/autocomplete/categories', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertContains('UniqueCat', $data);

        $this->client->request('GET', '/api/autocomplete/locations', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertContains('UniqueLoc', $data);
    }
}
