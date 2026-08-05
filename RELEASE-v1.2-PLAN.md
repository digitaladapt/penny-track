# Penny-Track — Release v1.2 Planning Document

**Status:** Draft  
**Target Version:** 1.2.0  
**Date:** 2026-08-03  

---

## Executive Summary

This document details all changes planned for release v1.2 of Penny-Track. The scope includes UI/UX improvements to the receipt entry flow and dashboard, new configurable display options with client-side persistence via localStorage, an API enhancement to support month-over-month category comparison, and corrections to flawed insight calculations.

No database migrations or entity schema changes are required for this release.

---

## Change Log Overview

| # | Feature / Fix | Type | Impact Area(s) | Complexity |
|---|---------------|------|----------------|------------|
| 1 | Default date/time to "now" on new receipt form | UX fix | Frontend (new.html.twig) | Low |
| 2 | Spending by category: side-by-side this month vs last month | New feature + API change | DashboardController, Dashboard template, ReceiptRepository | Medium |
| 3 | Top Businesses count selector (5/10/15/25) with localStorage persistence | New feature + API change | DashboardController, Dashboard template | Low-Medium |
| 4 | Recent Transactions count selector (10/25/50/100) with localStorage persistence | UX enhancement | Dashboard template only | Low |
| 5 | Fix category anomaly insight calculation bug | Bug fix | DashboardController, ReceiptRepository | Medium |
| 6 | Fix "Most visited" insight label accuracy | Bug fix | DashboardController | Low |
| 7 | Add missing insights (see section below) | New feature | DashboardController, ReceiptRepository | Medium |

---

## Detailed Specifications

### 1. Default Date/Time to "Now" on New Receipt Form

**Problem:** The `datetime-local` input on `/receipts/new` is empty by default with label saying "(optional, defaults to now)". This is confusing because users expect the field to reflect what will actually be saved if they don't touch it.

**Current behavior:**  
- Input has no value set on page load.
- If user leaves blank, `created_at: null` sent → entity lifecycle callback sets NOW.

**Desired behavior:**  
- On page load (and after form reset), the datetime-local input is pre-filled with the current local date and time in ISO format (`YYYY-MM-DDTHH:mm`).

**Implementation:**

- **File:** `templates/receipt/new.html.twig`
- Add a JavaScript function that:
  - Gets current DateTime.
  - Formats to local ISO string compatible with `datetime-local`.
  - Sets the value of `[name="created_at"]`.
- Call this function on DOMContentLoaded and again after any form reset (including successful submission before redirect).

**Code snippet (JS):**
```javascript
function setDefaultDateTime() {
    const now = new Date();
    const offset = now.getTimezoneOffset();
    const local = new Date(now.getTime() - offset * 60000);
    document.querySelector('input[name="created_at"]').value =
        local.toISOString().slice(0, 16); // YYYY-MM-DDTHH:mm
}

document.addEventListener('DOMContentLoaded', setDefaultDateTime);
// Also call after reset if needed before redirect:
form.onreset = () => setTimeout(setDefaultDateTime, 0);
```

**Testing:**
- Functional test: Load `/receipts/new`, assert datetime-local input has a value matching current date.

---

### 2. Spending by Category — This Month vs Last Month (Side-by-Side)

**Problem:** The existing category chart shows aggregated spending over N months combined into one dataset, making it impossible to compare this month against last month visually. PLANNING.md explicitly called out "this month vs. last month toggle" but it was never implemented that way.

**Current API endpoint:**  
`GET /api/dashboard/spending-by-category?months=N`  
Returns flat list: `[{ category, total }]` — all months summed together.

**New API specification:**

**Endpoint:** `GET /api/dashboard/spending-by-category`  

**Query parameters (optional):**
| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `comparison` | bool | true | If true, returns separate this_month and last_month breakdowns. |

When `comparison=true`:
```json
{
  "this_month": [
    { "category": "Food", "total": 320.50 },
    { "category": "Transport", "total": 85.00 }
  ],
  "last_month": [
    { "category": "Food", "total": 410.25 },
    { "category": "Transport", "total": 62.75 }
  ]
}
```

When `comparison=false` (backward compatible):
```json
[
  { "category": "Food", "total": 320.50 },
  { "category": "Transport", "total": 85.00 }
]
```
This returns only this month's data (same as `months=1` before).

**Repository changes:**

Add a new method or enhance existing to support returning category totals for two date ranges:

- **File:** `src/Repository/ReceiptRepository.php`  
  Add helper that accepts two range pairs and merges results by category.

Approach:
```php
/**
 * @return array{this_month: array<string,float>, last_month: array<string,float>}
 */
public function getSpendingByCategoryComparison(
    DateTimeInterface $thisMonthStart,
    DateTimeInterface $lastMonthStart,
    DateTimeInterface $lastMonthEnd
): array {
    // Query this month totals by category
    // Query last month totals by category
    // Union all categories from both periods
    // Return aligned arrays keyed by category with zeros for missing months
}
```

**Controller changes:**

- **File:** `src/Controller/DashboardController.php`  
  Modify `spendingByCategory()` action to detect `comparison` param and return new shape.

**Frontend changes:**

- **File:** `templates/dashboard/index.html.twig`

Update the category chart section:
- Change from single-dataset doughnut chart to a grouped bar chart with two series ("This Month" and "Last Month").
- Categories that have no spending in one month display 0 for that month's bar.
- Keep the title as "Spending by Category (This Month vs Last Month)".

Chart.js configuration:
```javascript
categoryChart = new Chart(document.getElementById('category-chart'), {
    type: 'bar',
    data: {
        labels: categories, // union of both months' categories
        datasets: [
            { label: 'This Month', data: thisMonthTotals, backgroundColor: '#10b981', borderRadius: 4 },
            { label: 'Last Month', data: lastMonthTotals, backgroundColor: '#9ca3af', borderRadius: 4 }
        ]
    },
    options: { ... }
});
```

**Testing:**
- Functional test for API endpoint with `comparison=true`: verify response shape and that both periods are correct.
- Functional test for backward compatibility (`comparison=false` or omitted defaults to current-month-only list).

---

### 3. Top Businesses Count Selector (5 / 10 / 15 / 25)

**Problem:** Currently the API returns exactly 5 top businesses with no way for user to see more. Users may want to explore deeper into their spending history by merchant.

**Current behavior:**  
`GET /api/dashboard/top-businesses` → hardcoded limit of 5 in controller, calls `getTopBusinesses(..., 5)`.

**New API specification:**

**Endpoint:** `GET /api/dashboard/top-businesses`

**Query parameter (new):**
| Param | Type | Default | Allowed values | Description |
|-------|------|---------|----------------|-------------|
| `limit` | int | 5 | [5, 10, 15, 25] | Number of businesses to return. Clamped if out of range. |

Response format unchanged:
```json
[
  { "business": "Walmart", "total": 487.32, "count": 14 },
  ...
]
```

**Repository changes:** None required — `getTopBusinesses()` already accepts a `$limit` parameter.

**Controller changes:**

- **File:** `src/Controller/DashboardController.php`  
  Update `topBusinesses()` action to read and validate the `limit` query param:

```php
$rawLimit = (int) $request->query->get('limit', 5);
$allowedLimits = [5, 10, 15, 25];
$limit = in_array($rawLimit, $allowedLimits, true) ? $rawLimit : 5;

$data = $this->receiptRepository->getTopBusinesses($from, $now, $limit);
```

**Frontend changes:**

- **File:** `templates/dashboard/index.html.twig`

Add a dropdown above the Top Businesses chart:
```html
<div class="flex items-center justify-between mb-2">
    <h3 class="text-sm font-semibold text-gray-700">Top Businesses</h3>
    <select id="top-businesses-limit" class="text-xs border rounded-md px-2 py-1 bg-white">
        <option value="5" selected>Show 5</option>
        <option value="10">Show 10</option>
        <option value="15">Show 15</option>
        <option value="25">Show 25</option>
    </select>
</div>
```

JavaScript behavior:
- On load, read `localStorage.getItem('pennyTrack_topBusinessesLimit')` and select matching option (default to 5 if invalid/missing).
- On dropdown change: store selection in localStorage, call API with new limit, re-render chart.

```javascript
function getTopBusinessesLimit() {
    return parseInt(localStorage.getItem('pennyTrack_topBusinessesLimit') || '5', 10);
}

document.getElementById('top-businesses-limit').addEventListener('change', async (e) => {
    localStorage.setItem('pennyTrack_topBusinessesLimit', e.target.value);
    // Re-fetch and redraw business chart with new limit
});
```

**Testing:**
- Functional test: request `/api/dashboard/top-businesses?limit=15`, assert 15 or fewer items returned.
- Functional test: invalid limit (e.g., `limit=99`) falls back to default of 5.

---

### 4. Recent Transactions Count Selector (10 / 25 / 50 / 100)

**Problem:** The dashboard fetches up to 100 receipts and displays all of them in one unpaginated table with no user control over how many are visible at once.

**Current behavior:**  
- JS calls `/api/receipts?limit=100`
- All results rendered into table body.

**Desired behavior:**  
User selects how many transactions to display from a dropdown (10/25/50/100). Selection persisted in localStorage. No new API endpoint needed — we already fetch 100; this is purely client-side filtering.

**Frontend changes only:**

- **File:** `templates/dashboard/index.html.twig`

Add a dropdown near the Recent Transactions header:
```html
<div class="flex items-center justify-between mb-3">
    <h2 class="text-lg font-semibold text-gray-900">Recent Transactions</h2>
    <div class="flex items-center gap-3">
        <select id="recent-transactions-limit" class="text-xs border rounded-md px-2 py-1 bg-white">
            <option value="10" selected>Show 10</option>
            <option value="25">Show 25</option>
            <option value="50">Show 50</option>
            <option value="100">Show 100</option>
        </select>
        <a href="{{ path('app_receipt_new') }}" class="text-primary-600 text-sm font-medium hover:underline">+ Add New</a>
    </div>
</div>
```

JavaScript behavior:
- On load, read `localStorage.getItem('pennyTrack_recentTransactionsLimit')` (default 10) and set dropdown.
- Slice the already-fetched receipts array before rendering based on selected limit.
- On dropdown change: update localStorage, re-render table with new slice.

```javascript
function getRecentTransactionsLimit() {
    return parseInt(localStorage.getItem('pennyTrack_recentTransactionsLimit') || '10', 10);
}

const renderRecentTransactions = (allReceipts) => {
    const limit = getRecentTransactionsLimit();
    const visible = allReceipts.slice(0, limit);
    // ... render `visible` instead of allReceipts
};

document.getElementById('recent-transactions-limit').addEventListener('change', () => {
    localStorage.setItem('pennyTrack_recentTransactionsLimit', e.target.value);
    renderRecentTransactions(allReceipts);
});
```

**Testing:**
- Manual verification sufficient (no backend change).
- Could add a frontend integration test with Panther if desired.

---

### 5. Dashboard Insights — Review and Fixes

#### Existing Insights Audit

| Insight | Current Logic | Issue? | Severity |
|---------|--------------|--------|----------|
| Month-over-month >20% more | Compares thisMonthTotal vs lastMonthTotal, triggers if change >20% | Correct logic. Clear message. | None |
| Month-over-month >20% less | Same as above but < -20% | Correct logic. Good positive reinforcement. | None |
| Most visited business (by $) | Uses `getTopBusinesses(..., 1)` ordered by total spend | Label says "visited" but ranks by dollars spent, not count of visits. Misleading. | Medium |
| Spending velocity projection | `(thisMonthTotal / dayOfMonth) * daysInMonth` | Correct calculation. Helpful insight. Edge case: shows on very early month with tiny data — consider requiring minimum transaction count. | Low |
| Category anomaly (>2x average) | Uses `getCategoryAverages(3)` which computes AVG(r.amount) per category over 3 months, then compares thisMonthTotal to that | **BUG.** Comparing a monthly total against an average-per-transaction value is meaningless. A $500 month in Food with 10 transactions gives avg $50; comparing monthly total $500 to avg $50 triggers every time. | High |

#### Fix #5a: Category Anomaly Calculation

**Current (buggy) repository method:**
```php
public function getCategoryAverages(int $months = 3): array {
    // AVG(r.amount) — average per transaction, NOT monthly total!
}
```

**New approach:** Compute the average *monthly spending* per category over the last N months.

Add a new repository method:

- **File:** `src/Repository/ReceiptRepository.php`

```php
/**
 * Return average monthly spend by category for the given number of months.
 * @return array<int, array{category: string, avg_monthly_total: float}>
 */
public function getCategoryMonthlyAverages(int $months = 3): array {
    // Strategy: 
    // 1. Get total per category for each of the last N months.
    // 2. Average those monthly totals by category.
}
```

Implementation option (Doctrine-friendly, single query using GROUP BY month and category, then aggregate in PHP):
```php
$from = new \DateTimeImmutable("-{$months} months");
$to = new \DateTimeImmutable();

$rows = $this->createQueryBuilder('r')
    ->select("STRFTIME('%Y-%m', r.createdAt) as month, r.category, SUM(r.amount) as total")
    ->where('r.createdAt >= :from')->andWhere('r.createdAt <= :to')
    ->groupBy('month')->addGroupBy('r.category')
    ->setParameter('from', $from)->setParameter('to', $to)
    ->getQuery()->getResult();

// Aggregate in PHP:
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
```

**Controller update:**

- **File:** `src/Controller/DashboardController.php`  
  Replace usage of `getCategoryAverages()` with new `getCategoryMonthlyAverages()`. Compare this month's category total against the average monthly total, trigger warning if >2x.

#### Fix #5b: "Most Visited" Insight Label

**Current message:** `"Most visited this month: {business} (${total})"`

The query orders by total spend but labels it as "visited". This conflates spending with frequency.

**Fix:** Either fix the query or fix the label. Recommended: keep the dollar-based insight (it's valuable) and add a separate visit-count insight.

- Change message to reflect actual metric:
  ```php
  'message' => sprintf('Top spender this month: %s ($%.2f)', $topBusinesses[0]['business'], (float)$topBusinesses[0]['total']),
  ```

#### Fix #5c: Velocity Insight — Add Minimum Transaction Guard

**Current behavior:** Shows velocity projection even with 1 transaction on day 1.

**Fix:** Only show if `thisMonthCount >= 3` (configurable threshold) to avoid noise from tiny samples.

```php
if ($dayOfMonth > 2 && $thisMonthTotal > 0 && $thisMonthCount >= 3) {
    // ... velocity projection
}
```

#### New Insights to Add

Beyond fixing existing issues, the following insights are missing and would significantly increase dashboard value:

| Insight | Logic | Type | Priority |
|---------|-------|------|----------|
| Largest single transaction this month | Find receipt with max amount; display if > $X threshold or simply always show top. | info | Medium |
| Spending streak warning | If current day-of-month spend exceeds same point in previous N months average by significant margin, flag it. | warning | Low (complex) |
| Unspent days reminder | "You haven't logged any expenses today." if no receipts with createdAt date == now.date(). Only show after 12:00 PM local time to avoid false positives early morning. | info | Low |
| New category detected | If a category appears this month that was not present in last N months, flag it as new spending area. Could indicate lifestyle change or data entry drift. | info | Medium |

For v1.2 scope, I recommend implementing:
- **Largest single transaction** — straightforward and immediately useful.
- **New category detected** — helps user catch miscategorization early.

**Largest Single Transaction Implementation:**

Add repository method `getLargestReceipt()` for given range. Controller adds insight if amount exceeds reasonable threshold (e.g., > $100 or top receipt is more than 5x average transaction).

```php
// Repository:
public function getLargestReceipt(DateTimeInterface $from, DateTimeInterface $to): ?array {
    return $this->createQueryBuilder('r')
        ->select('r.id as id, r.business as business, r.amount as amount, DATE(r.createdAt) as date')
        ->where('r.createdAt >= :from')->andWhere('r.createdAt <= :to')
        ->orderBy('r.amount', 'DESC')
        ->setMaxResults(1)
        ->setParameter('from', $from)->setParameter('to', $to)
        ->getQuery()->getOneOrNullResult();
}

// Controller insight (if applicable):
$largest = $this->receiptRepository->getLargestReceipt($startOfMonth, $now);
if ($largest && (float)$largest['amount'] > 100) {
    $insights[] = [
        'type' => 'info',
        'message' => sprintf('Largest transaction this month: %s — $%.2f', $largest['business'], (float)$largest['amount']),
    ];
}
```

**New Category Detected Implementation:**

Compare this month's unique categories against last N months. Flag new ones.

```php
// Controller logic:
$thisMonthCats = array_column($this->receiptRepository->getSpendingByCategory($startOfMonth, $now), 'category');
$last3MonthsCats = [];
foreach ($this->receiptRepository->getSpendingByCategory(
    new \DateTimeImmutable('-3 months'),
    $endOfLastMonth
) as $row) {
    $last3MonthsCats[] = $row['category'];
}

$newCategories = array_diff($thisMonthCats, $last3MonthsCats);
if (!empty($newCategories)) {
    foreach ($newCategories as $cat) {
        $insights[] = [
            'type' => 'info',
            'message' => sprintf('New category this month: %s', $cat),
        ];
    }
}
```

---

## API Changes Summary

### Modified Endpoints

| Endpoint | Change | Backward Compatible? |
|----------|--------|---------------------|
| `GET /api/dashboard/spending-by-category` | New optional query param `comparison`. When true, returns `{ this_month: [...], last_month: [...] }`. Default behavior returns current-month-only list. | Yes — existing callers receive same flat array format. |
| `GET /api/dashboard/top-businesses` | New optional query param `limit` (5/10/15/25). Defaults to 5 if omitted or invalid. | Yes — default behavior unchanged. |

### No Changes Required

- Receipt CRUD endpoints (`POST/PUT/DELETE /api/receipts`)
- Autocomplete endpoints
- Insights endpoint schema (internal logic changes only)
- Auth endpoints

---

## Frontend Change Summary

| Page | Component | Change |
|------|-----------|--------|
| `/receipts/new` | Date/time input | Pre-filled with current local datetime on load and reset. |
| Dashboard | Category chart | Changed from single-series doughnut to grouped bar chart comparing this month vs last month by category. |
| Dashboard | Top Businesses section | Added dropdown (5/10/15/25), persisted in localStorage, re-fetches API on change. |
| Dashboard | Recent Transactions section | Added dropdown (10/25/50/100), persisted in localStorage, client-side slice of fetched data. |

**localStorage keys:**
- `pennyTrack_topBusinessesLimit` — integer string "5"|"10"|"15"|"25", default "5"
- `pennyTrack_recentTransactionsLimit` — integer string "10"|"25"|"50"|"100", default "10"

---

## File Change Index

| File | Action | Details |
|------|--------|---------|
| `templates/receipt/new.html.twig` | Modify | Add JS to pre-fill datetime-local with now. |
| `src/Controller/DashboardController.php` | Modify | Update spendingByCategory for comparison mode; update topBusinesses to accept limit param; fix insights logic (category averages, label, velocity guard); add new insights. |
| `src/Repository/ReceiptRepository.php` | Modify | Add getCategoryMonthlyAverages(); add getLargestReceipt(). |
| `templates/dashboard/index.html.twig` | Modify | Change category chart type and API call; add two dropdown selectors with localStorage hooks. |

---

## Test Plan

### Unit Tests (PHPUnit)

1. **ReceiptRepositoryTest**
   - `testGetCategoryMonthlyAverages()` — create known data across multiple months, assert correct average per category.
   - `testGetLargestReceipt()` — verify returns highest-amount receipt in range; null if no receipts.
   - Regression: ensure existing methods (`getSpendingByCategory`, `getTopBusinesses`, etc.) still pass.

2. **DashboardControllerTest** (functional)
   - `testSpendingByCategoryComparisonMode()` — assert JSON shape when `comparison=true`.
   - `testSpendingByCategoryBackwardCompatibility()` — assert flat array when param absent/false.
   - `testTopBusinessesLimitParameter()` — assert correct limit applied for each valid value; default to 5 on invalid.
   - `testInsightsCategoryAnomalyDetection()` — seed data where one category exceeds 2x monthly average; verify warning generated.
   - `testInsightsVelocityGuard()` — with <3 transactions, velocity insight should not appear.

### Functional / Integration Tests (Panther or API client)

1. **Receipt creation flow**
   - Load `/receipts/new`, assert datetime-local field has current date value.
   
2. **Dashboard rendering tests**
   - Verify category chart loads without errors using comparison endpoint.
   - Verify dropdowns render and respond to change events (can be manual or Cypress/Panther if added).

---

## Implementation Order (Recommended)

1. Fix category anomaly insight bug (highest severity)
2. Default datetime to "now" on new receipt form
3. Top Businesses limit parameter + UI
4. Recent Transactions limit dropdown
5. Spending by category comparison API + chart update
6. Remaining insight fixes and additions

---

## Out of Scope for v1.2

- Backend pagination or server-side limiting for recent transactions (we're doing client-side filtering).
- Custom date range selectors on charts (mentioned in PLANNING.md but not part of current request).
- Multi-user support, budgeting features, receipt image upload/OCR (all listed as future considerations).

---

*Document prepared by Lyra — Release v1.2 Planning Phase*
