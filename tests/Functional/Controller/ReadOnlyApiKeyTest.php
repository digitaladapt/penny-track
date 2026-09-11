<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\ApiKey;
use App\Entity\Receipt;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ReadOnlyApiKeyTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $adminKey;
    private string $readOnlyKey;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->adminKey = bin2hex(random_bytes(32));
        $admin = new ApiKey();
        $admin->setKeyHash(password_hash($this->adminKey, PASSWORD_BCRYPT));
        $this->em->persist($admin);

        $this->readOnlyKey = bin2hex(random_bytes(32));
        $readOnly = new ApiKey();
        $readOnly->setKeyHash(password_hash($this->readOnlyKey, PASSWORD_BCRYPT));
        $readOnly->setReadOnly(true);
        $this->em->persist($readOnly);

        $this->em->flush();
    }

    public function testReadOnlyKeyCanListReceipts(): void
    {
        $receipt = new Receipt();
        $receipt->setAmount('25.00');
        $receipt->setBusiness('Starbucks');
        $receipt->setCategory('Food');
        $this->em->persist($receipt);
        $this->em->flush();

        $this->client->request('GET', '/api/receipts', [], [], ['HTTP_X_API_KEY' => $this->readOnlyKey]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $data['data']);
        $this->assertSame('Starbucks', $data['data'][0]['business']);
    }

    public function testReadOnlyKeyCanGetSingleReceipt(): void
    {
        $receipt = new Receipt();
        $receipt->setAmount('10.00');
        $receipt->setBusiness('Test');
        $receipt->setCategory('Other');
        $this->em->persist($receipt);
        $this->em->flush();

        $this->client->request('GET', '/api/receipts/' . $receipt->getId(), [], [], ['HTTP_X_API_KEY' => $this->readOnlyKey]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Test', $data['business']);
    }

    public function testReadOnlyKeyCanReadDashboardSummary(): void
    {
        $this->client->request('GET', '/api/dashboard/summary', [], [], ['HTTP_X_API_KEY' => $this->readOnlyKey]);

        $this->assertResponseIsSuccessful();
    }

    public function testReadOnlyKeyCannotCreateReceipt(): void
    {
        $this->client->request('POST', '/api/receipts', [], [], [
            'HTTP_X_API_KEY' => $this->readOnlyKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'amount' => 25.00,
            'business' => 'Chipotle',
            'category' => 'Food',
        ]));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testReadOnlyKeyCannotUpdateReceipt(): void
    {
        $receipt = new Receipt();
        $receipt->setAmount('10.00');
        $receipt->setBusiness('Original');
        $receipt->setCategory('Other');
        $this->em->persist($receipt);
        $this->em->flush();

        $this->client->request('PUT', '/api/receipts/' . $receipt->getId(), [], [], [
            'HTTP_X_API_KEY' => $this->readOnlyKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['business' => 'Changed']));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testReadOnlyKeyCannotDeleteReceipt(): void
    {
        $receipt = new Receipt();
        $receipt->setAmount('10.00');
        $receipt->setBusiness('Test');
        $receipt->setCategory('Other');
        $this->em->persist($receipt);
        $this->em->flush();

        $this->client->request('DELETE', '/api/receipts/' . $receipt->getId(), [], [], ['HTTP_X_API_KEY' => $this->readOnlyKey]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testReadOnlyKeyCannotParseReceipt(): void
    {
        $this->client->request('POST', '/api/receipts/parse', [], [], [
            'HTTP_X_API_KEY' => $this->readOnlyKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['text' => 'coffee 5 dollars']));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testReadOnlyKeyCannotDeleteParseJob(): void
    {
        $this->client->request('DELETE', '/api/parse-jobs/1', [], [], ['HTTP_X_API_KEY' => $this->readOnlyKey]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testReadOnlyKeyCannotRetryParseJob(): void
    {
        $this->client->request('POST', '/api/parse-jobs/1/retry', [], [], ['HTTP_X_API_KEY' => $this->readOnlyKey]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminKeyCanStillWrite(): void
    {
        $this->client->request('POST', '/api/receipts', [], [], [
            'HTTP_X_API_KEY' => $this->adminKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'amount' => 25.00,
            'business' => 'Chipotle',
            'category' => 'Food',
        ]));

        $this->assertResponseStatusCodeSame(201);
    }

    public function testVerifyEndpointReportsReadOnlyFlag(): void
    {
        $this->client->request('POST', '/api/auth/verify', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['api_key' => $this->readOnlyKey]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['valid']);
        $this->assertTrue($data['read_only']);
    }

    public function testVerifyEndpointReportsAdminFlag(): void
    {
        $this->client->request('POST', '/api/auth/verify', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['api_key' => $this->adminKey]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['valid']);
        $this->assertFalse($data['read_only']);
    }
}
