<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ReceiptRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly ReceiptRepository $receiptRepository,
    ) {
    }

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('dashboard/index.html.twig');
    }

    #[Route('/api/dashboard/summary', name: 'api_dashboard_summary', methods: ['GET'])]
    public function summary(): JsonResponse
    {
        $now = new \DateTimeImmutable();
        $startOfMonth = $now->modify('first day of this month midnight');
        $startOfLastMonth = $now->modify('first day of last month midnight');
        $endOfLastMonth = $startOfMonth;

        $thisMonthTotal = $this->receiptRepository->getTotalSpent($startOfMonth, $now);
        $lastMonthTotal = $this->receiptRepository->getTotalSpent($startOfLastMonth, $endOfLastMonth);
        $thisMonthCount = $this->receiptRepository->getCountInRange($startOfMonth, $now);
        $lastMonthCount = $this->receiptRepository->getCountInRange($startOfLastMonth, $endOfLastMonth);

        $avg = $thisMonthCount > 0 ? $thisMonthTotal / $thisMonthCount : 0;

        $change = 0;
        if ($lastMonthTotal > 0) {
            $change = (($thisMonthTotal - $lastMonthTotal) / $lastMonthTotal) * 100;
        }

        return new JsonResponse([
            'this_month_total' => round($thisMonthTotal, 2),
            'last_month_total' => round($lastMonthTotal, 2),
            'this_month_count' => $thisMonthCount,
            'last_month_count' => $lastMonthCount,
            'average_transaction' => round($avg, 2),
            'month_over_month_change_percent' => round($change, 1),
        ]);
    }

    #[Route('/api/dashboard/spending-by-category', name: 'api_dashboard_spending_by_category', methods: ['GET'])]
    public function spendingByCategory(Request $request): JsonResponse
    {
        $months = max(1, min(12, (int) $request->query->get('months', 1)));
        $now = new \DateTimeImmutable();
        $startOfMonth = $now->modify('first day of this month midnight');
        $startOfLastMonth = $now->modify('first day of -' . ($months - 1) . ' months midnight');
        $endOfLastMonth = (clone $startOfMonth)->modify('-1 second');

        // When comparison=true, return separate this_month and last_month breakdowns
        if ($request->query->getBoolean('comparison', false)) {
            $thisMonthData = $this->receiptRepository->getSpendingByCategory($startOfMonth, $now);
            $lastMonthData = $this->receiptRepository->getSpendingByCategory($startOfLastMonth, $endOfLastMonth);

            // Build lookup maps by category
            $thisMonthMap = [];
            foreach ($thisMonthData as $row) {
                $thisMonthMap[$row['category']] = (float) $row['total'];
            }

            $lastMonthMap = [];
            foreach ($lastMonthData as $row) {
                $lastMonthMap[$row['category']] = (float) $row['total'];
            }

            // Union of all categories from both months
            $allCategories = array_unique(array_merge(array_keys($thisMonthMap), array_keys($lastMonthMap)));
            sort($allCategories);

            return new JsonResponse([
                'this_month' => array_map(fn ($cat) => [
                    'category' => $cat,
                    'total' => $thisMonthMap[$cat] ?? 0.0,
                ], $allCategories),
                'last_month' => array_map(fn ($cat) => [
                    'category' => $cat,
                    'total' => $lastMonthMap[$cat] ?? 0.0,
                ], $allCategories),
            ]);
        }

        // Backward compatible: return only this month's data (flat array)
        $data = $this->receiptRepository->getSpendingByCategory($startOfMonth, $now);

        return new JsonResponse(array_map(fn ($row) => [
            'category' => $row['category'],
            'total' => (float) $row['total'],
        ], $data));
    }

    #[Route('/api/dashboard/spending-over-time', name: 'api_dashboard_spending_over_time', methods: ['GET'])]
    public function spendingOverTime(Request $request): JsonResponse
    {
        $days = max(7, min(365, (int) $request->query->get('days', 30)));
        $now = new \DateTimeImmutable();
        $from = $now->modify("-{$days} days midnight");

        $data = $this->receiptRepository->getSpendingOverTime($from, $now);

        // Fill in missing days with zeros
        $filled = [];
        $current = clone $from;
        $end = clone $now;
        $lookup = [];
        foreach ($data as $row) {
            $lookup[$row['date']] = (float) $row['total'];
        }

        while ($current <= $end) {
            $dateStr = $current->format('Y-m-d');
            $filled[] = [
                'date' => $dateStr,
                'total' => $lookup[$dateStr] ?? 0,
            ];
            /* modify() does not change the content of current directly, because it is DateTimeImmutable */
            $current = $current->modify('+1 day');
        }

        return new JsonResponse($filled);
    }

    #[Route('/api/dashboard/top-businesses', name: 'api_dashboard_top_businesses', methods: ['GET'])]
    public function topBusinesses(Request $request): JsonResponse
    {
        $now = new \DateTimeImmutable();
        $from = $now->modify('first day of this month midnight');

        $rawLimit = (int) $request->query->get('limit', 5);
        $allowedLimits = [5, 10, 15, 25];
        $limit = in_array($rawLimit, $allowedLimits, true) ? $rawLimit : 5;

        $data = $this->receiptRepository->getTopBusinesses($from, $now, $limit);

        return new JsonResponse(array_map(fn ($row) => [
            'business' => $row['business'],
            'total' => (float) $row['total'],
            'count' => (int) $row['count'],
        ], $data));
    }

    #[Route('/api/dashboard/insights', name: 'api_dashboard_insights', methods: ['GET'])]
    public function insights(): JsonResponse
    {
        $now = new \DateTimeImmutable();
        $startOfMonth = $now->modify('first day of this month midnight');
        $startOfLastMonth = $now->modify('first day of last month midnight');
        $startOfMonthsAgo = $now->modify('first day of 3 months ago midnight');
        $endOfLastMonth = $startOfMonth;

        $insights = [];

        $thisMonthTotal = $this->receiptRepository->getTotalSpent($startOfMonth, $now);
        $lastMonthTotal = $this->receiptRepository->getTotalSpent($startOfLastMonth, $endOfLastMonth);

        if ($lastMonthTotal > 0) {
            $change = (($thisMonthTotal - $lastMonthTotal) / $lastMonthTotal) * 100;
            if ($change > 20) {
                $insights[] = [
                    'type' => 'warning',
                    'message' => sprintf('You spent %.0f%% more than last month', $change),
                ];
            } elseif ($change < -20) {
                $insights[] = [
                    'type' => 'success',
                    'message' => sprintf('You spent %.0f%% less than last month', abs($change)),
                ];
            }
        }

        $topBusinesses = $this->receiptRepository->getTopBusinesses($startOfMonth, $now, 1);
        if (!empty($topBusinesses)) {
            $insights[] = [
                'type' => 'info',
                'message' => sprintf('Top spender this month: %s ($%.2f)', $topBusinesses[0]['business'], (float) $topBusinesses[0]['total']),
            ];
        }

        // Largest single transaction
        $largest = $this->receiptRepository->getLargestReceipt($startOfMonth, $now);
        if ($largest && (float)$largest['amount'] > 100) {
            $insights[] = [
                'type' => 'info',
                'message' => sprintf('Largest transaction this month: %s — $%.2f', $largest['business'], (float)$largest['amount']),
            ];
        }

        // Spending velocity v1.2 update
        $dayOfMonth = (int) $now->format('j');
        $daysInMonth = (int) $now->format('t');
        $thisMonthCount = $this->receiptRepository->getCountInRange($startOfMonth, $now);
        if ($dayOfMonth > 2 && $thisMonthTotal > 0 && $thisMonthCount >= 3) {
            $dailyRate = $thisMonthTotal / $dayOfMonth;
            $projected = $dailyRate * $daysInMonth;
            $insights[] = [
                'type' => 'info',
                'message' => sprintf('On track to spend ~$%.2f this month (v1.2)', $projected),
            ];
        }

        // Spending velocity v2.0 redesign
        $categoryAverages = $this->receiptRepository->getCategoryAverages($startOfMonthsAgo, $endOfLastMonth);
        $thisMonthByCategory = $this->receiptRepository->getSpendingByCategory($startOfMonth, $now);

        $avgLookup = [];
        foreach ($categoryAverages as $row) {
            $avgLookup[$row['category']] = [
                'average_count' => (float)$row['average_count'],
                'average'       => (float)$row['average'],
            ];
        }

        $projected = 0.0;
        $covered = [];
        foreach ($thisMonthByCategory as $row) {
            $cat = $row['category'];
            $total = (float)$row['total'];
            $covered[$cat] = true;
            // count and count can't be zero, otherwise there would be no results to report
            if (isset($avgLookup[$cat]['average_count']) &&
                $avgLookup[$cat]['average_count'] > $row['count']
            ) {
                // estimate how far we are through this month
                // half expected transaction count
                // half expected total amount
                $progress = (($row['count'] / $avgLookup[$cat]['average_count']) +
                    ($total / $avgLookup[$cat]['average_count'] * $avgLookup[$cat]['average'])
                ) / 2;
                // cap projection to 100%
                $projected += $total / min($progress, 1);
            } else {
                // new category, do not predict more
                $projected += $total;
            }
        }
        foreach ($avgLookup as $cat => $row) {
            if ( ! isset($covered[$cat])) {
                // a category from the history, which has no spending in it yet
                $projected += $row['average_count'] * $row['average'];
            }
        }
        $insights[] = [
            'type' => 'info',
            'message' => sprintf('On track to spend ~$%.2f this month (v2.0)', $projected),
        ];

        // Category anomalies
        $categoryMonthlyAverages = $this->receiptRepository->getCategoryMonthlyAverages(3);

        $avgLookup = [];
        foreach ($categoryMonthlyAverages as $row) {
            $avgLookup[$row['category']] = (float) $row['avg_monthly_total'];
        }

        foreach ($thisMonthByCategory as $row) {
            $cat = $row['category'];
            $total = (float) $row['total'];
            if (isset($avgLookup[$cat]) && $avgLookup[$cat] > 0) {
                $ratio = $total / $avgLookup[$cat];
                if ($ratio > 2) {
                    $insights[] = [
                        'type' => 'warning',
                        'message' => sprintf('Unusually high spending in %s (%.0fx average monthly)', $cat, $ratio),
                    ];
                }
            }
        }

        // New category detected — compare this month's categories against last 3 months
        $thisMonthCats = array_column($this->receiptRepository->getSpendingByCategory($startOfMonth, $now), 'category');
        $last3MonthsStart = (new \DateTimeImmutable('-3 months'))->modify('first day of midnight');
        $endOfLastMonth = (clone $startOfMonth)->modify('-1 second');

        $pastCategoriesData = $this->receiptRepository->getSpendingByCategory($last3MonthsStart, $endOfLastMonth);
        $pastCats = array_column($pastCategoriesData, 'category');

        $newCategories = array_diff($thisMonthCats, $pastCats);
        if (!empty($newCategories)) {
            foreach ($newCategories as $cat) {
                $insights[] = [
                    'type' => 'info',
                    'message' => sprintf('New category this month: %s', $cat),
                ];
            }
        }

        return new JsonResponse($insights);
    }
}
