<?php

namespace BugCollector\Client\Tests\Feature;

use BugCollector\Client\Instrumentation\JobTiming;
use BugCollector\Client\Tests\TestCase;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class JobTimingTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_a_slow_job_is_reported_and_flushed_immediately(): void
    {
        config(['bug-collector.performance.thresholds.job' => 1]);
        Http::fake();

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('getJobId')->andReturn('job-1');
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SendInvoice');

        $collector = $this->app->make(JobTiming::class);
        $collector->processing(new JobProcessing('sync', $job));
        usleep(2000); // durée mesurable, au-dessus du seuil de 1 ms
        $collector->processed(new JobProcessed('sync', $job));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://collector.test/api/laravel-perf/add'
                && $request['kind'] === 'job'
                && $request['label'] === 'App\\Jobs\\SendInvoice';
        });
    }
}
