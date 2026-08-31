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
 * Assert an exact canonical or legacy structural marker value.
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

    $markerCount = 0;

    foreach ($document->getElementsByTagName('*') as $element) {
        $hasCanonicalMarker = $element->hasAttribute('data-testid');
        $hasLegacyMarker = $element->hasAttribute('data-test');

        if ($hasCanonicalMarker && $element->getAttribute('data-testid') === $marker) {
            $markerCount++;
        }

        if ($hasLegacyMarker && $element->getAttribute('data-test') === $marker) {
            $markerCount++;
        }
    }

    if ($present) {
        Assert::assertGreaterThan(0, $markerCount, "Failed asserting that marker [{$marker}] is present.");

        return;
    }

    Assert::assertSame(0, $markerCount, "Failed asserting that marker [{$marker}] is absent.");
}

function assertBladeSourceTestMarkers(string $source, string $sourceName = 'Blade source'): void
{
    $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source);

    Assert::assertIsString($source, "Failed preparing [{$sourceName}] for marker linting.");

    $sourceLength = strlen($source);
    $offset = 0;

    while (($tagStart = strpos($source, '<', $offset)) !== false) {
        if (substr_compare($source, '<!--', $tagStart, 4) === 0) {
            $commentEnd = strpos($source, '-->', $tagStart + 4);
            $offset = $commentEnd === false ? $sourceLength : $commentEnd + 3;

            continue;
        }

        $tagNameStart = $tagStart + 1;

        if ($tagNameStart >= $sourceLength || ! ctype_alpha($source[$tagNameStart])) {
            $offset = $tagNameStart;

            continue;
        }

        $tagEnd = $tagNameStart;
        $quote = null;

        while ($tagEnd < $sourceLength) {
            $character = $source[$tagEnd];

            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }
            } elseif ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '>') {
                break;
            }

            $tagEnd++;
        }

        if ($tagEnd >= $sourceLength) {
            break;
        }

        $tag = substr($source, $tagStart, $tagEnd - $tagStart + 1);
        $tagLength = strlen($tag);
        $cursor = 1;

        while ($cursor < $tagLength && ! ctype_space($tag[$cursor]) && ! in_array($tag[$cursor], ['/', '>'], true)) {
            $cursor++;
        }

        $tagName = strtolower(substr($tag, 1, $cursor - 1));
        $markerAttributes = [];

        while ($cursor < $tagLength) {
            while ($cursor < $tagLength && ctype_space($tag[$cursor])) {
                $cursor++;
            }

            if ($cursor >= $tagLength || in_array($tag[$cursor], ['/', '>'], true)) {
                break;
            }

            $attributeStart = $cursor;

            while ($cursor < $tagLength && ! ctype_space($tag[$cursor]) && ! in_array($tag[$cursor], ['=', '/', '>'], true)) {
                $cursor++;
            }

            if ($attributeStart === $cursor) {
                $cursor++;

                continue;
            }

            $attribute = strtolower(substr($tag, $attributeStart, $cursor - $attributeStart));

            if (in_array($attribute, ['data-testid', 'data-test'], true)) {
                $markerAttributes[] = $attribute;
            }

            while ($cursor < $tagLength && ctype_space($tag[$cursor])) {
                $cursor++;
            }

            if ($cursor >= $tagLength || $tag[$cursor] !== '=') {
                continue;
            }

            $cursor++;

            while ($cursor < $tagLength && ctype_space($tag[$cursor])) {
                $cursor++;
            }

            if ($cursor < $tagLength && in_array($tag[$cursor], ['"', "'"], true)) {
                $valueQuote = $tag[$cursor];
                $cursor++;

                while ($cursor < $tagLength && $tag[$cursor] !== $valueQuote) {
                    $cursor++;
                }

                $cursor++;
            } else {
                while ($cursor < $tagLength && ! ctype_space($tag[$cursor]) && $tag[$cursor] !== '>') {
                    $cursor++;
                }
            }
        }

        $line = substr_count($source, "\n", 0, $tagStart) + 1;

        Assert::assertLessThanOrEqual(
            1,
            count($markerAttributes),
            "{$sourceName}:{$line} has multiple data-testid/data-test marker attributes on one element.",
        );

        $offset = $tagEnd + 1;

        if (in_array($tagName, ['script', 'style'], true)) {
            $closingTag = stripos($source, "</{$tagName}", $offset);

            if ($closingTag === false) {
                break;
            }

            $closingTagEnd = strpos($source, '>', $closingTag);
            $offset = $closingTagEnd === false ? $sourceLength : $closingTagEnd + 1;
        }
    }
}
