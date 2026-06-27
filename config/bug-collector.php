<?php

return [

    // Connexion au service Bug Collector. base_url = la RACINE du service
    // (pas l'endpoint complet) ; les chemins sont ajoutés par le transport.
    'base_url'   => env('BUG_COLLECTOR_URL'),
    'ingest_key' => env('BUG_COLLECTOR_INGEST_KEY'),

    // Coupe-circuit global. Si base_url ou ingest_key manquent, le client
    // se désactive de lui-même (utile en local).
    'enabled' => env('BUG_COLLECTOR_ENABLED', true),

    // Timeout du transport HTTP, en secondes.
    'timeout' => env('BUG_COLLECTOR_TIMEOUT', 2),

    'endpoints' => [
        'errors'      => '/api/laravel-bugs/add',
        'performance' => '/api/laravel-perf/add',
    ],

    'errors' => [
        'enabled'     => env('BUG_COLLECTOR_ERRORS_ENABLED', true),
        'trace_depth' => env('BUG_COLLECTOR_TRACE_DEPTH', 50),
    ],

    'performance' => [
        'enabled' => env('BUG_COLLECTOR_PERF_ENABLED', true),

        // Seuils en millisecondes. En dessous, rien n'est envoyé.
        'thresholds' => [
            'request' => env('BUG_COLLECTOR_THRESHOLD_REQUEST', 2000),
            'job'     => env('BUG_COLLECTOR_THRESHOLD_JOB', 30000),
            'query'   => env('BUG_COLLECTOR_THRESHOLD_QUERY', 1000),
            'command' => env('BUG_COLLECTOR_THRESHOLD_COMMAND', 60000),
        ],
    ],

];
