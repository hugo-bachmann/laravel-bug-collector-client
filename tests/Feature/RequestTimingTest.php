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

    public function test_a_fast_request_under_threshold_is_not_reported(): void
    {
        config(['bug-collector.performance.thresholds.request' => 999999]);
        Http::fake();

        Route::get('/fast', fn () => 'ok');

        $this->get('/fast')->assertOk();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'laravel-perf'));
    }
}
