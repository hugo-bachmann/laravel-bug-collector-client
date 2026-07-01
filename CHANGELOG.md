# Changelog

Toutes les modifications notables de ce package sont documentées dans ce fichier.
Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le
projet respecte le [versioning sémantique](https://semver.org/lang/fr/).

## [1.0.0] - 2026-07-01

Première version stable. Le contrat public (clés de `config/bug-collector.php`,
variables d'environnement `BUG_COLLECTOR_*`, chemins d'endpoints par défaut) est
désormais gelé et suivra le [versioning sémantique](https://semver.org/lang/fr/) :
aucune rupture en 1.x.

### Ajouté
- Intégration continue GitHub Actions : matrice PHP 8.2/8.3/8.4 × Laravel 11/12/13
  (tests) et job de lint (Pint + PHPStan).
- Analyse statique PHPStan / Larastan au niveau 6 (`phpstan.neon`).
- Scripts Composer `test`, `lint`, `format`, `analyse`.
- `.editorconfig` pour un style d'édition cohérent.

### Modifié
- Types de retour / paramètres précisés (`HandlerDecorator::__call`,
  `RequestTiming::handle`/`terminate`) pour satisfaire l'analyse statique, sans
  changement de comportement.

## [0.1.2] - 2026-06-29

### Corrigé
- `HandlerDecorator` délègue désormais au handler décoré toute méthode hors du contrat `ExceptionHandler` (via `__call`). Les packages qui s'appuient sur les méthodes du handler concret de Laravel — `reportable()`, `renderable()`, `map()`, `ignore()`… — fonctionnent à nouveau. Corrige une erreur fatale au boot (`Call to undefined method HandlerDecorator::reportable()`) en présence de Laravel Pulse.

## [0.1.1] - 2026-06-28

### Sécurité
- Masquage des paramètres d'URL sensibles (`token`, `signature`, `password`, `secret`, `api_key`, `code`, `otp`…) avant l'envoi : seule la valeur est remplacée par `[REDACTED]`, le reste de l'URL est conservé.
- HTTPS désormais exigé pour `base_url` (sauf `localhost`/`127.0.0.1`) : un endpoint en clair désactive automatiquement le client pour ne jamais transmettre la clé d'ingestion ni les données en clair.
- Rédaction des cookies élargie : tout cookie dont le nom contient `token`, `auth`, `session`, `csrf`, `secret` ou `password` est masqué, en plus des défauts existants.

### Ajouté
- `BUG_COLLECTOR_REDACT_COOKIES` : liste CSV de noms de cookies additionnels à masquer.

## [0.1.0] - 2026-06-27

### Ajouté
- Reporting des erreurs via décoration de l'`ExceptionHandler` (zéro config côté client), filtré par le `shouldReport()` du handler hôte.
- Sondes de performance : requêtes HTTP (middleware), commandes artisan, jobs de queue (flush immédiat) et requêtes SQL — chacune filtrée par un seuil configurable.
- Transport HTTP fail-safe : header `X-Ingest-Key`, timeout court, toute erreur réseau avalée (une panne du collecteur n'impacte jamais l'app hôte).
- Tampon `Outbox` borné et dédupliqué ; vidé via le hook `terminating` (web/console) ou immédiatement après chaque job (workers longue durée).
- Capture des cookies opt-in (désactivée par défaut, `BUG_COLLECTOR_SEND_COOKIES`) avec rédaction des cookies sensibles (session, `XSRF-TOKEN`, `remember_*`).
- Configuration publiable (`base_url`, endpoints, seuils, kill-switches globaux et par fonctionnalité).

[1.0.0]: https://github.com/hugo-bachmann/laravel-bug-collector-client/releases/tag/v1.0.0
[0.1.2]: https://github.com/hugo-bachmann/laravel-bug-collector-client/releases/tag/v0.1.2
[0.1.1]: https://github.com/hugo-bachmann/laravel-bug-collector-client/releases/tag/v0.1.1
[0.1.0]: https://github.com/hugo-bachmann/laravel-bug-collector-client/releases/tag/v0.1.0
