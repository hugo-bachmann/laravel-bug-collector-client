# Améliorations v1.1 (client + web app) — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Objectif :** livrer les 5 améliorations prioritaires : (1) classe d'exception + chaîne `previous` + chemins relatifs, (2) coupe-circuit au flush, (3) mesure correcte des requêtes, (4) `context` des requêtes SQL, (5) commande `bug-collector:test`, ainsi que l'adaptation de la web app.

**Architecture :** deux dépôts, une branche + une PR chacun. La web app (`~/Documents/Dev/bug-collector`) accepte d'abord les nouveaux champs de façon **rétro-compatible** : les clients v1.0.x continuent de fonctionner et gardent les mêmes empreintes. Ensuite le client (`~/Documents/Dev/laravel-bug-collector-client`) est publié en v1.1.0.

**Stack :** PHP 8.2+, Laravel 12/13, Orchestra Testbench (client), PHPUnit 12 + Filament (web app).

**Spec :** les propositions de la conversation du 2026-11-10 (aucun document séparé) ; les décisions sont figées ci-dessous.

## Contraintes globales

- Le client reste **fail-safe** : aucune exception ne doit remonter dans l'app hôte (try/catch existants conservés).
- Commentaires, docs et messages de commande **en français**.
- Le payload n'évolue que par **ajout de champs** ; aucun champ existant n'est renommé ni supprimé.
- Client : `vendor/bin/phpunit` et `vendor/bin/pint --test` verts avant chaque commit.
- Web app : `php artisan test` et `vendor/bin/pint --test` verts avant chaque commit.
- Ordre de déploiement : **web app en prod d'abord**, puis tag client `v1.1.0`.

## Points d'attention en revue

1. Les clients v1.0.x n'envoient ni `class` ni `previous` : leur empreinte doit rester **identique** à l'actuelle (test en W1).
2. Collecteur injoignable : le flush s'arrête au premier échec au lieu d'attendre N × timeout (test en C1).
3. `REQUEST_TIME_FLOAT` absent ou aberrant (Octane : vieux de plusieurs heures, ou dans le futur) : repli sur le début du middleware (test en C2).
4. Chaîne `previous` cyclique ou très profonde : plafond de 5 maillons (test en C3).
5. Requête SQL lancée uniquement depuis `vendor/` (migrations, internals de queue) : `caller` vaut `null`, sans plantage (test en C4).

---

## Partie A — Web app (`~/Documents/Dev/bug-collector`)

Branche : `feat/client-v1-1-fields`.

### Task 1 (W1) : stocker `class` et `previous`, empreinte basée sur la classe

**Fichiers :**
- Créer : `database/migrations/2026_11_10_000001_add_exception_class_and_previous_to_laravel_bugs.php`
- Modifier : `app/Http/Requests/StoreLaravelBugRequest.php` (rules)
- Modifier : `app/Models/LaravelBug.php` (fillable, casts, `fingerprintFor`)
- Modifier : `app/Http/Controllers/ApiController.php:41-87` (`storeLaravelBug`)
- Modifier : `app/Http/Controllers/Api/V1/DesktopController.php:190` (`bugJson`)
- Tests : `tests/Feature/LaravelBugFingerprintTest.php`, `tests/Feature/IngestLaravelBugTest.php`, `tests/Feature/StoreLaravelBugRequestTest.php`

**Interfaces :**
- Produit : colonnes `laravel_bugs.exception_class` (string 255, nullable) et `laravel_bugs.previous` (json, nullable) ; attributs Eloquent `exception_class`, `previous` (cast `json`).
- Produit : `LaravelBug::fingerprintFor(int $type, string $file, int $line, string $message, ?string $class = null): string`.
- Contrat d'entrée : `class` (`nullable|string|max:255`), `previous` (`nullable|array|max:10` + `new WithinByteSize(16 * 1024)`) ; chaque élément vaut `{class, message, file, line}`.
- Produit : `bugJson()` ajoute `'exception_class'` et `'previous' => $bug->previous ?? []`.

- [ ] **Étape 1 : tests en échec**

Dans `LaravelBugFingerprintTest` :
```php
public function test_legacy_fingerprint_is_unchanged_without_class(): void
{
    $this->assertSame(sha1('500|/app/Foo.php|42|Boom'), LaravelBug::fingerprintFor(500, '/app/Foo.php', 42, 'Boom'));
}

public function test_class_distinguishes_fingerprints(): void
{
    $a = LaravelBug::fingerprintFor(500, 'app/Foo.php', 42, 'Boom', 'RuntimeException');
    $b = LaravelBug::fingerprintFor(500, 'app/Foo.php', 42, 'Boom', 'LogicException');
    $this->assertNotSame($a, $b);
    $this->assertNotSame(LaravelBug::fingerprintFor(500, 'app/Foo.php', 42, 'Boom'), $a);
}
```
Dans `IngestLaravelBugTest` : `test_class_and_previous_are_stored` (POST de `payload(['class' => 'RuntimeException', 'previous' => [['class' => 'PDOException', 'message' => 'x', 'file' => 'app/A.php', 'line' => 3]]])`, puis assertion que `exception_class === 'RuntimeException'` et `previous[0]['class'] === 'PDOException'`) et `test_legacy_payload_without_class_still_202`.
Dans `StoreLaravelBugRequestTest` : `previous` comportant 11 éléments → 422.

- [ ] **Étape 2 :** `php artisan test --filter 'LaravelBugFingerprint|IngestLaravelBug|StoreLaravelBugRequest'` → les nouveaux tests ÉCHOUENT.
- [ ] **Étape 3 : implémentation.** Corps de l'empreinte : `$class === null ? sha1("{$type}|{$file}|{$line}|{$message}") : sha1("{$class}|{$type}|{$file}|{$line}|{$message}")`. Le contrôleur passe `$request->input('class')` à `fingerprintFor` et stocke `exception_class` et `previous` dans `create()`.
- [ ] **Étape 4 :** relancer la même commande → PASS, puis `php artisan test` complet → PASS.
- [ ] **Étape 5 : commit** `Accepte la classe d'exception et la chaîne previous (empreinte rétro-compatible)`.

### Task 2 (W2) : affichage dans Filament

**Fichiers :**
- Modifier : `resources/views/filament/resources/laravel-bugs/error-detail.blade.php`
- Modifier : `resources/views/filament/resources/performance-events/perf-detail.blade.php:16-26` (`$contextRows`)
- Tests : `tests/Feature/ErrorDetailPageTest.php`, `tests/Feature/PerformanceEventResourceTest.php`

**Interfaces :**
- Consomme : `exception_class` et `previous` (W1) ; les clés de context `origin` et `caller` (C4).

- [ ] **Étape 1 : tests en échec.**
  - `ErrorDetailPageTest::test_exception_class_and_causes_are_shown` : un bug avec `exception_class = 'App\Exceptions\Boom'` et un `previous` dont le message vaut `'cause racine'` → la page affiche `'App\Exceptions\Boom'` et `'cause racine'`.
  - `PerformanceEventResourceTest::test_origin_and_caller_are_shown` : un `context` de `['origin' => 'GET users', 'caller' => 'app/Http/Controllers/UserController.php:42']` → les deux valeurs sont affichées.
- [ ] **Étape 2 :** `php artisan test --filter 'ErrorDetailPage|PerformanceEventResource'` → ÉCHEC.
- [ ] **Étape 3 : implémentation.**
  - En-tête d'erreur : afficher `$bug->exception_class` dans `.cab-meta`, à côté de `Type {{ $bug->type }}`, uniquement s'il est renseigné.
  - Nouvelle carte « Causes » après la stack trace, uniquement si `previous` n'est pas vide : pour chaque maillon, `class`, `message` et `file:line`, en réutilisant les classes `cab-card` / `cab-kv`.
  - Perf : ajouter `'Origine' => $context['origin'] ?? null` et `'Appelé depuis' => $context['caller'] ?? null` à `$contextRows`.
- [ ] **Étape 4 :** relancer → PASS ; `php artisan test` complet → PASS.
- [ ] **Étape 5 : commit** `Affiche la classe, les causes et l'origine des requêtes lentes`, puis PR et **déploiement en prod avant la partie B**.

---

## Partie B — Client (`~/Documents/Dev/laravel-bug-collector-client`)

Branche : `feat/v1-1`. Avant de commencer : committer ou mettre de côté la modification en cours de `tests/Unit/PerformanceReporterTest.php`.

### Task 3 (C1) : coupe-circuit au flush

**Fichiers :**
- Modifier : `src/Transport/Transport.php`, `src/Transport/HttpTransport.php`, `src/Outbox.php:43-52`
- Tests : `tests/Unit/OutboxTest.php`, `tests/Unit/HttpTransportTest.php` (adapter le faux transport anonyme de `OutboxTest` pour qu'il retourne `true`)

**Interfaces :**
- Produit : `Transport::send(string $path, array $payload): bool`. `true` signifie que le collecteur a accepté (`$response->successful()`) ; `false` couvre une réponse non 2xx ou une exception (timeout, DNS…).
- Produit : `Outbox::flush()` s'arrête au premier `false` et **abandonne** le reste du lot (pas de remise en file : rester fail-safe et borné).

- [ ] **Étape 1 : tests en échec.**
  - `OutboxTest::test_flush_stops_after_first_failed_send` : 3 entrées, faux transport qui retourne `false` → 1 seul appel, puis `isEmpty() === true`.
  - `HttpTransportTest::test_send_returns_false_on_server_error` : `Http::fake(['*' => Http::response('', 500)])` → `false`.
  - `HttpTransportTest::test_send_returns_false_on_connection_error` : `Http::fake(fn () => throw new ConnectionException('down'))` → `false`.
  - `HttpTransportTest::test_send_returns_true_on_2xx` : réponse 202 → `true`.
- [ ] **Étape 2 :** `vendor/bin/phpunit --filter 'OutboxTest|HttpTransportTest'` → ÉCHEC.
- [ ] **Étape 3 :** implémenter.
- [ ] **Étape 4 :** relancer → PASS ; suite complète → PASS.
- [ ] **Étape 5 : commit** `Interrompt le flush dès que le collecteur ne répond plus`.

### Task 4 (C2) : mesure réelle des requêtes

**Fichiers :**
- Modifier : `src/Instrumentation/RequestTiming.php` (tout passe dans `handle()`, `terminate()` disparaît)
- Tests : `tests/Feature/RequestTimingTest.php`

**Interfaces :**
- `handle(Request $request, Closure $next): mixed` : début = `(float) $request->server('REQUEST_TIME_FLOAT')` s'il est présent, `<= microtime(true)` et datant de moins de 3600 s ; sinon `microtime(true)` à l'entrée du middleware. Fin = juste après `$next($request)`. On reporte avant de retourner la réponse, mais le `report()` ne fait qu'alimenter l'Outbox : l'envoi HTTP reste dans `terminating`. Exclusion des streams et des fichiers inchangée, try/catch fail-safe conservé autour de la mesure.
- La constante `START_KEY` et l'attribut de requête disparaissent.

- [ ] **Étape 1 : tests en échec.**
  - `test_duration_starts_at_request_time_float` : seuil de 1000 ms, `$this->call('GET', '/fast', server: ['REQUEST_TIME_FLOAT' => microtime(true) - 5])` → `Http::assertSent` avec `$request['duration_ms'] >= 5000`.
  - `test_stale_request_time_float_falls_back_to_middleware_start` : `REQUEST_TIME_FLOAT => microtime(true) - 7200` et seuil de 1000 ms → rien n'est envoyé.
- [ ] **Étape 2 :** `vendor/bin/phpunit --filter RequestTimingTest` → ÉCHEC.
- [ ] **Étape 3 :** implémenter.
- [ ] **Étape 4 :** relancer → PASS (les tests existants « streamed », « fast » et « slow » doivent toujours passer) ; suite complète → PASS.
- [ ] **Étape 5 : commit** `Mesure la durée des requêtes depuis REQUEST_TIME_FLOAT jusqu'à la réponse`.

### Task 5 (C3) : classe, chaîne `previous`, chemins relatifs

**Fichiers :**
- Modifier : `src/Payloads/BugPayload.php`, `src/Reporting/ErrorReporter.php:50-58`
- Tests : `tests/Unit/BugPayloadTest.php`, `tests/Feature/ErrorReportingTest.php`

**Interfaces :**
- Produit : `BugPayload::fromThrowable(Throwable $e, ?Request $request, int $traceDepth = 50, bool $includeCookies = false, array $redactCookieNames = [], string $basePath = ''): array`.
- Nouveaux champs : `'class' => $e::class` et `'previous' => list<array{class: string, message: string, file: string, line: int}>` (au plus 5 maillons, message tronqué à 1000 caractères ; `[]` s'il n'y a pas de cause).
- Chemins : `file`, `previous[*].file` et `stackTrace[*].file` deviennent relatifs quand ils commencent par `rtrim($basePath, '/').'/'` ; sinon (ou si `$basePath === ''`) ils restent intacts.
- `ErrorReporter` passe `base_path()`.

- [ ] **Étape 1 : tests en échec** dans `BugPayloadTest`.
  - `test_class_is_sent` : `new RuntimeException('x')` → `'class' === RuntimeException::class`.
  - `test_previous_chain_is_capped_at_five` : une chaîne de 7 exceptions imbriquées → `count(previous) === 5`, avec `previous[0]` = la cause directe.
  - `test_paths_are_made_relative_to_base_path` : `$basePath = dirname(__DIR__, 2)` → `file` vaut `'tests/Unit/BugPayloadTest.php'` et aucune frame de `stackTrace` ne commence par `$basePath`.
  - `test_paths_outside_base_path_stay_absolute` : `$basePath = '/nonexistent'` → `file === __FILE__`.
- [ ] **Étape 2 :** `vendor/bin/phpunit --filter BugPayloadTest` → ÉCHEC.
- [ ] **Étape 3 :** implémenter (une petite méthode privée `relative(string $path, string $base): string`, réutilisée aux trois endroits).
- [ ] **Étape 4 :** relancer → PASS ; suite complète → PASS.
- [ ] **Étape 5 : commit** `Envoie la classe, la chaîne previous et des chemins relatifs`.

### Task 6 (C4) : `context` des requêtes SQL lentes (calculé seulement au-delà du seuil)

**Fichiers :**
- Modifier : `src/Reporting/PerformanceReporter.php:34`, `src/Instrumentation/QueryTiming.php`, `src/Instrumentation/JobTiming.php`
- Tests : `tests/Unit/PerformanceReporterTest.php`, `tests/Feature/QueryTimingTest.php`

**Interfaces :**
- Produit : `PerformanceReporter::report(string $kind, string $label, int $durationMs, array|Closure|null $context = null): void`. Une `Closure` n'est appelée **qu'après** la vérification du seuil, pour que le backtrace ne coûte rien sur les requêtes rapides.
- Produit : `JobTiming::currentJob(): ?string`, qui donne le nom du job en cours (renseigné dans `processing`, remis à `null` dans `finish`).
- Context d'une requête SQL : `['connection' => $event->connectionName, 'origin' => ?string, 'caller' => ?string]`.
  - `origin` : `currentJob()` en priorité ; sinon, en HTTP (`! app()->runningInConsole()`), `METHOD uri-de-route` (même format que `RequestTiming`) ; sinon `$_SERVER['argv'][1] ?? 'console'`. Jamais l'argv complet, qui peut contenir des secrets.
  - `caller` : `QueryTiming::callerFrom(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50), base_path())`. Méthode `public static function callerFrom(array $frames, string $basePath): ?string` : première frame dont le `file` n'est ni dans `/vendor/` ni dans le `src/` du package (`dirname(__DIR__)`), au format `chemin-relatif:ligne` (même règle de chemin relatif que C3) ; `null` si aucune frame ne correspond.
  - `connection` est déjà affiché par la web app ; `origin` et `caller` le sont grâce à W2.

- [ ] **Étape 1 : tests en échec.**
  - `PerformanceReporterTest::test_context_closure_is_not_called_under_threshold` : une closure qui lève une exception, une durée sous le seuil → aucune exception et Outbox vide.
  - `QueryTimingTest::test_slow_query_context_has_connection_and_caller` : seuil de 1 ms, `event(new QueryExecuted('select 1', [], 50, DB::connection()))`, puis `Http::assertSent` avec `$r['context']['connection'] === 'testing'` et `str_contains($r['context']['caller'], 'QueryTimingTest.php')`.
  - `QueryTimingTest::test_caller_is_null_when_only_vendor_frames` : `QueryTiming::callerFrom([['file' => '/app/vendor/x/Y.php', 'line' => 1]], '/app')` → `null`, et `callerFrom([['file' => '/app/vendor/x/Y.php', 'line' => 1], ['file' => '/app/app/Foo.php', 'line' => 9]], '/app')` → `'app/Foo.php:9'`.
- [ ] **Étape 2 :** `vendor/bin/phpunit --filter 'PerformanceReporterTest|QueryTimingTest'` → ÉCHEC.
- [ ] **Étape 3 :** implémenter.
- [ ] **Étape 4 :** relancer → PASS ; suite complète → PASS.
- [ ] **Étape 5 : commit** `Ajoute connexion, origine et appelant aux requêtes SQL lentes`.

### Task 7 (C5) : commande `php artisan bug-collector:test`

**Fichiers :**
- Créer : `src/Console/TestCommand.php`
- Modifier : `src/Support/Gate.php` (extraire la raison), `src/BugCollectorServiceProvider.php:44` (enregistrer la commande **avant** le `return` anticipé du Gate)
- Tests : `tests/Feature/TestCommandTest.php`, `tests/Unit/GateTest.php`

**Interfaces :**
- Produit : `Gate::disabledReason(): ?string`, qui retourne `null` si tout est actif ; `enabled()` devient `return $this->disabledReason() === null;`. Messages exacts :
  - `'BUG_COLLECTOR_ENABLED est à false.'`
  - `'BUG_COLLECTOR_URL est manquant.'`
  - `'BUG_COLLECTOR_INGEST_KEY est manquant.'`
  - `'BUG_COLLECTOR_URL doit être en HTTPS (http toléré uniquement sur localhost).'`
- Commande `bug-collector:test` : si `disabledReason()` n'est pas `null`, elle l'affiche en erreur et retourne `1`. Sinon elle construit `BugPayload::fromThrowable(new RuntimeException('Bug Collector : exception de test'), null, basePath: base_path())`, l'envoie **directement** via `Transport::send()` (sans Outbox, pour connaître le résultat), affiche `'Exception de test envoyée.'` puis retourne `0`, ou bien affiche `'Le collecteur a refusé ou n'a pas répondu.'` et retourne `1`.
- Enregistrement : `if ($this->app->runningInConsole()) { $this->commands([TestCommand::class]); }`.

- [ ] **Étape 1 : tests en échec.**
  - `GateTest` : un test par message de `disabledReason()`.
  - `TestCommandTest::test_success` : `Http::fake(['*' => Http::response('', 202)])`, `artisan('bug-collector:test')->expectsOutputToContain('envoyée')->assertExitCode(0)` et `Http::assertSent` avec `$r['class'] === RuntimeException::class`.
  - `TestCommandTest::test_refused` : réponse 401 → code de sortie `1`.
  - `TestCommandTest::test_misconfigured` : `base_url` vide → affiche `'BUG_COLLECTOR_URL est manquant.'`, code de sortie `1`, `Http::assertNothingSent()`.
- [ ] **Étape 2 :** `vendor/bin/phpunit --filter 'GateTest|TestCommandTest'` → ÉCHEC.
- [ ] **Étape 3 :** implémenter.
- [ ] **Étape 4 :** relancer → PASS ; suite complète → PASS ; `vendor/bin/pint --test` → OK.
- [ ] **Étape 5 : commit** `Ajoute la commande bug-collector:test`.

### Task 8 (C6) : documentation et release

**Fichiers :** `README.md` (section Installation : mentionner `php artisan bug-collector:test` ; section Sécurité & confidentialité : chemins relatifs, `previous` sans arguments), `CHANGELOG.md` (entrée `1.1.0`), `CLAUDE.md` (Transport retourne `bool`, flush interrompu au premier échec, commande de test).

- [ ] **Étape 1 :** mettre à jour les trois fichiers, en français. Dans le CHANGELOG, signaler explicitement que **les empreintes d'erreurs changent une fois** (classe + chemins relatifs) : les erreurs existantes réapparaîtront comme nouvelles.
- [ ] **Étape 2 :** `vendor/bin/phpunit` et `vendor/bin/pint --test` → verts.
- [ ] **Étape 3 :** commit `Prépare la v1.1.0`, puis PR. Taguer `v1.1.0` **seulement après** le déploiement de la partie A en prod.
