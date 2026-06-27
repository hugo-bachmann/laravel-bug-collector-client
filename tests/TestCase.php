<?php

namespace BugCollector\Client\Tests;

use BugCollector\Client\BugCollectorServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [BugCollectorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        // Config réseau valide par défaut pour que le Gate soit "enabled".
        $config->set('bug-collector.base_url', 'https://collector.test');
        $config->set('bug-collector.ingest_key', 'test-key');

        // Base sqlite en mémoire pour les tests qui touchent la DB.
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }
}
