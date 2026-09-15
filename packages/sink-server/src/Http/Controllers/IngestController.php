<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Http\Controllers;

use ArtisanBuild\BuiltForCloud\Auth\CredentialResolver;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialUsageRecorder;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\SinkContracts\Envelope;
use ArtisanBuild\SinkContracts\Exceptions\InvalidEnvelope;
use ArtisanBuild\SinkServer\Actions\QueueMessageBlobCleanup;
use ArtisanBuild\SinkServer\Jobs\ParseMessage;
use ArtisanBuild\SinkServer\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;

final class IngestController
{
    public function ingest(
        Request $request,
        CredentialResolver $credentials,
        CredentialUsageRecorder $usage,
    ): JsonResponse {
        $credential = $credentials->resolve(CredentialKind::Bearer, $request->bearerToken());

        if ($credential?->purpose !== CredentialPurpose::Consumption
            || $credential->subject_type !== SubjectType::Installation
            || $credential->user_id !== null
            || ! $usage->recordUsage($credential)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $appId = $credential->subject_ref;

        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return response()->json(['message' => 'Envelope is not valid JSON: '.$e->getMessage()], 422);
        }

        if (! is_array($data) || array_is_list($data)) {
            return response()->json(['message' => 'Envelope JSON must decode to an object.'], 422);
        }

        try {
            /** @var array<string, mixed> $data */
            $version = Envelope::versionFrom($data);
        } catch (InvalidEnvelope $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($version > Envelope::VERSION) {
            return response()->json([
                'message' => "Envelope v{$version} is newer than this Sink instance (max v".Envelope::VERSION.') — upgrade your Sink server.',
            ], 422);
        }

        try {
            $envelope = Envelope::fromArray($data);
        } catch (InvalidEnvelope $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $envelope->idempotencyKey)) {
            return response()->json(['message' => 'Envelope "idempotency_key" must be a valid ULID.'], 422);
        }

        $raw = base64_decode($envelope->message, true);

        if ($raw === false) {
            return response()->json(['message' => 'Envelope "message" must be valid base64.'], 422);
        }

        $key = "raw/{$appId}/".(string) Str::ulid().'.eml';
        $connection = (new Message)->getConnection();
        $message = $connection->transaction(function () use ($appId, $envelope, $key, $raw, $connection): Message {
            $message = Message::query()->firstOrCreate([
                'app' => $appId,
                'idempotency_key' => $envelope->idempotencyKey,
            ], [
                'stream' => $envelope->stream,
                'sent_at' => $this->parseSentAt($envelope->sentAt),
                'received_at' => now(),
                'truncation' => $envelope->truncation->value,
                'raw_object_key' => $key,
                'size_bytes' => strlen($raw),
            ]);
            $message = Message::query()->whereKey($message->getKey())->lockForUpdate()->firstOrFail();
            $replacedObjectKey = $message->raw_object_key;

            Storage::disk((string) config('sink-server.disk'))->put($key, $raw);

            $message->forceFill([
                'stream' => $envelope->stream,
                'sent_at' => $this->parseSentAt($envelope->sentAt),
                'received_at' => now(),
                'truncation' => $envelope->truncation->value,
                'raw_object_key' => $key,
                'size_bytes' => strlen($raw),
            ])->save();

            if ($replacedObjectKey !== $key) {
                app(QueueMessageBlobCleanup::class)([$replacedObjectKey], $connection);
            }

            return $message;
        });

        $job = new ParseMessage((int) $message->getKey());

        app()->terminating(function () use ($job): void {
            Bus::dispatch($job);
        });

        return response()->json(['id' => $message->getKey()], 202);
    }

    private function parseSentAt(string $sentAt): ?Carbon
    {
        try {
            return Carbon::parse($sentAt);
        } catch (\Throwable) {
            return null;
        }
    }
}
