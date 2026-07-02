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
}
