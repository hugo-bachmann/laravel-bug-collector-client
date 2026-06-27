<?php

namespace BugCollector\Client\Instrumentation;

use BugCollector\Client\Reporting\PerformanceReporter;
use Illuminate\Database\Events\QueryExecuted;

/** Reporte les requêtes SQL dont la durée dépasse le seuil configuré. */
class QueryTiming
{
    public function __construct(private PerformanceReporter $reporter) {}

    public function handle(QueryExecuted $event): void
    {
        // $event->time est en millisecondes ; $event->sql garde les placeholders ?
        // (bindings non interpolés => label naturellement normalisé).
        $this->reporter->report('query', $event->sql, (int) $event->time);
    }
}
