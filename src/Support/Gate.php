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

        $baseUrl = (string) $this->config->get('bug-collector.base_url');

        return $baseUrl !== ''
            && ! empty($this->config->get('bug-collector.ingest_key'))
            && $this->isSecureBaseUrl($baseUrl);
    }

    /**
     * Exige HTTPS pour ne jamais envoyer la clé d'ingestion ni les données en clair.
     * Tolère http sur localhost / 127.0.0.1 / ::1 pour le développement local.
     */
    private function isSecureBaseUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme === 'https') {
            return true;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, ['localhost', '127.0.0.1', '::1'], strict: true);
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
