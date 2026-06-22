<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkClient\Exceptions;

use RuntimeException;

final class SinkProductionFuse extends RuntimeException
{
    public static function blocked(): self
    {
        return new self('Sink mail transport refuses to run in production unless SINK_ALLOW_PRODUCTION=true.');
    }
}
