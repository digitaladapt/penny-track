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
        $from = $now->modify("first day of -" . ($months - 1) . " months midnight");

        $data = $this->receiptRepository->getSpendingByCategory($from, $now);

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
    public function topBusinesses(): JsonResponse
    {
        $now = new \DateTimeImmutable();
        $from = $now->modify('first day of this month midnight');

        $data = $this->receiptRepository->getTopBusinesses($from, $now, 5);

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
                'message' => sprintf('Most visited this month: %s ($%.2f)', $topBusinesses[0]['business'], (float) $topBusinesses[0]['total']),
            ];
        }

        // Spending velocity
        //$dayOfMonth = (int) $now->format('j');
        //$daysInMonth = (int) $now->format('t');
        //if ($dayOfMonth > 1 && $thisMonthTotal > 0) {
        //    $dailyRate = $thisMonthTotal / $dayOfMonth;
        //    $projected = $dailyRate * $daysInMonth;
        //    $insights[] = [
        //        'type' => 'info',
        //        'message' => sprintf('On track to spend ~$%.2f this month', $projected),
        //    ];
        //}

        // Category anomalies
        $categoryAverages = $this->receiptRepository->getCategoryAverages($startOfMonthsAgo, $endOfLastMonth);
        $thisMonthByCategory = $this->receiptRepository->getSpendingByCategory($startOfMonth, $now);

        $avgLookup = [];
        $maxMonths = 1.0;
        foreach ($categoryAverages as $row) {
            $avgLookup[$row['category']] = [
                'average_count' => (float)$row['average_count'],
                'average'       => (float)$row['average'],
                'months'        => (float)$row['months'],
            ];
            $maxMonths = max($maxMonths, (float)$row['months']);
        }

        $projectedMin = 0.0;
        $projectedMax = 0.0;
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
                $byCount  = $row['count'] / ($avgLookup[$cat]['average_count']);
                // half expected total amount
                $byAmount = $total / ($avgLookup[$cat]['average_count'] * $avgLookup[$cat]['average']);
                // cap projection to 100%
                $projectedMin += $total / (min(max($byCount, $byAmount), 1) * $avgLookup[$cat]['months'] / $maxMonths);
                $projectedMax += $total / (min($byCount, $byAmount, 1) * $avgLookup[$cat]['months'] / $maxMonths);
            } else {
                // new category, do not predict more
                $projectedMin += $total;
                $projectedMax += $total;
            }
        }
        foreach ($avgLookup as $cat => $row) {
            if ( ! isset($covered[$cat])) {
                // a category from the history, which has no spending in it yet
                $projectedMin += $row['average_count'] * $row['average'] * ($avgLookup[$cat]['months'] / $maxMonths);
                $projectedMax += $row['average_count'] * $row['average'] * ($avgLookup[$cat]['months'] / $maxMonths);
            }
        }
        $insights[] = [
            'type' => 'info',
            'message' => sprintf('On track to spend between $%.2f ~ $%.2f this month', $projectedMin, $projectedMax),
        ];

        foreach ($thisMonthByCategory as $row) {
            $cat = $row['category'];
            $total = (float) $row['total'];
            if (isset($avgLookup[$cat]['average']) && $avgLookup[$cat]['average'] > 0) {
                $ratio = $total / ($avgLookup[$cat]['average'] * $avgLookup[$cat]['average_count']);
                if ($ratio > 1.25) {
                    $insights[] = [
                        'type' => 'warning',
                        'message' => sprintf('Unusually high spending in %s (%.0fx average)', $cat, $ratio),
                    ];
                }
            }
        }

        return new JsonResponse($insights);
    }
}
