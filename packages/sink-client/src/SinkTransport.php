<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkClient;

use ArtisanBuild\SinkContracts\Envelope;
use ArtisanBuild\SinkContracts\Truncation;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class SinkTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly ?string $stream,
        private readonly int $retryAttempts,
        private readonly int $retryBaseMs,
        private readonly float $timeout,
        private readonly int $maxMessageBytes,
        private readonly Factory $http,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'sink';
    }

    protected function doSend(SentMessage $message): void
    {
        $idempotencyKey = (string) Str::ulid();
        [$rawMime, $truncation] = $this->messagePayload($message);

        $envelope = Envelope::make(
            idempotencyKey: $idempotencyKey,
            sentAt: Carbon::now()->toIso8601String(),
            message: base64_encode($rawMime),
            stream: $this->stream,
            truncation: $truncation,
        )->toArray();

        $this->http
            ->withToken($this->token)
            ->timeout($this->timeout)
            ->retry($this->retryAttempts, fn (int $attempt): int => $this->retryDelay($attempt), throw: true)
            ->post($this->ingestUrl(), $envelope)
            ->throw();
    }

    /**
     * @return array{0: string, 1: Truncation}
     */
    private function messagePayload(SentMessage $message): array
    {
        $rawMime = $message->toString();

        if ($this->withinLimit($rawMime)) {
            return [$rawMime, Truncation::None];
        }

        $withoutAttachments = $this->withoutAttachments($message->getOriginalMessage());

        if ($this->withinLimit($withoutAttachments)) {
            return [$withoutAttachments, Truncation::AttachmentsDropped];
        }

        return [$this->headersOnly($withoutAttachments), Truncation::HeadersOnly];
    }

    private function withoutAttachments(RawMessage $message): string
    {
        if (! $message instanceof Email) {
            return $message->toString();
        }

        $email = clone $message;
        $reflection = new ReflectionClass($email);

        foreach (['attachments' => [], 'cachedBody' => null] as $propertyName => $value) {
            if ($reflection->hasProperty($propertyName)) {
                $property = $reflection->getProperty($propertyName);
                $property->setValue($email, $value);
            }
        }

        return $email->toString();
    }

    private function headersOnly(string $rawMime): string
    {
        if (preg_match("/\r\n\r\n|\n\n/", $rawMime, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $rawMime;
        }

        $separator = $matches[0][0];
        $offset = (int) $matches[0][1];

        return substr($rawMime, 0, $offset).$separator;
    }

    private function withinLimit(string $rawMime): bool
    {
        return strlen($rawMime) <= max(0, $this->maxMessageBytes);
    }

    private function retryDelay(int $attempt): int
    {
        $base = max(1, $this->retryBaseMs);
        $exponential = $base * (2 ** max(0, $attempt - 1));

        return $exponential + random_int(0, $base);
    }

    private function ingestUrl(): string
    {
        return rtrim($this->url, '/').'/ingest';
    }
}
