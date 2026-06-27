<?php

namespace BugCollector\Client\Instrumentation;

use BugCollector\Client\Reporting\PerformanceReporter;
use Closure;
use Illuminate\Http\Request;
use Throwable;

class RequestTiming
{
    private const START_KEY = 'bugcollector_start';

    public function __construct(private PerformanceReporter $reporter) {}

    public function handle(Request $request, Closure $next)
    {
        $request->attributes->set(self::START_KEY, microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        try {
            $start = $request->attributes->get(self::START_KEY);

            if ($start === null) {
                return;
            }

            $ms = (int) ((microtime(true) - (float) $start) * 1000);
            $label = $request->method().' '.($request->route()?->uri() ?? $request->path());

            $this->reporter->report('request', $label, $ms);
        } catch (Throwable) {
            // Fail-safe.
        }
    }
}
