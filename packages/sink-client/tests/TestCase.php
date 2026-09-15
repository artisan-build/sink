<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkClient\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected $enablesPackageDiscoveries = true;
}
