# Changelog

Toutes les modifications notables de ce package sont documentées dans ce fichier.
Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le
projet respecte le [versioning sémantique](https://semver.org/lang/fr/).

## [0.1.0] - 2026-06-27

### Ajouté
- Reporting des erreurs via décoration de l'`ExceptionHandler` (zéro config côté client), filtré par le `shouldReport()` du handler hôte.
- Sondes de performance : requêtes HTTP (middleware), commandes artisan, jobs de queue (flush immédiat) et requêtes SQL — chacune filtrée par un seuil configurable.
- Transport HTTP fail-safe : header `X-Ingest-Key`, timeout court, toute erreur réseau avalée (une panne du collecteur n'impacte jamais l'app hôte).
- Tampon `Outbox` borné et dédupliqué ; vidé via le hook `terminating` (web/console) ou immédiatement après chaque job (workers longue durée).
- Capture des cookies opt-in (désactivée par défaut, `BUG_COLLECTOR_SEND_COOKIES`) avec rédaction des cookies sensibles (session, `XSRF-TOKEN`, `remember_*`).
- Configuration publiable (`base_url`, endpoints, seuils, kill-switches globaux et par fonctionnalité).

[0.1.0]: https://github.com/hugobachmann/laravel-bug-collector-client/releases/tag/v0.1.0
