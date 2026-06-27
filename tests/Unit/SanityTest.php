<?php

namespace BugCollector\Client\Tests\Unit;

use BugCollector\Client\Tests\TestCase;

class SanityTest extends TestCase
{
    public function test_the_config_is_merged(): void
    {
        $this->assertSame('https://collector.test', config('bug-collector.base_url'));
        $this->assertSame('/api/laravel-bugs/add', config('bug-collector.endpoints.errors'));
        $this->assertSame(2000, config('bug-collector.performance.thresholds.request'));
    }
}
