<?php

namespace BugCollector\Client\Tests\Unit;

use BugCollector\Client\Instrumentation\HandlerDecorator;
use BugCollector\Client\Reporting\ErrorReporter;
use BugCollector\Client\Tests\TestCase;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;

class HandlerDecoratorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_report_calls_reporter_then_delegates_to_inner(): void
    {
        $e = new RuntimeException('Boom');

        $inner = Mockery::mock(ExceptionHandler::class);
        $inner->shouldReceive('shouldReport')->once()->with($e)->andReturnTrue();
        $inner->shouldReceive('report')->once()->with($e);

        $reporter = Mockery::mock(ErrorReporter::class);
        $reporter->shouldReceive('report')->once()->with($e);

        (new HandlerDecorator($inner, $reporter))->report($e);
    }

    public function test_report_still_delegates_when_reporter_throws(): void
    {
        $e = new RuntimeException('Boom');

        $inner = Mockery::mock(ExceptionHandler::class);
        $inner->shouldReceive('shouldReport')->once()->with($e)->andReturnTrue();
        $inner->shouldReceive('report')->once()->with($e);

        $reporter = Mockery::mock(ErrorReporter::class);
        $reporter->shouldReceive('report')->andThrow(new RuntimeException('reporter exploded'));

        // Ne doit pas lever : la délégation au handler d'origine reste garantie.
        (new HandlerDecorator($inner, $reporter))->report($e);
    }

    public function test_report_skips_reporter_when_inner_should_not_report(): void
    {
        $e = new RuntimeException('Boom');

        $inner = Mockery::mock(ExceptionHandler::class);
        $inner->shouldReceive('shouldReport')->once()->with($e)->andReturnFalse();
        $inner->shouldReceive('report')->once()->with($e);

        $reporter = Mockery::mock(ErrorReporter::class);
        $reporter->shouldReceive('report')->never();

        // Les exceptions filtrées (404, 422, 401…) ne doivent pas être transmises au collector.
        (new HandlerDecorator($inner, $reporter))->report($e);
    }

    public function test_render_and_shouldreport_delegate(): void
    {
        $e = new RuntimeException('Boom');
        $request = Mockery::mock(Request::class);

        $inner = Mockery::mock(ExceptionHandler::class);
        $inner->shouldReceive('shouldReport')->once()->with($e)->andReturnTrue();
        $inner->shouldReceive('render')->once()->with($request, $e)->andReturn('rendered');

        $reporter = Mockery::mock(ErrorReporter::class);

        $decorator = new HandlerDecorator($inner, $reporter);

        $this->assertTrue($decorator->shouldReport($e));
        $this->assertSame('rendered', $decorator->render($request, $e));
    }
}
