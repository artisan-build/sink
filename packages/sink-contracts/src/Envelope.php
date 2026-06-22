<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkContracts;

use ArtisanBuild\SinkContracts\Exceptions\InvalidEnvelope;

/**
 * The thin, versioned wire envelope that crosses the network between a source
 * app (sink-client) and a Sink server (sink-server).
 *
 * The envelope wraps opaque raw MIME bytes with the minimum metadata the server
 * needs to version-check and store a captured send. Only the envelope itself is
 * version-sensitive — the message payload is never interpreted here.
 *
 * Compatibility rule: the envelope evolves ADDITIVELY within a major. New fields
 * are optional and added; existing fields are never removed or repurposed.
 * {@see fromArray()} therefore tolerates unknown extra keys (a newer sender
 * talking to an older server) and supplies defaults for absent optional keys (an
 * older sender talking to a newer server).
 */
final class Envelope
{
    /**
     * The envelope major version this build speaks. Bump ONLY on a breaking wire
     * change. Crossing this in a client is a deliberate `composer require`, never
     * a routine `composer update` (sink-client pins sink-contracts at `^X`).
     */
    public const int VERSION = 1;

    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $sentAt,
        public readonly string $message,
        public readonly ?string $stream = null,
        public readonly Truncation $truncation = Truncation::None,
        public readonly int $envelopeVersion = self::VERSION,
    ) {}

    public static function make(
        string $idempotencyKey,
        string $sentAt,
        string $message,
        ?string $stream = null,
        Truncation $truncation = Truncation::None,
    ): self {
        return new self(
            idempotencyKey: $idempotencyKey,
            sentAt: $sentAt,
            message: $message,
            stream: $stream,
            truncation: $truncation,
        );
    }

    /**
     * @return array{envelope_version: int, idempotency_key: string, sent_at: string, stream: string|null, message: string, truncation: string}
     */
    public function toArray(): array
    {
        return [
            'envelope_version' => $this->envelopeVersion,
            'idempotency_key' => $this->idempotencyKey,
            'sent_at' => $this->sentAt,
            'stream' => $this->stream,
            'message' => $this->message,
            'truncation' => $this->truncation->value,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * Reconstruct an envelope from its array form. Tolerant by design: unknown keys
     * are ignored and absent optional keys take defaults — that tolerance is what
     * lets a backward-compatible server parse every older major.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidEnvelope When required fields are missing or malformed.
     */
    public static function fromArray(array $data): self
    {
        $version = self::versionFrom($data);

        if (! isset($data['idempotency_key']) || ! is_string($data['idempotency_key']) || $data['idempotency_key'] === '') {
            throw new InvalidEnvelope('Envelope is missing a non-empty string "idempotency_key".');
        }

        if (! isset($data['sent_at']) || ! is_string($data['sent_at']) || $data['sent_at'] === '') {
            throw new InvalidEnvelope('Envelope is missing a non-empty string "sent_at".');
        }

        if (! isset($data['message']) || ! is_string($data['message']) || $data['message'] === '') {
            throw new InvalidEnvelope('Envelope is missing a non-empty string "message".');
        }

        $stream = $data['stream'] ?? null;

        if ($stream !== null && ! is_string($stream)) {
            throw new InvalidEnvelope('Envelope "stream" must be a string or null.');
        }

        $truncation = $data['truncation'] ?? Truncation::None->value;

        if (! is_string($truncation)) {
            throw new InvalidEnvelope('Envelope "truncation" must be a string.');
        }

        $truncation = Truncation::tryFrom($truncation);

        if (! $truncation instanceof Truncation) {
            throw new InvalidEnvelope('Envelope "truncation" is not recognized.');
        }

        return new self(
            idempotencyKey: $data['idempotency_key'],
            sentAt: $data['sent_at'],
            message: $data['message'],
            stream: $stream,
            truncation: $truncation,
            envelopeVersion: $version,
        );
    }

    /**
     * @throws InvalidEnvelope
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidEnvelope('Envelope is not valid JSON: '.$e->getMessage(), previous: $e);
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidEnvelope('Envelope JSON must decode to an object.');
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data);
    }

    /**
     * Peek at an envelope's major version without fully parsing it. The ingest endpoint
     * uses this to reject envelopes newer than it understands with a loud 4xx before it
     * attempts to interpret anything else.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidEnvelope
     */
    public static function versionFrom(array $data): int
    {
        if (! isset($data['envelope_version']) || ! is_numeric($data['envelope_version'])) {
            throw new InvalidEnvelope('Envelope is missing a numeric "envelope_version".');
        }

        return (int) $data['envelope_version'];
    }
}
