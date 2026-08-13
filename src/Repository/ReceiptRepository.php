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
            ->select('r.category, SUM(r.amount) as total, COUNT(r.id) as count')
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
    public function getCategoryAverages(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('r')
            ->select('r.category, AVG(r.amount) as average, (COUNT(r.id) / COUNT(DISTINCT STRFTIME(\'%Y-%m\', r.createdAt))) as average_count, COUNT(DISTINCT STRFTIME(\'%Y-%m\', r.createdAt)) as months')
            ->where('r.createdAt >= :from')
            ->andWhere('r.createdAt <= :to')
            ->groupBy('r.category')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();
    }

    /**
     * Return average monthly spend by category for the given number of months.
     *
     * @return array<int, array{category: string, avg_monthly_total: float}>
     */
    public function getCategoryMonthlyAverages(int $months = 3): array
    {
        $from = new \DateTimeImmutable("-{$months} months");
        $to = new \DateTimeImmutable();

        $rows = $this->createQueryBuilder('r')
            ->select("STRFTIME('%Y-%m', r.createdAt) as month, r.category, SUM(r.amount) as total")
            ->where('r.createdAt >= :from')
            ->andWhere('r.createdAt <= :to')
            ->groupBy('month')
            ->addGroupBy('r.category')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();

        // Aggregate in PHP: sum monthly totals per category, count months, compute average
        $sumByCategory = [];
        $countByCategory = [];
        foreach ($rows as $row) {
            $cat = $row['category'];
            $total = (float) $row['total'];
            $sumByCategory[$cat] = ($sumByCategory[$cat] ?? 0) + $total;
            $countByCategory[$cat] = ($countByCategory[$cat] ?? 0) + 1;
        }

        return array_map(function ($cat) use ($sumByCategory, $countByCategory) {
            return [
                'category' => $cat,
                'avg_monthly_total' => $countByCategory[$cat] > 0
                    ? round($sumByCategory[$cat] / $countByCategory[$cat], 2)
                    : 0.0,
            ];
        }, array_keys($sumByCategory));
    }

    /**
     * Return per-month, per-category spending totals for the last N months.
     *
     * Returns an associative array keyed by 'YYYY-MM', each value being a list of
     * {category, total} arrays sorted by total descending.
     *
     * @return array<string, array<int, array{category: string, total: float}>>
     */
    public function getMonthlyCategoryBreakdown(int $months): array
    {
        $now = new \DateTimeImmutable();
        $from = $now->modify("first day of -" . ($months - 1) . " months midnight");

        $rows = $this->createQueryBuilder('r')
            ->select("STRFTIME('%Y-%m', r.createdAt) as month, r.category, SUM(r.amount) as total")
            ->where('r.createdAt >= :from')
            ->andWhere('r.createdAt <= :now')
            ->groupBy('month')
            ->addGroupBy('r.category')
            ->orderBy('month', 'ASC')
            ->addOrderBy('total', 'DESC')
            ->setParameter('from', $from)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['month']][] = [
                'category' => $row['category'],
                'total' => (float) $row['total'],
            ];
        }

        return $result;
    }

    /**
     * Return the receipt with the largest amount in the given date range.
     *
     * @return array{id: int, business: string, amount: float, date: string}|null
     */
    public function getLargestReceipt(\DateTimeInterface $from, \DateTimeInterface $to): ?array
    {
        return $this->createQueryBuilder('r')
            ->select('r.id as id, r.business as business, r.amount as amount, DATE(r.createdAt) as date')
            ->where('r.createdAt >= :from')
            ->andWhere('r.createdAt <= :to')
            ->orderBy('r.amount', 'DESC')
            ->setMaxResults(1)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
