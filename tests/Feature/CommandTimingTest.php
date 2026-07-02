<?php

namespace BugCollector\Client\Tests\Feature;

use BugCollector\Client\Instrumentation\CommandTiming;
use BugCollector\Client\Outbox;
use BugCollector\Client\Tests\TestCase;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class CommandTimingTest extends TestCase
{
    public function test_a_slow_command_is_recorded(): void
    {
        config(['bug-collector.performance.thresholds.command' => 1]);

        $collector = $this->app->make(CommandTiming::class);
        $input = new ArrayInput([]);
        $output = new BufferedOutput;

        $collector->starting(new CommandStarting('app:sync', $input, $output));
        usleep(2000); // durée mesurable, au-dessus du seuil de 1 ms
        $collector->finished(new CommandFinished('app:sync', $input, $output, 0));

        $pending = $this->app->make(Outbox::class)->pending();
        $this->assertCount(1, $pending);
        $this->assertSame('command', $pending[0]['payload']['kind']);
        $this->assertSame('app:sync', $pending[0]['payload']['label']);
    }

    public function test_finished_without_start_records_nothing(): void
    {
        config(['bug-collector.performance.thresholds.command' => 0]);

        $collector = $this->app->make(CommandTiming::class);
        $collector->finished(new CommandFinished('app:orphan', new ArrayInput([]), new BufferedOutput, 0));

        $this->assertTrue($this->app->make(Outbox::class)->isEmpty());
    }
}
