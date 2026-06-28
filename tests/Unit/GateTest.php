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

    public function test_disabled_when_base_url_not_https(): void
    {
        // Un base_url en clair exposerait la clé d'ingestion + les données : on coupe.
        config(['bug-collector.base_url' => 'http://collector.test']);
        $this->assertFalse($this->gate()->enabled());
    }

    public function test_enabled_for_https_base_url(): void
    {
        config(['bug-collector.base_url' => 'https://collector.test']);
        $this->assertTrue($this->gate()->enabled());
    }

    public function test_allows_http_on_localhost(): void
    {
        // Tolérance dev local : http sur localhost/127.0.0.1 reste accepté.
        config(['bug-collector.base_url' => 'http://localhost:8080']);
        $this->assertTrue($this->gate()->enabled());

        config(['bug-collector.base_url' => 'http://127.0.0.1:8080']);
        $this->assertTrue($this->gate()->enabled());
    }
}
