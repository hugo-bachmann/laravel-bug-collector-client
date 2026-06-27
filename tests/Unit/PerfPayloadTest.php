<?php

namespace BugCollector\Client\Tests\Unit;

use BugCollector\Client\Payloads\PerfPayload;
use BugCollector\Client\Tests\TestCase;

class PerfPayloadTest extends TestCase
{
    public function test_builds_the_perf_payload(): void
    {
        $payload = PerfPayload::make('request', 'GET /orders', 2500, ['route' => 'orders.index']);

        $this->assertSame('request', $payload['kind']);
        $this->assertSame('GET /orders', $payload['label']);
        $this->assertSame(2500, $payload['duration_ms']);
        $this->assertSame(['route' => 'orders.index'], $payload['context']);
    }

    public function test_truncates_label_to_1000(): void
    {
        $payload = PerfPayload::make('query', str_repeat('x', 1500), 1200);

        $this->assertSame(1000, strlen($payload['label']));
    }

    public function test_context_defaults_to_null(): void
    {
        $payload = PerfPayload::make('command', 'app:sync', 70000);

        $this->assertNull($payload['context']);
    }
}
