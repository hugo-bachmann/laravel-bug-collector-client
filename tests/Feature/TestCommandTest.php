<?php

namespace BugCollector\Client\Tests\Feature;

use BugCollector\Client\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TestCommandTest extends TestCase
{
    public function test_success(): void
    {
        Http::fake(['*' => Http::response('', 202)]);

        $this->artisan('bug-collector:test')
            ->expectsOutputToContain('envoyée')
            ->assertExitCode(0);

        Http::assertSent(fn ($r) => $r['class'] === RuntimeException::class);
    }

    public function test_refused(): void
    {
        Http::fake(['*' => Http::response('', 401)]);

        $this->artisan('bug-collector:test')->assertExitCode(1);
    }

    public function test_misconfigured(): void
    {
        Http::fake();
        config(['bug-collector.base_url' => '']);

        $this->artisan('bug-collector:test')
            ->expectsOutputToContain('BUG_COLLECTOR_URL est manquant.')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }
}
