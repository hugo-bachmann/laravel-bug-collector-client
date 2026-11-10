# Changelog

Toutes les modifications notables de ce package sont documentées dans ce fichier.
Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le
projet respecte le [versioning sémantique](https://semver.org/lang/fr/).

## [1.4.0] - 2026-11-10

**Mise à jour : déployer d'abord la version du serveur Bug Collector qui accepte `class` et `previous`, puis ce client.**

**Les empreintes (fingerprints) des erreurs changent une seule fois** (classe ajoutée,
chemins relatifs) : les erreurs existantes réapparaîtront comme nouvelles.

### Ajouté
- Commande `php artisan bug-collector:test` : diagnostique la configuration (raison
  exacte de désactivation), envoie une exception de test directement, code de sortie 0/1.
- Payload d'erreur : `class` et `previous` (chaîne des causes, 5 maillons max,
  `{class, message, file, line}`, sans arguments).
- Requêtes SQL lentes : `context` avec `connection`, `origin` (job, `METHOD route-uri`
  ou commande artisan, jamais l'argv complet) et `caller` (premier appelant hors
  vendor/package, `chemin/relatif.php:ligne`), calculés seulement au-dessus du seuil.

### Modifié
- Les chemins de fichiers des erreurs (`file`, `previous[*].file`, `stackTrace[*].file`)
  sont relatifs à la racine du projet.
- `Transport::send()` retourne un `bool` (vrai seulement sur 2xx) : une implémentation
  personnalisée de `Transport` doit adapter sa signature ; `Outbox::flush()`
  s'arrête au premier échec et abandonne le reste du lot (collecteur en panne :
  plus de N × timeout).
- `RequestTiming` mesure depuis `REQUEST_TIME_FLOAT` (repli sur l'entrée du middleware
  si absent, non numérique, dans le futur ou vieux de plus de 3600 s) jusqu'à la
  construction de la réponse, entièrement dans `handle()` (plus de `terminate()`).

### Corrigé
- `JobExceptionOccurred` termine la mesure de la tentative : un job lent relancé
  produit un événement par tentative lente.

## [1.3.0] - 2026-11-10

### Ajouté
- Option `ignore` : classes d'exceptions jamais envoyées (`instanceof`, sous-classes
  comprises). Défaut : les exceptions Livewire déclenchées par des bots, des
  onglets ouverts pendant un déploiement ou un changement d'`APP_KEY`
  (`CorruptComponentPayloadException`, `ComponentNotFoundException`,
  `MethodNotFoundException`, `PublicPropertyNotFoundException`,
  `CannotUpdateLockedPropertyException`), référencées par chaîne car Livewire
  est optionnel.

### Corrigé
- Les téléchargements de fichiers (`BinaryFileResponse`) et réponses streamées
  (`StreamedResponse` : stream, streamDownload, SSE) ne remontent plus comme
  « requêtes lentes » : leur durée mesurée incluait l'envoi au client.

## [1.2.0] - 2026-09-21

### Corrigé
- Les commandes longue durée (démons) ne remontent plus comme « commandes
  lentes » : leur durée mesurée était leur uptime (ex. `inertia:start-ssr` à
  9 jours à chaque redéploiement).

### Ajouté
- Option `performance.ignore_commands` : commandes jamais chronométrées. Défaut :
  `inertia:start-ssr`, `queue:work`, `queue:listen`, `schedule:work`,
  `pulse:check`, `pulse:work`, `reverb:start`, `horizon`, `horizon:supervisor`,
  `horizon:work`, `horizon:listen`, `octane:start`. Une config publiée doit
  recopier la clé (fusion de config au premier niveau seulement).

## [1.1.0] - 2026-07-02

### Corrigé
- Un seuil de performance mal configuré ne désactive plus silencieusement le
  filtrage. Une valeur vide (typiquement `BUG_COLLECTOR_THRESHOLD_REQUEST=` dans
  le `.env`), non numérique, `0` ou négative était castée en `0` : `durée < 0`
  étant toujours faux, **toutes** les mesures passaient et inondaient le
  collecteur d'évènements « lents » à 100–300 ms. Ces valeurs retombent
  désormais sur le défaut du type.

### Modifié
- `PerformanceReporter` valide le seuil configuré avant de l'appliquer :
  `false`/`null` désactivent un type (inchangé), un nombre ≥ 1 est utilisé tel
  quel, et toute autre valeur retombe sur le défaut intégré (`request` 2000,
  `job` 30000, `query` 1000, `command` 60000 ms). Conséquence : `0` ne signifie
  plus « tout envoyer » mais « valeur invalide → défaut ».

## [1.0.1] - 2026-07-01

### Corrigé
- Nettoyage de la documentation, sans aucun changement de code : retrait du
  statut « cible » de la section Installation du README et des dernières
  références à Laravel 11 (README, commentaire du baseline PHPStan).

## [1.0.0] - 2026-07-01

Première version stable. Le contrat public (clés de `config/bug-collector.php`,
variables d'environnement `BUG_COLLECTOR_*`, chemins d'endpoints par défaut) est
désormais gelé et suivra le [versioning sémantique](https://semver.org/lang/fr/) :
aucune rupture en 1.x.

### Ajouté
- Intégration continue GitHub Actions : matrice PHP 8.2/8.3/8.4 × Laravel 12/13
  (tests) et job de lint (Pint + PHPStan).
- Analyse statique PHPStan / Larastan au niveau 6 (`phpstan.neon`).
- Scripts Composer `test`, `lint`, `format`, `analyse`.
- `.editorconfig` pour un style d'édition cohérent.

### Modifié
- Types de retour / paramètres précisés (`HandlerDecorator::__call`,
  `RequestTiming::handle`/`terminate`) pour satisfaire l'analyse statique, sans
  changement de comportement.

### Retiré
- **Support de Laravel 11** : la ligne 11.x est en fin de support sécurité et
  n'installe plus proprement (advisories non corrigées). Contraintes portées à
  `illuminate/* ^12|^13` et `orchestra/testbench ^10|^11`. Les apps sur Laravel 11
  restent sur la 0.1.x.

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

[1.0.1]: https://github.com/hugo-bachmann/laravel-bug-collector-client/releases/tag/v1.0.1
[1.0.0]: https://github.com/hugo-bachmann/laravel-bug-collector-client/releases/tag/v1.0.0
[0.1.2]: https://github.com/hugo-bachmann/laravel-bug-collector-client/releases/tag/v0.1.2
[0.1.1]: https://github.com/hugo-bachmann/laravel-bug-collector-client/releases/tag/v0.1.1
[0.1.0]: https://github.com/hugo-bachmann/laravel-bug-collector-client/releases/tag/v0.1.0
