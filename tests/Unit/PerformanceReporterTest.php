<?php

namespace BugCollector\Client\Tests\Unit;

use BugCollector\Client\Outbox;
use BugCollector\Client\Reporting\PerformanceReporter;
use BugCollector\Client\Tests\TestCase;

class PerformanceReporterTest extends TestCase
{
    private function reporter(): PerformanceReporter
    {
        return $this->app->make(PerformanceReporter::class);
    }

    private function outbox(): Outbox
    {
        return $this->app->make(Outbox::class);
    }

    public function test_records_when_above_threshold(): void
    {
        config(['bug-collector.performance.thresholds.request' => 2000]);

        $this->reporter()->report('request', 'GET /orders', 2500);

        $pending = $this->outbox()->pending();
        $this->assertCount(1, $pending);
        $this->assertSame('/api/laravel-perf/add', $pending[0]['path']);
        $this->assertSame('request', $pending[0]['payload']['kind']);
    }

    public function test_ignores_when_below_threshold(): void
    {
        config(['bug-collector.performance.thresholds.request' => 2000]);

        $this->reporter()->report('request', 'GET /orders', 1999);

        $this->assertTrue($this->outbox()->isEmpty());
    }

    public function test_ignores_when_threshold_disabled_false(): void
    {
        config(['bug-collector.performance.thresholds.job' => false]);

        $this->reporter()->report('job', 'App\\Jobs\\Foo', 999999);

        $this->assertTrue($this->outbox()->isEmpty());
    }

    public function test_ignores_when_performance_flag_off(): void
    {
        config(['bug-collector.performance.enabled' => false]);

        $this->reporter()->report('request', 'GET /orders', 999999);

        $this->assertTrue($this->outbox()->isEmpty());
    }

    public function test_empty_string_threshold_falls_back_to_default(): void
    {
        // Un seuil vide (ex. BUG_COLLECTOR_THRESHOLD_REQUEST= dans le .env) ne
        // doit plus valoir 0 (= tout envoyer) mais retomber sur le défaut 2000.
        config(['bug-collector.performance.thresholds.request' => '']);

        $this->reporter()->report('request', 'GET /below', 1999);
        $this->assertTrue($this->outbox()->isEmpty());

        $this->reporter()->report('request', 'GET /above', 2500);
        $this->assertCount(1, $this->outbox()->pending());
    }

    public function test_zero_threshold_falls_back_to_default(): void
    {
        config(['bug-collector.performance.thresholds.request' => 0]);

        $this->reporter()->report('request', 'GET /below', 1999);
        $this->assertTrue($this->outbox()->isEmpty());

        $this->reporter()->report('request', 'GET /above', 2500);
        $this->assertCount(1, $this->outbox()->pending());
    }

    public function test_negative_threshold_falls_back_to_default(): void
    {
        config(['bug-collector.performance.thresholds.request' => -5]);

        $this->reporter()->report('request', 'GET /below', 1999);
        $this->assertTrue($this->outbox()->isEmpty());

        $this->reporter()->report('request', 'GET /above', 2500);
        $this->assertCount(1, $this->outbox()->pending());
    }

    public function test_non_numeric_threshold_falls_back_to_default(): void
    {
        config(['bug-collector.performance.thresholds.request' => 'abc']);

        $this->reporter()->report('request', 'GET /below', 1999);
        $this->assertTrue($this->outbox()->isEmpty());

        $this->reporter()->report('request', 'GET /above', 2500);
        $this->assertCount(1, $this->outbox()->pending());
    }
}
