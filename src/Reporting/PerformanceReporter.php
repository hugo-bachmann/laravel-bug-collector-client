<?php

namespace BugCollector\Client\Reporting;

use BugCollector\Client\Outbox;
use BugCollector\Client\Payloads\PerfPayload;
use BugCollector\Client\Support\Gate;
use Illuminate\Contracts\Config\Repository;
use Throwable;

class PerformanceReporter
{
    public function __construct(
        private Gate $gate,
        private Outbox $outbox,
        private Repository $config,
    ) {}

    /**
     * @param  array<string, mixed>|null  $context
     */
    public function report(string $kind, string $label, int $durationMs, ?array $context = null): void
    {
        try {
            if (! $this->gate->shouldReportPerformance()) {
                return;
            }

            $threshold = $this->config->get("bug-collector.performance.thresholds.{$kind}");

            // false ou null => type désactivé.
            if ($threshold === false || $threshold === null) {
                return;
            }

            if ($durationMs < (int) $threshold) {
                return;
            }

            $this->outbox->record(
                (string) $this->config->get('bug-collector.endpoints.performance'),
                PerfPayload::make($kind, $label, $durationMs, $context),
            );
        } catch (Throwable) {
            // Fail-safe : la collecte ne casse jamais l'app hôte.
        }
    }
}
