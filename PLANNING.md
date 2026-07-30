# Penny-Track: Budgeting & Receipt Tracking System

## Project Overview

Penny-Track is a fast, mobile-responsive web application for tracking personal expenses and receipts. The MVP targets a single user with simple API-key authentication, focusing on rapid data entry and insightful dashboards.

---

## Technology Stack

| Layer | Technology | Rationale |
|-------|-----------|-----------|
| **Backend Framework** | PHP 8.3 + Symfony 7.x | Mature, well-documented, excellent ORM (Doctrine), built-in testing tools, strong typing with PHP 8+ |
| **Database** | SQLite (via Doctrine DBAL/ORM) | Zero-config for single-user MVP; Doctrine abstracts migration to PostgreSQL/MySQL later |
| **Frontend** | Twig + Vanilla JS + Tailwind CSS | Lightweight, no build step complexity for MVP, mobile-first responsive design |
| **Testing** | PHPUnit + Symfony Panther (optional) | Unit tests for services/entities, functional tests for controllers/API |
| **LLM Integration** | OpenAI-compatible API (configurable endpoint) | Natural language expense parsing; supports OpenAI, Ollama, or any compatible provider |
| **Version Control** | Git with Git-Flow branching model | `main`, `develop`, `feature/*`, `release/*`, `hotfix/*` |

> **Why Symfony over alternatives?** Symfony provides a robust, enterprise-grade foundation with excellent Doctrine integration, built-in security components, and a mature testing ecosystem. For a single-developer MVP, it offers structure without rigidity, and the learning curve pays dividends as the project grows.

---

## Architecture Overview

```
penny-track/
├── config/                 # Symfony configuration
├── migrations/             # Doctrine migrations
├── public/                 # Web root
├── src/
│   ├── Controller/         # HTTP request handlers
│   ├── Entity/             # Doctrine entities
│   ├── Repository/         # Custom repositories
│   ├── Service/            # Business logic
│   │   ├── LLM/            # LLM integration services
│   │   ├── Receipt/        # Receipt processing
│   │   └── Dashboard/      # Dashboard data aggregation
│   ├── Form/               # Symfony forms
│   ├── Security/           # API key authenticator
│   └── DTO/                # Data Transfer Objects
├── templates/              # Twig templates
├── tests/
│   ├── Unit/               # Unit tests
│   └── Functional/         # Functional tests
├── var/                    # Cache, logs, SQLite DB
└── .env                    # Environment configuration
```

---

## Database Schema (SQLite via Doctrine)

### Entity: `Receipt`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| `id` | INTEGER | PK, Auto-increment | |
| `amount` | DECIMAL(10,2) | NOT NULL | Stored as cents integer internally, displayed as dollars |
| `location` | VARCHAR(255) | NULL | Physical location (city, address) |
| `business` | VARCHAR(255) | NOT NULL | Store/restaurant/merchant name |
| `category` | VARCHAR(100) | NOT NULL | e.g., "Food", "Transport", "Utilities" |
| `tags` | JSON | NULL | Array of additional tags |
| `notes` | TEXT | NULL | User notes |
| `raw_input` | TEXT | NULL | Original natural language string (for LLM entries) |
| `created_at` | DATETIME | NOT NULL | Auto-set on creation |
| `updated_at` | DATETIME | NOT NULL | Auto-set on update |

### Entity: `ApiKey` (Single User)

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| `id` | INTEGER | PK | |
| `key_hash` | VARCHAR(255) | NOT NULL | bcrypt hash of the API key |
| `created_at` | DATETIME | NOT NULL | |

> **Note:** For the MVP, the API key is generated once during setup and stored in the browser's `localStorage`. All API requests include it as an `X-API-Key` header.

---

## Feature Specifications

### 1. Authentication & Security (MVP: Single User)

- **API Key Flow:**
  1. On first visit, if no API key is configured, prompt user to generate one
  2. Store key in browser `localStorage`
  3. Include `X-API-Key` header in all AJAX/fetch requests
  4. Symfony custom authenticator validates key against `ApiKey` entity
  5. If invalid/missing, return 401; frontend redirects to login/setup page

- **Security Considerations:**
  - API key is hashed with bcrypt in database
  - HTTPS required in production (handled by reverse proxy)
  - No session management needed (stateless)
  - CORS configured for same-origin only

### 2. Receipt Entry — Manual Form

**UI:** Clean, mobile-first form accessible from prominent "+" button on dashboard.

**Fields:**
- Amount (number input, step 0.01, required)
- Business (text input, required, autocomplete from history)
- Category (select dropdown + "Add new" option, required)
- Location (text input, optional, autocomplete from history)
- Tags (multi-select or comma-separated input, optional)
- Notes (textarea, optional)
- Date (datetime-local, defaults to now, required)

**Behavior:**
- Client-side validation for required fields
- Server-side validation via Symfony Form + Validator
- On success: flash message, clear form, option to add another
- Autocomplete suggestions powered by existing receipt data

### 3. Receipt Entry — Natural Language (LLM)

**UI:** Single prominent text input (like a chat box) with placeholder: *"I spent $45.50 on lunch at Chipotle in downtown today"*

**Flow:**
1. User enters natural language description
2. Frontend sends text to `/api/receipts/parse` endpoint
3. Backend constructs LLM prompt with system instructions
4. LLM returns structured JSON
5. Backend validates parsed data, creates `Receipt` entity
6. Returns created receipt (or validation errors) to frontend
7. Frontend shows confirmation with parsed details; user can edit if needed

**LLM Prompt Engineering:**

```
System: You are a receipt parsing assistant. Extract expense details from the user's 
message and return ONLY a JSON object with these fields:
- amount: number (required, in dollars)
- business: string (required, merchant name)
- category: string (required, one of: Food, Transport, Utilities, Entertainment, Shopping, Health, Other)
- location: string or null
- tags: array of strings or empty array
- notes: string or null (include the original message here)
- date: ISO 8601 datetime or null (infer from relative terms like "today", "yesterday", "last Tuesday")

Rules:
- If a field cannot be determined, use null (except amount, business, category which are required)
- For category, choose the best fit; default to "Other" if uncertain
- Normalize business names (capitalize properly)
- Today is: {current_date}

Respond with ONLY the JSON object, no markdown, no explanation.
```

**LLM Configuration:**
- Endpoint: configurable via `.env` (`LLM_API_ENDPOINT`)
- Model: configurable via `.env` (`LLM_MODEL`)
- API Key: configurable via `.env` (`LLM_API_KEY`)
- Timeout: 10 seconds
- Fallback: If LLM fails or returns invalid JSON, store the raw text as a receipt with amount=0 and flag for manual review

**Supported LLM Providers:**
- OpenAI (`https://api.openai.com/v1/chat/completions`)
- Ollama (`http://localhost:11434/api/chat`)
- Any OpenAI-compatible API (LM Studio, vLLM, etc.)

### 4. Dashboard

**Layout:** Responsive grid, mobile-first. Key sections:

#### 4.1 Summary Cards (Top Row)
- Total spent this month
- Total spent last month (with % change indicator)
- Number of transactions this month
- Average transaction amount

#### 4.2 Spending Breakdown
- **Pie/Donut Chart:** By category (this month vs. last month toggle)
- **Bar Chart:** Spending by day (last 30 days)
- **Horizontal Bar Chart:** Top businesses by spend

#### 4.3 Recent Transactions
- Paginated table (5-10 items) with quick-edit/delete
- Mobile: card-based list view

#### 4.4 Insights & Anomalies
- "Unusually high spending in {category}" (compares to 3-month average)
- "You spent X% more than last month"
- "Most visited business this month: {business}"
- Spending velocity: "On track to spend $X this month"

#### 4.5 Time-Based Comparisons
- Month-over-month spending trend line chart
- Category trend comparison (select 2 categories)
- Custom date range selector for all charts

**Charting Library:** [Chart.js](https://www.chartjs.org/) — lightweight, responsive, no dependencies.

### 5. Data Management

- **Edit Receipt:** Inline or modal form, pre-populated
- **Delete Receipt:** Confirmation dialog, soft delete optional for MVP (hard delete acceptable)
- **Search/Filter:** Full-text search across business, category, notes; filter by date range, category, amount range
- **Export:** JSON/CSV export (stretch goal, not MVP)

---

## API Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| POST | `/api/auth/setup` | Generate initial API key | No (only if no key exists) |
| POST | `/api/auth/verify` | Verify API key | No |
| GET | `/api/dashboard/summary` | Summary statistics | API Key |
| GET | `/api/dashboard/spending-by-category` | Category breakdown | API Key |
| GET | `/api/dashboard/spending-over-time` | Time-series data | API Key |
| GET | `/api/dashboard/insights` | Anomaly/insight data | API Key |
| POST | `/api/receipts/parse` | Parse natural language | API Key |
| GET | `/api/receipts` | List receipts (paginated) | API Key |
| POST | `/api/receipts` | Create receipt (manual) | API Key |
| GET | `/api/receipts/{id}` | Get single receipt | API Key |
| PUT | `/api/receipts/{id}` | Update receipt | API Key |
| DELETE | `/api/receipts/{id}` | Delete receipt | API Key |
| GET | `/api/autocomplete/businesses` | Autocomplete businesses | API Key |
| GET | `/api/autocomplete/categories` | Autocomplete categories | API Key |
| GET | `/api/autocomplete/locations` | Autocomplete locations | API Key |

**Frontend Routes (Twig-rendered pages):**
| Method | Route | Description |
|--------|-------|-------------|
| GET | `/` | Dashboard (redirects to setup if no API key) |
| GET | `/setup` | Initial API key generation |
| GET | `/login` | API key entry (if localStorage cleared) |
| GET | `/receipts/new` | Manual entry form |
| GET | `/receipts/{id}/edit` | Edit receipt |

---

## Implementation Milestones (Git-Flow)

### Milestone 1: Project Bootstrap
**Branch:** `feature/project-bootstrap` → `develop`
- Initialize Symfony project (`symfony new --webapp`)
- Configure SQLite + Doctrine
- Set up Tailwind CSS (CDN for MVP simplicity, or CLI build)
- Configure PHPUnit
- Create base layout template (responsive navbar, mobile menu)
- Commit to `develop`

### Milestone 2: Authentication & API Key System
**Branch:** `feature/api-key-auth` → `develop`
- Create `ApiKey` entity
- Implement custom API key authenticator
- Create setup page (generate first API key)
- Create login page (enter existing key)
- Store/retrieve key from `localStorage`
- Add API key middleware to all routes
- Functional tests for auth flow

### Milestone 3: Receipt Entity & Manual Entry
**Branch:** `feature/receipt-manual-entry` → `develop`
- Create `Receipt` entity with full schema
- Create manual entry form (Symfony Form)
- Implement CRUD API endpoints
- Create receipt list view (recent transactions)
- Add autocomplete endpoints
- Unit tests for Receipt entity, Repository
- Functional tests for CRUD endpoints

### Milestone 4: Natural Language Entry (LLM)
**Branch:** `feature/natural-language-entry` → `develop`
- Create LLM service with OpenAI-compatible client
- Implement `/api/receipts/parse` endpoint
- Build natural language input UI
- Add prompt engineering and JSON parsing
- Handle LLM errors gracefully
- Unit tests for LLM service (with mocked client)
- Functional tests for parse endpoint

### Milestone 5: Dashboard & Data Visualization
**Branch:** `feature/dashboard` → `develop`
- Implement dashboard data aggregation services
- Create Chart.js visualizations
- Build summary cards, spending breakdown, trends
- Implement insights/anomaly detection service
- Make all charts responsive
- Functional tests for dashboard endpoints

### Milestone 6: Polish, Testing & Release
**Branch:** `release/v1.0.0` → `main`
- Mobile responsiveness audit
- Performance optimization (query caching, eager loading)
- Final test coverage review (aim for >70%)
- Documentation update
- Merge to `main`, tag `v1.0.0`

---

## Testing Strategy

### Unit Tests
- **Entities:** Validation rules, getters/setters, business methods
- **Services:** LLM parsing logic, dashboard calculations, receipt processing
- **Repositories:** Custom query methods
- Mock external dependencies (LLM API, clock for date testing)

### Functional Tests
- **Controllers:** All API endpoints return correct status codes and data shapes
- **Forms:** Validation errors, successful submission
- **Authentication:** Access control, API key validation
- **End-to-end flows:** Manual entry → appears on dashboard, NL entry → parsed correctly

### Test Data
- Use Doctrine fixtures for consistent test data
- Separate test database (`var/test.db`)

---

## Environment Configuration (`.env`)

```env
# Application
APP_ENV=dev
APP_SECRET=change-me-in-production

# Database
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"

# LLM Configuration
LLM_API_ENDPOINT=https://api.openai.com/v1/chat/completions
LLM_API_KEY=sk-your-key-here
LLM_MODEL=gpt-4o-mini
LLM_TIMEOUT=10

# CORS (production)
CORS_ALLOW_ORIGIN='^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$'
```

---

## Mobile Responsiveness Strategy

- **Framework:** Tailwind CSS with mobile-first utility classes
- **Breakpoints:**
  - Default: Mobile (single column, stacked cards)
  - `md` (768px): Tablet (2-column grids)
  - `lg` (1024px): Desktop (full dashboard layout)
- **Touch Optimizations:**
  - Minimum tap target: 44x44px
  - Bottom navigation bar on mobile (easy thumb reach)
  - Floating Action Button (FAB) for quick entry
  - Swipe gestures for receipt list (optional stretch)
- **Performance:**
  - Lazy-load charts below the fold
  - Debounce search inputs
  - Minimize JS bundle (no heavy frameworks)

---

## Future Considerations (Post-MVP)

- Multi-user support with proper authentication (OAuth2, JWT)
- Receipt image upload + OCR (Tesseract or cloud OCR)
- Recurring expense tracking
- Budget setting and alerts
- Data export (CSV, PDF)
- Progressive Web App (PWA) with offline support
- PostgreSQL migration path
- Docker containerization
- CI/CD pipeline

---

## Development Commands

```bash
# Start local server
symfony server:start

# Run migrations
php bin/console doctrine:migrations:migrate

# Load fixtures
php bin/console doctrine:fixtures:load

# Run tests
php bin/phpunit

# Run tests with coverage
php bin/phpunit --coverage-html var/coverage

# Clear cache
php bin/console cache:clear
```

---

## Decision Log

| Date | Decision | Rationale |
|------|----------|-----------|
| 2026-07-30 | Symfony over Laravel/Slim | Better Doctrine integration, mature testing, long-term maintainability |
| 2026-07-30 | SQLite over PostgreSQL | Zero-config for single-user MVP; Doctrine makes migration trivial later |
| 2026-07-30 | Twig + Vanilla JS over React/Vue | Faster initial development, no build complexity, sufficient for MVP |
| 2026-07-30 | Tailwind CSS over Bootstrap | Utility-first enables rapid custom UI, smaller bundle |
| 2026-07-30 | API Key over Session Auth | Stateless, simpler for SPA-like frontend, sufficient for single user |
| 2026-07-30 | Chart.js over D3/Highcharts | Lightweight, responsive, easier to implement |
