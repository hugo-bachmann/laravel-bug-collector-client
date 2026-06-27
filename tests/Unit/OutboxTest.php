<?php

namespace BugCollector\Client\Tests\Unit;

use BugCollector\Client\Outbox;
use BugCollector\Client\Tests\TestCase;
use BugCollector\Client\Transport\Transport;

class OutboxTest extends TestCase
{
    public function test_record_then_pending_reflects_entries(): void
    {
        $outbox = new Outbox;
        $this->assertTrue($outbox->isEmpty());

        $outbox->record('/errors', ['a' => 1]);

        $this->assertFalse($outbox->isEmpty());
        $this->assertCount(1, $outbox->pending());
    }

    public function test_flush_sends_each_entry_once_then_empties(): void
    {
        $outbox = new Outbox;
        $outbox->record('/errors', ['a' => 1]);
        $outbox->record('/perf', ['b' => 2]);

        $sent = [];
        $transport = new class($sent) implements Transport
        {
            public function __construct(public array &$sent) {}

            public function send(string $path, array $payload): void
            {
                $this->sent[] = [$path, $payload];
            }
        };

        $outbox->flush($transport);

        $this->assertSame([['/errors', ['a' => 1]], ['/perf', ['b' => 2]]], $sent);
        $this->assertTrue($outbox->isEmpty());

        // Un second flush ne renvoie rien.
        $outbox->flush($transport);
        $this->assertCount(2, $sent);
    }
}
