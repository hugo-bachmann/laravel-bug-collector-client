<?php

namespace BugCollector\Client\Instrumentation;

use BugCollector\Client\Outbox;
use BugCollector\Client\Reporting\PerformanceReporter;
use BugCollector\Client\Transport\Transport;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

/** Mesure la durée des jobs de queue et reporte ceux dépassant le seuil configuré ; vide l'Outbox immédiatement (worker long-vivant). */
class JobTiming
{
    /** @var array<string, float> */
    private array $started = [];

    public function __construct(
        private PerformanceReporter $reporter,
        private Outbox $outbox,
        private Transport $transport,
    ) {}

    public function processing(JobProcessing $event): void
    {
        $this->started[$event->job->getJobId()] = microtime(true);
    }

    public function processed(JobProcessed $event): void
    {
        $this->finish($event->job);
    }

    public function failed(JobFailed $event): void
    {
        $this->finish($event->job);
    }

    private function finish(Job $job): void
    {
        $id = $job->getJobId();

        if (! isset($this->started[$id])) {
            return;
        }

        $ms = (int) ((microtime(true) - $this->started[$id]) * 1000);
        unset($this->started[$id]);

        $this->reporter->report('job', $job->resolveName(), $ms);

        // Le worker est long-vivant : terminating ne se déclenchera pas par job.
        if (! $this->outbox->isEmpty()) {
            $this->outbox->flush($this->transport);
        }
    }
}
