<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Assert an exact canonical or legacy structural marker and reject elements
 * carrying multiple marker attributes.
 *
 * @param  TestResponse<Response>  $response
 */
function assertTestMarker(TestResponse $response, string $marker, bool $present = true): void
{
    $html = (string) $response->getContent();
    $document = new DOMDocument;
    $previousErrorHandling = libxml_use_internal_errors(true);

    try {
        $parsed = $document->loadHTML(
            $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
        );
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorHandling);
    }

    Assert::assertTrue($parsed, 'Failed asserting that the response contains parseable HTML.');

    $elementsWithDuplicateMarkers = 0;
    $markerCount = 0;

    foreach ($document->getElementsByTagName('*') as $element) {
        $hasCanonicalMarker = $element->hasAttribute('data-testid');
        $hasLegacyMarker = $element->hasAttribute('data-test');

        if ($hasCanonicalMarker && $hasLegacyMarker) {
            $elementsWithDuplicateMarkers++;
        }

        if ($hasCanonicalMarker && $element->getAttribute('data-testid') === $marker) {
            $markerCount++;
        }

        if ($hasLegacyMarker && $element->getAttribute('data-test') === $marker) {
            $markerCount++;
        }
    }

    Assert::assertSame(
        0,
        $elementsWithDuplicateMarkers,
        'An element may carry only one data-testid/data-test marker attribute.',
    );

    if ($present) {
        Assert::assertGreaterThan(0, $markerCount, "Failed asserting that marker [{$marker}] is present.");

        return;
    }

    Assert::assertSame(0, $markerCount, "Failed asserting that marker [{$marker}] is absent.");
}
