<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\ApiKey;
use App\Entity\ParseJob;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ParseJobControllerTest extends WebTestCase
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

    public function test_index_lists_uncompleted_jobs(): void
    {
        $this->persistJob(ParseJob::STATUS_PENDING, 'coffee $5');
        $this->persistJob(ParseJob::STATUS_FAILED, 'failed purchase');
        $this->persistJob(ParseJob::STATUS_COMPLETED, 'completed purchase');

        $this->client->request('GET', '/parse-jobs', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'coffee $5');
        $this->assertSelectorTextContains('body', 'failed purchase');
        $this->assertSelectorTextNotContains('body', 'completed purchase');
    }

    public function test_retry_returns_not_found_for_unknown_job(): void
    {
        $this->client->request('POST', '/api/parse-jobs/424242/retry', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function test_retry_rejects_processing_job(): void
    {
        $job = $this->persistJob(ParseJob::STATUS_PROCESSING, 'busy');

        $this->client->request('POST', '/api/parse-jobs/'.$job->getId().'/retry', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseStatusCodeSame(409);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Cannot retry a job currently processing', $data['error']);
    }

    public function test_retry_rejects_completed_job(): void
    {
        $job = $this->persistJob(ParseJob::STATUS_COMPLETED, 'done');

        $this->client->request('POST', '/api/parse-jobs/'.$job->getId().'/retry', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseStatusCodeSame(409);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Cannot retry a completed job', $data['error']);
    }

    public function test_retry_resets_failed_job_to_pending(): void
    {
        $job = $this->persistJob(ParseJob::STATUS_FAILED, 'ouch');
        $job->setLastError('previous failure');
        $this->em->flush();
        $jobId = $job->getId();

        $this->client->request('POST', '/api/parse-jobs/'.$jobId.'/retry', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('ok', $data['status']);

        $this->em->clear();
        $reloaded = $this->em->getRepository(ParseJob::class)->find($jobId);
        $this->assertSame(ParseJob::STATUS_PENDING, $reloaded->getStatus());
        $this->assertNull($reloaded->getLastError());
    }

    public function test_delete_returns_not_found_for_unknown_job(): void
    {
        $this->client->request('DELETE', '/api/parse-jobs/424242', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function test_delete_rejects_processing_job(): void
    {
        $job = $this->persistJob(ParseJob::STATUS_PROCESSING, 'busy');

        $this->client->request('DELETE', '/api/parse-jobs/'.$job->getId(), [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseStatusCodeSame(409);
    }

    public function test_delete_removes_job(): void
    {
        $job = $this->persistJob(ParseJob::STATUS_FAILED, 'to delete');
        $jobId = $job->getId();

        $this->client->request('DELETE', '/api/parse-jobs/'.$jobId, [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseStatusCodeSame(204);

        $this->em->clear();
        $this->assertNull($this->em->getRepository(ParseJob::class)->find($jobId));
    }

    public function test_manual_add_returns_not_found_for_unknown_job(): void
    {
        $this->client->request('POST', '/api/parse-jobs/424242/manual-add', [], [], [
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['amount' => 5, 'business' => 'X', 'category' => 'Food']));

        $this->assertResponseStatusCodeSame(404);
    }

    public function test_manual_add_rejects_completed_job(): void
    {
        $job = $this->persistJob(ParseJob::STATUS_COMPLETED, 'done');

        $this->client->request('POST', '/api/parse-jobs/'.$job->getId().'/manual-add', [], [], [
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['amount' => 5, 'business' => 'X', 'category' => 'Food']));

        $this->assertResponseStatusCodeSame(409);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Job already completed', $data['error']);
    }

    public function test_manual_add_rejects_invalid_json(): void
    {
        $job = $this->persistJob(ParseJob::STATUS_PENDING, 'raw text');

        $this->client->request('POST', '/api/parse-jobs/'.$job->getId().'/manual-add', [], [], [
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'application/json',
        ], 'not-json');

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Invalid JSON body', $data['error']);
    }

    public function test_manual_add_returns_validation_errors(): void
    {
        $job = $this->persistJob(ParseJob::STATUS_PENDING, 'raw text');

        $this->client->request('POST', '/api/parse-jobs/'.$job->getId().'/manual-add', [], [], [
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $this->assertResponseStatusCodeSame(422);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('amount', $data['errors']);
        $this->assertArrayHasKey('business', $data['errors']);
        $this->assertArrayHasKey('category', $data['errors']);
    }

    public function test_manual_add_creates_receipt_and_completes_job(): void
    {
        $job = $this->persistJob(ParseJob::STATUS_FAILED, 'lunch with the team');
        $jobId = $job->getId();

        $this->client->request('POST', '/api/parse-jobs/'.$jobId.'/manual-add', [], [], [
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'amount' => '18.456',
            'business' => 'Sandwich Shop',
            'category' => 'Food',
            'location' => 'Downtown',
            'tags' => ['lunch', 'work'],
            'created_at' => '2025-05-04T10:00:00+00:00',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('ok', $data['status']);
        $this->assertNotNull($data['receipt_id']);

        $this->em->clear();
        $reloaded = $this->em->getRepository(ParseJob::class)->find($jobId);
        $this->assertSame(ParseJob::STATUS_COMPLETED, $reloaded->getStatus());
        $this->assertNotNull($reloaded->getReceipt());
        $this->assertNotNull($reloaded->getCompletedAt());
        $this->assertNull($reloaded->getLastError());

        $receipt = $reloaded->getReceipt();
        $this->assertSame('18.46', $receipt->getAmount());
        $this->assertSame('Sandwich Shop', $receipt->getBusiness());
        $this->assertSame('Food', $receipt->getCategory());
        $this->assertSame('Downtown', $receipt->getLocation());
        $this->assertSame(['lunch', 'work'], $receipt->getTags());
        $this->assertSame('lunch with the team', $receipt->getNotes());
        $this->assertSame('lunch with the team', $receipt->getRawInput());
        $this->assertSame('2025-05-04', $receipt->getCreatedAt()?->format('Y-m-d'));
    }

    public function test_manual_add_uses_raw_text_when_notes_missing_and_ignores_bad_date(): void
    {
        $job = $this->persistJob(ParseJob::STATUS_PENDING, 'original raw text');
        $jobId = $job->getId();

        $this->client->request('POST', '/api/parse-jobs/'.$jobId.'/manual-add', [], [], [
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'amount' => 7,
            'business' => 'Bakery',
            'category' => 'Food',
            'tags' => 'not-an-array',
            'created_at' => 'definitely-not-a-date',
        ]));

        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $reloaded = $this->em->getRepository(ParseJob::class)->find($jobId);
        $receipt = $reloaded->getReceipt();
        $this->assertNotNull($receipt);
        $this->assertSame('original raw text', $receipt->getNotes());
        $this->assertSame([], $receipt->getTags());
        $this->assertNotNull($receipt->getCreatedAt());
    }

    public function test_counts_endpoint_reports_pending_and_failed(): void
    {
        $this->persistJob(ParseJob::STATUS_PENDING, 'a');
        $this->persistJob(ParseJob::STATUS_PROCESSING, 'b');
        $this->persistJob(ParseJob::STATUS_FAILED, 'c');
        $this->persistJob(ParseJob::STATUS_COMPLETED, 'd');

        $this->client->request('GET', '/api/parse-jobs/counts', [], [], ['HTTP_X_API_KEY' => $this->apiKey]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(2, $data['pending']);
        $this->assertSame(1, $data['failed']);
    }

    private function persistJob(string $status, string $rawText): ParseJob
    {
        $job = new ParseJob();
        $job->setRawText($rawText);
        $job->setStatus($status);
        $this->em->persist($job);
        $this->em->flush();

        return $job;
    }
}
