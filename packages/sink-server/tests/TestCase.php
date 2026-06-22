<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Tests;

use ArtisanBuild\SinkServer\SinkServerServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SinkServerServiceProvider::class];
    }
}
