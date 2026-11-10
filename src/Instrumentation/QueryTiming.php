<?php

namespace BugCollector\Client\Instrumentation;

use BugCollector\Client\Payloads\BugPayload;
use BugCollector\Client\Reporting\PerformanceReporter;
use Illuminate\Database\Events\QueryExecuted;

/** Reporte les requêtes SQL dont la durée dépasse le seuil configuré. */
class QueryTiming
{
    public function __construct(private PerformanceReporter $reporter, private JobTiming $jobs) {}

    public function handle(QueryExecuted $event): void
    {
        // $event->time est en millisecondes ; $event->sql garde les placeholders ?
        // (bindings non interpolés => label naturellement normalisé).
        // Le contexte (backtrace compris) n'est calculé qu'au-delà du seuil.
        $this->reporter->report('query', $event->sql, (int) $event->time, fn () => [
            'connection' => $event->connectionName,
            'origin' => $this->origin(),
            'caller' => self::callerFrom(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50), base_path()),
        ]);
    }

    private function origin(): ?string
    {
        if ($job = $this->jobs->currentJob()) {
            return $job;
        }

        if (! app()->runningInConsole()) {
            $request = request();

            return $request->method().' '.($request->route()?->uri() ?? $request->path());
        }

        // Jamais l'argv complet : il peut contenir des secrets.
        return $_SERVER['argv'][1] ?? 'console';
    }

    /**
     * Première frame hors vendor/ et hors package, au format `chemin-relatif:ligne`.
     *
     * @param  array<int, array<string, mixed>>  $frames
     */
    public static function callerFrom(array $frames, string $basePath): ?string
    {
        $own = dirname(__DIR__).'/';

        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;

            if (! is_string($file) || str_contains($file, '/vendor/') || str_starts_with($file, $own)) {
                continue;
            }

            $relative = BugPayload::relativePath($file, $basePath);

            // Points d'entrée : aucune info utile sur l'appelant.
            if ($relative === 'artisan' || $relative === 'public/index.php') {
                continue;
            }

            return $relative.':'.($frame['line'] ?? 0);
        }

        return null;
    }
}
