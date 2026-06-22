<?php

declare(strict_types=1);

use ArtisanBuild\SinkContracts\SinkContracts;

it('identifies the contracts package and scaffold version', function (): void {
    expect(SinkContracts::PACKAGE)->toBe('sink-contracts')
        ->and(SinkContracts::VERSION)->toBe('1.0.0');
});
