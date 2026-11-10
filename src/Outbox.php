<?php

namespace BugCollector\Client;

use BugCollector\Client\Transport\Transport;

class Outbox
{
    /** @var array<int, array{path: string, payload: array<string, mixed>}> */
    private array $pending = [];

    /** @var array<string, true> */
    private array $seenKeys = [];

    public function __construct(private int $maxEntries = 100) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $path, array $payload): void
    {
        if (isset($payload['kind'], $payload['label'])) {
            $key = "{$path}|{$payload['kind']}|{$payload['label']}";
        } elseif (isset($payload['message'])) {
            $key = "{$path}|{$payload['message']}|".($payload['file'] ?? '').'|'.($payload['line'] ?? '');
        } else {
            $key = $path.'|'.md5(serialize($payload));
        }

        if (isset($this->seenKeys[$key])) {
            return;
        }

        if (count($this->pending) >= $this->maxEntries) {
            return;
        }

        $this->seenKeys[$key] = true;
        $this->pending[] = ['path' => $path, 'payload' => $payload];
    }

    public function flush(Transport $transport): void
    {
        $items = $this->pending;
        $this->pending = [];
        $this->seenKeys = [];

        foreach ($items as $item) {
            // Coupe-circuit : collecteur injoignable, on abandonne le reste du lot
            // (pas de remise en file) plutôt que d'attendre N × timeout.
            if (! $transport->send($item['path'], $item['payload'])) {
                break;
            }
        }
    }

    /** @return array<int, array{path: string, payload: array<string, mixed>}> */
    public function pending(): array
    {
        return $this->pending;
    }

    public function isEmpty(): bool
    {
        return $this->pending === [];
    }
}
