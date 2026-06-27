# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`bug-collector/laravel-client` — a Laravel package (installed into client apps via Composer, not a standalone app) that reports **errors** and **performance anomalies** (slow HTTP requests, queue jobs, SQL queries, and artisan commands) to a centralized Bug Collector service.

**Status: skeleton only.** The service provider, config, and Composer metadata exist; the transport (HTTP client) and all instrumentation are unimplemented — marked with `TODO` in `src/BugCollectorServiceProvider.php`. Documentation and code comments are in French; match that language when editing them.

## Commands

This package has no `vendor/` yet. First run `composer install`. Tooling is declared in `composer.json` `require-dev` (PHPUnit, Pint, Orchestra Testbench):

```bash
composer install
vendor/bin/phpunit                          # run tests (no tests/ or phpunit.xml exist yet — create them)
vendor/bin/phpunit --filter testMethodName  # run a single test
vendor/bin/pint                             # format (Laravel Pint)
vendor/bin/pint --test                      # check formatting without writing
```

Tests use **Orchestra Testbench** (boots a minimal Laravel app for package testing). Test classes belong under `tests/` in the `BugCollector\Client\Tests\` namespace (already wired in `autoload-dev`).

## Architecture

- **PSR-4:** `BugCollector\Client\` → `src/`. PHP `^8.2`; supports Laravel 11/12/13 (`illuminate/*: ^11|^12|^13`).
- **Entry point:** `BugCollectorServiceProvider` is auto-discovered via `extra.laravel.providers` in `composer.json` — clients don't register it manually. `register()` merges config; `boot()` publishes config (tag `bug-collector-config`) and is where instrumentation will be wired once the wire contract is fixed.
- **Config-driven kill switches:** `config/bug-collector.php` is the contract for behavior. A global `enabled` flag (boot returns early if false), plus independent `errors.enabled` and `performance.enabled`. Performance reporting is **threshold-based**: events are only sent when they exceed a per-type threshold in milliseconds (`request`/`job`/`query`/`command`). Every setting is env-overridable (`BUG_COLLECTOR_*`).
- **Required env in client apps:** `BUG_COLLECTOR_URL` (ingest endpoint) and `BUG_COLLECTOR_INGEST_KEY` (identifies the site/team server-side). The client is meant to self-disable when network config is absent (useful locally).

### Planned instrumentation (the unbuilt core)

When implementing, the intended Laravel hook points (per the `boot()` TODO) are:
- Errors → the exception handler
- Slow requests → a timing middleware
- Slow jobs → queue events
- Slow queries → `DB::whenQueryingForLongerThan` / `DB::listen`
- Slow commands → console events

Honor the config kill switches and thresholds for each, and keep reporting non-blocking / fail-safe so a Bug Collector outage never breaks the host app.
