<?php

declare(strict_types=1);

use ArtisanBuild\SinkContracts\Envelope;
use ArtisanBuild\SinkContracts\Exceptions\InvalidEnvelope;
use ArtisanBuild\SinkContracts\Truncation;

it('round-trips minimal and full envelopes through array and json form', function (Envelope $envelope): void {
    $fromArray = Envelope::fromArray($envelope->toArray());
    $fromJson = Envelope::fromJson($envelope->toJson());

    foreach ([$fromArray, $fromJson] as $restored) {
        expect($restored->envelopeVersion)->toBe($envelope->envelopeVersion)
            ->and($restored->idempotencyKey)->toBe($envelope->idempotencyKey)
            ->and($restored->sentAt)->toBe($envelope->sentAt)
            ->and($restored->stream)->toBe($envelope->stream)
            ->and($restored->message)->toBe($envelope->message)
            ->and($restored->truncation)->toBe($envelope->truncation);
    }
})->with([
    'minimal' => fn (): Envelope => Envelope::make(
        idempotencyKey: '01K2SMVCA7EA6Y3KFE3S40QY2C',
        sentAt: '2026-06-22T12:00:00+00:00',
        message: base64_encode('From: a@example.com'."\r\n".'To: b@example.com'."\r\n\r\n".'Hello'),
    ),
    'full' => fn (): Envelope => Envelope::make(
        idempotencyKey: '01K2SMWQ2H6DR75TSWRZFH85HC',
        sentAt: '2026-06-22T12:01:00+00:00',
        message: base64_encode('Subject: Full'."\r\n\r\n".'Body'),
        stream: 'ci-run-7',
        truncation: Truncation::HeadersOnly,
    ),
]);

it('emits exact wire keys with reserved stream and serialized truncation', function (): void {
    $minimal = Envelope::make('01K2SMVCA7EA6Y3KFE3S40QY2C', '2026-06-22T12:00:00+00:00', 'bWltZQ==');
    $full = Envelope::make('01K2SMWQ2H6DR75TSWRZFH85HC', '2026-06-22T12:01:00+00:00', 'bWltZQ==', 'ci-run-7', Truncation::HeadersOnly);

    expect(array_keys($minimal->toArray()))->toBe([
        'envelope_version',
        'idempotency_key',
        'sent_at',
        'stream',
        'message',
        'truncation',
    ])->and($minimal->toArray())->toHaveKey('stream')
        ->and($minimal->toArray()['stream'])->toBeNull()
        ->and($minimal->toArray()['truncation'])->toBe('none')
        ->and($full->toArray()['truncation'])->toBe('headers_only');
});

it('stamps and peeks envelope versions', function (): void {
    $envelope = Envelope::make('01K2SMVCA7EA6Y3KFE3S40QY2C', '2026-06-22T12:00:00+00:00', 'bWltZQ==');
    $restored = Envelope::fromArray([
        'envelope_version' => '1',
        'idempotency_key' => '01K2SMVCA7EA6Y3KFE3S40QY2C',
        'sent_at' => '2026-06-22T12:00:00+00:00',
        'message' => 'bWltZQ==',
    ]);

    expect(Envelope::VERSION)->toBe(1)
        ->and($envelope->envelopeVersion)->toBe(1)
        ->and(Envelope::versionFrom(['envelope_version' => 2]))->toBe(2)
        ->and(Envelope::versionFrom(['envelope_version' => '3']))->toBe(3)
        ->and($restored->envelopeVersion)->toBe(1);
});

it('rejects a missing version when peeking', function (): void {
    Envelope::versionFrom([]);
})->throws(InvalidEnvelope::class);

it('tolerates unknown keys and defaults absent optional keys', function (): void {
    $envelope = Envelope::fromArray([
        'envelope_version' => Envelope::VERSION,
        'idempotency_key' => '01K2SMVCA7EA6Y3KFE3S40QY2C',
        'sent_at' => '2026-06-22T12:00:00+00:00',
        'message' => 'bWltZQ==',
        'future_key' => ['ignored' => true],
    ]);

    expect($envelope->idempotencyKey)->toBe('01K2SMVCA7EA6Y3KFE3S40QY2C')
        ->and($envelope->stream)->toBeNull()
        ->and($envelope->truncation)->toBe(Truncation::None);
});

it('rejects malformed envelopes', function (array|string $payload): void {
    if (is_string($payload)) {
        Envelope::fromJson($payload);

        return;
    }

    Envelope::fromArray($payload);
})->with([
    'missing idempotency key' => [[
        'envelope_version' => Envelope::VERSION,
        'sent_at' => '2026-06-22T12:00:00+00:00',
        'message' => 'bWltZQ==',
    ]],
    'empty idempotency key' => [[
        'envelope_version' => Envelope::VERSION,
        'idempotency_key' => '',
        'sent_at' => '2026-06-22T12:00:00+00:00',
        'message' => 'bWltZQ==',
    ]],
    'integer idempotency key' => [[
        'envelope_version' => Envelope::VERSION,
        'idempotency_key' => 123,
        'sent_at' => '2026-06-22T12:00:00+00:00',
        'message' => 'bWltZQ==',
    ]],
    'missing sent at' => [[
        'envelope_version' => Envelope::VERSION,
        'idempotency_key' => '01K2SMVCA7EA6Y3KFE3S40QY2C',
        'message' => 'bWltZQ==',
    ]],
    'empty sent at' => [[
        'envelope_version' => Envelope::VERSION,
        'idempotency_key' => '01K2SMVCA7EA6Y3KFE3S40QY2C',
        'sent_at' => '',
        'message' => 'bWltZQ==',
    ]],
    'missing message' => [[
        'envelope_version' => Envelope::VERSION,
        'idempotency_key' => '01K2SMVCA7EA6Y3KFE3S40QY2C',
        'sent_at' => '2026-06-22T12:00:00+00:00',
    ]],
    'empty message' => [[
        'envelope_version' => Envelope::VERSION,
        'idempotency_key' => '01K2SMVCA7EA6Y3KFE3S40QY2C',
        'sent_at' => '2026-06-22T12:00:00+00:00',
        'message' => '',
    ]],
    'array message' => [[
        'envelope_version' => Envelope::VERSION,
        'idempotency_key' => '01K2SMVCA7EA6Y3KFE3S40QY2C',
        'sent_at' => '2026-06-22T12:00:00+00:00',
        'message' => ['bWltZQ=='],
    ]],
    'integer stream' => [[
        'envelope_version' => Envelope::VERSION,
        'idempotency_key' => '01K2SMVCA7EA6Y3KFE3S40QY2C',
        'sent_at' => '2026-06-22T12:00:00+00:00',
        'message' => 'bWltZQ==',
        'stream' => 123,
    ]],
    'integer truncation' => [[
        'envelope_version' => Envelope::VERSION,
        'idempotency_key' => '01K2SMVCA7EA6Y3KFE3S40QY2C',
        'sent_at' => '2026-06-22T12:00:00+00:00',
        'message' => 'bWltZQ==',
        'truncation' => 123,
    ]],
    'unrecognized truncation' => [[
        'envelope_version' => Envelope::VERSION,
        'idempotency_key' => '01K2SMVCA7EA6Y3KFE3S40QY2C',
        'sent_at' => '2026-06-22T12:00:00+00:00',
        'message' => 'bWltZQ==',
        'truncation' => 'body_only',
    ]],
    'invalid json' => ['not json'],
    'json empty list' => ['[]'],
    'json non-empty list' => ['[1,2]'],
    'json scalar' => ['5'],
])->throws(InvalidEnvelope::class);
