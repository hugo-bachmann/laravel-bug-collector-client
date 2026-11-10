<?php

namespace BugCollector\Client\Instrumentation;

use BugCollector\Client\Reporting\PerformanceReporter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** Middleware mesurant la durée des requêtes HTTP et reportant celles dépassant le seuil configuré. */
class RequestTiming
{
    public function __construct(private PerformanceReporter $reporter) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $entry = microtime(true);

        // Si $next() lève, l'exception remonte telle quelle (non avalée).
        $response = $next($request);

        try {
            // Fichiers, streams et SSE : la durée mesurée inclut l'envoi au
            // client (débit réseau), pas une lenteur de l'app.
            if (! $response instanceof BinaryFileResponse && ! $response instanceof StreamedResponse) {
                $start = $this->startTime($request, $entry);
                $ms = (int) ((microtime(true) - $start) * 1000);
                $label = $request->method().' '.($request->route()?->uri() ?? $request->path());

                // Alimente seulement l'Outbox : l'envoi reste dans `terminating`.
                $this->reporter->report('request', $label, $ms);
            }
        } catch (Throwable) {
            // Sécurité : on n'impacte jamais l'app hôte.
        }

        return $response;
    }

    /** Début réel de la requête (boot inclus), sinon repli sur l'entrée du middleware (absent, aberrant, Octane). */
    private function startTime(Request $request, float $entry): float
    {
        $raw = $request->server('REQUEST_TIME_FLOAT');

        if (is_numeric($raw) && (float) $raw <= $entry && $entry - (float) $raw < 3600) {
            return (float) $raw;
        }

        return $entry;
    }
}
