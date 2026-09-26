<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Command\ParseJobProcessCommand;
use App\Entity\ParseJob;
use App\Repository\ParseJobRepository;
use App\Service\LLM\LlmClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Override;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
class ParseJobProcessCommandTest extends KernelTestCase
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

    public function test_unknown_job_id_fails(): void
    {
        $tester = $this->tester($this->createMock(LlmClient::class));

        $exitCode = $tester->execute(['job-id' => '999999']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('not found', $tester->getDisplay());
    }

    public function test_job_not_in_processing_state_is_skipped(): void
    {
        $job = $this->persistJob(ParseJob::STATUS_PENDING);
        $tester = $this->tester($this->createMock(LlmClient::class));

        $exitCode = $tester->execute(['job-id' => (string) $job->getId()]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('is not in processing state', $tester->getDisplay());
        $this->assertSame(ParseJob::STATUS_PENDING, $this->reload($job)->getStatus());
    }

    public function test_successful_processing_creates_receipt_and_completes_job(): void
    {
        $job = $this->persistJob(ParseJob::STATUS_PROCESSING, 'lunch 12.50 at cafe');

        $llm = $this->createMock(LlmClient::class);
        $llm->expects($this->once())
            ->method('chat')
            ->willReturn([
                'amount' => '12.5',
                'business' => 'Cafe',
                'category' => 'Food',
                'location' => 'Main St',
                'tags' => ['lunch', 42, 'work'],
                'notes' => 'team lunch',
                'date' => '2025-06-01T12:30:00+00:00',
            ]);

        $tester = $this->tester($llm);
        $exitCode = $tester->execute(['job-id' => (string) $job->getId()]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertStringContainsString('completed', $tester->getDisplay());

        $reloaded = $this->reload($job);
        $this->assertSame(ParseJob::STATUS_COMPLETED, $reloaded->getStatus());
        $this->assertSame(1, $reloaded->getAttempts());
        $this->assertNotNull($reloaded->getCompletedAt());

        $receipt = $reloaded->getReceipt();
        $this->assertNotNull($receipt);
        $this->assertEqualsWithDelta(12.50, (float) $receipt->getAmount(), 0.001);
        $this->assertSame('Cafe', $receipt->getBusiness());
        $this->assertSame('Food', $receipt->getCategory());
        $this->assertSame('Main St', $receipt->getLocation());
        $this->assertSame(['lunch', 'work'], $receipt->getTags());
        $this->assertSame('team lunch', $receipt->getNotes());
        $this->assertSame('lunch 12.50 at cafe', $receipt->getRawInput());
        $this->assertSame('2025-06-01 12:30:00', $receipt->getCreatedAt()?->format('Y-m-d H:i:s'));
    }

    public function test_invalid_llm_fields_fall_back_to_defaults(): void
    {
        $job = $this->persistJob(ParseJob::STATUS_PROCESSING, 'mystery charge');

        $llm = $this->createMock(LlmClient::class);
        $llm->method('chat')->willReturn([
            'amount' => 'not-a-number',
            'business' => '',
            'category' => '',
            'location' => '',
            'tags' => 'not-an-array',
            'notes' => '',
            'date' => 'definitely-not-a-date',
        ]);

        $tester = $this->tester($llm);
        $exitCode = $tester->execute(['job-id' => (string) $job->getId()]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());

        $receipt = $this->reload($job)->getReceipt();
        $this->assertNotNull($receipt);
        $this->assertEqualsWithDelta(0.0, (float) $receipt->getAmount(), 0.001);
        $this->assertSame('Unknown', $receipt->getBusiness());
        $this->assertSame('Other', $receipt->getCategory());
        $this->assertNull($receipt->getLocation());
        $this->assertSame([], $receipt->getTags());
        $this->assertSame('mystery charge', $receipt->getNotes());
    }

    public function test_date_without_time_gets_current_time_of_day(): void
    {
        $job = $this->persistJob(ParseJob::STATUS_PROCESSING, 'coffee');

        $llm = $this->createMock(LlmClient::class);
        $llm->method('chat')->willReturn([
            'amount' => 4.5,
            'business' => 'Cafe',
            'category' => 'Food',
            'date' => '2025-06-01',
        ]);

        $tester = $this->tester($llm);
        $tester->execute(['job-id' => (string) $job->getId()]);

        $receipt = $this->reload($job)->getReceipt();
        $this->assertNotNull($receipt);
        $this->assertSame('2025-06-01', $receipt->getCreatedAt()?->format('Y-m-d'));
        $this->assertNotSame('00:00:00', $receipt->getCreatedAt()?->format('H:i:s'));
    }

    public function test_llm_failure_marks_job_failed_when_attempts_are_exhausted(): void
    {
        $job = $this->persistJob(ParseJob::STATUS_PROCESSING, 'bad input', 0, 1);

        $llm = $this->createMock(LlmClient::class);
        $llm->method('chat')->willThrowException(new RuntimeException('LLM exploded'));

        $tester = $this->tester($llm);
        $exitCode = $tester->execute(['job-id' => (string) $job->getId()]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('LLM exploded', $tester->getDisplay());

        $reloaded = $this->reload($job);
        $this->assertSame(ParseJob::STATUS_FAILED, $reloaded->getStatus());
        $this->assertSame('LLM exploded', $reloaded->getLastError());
        $this->assertSame(1, $reloaded->getAttempts());
    }

    public function test_llm_failure_returns_job_to_pending_when_retries_remain(): void
    {
        $job = $this->persistJob(ParseJob::STATUS_PROCESSING, 'flaky input', 0, 3);

        $llm = $this->createMock(LlmClient::class);
        $llm->method('chat')->willThrowException(new RuntimeException('temporary glitch'));

        $tester = $this->tester($llm);
        $exitCode = $tester->execute(['job-id' => (string) $job->getId()]);

        $this->assertSame(1, $exitCode);

        $reloaded = $this->reload($job);
        $this->assertSame(ParseJob::STATUS_PENDING, $reloaded->getStatus());
        $this->assertSame('temporary glitch', $reloaded->getLastError());
        $this->assertSame(1, $reloaded->getAttempts());
    }

    private function tester(LlmClient&MockObject $llm): CommandTester
    {
        return new CommandTester(new ParseJobProcessCommand($this->em(), $this->jobs(), $llm));
    }

    private function persistJob(string $status, string $rawText = 'coffee $5', int $attempts = 0, int $maxAttempts = 1): ParseJob
    {
        $job = new ParseJob();
        $job->setRawText($rawText);
        $job->setStatus($status);
        $job->setAttempts($attempts);
        $job->setMaxAttempts($maxAttempts);

        $em = $this->em();
        $em->persist($job);
        $em->flush();

        return $job;
    }

    private function reload(ParseJob $job): ParseJob
    {
        $this->em()->clear();
        $reloaded = $this->jobs()->find($job->getId());
        $this->assertInstanceOf(ParseJob::class, $reloaded);

        return $reloaded;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function jobs(): ParseJobRepository
    {
        return static::getContainer()->get(ParseJobRepository::class);
    }
}
