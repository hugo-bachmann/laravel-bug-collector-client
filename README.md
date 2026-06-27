# Bug Collector — Client Laravel

Client Laravel pour [Bug Collector](https://github.com/) : reporte automatiquement les **erreurs** et les **anomalies de performance** (requêtes HTTP, jobs, queries SQL, commandes artisan trop lentes) à un service Bug Collector centralisé.

L'instrumentation et le transport sont implémentés. L'authentification se fait via le header `X-Ingest-Key` ; l'envoi est fail-safe (une panne du collecteur ne casse jamais l'app hôte) ; les seuils de performance sont configurables.

## Installation (cible)

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

## Cookies

La capture des cookies est **désactivée par défaut** (opt-in via `BUG_COLLECTOR_SEND_COOKIES=true`). Quand elle est activée, les cookies sensibles (session Laravel, `XSRF-TOKEN`, tout cookie commençant par `remember_`) sont automatiquement remplacés par `[REDACTED]`.

## Licence

MIT
