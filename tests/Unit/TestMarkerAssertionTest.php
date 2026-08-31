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

test('the shared marker helper rejects multiple marker attributes after a quoted greater-than sign', function (): void {
    $response = new TestResponse(new Response(
        '<section title="x > y" data-testid="console-shell" data-test="legacy-shell"></section>',
    ));

    expect(fn () => assertTestMarker($response, 'console-shell'))
        ->toThrow(AssertionFailedError::class);
});

test('marker-like script text does not satisfy structural marker presence', function (): void {
    $response = new TestResponse(new Response(
        '<script>const marker = \' data-testid="console-shell" \';</script>',
    ));

    assertTestMarker($response, 'console-shell', present: false);

    expect(fn () => assertTestMarker($response, 'console-shell'))
        ->toThrow(AssertionFailedError::class);
});

test('the shared marker helper accepts repeated markers on different elements', function (): void {
    $response = new TestResponse(new Response(
        '<section data-testid="console-shell"></section><aside data-test="console-shell"></aside>',
    ));

    assertTestMarker($response, 'console-shell');
});
