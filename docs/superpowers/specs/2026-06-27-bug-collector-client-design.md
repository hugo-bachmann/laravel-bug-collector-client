# Bug Collector — Client Laravel : design

> Spec validée le 2026-06-27. Implémente l'instrumentation et le transport du package `bug-collector/laravel-client`, qui reporte erreurs et anomalies de performance à un service Bug Collector centralisé.

## Contexte

Le squelette du package existe (ServiceProvider auto-découvert, config publiable). Le serveur Bug Collector (`../bug-collector`, app Laravel) expose deux endpoints d'ingestion dont le contrat est figé. Cette spec couvre la construction du client : capter, filtrer et envoyer les événements, sans jamais impacter l'app hôte.

### Contrat serveur (figé — lu côté `../bug-collector`)

**Auth** : header HTTP `X-Ingest-Key: <clé en clair>`. Le serveur la hash en SHA-256 pour retrouver le `Site`. Réponses : `202 Accepted` en succès, `401` si clé invalide/absente, `422` si validation échoue. Throttle 60 req/min.

**`POST {base_url}/api/laravel-bugs/add`** — erreurs :

| champ | règle serveur |
|---|---|
| `message` | requis, string |
| `type` | requis, entier (= code HTTP, ex. `500`) |
| `file` | requis, string |
| `line` | requis, entier ≥ 0 |
| `stackTrace` | requis, **array** |
| `url` | requis, string ≤ 2000 |
| `ip` | requis, **IP valide** |
| `userAgent` | nullable, string |
| `username` | nullable, string |
| `userEmail` | nullable, email |
| `cookies` | nullable, array (le serveur rédige les sensibles) |

**`POST {base_url}/api/laravel-perf/add`** — performance :

| champ | règle serveur |
|---|---|
| `kind` | requis, `in: request,job,query,command` |
| `label` | requis, string ≤ 1000 |
| `duration_ms` | requis, entier ≥ 0 |
| `context` | nullable, array |

**Dédup et notifications sont côté serveur** (fingerprint + compteur d'occurrences). Le client se contente d'émettre chaque événement individuel.

## Décisions de design (validées)

1. **Transport async via `terminating()`** pour le web et les commandes (zéro latence ajoutée, pas de worker requis) ; envoi immédiat pour les jobs de queue (worker long-vivant).
2. **Fail-safe silencieux total** : toute erreur réseau/timeout est avalée. Une panne du collecteur n'altère jamais l'app hôte.
3. **Périmètre complet** : erreurs + 4 sondes perf (request, job, query, command) en une fois.
4. **Capture des erreurs par décoration de l'`ExceptionHandler`** : zéro config côté client (tient la promesse du README).

## Architecture

Trois couches découplées, chacune testable indépendamment :

```
Instrumentation (capte)  →  Reporting (construit + filtre)  →  Outbox (tampon)  →  Transport (envoie)
```

- **Instrumentation** : observe Laravel et produit des événements bruts.
- **Reporting** : transforme en payload conforme au contrat, applique seuils et kill-switches.
- **Outbox** : tampon mémoire des payloads en attente, drainé en fin de cycle de vie.
- **Transport** : POST HTTP, fail-safe.

### Stratégie de flush de l'Outbox

| Contexte | Quand l'Outbox est vidé |
|---|---|
| Requête HTTP | `app->terminating()` — après l'envoi de la réponse |
| Commande artisan | `app->terminating()` |
| Job de queue | immédiatement après le job (event `JobProcessed` / `JobFailed`) |

L'Outbox se draine à chaque `flush()`, donc aucun double envoi même si plusieurs déclencheurs coexistent.

## Composants (un fichier = une responsabilité)

```
src/
  BugCollectorServiceProvider.php      # register() : décore le handler + bind singletons ; boot() : wire l'instrumentation
  Support/Gate.php                     # "doit-on reporter ?" (enabled global, flags, base_url+key présents)
  Outbox.php                           # record(path, payload) + flush(Transport)
  Transport/Transport.php              # interface : send(string $path, array $payload)
  Transport/HttpTransport.php          # POST X-Ingest-Key, timeout court, try/catch silencieux
  Reporting/ErrorReporter.php          # Throwable -> BugPayload -> Outbox
  Reporting/PerformanceReporter.php    # (kind,label,durée,ctx) -> seuil -> PerfPayload -> Outbox
  Payloads/BugPayload.php              # fromThrowable() : map exact du contrat /laravel-bugs/add
  Payloads/PerfPayload.php             # map exact du contrat /laravel-perf/add
  Instrumentation/HandlerDecorator.php # implémente ExceptionHandler ; report() -> ErrorReporter puis délègue
  Instrumentation/RequestTiming.php    # middleware global, mesure la durée de la requête
  Instrumentation/JobTiming.php        # events queue (JobProcessing/JobProcessed/JobFailed)
  Instrumentation/QueryTiming.php      # DB::listen, durée par query
  Instrumentation/CommandTiming.php    # events console (CommandStarting/CommandFinished)
  Instrumentation/Registrar.php        # wire les 5 collecteurs depuis boot() selon les flags
```

### Responsabilités par unité

- **`Gate`** — point unique de décision « reporter ou pas ». Vérifie : `enabled` global, flag de la feature (`errors.enabled` / `performance.enabled`), présence de `base_url` **et** `ingest_key`. Self-disable si la config réseau manque.
- **`Outbox`** — singleton. `record(string $path, array $payload)` empile ; `flush(Transport $t)` envoie tout puis vide. Ne lève jamais.
- **`Transport` / `HttpTransport`** — `HttpTransport` construit la requête `Illuminate\Http\Client` avec header `X-Ingest-Key`, timeout court (défaut 2s), `try/catch` qui avale tout (au plus rien — pas de log, choix « silencieux total »).
- **`ErrorReporter`** — reçoit un `Throwable` + la requête courante (si dispo), délègue à `BugPayload::fromThrowable()`, `record()` dans l'Outbox sur le chemin bugs. Enveloppé `try/catch`.
- **`PerformanceReporter`** — `report(string $kind, string $label, int $durationMs, array $context = [])`. Lit le seuil du `kind` dans la config ; si `duration < seuil` ou `kind` désactivé, ne fait rien ; sinon `PerfPayload` -> Outbox sur le chemin perf.
- **`BugPayload::fromThrowable()`** — voir mapping ci-dessous.
- **`PerfPayload`** — assemble `{kind, label, duration_ms, context}` ; tronque `label` à 1000.
- **`HandlerDecorator`** — implémente `Illuminate\Contracts\Debug\ExceptionHandler`, wrappe le handler d'origine. `report()` appelle `ErrorReporter` (sous garde de ré-entrance) **puis délègue toujours** à l'original. `render()`, `renderForConsole()`, `shouldReport()` délèguent tels quels.
- **Collecteurs perf** — chacun mesure une durée et appelle `PerformanceReporter::report()`. `RequestTiming` est un middleware global poussé sur le kernel ; les autres s'abonnent à des events Laravel.
- **`Registrar`** — branche les collecteurs activés en `boot()`.

## Mapping des payloads

### `BugPayload::fromThrowable(Throwable $e, ?Request $request)`

| champ | source |
|---|---|
| `message` | `$e->getMessage()` |
| `type` | `getStatusCode()` si `Symfony\...\HttpExceptionInterface`, sinon `500` |
| `file` | `$e->getFile()` |
| `line` | `$e->getLine()` |
| `stackTrace` | array de frames `{file, line, function, class}`, profondeur plafonnée (défaut 50), **args supprimés** |
| `url` | requête web : URL complète ; console : `artisan:<commande>` ; job : `job:<classe>` |
| `ip` | requête web : `$request->ip()` ; hors web : `127.0.0.1` (fallback obligatoire, sinon 422) |
| `userAgent` | `$request?->userAgent()` |
| `username` | `$request?->user()?->name` si dispo (best-effort, jamais bloquant) |
| `userEmail` | `$request?->user()?->email` si dispo |
| `cookies` | `$request?->cookies->all()` (le serveur rédige les sensibles) |

La construction est entièrement défensive : toute valeur indisponible -> `null` ou fallback, jamais d'exception propagée.

### `PerfPayload`

Mapping direct : `kind`, `label` (tronqué 1000), `duration_ms` (int), `context` (array ou `null`).

Labels par collecteur :
- `request` : `"<METHOD> <route ou path>"`
- `job` : nom de classe du job
- `query` : SQL normalisé (bindings retirés)
- `command` : nom de la commande artisan

## Sécurité d'exécution

- **Garde de ré-entrance** : un flag « report en cours » dans l'`ErrorReporter` (ou un service dédié) empêche qu'une exception levée par notre propre code de report soit re-captée par le handler décoré (boucle infinie). De plus, le décorateur ignore toute exception dont l'origine est le namespace `BugCollector\Client`.
- **Fail-safe** : `Transport`, `Reporters` et la construction de payload sont enveloppés `try/catch`. Le `HandlerDecorator` délègue toujours au handler d'origine, même si notre reporting plante.
- **Self-disable** : `Gate` coupe tout si `base_url` ou `ingest_key` manquent.

## Config remaniée (`config/bug-collector.php`)

```php
return [
    'base_url'   => env('BUG_COLLECTOR_URL'),          // ex. https://host/  (la base, pas l'endpoint complet)
    'ingest_key' => env('BUG_COLLECTOR_INGEST_KEY'),
    'enabled'    => env('BUG_COLLECTOR_ENABLED', true),
    'timeout'    => env('BUG_COLLECTOR_TIMEOUT', 2),    // secondes, transport HTTP

    'endpoints' => [
        'errors'      => '/api/laravel-bugs/add',
        'performance' => '/api/laravel-perf/add',
    ],

    'errors' => [
        'enabled'     => env('BUG_COLLECTOR_ERRORS_ENABLED', true),
        'trace_depth' => env('BUG_COLLECTOR_TRACE_DEPTH', 50),
    ],

    'performance' => [
        'enabled'    => env('BUG_COLLECTOR_PERF_ENABLED', true),
        'thresholds' => [
            'request' => env('BUG_COLLECTOR_THRESHOLD_REQUEST', 2000),
            'job'     => env('BUG_COLLECTOR_THRESHOLD_JOB', 30000),
            'query'   => env('BUG_COLLECTOR_THRESHOLD_QUERY', 1000),
            'command' => env('BUG_COLLECTOR_THRESHOLD_COMMAND', 60000),
        ],
    ],
];
```

Le README et son tableau d'env (`BUG_COLLECTOR_URL`) sont mis à jour pour refléter `base_url` (valeur = la racine du service, plus l'endpoint complet).

## Flux de données

1. **Erreur** : exception -> `HandlerDecorator::report()` -> `ErrorReporter` (sous garde) -> `BugPayload::fromThrowable()` -> `Outbox::record(endpoints.errors, payload)` -> délégation au handler d'origine -> (web) `terminating` flush -> `HttpTransport` POST.
2. **Requête lente** : `RequestTiming` (terminate du middleware) -> `PerformanceReporter::report('request', …)` -> seuil OK -> Outbox -> `terminating` flush.
3. **Job lent** : `JobProcessed`/`JobFailed` -> `PerformanceReporter::report('job', …)` -> Outbox -> flush immédiat.
4. **Query lente** : `DB::listen` -> si `time` > seuil -> `PerformanceReporter::report('query', …)` -> Outbox (vidé par le flush du cycle englobant : requête ou job).
5. **Commande lente** : `CommandFinished` -> `PerformanceReporter::report('command', …)` -> Outbox -> `terminating` flush.

## Stratégie de tests (Orchestra Testbench, TDD)

- **Unitaire pur** : `BugPayload` (mapping, `type` HTTP vs 500, fallbacks ip/url console+job, troncature, profondeur de trace, suppression des args), `PerfPayload`, `Gate` (toutes les conditions on/off), seuils du `PerformanceReporter`.
- **`HttpTransport`** avec `Http::fake()` : header `X-Ingest-Key` présent, bon endpoint, **silence total** sur erreur réseau / timeout / 4xx-5xx.
- **`HandlerDecorator`** : délègue toujours `report`/`render` ; reporte bien ; garde de ré-entrance (une exception de notre namespace n'est pas re-captée).
- **`Outbox`** : record empile, flush vide et n'envoie qu'une fois.
- **Feature (app Testbench)** : chaque collecteur déclenche un envoi au-dessus du seuil et **rien** en dessous ; un kill-switch désactive bien sa feature ; self-disable quand la config réseau manque.

## Hors périmètre (YAGNI)

- Pas de persistance/retry côté client (le serveur dédup ; un envoi raté est perdu, acceptable).
- Pas de batching multi-événements (un POST par événement ; throttle serveur 60/min jugé suffisant à ce stade).
- Pas d'échantillonnage configurable.
- Pas de transport « queue » dédié (décision : terminating + envoi immédiat jobs).
