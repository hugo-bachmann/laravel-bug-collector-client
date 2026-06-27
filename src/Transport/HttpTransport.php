<?php

namespace BugCollector\Client\Transport;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;
use Throwable;

class HttpTransport implements Transport
{
    public function __construct(
        private Factory $http,
        private Repository $config,
    ) {}

    public function send(string $path, array $payload): void
    {
        try {
            $base = rtrim((string) $this->config->get('bug-collector.base_url'), '/');

            $this->http
                ->timeout((int) $this->config->get('bug-collector.timeout', 2))
                ->acceptJson()
                ->withHeaders(['X-Ingest-Key' => (string) $this->config->get('bug-collector.ingest_key')])
                ->post($base.$path, $payload);
        } catch (Throwable) {
            // Fail-safe silencieux total : une panne du collecteur ne doit
            // jamais impacter l'app hôte.
        }
    }
}
