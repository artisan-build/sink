#!/usr/bin/env php
<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\TokenGenerator;
use ArtisanBuild\SinkServer\Models\Message;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([^=]+)=(.*)$/', $argument, $matches) === 1) {
        $options[$matches[1]] = $matches[2];
    }
}

$database = (string) getenv('DB_DATABASE');
if (preg_match('/^sink_verify_[a-z0-9_]+$/', $database) !== 1) {
    fwrite(STDERR, "REFUSING: DB_DATABASE is not a disposable sink_verify_* database.\n");
    exit(2);
}

$appDirectory = (string) getenv('APP_DIR_FOR_VERIFY');
require $appDirectory.'/vendor/autoload.php';
$app = require $appDirectory.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$defaultDatabase = DB::selectOne('select current_database() as name')?->name;
$sinkDatabase = DB::connection('sink')->selectOne('select current_database() as name')?->name;
if ($defaultDatabase !== $database || $sinkDatabase !== $database) {
    fwrite(STDERR, "REFUSING: default and sink database identities do not match the recorded run.\n");
    exit(2);
}

$sourceApp = (string) ($options['app'] ?? 'verify-source');
$recipient = (string) ($options['recipient'] ?? 'recipient@verify.test');
$subject = (string) ($options['subject'] ?? 'Verification message');
$idempotencyKey = (string) Str::ulid();
$messageId = '<'.$idempotencyKey.'@verify.test>';
$raw = "From: Verify Sender <sender@verify.test>\r\n".
    "To: Verify Recipient <{$recipient}>\r\n".
    "Subject: {$subject}\r\n".
    "Message-ID: {$messageId}\r\n".
    "MIME-Version: 1.0\r\n".
    "Content-Type: text/html; charset=UTF-8\r\n\r\n".
    '<html><body><h1>'.htmlspecialchars($subject, ENT_QUOTES | ENT_HTML5).'</h1>'.
    '<p>Captured by the isolated Sink verifier.</p><a href="https://example.test/verify">Verification link</a></body></html>';

$generated = app(TokenGenerator::class)->generate();
Artisan::call('token:create', [
    'name' => $sourceApp,
    '--execute' => true,
    '--hash' => $generated->hash,
    '--no-interaction' => true,
]);
if (Artisan::output() === '') {
    fwrite(STDERR, "The local token command did not confirm token creation.\n");
    exit(1);
}

$payload = json_encode([
    'envelope_version' => 1,
    'idempotency_key' => $idempotencyKey,
    'sent_at' => now()->toIso8601String(),
    'stream' => null,
    'message' => base64_encode($raw),
    'truncation' => 'none',
], JSON_THROW_ON_ERROR);

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => [
            'Authorization: Bearer '.$generated->plaintext,
            'Content-Type: application/json',
            'Content-Length: '.strlen($payload),
        ],
        'content' => $payload,
        'ignore_errors' => true,
        'timeout' => 15,
    ],
]);
$responseBody = file_get_contents(rtrim((string) getenv('APP_URL'), '/').'/ingest', false, $context);
$statusLine = $http_response_header[0] ?? '';
if (! str_contains($statusLine, ' 202 ')) {
    fwrite(STDERR, "POST /ingest did not return 202 ({$statusLine}): ".(string) $responseBody."\n");
    exit(1);
}

$deadline = microtime(true) + 20;
do {
    usleep(200000);
    $message = Message::query()
        ->where('app', $sourceApp)
        ->where('idempotency_key', $idempotencyKey)
        ->first();
} while (($message === null || $message->parsed_at === null) && microtime(true) < $deadline);

if ($message === null || $message->parsed_at === null) {
    fwrite(STDERR, "The message was accepted but the database queue did not finish parsing it.\n");
    exit(1);
}

echo json_encode([
    'database' => $database,
    'http_status' => 202,
    'message_id' => $message->getKey(),
    'idempotency_key' => $idempotencyKey,
    'app' => $message->app,
    'subject' => $message->subject,
    'recipient' => $recipient,
    'parsed_at' => $message->parsed_at?->toIso8601String(),
    'link_count' => $message->link_count,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
