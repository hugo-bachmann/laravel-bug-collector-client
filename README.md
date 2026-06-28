# Bug Collector — Client Laravel

Client Laravel pour [Bug Collector](https://github.com/hugo-bachmann/bug-collector) : reporte automatiquement les **erreurs** et les **anomalies de performance** (requêtes HTTP, jobs, queries SQL, commandes artisan trop lentes) à un service Bug Collector centralisé.

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

## Licence

Ce package est distribué sous licence [MIT](LICENSE).
