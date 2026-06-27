<?php

namespace BugCollector\Client\Tests\Feature;

use BugCollector\Client\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

class RequestTimingTest extends TestCase
{
    public function test_a_slow_request_is_reported(): void
    {
        config(['bug-collector.performance.thresholds.request' => 0]); // tout qualifie
        Http::fake();

        Route::get('/slow', fn () => 'ok');

        $this->get('/slow')->assertOk();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://collector.test/api/laravel-perf/add'
                && $request['kind'] === 'request'
                && str_contains($request['label'], 'slow');
        });
    }

    public function test_a_fast_request_under_threshold_is_not_reported(): void
    {
        config(['bug-collector.performance.thresholds.request' => 999999]);
        Http::fake();

        Route::get('/fast', fn () => 'ok');

        $this->get('/fast')->assertOk();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'laravel-perf'));
    }
}
