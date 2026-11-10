<?php

namespace BugCollector\Client\Instrumentation;

use BugCollector\Client\Reporting\PerformanceReporter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** Middleware mesurant la durée des requêtes HTTP et reportant celles dépassant le seuil configuré. */
class RequestTiming
{
    private const START_KEY = 'bugcollector_start';

    public function __construct(private PerformanceReporter $reporter) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $request->attributes->set(self::START_KEY, microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $start = $request->attributes->get(self::START_KEY);

            // Fichiers, streams et SSE : la durée mesurée inclut l'envoi au
            // client (débit réseau), pas une lenteur de l'app.
            if ($start === null || $response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
                return;
            }

            $ms = (int) ((microtime(true) - (float) $start) * 1000);
            $label = $request->method().' '.($request->route()?->uri() ?? $request->path());

            $this->reporter->report('request', $label, $ms);
        } catch (Throwable) {
            // Sécurité : on n'impacte jamais l'app hôte.
        }
    }
}
