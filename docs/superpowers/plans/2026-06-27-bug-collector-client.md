# Bug Collector Client — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implémenter l'instrumentation (erreurs + 4 sondes perf) et le transport HTTP du package `bug-collector/laravel-client`, qui reporte au service Bug Collector centralisé.

**Architecture:** Trois couches découplées — Instrumentation (capte les événements Laravel) → Reporting (construit les payloads + applique seuils/kill-switches) → Outbox (tampon mémoire) → Transport (POST HTTP fail-safe). Les erreurs sont captées en décorant l'`ExceptionHandler` (zéro config client). L'Outbox est vidé via `app->terminating()` (web/commande) ou immédiatement (job de queue).

**Tech Stack:** PHP 8.2+, Laravel 11/12/13 (`illuminate/*`), Orchestra Testbench, PHPUnit, Mockery, Laravel Pint.

## Global Constraints

- PHP `^8.2` ; compatible Laravel `^11.0|^12.0|^13.0`.
- **Fail-safe silencieux total** : aucune erreur du client (réseau, timeout, construction de payload) ne doit jamais remonter dans l'app hôte. Tout est enveloppé `try/catch` et avalé.
- **Auth** : header HTTP `X-Ingest-Key: <clé en clair>` sur chaque requête.
- **Deux endpoints**, construits comme `rtrim(base_url,'/') . path` : `/api/laravel-bugs/add` (erreurs), `/api/laravel-perf/add` (perf).
- **Contrat erreur** (champs requis) : `message` (string ≤1000), `type` (int = code HTTP), `file` (string), `line` (int ≥0), `stackTrace` (array), `url` (string ≤2000), `ip` (IP valide). Optionnels : `userAgent`, `username`, `userEmail`, `cookies` (array).
- **Contrat perf** (champs requis) : `kind` ∈ `request|job|query|command`, `label` (string ≤1000), `duration_ms` (int ≥0). Optionnel : `context` (array).
- **Namespace** : `BugCollector\Client\` → `src/`. Tests : `BugCollector\Client\Tests\` → `tests/`.
- TDD strict, commits fréquents, commentaires/doc en français.

---

## File Structure

```
src/
  BugCollectorServiceProvider.php      # bindings + décoration handler + boot wiring
  Support/Gate.php                     # décision "doit-on reporter ?"
  Outbox.php                           # tampon record/flush
  Transport/Transport.php              # interface send()
  Transport/HttpTransport.php          # POST X-Ingest-Key, fail-safe
  Reporting/ErrorReporter.php          # Throwable -> Outbox (garde de ré-entrance)
  Reporting/PerformanceReporter.php    # seuil -> Outbox
  Payloads/BugPayload.php              # fromThrowable()
  Payloads/PerfPayload.php             # make()
  Instrumentation/HandlerDecorator.php # ExceptionHandler décoré
  Instrumentation/RequestTiming.php    # middleware global
  Instrumentation/CommandTiming.php    # events console
  Instrumentation/JobTiming.php        # events queue + flush immédiat
  Instrumentation/QueryTiming.php      # QueryExecuted
  Instrumentation/Registrar.php        # wire les collecteurs perf
config/bug-collector.php               # config remaniée
tests/
  TestCase.php                         # base Testbench
  Unit/...                             # tests unitaires
  Feature/...                          # tests feature
phpunit.xml
```

---

## Task 1: Harness de test + config remaniée

**Files:**
- Modify: `config/bug-collector.php`
- Create: `phpunit.xml`
- Create: `tests/TestCase.php`
- Create: `tests/Unit/SanityTest.php`
- Modify: `composer.json` (ajout `mockery/mockery` en require-dev)

**Interfaces:**
- Produces: clé de config `bug-collector.base_url`, `bug-collector.endpoints.errors`, `bug-collector.endpoints.performance`, `bug-collector.timeout`, `bug-collector.errors.trace_depth`, `bug-collector.performance.thresholds.{request,job,query,command}`. Base `TestCase` Testbench avec `base_url`/`ingest_key`/sqlite préconfigurés.

- [ ] **Step 1: Remanier la config**

Remplacer intégralement `config/bug-collector.php` par :

```php
<?php

return [

    // Connexion au service Bug Collector. base_url = la RACINE du service
    // (pas l'endpoint complet) ; les chemins sont ajoutés par le transport.
    'base_url'   => env('BUG_COLLECTOR_URL'),
    'ingest_key' => env('BUG_COLLECTOR_INGEST_KEY'),

    // Coupe-circuit global. Si base_url ou ingest_key manquent, le client
    // se désactive de lui-même (utile en local).
    'enabled' => env('BUG_COLLECTOR_ENABLED', true),

    // Timeout du transport HTTP, en secondes.
    'timeout' => env('BUG_COLLECTOR_TIMEOUT', 2),

    'endpoints' => [
        'errors'      => '/api/laravel-bugs/add',
        'performance' => '/api/laravel-perf/add',
    ],

    'errors' => [
        'enabled'     => env('BUG_COLLECTOR_ERRORS_ENABLED', true),
        'trace_depth' => env('BUG_COLLECTOR_TRACE_DEPTH', 50),
    ],

    'performance' => [
        'enabled' => env('BUG_COLLECTOR_PERF_ENABLED', true),

        // Seuils en millisecondes. En dessous, rien n'est envoyé.
        'thresholds' => [
            'request' => env('BUG_COLLECTOR_THRESHOLD_REQUEST', 2000),
            'job'     => env('BUG_COLLECTOR_THRESHOLD_JOB', 30000),
            'query'   => env('BUG_COLLECTOR_THRESHOLD_QUERY', 1000),
            'command' => env('BUG_COLLECTOR_THRESHOLD_COMMAND', 60000),
        ],
    ],

];
```

- [ ] **Step 2: Ajouter Mockery en require-dev**

Dans `composer.json`, ajouter à `require-dev` (garder l'ordre alphabétique avec `sort-packages`) :

```json
"mockery/mockery": "^1.6",
```

Puis lancer :

```bash
composer update
```

Expected: `vendor/` installé, pas d'erreur de résolution.

- [ ] **Step 3: Créer `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="Package Test Suite">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 4: Créer la base `tests/TestCase.php`**

```php
<?php

namespace BugCollector\Client\Tests;

use BugCollector\Client\BugCollectorServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [BugCollectorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        // Config réseau valide par défaut pour que le Gate soit "enabled".
        $config->set('bug-collector.base_url', 'https://collector.test');
        $config->set('bug-collector.ingest_key', 'test-key');

        // Base sqlite en mémoire pour les tests qui touchent la DB.
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }
}
```

- [ ] **Step 5: Créer un test sanity**

`tests/Unit/SanityTest.php` :

```php
<?php

namespace BugCollector\Client\Tests\Unit;

use BugCollector\Client\Tests\TestCase;

class SanityTest extends TestCase
{
    public function test_the_config_is_merged(): void
    {
        $this->assertSame('https://collector.test', config('bug-collector.base_url'));
        $this->assertSame('/api/laravel-bugs/add', config('bug-collector.endpoints.errors'));
        $this->assertSame(2000, config('bug-collector.performance.thresholds.request'));
    }
}
```

- [ ] **Step 6: Lancer les tests**

Run: `vendor/bin/phpunit --filter SanityTest`
Expected: PASS (1 test).

- [ ] **Step 7: Commit**

```bash
git add composer.json config/bug-collector.php phpunit.xml tests/
git commit -m "Add test harness and reshape config (base_url + endpoints)"
```

---

## Task 2: Gate (décision de reporting)

**Files:**
- Create: `src/Support/Gate.php`
- Test: `tests/Unit/GateTest.php`

**Interfaces:**
- Consumes: config `bug-collector.*` (Task 1).
- Produces: `Gate::__construct(Illuminate\Contracts\Config\Repository $config)` ; `enabled(): bool` (global + base_url + ingest_key présents) ; `shouldReportErrors(): bool` ; `shouldReportPerformance(): bool`.

- [ ] **Step 1: Écrire les tests**

`tests/Unit/GateTest.php` :

```php
<?php

namespace BugCollector\Client\Tests\Unit;

use BugCollector\Client\Support\Gate;
use BugCollector\Client\Tests\TestCase;

class GateTest extends TestCase
{
    private function gate(): Gate
    {
        return $this->app->make(Gate::class);
    }

    public function test_enabled_when_config_is_complete(): void
    {
        $this->assertTrue($this->gate()->enabled());
    }

    public function test_disabled_when_base_url_missing(): void
    {
        config(['bug-collector.base_url' => null]);
        $this->assertFalse($this->gate()->enabled());
    }

    public function test_disabled_when_ingest_key_missing(): void
    {
        config(['bug-collector.ingest_key' => '']);
        $this->assertFalse($this->gate()->enabled());
    }

    public function test_disabled_when_global_flag_off(): void
    {
        config(['bug-collector.enabled' => false]);
        $this->assertFalse($this->gate()->enabled());
    }

    public function test_errors_flag_gates_error_reporting(): void
    {
        config(['bug-collector.errors.enabled' => false]);
        $this->assertFalse($this->gate()->shouldReportErrors());
        $this->assertTrue($this->gate()->shouldReportPerformance());
    }

    public function test_performance_flag_gates_perf_reporting(): void
    {
        config(['bug-collector.performance.enabled' => false]);
        $this->assertFalse($this->gate()->shouldReportPerformance());
        $this->assertTrue($this->gate()->shouldReportErrors());
    }
}
```

- [ ] **Step 2: Vérifier l'échec**

Run: `vendor/bin/phpunit --filter GateTest`
Expected: FAIL (`Class "BugCollector\Client\Support\Gate" not found`).

- [ ] **Step 3: Implémenter `Gate`**

```php
<?php

namespace BugCollector\Client\Support;

use Illuminate\Contracts\Config\Repository;

class Gate
{
    public function __construct(private Repository $config) {}

    /**
     * Coupe-circuit global : activé seulement si la config réseau est complète.
     */
    public function enabled(): bool
    {
        if (! $this->config->get('bug-collector.enabled', true)) {
            return false;
        }

        return ! empty($this->config->get('bug-collector.base_url'))
            && ! empty($this->config->get('bug-collector.ingest_key'));
    }

    public function shouldReportErrors(): bool
    {
        return $this->enabled()
            && (bool) $this->config->get('bug-collector.errors.enabled', true);
    }

    public function shouldReportPerformance(): bool
    {
        return $this->enabled()
            && (bool) $this->config->get('bug-collector.performance.enabled', true);
    }
}
```

- [ ] **Step 4: Vérifier le succès**

Run: `vendor/bin/phpunit --filter GateTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Support/Gate.php tests/Unit/GateTest.php
git commit -m "Add Gate for reporting decisions"
```

---

## Task 3: BugPayload (mapping erreur)

**Files:**
- Create: `src/Payloads/BugPayload.php`
- Test: `tests/Unit/BugPayloadTest.php`

**Interfaces:**
- Produces: `BugPayload::fromThrowable(\Throwable $e, ?\Illuminate\Http\Request $request, int $traceDepth = 50): array` — renvoie un payload conforme au contrat erreur.

> **Note de simplification (vs spec)** : pour les erreurs hors-web (console/job), `url` vaut `'console'` et `ip` retombe sur `127.0.0.1`. La granularité `artisan:<cmd>` / `job:<classe>` de la spec est portée par les **events perf** (labels précis), pas par les payloads d'erreur — qui restent ainsi valides côté serveur sans propagation de contexte fragile.

- [ ] **Step 1: Écrire les tests**

`tests/Unit/BugPayloadTest.php` :

```php
<?php

namespace BugCollector\Client\Tests\Unit;

use BugCollector\Client\Payloads\BugPayload;
use BugCollector\Client\Tests\TestCase;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BugPayloadTest extends TestCase
{
    public function test_maps_a_generic_exception_to_type_500(): void
    {
        $payload = BugPayload::fromThrowable(new RuntimeException('Boom'), null);

        $this->assertSame('Boom', $payload['message']);
        $this->assertSame(500, $payload['type']);
        $this->assertIsArray($payload['stackTrace']);
        $this->assertSame('console', $payload['url']);
        $this->assertSame('127.0.0.1', $payload['ip']);
    }

    public function test_uses_http_status_code_for_http_exceptions(): void
    {
        $payload = BugPayload::fromThrowable(new NotFoundHttpException('Nope'), null);

        $this->assertSame(404, $payload['type']);
    }

    public function test_empty_message_falls_back_to_class_name(): void
    {
        $payload = BugPayload::fromThrowable(new RuntimeException(''), null);

        $this->assertSame(RuntimeException::class, $payload['message']);
    }

    public function test_truncates_long_message_to_1000(): void
    {
        $payload = BugPayload::fromThrowable(new RuntimeException(str_repeat('a', 1500)), null);

        $this->assertSame(1000, strlen($payload['message']));
    }

    public function test_trace_frames_drop_args_and_respect_depth(): void
    {
        $payload = BugPayload::fromThrowable(new RuntimeException('x'), null, 2);

        $this->assertLessThanOrEqual(2, count($payload['stackTrace']));
        foreach ($payload['stackTrace'] as $frame) {
            $this->assertArrayNotHasKey('args', $frame);
            $this->assertArrayHasKey('function', $frame);
        }
    }

    public function test_pulls_url_ip_and_useragent_from_request(): void
    {
        $request = Request::create('https://app.test/orders?x=1', 'GET', server: [
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_USER_AGENT' => 'PHPUnit',
        ]);

        $payload = BugPayload::fromThrowable(new RuntimeException('x'), $request);

        $this->assertStringStartsWith('https://app.test/orders', $payload['url']);
        $this->assertSame('203.0.113.7', $payload['ip']);
        $this->assertSame('PHPUnit', $payload['userAgent']);
    }
}
```

- [ ] **Step 2: Vérifier l'échec**

Run: `vendor/bin/phpunit --filter BugPayloadTest`
Expected: FAIL (`Class "BugCollector\Client\Payloads\BugPayload" not found`).

- [ ] **Step 3: Implémenter `BugPayload`**

```php
<?php

namespace BugCollector\Client\Payloads;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class BugPayload
{
    /**
     * Construit le payload d'erreur conforme au contrat /api/laravel-bugs/add.
     *
     * @return array<string, mixed>
     */
    public static function fromThrowable(Throwable $e, ?Request $request, int $traceDepth = 50): array
    {
        $message = substr($e->getMessage(), 0, 1000);

        return [
            'message'    => $message !== '' ? $message : $e::class,
            'type'       => $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500,
            'file'       => substr($e->getFile(), 0, 1000),
            'line'       => $e->getLine(),
            'stackTrace' => self::trace($e, $traceDepth),
            'url'        => $request ? substr($request->fullUrl(), 0, 2000) : 'console',
            'ip'         => ($request?->ip()) ?: '127.0.0.1',
            'userAgent'  => $request ? substr((string) $request->userAgent(), 0, 1000) : null,
            'username'   => self::userAttr($request, 'name'),
            'userEmail'  => self::userAttr($request, 'email'),
            'cookies'    => $request ? $request->cookies->all() : null,
        ];
    }

    /**
     * Frames file/line/function/class uniquement — les args sont volontairement
     * exclus (volume + données sensibles).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function trace(Throwable $e, int $depth): array
    {
        $frames = array_slice($e->getTrace(), 0, max(0, $depth));

        return array_map(static fn (array $frame): array => [
            'file'     => $frame['file'] ?? null,
            'line'     => $frame['line'] ?? null,
            'function' => $frame['function'] ?? null,
            'class'    => $frame['class'] ?? null,
        ], $frames);
    }

    private static function userAttr(?Request $request, string $attr): ?string
    {
        try {
            $user = $request?->user();
            $value = $user?->{$attr} ?? null;

            return $value === null ? null : substr((string) $value, 0, 255);
        } catch (Throwable) {
            return null;
        }
    }
}
```

- [ ] **Step 4: Vérifier le succès**

Run: `vendor/bin/phpunit --filter BugPayloadTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Payloads/BugPayload.php tests/Unit/BugPayloadTest.php
git commit -m "Add BugPayload error mapping"
```

---

## Task 4: PerfPayload (mapping perf)

**Files:**
- Create: `src/Payloads/PerfPayload.php`
- Test: `tests/Unit/PerfPayloadTest.php`

**Interfaces:**
- Produces: `PerfPayload::make(string $kind, string $label, int $durationMs, ?array $context = null): array`.

- [ ] **Step 1: Écrire les tests**

`tests/Unit/PerfPayloadTest.php` :

```php
<?php

namespace BugCollector\Client\Tests\Unit;

use BugCollector\Client\Payloads\PerfPayload;
use BugCollector\Client\Tests\TestCase;

class PerfPayloadTest extends TestCase
{
    public function test_builds_the_perf_payload(): void
    {
        $payload = PerfPayload::make('request', 'GET /orders', 2500, ['route' => 'orders.index']);

        $this->assertSame('request', $payload['kind']);
        $this->assertSame('GET /orders', $payload['label']);
        $this->assertSame(2500, $payload['duration_ms']);
        $this->assertSame(['route' => 'orders.index'], $payload['context']);
    }

    public function test_truncates_label_to_1000(): void
    {
        $payload = PerfPayload::make('query', str_repeat('x', 1500), 1200);

        $this->assertSame(1000, strlen($payload['label']));
    }

    public function test_context_defaults_to_null(): void
    {
        $payload = PerfPayload::make('command', 'app:sync', 70000);

        $this->assertNull($payload['context']);
    }
}
```

- [ ] **Step 2: Vérifier l'échec**

Run: `vendor/bin/phpunit --filter PerfPayloadTest`
Expected: FAIL (`Class "...PerfPayload" not found`).

- [ ] **Step 3: Implémenter `PerfPayload`**

```php
<?php

namespace BugCollector\Client\Payloads;

class PerfPayload
{
    /**
     * Construit le payload perf conforme au contrat /api/laravel-perf/add.
     *
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    public static function make(string $kind, string $label, int $durationMs, ?array $context = null): array
    {
        return [
            'kind'        => $kind,
            'label'       => substr($label, 0, 1000),
            'duration_ms' => max(0, $durationMs),
            'context'     => $context,
        ];
    }
}
```

- [ ] **Step 4: Vérifier le succès**

Run: `vendor/bin/phpunit --filter PerfPayloadTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Payloads/PerfPayload.php tests/Unit/PerfPayloadTest.php
git commit -m "Add PerfPayload mapping"
```

---

## Task 5: Outbox (tampon)

**Files:**
- Create: `src/Outbox.php`
- Test: `tests/Unit/OutboxTest.php`

**Interfaces:**
- Consumes: `Transport` interface (défini en Task 6 ; ici on teste avec un double anonyme).
- Produces: `Outbox::record(string $path, array $payload): void` ; `flush(Transport $transport): void` ; `pending(): array` ; `isEmpty(): bool`.

> Note d'ordre : l'`Outbox` type-hint `Transport` (Task 6). Pour éviter un couplage d'ordre, créer d'abord le fichier d'interface vide de Task 6 Step 3 si on exécute dans l'ordre strict — sinon `flush()` peut prendre un paramètre non typé. Ici on type `Transport` et on suppose Task 6 exécutée juste après ; le test utilise un faux implémentant l'interface.

- [ ] **Step 1: Écrire les tests**

`tests/Unit/OutboxTest.php` :

```php
<?php

namespace BugCollector\Client\Tests\Unit;

use BugCollector\Client\Outbox;
use BugCollector\Client\Tests\TestCase;
use BugCollector\Client\Transport\Transport;

class OutboxTest extends TestCase
{
    public function test_record_then_pending_reflects_entries(): void
    {
        $outbox = new Outbox();
        $this->assertTrue($outbox->isEmpty());

        $outbox->record('/errors', ['a' => 1]);

        $this->assertFalse($outbox->isEmpty());
        $this->assertCount(1, $outbox->pending());
    }

    public function test_flush_sends_each_entry_once_then_empties(): void
    {
        $outbox = new Outbox();
        $outbox->record('/errors', ['a' => 1]);
        $outbox->record('/perf', ['b' => 2]);

        $sent = [];
        $transport = new class($sent) implements Transport {
            public function __construct(public array &$sent) {}
            public function send(string $path, array $payload): void
            {
                $this->sent[] = [$path, $payload];
            }
        };

        $outbox->flush($transport);

        $this->assertSame([['/errors', ['a' => 1]], ['/perf', ['b' => 2]]], $sent);
        $this->assertTrue($outbox->isEmpty());

        // Un second flush ne renvoie rien.
        $outbox->flush($transport);
        $this->assertCount(2, $sent);
    }
}
```

- [ ] **Step 2: Vérifier l'échec**

Run: `vendor/bin/phpunit --filter OutboxTest`
Expected: FAIL (`Class "BugCollector\Client\Outbox" not found` ou interface Transport manquante).

- [ ] **Step 3: Implémenter `Outbox`**

```php
<?php

namespace BugCollector\Client;

use BugCollector\Client\Transport\Transport;

class Outbox
{
    /** @var array<int, array{path: string, payload: array<string, mixed>}> */
    private array $pending = [];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $path, array $payload): void
    {
        $this->pending[] = ['path' => $path, 'payload' => $payload];
    }

    public function flush(Transport $transport): void
    {
        $items = $this->pending;
        $this->pending = [];

        foreach ($items as $item) {
            $transport->send($item['path'], $item['payload']);
        }
    }

    /** @return array<int, array{path: string, payload: array<string, mixed>}> */
    public function pending(): array
    {
        return $this->pending;
    }

    public function isEmpty(): bool
    {
        return $this->pending === [];
    }
}
```

- [ ] **Step 4: Vérifier le succès** (après Task 6 Step 3, l'interface existe ; si exécuté avant, créer d'abord l'interface)

Run: `vendor/bin/phpunit --filter OutboxTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Outbox.php tests/Unit/OutboxTest.php
git commit -m "Add Outbox buffer"
```

---

## Task 6: Transport + HttpTransport

**Files:**
- Create: `src/Transport/Transport.php`
- Create: `src/Transport/HttpTransport.php`
- Test: `tests/Unit/HttpTransportTest.php`

**Interfaces:**
- Consumes: config `bug-collector.{base_url, ingest_key, timeout}`.
- Produces: interface `Transport::send(string $path, array $payload): void` ; `HttpTransport::__construct(\Illuminate\Http\Client\Factory $http, \Illuminate\Contracts\Config\Repository $config)`.

- [ ] **Step 1: Écrire les tests**

`tests/Unit/HttpTransportTest.php` :

```php
<?php

namespace BugCollector\Client\Tests\Unit;

use BugCollector\Client\Tests\TestCase;
use BugCollector\Client\Transport\HttpTransport;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

class HttpTransportTest extends TestCase
{
    private function transport(): HttpTransport
    {
        return $this->app->make(HttpTransport::class);
    }

    public function test_posts_to_base_url_plus_path_with_ingest_key_header(): void
    {
        Http::fake();

        $this->transport()->send('/api/laravel-bugs/add', ['message' => 'Boom']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://collector.test/api/laravel-bugs/add'
                && $request->hasHeader('X-Ingest-Key', 'test-key')
                && $request['message'] === 'Boom';
        });
    }

    public function test_trims_trailing_slash_on_base_url(): void
    {
        config(['bug-collector.base_url' => 'https://collector.test/']);
        Http::fake();

        $this->transport()->send('/api/laravel-perf/add', ['kind' => 'request']);

        Http::assertSent(fn ($request) => $request->url() === 'https://collector.test/api/laravel-perf/add');
    }

    public function test_swallows_network_errors_silently(): void
    {
        Http::fake(fn () => throw new \RuntimeException('network down'));

        // Ne doit lever aucune exception.
        $this->transport()->send('/api/laravel-bugs/add', ['message' => 'Boom']);

        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Vérifier l'échec**

Run: `vendor/bin/phpunit --filter HttpTransportTest`
Expected: FAIL (`Class "...HttpTransport" not found`).

- [ ] **Step 3: Implémenter l'interface `Transport`**

```php
<?php

namespace BugCollector\Client\Transport;

interface Transport
{
    /**
     * Envoie un payload vers un chemin d'endpoint. Ne doit jamais lever.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(string $path, array $payload): void;
}
```

- [ ] **Step 4: Implémenter `HttpTransport`**

```php
<?php

namespace BugCollector\Client\Transport;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;
use Throwable;

class HttpTransport implements Transport
{
    public function __construct(
        private Factory $http,
        private Repository $config,
    ) {}

    public function send(string $path, array $payload): void
    {
        try {
            $base = rtrim((string) $this->config->get('bug-collector.base_url'), '/');

            $this->http
                ->timeout((int) $this->config->get('bug-collector.timeout', 2))
                ->acceptJson()
                ->withHeaders(['X-Ingest-Key' => (string) $this->config->get('bug-collector.ingest_key')])
                ->post($base.$path, $payload);
        } catch (Throwable) {
            // Fail-safe silencieux total : une panne du collecteur ne doit
            // jamais impacter l'app hôte.
        }
    }
}
```

- [ ] **Step 5: Vérifier le succès**

Run: `vendor/bin/phpunit --filter HttpTransportTest`
Expected: PASS (3 tests). Relancer aussi `vendor/bin/phpunit --filter OutboxTest` → PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Transport/ tests/Unit/HttpTransportTest.php
git commit -m "Add Transport interface and fail-safe HttpTransport"
```

---

## Task 7: PerformanceReporter (seuils)

**Files:**
- Create: `src/Reporting/PerformanceReporter.php`
- Test: `tests/Unit/PerformanceReporterTest.php`

**Interfaces:**
- Consumes: `Gate`, `Outbox`, config thresholds, `PerfPayload::make`.
- Produces: `PerformanceReporter::__construct(Gate $gate, Outbox $outbox, Repository $config)` ; `report(string $kind, string $label, int $durationMs, ?array $context = null): void`.

- [ ] **Step 1: Écrire les tests**

`tests/Unit/PerformanceReporterTest.php` :

```php
<?php

namespace BugCollector\Client\Tests\Unit;

use BugCollector\Client\Outbox;
use BugCollector\Client\Reporting\PerformanceReporter;
use BugCollector\Client\Tests\TestCase;

class PerformanceReporterTest extends TestCase
{
    private function reporter(): PerformanceReporter
    {
        return $this->app->make(PerformanceReporter::class);
    }

    private function outbox(): Outbox
    {
        return $this->app->make(Outbox::class);
    }

    public function test_records_when_above_threshold(): void
    {
        config(['bug-collector.performance.thresholds.request' => 2000]);

        $this->reporter()->report('request', 'GET /orders', 2500);

        $pending = $this->outbox()->pending();
        $this->assertCount(1, $pending);
        $this->assertSame('/api/laravel-perf/add', $pending[0]['path']);
        $this->assertSame('request', $pending[0]['payload']['kind']);
    }

    public function test_ignores_when_below_threshold(): void
    {
        config(['bug-collector.performance.thresholds.request' => 2000]);

        $this->reporter()->report('request', 'GET /orders', 1999);

        $this->assertTrue($this->outbox()->isEmpty());
    }

    public function test_ignores_when_threshold_disabled_false(): void
    {
        config(['bug-collector.performance.thresholds.job' => false]);

        $this->reporter()->report('job', 'App\\Jobs\\Foo', 999999);

        $this->assertTrue($this->outbox()->isEmpty());
    }

    public function test_ignores_when_performance_flag_off(): void
    {
        config(['bug-collector.performance.enabled' => false]);

        $this->reporter()->report('request', 'GET /orders', 999999);

        $this->assertTrue($this->outbox()->isEmpty());
    }
}
```

- [ ] **Step 2: Vérifier l'échec**

Run: `vendor/bin/phpunit --filter PerformanceReporterTest`
Expected: FAIL (`Class "...PerformanceReporter" not found`).

- [ ] **Step 3: Implémenter `PerformanceReporter`**

```php
<?php

namespace BugCollector\Client\Reporting;

use BugCollector\Client\Outbox;
use BugCollector\Client\Payloads\PerfPayload;
use BugCollector\Client\Support\Gate;
use Illuminate\Contracts\Config\Repository;
use Throwable;

class PerformanceReporter
{
    public function __construct(
        private Gate $gate,
        private Outbox $outbox,
        private Repository $config,
    ) {}

    /**
     * @param  array<string, mixed>|null  $context
     */
    public function report(string $kind, string $label, int $durationMs, ?array $context = null): void
    {
        try {
            if (! $this->gate->shouldReportPerformance()) {
                return;
            }

            $threshold = $this->config->get("bug-collector.performance.thresholds.{$kind}");

            // false ou null => type désactivé.
            if ($threshold === false || $threshold === null) {
                return;
            }

            if ($durationMs < (int) $threshold) {
                return;
            }

            $this->outbox->record(
                (string) $this->config->get('bug-collector.endpoints.performance'),
                PerfPayload::make($kind, $label, $durationMs, $context),
            );
        } catch (Throwable) {
            // Fail-safe : la collecte ne casse jamais l'app hôte.
        }
    }
}
```

- [ ] **Step 4: Vérifier le succès**

Run: `vendor/bin/phpunit --filter PerformanceReporterTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Reporting/PerformanceReporter.php tests/Unit/PerformanceReporterTest.php
git commit -m "Add PerformanceReporter with threshold filtering"
```

---

## Task 8: ErrorReporter (+ garde de ré-entrance)

**Files:**
- Create: `src/Reporting/ErrorReporter.php`
- Test: `tests/Unit/ErrorReporterTest.php`

**Interfaces:**
- Consumes: `Gate`, `Outbox`, config, container (pour résoudre la requête courante), `BugPayload::fromThrowable`.
- Produces: `ErrorReporter::__construct(Gate $gate, Outbox $outbox, Repository $config, \Illuminate\Contracts\Container\Container $app)` ; `report(\Throwable $e): void`.

- [ ] **Step 1: Écrire les tests**

`tests/Unit/ErrorReporterTest.php` :

```php
<?php

namespace BugCollector\Client\Tests\Unit;

use BugCollector\Client\Outbox;
use BugCollector\Client\Reporting\ErrorReporter;
use BugCollector\Client\Tests\TestCase;
use RuntimeException;

class ErrorReporterTest extends TestCase
{
    private function reporter(): ErrorReporter
    {
        return $this->app->make(ErrorReporter::class);
    }

    private function outbox(): Outbox
    {
        return $this->app->make(Outbox::class);
    }

    public function test_records_an_error_payload(): void
    {
        $this->reporter()->report(new RuntimeException('Boom'));

        $pending = $this->outbox()->pending();
        $this->assertCount(1, $pending);
        $this->assertSame('/api/laravel-bugs/add', $pending[0]['path']);
        $this->assertSame('Boom', $pending[0]['payload']['message']);
    }

    public function test_ignores_when_errors_flag_off(): void
    {
        config(['bug-collector.errors.enabled' => false]);

        $this->reporter()->report(new RuntimeException('Boom'));

        $this->assertTrue($this->outbox()->isEmpty());
    }

    public function test_ignores_exceptions_from_our_own_namespace(): void
    {
        $own = new \BugCollector\Client\Tests\Fixtures\OwnException('loop');

        $this->reporter()->report($own);

        $this->assertTrue($this->outbox()->isEmpty());
    }
}
```

Créer aussi le fixture `tests/Fixtures/OwnException.php` — il doit vivre sous le namespace du package pour simuler une erreur "maison" :

```php
<?php

namespace BugCollector\Client\Tests\Fixtures;

use RuntimeException;

class OwnException extends RuntimeException {}
```

> La garde « namespace maison » teste le préfixe `BugCollector\Client\`. Le fixture est sous `BugCollector\Client\Tests\Fixtures\`, donc bien couvert par le préfixe.

- [ ] **Step 2: Vérifier l'échec**

Run: `vendor/bin/phpunit --filter ErrorReporterTest`
Expected: FAIL (`Class "...ErrorReporter" not found`).

- [ ] **Step 3: Implémenter `ErrorReporter`**

```php
<?php

namespace BugCollector\Client\Reporting;

use BugCollector\Client\Outbox;
use BugCollector\Client\Payloads\BugPayload;
use BugCollector\Client\Support\Gate;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Throwable;

class ErrorReporter
{
    private bool $reporting = false;

    public function __construct(
        private Gate $gate,
        private Outbox $outbox,
        private Repository $config,
        private Container $app,
    ) {}

    public function report(Throwable $e): void
    {
        // Garde de ré-entrance : si notre propre code de report lève, ne pas
        // re-capter (boucle infinie via le handler décoré).
        if ($this->reporting) {
            return;
        }

        // Ignore toute exception issue de notre namespace.
        if (str_starts_with($e::class, 'BugCollector\\Client\\')) {
            return;
        }

        if (! $this->gate->shouldReportErrors()) {
            return;
        }

        $this->reporting = true;

        try {
            $depth = (int) $this->config->get('bug-collector.errors.trace_depth', 50);

            $this->outbox->record(
                (string) $this->config->get('bug-collector.endpoints.errors'),
                BugPayload::fromThrowable($e, $this->currentRequest(), $depth),
            );
        } catch (Throwable) {
            // Fail-safe.
        } finally {
            $this->reporting = false;
        }
    }

    private function currentRequest(): ?Request
    {
        try {
            if (! $this->app->bound('request')) {
                return null;
            }

            $request = $this->app->make('request');

            return $request instanceof Request ? $request : null;
        } catch (Throwable) {
            return null;
        }
    }
}
```

- [ ] **Step 4: Vérifier le succès**

Run: `vendor/bin/phpunit --filter ErrorReporterTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Reporting/ErrorReporter.php tests/Unit/ErrorReporterTest.php tests/Fixtures/OwnException.php
git commit -m "Add ErrorReporter with reentrancy guard"
```

---

## Task 9: HandlerDecorator

**Files:**
- Create: `src/Instrumentation/HandlerDecorator.php`
- Test: `tests/Unit/HandlerDecoratorTest.php`

**Interfaces:**
- Consumes: `ErrorReporter::report`, contrat `Illuminate\Contracts\Debug\ExceptionHandler`.
- Produces: `HandlerDecorator::__construct(ExceptionHandler $inner, ErrorReporter $reporter)` implémentant `ExceptionHandler` (report, shouldReport, render, renderForConsole).

- [ ] **Step 1: Écrire les tests**

`tests/Unit/HandlerDecoratorTest.php` :

```php
<?php

namespace BugCollector\Client\Tests\Unit;

use BugCollector\Client\Instrumentation\HandlerDecorator;
use BugCollector\Client\Reporting\ErrorReporter;
use BugCollector\Client\Tests\TestCase;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Mockery;
use RuntimeException;

class HandlerDecoratorTest extends TestCase
{
    public function test_report_calls_reporter_then_delegates_to_inner(): void
    {
        $e = new RuntimeException('Boom');

        $inner = Mockery::mock(ExceptionHandler::class);
        $inner->shouldReceive('report')->once()->with($e);

        $reporter = Mockery::mock(ErrorReporter::class);
        $reporter->shouldReceive('report')->once()->with($e);

        (new HandlerDecorator($inner, $reporter))->report($e);
    }

    public function test_report_still_delegates_when_reporter_throws(): void
    {
        $e = new RuntimeException('Boom');

        $inner = Mockery::mock(ExceptionHandler::class);
        $inner->shouldReceive('report')->once()->with($e);

        $reporter = Mockery::mock(ErrorReporter::class);
        $reporter->shouldReceive('report')->andThrow(new RuntimeException('reporter exploded'));

        // Ne doit pas lever : la délégation au handler d'origine reste garantie.
        (new HandlerDecorator($inner, $reporter))->report($e);
    }

    public function test_render_and_shouldreport_delegate(): void
    {
        $e = new RuntimeException('Boom');
        $request = Mockery::mock(\Illuminate\Http\Request::class);

        $inner = Mockery::mock(ExceptionHandler::class);
        $inner->shouldReceive('shouldReport')->once()->with($e)->andReturnTrue();
        $inner->shouldReceive('render')->once()->with($request, $e)->andReturn('rendered');

        $reporter = Mockery::mock(ErrorReporter::class);

        $decorator = new HandlerDecorator($inner, $reporter);

        $this->assertTrue($decorator->shouldReport($e));
        $this->assertSame('rendered', $decorator->render($request, $e));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Vérifier l'échec**

Run: `vendor/bin/phpunit --filter HandlerDecoratorTest`
Expected: FAIL (`Class "...HandlerDecorator" not found`).

- [ ] **Step 3: Implémenter `HandlerDecorator`**

```php
<?php

namespace BugCollector\Client\Instrumentation;

use BugCollector\Client\Reporting\ErrorReporter;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

class HandlerDecorator implements ExceptionHandler
{
    public function __construct(
        private ExceptionHandler $inner,
        private ErrorReporter $reporter,
    ) {}

    public function report(Throwable $e): void
    {
        try {
            $this->reporter->report($e);
        } catch (Throwable) {
            // Notre reporting ne doit jamais empêcher la délégation.
        }

        $this->inner->report($e);
    }

    public function shouldReport(Throwable $e): bool
    {
        return $this->inner->shouldReport($e);
    }

    public function render($request, Throwable $e)
    {
        return $this->inner->render($request, $e);
    }

    public function renderForConsole($output, Throwable $e): void
    {
        $this->inner->renderForConsole($output, $e);
    }
}
```

- [ ] **Step 4: Vérifier le succès**

Run: `vendor/bin/phpunit --filter HandlerDecoratorTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Instrumentation/HandlerDecorator.php tests/Unit/HandlerDecoratorTest.php
git commit -m "Add ExceptionHandler decorator"
```

---

## Task 10: ServiceProvider — bindings, décoration, flush terminating

**Files:**
- Modify: `src/BugCollectorServiceProvider.php`
- Create: `src/Instrumentation/Registrar.php` (squelette ; collecteurs branchés aux tasks suivantes)
- Test: `tests/Feature/ErrorReportingTest.php`

**Interfaces:**
- Consumes: tout ce qui précède.
- Produces: bindings singletons (`Gate`, `Outbox`, `Transport`→`HttpTransport`, `ErrorReporter`, `PerformanceReporter`, collecteurs, `Registrar`) ; décoration de `ExceptionHandler` ; callback `terminating` qui vide l'`Outbox`. `Registrar::register(): void` (vide pour l'instant).

- [ ] **Step 1: Écrire le test feature (chemin erreur bout-en-bout)**

`tests/Feature/ErrorReportingTest.php` :

```php
<?php

namespace BugCollector\Client\Tests\Feature;

use BugCollector\Client\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;use RuntimeException;

class ErrorReportingTest extends TestCase
{
    public function test_an_uncaught_exception_is_reported_after_response(): void
    {
        Http::fake();

        Route::get('/boom', function () {
            throw new RuntimeException('Kaboom');
        });

        $this->get('/boom'); // 500 attendu

        Http::assertSent(function ($request) {
            return $request->url() === 'https://collector.test/api/laravel-bugs/add'
                && $request->hasHeader('X-Ingest-Key', 'test-key')
                && $request['message'] === 'Kaboom'
                && $request['type'] === 500;
        });
    }

    public function test_nothing_is_sent_when_globally_disabled(): void
    {
        config(['bug-collector.base_url' => null]);
        Http::fake();

        Route::get('/boom', function () {
            throw new RuntimeException('Kaboom');
        });

        $this->get('/boom');

        Http::assertNothingSent();
    }
}
```

- [ ] **Step 2: Vérifier l'échec**

Run: `vendor/bin/phpunit --filter ErrorReportingTest`
Expected: FAIL (pas de requête envoyée — bindings/décoration/flush absents).

- [ ] **Step 3: Créer le squelette `Registrar`**

```php
<?php

namespace BugCollector\Client\Instrumentation;

use BugCollector\Client\Support\Gate;
use Illuminate\Contracts\Container\Container;

class Registrar
{
    public function __construct(
        private Container $app,
        private Gate $gate,
    ) {}

    /**
     * Branche les collecteurs de performance activés. Rempli au fil des tasks
     * 11 à 14.
     */
    public function register(): void
    {
        if (! $this->gate->shouldReportPerformance()) {
            return;
        }

        // Les collecteurs seront ajoutés ici (request, command, job, query).
    }
}
```

- [ ] **Step 4: Réécrire `BugCollectorServiceProvider`**

```php
<?php

namespace BugCollector\Client;

use BugCollector\Client\Instrumentation\HandlerDecorator;
use BugCollector\Client\Instrumentation\Registrar;
use BugCollector\Client\Reporting\ErrorReporter;
use BugCollector\Client\Support\Gate;
use BugCollector\Client\Transport\HttpTransport;
use BugCollector\Client\Transport\Transport;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;

class BugCollectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bug-collector.php', 'bug-collector');

        $this->app->singleton(Gate::class);
        $this->app->singleton(Outbox::class);
        $this->app->singleton(Transport::class, HttpTransport::class);
        $this->app->singleton(ErrorReporter::class);
        $this->app->singleton(Registrar::class);

        // Décore le handler d'exceptions de l'app : report() passe par notre
        // reporter puis délègue toujours à l'original. Zéro config côté client.
        $this->app->extend(ExceptionHandler::class, function (ExceptionHandler $handler, Container $app) {
            return new HandlerDecorator($handler, $app->make(ErrorReporter::class));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/bug-collector.php' => config_path('bug-collector.php'),
        ], 'bug-collector-config');

        if (! $this->app->make(Gate::class)->enabled()) {
            return;
        }

        // Vide l'Outbox après la réponse (web) ou après la commande (console).
        $this->app->terminating(function () {
            $outbox = $this->app->make(Outbox::class);

            if (! $outbox->isEmpty()) {
                $outbox->flush($this->app->make(Transport::class));
            }
        });

        $this->app->make(Registrar::class)->register();
    }
}
```

- [ ] **Step 5: Vérifier le succès**

Run: `vendor/bin/phpunit --filter ErrorReportingTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Lancer toute la suite**

Run: `vendor/bin/phpunit`
Expected: PASS (tous les tests précédents inclus).

- [ ] **Step 7: Commit**

```bash
git add src/BugCollectorServiceProvider.php src/Instrumentation/Registrar.php tests/Feature/ErrorReportingTest.php
git commit -m "Wire service provider: bindings, handler decoration, terminating flush"
```

---

## Task 11: RequestTiming (middleware)

**Files:**
- Create: `src/Instrumentation/RequestTiming.php`
- Modify: `src/BugCollectorServiceProvider.php` (binding singleton)
- Modify: `src/Instrumentation/Registrar.php` (push middleware)
- Test: `tests/Feature/RequestTimingTest.php`

**Interfaces:**
- Consumes: `PerformanceReporter::report`.
- Produces: middleware `RequestTiming` avec `handle(Request, Closure)` + `terminate(Request, $response): void`. Le timing est stocké dans `$request->attributes` (le middleware est ré-résolu pour `terminate`).

- [ ] **Step 1: Écrire le test feature**

`tests/Feature/RequestTimingTest.php` :

```php
<?php

namespace BugCollector\Client\Tests\Feature;

use BugCollector\Client\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

class RequestTimingTest extends TestCase
{
    public function test_a_slow_request_is_reported(): void
    {
        config(['bug-collector.performance.thresholds.request' => 0]); // tout qualifie
        Http::fake();

        Route::get('/slow', fn () => 'ok');

        $this->get('/slow')->assertOk();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://collector.test/api/laravel-perf/add'
                && $request['kind'] === 'request'
                && str_contains($request['label'], 'slow');
        });
    }

    public function test_a_fast_request_under_threshold_is_not_reported(): void
    {
        config(['bug-collector.performance.thresholds.request' => 999999]);
        Http::fake();

        Route::get('/fast', fn () => 'ok');

        $this->get('/fast')->assertOk();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'laravel-perf'));
    }
}
```

- [ ] **Step 2: Vérifier l'échec**

Run: `vendor/bin/phpunit --filter RequestTimingTest`
Expected: FAIL (`Class "...RequestTiming" not found` ou rien envoyé).

- [ ] **Step 3: Implémenter `RequestTiming`**

```php
<?php

namespace BugCollector\Client\Instrumentation;

use BugCollector\Client\Reporting\PerformanceReporter;
use Closure;
use Illuminate\Http\Request;
use Throwable;

class RequestTiming
{
    private const START_KEY = 'bugcollector_start';

    public function __construct(private PerformanceReporter $reporter) {}

    public function handle(Request $request, Closure $next)
    {
        $request->attributes->set(self::START_KEY, microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        try {
            $start = $request->attributes->get(self::START_KEY);

            if ($start === null) {
                return;
            }

            $ms = (int) ((microtime(true) - (float) $start) * 1000);
            $label = $request->method().' '.($request->route()?->uri() ?? $request->path());

            $this->reporter->report('request', $label, $ms);
        } catch (Throwable) {
            // Fail-safe.
        }
    }
}
```

- [ ] **Step 4: Binder le middleware en singleton**

Dans `BugCollectorServiceProvider::register()`, ajouter après le binding de `Registrar` :

```php
        $this->app->singleton(\BugCollector\Client\Instrumentation\RequestTiming::class);
        $this->app->singleton(\BugCollector\Client\Reporting\PerformanceReporter::class);
```

- [ ] **Step 5: Pousser le middleware dans le `Registrar`**

Remplacer le corps de `Registrar::register()` (le commentaire placeholder) par :

```php
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);

        if (method_exists($kernel, 'pushMiddleware')) {
            $kernel->pushMiddleware(RequestTiming::class);
        }
```

Et ajouter l'import en tête du fichier : `use Illuminate\Contracts\Http\Kernel;` n'est pas requis (on référence via FQN dans `make`), mais ajouter `use` pour `RequestTiming` n'est pas nécessaire car même namespace.

- [ ] **Step 6: Vérifier le succès**

Run: `vendor/bin/phpunit --filter RequestTimingTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add src/Instrumentation/RequestTiming.php src/Instrumentation/Registrar.php src/BugCollectorServiceProvider.php tests/Feature/RequestTimingTest.php
git commit -m "Add RequestTiming middleware"
```

---

## Task 12: CommandTiming (events console)

**Files:**
- Create: `src/Instrumentation/CommandTiming.php`
- Modify: `src/BugCollectorServiceProvider.php` (binding singleton)
- Modify: `src/Instrumentation/Registrar.php` (listeners)
- Test: `tests/Feature/CommandTimingTest.php`

**Interfaces:**
- Consumes: events `Illuminate\Console\Events\CommandStarting`, `CommandFinished` ; `PerformanceReporter::report`.
- Produces: `CommandTiming` singleton avec `starting(CommandStarting): void` et `finished(CommandFinished): void`.

- [ ] **Step 1: Écrire le test**

`tests/Feature/CommandTimingTest.php` :

```php
<?php

namespace BugCollector\Client\Tests\Feature;

use BugCollector\Client\Instrumentation\CommandTiming;
use BugCollector\Client\Outbox;
use BugCollector\Client\Tests\TestCase;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class CommandTimingTest extends TestCase
{
    public function test_a_slow_command_is_recorded(): void
    {
        config(['bug-collector.performance.thresholds.command' => 0]);

        $collector = $this->app->make(CommandTiming::class);
        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $collector->starting(new CommandStarting('app:sync', $input, $output));
        $collector->finished(new CommandFinished('app:sync', $input, $output, 0));

        $pending = $this->app->make(Outbox::class)->pending();
        $this->assertCount(1, $pending);
        $this->assertSame('command', $pending[0]['payload']['kind']);
        $this->assertSame('app:sync', $pending[0]['payload']['label']);
    }

    public function test_finished_without_start_records_nothing(): void
    {
        config(['bug-collector.performance.thresholds.command' => 0]);

        $collector = $this->app->make(CommandTiming::class);
        $collector->finished(new CommandFinished('app:orphan', new ArrayInput([]), new BufferedOutput(), 0));

        $this->assertTrue($this->app->make(Outbox::class)->isEmpty());
    }
}
```

- [ ] **Step 2: Vérifier l'échec**

Run: `vendor/bin/phpunit --filter CommandTimingTest`
Expected: FAIL (`Class "...CommandTiming" not found`).

- [ ] **Step 3: Implémenter `CommandTiming`**

```php
<?php

namespace BugCollector\Client\Instrumentation;

use BugCollector\Client\Reporting\PerformanceReporter;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;

class CommandTiming
{
    /** @var array<string, float> */
    private array $started = [];

    public function __construct(private PerformanceReporter $reporter) {}

    public function starting(CommandStarting $event): void
    {
        if ($event->command !== null) {
            $this->started[$event->command] = microtime(true);
        }
    }

    public function finished(CommandFinished $event): void
    {
        if ($event->command === null || ! isset($this->started[$event->command])) {
            return;
        }

        $ms = (int) ((microtime(true) - $this->started[$event->command]) * 1000);
        unset($this->started[$event->command]);

        $this->reporter->report('command', $event->command, $ms);
    }
}
```

- [ ] **Step 4: Binder en singleton**

Dans `register()`, ajouter :

```php
        $this->app->singleton(\BugCollector\Client\Instrumentation\CommandTiming::class);
```

- [ ] **Step 5: Brancher les listeners dans `Registrar`**

Ajouter en fin de `Registrar::register()` :

```php
        $events = $this->app->make(\Illuminate\Contracts\Events\Dispatcher::class);

        $events->listen(\Illuminate\Console\Events\CommandStarting::class, [CommandTiming::class, 'starting']);
        $events->listen(\Illuminate\Console\Events\CommandFinished::class, [CommandTiming::class, 'finished']);
```

> Les listeners en `[Class::class, 'method']` sont résolus via le container ; comme `CommandTiming` est singleton, l'état `$started` est partagé entre `starting` et `finished`.

- [ ] **Step 6: Vérifier le succès**

Run: `vendor/bin/phpunit --filter CommandTimingTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add src/Instrumentation/CommandTiming.php src/Instrumentation/Registrar.php src/BugCollectorServiceProvider.php tests/Feature/CommandTimingTest.php
git commit -m "Add CommandTiming collector"
```

---

## Task 13: JobTiming (events queue + flush immédiat)

**Files:**
- Create: `src/Instrumentation/JobTiming.php`
- Modify: `src/BugCollectorServiceProvider.php` (binding singleton)
- Modify: `src/Instrumentation/Registrar.php` (listeners)
- Test: `tests/Feature/JobTimingTest.php`

**Interfaces:**
- Consumes: events `Illuminate\Queue\Events\JobProcessing`, `JobProcessed`, `JobFailed` ; `PerformanceReporter`, `Outbox`, `Transport`.
- Produces: `JobTiming` singleton : `processing(JobProcessing): void`, `processed(JobProcessed): void`, `failed(JobFailed): void`. Vide l'`Outbox` immédiatement après chaque job (worker long-vivant).

- [ ] **Step 1: Écrire le test**

`tests/Feature/JobTimingTest.php` :

```php
<?php

namespace BugCollector\Client\Tests\Feature;

use BugCollector\Client\Instrumentation\JobTiming;
use BugCollector\Client\Tests\TestCase;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Http;
use Mockery;

class JobTimingTest extends TestCase
{
    public function test_a_slow_job_is_reported_and_flushed_immediately(): void
    {
        config(['bug-collector.performance.thresholds.job' => 0]);
        Http::fake();

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('getJobId')->andReturn('job-1');
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SendInvoice');

        $collector = $this->app->make(JobTiming::class);
        $collector->processing(new JobProcessing('sync', $job));
        $collector->processed(new JobProcessed('sync', $job));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://collector.test/api/laravel-perf/add'
                && $request['kind'] === 'job'
                && $request['label'] === 'App\\Jobs\\SendInvoice';
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Vérifier l'échec**

Run: `vendor/bin/phpunit --filter JobTimingTest`
Expected: FAIL (`Class "...JobTiming" not found`).

- [ ] **Step 3: Implémenter `JobTiming`**

```php
<?php

namespace BugCollector\Client\Instrumentation;

use BugCollector\Client\Outbox;
use BugCollector\Client\Reporting\PerformanceReporter;
use BugCollector\Client\Transport\Transport;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

class JobTiming
{
    /** @var array<string, float> */
    private array $started = [];

    public function __construct(
        private PerformanceReporter $reporter,
        private Outbox $outbox,
        private Transport $transport,
    ) {}

    public function processing(JobProcessing $event): void
    {
        $this->started[$event->job->getJobId()] = microtime(true);
    }

    public function processed(JobProcessed $event): void
    {
        $this->finish($event->job);
    }

    public function failed(JobFailed $event): void
    {
        $this->finish($event->job);
    }

    private function finish(Job $job): void
    {
        $id = $job->getJobId();

        if (! isset($this->started[$id])) {
            return;
        }

        $ms = (int) ((microtime(true) - $this->started[$id]) * 1000);
        unset($this->started[$id]);

        $this->reporter->report('job', $job->resolveName(), $ms);

        // Le worker est long-vivant : terminating ne se déclenchera pas par job.
        if (! $this->outbox->isEmpty()) {
            $this->outbox->flush($this->transport);
        }
    }
}
```

- [ ] **Step 4: Binder en singleton**

Dans `register()`, ajouter :

```php
        $this->app->singleton(\BugCollector\Client\Instrumentation\JobTiming::class);
```

- [ ] **Step 5: Brancher les listeners dans `Registrar`**

Ajouter en fin de `Registrar::register()` :

```php
        $events->listen(\Illuminate\Queue\Events\JobProcessing::class, [JobTiming::class, 'processing']);
        $events->listen(\Illuminate\Queue\Events\JobProcessed::class, [JobTiming::class, 'processed']);
        $events->listen(\Illuminate\Queue\Events\JobFailed::class, [JobTiming::class, 'failed']);
```

- [ ] **Step 6: Vérifier le succès**

Run: `vendor/bin/phpunit --filter JobTimingTest`
Expected: PASS (1 test).

- [ ] **Step 7: Commit**

```bash
git add src/Instrumentation/JobTiming.php src/Instrumentation/Registrar.php src/BugCollectorServiceProvider.php tests/Feature/JobTimingTest.php
git commit -m "Add JobTiming collector with immediate flush"
```

---

## Task 14: QueryTiming (QueryExecuted)

**Files:**
- Create: `src/Instrumentation/QueryTiming.php`
- Modify: `src/BugCollectorServiceProvider.php` (binding singleton)
- Modify: `src/Instrumentation/Registrar.php` (listener)
- Test: `tests/Feature/QueryTimingTest.php`

**Interfaces:**
- Consumes: event `Illuminate\Database\Events\QueryExecuted` (`->sql`, `->time` en ms) ; `PerformanceReporter`.
- Produces: `QueryTiming::handle(QueryExecuted): void`.

- [ ] **Step 1: Écrire le test**

`tests/Feature/QueryTimingTest.php` (utilise la sqlite en mémoire du `TestCase` : une vraie requête émet `QueryExecuted`) :

```php
<?php

namespace BugCollector\Client\Tests\Feature;

use BugCollector\Client\Outbox;
use BugCollector\Client\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class QueryTimingTest extends TestCase
{
    public function test_a_slow_query_is_recorded(): void
    {
        config(['bug-collector.performance.thresholds.query' => 0]); // tout qualifie

        DB::select('select 1 as n');

        $pending = $this->app->make(Outbox::class)->pending();
        $this->assertNotEmpty($pending);
        $this->assertSame('query', $pending[0]['payload']['kind']);
        $this->assertStringContainsString('select 1', $pending[0]['payload']['label']);
    }

    public function test_a_fast_query_under_threshold_is_ignored(): void
    {
        config(['bug-collector.performance.thresholds.query' => 999999]);

        DB::select('select 1 as n');

        $this->assertTrue($this->app->make(Outbox::class)->isEmpty());
    }
}
```

- [ ] **Step 2: Vérifier l'échec**

Run: `vendor/bin/phpunit --filter QueryTimingTest`
Expected: FAIL (`Class "...QueryTiming" not found`).

- [ ] **Step 3: Implémenter `QueryTiming`**

```php
<?php

namespace BugCollector\Client\Instrumentation;

use BugCollector\Client\Reporting\PerformanceReporter;
use Illuminate\Database\Events\QueryExecuted;

class QueryTiming
{
    public function __construct(private PerformanceReporter $reporter) {}

    public function handle(QueryExecuted $event): void
    {
        // $event->time est en millisecondes ; $event->sql garde les placeholders ?
        // (bindings non interpolés => label naturellement normalisé).
        $this->reporter->report('query', $event->sql, (int) $event->time);
    }
}
```

- [ ] **Step 4: Binder en singleton**

Dans `register()`, ajouter :

```php
        $this->app->singleton(\BugCollector\Client\Instrumentation\QueryTiming::class);
```

- [ ] **Step 5: Brancher le listener dans `Registrar`**

Ajouter en fin de `Registrar::register()` :

```php
        $events->listen(\Illuminate\Database\Events\QueryExecuted::class, [QueryTiming::class, 'handle']);
```

- [ ] **Step 6: Vérifier le succès**

Run: `vendor/bin/phpunit --filter QueryTimingTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Lancer toute la suite**

Run: `vendor/bin/phpunit`
Expected: PASS (tout vert).

- [ ] **Step 8: Commit**

```bash
git add src/Instrumentation/QueryTiming.php src/Instrumentation/Registrar.php src/BugCollectorServiceProvider.php tests/Feature/QueryTimingTest.php
git commit -m "Add QueryTiming collector"
```

---

## Task 15: Finitions — README, format, suite complète

**Files:**
- Modify: `README.md`
- Modify: `CLAUDE.md` (refléter `base_url` + état "implémenté")

**Interfaces:** aucune (documentation + qualité).

- [ ] **Step 1: Mettre à jour le README**

Dans `README.md`, remplacer le bloc `.env` cible par :

```env
BUG_COLLECTOR_URL="https://<host-bug-collector>"
BUG_COLLECTOR_INGEST_KEY="<clé générée dans le panneau Bug Collector>"
```

Et corriger la phrase d'intro pour indiquer que `BUG_COLLECTOR_URL` est désormais la **racine** du service (le client ajoute `/api/laravel-bugs/add` et `/api/laravel-perf/add`). Retirer la mention « 🚧 En cours de conception » et la remplacer par une courte note d'usage : auth via header `X-Ingest-Key`, envoi fail-safe, seuils configurables.

- [ ] **Step 2: Mettre à jour `CLAUDE.md`**

Mettre à jour la section "Status" pour indiquer que l'instrumentation et le transport sont implémentés, et remplacer toute référence à la config `url` par `base_url` + `endpoints`.

- [ ] **Step 3: Formater avec Pint**

Run: `vendor/bin/pint`
Expected: fichiers reformatés sans erreur.

- [ ] **Step 4: Vérifier le format**

Run: `vendor/bin/pint --test`
Expected: PASS (aucun diff).

- [ ] **Step 5: Lancer toute la suite une dernière fois**

Run: `vendor/bin/phpunit`
Expected: PASS (tout vert).

- [ ] **Step 6: Commit**

```bash
git add README.md CLAUDE.md src/ config/
git commit -m "Update docs and apply Pint formatting"
```

---

## Notes de réalisation

- **Ordre Outbox/Transport** : la Task 5 (Outbox) type-hint l'interface `Transport` créée en Task 6. Si exécutées strictement dans l'ordre, créer l'interface (Task 6 Step 3) avant de lancer les tests de la Task 5, ou exécuter Task 6 immédiatement après. Les deux sont de petites tasks couplées.
- **Réduction assumée** : pour les payloads d'**erreur** hors-web, `url='console'` / `ip='127.0.0.1'` (cf. note Task 3). Les labels fins (`artisan:`/`job:`) restent portés par les events perf.
- **Pas de retry/persistance/batching** (YAGNI, cf. spec) : un envoi raté est perdu, le serveur déduplique de toute façon.
