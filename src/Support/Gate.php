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
        return $this->disabledReason() === null;
    }

    /**
     * Raison pour laquelle le client est désactivé, ou null s'il est actif.
     */
    public function disabledReason(): ?string
    {
        if (! $this->config->get('bug-collector.enabled', true)) {
            return 'BUG_COLLECTOR_ENABLED est à false.';
        }

        $baseUrl = (string) $this->config->get('bug-collector.base_url');

        if ($baseUrl === '') {
            return 'BUG_COLLECTOR_URL est manquant.';
        }

        if (empty($this->config->get('bug-collector.ingest_key'))) {
            return 'BUG_COLLECTOR_INGEST_KEY est manquant.';
        }

        if (! $this->isSecureBaseUrl($baseUrl)) {
            return 'BUG_COLLECTOR_URL doit être en HTTPS (http toléré uniquement sur localhost).';
        }

        return null;
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
