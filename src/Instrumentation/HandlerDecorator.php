<?php

namespace BugCollector\Client\Instrumentation;

use BugCollector\Client\Reporting\ErrorReporter;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

class HandlerDecorator implements ExceptionHandler
{
    public function __construct(
        private ExceptionHandler $inner,
        private ErrorReporter $reporter,
    ) {}

    public function report(Throwable $e): void
    {
        if ($this->inner->shouldReport($e)) {
            try {
                $this->reporter->report($e);
            } catch (Throwable) {
                // Notre reporting ne doit jamais empêcher la délégation.
            }
        }

        $this->inner->report($e);
    }

    public function shouldReport(Throwable $e): bool
    {
        return $this->inner->shouldReport($e);
    }

    public function render($request, Throwable $e)
    {
        return $this->inner->render($request, $e);
    }

    public function renderForConsole($output, Throwable $e): void
    {
        $this->inner->renderForConsole($output, $e);
    }

    /**
     * Délègue au handler décoré toute méthode hors du contrat ExceptionHandler.
     *
     * Le handler concret de Laravel expose des méthodes supplémentaires
     * (reportable, renderable, map, ignore, dontReport...) sur lesquelles
     * s'appuient d'autres packages (ex. Laravel Pulse). Sans cette délégation,
     * leur appel lèverait une erreur fatale au boot.
     */
    public function __call(string $method, array $arguments)
    {
        return $this->inner->{$method}(...$arguments);
    }
}
