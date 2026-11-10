<?php

namespace BugCollector\Client\Tests\Feature;

use BugCollector\Client\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

class RequestTimingTest extends TestCase
{
    public function test_a_slow_request_is_reported(): void
    {
        config(['bug-collector.performance.thresholds.request' => 1]);
        Http::fake();

        Route::get('/slow', function () {
            usleep(2000); // durée mesurable, au-dessus du seuil de 1 ms

            return 'ok';
        });

        $this->get('/slow')->assertOk();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://collector.test/api/laravel-perf/add'
                && $request['kind'] === 'request'
                && str_contains($request['label'], 'slow');
        });
    }

    public function test_streamed_and_file_responses_are_not_timed(): void
    {
        config(['bug-collector.performance.thresholds.request' => 1]);
        Http::fake();

        Route::get('/stream', fn () => response()->stream(function () {
            usleep(2000);
            echo 'chunk';
        }));
        Route::get('/file', function () {
            usleep(2000);

            return response()->download(__FILE__);
        });

        $this->get('/stream')->assertOk();
        $this->get('/file')->assertOk();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'laravel-perf'));
    }

    public function test_a_fast_request_under_threshold_is_not_reported(): void
    {
        config(['bug-collector.performance.thresholds.request' => 999999]);
        Http::fake();

        Route::get('/fast', fn () => 'ok');

        $this->get('/fast')->assertOk();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'laravel-perf'));
    }

    public function test_duration_starts_at_request_time_float(): void
    {
        config(['bug-collector.performance.thresholds.request' => 1000]);
        Http::fake();

        Route::get('/fast', fn () => 'ok');

        $this->call('GET', '/fast', server: ['REQUEST_TIME_FLOAT' => microtime(true) - 5])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'laravel-perf')
            && $request['duration_ms'] >= 5000);
    }

    public function test_stale_request_time_float_falls_back_to_middleware_start(): void
    {
        config(['bug-collector.performance.thresholds.request' => 1000]);
        Http::fake();

        Route::get('/fast', fn () => 'ok');

        $this->call('GET', '/fast', server: ['REQUEST_TIME_FLOAT' => microtime(true) - 7200])->assertOk();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'laravel-perf'));
    }
}
