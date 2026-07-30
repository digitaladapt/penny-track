<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Receipt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Receipt>
 */
class ReceiptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Receipt::class);
    }

    /**
     * @return Receipt[]
     */
    public function findRecent(int $limit = 10): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<string, string>
     */
    public function findUniqueBusinesses(): array
    {
        $results = $this->createQueryBuilder('r')
            ->select('DISTINCT r.business')
            ->orderBy('r.business', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_filter($results));
    }

    /**
     * @return array<string, string>
     */
    public function findUniqueCategories(): array
    {
        $results = $this->createQueryBuilder('r')
            ->select('DISTINCT r.category')
            ->orderBy('r.category', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_filter($results));
    }

    /**
     * @return array<string, string>
     */
    public function findUniqueLocations(): array
    {
        $results = $this->createQueryBuilder('r')
            ->select('DISTINCT r.location')
            ->where('r.location IS NOT NULL')
            ->orderBy('r.location', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_filter($results));
    }

    public function getTotalSpent(\DateTimeInterface $from, \DateTimeInterface $to): float
    {
        $result = $this->createQueryBuilder('r')
            ->select('SUM(r.amount)')
            ->where('r.createdAt >= :from')
            ->andWhere('r.createdAt <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    public function getCountInRange(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        $result = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.createdAt >= :from')
            ->andWhere('r.createdAt <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    /**
     * @return array<int, array{category: string, total: float}>
     */
    public function getSpendingByCategory(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('r')
            ->select('r.category, SUM(r.amount) as total')
            ->where('r.createdAt >= :from')
            ->andWhere('r.createdAt <= :to')
            ->groupBy('r.category')
            ->orderBy('total', 'DESC')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<int, array{date: string, total: float}>
     */
    public function getSpendingOverTime(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('r')
            ->select("DATE(r.createdAt) as date, SUM(r.amount) as total")
            ->where('r.createdAt >= :from')
            ->andWhere('r.createdAt <= :to')
            ->groupBy('date')
            ->orderBy('date', 'ASC')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<int, array{business: string, total: float, count: int}>
     */
    public function getTopBusinesses(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 5): array
    {
        return $this->createQueryBuilder('r')
            ->select('r.business, SUM(r.amount) as total, COUNT(r.id) as count')
            ->where('r.createdAt >= :from')
            ->andWhere('r.createdAt <= :to')
            ->groupBy('r.business')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<int, array{category: string, total: float}>
     */
    public function getCategoryAverages(int $months = 3): array
    {
        $from = new \DateTimeImmutable("-{$months} months");
        $to = new \DateTimeImmutable();

        return $this->createQueryBuilder('r')
            ->select('r.category, AVG(r.amount) as total')
            ->where('r.createdAt >= :from')
            ->andWhere('r.createdAt <= :to')
            ->groupBy('r.category')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();
    }
}
