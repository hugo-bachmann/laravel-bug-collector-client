# Bug Collector — Client Laravel

[![tests](https://github.com/hugo-bachmann/laravel-bug-collector-client/actions/workflows/tests.yml/badge.svg)](https://github.com/hugo-bachmann/laravel-bug-collector-client/actions/workflows/tests.yml)
[![lint](https://github.com/hugo-bachmann/laravel-bug-collector-client/actions/workflows/lint.yml/badge.svg)](https://github.com/hugo-bachmann/laravel-bug-collector-client/actions/workflows/lint.yml)
[![Packagist Version](https://img.shields.io/packagist/v/bug-collector/laravel-client)](https://packagist.org/packages/bug-collector/laravel-client)
[![License](https://img.shields.io/packagist/l/bug-collector/laravel-client)](LICENSE)

Client Laravel pour [Bug Collector](https://github.com/hugo-bachmann/bug-collector) : reporte automatiquement les **erreurs** et les **anomalies de performance** (requêtes HTTP, jobs, queries SQL, commandes artisan trop lentes) à un service Bug Collector centralisé.

## Compatibilité

| Package | PHP        | Laravel   |
|---------|------------|-----------|
| 1.x     | 8.2 – 8.4  | 12 · 13   |

L'instrumentation et le transport sont implémentés. L'authentification se fait via le header `X-Ingest-Key` ; l'envoi est fail-safe (une panne du collecteur ne casse jamais l'app hôte) ; les seuils de performance sont configurables.

## Installation

```bash
composer require bug-collector/laravel-client
```

Puis dans le `.env` de l'app cliente :

```env
BUG_COLLECTOR_URL="https://<host-bug-collector>"
BUG_COLLECTOR_INGEST_KEY="<clé générée dans le panneau Bug Collector>"
```

`BUG_COLLECTOR_URL` est la **racine** du service Bug Collector. Le client ajoute automatiquement `/api/laravel-bugs/add` pour les erreurs et `/api/laravel-perf/add` pour la performance.

Le ServiceProvider est auto-découvert — rien à enregistrer à la main.

## Configuration

```bash
php artisan vendor:publish --tag=bug-collector-config
```

Voir `config/bug-collector.php` pour les seuils de performance (en millisecondes) :

| Type      | Défaut   |
|-----------|----------|
| `request` | 2000 ms  |
| `job`     | 30000 ms |
| `query`   | 1000 ms  |
| `command` | 60000 ms |

Les commandes longue durée (démons : `inertia:start-ssr`, `queue:work`, `horizon:work`, `schedule:work`…) ne sont jamais chronométrées : leur « durée » serait leur uptime. Liste dans `performance.ignore_commands`. Si vous avez publié la config, recopiez-y cette clé (la fusion de config ne descend pas sous `performance`).

Les téléchargements de fichiers et les réponses streamées (`response()->download()`, `->stream()`, `->streamDownload()`, SSE) ne sont pas chronométrés : leur durée dépend du débit du client.

Les exceptions exclues par l'app hôte (`$exceptions->dontReport(...)` / `->ignore(...)` dans `bootstrap/app.php`) ne sont jamais envoyées : le client ne remonte que ce que `shouldReport()` du handler accepte.

L'option `ignore` liste en plus des classes d'exceptions jamais envoyées (`instanceof`, sous-classes comprises). Défaut : les exceptions Livewire déclenchées par des bots, des onglets ouverts pendant un déploiement ou un changement d'`APP_KEY` (`CorruptComponentPayloadException`, `ComponentNotFoundException`, `MethodNotFoundException`, `PublicPropertyNotFoundException`, `CannotUpdateLockedPropertyException`). Si vous définissez `ignore` dans une config publiée, elle remplace la liste par défaut : recopiez-y la classe Livewire.

## Cookies

La capture des cookies est **désactivée par défaut** (opt-in via `BUG_COLLECTOR_SEND_COOKIES=true`). Quand elle est activée, sont automatiquement remplacés par `[REDACTED]` :

- la session Laravel et `XSRF-TOKEN` ;
- tout cookie commençant par `remember_` ;
- tout cookie dont le nom contient `token`, `auth`, `session`, `csrf`, `secret` ou `password` (insensible à la casse).

Pour masquer des noms supplémentaires (cookies d'auth/JWT custom), listez-les en CSV via `BUG_COLLECTOR_REDACT_COOKIES="tenant_id,locale_pref"`.

## Sécurité & confidentialité

- **HTTPS requis.** `BUG_COLLECTOR_URL` doit être en `https://` (sauf `localhost`/`127.0.0.1` en dev). Sinon le client se **désactive automatiquement** pour ne jamais transmettre la clé d'ingestion ni les données en clair.
- **Paramètres d'URL sensibles masqués.** Les valeurs des paramètres de query courants (`token`, `signature`, `password`, `secret`, `api_key`, `code`, `otp`…) sont remplacées par `[REDACTED]` avant l'envoi.
- **Args de stack trace exclus** : seuls fichier / ligne / fonction / classe sont remontés.
- **Attention aux messages d'exception et au SQL brut.** Le message d'exception (`getMessage()`) et le label des requêtes lentes sont envoyés **verbatim**. Les requêtes Eloquent gardent leurs placeholders (`?`), mais le SQL brut (`whereRaw`, `DB::raw`) et les messages d'exception peuvent contenir des littéraux sensibles — évitez d'y placer des secrets.
- **Latence.** L'envoi est synchrone au `terminate` (après la réponse / la commande), borné par `BUG_COLLECTOR_TIMEOUT` (2 s par défaut) ; toute erreur réseau est avalée.

## Développement

```bash
composer install
composer test       # phpunit
composer lint       # pint --test (vérifie le style)
composer format     # pint (corrige le style)
composer analyse    # phpstan (analyse statique, niveau 6)
```

L'intégration continue exécute la suite de tests sur la matrice PHP 8.2/8.3/8.4 ×
Laravel 12/13, ainsi que Pint et PHPStan.

## Licence

Ce package est distribué sous licence [MIT](LICENSE).
