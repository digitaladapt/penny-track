<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Entity\ParseJob;
use App\Repository\ParseJobRepository;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Override;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ParseJobRepositoryTest extends KernelTestCase
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

    public function test_claim_next_pending_returns_null_when_nothing_pending(): void
    {
        $this->persistJob(ParseJob::STATUS_PROCESSING);
        $this->persistJob(ParseJob::STATUS_FAILED);
        $this->persistJob(ParseJob::STATUS_COMPLETED);

        $this->assertNull($this->repository()->claimNextPending());
    }

    public function test_claim_next_pending_claims_oldest_job_and_marks_it_processing(): void
    {
        $oldest = $this->persistJob(ParseJob::STATUS_PENDING, new DateTimeImmutable('-10 minutes'));
        $newer = $this->persistJob(ParseJob::STATUS_PENDING, new DateTimeImmutable('-5 minutes'));

        $claimed = $this->repository()->claimNextPending();

        $this->assertNotNull($claimed);
        $this->assertSame($oldest->getId(), $claimed->getId());
        $this->assertSame(ParseJob::STATUS_PROCESSING, $claimed->getStatus());

        $fresh = $this->repository()->find($newer->getId());
        $this->assertSame(ParseJob::STATUS_PENDING, $fresh?->getStatus());
    }

    public function test_count_methods(): void
    {
        $this->persistJob(ParseJob::STATUS_PENDING);
        $this->persistJob(ParseJob::STATUS_PENDING);
        $this->persistJob(ParseJob::STATUS_PROCESSING);
        $this->persistJob(ParseJob::STATUS_FAILED);
        $this->persistJob(ParseJob::STATUS_COMPLETED);

        $repository = $this->repository();

        $this->assertSame(1, $repository->countProcessing());
        $this->assertSame(3, $repository->countPending());
        $this->assertSame(1, $repository->countFailed());
    }

    public function test_find_uncompleted_returns_everything_but_completed(): void
    {
        $pending = $this->persistJob(ParseJob::STATUS_PENDING);
        $processing = $this->persistJob(ParseJob::STATUS_PROCESSING);
        $failed = $this->persistJob(ParseJob::STATUS_FAILED);
        $this->persistJob(ParseJob::STATUS_COMPLETED);

        $jobs = $this->repository()->findUncompleted();

        $ids = array_map(static fn (ParseJob $job) => $job->getId(), $jobs);
        $this->assertCount(3, $ids);
        $this->assertContains($pending->getId(), $ids);
        $this->assertContains($processing->getId(), $ids);
        $this->assertContains($failed->getId(), $ids);
    }

    public function test_find_stale_processing_only_returns_aged_jobs(): void
    {
        $stale = $this->persistJob(ParseJob::STATUS_PROCESSING);
        $this->ageUpdatedAt($stale, new DateTimeImmutable('-10 minutes'));

        $fresh = $this->persistJob(ParseJob::STATUS_PROCESSING);

        $jobs = $this->repository()->findStaleProcessing(new DateInterval('PT5M'));

        $ids = array_map(static fn (ParseJob $job) => $job->getId(), $jobs);
        $this->assertContains($stale->getId(), $ids);
        $this->assertNotContains($fresh->getId(), $ids);
    }

    private function persistJob(string $status, ?DateTimeImmutable $createdAt = null): ParseJob
    {
        $job = new ParseJob();
        $job->setRawText('coffee $5');
        $job->setStatus($status);
        $job->setCreatedAt($createdAt ?? new DateTimeImmutable());

        $em = $this->em();
        $em->persist($job);
        $em->flush();

        return $job;
    }

    /**
     * Lifecycle callbacks always stamp updated_at on flush, so aging a job
     * has to bypass the ORM.
     */
    private function ageUpdatedAt(ParseJob $job, DateTimeImmutable $updatedAt): void
    {
        $connection = $this->em()->getConnection();
        $connection->executeStatement(
            'UPDATE parse_job SET updated_at = ? WHERE id = ?',
            [$updatedAt->format('Y-m-d H:i:s'), $job->getId()],
        );
        $this->em()->clear();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function repository(): ParseJobRepository
    {
        return static::getContainer()->get(ParseJobRepository::class);
    }
}
