# Penny-Track — Project Roadmap

## Project Overview

Penny-track is a personal expense tracker built with **Symfony 8.1**
(PHP ≥ 8.4), **Doctrine ORM** (SQLite by default), and **Twig** templates
with a Tailwind CSS frontend. It tracks receipts with amount, business,
category, location, tags, and notes, and provides a dashboard with
monthly spending summaries.

- **Location:** `projects/penny-track/`
- **Framework:** Symfony 8.1 (full-stack skeleton)
- **Language:** PHP ≥ 8.4
- **Database:** SQLite (`var/data.db`), PostgreSQL-capable
- **Frontend:** Tailwind CSS (CDN), Chart.js 4.4.1 (CDN)
- **Auth:** API key (bcrypt-hashed, stored in DB)

---

## Current State — Audit Summary

### What's Working

- ✅ Receipt CRUD API (list, get, create, update, delete)
- ✅ Dashboard API (monthly summary, spending-by-category)
- ✅ API key authentication (setup, verify, middleware)
- ✅ LLM-powered receipt parsing (`POST /api/receipts/parse`)
- ✅ Two HTML pages (dashboard + new receipt form)
- ✅ Basic test suite (functional + unit tests)
- ✅ Docker Compose setup for local development

### What's Missing or Needs Work

- ❌ No search/filter endpoint for receipts
- ❌ No migration files (schema managed manually or via `schema:create`)
- ❌ No published Docker image
- ❌ No published GitHub/Gitea repository
- ❌ Test coverage is thin — no tests for auth, parse, or several edge cases
- ❌ No input sanitisation / validation beyond basic Symfony constraints
- ❌ Frontend uses CDN-hosted Tailwind (no build step, offline-broken)
- ❌ No rate limiting on API endpoints
- ❌ No CSRF protection on forms (bundle installed but not wired)
- ❌ `compose.override.yaml` exposes debug/profiler in dev (expected, but
      needs ensuring it's not in production)

---

## Existing API Endpoints

### Authentication

| Method | Path               | Auth | Description                                      |
|--------|--------------------|------|--------------------------------------------------|
| POST   | `/api/auth/setup`  | No   | Generate initial API key (only works once)       |
| POST   | `/api/auth/verify` | No   | Verify an API key                                |

### Receipts

| Method | Path                    | Auth | Parameters                                              |
|--------|-------------------------|------|---------------------------------------------------------|
| GET    | `/api/receipts`         | Yes  | `page` (default 1), `limit` (default 10, max 100)      |
| GET    | `/api/receipts/{id}`    | Yes  | —                                                       |
| POST   | `/api/receipts`         | Yes  | `amount`, `business`, `category`, `location?`, `tags?`, `notes?`, `created_at?` |
| PUT    | `/api/receipts/{id}`    | Yes  | Same as POST (all optional, partial update)             |
| DELETE | `/api/receipts/{id}`    | Yes  | —                                                       |
| POST   | `/api/receipts/parse`   | Yes  | `text` (required) — LLM parses and creates receipt      |

### Dashboard

| Method | Path                                  | Auth | Parameters                        |
|--------|---------------------------------------|------|-----------------------------------|
| GET    | `/api/dashboard/summary`              | Yes  | —                                 |
| GET    | `/api/dashboard/spending-by-category` | Yes  | `months` (default 1, range 1–12)  |

### Receipt Data Model

| Field         | Type                 | Required | Notes                              |
|---------------|----------------------|----------|------------------------------------|
| `id`          | int (auto-increment) | auto     | Primary key                        |
| `amount`      | decimal              | yes      | Must be > 0                        |
| `business`    | string               | yes      | Merchant/business name             |
| `category`    | string               | yes      | e.g. "Software", "Groceries"       |
| `location`    | string (nullable)    | no       | Physical location                  |
| `tags`        | JSON array           | no       | Array of string tags               |
| `notes`       | text (nullable)      | no       | Free-form notes                    |
| `created_at`  | datetime             | no       | Defaults to now()                  |

### Configuration

| Variable           | Default                        | Purpose                       |
|--------------------|--------------------------------|-------------------------------|
| `DATABASE_URL`     | `sqlite:///%kernel.project_dir%/var/data.db` | Database connection |
| `LLM_API_URL`      | *(required for parse)*         | LLM endpoint for receipt parsing |
| `LLM_API_KEY`      | *(required for parse)*         | LLM API key                   |
| `APP_ENV`          | `dev`                          | Symfony environment           |
| `APP_SECRET`       | *(generated)*                  | Symfony secret                |

---

## Roadmap

### Phase 1 — Hardening & Test Suite

**Goal:** Make the codebase robust and well-tested before adding
features or publishing.

- [ ] **Add Doctrine migrations** — replace ad-hoc schema creation with
      proper versioned migration files. Run `doctrine:migrations:diff`
      to generate the initial migration from existing entities.
- [ ] **Comprehensive test coverage:**
  - [ ] `AuthController` tests — setup (first-time, already-set-up),
        verify (valid, invalid, missing key), middleware (unauthenticated
        access, wrong header format)
  - [ ] `ReceiptController` tests — create (validation: missing fields,
        negative amount, future date), update (partial, full, not-found),
        delete (not-found, already-deleted), list (pagination edge cases),
        get (not-found)
  - [ ] `DashboardController` tests — summary with empty DB, single month,
        month-over-month boundary, spending-by-category with varying
        `months` parameter
  - [ ] `parse` endpoint — mock LLM responses, test fallback when LLM
        fails, test malformed text
  - [ ] Edge cases — SQL injection attempts, oversized payloads,
        concurrent access
- [ ] **Input validation hardening:**
  - [ ] Add `StringLength` constraints on `business`, `category`,
        `location`, `notes`
  - [ ] Add `Range` constraint on `amount` (sane max, e.g. 1,000,000)
  - [ ] Validate `tags` array entries (non-empty strings, max length,
        max count)
  - [ ] Validate `created_at` (not in the future, not ancient)
- [ ] **Security hardening:**
  - [ ] Rate limiting on auth endpoints (prevent brute-force on API key)
  - [ ] Rate limiting on receipt creation
  - [ ] Ensure `APP_ENV=prod` disables debug profiler
  - [ ] Add CORS headers if the API will be consumed cross-origin
  - [ ] Add security headers (CSP, X-Content-Type-Options, etc.)
- [ ] **Error handling consistency:**
  - [ ] Standardise all error responses as `{"error": "message", "details": {...}}`
  - [ ] Ensure 404s don't leak entity names
  - [ ] Add a global exception handler for unexpected errors

### Phase 2 — Search & Filter API

**Goal:** Add the search endpoint needed by the MCP email integration
(and useful in general).

- [ ] `GET /api/receipts/search` with query parameters:
  - `q` (string) — general search across `business`, `notes`, and `tags`
  - `business` (string) — exact or LIKE match
  - `category` (string) — exact match
  - `tags` (comma-separated) — receipts matching any/all tags
  - `amount_min` / `amount_max` (float) — amount range filter
  - `date_from` / `date_to` (ISO 8601) — `created_at` date range
  - `sort` (string) — `date`, `amount`, `business` (default: `date`)
  - `order` (string) — `asc` / `desc` (default: `desc`)
  - `page` / `limit` — same pagination as list endpoint
- [ ] Repository method `searchByCriteria(array $criteria): QueryBuilder`
- [ ] Tests for every filter combination
- [ ] Document the search endpoint in the README

### Phase 3 — Docker Image & Publishing

**Goal:** Get penny-track containerised and published.

- [ ] **Production Dockerfile:**
  - Multi-stage build (composer install in build stage, copy to runtime)
  - PHP-FPM + Caddy/Nginx or PHP's built-in server for simplicity
  - Use `APP_ENV=prod` in the image
  - Run migrations on container startup
  - SQLite data stored in a volume
  - Health check endpoint
- [ ] **docker-compose.production.yml:**
  - Single service (app + SQLite)
  - Optional PostgreSQL service for larger deployments
  - Volume for `var/` (database + logs)
  - Environment variable configuration
- [ ] **Docker image publishing:**
  - Build and tag as `ghcr.io/<user>/penny-track:latest`
  - Version tags (`:v1.0.0`, `:1.0`, `:1`)
  - GitHub Actions workflow for automated builds on tag push
- [ ] **GitHub repository:**
  - Initialise git repo (if not already)
  - Push to GitHub/Gitea
  - Set up branch protection on `main`
  - GitHub Actions CI (run tests on push/PR)

### Phase 4 — Polish & v1.0 Release

**Goal:** Production-ready v1.0.

- [ ] **Frontend improvements:**
  - Self-host Tailwind (build step, no CDN dependency)
  - Add receipt list/edit view
  - Add search UI
  - Mobile-responsive audit
- [ ] **Documentation:**
  - Comprehensive README with setup, API reference, deployment guide
  - API documentation (OpenAPI/Swagger via `nelmio/api-doc-bundle` or
    hand-written)
  - Changelog
- [ ] **Release process:**
  - Tag `v1.0.0`
  - GitHub release with release notes
  - Docker image published
  - Announcement

---

## Architecture Notes

### Current Tech Stack

```
┌─────────────────────────────────────────┐
│  Browser (Tailwind CSS + Chart.js)      │
├─────────────────────────────────────────┤
│  Symfony 8.1 (PHP-FPM)                  │
│  ├── Controllers (Auth, Dashboard,      │
│  │   Receipt)                            │
│  ├── Doctrine ORM 3.x                   │
│  ├── ApiKey Authenticator (middleware)   │
│  └── LLM Client Service                  │
├─────────────────────────────────────────┤
│  SQLite (var/data.db)                    │
└─────────────────────────────────────────┘
```

### LLM Integration

The `POST /api/receipts/parse` endpoint sends raw text to an external LLM
(configured via `LLM_API_URL` and `LLM_API_KEY`) and expects a JSON
response with receipt fields. If the LLM fails or returns unparseable
data, it falls back to creating a receipt with `amount=0`,
`business="Unknown"`, `category="Other"`.

This is independent from the MCP server's LLM — penny-track has its own
LLM integration for receipt parsing. The MCP server's email workflow
would use *its own* LLM to parse emails, then call penny-track's
`POST /api/receipts` directly (not the parse endpoint).

### Security Model

- API key is generated once via `/api/auth/setup` (64-char hex).
- Stored as bcrypt hash in the `api_keys` table.
- Every API request (except setup/verify) must include
  `X-API-Key: <key>` header.
- The `ApiKeyAuthenticator` checks the header against all stored hashes.

**For MCP integration:** The MCP server stores the penny-track API key
in its own `.env` (`PENNY_TRACK_API_KEY`) and includes it in all proxied
requests. Lyra never sees the raw key.

---

## Relationship to MCP Server

| penny-track endpoint              | MCP server endpoint                         |
|-----------------------------------|---------------------------------------------|
| `GET /api/receipts`               | `GET /penny-track/receipts`                 |
| `GET /api/receipts/search` (new)  | `GET /penny-track/receipts/search`          |
| `GET /api/receipts/{id}`          | `GET /penny-track/receipts/{id}`            |
| `POST /api/receipts`              | `POST /penny-track/receipts`                |
| `PUT /api/receipts/{id}`          | `PUT /penny-track/receipts/{id}`            |
| `DELETE /api/receipts/{id}`       | `DELETE /penny-track/receipts/{id}`         |
| `GET /api/dashboard/summary`      | `GET /penny-track/dashboard/summary`        |
| `GET /api/dashboard/spending-by-category` | `GET /penny-track/dashboard/by-category` |

The MCP server acts as an authenticated proxy — it adds the penny-track
API key to requests so that Lyra (and any other MCP consumer) doesn't
need to manage credentials directly.

See `projects/mcp_server/plans/email-integration-roadmap.md` for the
email → penny-track expense logging workflow.

---

*Prepared by Lyra, your office-side assistant. ✨*
