<?php

namespace BugCollector\Client\Console;

use BugCollector\Client\Payloads\BugPayload;
use BugCollector\Client\Support\Gate;
use BugCollector\Client\Transport\Transport;
use Illuminate\Console\Command;
use RuntimeException;

class TestCommand extends Command
{
    protected $signature = 'bug-collector:test';

    protected $description = 'Envoie une exception de test au Bug Collector pour vérifier la configuration';

    public function handle(Gate $gate, Transport $transport): int
    {
        if (($reason = $gate->disabledReason()) !== null) {
            $this->error($reason);

            return 1;
        }

        $payload = BugPayload::fromThrowable(
            new RuntimeException('Bug Collector : exception de test'),
            null,
            basePath: base_path(),
        );

        // Envoi direct (sans Outbox) pour connaître le résultat.
        if ($transport->send((string) config('bug-collector.endpoints.errors'), $payload)) {
            $this->info('Exception de test envoyée.');

            return 0;
        }

        $this->error("Le collecteur a refusé ou n'a pas répondu.");

        return 1;
    }
}
