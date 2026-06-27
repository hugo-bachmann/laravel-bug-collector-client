<?php

namespace BugCollector\Client\Instrumentation;

use BugCollector\Client\Support\Gate;
use Illuminate\Contracts\Container\Container;

class Registrar
{
    public function __construct(
        private Container $app,
        private Gate $gate,
    ) {}

    /**
     * Branche les collecteurs de performance activés. Rempli au fil des tasks
     * 11 à 14.
     */
    public function register(): void
    {
        if (! $this->gate->shouldReportPerformance()) {
            return;
        }

        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);

        if (method_exists($kernel, 'pushMiddleware')) {
            $kernel->pushMiddleware(RequestTiming::class);
        }

        $events = $this->app->make(\Illuminate\Contracts\Events\Dispatcher::class);

        $events->listen(\Illuminate\Console\Events\CommandStarting::class, [CommandTiming::class, 'starting']);
        $events->listen(\Illuminate\Console\Events\CommandFinished::class, [CommandTiming::class, 'finished']);

        $events->listen(\Illuminate\Queue\Events\JobProcessing::class, [JobTiming::class, 'processing']);
        $events->listen(\Illuminate\Queue\Events\JobProcessed::class, [JobTiming::class, 'processed']);
        $events->listen(\Illuminate\Queue\Events\JobFailed::class, [JobTiming::class, 'failed']);

        $events->listen(\Illuminate\Database\Events\QueryExecuted::class, [QueryTiming::class, 'handle']);
    }
}
