<?php

return [

    // Connexion au service Bug Collector. base_url = la RACINE du service
    // (pas l'endpoint complet) ; les chemins sont ajoutés par le transport.
    'base_url' => env('BUG_COLLECTOR_URL'),
    'ingest_key' => env('BUG_COLLECTOR_INGEST_KEY'),

    // Coupe-circuit global. Si base_url ou ingest_key manquent, le client
    // se désactive de lui-même (utile en local).
    'enabled' => env('BUG_COLLECTOR_ENABLED', true),

    // Timeout du transport HTTP, en secondes.
    'timeout' => env('BUG_COLLECTOR_TIMEOUT', 2),

    'endpoints' => [
        'errors' => '/api/laravel-bugs/add',
        'performance' => '/api/laravel-perf/add',
    ],

    'errors' => [
        'enabled' => env('BUG_COLLECTOR_ERRORS_ENABLED', true),
        'trace_depth' => env('BUG_COLLECTOR_TRACE_DEPTH', 50),

        // Capture des cookies : désactivée par défaut (opt-in). Quand activée,
        // les cookies sensibles (session, CSRF, remember_*, et tout nom contenant
        // token/auth/session/csrf/secret/password) sont automatiquement masqués.
        'cookies' => env('BUG_COLLECTOR_SEND_COOKIES', false),

        // Noms de cookies additionnels à masquer (CSV), en plus des défauts
        // (session, XSRF-TOKEN, remember_*) et des motifs sensibles courants.
        'redact_cookies' => env('BUG_COLLECTOR_REDACT_COOKIES', ''),
    ],

    // Exceptions jamais envoyées (instanceof : sous-classes comprises). Chaînes,
    // pas ::class, car la classe peut ne pas exister (Livewire non installé).
    'ignore' => [
        // Livewire : bots qui rejouent /livewire/update, onglets restés ouverts
        // pendant un déploiement, changement d'APP_KEY, propriétés trafiquées.
        'Livewire\Mechanisms\HandleComponents\CorruptComponentPayloadException',
        'Livewire\Exceptions\ComponentNotFoundException',
        'Livewire\Exceptions\MethodNotFoundException',
        'Livewire\Exceptions\PublicPropertyNotFoundException',
        'Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException',
    ],

    // Nombre maximum de payloads bufferisés par cycle, garde-fou anti N+1.
    'max_buffer' => env('BUG_COLLECTOR_MAX_BUFFER', 100),

    'performance' => [
        'enabled' => env('BUG_COLLECTOR_PERF_ENABLED', true),

        // Seuils en millisecondes : en dessous, rien n'est envoyé. `false` (ou
        // null) désactive complètement un type. Toute valeur invalide — vide,
        // non numérique, 0 ou négative — retombe sur le défaut du type au lieu
        // de désactiver le filtre (garde-fou anti-inondation du collecteur).
        'thresholds' => [
            'request' => env('BUG_COLLECTOR_THRESHOLD_REQUEST', 2000),
            'job' => env('BUG_COLLECTOR_THRESHOLD_JOB', 30000),
            'query' => env('BUG_COLLECTOR_THRESHOLD_QUERY', 1000),
            'command' => env('BUG_COLLECTOR_THRESHOLD_COMMAND', 60000),
        ],

        // Commandes longue durée (démons) : leur « durée » est leur uptime,
        // pas une lenteur. Elles ne sont jamais chronométrées.
        'ignore_commands' => [
            'inertia:start-ssr',
            'queue:work',
            'queue:listen',
            'schedule:work',
            'pulse:check',
            'pulse:work',
            'reverb:start',
            'horizon',
            'horizon:supervisor',
            'horizon:work',
            'horizon:listen',
            'octane:start',
        ],
    ],

];
