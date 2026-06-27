<?php

namespace BugCollector\Client;

use BugCollector\Client\Transport\Transport;

class Outbox
{
    /** @var array<int, array{path: string, payload: array<string, mixed>}> */
    private array $pending = [];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $path, array $payload): void
    {
        $this->pending[] = ['path' => $path, 'payload' => $payload];
    }

    public function flush(Transport $transport): void
    {
        $items = $this->pending;
        $this->pending = [];

        foreach ($items as $item) {
            $transport->send($item['path'], $item['payload']);
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
