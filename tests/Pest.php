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
    $elements = [];
    $elementsWithDuplicateMarkers = 0;

    preg_match_all('/<[^>]+>/', $html, $elements);

    foreach ($elements[0] ?? [] as $element) {
        $attributeCount = preg_match_all('/\sdata-test(?:id)?\s*=/', $element);

        if ($attributeCount > 1) {
            $elementsWithDuplicateMarkers++;
        }
    }

    Assert::assertSame(
        0,
        $elementsWithDuplicateMarkers,
        'An element may carry only one data-testid/data-test marker attribute.',
    );

    $markerCount = preg_match_all(
        '/\s(?:data-testid|data-test)\s*=\s*(["\'])'.preg_quote($marker, '/').'\1(?=\s|\/?>)/',
        $html,
    );

    if ($present) {
        Assert::assertGreaterThan(0, $markerCount, "Failed asserting that marker [{$marker}] is present.");

        return;
    }

    Assert::assertSame(0, $markerCount, "Failed asserting that marker [{$marker}] is absent.");
}
