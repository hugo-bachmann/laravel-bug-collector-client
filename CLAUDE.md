# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`bug-collector/laravel-client` — a Laravel package (installed into client apps via Composer, not a standalone app) that reports **errors** and **performance anomalies** (slow HTTP requests, queue jobs, SQL queries, and artisan commands) to a centralized Bug Collector service.

**Status: implemented.** The service provider, config, transport (HttpTransport), and all instrumentation are implemented. Errors are captured via HandlerDecorator; performance events are collected by RequestTiming (middleware), CommandTiming, JobTiming, and QueryTiming. Documentation and code comments are in French; match that language when editing them.

## Commands

```bash
composer install
vendor/bin/phpunit                          # run tests
vendor/bin/phpunit --filter testMethodName  # run a single test
vendor/bin/pint                             # format (Laravel Pint)
vendor/bin/pint --test                      # check formatting without writing
```

Tests use **Orchestra Testbench** (boots a minimal Laravel app for package testing). Test classes belong under `tests/` in the `BugCollector\Client\Tests\` namespace (already wired in `autoload-dev`).

## Architecture

- **PSR-4:** `BugCollector\Client\` → `src/`. PHP `^8.2`; supports Laravel 12/13 (`illuminate/*: ^12|^13`).
- **Entry point:** `BugCollectorServiceProvider` is auto-discovered via `extra.laravel.providers` in `composer.json` — clients don't register it manually. `register()` merges config and wires all singletons; `boot()` publishes config (tag `bug-collector-config`), registers the terminating hook (flushes the Outbox), and calls `Registrar::register()` to wire instrumentation.
- **Config-driven kill switches:** `config/bug-collector.php` is the contract for behavior. A global `enabled` flag (boot returns early if false), plus independent `errors.enabled` and `performance.enabled`. Performance reporting is **threshold-based**: events are only sent when they exceed a per-type threshold in milliseconds (`request`/`job`/`query`/`command`). Every setting is env-overridable (`BUG_COLLECTOR_*`).
- **Cookie capture** is opt-in (`errors.cookies`, env `BUG_COLLECTOR_SEND_COOKIES`, default `false`). When enabled, `BugPayload` redacts sensitive cookie names (session cookie, `XSRF-TOKEN`, any key starting with `remember_`) — replaced with `'[REDACTED]'`. The session cookie name is read from `session.cookie` config at report time.
- **Required env in client apps:** `BUG_COLLECTOR_URL` (root URL of the Bug Collector service — the client appends the endpoint paths) and `BUG_COLLECTOR_INGEST_KEY` (sent as `X-Ingest-Key` header). Config keys: `base_url` and `endpoints.errors` / `endpoints.performance`.
- **Transport:** `HttpTransport` wraps Laravel's HTTP client with a short timeout and the `X-Ingest-Key` header. `send()` returns `bool` (true only on 2xx); all errors are swallowed (fail-safe).
- **Outbox:** collects payloads during the request, flushed in the terminating hook (web/console) or immediately after each job (long-lived worker). `flush()` stops at the first failed send and drops the rest of the batch.

### Instrumentation

- **Errors** → `HandlerDecorator` decorates the app's ExceptionHandler; `ErrorReporter` builds the payload and records it in the Outbox.
- **Slow requests** → `RequestTiming` middleware (pushed via `Registrar`); measures from `REQUEST_TIME_FLOAT` (guarded fallback) until the response is built, all in `handle()` (no `terminate()`).
- **Slow jobs** → `JobTiming` listens to queue events (including `JobExceptionOccurred`, which ends the attempt measurement); flushes the Outbox immediately (worker is long-lived).
- **Slow queries** → `QueryTiming` listens to `QueryExecuted`; above the threshold adds `context` (`connection`, `origin`, `caller`).
- **Slow commands** → `CommandTiming` listens to `CommandStarting` / `CommandFinished`.
- **Diagnostics** → `php artisan bug-collector:test` (uses `Gate::disabledReason()`, sends a test exception directly, exit 0/1).

All instrumentation honors the config kill switches and thresholds, and is non-blocking / fail-safe.

### Octane / long-lived workers

`Outbox` and the timing collectors (`RequestTiming`, `JobTiming`, `QueryTiming`, `CommandTiming`) are registered as container singletons. Under a standard HTTP request or artisan command the Outbox is flushed in the `terminating` callback registered by `BugCollectorServiceProvider::boot()`. Under a long-lived runtime (Octane, queue workers) that callback still fires per request/command cycle, so web and console flushes work correctly as long as the framework triggers `terminate()`. Queue jobs flush the Outbox immediately inside `JobTiming` after each job completes, so they do not rely on the terminating hook at all.
