<?php

namespace BugCollector\Client\Tests\Unit;

use BugCollector\Client\Support\Gate;
use BugCollector\Client\Tests\TestCase;

class GateTest extends TestCase
{
    private function gate(): Gate
    {
        return $this->app->make(Gate::class);
    }

    public function test_enabled_when_config_is_complete(): void
    {
        $this->assertTrue($this->gate()->enabled());
    }

    public function test_disabled_when_base_url_missing(): void
    {
        config(['bug-collector.base_url' => null]);
        $this->assertFalse($this->gate()->enabled());
    }

    public function test_disabled_when_ingest_key_missing(): void
    {
        config(['bug-collector.ingest_key' => '']);
        $this->assertFalse($this->gate()->enabled());
    }

    public function test_disabled_when_global_flag_off(): void
    {
        config(['bug-collector.enabled' => false]);
        $this->assertFalse($this->gate()->enabled());
    }

    public function test_errors_flag_gates_error_reporting(): void
    {
        config(['bug-collector.errors.enabled' => false]);
        $this->assertFalse($this->gate()->shouldReportErrors());
        $this->assertTrue($this->gate()->shouldReportPerformance());
    }

    public function test_performance_flag_gates_perf_reporting(): void
    {
        config(['bug-collector.performance.enabled' => false]);
        $this->assertFalse($this->gate()->shouldReportPerformance());
        $this->assertTrue($this->gate()->shouldReportErrors());
    }
}
