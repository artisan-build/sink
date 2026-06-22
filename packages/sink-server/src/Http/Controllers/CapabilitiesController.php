<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Http\Controllers;

use ArtisanBuild\SinkContracts\Envelope;
use Illuminate\Http\JsonResponse;

final class CapabilitiesController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'envelope' => [
                'min_major' => 1,
                'max_major' => Envelope::VERSION,
                'supported_majors' => range(1, Envelope::VERSION),
            ],
        ]);
    }
}
