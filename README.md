# Bug Collector — Client Laravel

Client Laravel pour [Bug Collector](https://github.com/) : reporte automatiquement les **erreurs** et les **anomalies de performance** (requêtes HTTP, jobs, queries SQL, commandes artisan trop lentes) à un service Bug Collector centralisé.

> 🚧 **En cours de conception.** Le squelette du package est posé ; l'instrumentation et le contrat d'envoi ne sont pas encore implémentés.

## Installation (cible)

```bash
composer require bug-collector/laravel-client
```

Puis dans le `.env` de l'app cliente :

```env
BUG_COLLECTOR_URL="https://<host-bug-collector>/api/laravel-bugs/add"
BUG_COLLECTOR_INGEST_KEY="<clé générée dans le panneau Bug Collector>"
```

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

## Licence

MIT
