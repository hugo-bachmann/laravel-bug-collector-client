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
    }
}
