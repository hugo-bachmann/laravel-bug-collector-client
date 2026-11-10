<?php

namespace BugCollector\Client\Tests\Unit;

use BugCollector\Client\Payloads\BugPayload;
use BugCollector\Client\Tests\TestCase;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BugPayloadTest extends TestCase
{
    public function test_maps_a_generic_exception_to_type_500(): void
    {
        $payload = BugPayload::fromThrowable(new RuntimeException('Boom'), null);

        $this->assertSame('Boom', $payload['message']);
        $this->assertSame(500, $payload['type']);
        $this->assertIsArray($payload['stackTrace']);
        $this->assertSame('console', $payload['url']);
        $this->assertSame('127.0.0.1', $payload['ip']);
        $this->assertNull($payload['userAgent']);
    }

    public function test_uses_http_status_code_for_http_exceptions(): void
    {
        $payload = BugPayload::fromThrowable(new NotFoundHttpException('Nope'), null);

        $this->assertSame(404, $payload['type']);
    }

    public function test_empty_message_falls_back_to_class_name(): void
    {
        $payload = BugPayload::fromThrowable(new RuntimeException(''), null);

        $this->assertSame(RuntimeException::class, $payload['message']);
    }

    public function test_truncates_long_message_to_1000(): void
    {
        $payload = BugPayload::fromThrowable(new RuntimeException(str_repeat('a', 1500)), null);

        $this->assertSame(1000, strlen($payload['message']));
    }

    public function test_trace_frames_drop_args_and_respect_depth(): void
    {
        $payload = BugPayload::fromThrowable(new RuntimeException('x'), null, 2);

        $this->assertCount(2, $payload['stackTrace']);
        foreach ($payload['stackTrace'] as $frame) {
            $this->assertArrayNotHasKey('args', $frame);
            $this->assertArrayHasKey('function', $frame);
            $this->assertArrayHasKey('file', $frame);
            $this->assertArrayHasKey('line', $frame);
            $this->assertArrayHasKey('class', $frame);
        }
    }

    public function test_pulls_url_ip_and_useragent_from_request(): void
    {
        $request = Request::create('https://app.test/orders?x=1', 'GET', server: [
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_USER_AGENT' => 'PHPUnit',
        ]);

        $payload = BugPayload::fromThrowable(new RuntimeException('x'), $request);

        $this->assertStringStartsWith('https://app.test/orders', $payload['url']);
        $this->assertSame('203.0.113.7', $payload['ip']);
        $this->assertSame('PHPUnit', $payload['userAgent']);
    }

    public function test_cookies_are_null_by_default_even_with_request(): void
    {
        $request = Request::create('https://app.test/', 'GET', cookies: [
            'laravel_session' => 'secret123',
            'theme' => 'dark',
        ]);

        $payload = BugPayload::fromThrowable(new RuntimeException('x'), $request);

        $this->assertNull($payload['cookies']);
    }

    public function test_cookies_are_included_and_sensitive_ones_redacted_when_opt_in(): void
    {
        $request = Request::create('https://app.test/', 'GET', cookies: [
            'laravel_session' => 'secret123',
            'XSRF-TOKEN' => 'csrf_value',
            'remember_web_abc' => 'remember_token',
            'theme' => 'dark',
        ]);

        $payload = BugPayload::fromThrowable(
            new RuntimeException('x'),
            $request,
            includeCookies: true,
            redactCookieNames: ['laravel_session', 'XSRF-TOKEN'],
        );

        $this->assertIsArray($payload['cookies']);
        $this->assertSame('[REDACTED]', $payload['cookies']['laravel_session']);
        $this->assertSame('[REDACTED]', $payload['cookies']['XSRF-TOKEN']);
        $this->assertSame('[REDACTED]', $payload['cookies']['remember_web_abc']);
        $this->assertSame('dark', $payload['cookies']['theme']);
    }

    public function test_redacts_cookies_matching_sensitive_fragments(): void
    {
        // Filet deny élargi : noms custom contenant un motif sensible (token,
        // auth, session, csrf, secret, password) sont masqués sans liste explicite.
        $request = Request::create('https://app.test/', 'GET', cookies: [
            'auth_token' => 'jwt_value',
            'My_Session_Id' => 'sess',
            'theme' => 'dark',
        ]);

        $payload = BugPayload::fromThrowable(
            new RuntimeException('x'),
            $request,
            includeCookies: true,
        );

        $this->assertSame('[REDACTED]', $payload['cookies']['auth_token']);
        $this->assertSame('[REDACTED]', $payload['cookies']['My_Session_Id']);
        $this->assertSame('dark', $payload['cookies']['theme']);
    }

    public function test_redacts_sensitive_query_params(): void
    {
        $request = Request::create('https://app.test/reset?token=abc&page=2&Signature=xyz', 'GET');

        $payload = BugPayload::fromThrowable(new RuntimeException('x'), $request);

        $this->assertStringContainsString('token=%5BREDACTED%5D', $payload['url']);
        $this->assertStringContainsString('Signature=%5BREDACTED%5D', $payload['url']);
        $this->assertStringContainsString('page=2', $payload['url']);
        $this->assertStringNotContainsString('abc', $payload['url']);
        $this->assertStringNotContainsString('xyz', $payload['url']);
    }

    public function test_leaves_url_without_query_untouched(): void
    {
        $request = Request::create('https://app.test/orders/42', 'GET');

        $payload = BugPayload::fromThrowable(new RuntimeException('x'), $request);

        $this->assertSame('https://app.test/orders/42', $payload['url']);
    }

    public function test_class_is_sent(): void
    {
        $payload = BugPayload::fromThrowable(new RuntimeException('x'), null);

        $this->assertSame(RuntimeException::class, $payload['class']);
        $this->assertSame([], $payload['previous']);
    }

    public function test_previous_chain_is_capped_at_five(): void
    {
        $e = new RuntimeException('cause 0');
        for ($i = 1; $i < 7; $i++) {
            $e = new RuntimeException("cause $i", 0, $e);
        }
        $direct = $e->getPrevious();

        $payload = BugPayload::fromThrowable($e, null);

        $this->assertCount(5, $payload['previous']);
        $this->assertSame($direct->getMessage(), $payload['previous'][0]['message']);
        $this->assertSame(RuntimeException::class, $payload['previous'][0]['class']);
    }

    public function test_paths_are_made_relative_to_base_path(): void
    {
        $base = dirname(__DIR__, 2);
        $payload = BugPayload::fromThrowable(new RuntimeException('x', 0, new RuntimeException('y')), null, 50, false, [], $base);

        $this->assertSame('tests/Unit/BugPayloadTest.php', $payload['file']);
        $this->assertSame('tests/Unit/BugPayloadTest.php', $payload['previous'][0]['file']);
        foreach ($payload['stackTrace'] as $frame) {
            $this->assertFalse(str_starts_with((string) $frame['file'], $base));
        }
    }

    public function test_paths_outside_base_path_stay_absolute(): void
    {
        $payload = BugPayload::fromThrowable(new RuntimeException('x'), null, 50, false, [], '/nonexistent');

        $this->assertSame(__FILE__, $payload['file']);
    }
}
