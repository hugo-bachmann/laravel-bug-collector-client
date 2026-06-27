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
}
