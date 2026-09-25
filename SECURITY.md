# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 2.1.x   | ✅ |

## Reporting a Vulnerability

Report vulnerabilities privately to **security@digitaladapt.com** (or open a private
security advisory on the repository). Please include reproduction steps and affected
versions. You will receive an acknowledgement within 48 hours and a status update at
least weekly until resolution.

**Do not open a public issue for a suspected vulnerability.**

## Security model summary

penny-track is a single-user expense tracker. There is no multi-tenancy and no
per-user authorisation: the API key *is* the identity.

- **API-key authentication, in two flavours.** Full-access keys may read and
  write; read-only keys may only read (`src/Security/ApiKeyAuthenticator.php`).
  Keys are stored as bcrypt hashes — the plaintext exists only at creation time
  and is never recoverable.
- **The key becomes a bearer credential in the browser.** The login page stores
  it in `localStorage` under `pennytrack_api_key` and every API call sends it as
  an `X-API-Key` header. That is a deliberate trade-off for a single-user tool
  with no cookie session for the API; it does mean **any XSS on this origin can
  exfiltrate the key**. There is no third-party script on the page by design —
  see below.
- **No third-party JavaScript.** Assets are served from the app's own
  AssetMapper pipeline rather than a CDN, so a compromised CDN cannot inject
  script into a page that holds the API key.
- **Security headers are set by the app, not left to the proxy.**
  `src/EventSubscriber/SecurityHeadersSubscriber.php` sets
  `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  `Referrer-Policy: strict-origin-when-cross-origin` and HSTS (HTTPS only).
  `X-XSS-Protection` is deliberately **not** set — it is deprecated and modern
  browsers ignore it (§8.9).
- **`.env` is never committed; secrets are env vars injected at runtime.**
  Real secrets belong in `.env.local`, read via `%env(...)%`. `.env.example`,
  `.env.test` and `docs/examples/.env.example` are the committed env files.
  `compose.yaml` uses `${VAR:?}` for `APP_SECRET` and `LLM_API_KEY` so a missing
  secret stops the container instead of silently booting an insecure instance.
- **LLM calls leave the machine.** If you use the natural-language quick entry,
  the text you type is sent to whatever `LLM_API_ENDPOINT` points at. Point it
  at a local model if that matters to you.

## Scope

In scope: the application code in `src/`, the shipped `docker/Caddyfile`, the
`Dockerfile`, and the authentication/authorisation logic.

Out of scope: the host reverse proxy configuration (see
`docs/examples/Caddyfile`) and the security of any third-party LLM endpoint you
choose to configure.

## Deployment note

The image runs as the non-root `nobody` user and declares `VOLUME /app/var`,
which is where the SQLite database, logs and cache live. That volume is the
application state — back it up.
