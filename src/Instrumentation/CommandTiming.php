<?php

namespace BugCollector\Client\Instrumentation;

use BugCollector\Client\Reporting\PerformanceReporter;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository;

/** Mesure la durée des commandes artisan et reporte celles dépassant le seuil configuré. */
class CommandTiming
{
    /** @var array<string, float> */
    private array $started = [];

    public function __construct(
        private PerformanceReporter $reporter,
        private Repository $config,
    ) {}

    public function starting(CommandStarting $event): void
    {
        $ignored = (array) $this->config->get('bug-collector.performance.ignore_commands', []);

        if ($event->command !== null && ! in_array($event->command, $ignored, true)) {
            $this->started[$event->command] = microtime(true);
        }
    }

    public function finished(CommandFinished $event): void
    {
        if ($event->command === null || ! isset($this->started[$event->command])) {
            return;
        }

        $ms = (int) ((microtime(true) - $this->started[$event->command]) * 1000);
        unset($this->started[$event->command]);

        $this->reporter->report('command', $event->command, $ms);
    }
}
