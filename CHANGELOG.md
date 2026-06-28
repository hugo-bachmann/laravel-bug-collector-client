# Changelog

Toutes les modifications notables de ce package sont documentées dans ce fichier.
Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le
projet respecte le [versioning sémantique](https://semver.org/lang/fr/).

## [Unreleased]

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

[Unreleased]: https://github.com/hugo-bachmann/laravel-bug-collector-client/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/hugo-bachmann/laravel-bug-collector-client/releases/tag/v0.1.0
