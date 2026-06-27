<?php

namespace BugCollector\Client\Tests\Feature;

use BugCollector\Client\Outbox;
use BugCollector\Client\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class QueryTimingTest extends TestCase
{
    public function test_a_slow_query_is_recorded(): void
    {
        config(['bug-collector.performance.thresholds.query' => 0]); // tout qualifie

        DB::select('select 1 as n');

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
