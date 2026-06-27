<?php

namespace BugCollector\Client;

use Illuminate\Support\ServiceProvider;

class BugCollectorServiceProvider extends ServiceProvider
{
    /**
     * Enregistre les liaisons du conteneur.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bug-collector.php', 'bug-collector');

        // TODO: enregistrer le client HTTP / transport (à concevoir).
    }

    /**
     * Démarre le package : publication de config + instrumentation.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/bug-collector.php' => config_path('bug-collector.php'),
        ], 'bug-collector-config');

        if (! config('bug-collector.enabled')) {
            return;
        }

        // TODO: brancher l'instrumentation une fois le contrat figé :
        //   - reporting des erreurs (handler d'exceptions)
        //   - requêtes HTTP lentes (middleware de timing)
        //   - jobs lents (events de queue)
        //   - queries lentes (DB::whenQueryingForLongerThan / DB::listen)
        //   - commandes lentes (events de console)
    }
}
