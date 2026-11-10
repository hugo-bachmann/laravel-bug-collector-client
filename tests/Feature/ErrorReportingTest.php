<?php

namespace BugCollector\Client\Tests\Feature;

use BugCollector\Client\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

class ErrorReportingTest extends TestCase
{
    public function test_an_uncaught_exception_is_reported_after_response(): void
    {
        Http::fake();

        Route::get('/boom', function () {
            throw new RuntimeException('Kaboom');
        });

        $this->get('/boom'); // 500 attendu

        Http::assertSent(function ($request) {
            return $request->url() === 'https://collector.test/api/laravel-bugs/add'
                && $request->hasHeader('X-Ingest-Key', 'test-key')
                && $request['message'] === 'Kaboom'
                && $request['type'] === 500;
        });
    }

    public function test_ignored_exception_is_not_sent(): void
    {
        $this->throwWithIgnoredLogicException(new LogicException('Ignored'));

        Http::assertNothingSent();
    }

    public function test_subclass_of_ignored_exception_is_not_sent(): void
    {
        $this->throwWithIgnoredLogicException(new InvalidArgumentException('Ignored child'));

        Http::assertNothingSent();
    }

    public function test_non_ignored_exception_is_still_sent(): void
    {
        $this->throwWithIgnoredLogicException(new RuntimeException('Kept'));

        Http::assertSent(fn ($request) => $request['message'] === 'Kept');
    }

    private function throwWithIgnoredLogicException(Throwable $e): void
    {
        config(['bug-collector.ignore' => [LogicException::class]]);
        Http::fake();

        Route::get('/boom', function () use ($e) {
            throw $e;
        });

        $this->get('/boom');
    }

    public function test_nothing_is_sent_when_globally_disabled(): void
    {
        config(['bug-collector.base_url' => null]);
        Http::fake();

        Route::get('/boom', function () {
            throw new RuntimeException('Kaboom');
        });

        $this->get('/boom');

        Http::assertNothingSent();
    }
}
