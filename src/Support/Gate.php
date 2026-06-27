<?php

namespace BugCollector\Client\Support;

use Illuminate\Contracts\Config\Repository;

class Gate
{
    public function __construct(private Repository $config) {}

    /**
     * Coupe-circuit global : activé seulement si la config réseau est complète.
     */
    public function enabled(): bool
    {
        if (! $this->config->get('bug-collector.enabled', true)) {
            return false;
        }

        return ! empty($this->config->get('bug-collector.base_url'))
            && ! empty($this->config->get('bug-collector.ingest_key'));
    }

    public function shouldReportErrors(): bool
    {
        return $this->enabled()
            && (bool) $this->config->get('bug-collector.errors.enabled', true);
    }

    public function shouldReportPerformance(): bool
    {
        return $this->enabled()
            && (bool) $this->config->get('bug-collector.performance.enabled', true);
    }
}
