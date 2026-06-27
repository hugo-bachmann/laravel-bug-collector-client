<?php

namespace BugCollector\Client\Instrumentation;

use BugCollector\Client\Support\Gate;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

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

        $kernel = $this->app->make(Kernel::class);

        if (method_exists($kernel, 'pushMiddleware')) {
            $kernel->pushMiddleware(RequestTiming::class);
        }

        $events = $this->app->make(Dispatcher::class);

        $events->listen(CommandStarting::class, [CommandTiming::class, 'starting']);
        $events->listen(CommandFinished::class, [CommandTiming::class, 'finished']);

        $events->listen(JobProcessing::class, [JobTiming::class, 'processing']);
        $events->listen(JobProcessed::class, [JobTiming::class, 'processed']);
        $events->listen(JobFailed::class, [JobTiming::class, 'failed']);

        $events->listen(QueryExecuted::class, [QueryTiming::class, 'handle']);
    }
}
