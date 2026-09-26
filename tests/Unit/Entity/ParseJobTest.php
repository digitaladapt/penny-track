<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ParseJob;
use App\Entity\Receipt;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ParseJobTest extends TestCase
{
    public function test_default_state(): void
    {
        $job = new ParseJob();

        $this->assertNull($job->getId());
        $this->assertNull($job->getRawText());
        $this->assertSame(ParseJob::STATUS_PENDING, $job->getStatus());
        $this->assertSame(0, $job->getAttempts());
        $this->assertSame(1, $job->getMaxAttempts());
        $this->assertNull($job->getLastError());
        $this->assertNull($job->getReceipt());
        $this->assertNull($job->getCreatedAt());
        $this->assertNull($job->getUpdatedAt());
        $this->assertNull($job->getCompletedAt());
    }

    public function test_setters_are_chainable_and_round_trip(): void
    {
        $job = new ParseJob();
        $receipt = new Receipt();
        $createdAt = new DateTimeImmutable('2025-01-02 03:04:05');
        $updatedAt = new DateTimeImmutable('2025-01-03 04:05:06');
        $completedAt = new DateTimeImmutable('2025-01-04 05:06:07');

        $result = $job
            ->setRawText('coffee 5 dollars')
            ->setStatus(ParseJob::STATUS_PROCESSING)
            ->setAttempts(2)
            ->setMaxAttempts(5)
            ->setLastError('oops')
            ->setReceipt($receipt)
            ->setCreatedAt($createdAt)
            ->setUpdatedAt($updatedAt)
            ->setCompletedAt($completedAt);

        $this->assertSame($job, $result);
        $this->assertSame('coffee 5 dollars', $job->getRawText());
        $this->assertSame(ParseJob::STATUS_PROCESSING, $job->getStatus());
        $this->assertSame(2, $job->getAttempts());
        $this->assertSame(5, $job->getMaxAttempts());
        $this->assertSame('oops', $job->getLastError());
        $this->assertSame($receipt, $job->getReceipt());
        $this->assertSame($createdAt, $job->getCreatedAt());
        $this->assertSame($updatedAt, $job->getUpdatedAt());
        $this->assertSame($completedAt, $job->getCompletedAt());
    }

    public function test_increment_attempts(): void
    {
        $job = new ParseJob();

        $job->incrementAttempts();
        $job->incrementAttempts();

        $this->assertSame(2, $job->getAttempts());
    }

    public function test_pre_persist_sets_timestamps(): void
    {
        $job = new ParseJob();

        $job->onPrePersist();

        $this->assertInstanceOf(DateTimeImmutable::class, $job->getCreatedAt());
        $this->assertInstanceOf(DateTimeImmutable::class, $job->getUpdatedAt());
    }

    public function test_pre_persist_keeps_existing_created_at(): void
    {
        $createdAt = new DateTimeImmutable('2025-01-01 00:00:00');
        $job = new ParseJob();
        $job->setCreatedAt($createdAt);

        $job->onPrePersist();

        $this->assertSame($createdAt, $job->getCreatedAt());
    }

    public function test_pre_update_refreshes_updated_at(): void
    {
        $job = new ParseJob();
        $job->setUpdatedAt(new DateTimeImmutable('2020-01-01 00:00:00'));

        $job->onPreUpdate();

        $this->assertNotSame(
            '2020-01-01 00:00:00',
            $job->getUpdatedAt()?->format('Y-m-d H:i:s'),
        );
    }
}
