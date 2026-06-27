<?php

namespace BugCollector\Client;

use BugCollector\Client\Instrumentation\CommandTiming;
use BugCollector\Client\Instrumentation\HandlerDecorator;
use BugCollector\Client\Instrumentation\JobTiming;
use BugCollector\Client\Instrumentation\QueryTiming;
use BugCollector\Client\Instrumentation\Registrar;
use BugCollector\Client\Instrumentation\RequestTiming;
use BugCollector\Client\Reporting\ErrorReporter;
use BugCollector\Client\Reporting\PerformanceReporter;
use BugCollector\Client\Support\Gate;
use BugCollector\Client\Transport\HttpTransport;
use BugCollector\Client\Transport\Transport;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;

class BugCollectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bug-collector.php', 'bug-collector');

        $this->app->singleton(Gate::class);
        $this->app->singleton(Outbox::class, function ($app) {
            return new Outbox((int) $app['config']->get('bug-collector.max_buffer', 100));
        });
        $this->app->singleton(Transport::class, HttpTransport::class);
        $this->app->singleton(ErrorReporter::class);
        $this->app->singleton(PerformanceReporter::class);
        $this->app->singleton(RequestTiming::class);
        $this->app->singleton(CommandTiming::class);
        $this->app->singleton(JobTiming::class);
        $this->app->singleton(QueryTiming::class);
        $this->app->singleton(Registrar::class);

        // Décore le handler d'exceptions de l'app : report() passe par notre
        // reporter puis délègue toujours à l'original. Zéro config côté client.
        $this->app->extend(ExceptionHandler::class, function (ExceptionHandler $handler, Container $app) {
            return new HandlerDecorator($handler, $app->make(ErrorReporter::class));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/bug-collector.php' => config_path('bug-collector.php'),
        ], 'bug-collector-config');

        if (! $this->app->make(Gate::class)->enabled()) {
            return;
        }

        // Vide l'Outbox après la réponse (web) ou après la commande (console).
        $this->app->terminating(function () {
            $outbox = $this->app->make(Outbox::class);

            if (! $outbox->isEmpty()) {
                $outbox->flush($this->app->make(Transport::class));
            }
        });

        $this->app->make(Registrar::class)->register();
    }
}
