# Penny-Track 💰

A fast, mobile-responsive web application for tracking personal expenses and receipts.

## Features

- **Quick Entry**: Natural language parsing via LLM (OpenAI-compatible)
- **Manual Entry**: Full form with autocomplete from history
- **Dashboard**: Summary cards, spending breakdowns, trends, and insights
- **Mobile-First**: Responsive design optimized for phones
- **Secure**: Simple API-key authentication (single-user MVP)

## Tech Stack

- **Backend**: PHP 8.3+ / Symfony 7
- **Database**: SQLite (via Doctrine ORM — easy migration path)
- **Frontend**: Twig + Tailwind CSS + Chart.js
- **Testing**: PHPUnit (unit + functional)

## Quick Start

```bash
# Install dependencies
composer install

# Create database & schema
php bin/console doctrine:schema:create

# Run tests
php bin/phpunit

# Start dev server
symfony server:start
```

Visit `http://localhost:8000` and follow the setup to generate your API key.

## LLM Configuration

Set in `.env` or `.env.local`:

```env
LLM_API_ENDPOINT=https://api.openai.com/v1/chat/completions
LLM_API_KEY=sk-your-key
LLM_MODEL=gpt-4o-mini
```

Supports any OpenAI-compatible API (Ollama, LM Studio, etc.).

## License

MIT
