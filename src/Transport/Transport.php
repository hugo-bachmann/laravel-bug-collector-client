<?php

namespace BugCollector\Client\Transport;

interface Transport
{
    /**
     * Envoie un payload vers un chemin d'endpoint. Ne doit jamais lever.
     * Retourne true si le collecteur a accepté (2xx), false sinon (non 2xx, timeout, DNS…).
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(string $path, array $payload): bool;
}
