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
