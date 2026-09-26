<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\ApiKey;
use App\Entity\Receipt;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers the ReceiptController branches the original suite leaves out:
 * not-found paths, invalid JSON bodies, the parse-queue endpoint,
 * autocomplete endpoints and the manual-entry page.
 */
class ReceiptControllerBranchesTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $apiKey;
    private EntityManagerInterface $em;

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

    public function test_get_returns_not_found_for_missing_receipt(): void
    {
        $this->client->request('GET', '/api/receipts/424242', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseStatusCodeSame(404);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Not found', $data['error']);
    }

    public function test_update_returns_not_found_for_missing_receipt(): void
    {
        $this->client->request('PUT', '/api/receipts/424242', [], [], [
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['business' => 'X']));

        $this->assertResponseStatusCodeSame(404);
    }

    public function test_update_rejects_invalid_json(): void
    {
        $receipt = $this->persistReceipt();

        $this->client->request('PUT', '/api/receipts/'.$receipt->getId(), [], [], [
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'application/json',
        ], 'not-json');

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Invalid JSON body', $data['error']);
    }

    public function test_update_returns_validation_errors(): void
    {
        $receipt = $this->persistReceipt();

        $this->client->request('PUT', '/api/receipts/'.$receipt->getId(), [], [], [
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['amount' => 'not-a-number']));

        $this->assertResponseStatusCodeSame(422);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('amount', $data['errors']);
    }

    public function test_update_clears_nullable_fields_when_empty_values_supplied(): void
    {
        $receipt = $this->persistReceipt();
        $receipt->setLocation('Somewhere');
        $receipt->setNotes('Some notes');
        $this->em->flush();

        $this->client->request('PUT', '/api/receipts/'.$receipt->getId(), [], [], [
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'location' => '',
            'notes' => '',
            'tags' => [],
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertNull($data['location']);
        $this->assertNull($data['notes']);
        $this->assertSame([], $data['tags']);
    }

    public function test_delete_returns_not_found_for_missing_receipt(): void
    {
        $this->client->request('DELETE', '/api/receipts/424242', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function test_parse_rejects_invalid_json(): void
    {
        $this->client->request('POST', '/api/receipts/parse', [], [], [
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'application/json',
        ], 'not-json');

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Invalid JSON body', $data['error']);
    }

    public function test_parse_rejects_blank_text(): void
    {
        $this->client->request('POST', '/api/receipts/parse', [], [], [
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['text' => '   ']));

        $this->assertResponseStatusCodeSame(422);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Text is required', $data['error']);
    }

    public function test_parse_queues_a_pending_job(): void
    {
        $this->client->request('POST', '/api/receipts/parse', [], [], [
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['text' => '  coffee and a bagel 8.50  ']));

        $this->assertResponseStatusCodeSame(202);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('queued', $data['status']);
        $this->assertArrayHasKey('job_id', $data);

        $job = $this->em->getRepository(\App\Entity\ParseJob::class)->find($data['job_id']);
        $this->assertNotNull($job);
        $this->assertSame('coffee and a bagel 8.50', $job->getRawText());
        $this->assertSame(\App\Entity\ParseJob::STATUS_PENDING, $job->getStatus());
    }

    public function test_autocomplete_endpoints_return_distinct_values(): void
    {
        $first = $this->persistReceipt();
        $first->setLocation('Downtown');

        $second = new Receipt();
        $second->setAmount('20.00');
        $second->setBusiness('Other Shop');
        $second->setCategory('Transport');
        $second->setLocation('Airport');
        $this->em->persist($second);

        $this->em->flush();

        $this->client->request('GET', '/api/autocomplete/businesses', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(['Other Shop', 'Test Store'], json_decode($this->client->getResponse()->getContent(), true));

        $this->client->request('GET', '/api/autocomplete/categories', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(['Food', 'Transport'], json_decode($this->client->getResponse()->getContent(), true));

        $this->client->request('GET', '/api/autocomplete/locations', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(['Airport', 'Downtown'], json_decode($this->client->getResponse()->getContent(), true));
    }

    public function test_new_receipt_page_renders(): void
    {
        $this->client->request('GET', '/receipts/new', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseIsSuccessful();
    }

    private function persistReceipt(): Receipt
    {
        $receipt = new Receipt();
        $receipt->setAmount('10.00');
        $receipt->setBusiness('Test Store');
        $receipt->setCategory('Food');
        $this->em->persist($receipt);
        $this->em->flush();

        return $receipt;
    }
}
