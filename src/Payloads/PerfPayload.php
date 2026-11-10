<?php

namespace BugCollector\Client\Payloads;

class PerfPayload
{
    /**
     * Construit le payload perf conforme au contrat /api/laravel-perf/add.
     *
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    public static function make(string $kind, string $label, int $durationMs, ?array $context = null): array
    {
        return [
            'kind' => $kind,
            'label' => mb_strcut($label, 0, 1000, 'UTF-8'),
            'duration_ms' => max(0, $durationMs),
            'context' => $context,
        ];
    }
}
