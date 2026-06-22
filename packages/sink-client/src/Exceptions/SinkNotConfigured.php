<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkClient\Exceptions;

use RuntimeException;

final class SinkNotConfigured extends RuntimeException
{
    /**
     * @param  list<string>  $missing
     */
    public static function missing(array $missing): self
    {
        return new self('Sink mail transport is not configured. Set '.implode(' and ', $missing).'.');
    }
}
