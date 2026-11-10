<?php

namespace BugCollector\Client\Tests\Feature;

use BugCollector\Client\Instrumentation\QueryTiming;
use BugCollector\Client\Outbox;
use BugCollector\Client\Tests\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

class QueryTimingTest extends TestCase
{
    public function test_a_slow_query_is_recorded(): void
    {
        config(['bug-collector.performance.thresholds.query' => 1]);

        // La durée (5 ms) est injectée via l'évènement, à la manière des tests
        // command/job qui pilotent leur collecteur directement — déterministe,
        // sans dépendre du temps réel d'un « select 1 ».
        $this->app->make(QueryTiming::class)->handle(
            new QueryExecuted('select 1 as n', [], 5.0, DB::connection())
        );

        $pending = $this->app->make(Outbox::class)->pending();
        $this->assertNotEmpty($pending);
        $this->assertSame('query', $pending[0]['payload']['kind']);
        $this->assertStringContainsString('select 1', $pending[0]['payload']['label']);
    }

    public function test_a_fast_query_under_threshold_is_ignored(): void
    {
        config(['bug-collector.performance.thresholds.query' => 999999]);

        DB::select('select 1 as n');

        $this->assertTrue($this->app->make(Outbox::class)->isEmpty());
    }

    public function test_slow_query_context_has_connection_and_caller(): void
    {
        config(['bug-collector.performance.thresholds.query' => 1]);

        $this->app->make(QueryTiming::class)->handle(
            new QueryExecuted('select 1', [], 50, DB::connection())
        );

        $context = $this->app->make(Outbox::class)->pending()[0]['payload']['context'];
        $this->assertSame('testing', $context['connection']);
        $this->assertStringContainsString('QueryTimingTest.php', $context['caller']);
    }

    public function test_caller_is_null_when_only_vendor_frames(): void
    {
        $this->assertNull(QueryTiming::callerFrom([['file' => '/app/vendor/x/Y.php', 'line' => 1]], '/app'));
        $this->assertSame('app/Foo.php:9', QueryTiming::callerFrom([
            ['file' => '/app/vendor/x/Y.php', 'line' => 1],
            ['file' => '/app/app/Foo.php', 'line' => 9],
        ], '/app'));
    }
}
