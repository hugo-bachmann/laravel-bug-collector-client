<?php

namespace BugCollector\Client\Tests\Unit;

use BugCollector\Client\Tests\TestCase;
use BugCollector\Client\Transport\HttpTransport;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

class HttpTransportTest extends TestCase
{
    private function transport(): HttpTransport
    {
        return $this->app->make(HttpTransport::class);
    }

    public function test_posts_to_base_url_plus_path_with_ingest_key_header(): void
    {
        Http::fake();

        $this->transport()->send('/api/laravel-bugs/add', ['message' => 'Boom']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://collector.test/api/laravel-bugs/add'
                && $request->hasHeader('X-Ingest-Key', 'test-key')
                && $request['message'] === 'Boom';
        });
    }

    public function test_trims_trailing_slash_on_base_url(): void
    {
        config(['bug-collector.base_url' => 'https://collector.test/']);
        Http::fake();

        $this->transport()->send('/api/laravel-perf/add', ['kind' => 'request']);

        Http::assertSent(fn ($request) => $request->url() === 'https://collector.test/api/laravel-perf/add');
    }

    public function test_swallows_network_errors_silently(): void
    {
        Http::fake(fn () => throw new \RuntimeException('network down'));

        // Ne doit lever aucune exception.
        $this->transport()->send('/api/laravel-bugs/add', ['message' => 'Boom']);

        $this->assertTrue(true);
    }
}
