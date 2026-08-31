<?php

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\HttpFoundation\Response;

test('the shared marker helper accepts exact canonical and legacy attributes', function (string $attribute): void {
    $response = new TestResponse(new Response("<section {$attribute}=\"console-shell\"></section>"));

    assertTestMarker($response, 'console-shell');
    assertTestMarker($response, 'console', present: false);
})->with([
    'canonical data-testid' => ['data-testid'],
    'legacy data-test' => ['data-test'],
]);

test('the shared marker helper rejects multiple marker attributes on one element', function (): void {
    $response = new TestResponse(new Response(
        '<section data-testid="console-shell" data-test="legacy-shell"></section>',
    ));

    expect(fn () => assertTestMarker($response, 'console-shell'))
        ->toThrow(AssertionFailedError::class);
});
