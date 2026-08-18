<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ParseJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ParseJob>
 */
class ParseJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParseJob::class);
    }

    /**
     * Atomically claim the next pending job by setting it to processing.
     * Returns the claimed job or null if none available.
     *
     * Uses a transaction with SELECT ... FOR UPDATE to prevent race
         * conditions where two calls in the same worker cycle could claim
     * the same job (resulting in duplicate subprocesses).
     */
    public function claimNextPending(): ?ParseJob
    {
        $conn = $this->getEntityManager()->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        return $conn->transactional(function () use ($conn, $now): ?ParseJob {
            // Lock the row for update so concurrent callers can't grab the same job
            $row = $conn->executeQuery(
                'SELECT id FROM parse_job
                 WHERE status = ?
                 ORDER BY created_at ASC
                 LIMIT 1',
                [ParseJob::STATUS_PENDING]
            )->fetchAssociative();

            if ($row === false) {
                return null;
            }

            $jobId = (int) $row['id'];

            $conn->executeStatement(
                'UPDATE parse_job SET status = ?, updated_at = ? WHERE id = ?',
                [ParseJob::STATUS_PROCESSING, $now, $jobId]
            );

            // Clear the EM so we get a fresh entity with the updated status
            $this->getEntityManager()->clear();

            return $this->find($jobId);
        });
    }

    /**
     * Count jobs currently in processing status.
     */
    public function countProcessing(): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.status = :status')
            ->setParameter('status', ParseJob::STATUS_PROCESSING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Count jobs by status (excluding completed).
     */
    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.status IN (:statuses)')
            ->setParameter('statuses', [ParseJob::STATUS_PENDING, ParseJob::STATUS_PROCESSING])
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countFailed(): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.status = :status')
            ->setParameter('status', ParseJob::STATUS_FAILED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find all non-completed jobs ordered by creation date.
     *
     * @return ParseJob[]
     */
    public function findUncompleted(): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.status != :status')
            ->setParameter('status', ParseJob::STATUS_COMPLETED)
            ->orderBy('j.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find jobs that have been stuck in processing for too long.
     * These likely had their subprocess die unexpectedly.
     *
     * @return ParseJob[]
     */
    public function findStaleProcessing(\DateInterval $threshold): array
    {
        $cutoff = (new \DateTimeImmutable())->sub($threshold);

        return $this->createQueryBuilder('j')
            ->where('j.status = :status')
            ->andWhere('j.updatedAt < :cutoff')
            ->setParameter('status', ParseJob::STATUS_PROCESSING)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }
}
