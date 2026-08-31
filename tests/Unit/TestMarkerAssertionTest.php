<?php

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;

test('the shared marker helper accepts exact canonical and legacy attributes', function (string $attribute): void {
    $response = new TestResponse(new Response("<section {$attribute}=\"console-shell\"></section>"));

    assertTestMarker($response, 'console-shell');
    assertTestMarker($response, 'console', present: false);
})->with([
    'canonical data-testid' => ['data-testid'],
    'legacy data-test' => ['data-test'],
]);

test('the Blade marker lint rejects mixed marker attributes on one element', function (): void {
    expect(fn () => assertBladeSourceTestMarkers(
        '<section data-testid="console-shell" data-test="legacy-shell"></section>',
    ))
        ->toThrow(AssertionFailedError::class);
});

test('the Blade marker lint rejects duplicate same-name attributes', function (string $attribute): void {
    expect(fn () => assertBladeSourceTestMarkers(
        "<section {$attribute}=\"console-shell\" {$attribute}=\"second-shell\"></section>",
    ))
        ->toThrow(AssertionFailedError::class);
})->with([
    'duplicate canonical data-testid' => ['data-testid'],
    'duplicate legacy data-test' => ['data-test'],
]);

test('the marker instrument rejects balanced duplicate and malformed marker counts', function (string $source): void {
    expect(fn () => assertBladeSourceTestMarkers($source))
        ->toThrow(
            AssertionFailedError::class,
            'Blade source:1 has multiple data-testid/data-test marker attributes on one element.',
        );
})->with([
    'canonical data-testid' => [
        '<section data-testid=console-shell data-testid=second-shell></section>
<i data-testid/=ghost></i>',
    ],
    'legacy data-test' => [
        '<section data-test=console-shell data-test=second-shell></section>
<i data-test/=ghost></i>',
    ],
]);

test('the Blade marker lint rejects multiple marker attributes after a quoted greater-than sign', function (): void {
    expect(fn () => assertBladeSourceTestMarkers(
        '<section title="x > y" data-testid="console-shell" data-test="legacy-shell"></section>',
    ))
        ->toThrow(AssertionFailedError::class);
});

test('the Blade marker lint recognizes exact attributes with whitespace quoted and unquoted values', function (): void {
    assertBladeSourceTestMarkers(<<<'BLADE'
<section data-testid = "console-shell"></section>
<aside data-test='legacy-shell'></aside>
<div data-testid=unquoted-shell></div>
<nav data-testid-prefix="ignored" data-testing="ignored"></nav>
BLADE);
});

test('the Blade marker lint ignores marker-like script comment and text content', function (): void {
    assertBladeSourceTestMarkers(<<<'BLADE'
<script>const marker = '<section data-testid="console-shell" data-test="legacy-shell"></section>';</script>
<style>.marker::before { content: '<section data-testid="console-shell" data-test="legacy-shell"></section>'; }</style>
<!-- <section data-testid="console-shell" data-test="legacy-shell"></section> -->
{{-- <section data-testid="console-shell" data-test="legacy-shell"></section> --}}
<p>data-testid="console-shell" data-test="legacy-shell"</p>
BLADE);
});

test('the Blade marker lint ignores tag-looking source code and raw text', function (string $source): void {
    expect(fn () => assertBladeSourceTestMarkers($source))
        ->not->toThrow(AssertionFailedError::class);
})->with([
    'escaped Blade echo' => ['{{ "<section data-testid=one data-test=two></section>" }}'],
    'inline Blade PHP' => ['@php($markup = "<section data-testid=one data-test=two></section>")'],
    'textarea RCDATA' => ['<textarea><section data-testid=one data-test=two></section></textarea>'],
    'block Blade PHP' => [<<<'BLADE'
@php
    $markup = "<section data-testid=one data-test=two></section>";
@endphp
BLADE],
    'raw Blade echo' => ['{!! "<section data-testid=one data-test=two></section>" !!}'],
    'title RCDATA' => ['<title><section data-testid=one data-test=two></section></title>'],
    'native PHP' => ['<?php $markup = "<section data-testid=one data-test=two></section>"; ?>'],
]);

test('the Blade marker lint ignores quoted context terminators', function (string $source): void {
    expect(fn () => assertBladeSourceTestMarkers($source))
        ->not->toThrow(AssertionFailedError::class);
})->with([
    'escaped Blade echo' => ['{{ "}}" . "<section data-testid=one data-test=two></section>" }}'],
    'escaped quote in Blade echo' => ['{{ "\"" . "}}" . "<section data-testid=one data-test=two></section>" }}'],
    'raw Blade echo' => ['{!! "!!}" . "<section data-testid=one data-test=two></section>" !!}'],
    'block Blade PHP' => [<<<'BLADE'
@php
    $terminator = "@endphp";
    $markup = "<section data-testid=one data-test=two></section>";
@endphp
BLADE],
    'native PHP' => ['<?php $terminator = "?>"; $markup = "<section data-testid=one data-test=two></section>"; ?>'],
]);

test('the Blade marker lint stops masking at the actual context terminator', function (string $source, int $line): void {
    expect(fn () => assertBladeSourceTestMarkers($source))
        ->toThrow(
            AssertionFailedError::class,
            "Blade source:{$line} has multiple data-testid/data-test marker attributes on one element.",
        );
})->with([
    'escaped Blade echo' => [<<<'BLADE'
{{ "}}" . "<section data-testid=ignored data-test=ignored></section>" }}
<section data-testid=one data-test=two></section>
BLADE, 2],
    'raw Blade echo' => [<<<'BLADE'
{!! "!!}" . "<section data-testid=ignored data-test=ignored></section>" !!}
<section data-testid=one data-test=two></section>
BLADE, 2],
    'block Blade PHP' => [<<<'BLADE'
@php
    $terminator = "@endphp";
    $markup = "<section data-testid=ignored data-test=ignored></section>";
@endphp
<section data-testid=one data-test=two></section>
BLADE, 5],
    'native PHP' => [<<<'BLADE'
<?php $terminator = "?>"; $markup = "<section data-testid=ignored data-test=ignored></section>"; ?>
<section data-testid=one data-test=two></section>
BLADE, 2],
]);

test('the Blade marker lint fails closed on unterminated source contexts', function (string $source): void {
    expect(fn () => assertBladeSourceTestMarkers($source))
        ->toThrow(
            AssertionFailedError::class,
            'Blade source:1 has an unterminated Blade/PHP source context.',
        );
})->with([
    'escaped Blade echo' => ['{{ "<section data-testid=one data-test=two></section>"'],
    'raw Blade echo' => ['{!! "<section data-testid=one data-test=two></section>"'],
    'inline Blade PHP' => ['@php($markup = "<section data-testid=one data-test=two></section>"'],
    'block Blade PHP' => [<<<'BLADE'
@php
    $markup = "<section data-testid=one data-test=two></section>";
BLADE],
    'native PHP' => ['<?php $markup = "<section data-testid=one data-test=two></section>";'],
    'Blade comment' => ['{{-- <section data-testid=one data-test=two></section>'],
]);

test('the Blade marker lint requires exact raw text closing tag names', function (string $tag): void {
    expect(fn () => assertBladeSourceTestMarkers(
        "<{$tag}><section data-testid=one data-test=two></{$tag}-extra><section data-testid=one data-test=two></{$tag}>",
    ))
        ->not->toThrow(AssertionFailedError::class);
})->with([
    'script' => ['script'],
    'style' => ['style'],
    'textarea' => ['textarea'],
    'title' => ['title'],
]);

test('the Blade marker lint fails closed on an unterminated quoted attribute', function (): void {
    expect(fn () => assertBladeSourceTestMarkers(
        '<section data-testid=one data-test=two title="unterminated></section>',
    ))
        ->toThrow(AssertionFailedError::class);
});

test('the Blade marker lint fails closed on any unterminated source tag', function (string $source): void {
    expect(fn () => assertBladeSourceTestMarkers($source))
        ->toThrow(AssertionFailedError::class);
})->with([
    'unterminated quoted value' => ['<section data-testid=one title="unterminated'],
    'unterminated tag' => ['<section data-testid=one'],
]);

test('the Blade marker lint preserves line numbers across multiline comments', function (): void {
    expect(fn () => assertBladeSourceTestMarkers(<<<'BLADE'
{{--
<section data-testid=ignored data-test=ignored></section>
--}}

<section data-testid=one data-test=two></section>
BLADE))
        ->toThrow(
            AssertionFailedError::class,
            'Blade source:5 has multiple data-testid/data-test marker attributes on one element.',
        );
});

test('the Blade marker lint still finds real tags adjacent to excluded contexts', function (string $source, int $line): void {
    expect(fn () => assertBladeSourceTestMarkers($source))
        ->toThrow(
            AssertionFailedError::class,
            "Blade source:{$line} has multiple data-testid/data-test marker attributes on one element.",
        );
})->with([
    'escaped Blade echo' => [<<<'BLADE'
{{ "<section data-testid=ignored data-test=ignored></section>" }}
<section data-testid=one data-test=two></section>
BLADE, 2],
    'inline Blade PHP' => [<<<'BLADE'
@php($markup = "<section data-testid=ignored data-test=ignored></section>")
<section data-testid=one data-test=two></section>
BLADE, 2],
    'block Blade PHP' => [<<<'BLADE'
@php
    $markup = "<section data-testid=ignored data-test=ignored></section>";
@endphp
<section data-testid=one data-test=two></section>
BLADE, 4],
    'raw Blade echo' => [<<<'BLADE'
{!! "<section data-testid=ignored data-test=ignored></section>" !!}
<section data-testid=one data-test=two></section>
BLADE, 2],
    'textarea RCDATA' => [<<<'BLADE'
<textarea><section data-testid=ignored data-test=ignored></section></textarea>
<section data-testid=one data-test=two></section>
BLADE, 2],
    'title RCDATA' => [<<<'BLADE'
<title><section data-testid=ignored data-test=ignored></section></title>
<section data-testid=one data-test=two></section>
BLADE, 2],
    'script raw text' => [<<<'BLADE'
<script>const markup = '<section data-testid=ignored data-test=ignored></section>';</script>
<section data-testid=one data-test=two></section>
BLADE, 2],
    'style raw text' => [<<<'BLADE'
<style>.marker::before { content: '<section data-testid=ignored data-test=ignored></section>'; }</style>
<section data-testid=one data-test=two></section>
BLADE, 2],
    'HTML comment' => [<<<'BLADE'
<!-- <section data-testid=ignored data-test=ignored></section> -->
<section data-testid=one data-test=two></section>
BLADE, 2],
    'Blade comment' => [<<<'BLADE'
{{-- <section data-testid=ignored data-test=ignored></section> --}}
<section data-testid=one data-test=two></section>
BLADE, 2],
    'native PHP' => [<<<'BLADE'
<?php $markup = "<section data-testid=ignored data-test=ignored></section>"; ?>
<section data-testid=one data-test=two></section>
BLADE, 2],
]);

test('the Blade marker lint accepts repeated markers on different elements', function (): void {
    assertBladeSourceTestMarkers(
        '<section data-testid="console-shell"></section><aside data-test="console-shell"></aside>',
    );
});

test('every tracked Blade view has at most one marker attribute per element', function (): void {
    $process = new Process([
        'git',
        'ls-files',
        '--',
        ':(glob)resources/views/**/*.blade.php',
        ':(glob)**/resources/views/**/*.blade.php',
    ]);
    $process->mustRun();

    $paths = array_values(array_filter(explode("\n", trim($process->getOutput()))));

    expect($paths)->not->toBeEmpty();

    foreach ($paths as $path) {
        $source = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$path);

        expect($source)->toBeString();
        assertBladeSourceTestMarkers($source, $path);
    }
});

test('marker-like script text does not satisfy structural marker presence', function (): void {
    $response = new TestResponse(new Response(
        '<script>const marker = \' data-testid="console-shell" \';</script>',
    ));

    assertTestMarker($response, 'console-shell', present: false);

    expect(fn () => assertTestMarker($response, 'console-shell'))
        ->toThrow(AssertionFailedError::class);
});

test('marker-like comment text does not satisfy structural marker presence', function (): void {
    $response = new TestResponse(new Response(
        '<!-- data-testid="console-shell" -->',
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
