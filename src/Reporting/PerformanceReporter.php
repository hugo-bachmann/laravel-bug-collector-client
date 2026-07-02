<?php

namespace BugCollector\Client\Reporting;

use BugCollector\Client\Outbox;
use BugCollector\Client\Payloads\PerfPayload;
use BugCollector\Client\Support\Gate;
use Illuminate\Contracts\Config\Repository;
use Throwable;

class PerformanceReporter
{
    /**
     * Seuils par défaut (ms) par type — source unique de vérité, utilisée comme
     * repli quand la valeur configurée est invalide (vide, non numérique, <= 0).
     *
     * @var array<string, int>
     */
    private const DEFAULT_THRESHOLDS = [
        'request' => 2000,
        'job' => 30000,
        'query' => 1000,
        'command' => 60000,
    ];

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

            $threshold = $this->resolveThreshold($kind);

            // null => type désactivé (false/null explicites, ou type inconnu).
            if ($threshold === null) {
                return;
            }

            if ($durationMs < $threshold) {
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

    /**
     * Résout le seuil effectif (ms) pour un type, ou null si le type est désactivé.
     *
     * - `false` / `null` explicites => désactivé.
     * - numérique >= 1 => utilisé tel quel.
     * - valeur invalide (vide, non numérique, 0, négatif) => repli sur le défaut
     *   du type, pour qu'une saisie erronée ne désactive jamais le filtre en
     *   silence (ce qui reviendrait à tout envoyer).
     * - type inconnu sans défaut => désactivé, par sécurité.
     */
    private function resolveThreshold(string $kind): ?int
    {
        $configured = $this->config->get("bug-collector.performance.thresholds.{$kind}");

        if ($configured === false || $configured === null) {
            return null;
        }

        if (is_numeric($configured) && (int) $configured >= 1) {
            return (int) $configured;
        }

        return self::DEFAULT_THRESHOLDS[$kind] ?? null;
    }
}
