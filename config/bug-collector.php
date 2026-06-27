<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Connexion au service Bug Collector
    |--------------------------------------------------------------------------
    |
    | URL de base du service Bug Collector et clé d'ingestion propre au site.
    | La clé identifie le site (et donc l'équipe) côté serveur ; elle se
    | génère dans le panneau d'admin Bug Collector.
    |
    */

    'url' => env('BUG_COLLECTOR_URL'),

    'ingest_key' => env('BUG_COLLECTOR_INGEST_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Activation
    |--------------------------------------------------------------------------
    |
    | Coupe-circuit global. Si la config réseau est absente, le client se
    | désactive de lui-même (utile en local).
    |
    */

    'enabled' => env('BUG_COLLECTOR_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Reporting des erreurs
    |--------------------------------------------------------------------------
    */

    'errors' => [
        'enabled' => env('BUG_COLLECTOR_ERRORS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reporting des performances
    |--------------------------------------------------------------------------
    |
    | On ne reporte qu'au-dessus d'un seuil (en millisecondes). En dessous,
    | rien n'est envoyé. Mettre un type à false le désactive complètement.
    |
    */

    'performance' => [
        'enabled' => env('BUG_COLLECTOR_PERF_ENABLED', true),

        'thresholds' => [
            'request' => env('BUG_COLLECTOR_THRESHOLD_REQUEST', 2000),
            'job'     => env('BUG_COLLECTOR_THRESHOLD_JOB', 30000),
            'query'   => env('BUG_COLLECTOR_THRESHOLD_QUERY', 1000),
            'command' => env('BUG_COLLECTOR_THRESHOLD_COMMAND', 60000),
        ],
    ],

];
