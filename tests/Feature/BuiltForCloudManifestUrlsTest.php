<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/**
 * Every absolute URL Sink ships in `config/built-for-cloud.php` is rendered to users on
 * every deployed instance (the Built for Cloud landing page and `/bfc/ui`), so a URL that
 * 404s is a user-visible defect. These are the URLs verified to resolve, with `curl`, on
 * 2026-09-23:
 *
 * - https://scalpels.app                                     200 text/html
 * - https://scalpels.app/products/sink                       200 text/html
 * - https://scalpels.app/img/products/transparent/sink.svg   200 image/svg+xml
 * - https://scalpels.app/img/products/transparent/sink.png   200 image/png
 *
 * Adding a URL here means verifying it resolves first. The test deliberately makes no
 * network request: the assertion pins the configured values, it does not probe them.
 *
 * @var list<string>
 */
$liveVerifiedUrls = [
    'https://scalpels.app',
    'https://scalpels.app/products/sink',
    'https://scalpels.app/img/products/transparent/sink.svg',
    'https://scalpels.app/img/products/transparent/sink.png',
];

test('every URL Sink configures for Built for Cloud is one verified to resolve', function () use ($liveVerifiedUrls): void {
    $configuredUrls = collect(Arr::dot(config('built-for-cloud')))
        ->filter(fn (mixed $value): bool => is_string($value) && str_starts_with($value, 'http'));

    expect($configuredUrls)->not->toBeEmpty();

    foreach ($configuredUrls as $key => $url) {
        expect($url)->toBeIn($liveVerifiedUrls, "built-for-cloud.{$key} is not a URL verified to resolve");
    }
});
