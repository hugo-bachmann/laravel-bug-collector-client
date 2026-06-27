<?php

namespace BugCollector\Client\Transport;

interface Transport
{
    /**
     * Envoie un payload vers un chemin d'endpoint. Ne doit jamais lever.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(string $path, array $payload): void;
}
