<?php

namespace BugCollector\Client\Reporting;

use BugCollector\Client\Outbox;
use BugCollector\Client\Payloads\BugPayload;
use BugCollector\Client\Support\Gate;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Throwable;

class ErrorReporter
{
    private bool $reporting = false;

    public function __construct(
        private Gate $gate,
        private Outbox $outbox,
        private Repository $config,
        private Container $app,
    ) {}

    public function report(Throwable $e): void
    {
        // Garde de ré-entrance : si notre propre code de report lève, ne pas
        // re-capter (boucle infinie via le handler décoré).
        if ($this->reporting) {
            return;
        }

        // Ignore toute exception issue de notre namespace.
        if (str_starts_with($e::class, 'BugCollector\\Client\\')) {
            return;
        }

        if (! $this->gate->shouldReportErrors()) {
            return;
        }

        $this->reporting = true;

        try {
            $depth = (int) $this->config->get('bug-collector.errors.trace_depth', 50);
            $includeCookies = (bool) $this->config->get('bug-collector.errors.cookies', false);
            $redact = array_values(array_filter([(string) $this->config->get('session.cookie'), 'XSRF-TOKEN']));

            $this->outbox->record(
                (string) $this->config->get('bug-collector.endpoints.errors'),
                BugPayload::fromThrowable($e, $this->currentRequest(), $depth, $includeCookies, $redact),
            );
        } catch (Throwable) {
            // Sécurité : on n'impacte jamais l'app hôte.
        } finally {
            $this->reporting = false;
        }
    }

    private function currentRequest(): ?Request
    {
        try {
            if (! $this->app->bound('request')) {
                return null;
            }

            $request = $this->app->make('request');

            return $request instanceof Request ? $request : null;
        } catch (Throwable) {
            return null;
        }
    }
}
