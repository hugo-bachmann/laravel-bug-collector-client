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

            public function send(string $path, array $payload): bool
            {
                $this->sent[] = [$path, $payload];

                return true;
            }
        };

        $outbox->flush($transport);

        $this->assertSame([['/errors', ['a' => 1]], ['/perf', ['b' => 2]]], $sent);
        $this->assertTrue($outbox->isEmpty());

        // Un second flush ne renvoie rien.
        $outbox->flush($transport);
        $this->assertCount(2, $sent);
    }

    public function test_dedup_perf_same_kind_and_label_collapses_to_one(): void
    {
        $outbox = new Outbox;
        $outbox->record('/perf', ['kind' => 'query', 'label' => 'select * from users', 'duration_ms' => 120]);
        $outbox->record('/perf', ['kind' => 'query', 'label' => 'select * from users', 'duration_ms' => 340]);

        $this->assertCount(1, $outbox->pending());
    }

    public function test_dedup_perf_distinct_labels_are_not_deduped(): void
    {
        $outbox = new Outbox;
        $outbox->record('/perf', ['kind' => 'query', 'label' => 'select * from users', 'duration_ms' => 120]);
        $outbox->record('/perf', ['kind' => 'query', 'label' => 'select * from posts', 'duration_ms' => 200]);

        $this->assertCount(2, $outbox->pending());
    }

    public function test_dedup_error_same_message_file_line_collapses_to_one(): void
    {
        $outbox = new Outbox;
        $outbox->record('/errors', ['message' => 'Undefined variable', 'file' => '/app/Foo.php', 'line' => 42]);
        $outbox->record('/errors', ['message' => 'Undefined variable', 'file' => '/app/Foo.php', 'line' => 42]);

        $this->assertCount(1, $outbox->pending());
    }

    public function test_cap_drops_entries_beyond_max(): void
    {
        $outbox = new Outbox(2);
        $outbox->record('/perf', ['kind' => 'query', 'label' => 'q1', 'duration_ms' => 10]);
        $outbox->record('/perf', ['kind' => 'query', 'label' => 'q2', 'duration_ms' => 20]);
        $outbox->record('/perf', ['kind' => 'query', 'label' => 'q3', 'duration_ms' => 30]);

        $this->assertCount(2, $outbox->pending());
    }

    public function test_flush_resets_dedup_so_same_payload_is_accepted_again(): void
    {
        $outbox = new Outbox;
        $outbox->record('/errors', ['message' => 'Oops', 'file' => '/app/Bar.php', 'line' => 7]);

        $sent = [];
        $transport = new class($sent) implements Transport
        {
            public function __construct(public array &$sent) {}

            public function send(string $path, array $payload): bool
            {
                $this->sent[] = [$path, $payload];

                return true;
            }
        };

        $outbox->flush($transport);
        $this->assertCount(0, $outbox->pending());

        // Après flush, la même entrée doit être acceptée à nouveau.
        $outbox->record('/errors', ['message' => 'Oops', 'file' => '/app/Bar.php', 'line' => 7]);
        $this->assertCount(1, $outbox->pending());
    }

    public function test_flush_stops_after_first_failed_send(): void
    {
        $outbox = new Outbox;
        $outbox->record('/a', ['a' => 1]);
        $outbox->record('/b', ['b' => 2]);
        $outbox->record('/c', ['c' => 3]);

        $calls = 0;
        $transport = new class($calls) implements Transport
        {
            public function __construct(public int &$calls) {}

            public function send(string $path, array $payload): bool
            {
                $this->calls++;

                return false;
            }
        };

        $outbox->flush($transport);

        $this->assertSame(1, $calls);
        $this->assertTrue($outbox->isEmpty());
    }
}
