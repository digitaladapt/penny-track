# Penny-Track 💰

A fast, mobile-responsive web application for tracking personal expenses and receipts.

## Features

- **Quick Entry**: Natural language parsing via LLM (OpenAI-compatible)
- **Manual Entry**: Full form with autocomplete from history
- **Dashboard**: Summary cards, spending breakdowns, trends, and insights
- **Mobile-First**: Responsive design optimized for phones
- **Secure**: Simple API-key authentication (single-user MVP)
- **Docker**: Production-ready container with FrankenPHP worker mode

## Tech Stack

- **Backend**: PHP 8.4 / Symfony 8.1
- **Database**: SQLite (via Doctrine ORM — easy migration path)
- **Frontend**: Twig + Tailwind CSS + Chart.js
- **Testing**: PHPUnit (unit + functional)
- **Container**: FrankenPHP (Caddy-based, PHP worker mode)

## Quick Start

### Docker (Recommended)

```bash
# Clone and configure
cp .env .env.local
# Edit .env.local: set APP_SECRET, LLM_API_KEY

# Build and run
docker compose up -d --build

# For tagged releases, pass the version:
APP_VERSION=$(git describe --tags --abbrev=0) docker compose up -d --build
```

The app listens on port 80 inside the container. Use a reverse proxy
(Caddy, Nginx, Traefik) to expose it on your desired port/domain.

### Local Development

```bash
# Install dependencies
composer install

# Create database & run migrations
php bin/console doctrine:migrations:migrate

# Run tests
php bin/phpunit

# Start dev server
symfony server:start
```

Visit `http://localhost:8000` and follow the setup to generate your API key.

## Configuration

Set in `.env` or `.env.local`:

```env
APP_SECRET=your-secret-here
LLM_API_ENDPOINT=https://api.openai.com/v1/chat/completions
LLM_API_KEY=sk-your-key
LLM_MODEL=gpt-4o-mini
LLM_TIMEOUT=10
```

Supports any OpenAI-compatible API (Ollama, LM Studio, etc.).

### Environment Variables

| Variable            | Default                        | Purpose                          |
|---------------------|--------------------------------|----------------------------------|
| `APP_SECRET`        | *(required in prod)*           | Symfony secret                   |
| `DATABASE_URL`      | `sqlite:///%kernel.project_dir%/var/data.db` | Database connection |
| `LLM_API_ENDPOINT`  | `https://api.openai.com/v1/chat/completions` | LLM endpoint for receipt parsing |
| `LLM_API_KEY`       | `change-me`                    | LLM API key                      |
| `LLM_MODEL`         | `gpt-4o-mini`                  | LLM model name                   |
| `LLM_TIMEOUT`       | `10`                           | LLM request timeout (seconds)    |
| `APP_VERSION`       | `dev`                          | Build version (for `/api/about`) |

## API Endpoints

### System

| Method | Path           | Auth | Description                |
|--------|----------------|------|----------------------------|
| GET    | `/api/about`   | No   | App name and version       |
| GET    | `/api/health`  | No   | Health check (`healthy`)   |

### Authentication

| Method | Path               | Auth | Description                               |
|--------|--------------------|------|-------------------------------------------|
| POST   | `/api/auth/setup`  | No   | Generate initial API key (only works once) |
| POST   | `/api/auth/verify` | No   | Verify an API key                         |

### Receipts

| Method | Path                    | Auth | Description                      |
|--------|-------------------------|------|----------------------------------|
| GET    | `/api/receipts`         | Yes  | List receipts (paginated)        |
| GET    | `/api/receipts/{id}`    | Yes  | Get single receipt               |
| POST   | `/api/receipts`         | Yes  | Create receipt                   |
| PUT    | `/api/receipts/{id}`    | Yes  | Update receipt (partial)         |
| DELETE | `/api/receipts/{id}`    | Yes  | Delete receipt                   |
| POST   | `/api/receipts/parse`   | Yes  | Parse natural language → receipt |

### Dashboard

| Method | Path                                  | Auth | Description                |
|--------|---------------------------------------|------|----------------------------|
| GET    | `/api/dashboard/summary`              | Yes  | Monthly summary statistics |
| GET    | `/api/dashboard/spending-by-category` | Yes  | Category breakdown         |

## License

MIT
