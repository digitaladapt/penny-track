# Penny-Track — Design Considerations

## Summary

Penny-Track is a single-user expense/receipt tracker built on Symfony 8.1/PHP 8.4 with SQLite, featuring a REST API secured by API-key authentication, a Twig+Tailwind+Chart.js frontend, and LLM-powered natural language receipt entry. The codebase is well-structured for its scope — controllers, entities, repositories, and services are cleanly separated, and the Docker setup is production-ready with multi-stage builds and FrankenPHP worker mode. However, there are several areas where modern best practices could be adopted to improve security, maintainability, test reliability, and frontend performance, all proportional to the project's single-user scale.

---

## 1. Security

### 1.1 API Key Verification Iterates All Keys with bcrypt — O(n) per Request [HIGH IMPACT]

**Current state:** Both `ApiKeyAuthenticator::authenticate()` and `AuthController::verify()` call `$this->apiKeyRepository->findAll()` and then loop through every stored key, calling `password_verify()` on each. For a single-user app this is typically one key, but the pattern is inherently unscalable and, more importantly, bcrypt is intentionally slow (cost factor 10+ by default). If multiple keys ever exist, every API request pays a multi-bcrypt-verify cost.

**Suggested change:** Since this is a single-user app, store a single key (or add a lookup index). A better approach: store a non-secret key identifier (e.g., first 8 hex chars) alongside the hash, look up the candidate row by identifier, then `password_verify` exactly once. Alternatively, if only one key will ever exist, fetch just the first row instead of `findAll()`.

**Why:** Even with one key, `findAll()` loads all rows into memory unnecessarily. The pattern also signals to future maintainers that this is O(n), which could become a performance issue or a subtle DoS vector if the keys table grows. For a single-user app, the simplest fix is fetching one row.

---

### 1.2 No Rate Limiting on Auth Endpoints [HIGH IMPACT]

**Current state:** `/api/auth/verify` and `/api/auth/setup` have no rate limiting. An attacker could brute-force the API key via repeated calls to `/api/auth/verify`. While the key is 64 hex characters (256 bits of entropy), the absence of any throttle means unlimited attempts are allowed.

**Suggested change:** Configure Symfony's built-in `login_throttling` on the `api` firewall, or implement a simple rate limiter on `/api/auth/verify` using Symfony's RateLimiter component. Even a generous limit (e.g., 30 attempts/minute) would prevent automated brute-force.

**Why:** Defense in depth. Even with high-entropy keys, rate limiting is a standard security practice for authentication endpoints. The ROADMAP.md already identifies this gap — it should be prioritized.

---

### 1.3 `shell_exec` in SystemController for Version Detection [MEDIUM IMPACT]

**Current state:** `SystemController::about()` calls `shell_exec('git describe --tags --abbrev=0')` on every request to determine the application version. This spawns a subprocess on each call and depends on the `.git` directory being present (which it won't be in the Docker production image, since `.git` is in `.dockerignore`).

**Suggested change:** Write the version into a file or environment variable during the Docker build step (e.g., `ARG VERSION; RUN echo "$VERSION" > /app/VERSION`), or inject it via a composer-extra parameter. Read that at runtime instead of shelling out.

**Why:** `shell_exec` is a security-sensitive function that some hardened environments disable entirely (`disable_functions` in php.ini). It also means the `/api/about` endpoint will always return the fallback version `1.3.0` in Docker deployments, making it misleading. A build-time approach is more reliable and eliminates the subprocess overhead.

---

### 1.4 API Key Stored in localStorage — XSS-Exfiltrable [MEDIUM IMPACT]

**Current state:** The frontend stores the API key in `localStorage` (`pennytrack_api_key`) and sends it as `X-API-Key` on every request. Any XSS vulnerability would allow an attacker to steal the key.

**Suggested change:** This is an accepted trade-off for a single-user app without session-based auth, and the ROADMAP acknowledges it. If improvement is desired, consider a hybrid approach: exchange the API key for a short-lived session token (HttpOnly cookie) via `/api/auth/verify`, then use cookie-based session auth for browser requests while keeping the `X-API-Key` header for API-only clients.

**Why:** localStorage is the most XSS-vulnerable storage mechanism. For a single-user self-hosted app the risk is low, but if the app is ever exposed on a network or shared machine, an XSS vector (e.g., from LLM-parsed content rendered in the UI) could leak the key. Documenting this as an accepted risk is the minimum; a session-token exchange would be ideal.

---

### 1.5 No Security Headers Configured [MEDIUM IMPACT]

**Current state:** The Caddyfile in `docker/Caddyfile` only sets `encode zstd gzip` — there are no security headers (CSP, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, etc.). The external `Caddyfile` at the project root also lacks them.

**Suggested change:** Add security headers either in the Caddyfile or via a Symfony response listener. At minimum: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`. A CSP header would be valuable since the app loads Tailwind and Chart.js from CDNs.

**Why:** Security headers are cheap defense-in-depth measures that protect against clickjacking, MIME-sniffing, and information leakage. They are especially relevant here because the app loads external scripts from CDNs, which expands the attack surface.

---

## 2. Architecture & Code Quality

### 2.1 DashboardController `insights()` Method is Too Complex [HIGH IMPACT]

**Current state:** The `insights()` method in `DashboardController` is ~90 lines of dense business logic — computing month-over-month changes, category anomalies, spending projections, new category detection, and more. The projection logic involves multiple nested loops, ad-hoc statistical calculations, and inline comments explaining the math. The method also contains commented-out code (the simpler velocity projection).

**Suggested change:** Extract this logic into a dedicated `InsightService` (or `DashboardAggregator`) service. The controller should simply call `$this->insightService->generate($startOfMonth, $now)` and return the result. The service can be unit-tested independently.

**Why:** This is the single most complex piece of business logic in the codebase, and it lives in a controller — making it impossible to unit test without a full HTTP kernel. The complexity also makes it fragile: the projection math has multiple edge cases (empty months, new categories, division by zero) that are hard to verify through functional tests alone. The ROADMAP and RELEASE-v1.2-PLAN both reference bugs in this logic, which confirms the difficulty of maintaining it in its current location.

---

### 2.2 Manual JSON Serialization Instead of Symfony Serializer [MEDIUM IMPACT]

**Current state:** `ReceiptController::serializeReceipt()` manually maps entity fields to an array. The `hydrateReceipt()` method manually extracts fields from the decoded JSON request body. Both are hand-rolled with no type safety or consistency guarantees.

**Suggested change:** Use Symfony's Serializer component (already installed via `symfony/serializer`) with normalization/denormalization groups. Define a `ReceiptDTO` for input, and let the serializer handle the mapping. This would also give consistent field naming (snake_case vs camelCase) via a name converter.

**Why:** The manual approach is error-prone — if a field is added to the entity, both the serializer and hydrator must be updated in tandem, and it's easy to forget one. The serializer component is already a dependency; not using it means paying the weight without getting the benefit. A DTO would also centralize validation rules instead of relying on entity-level assertions mixed with manual `array_key_exists` checks.

---

### 2.3 No Input Validation on JSON Request Bodies [MEDIUM IMPACT]

**Current state:** `ReceiptController::create()` and `update()` call `json_decode($request->getContent(), true)` and pass the result directly to `hydrateReceipt()` with no checks for:
- Whether the JSON decoded successfully (`json_decode` returns `null` on failure)
- Whether `$data` is actually an array (could be `null`, causing a type error)
- Whether unexpected fields are present
- Whether `tags` array entries are strings

The `parse()` endpoint has the same issue: `$data['text']` is accessed without confirming `$data` is an array.

**Suggested change:** Add a null-check after `json_decode` and return a 400 error if the body is invalid JSON. Better yet, use a Symfony Form or the Serializer with a DTO to handle input validation declaratively.

**Why:** Currently, sending `null`, an empty body, or malformed JSON to any POST/PUT endpoint would produce an uncaught `TypeError` or a 500 error instead of a clean 400 response. This is both a UX issue (confusing error messages) and a potential information leak (stack traces in dev mode).

---

### 2.4 Leftover Boilerplate and Scaffolding [LOW IMPACT]

**Current state:**
- `assets/controllers/hello_controller.js` — Symfony Maker boilerplate, not used anywhere
- `assets/app.js` — contains `console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉')`
- `assets/styles/app.css` — contains `body { background-color: skyblue; }` which is overridden by Tailwind's `bg-gray-50` on `<body>`
- `config/packages/notifier.yaml` — configured with `admin@example.com` but the Notifier bundle isn't used for anything
- `config/packages/messenger.yaml` — configured with async transports, but no custom messages exist; only Symfony's built-in mailer/notifier messages are routed
- The `.env.dev` file contains a hardcoded `APP_SECRET` — while acceptable for dev, it should be in `.env.local` (gitignored) instead

**Suggested change:** Remove unused boilerplate. Delete `hello_controller.js`, clean up `app.js` and `app.css`, remove or comment out notifier/messenger config if not used. Move `.env.dev` secrets to `.env.local`.

**Why:** Dead code and boilerplate create confusion for new contributors and add noise to the codebase. The `app.css` skyblue background could even flash before Tailwind loads (FOUC), though it's currently overridden. The notifier/messenger configurations imply functionality that doesn't exist.

---

### 2.5 `DashboardControllerTest_v1.2.php` Tests Non-Existent Functionality [HIGH IMPACT]

**Current state:** The test file `tests/Functional/Controller/DashboardControllerTest_v1.2.php` contains tests for a `comparison=true` parameter on the spending-by-category endpoint, but this parameter is not implemented in `DashboardController::spendingByCategory()`. The controller only supports `from`/`to` and `months` parameters. Additionally, the test class name is `DashboardControllerTest_v1_2` (underscores), which doesn't match the filename convention, and it extends `WebTestCase` but the tests would fail if run.

Similarly, the `testInsightsVelocityNotShownWithFewTransactions` test asserts that velocity insights don't appear with <3 transactions, but the controller has no such guard — the commented-out velocity code was replaced with a different projection algorithm that always runs.

**Suggested change:** Either implement the `comparison` parameter as described in `RELEASE-v1.2-PLAN.md`, or remove the test file if the feature was descoped. If the tests are meant to be TDD specs for upcoming work, they should be marked as skipped or placed in a separate branch.

**Why:** Having tests that test non-existent behavior is worse than having no tests — it gives a false sense of coverage and will fail confusingly when someone runs the full suite. This also indicates a disconnect between the planning documents and the actual implementation state.

---

## 3. Testing

### 3.1 No Tests for AuthController, LlmClient, or Parse Endpoint [HIGH IMPACT]

**Current state:** The test suite covers:
- Receipt entity validation and lifecycle callbacks ✓
- ReceiptRepository custom queries ✓
- ReceiptController CRUD and autocomplete ✓
- DashboardController summary, category, insights ✓

But there are **no tests** for:
- `AuthController` — setup (first-time, already-configured), verify (valid, invalid, missing key)
- `LlmClient` — response parsing, JSON extraction from markdown, error handling
- `ReceiptController::parse()` — the LLM-powered parsing flow, fallback behavior
- `SystemController` — about and health endpoints

**Suggested change:** Add unit tests for `LlmClient` with a mocked `HttpClientInterface`. Add functional tests for `AuthController` covering setup-already-done (409), verify with valid/invalid keys, and the parse endpoint with a mocked LLM client (returning a known response). The parse endpoint test should also cover the fallback path when the LLM throws an exception.

**Why:** The LLM integration is the app's signature feature, and it has zero test coverage. The fallback behavior (creating a receipt with amount=0 on LLM failure) is critical to verify. The auth flow is the security boundary — untested auth is a significant risk. The ROADMAP explicitly identifies these gaps.

---

### 3.2 Test Setup Duplicates Schema Creation Logic [LOW IMPACT]

**Current state:** Both `DashboardControllerTest` and `ReceiptControllerTest` have identical `setUp()` methods that create a client, get the entity manager, drop/create schema, and persist an API key. The `DashboardControllerTest_v1_2` class has a slightly different version.

**Suggested change:** Extract a shared `ApiTestCase` base class or trait that handles schema setup and API key creation. Each test class extends it and gets a `$this->client` and `$this->apiKey` for free.

**Why:** Reduces boilerplate and ensures consistency. If the schema setup logic needs to change (e.g., adding fixtures), it only needs to change in one place. The current duplication also means the v1.2 test class has subtly different setup logic (e.g., `if (!empty($metadata))` guard), creating inconsistency.

---

### 3.3 No Migration Files [MEDIUM IMPACT]

**Current state:** The `migrations/` directory contains only a `.gitignore` file. The database schema is created via `doctrine:schema:create` (in tests) or `doctrine:migrations:migrate` (in Docker entrypoint — but with `--allow-no-migration`, meaning it does nothing). The ROADMAP identifies this as a gap.

**Suggested change:** Run `doctrine:migrations:diff` to generate the initial migration from the existing entities. Commit it. This is especially important because the Docker `entrypoint.sh` runs `doctrine:migrations:migrate` — without any migration files, a fresh Docker deployment would start with no database schema.

**Why:** The Docker entrypoint expects migrations to exist, but none do. A fresh deployment would fail silently (the `--allow-no-migration` flag suppresses the error) and the app would 500 on any database query. This is a deployment-blocking issue. The test suite works around it by using `SchemaTool::createSchema()` directly, which masks the problem.

---

## 4. Frontend & Assets

### 4.1 CDN-Hosted Tailwind and Chart.js — No Offline Capability [MEDIUM IMPACT]

**Current state:** `base.html.twig` loads Tailwind CSS via `https://cdn.tailwindcss.com` and Chart.js via `https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js`. These are runtime CDN includes with no local fallback. The ROADMAP identifies this as a gap.

**Suggested change:** For Tailwind: use the Tailwind CLI or PostCSS to build a compiled CSS file, served via AssetMapper. For Chart.js: install it as an npm dependency via `importmap:require chart.js` and import it in `app.js`. Both would be served locally, eliminating the CDN dependency.

**Why:** CDN dependencies mean the app is broken offline, vulnerable to CDN outages, and introduces a supply-chain risk (if the CDN is compromised, the app is compromised). For a self-hosted single-user app, local assets are the expectation. The AssetMapper infrastructure is already set up and could handle both. Additionally, the CDN Tailwind includes the entire framework unminified (~3MB), whereas a built version would tree-shake unused utilities.

---

### 4.2 Large Inline JavaScript in Templates [MEDIUM IMPACT]

**Current state:** `dashboard/index.html.twig` contains ~250 lines of inline JavaScript, including all chart rendering, API calls, and event handling. `receipt/new.html.twig` has ~100 lines. The `base.html.twig` has the `PennyTrack` API helper object inline as well.

**Suggested change:** Extract the JavaScript into separate files in `assets/` (e.g., `assets/dashboard.js`, `assets/receipt-form.js`), import them via the importmap, and use Stimulus controllers for event binding. The `PennyTrack` helper could be a small utility module imported where needed.

**Why:** Inline JavaScript is harder to maintain, test, and lint. It also prevents browser caching (the JS is re-downloaded on every page load as part of the HTML). For a project that already has Stimulus and AssetMapper set up, moving to proper JS modules would be a natural improvement. The current approach also means Twig template syntax is mixed with JavaScript, which can cause subtle bugs (e.g., if a route path contains a quote character).

---

### 4.3 XSS Risk in Dashboard Transaction Rendering [MEDIUM IMPACT]

**Current state:** The dashboard template renders receipt data using template literals with `${r.business}` and `${r.category}` directly into HTML, without escaping:
```javascript
tbody.innerHTML = receipts.data.map(r => `
    <tr class="hover:bg-gray-50">
        <td class="px-4 py-3 font-medium text-gray-900">${r.business}</td>
        ...
    </tr>
`).join('');
```

Similarly, insights messages are rendered with `${i.message}`.

**Suggested change:** HTML-escape all user-controlled data before inserting it into `innerHTML`. Create a small `escapeHtml()` utility function and use it for all interpolated values. Alternatively, use DOM APIs (`textContent`) instead of `innerHTML`.

**Why:** Since the LLM parse endpoint stores `business` and `category` from LLM output (which is derived from user input), and the manual entry form accepts arbitrary strings, an attacker (or even a quirky LLM response) could inject `<script>` tags. For example, if the LLM parses a receipt with business name `<img src=x onerror=alert(1)>`, it would execute in the browser. This is a real XSS vulnerability that could lead to API key theft (since the key is in localStorage).

---

## 5. Docker & Deployment

### 5.1 Health Check Endpoint Doesn't Verify Database Connectivity [LOW IMPACT]

**Current state:** The Docker `HEALTHCHECK` calls `curl -sf http://localhost:80/` which hits the dashboard page. The `/api/health` endpoint returns `{"status": "healthy"}` unconditionally. Neither checks whether the database is accessible or migrations have run.

**Suggested change:** Make `/api/health` perform a lightweight database check (e.g., `SELECT 1`) and return 503 if it fails. Update the Docker healthcheck to call `/api/health` instead of `/`.

**Why:** A container can be "up" (FrankenPHP running) but "broken" (database file missing, migrations not run, SQLite locked). A health check that only verifies the HTTP server is responding provides a false sense of healthiness.

---

### 5.2 Docker Volume Mount in compose.yaml Uses Relative Path [LOW IMPACT]

**Current state:** `compose.yaml` mounts `./var:/app/var`. This binds the host's `var/` directory (which contains cache, logs, and the SQLite database) into the container. Since `var/` is in `.gitignore`, this is fine for the database, but it means the container's cache (which is built for the container's PHP version and FrankenPHP environment) is shared with the host.

**Suggested change:** Use a named Docker volume for the database file specifically, or mount only a `data/` subdirectory. Keep `var/cache` container-internal.

**Why:** Sharing the entire `var/` directory between host and container can cause cache corruption if the host and container have different PHP versions or extensions. It also means container cache files pollute the host's `var/` directory. A named volume for just the database would be cleaner.

---

### 5.3 APP_SECRET Empty in Default .env [MEDIUM IMPACT]

**Current state:** The `.env` file has `APP_SECRET=` (empty). The `compose.yaml` passes `APP_SECRET: ${APP_SECRET:-}` (also empty by default). If the operator doesn't set `APP_SECRET`, Symfony will generate a random one at runtime, but in a containerized environment with FrankenPHP worker mode, this could lead to inconsistent secrets across worker processes or container restarts.

**Suggested change:** Generate a default `APP_SECRET` in the `.env` file (as `.env.dev` already does), or require it as an environment variable in `compose.yaml` with a clear error message if not set.

**Why:** The `APP_SECRET` is used for CSRF tokens, session tokens, and signed URLs. An empty or randomly-regenerated secret means CSRF tokens and sessions invalidate on every container restart. For a stateless API-key app this is less critical (no sessions), but the CSRF infrastructure is configured and would be affected.

---

## 6. API Design

### 6.1 Inconsistent Error Response Formats [LOW IMPACT]

**Current state:** Error responses use different formats:
- 404: `{"error": "Not found"}`
- 422: `{"errors": {"field": "message"}}`
- 401: `{"error": "Invalid API key"}`
- 409: `{"error": "Already configured"}`
- 422 (parse): `{"error": "Text is required"}`

Some use `error` (singular string), some use `errors` (object of field messages). There's no consistent envelope.

**Suggested change:** Standardize on a format like `{"error": {"message": "...", "details": {...}}}` or keep `{"error": "message"}` for simple cases and `{"errors": {...}}` for validation, but document the convention. Consider a custom `ApiExceptionListener` that catches all exceptions and formats them consistently.

**Why:** Consistent error responses make the API easier to consume programmatically (e.g., from the MCP server proxy). The current inconsistency means consumers must handle multiple formats. A global exception handler would also prevent uncaught exceptions from leaking stack traces.

---

### 6.2 Autocomplete Endpoints Have No Query/Filter Parameter [LOW IMPACT]

**Current state:** `/api/autocomplete/businesses`, `/api/autocomplete/categories`, and `/api/autocomplete/locations` return all unique values with no filtering. As the dataset grows, this could return hundreds of entries.

**Suggested change:** Add an optional `q` query parameter that filters results with a `LIKE` clause (e.g., `WHERE business LIKE :query%`). Limit results to 50 by default.

**Why:** For a single-user app with hundreds of receipts, the autocomplete lists will grow. Loading all unique values on every page load of the receipt form is wasteful. A server-side filter would reduce payload size and improve UX.

---

## 7. LLM Integration

### 7.1 No Retry Logic or Timeout Handling in LlmClient [MEDIUM IMPACT]

**Current state:** `LlmClient::chat()` makes a single HTTP request to the LLM endpoint with a configurable timeout. If the request fails (timeout, 5xx, network error), it throws immediately. The `ReceiptController::parse()` method catches this and falls back to creating a receipt with amount=0.

**Suggested change:** Add a simple retry with exponential backoff (1-2 retries) for transient failures (5xx, timeout). The Symfony HTTP client already supports this via `retry_failed` configuration. Alternatively, configure it at the scoped client level in `services.yaml`.

**Why:** LLM APIs are notoriously flaky — rate limits, temporary outages, and slow responses are common. A single transient failure currently results in a receipt being created with amount=0 and "Unknown" business, which the user must manually fix. A retry would reduce the frequency of fallback creations.

---

### 7.2 LLM Prompt Has Hardcoded Category List [LOW IMPACT]

**Current state:** The system prompt in `ReceiptController::buildSystemPrompt()` hardcodes the category list: `Food, Transport, Utilities, Entertainment, Shopping, Health, Other`. The manual entry form uses a free-text input with autocomplete, so users can create arbitrary categories. This means LLM-parsed receipts will always use one of the 7 hardcoded categories, while manually entered receipts might use different ones (e.g., "Groceries", "Software").

**Suggested change:** Dynamically include the user's existing categories in the prompt: `Categories you've used before: Food, Transport, Groceries, Software. Choose the best fit; default to "Other" if uncertain.` This would make the LLM more likely to reuse existing categories.

**Why:** Category inconsistency between LLM-parsed and manually-entered receipts makes the dashboard's "spending by category" charts less useful. If the LLM always says "Food" but the user manually enters "Groceries", those expenses are split across two categories. Feeding existing categories to the LLM is a cheap prompt improvement that increases data consistency.

---

### 7.3 LLM Response Not Validated Before Use [MEDIUM IMPACT]

**Current state:** After parsing the LLM response JSON, `ReceiptController::parse()` uses `$parsed['amount']`, `$parsed['business']`, etc. with minimal type checking (`is_numeric` for amount, null coalescing for others). The LLM could return unexpected types (e.g., `amount` as a string like "forty-five dollars", `tags` as a comma-separated string instead of an array).

**Suggested change:** Validate the parsed LLM output against a schema before constructing the Receipt entity. Use the Symfony Validator on the parsed data (via a DTO), or at minimum add type checks: `is_string($parsed['business'])`, `is_array($parsed['tags'])`, etc.

**Why:** LLMs are non-deterministic. Even with `temperature: 0.1`, responses can vary. If the LLM returns `tags: "lunch, work"` (string) instead of `tags: ["lunch", "work"]` (array), the current code would set tags to a string, which Doctrine would store in the JSON column but would break the frontend's `r.tags.map()` call. Defensive validation prevents silent data corruption.

---

## 8. Configuration & Dependencies

### 8.1 Unused Symfony Bundles and Components [LOW IMPACT]

**Current state:** Several bundles/components are installed but unused:
- `symfony/mailer` + `symfony/mime` — `MAILER_DSN=null://null`, no emails sent
- `symfony/notifier` — no notifications configured or sent
- `symfony/messenger` — no custom messages, only default mailer/notifier routing (which is unused)
- `symfony/form` — no Symfony Forms used (frontend uses manual HTML forms)
- `symfony/intl` — no internationalization
- `twig/extra-bundle` — no Twig extra extensions used (no markdown, intl, html, etc.)

**Suggested change:** Remove unused dependencies from `composer.json` if they're truly not needed. If some are kept for future use, document why.

**Why:** Each unused dependency adds to the `composer install` time, Docker image size, and attack surface. For a self-hosted single-user app, a leaner dependency set means faster builds and smaller images. Some of these (mailer, notifier, messenger) make sense if the ROADMAP plans to use them, but the current state is pure overhead.

---

### 8.2 README is Outdated [LOW IMPACT]

**Current state:** The README says "PHP 8.3+ / Symfony 7" but the project uses PHP 8.4 and Symfony 8.1. The `PLANNING.md` also references "Symfony 7.x" and "PHP 8.3". The quick start instructions mention `symfony server:start` but the project is now Docker-first with FrankenPHP. The README doesn't mention Docker at all.

**Suggested change:** Update the README to reflect the current tech stack (PHP 8.4, Symfony 8.1), the Docker deployment workflow, and the actual environment variables (the README says `LLM_API_URL` but the code uses `LLM_API_ENDPOINT`).

**Why:** Outdated documentation is misleading for anyone setting up the project. The `LLM_API_URL` vs `LLM_API_ENDPOINT` discrepancy could cause a setup failure. The ROADMAP also has this inconsistency in its configuration table.

---

## 9. Documentation

### 9.1 Planning Documents Have Diverged from Implementation [MEDIUM IMPACT]

**Current state:** Three major planning documents exist:
- `PLANNING.md` — original MVP plan, references Symfony 7, PHP 8.3, Git-Flow branching, Symfony Forms, Doctrine fixtures, none of which match reality
- `ROADMAP.md` — audit summary that's partially outdated (lists Docker as "missing" but it exists, lists migrations as "missing" which is accurate, references `LLM_API_URL` instead of `LLM_API_ENDPOINT`)
- `RELEASE-v1.2-PLAN.md` — detailed plan for v1.2 features, some of which were implemented (top businesses limit, insights fixes) and some which weren't (comparison mode for spending-by-category)

**Suggested change:** Either update or archive these documents. The ROADMAP's "Current State" section should be refreshed to reflect what's actually been done. The RELEASE-v1.2-PLAN should be marked as partially complete or superseded. Consider a single `CHANGELOG.md` for tracking what's actually shipped.

**Why:** When documentation doesn't match reality, it becomes a liability rather than an asset. A new contributor reading PLANNING.md would have an incorrect mental model of the project. The RELEASE-v1.2-PLAN is especially confusing because it describes features that appear to have been partially implemented (some insights fixes are present) but not fully (the comparison mode isn't).

---

## 10. What's Done Well

- **Docker setup is solid** — multi-stage build, FrankenPHP worker mode, OPcache preloading, proper `.dockerignore`, healthcheck, volume for persistent data
- **Security model is appropriate for scope** — bcrypt-hashed API keys, stateless firewall, proper access control rules
- **Entity design is clean** — lifecycle callbacks for timestamps, proper validation constraints, sensible column types
- **Repository methods are well-documented** — PHPDoc return types on every method, clear naming
- **Test structure follows Symfony conventions** — proper unit/functional split, schema isolation per test, API key setup in test fixtures
- **CI pipeline is configured** — Gitea Actions workflow with PHP 8.4, composer caching, strict validation
- **Environment configuration is clean** — separate `.env`, `.env.test`, `.env.dev` files, env var usage in services.yaml with proper type casting
- **The LLM fallback strategy is thoughtful** — creating a receipt with amount=0 for manual review when parsing fails is a pragmatic UX decision
