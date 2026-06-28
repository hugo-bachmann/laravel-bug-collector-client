<?php

namespace BugCollector\Client\Tests\Unit;

use BugCollector\Client\Outbox;
use BugCollector\Client\Reporting\ErrorReporter;
use BugCollector\Client\Tests\Fixtures\OwnException;
use BugCollector\Client\Tests\TestCase;
use Illuminate\Http\Request;
use RuntimeException;

class ErrorReporterTest extends TestCase
{
    private function reporter(): ErrorReporter
    {
        return $this->app->make(ErrorReporter::class);
    }

    private function outbox(): Outbox
    {
        return $this->app->make(Outbox::class);
    }

    public function test_records_an_error_payload(): void
    {
        $this->reporter()->report(new RuntimeException('Boom'));

        $pending = $this->outbox()->pending();
        $this->assertCount(1, $pending);
        $this->assertSame('/api/laravel-bugs/add', $pending[0]['path']);
        $this->assertSame('Boom', $pending[0]['payload']['message']);
    }

    public function test_ignores_when_errors_flag_off(): void
    {
        config(['bug-collector.errors.enabled' => false]);

        $this->reporter()->report(new RuntimeException('Boom'));

        $this->assertTrue($this->outbox()->isEmpty());
    }

    public function test_ignores_exceptions_from_our_own_namespace(): void
    {
        $own = new OwnException('loop');

        $this->reporter()->report($own);

        $this->assertTrue($this->outbox()->isEmpty());
    }

    public function test_cookies_are_null_in_payload_when_config_cookies_off(): void
    {
        // cookies opt-in is false by default — no cookies should reach the payload.
        config(['bug-collector.errors.cookies' => false]);

        $this->reporter()->report(new RuntimeException('Boom'));

        $pending = $this->outbox()->pending();
        $this->assertCount(1, $pending);
        $this->assertNull($pending[0]['payload']['cookies']);
    }

    public function test_configured_cookie_names_are_redacted(): void
    {
        config([
            'bug-collector.errors.cookies' => true,
            'bug-collector.errors.redact_cookies' => 'tenant_id, locale_pref',
        ]);

        $request = Request::create('https://app.test/', 'GET', cookies: [
            'tenant_id' => 'acme',
            'locale_pref' => 'fr',
            'theme' => 'dark',
        ]);
        $this->app->instance('request', $request);

        $this->reporter()->report(new RuntimeException('Boom'));

        $cookies = $this->outbox()->pending()[0]['payload']['cookies'];
        $this->assertSame('[REDACTED]', $cookies['tenant_id']);
        $this->assertSame('[REDACTED]', $cookies['locale_pref']);
        $this->assertSame('dark', $cookies['theme']);
    }

    public function test_reentrancy_guard_blocks_nested_report(): void
    {
        $reporter = $this->app->make(ErrorReporter::class);

        $ref = new \ReflectionProperty($reporter, 'reporting');
        $ref->setValue($reporter, true); // simulate "already reporting"

        $reporter->report(new RuntimeException('Nested boom'));

        $this->assertTrue($this->app->make(Outbox::class)->isEmpty());
    }
}
