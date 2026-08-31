#!/usr/bin/env php
<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Invitation;
use ArtisanBuild\SinkServer\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
    fwrite(STDERR, "REFUSING: database identity mismatch.\n");
    exit(2);
}

$result = [
    'driver' => DB::connection()->getDriverName(),
    'database' => $defaultDatabase,
    'sink_database' => $sinkDatabase,
];

if (isset($options['invitation-prefix'])) {
    $prefix = (string) $options['invitation-prefix'];
    $invitations = Invitation::query()
        ->where('email', 'like', $prefix.'%')
        ->orderBy('email')
        ->get();
    $result['invitations'] = $invitations->map(fn (Invitation $invitation): array => [
        'email' => $invitation->email,
        'status' => $invitation->accepted_at !== null ? 'accepted' : ($invitation->expires_at?->isPast() ? 'expired' : 'pending'),
        'accepted_at' => $invitation->accepted_at?->toIso8601String(),
        'expires_at' => $invitation->expires_at?->toIso8601String(),
    ])->all();
    $result['count'] = $invitations->count();
} elseif (isset($options['user-email'])) {
    $email = (string) $options['user-email'];
    $users = User::query()->where('email', $email)->orderBy('id')->get();
    $result['users'] = $users->map(fn (User $user): array => [
        'id' => $user->getKey(),
        'name' => $user->name,
        'email' => $user->email,
        'is_admin' => $user->is_admin,
        'email_verified_at' => $user->email_verified_at?->toIso8601String(),
    ])->all();
    $result['count'] = $users->count();
} elseif (isset($options['message-subject'])) {
    $subject = (string) $options['message-subject'];
    $messages = Message::query()
        ->with('recipients')
        ->where('subject', $subject)
        ->orderBy('id')
        ->get();
    $result['messages'] = $messages->map(fn (Message $message): array => [
        'id' => $message->getKey(),
        'app' => $message->app,
        'subject' => $message->subject,
        'recipients' => $message->recipients->pluck('address')->all(),
        'parsed_at' => $message->parsed_at?->toIso8601String(),
        'attachment_count' => $message->attachment_count,
        'link_count' => $message->link_count,
        'raw_object_key' => $message->raw_object_key,
        'raw_object_exists' => Storage::disk((string) config('sink-server.disk'))->exists($message->raw_object_key),
    ])->all();
    $result['count'] = $messages->count();
} else {
    $result['counts'] = [
        'users' => DB::table('users')->count(),
        'invitations' => DB::table('invitations')->count(),
        'sessions' => DB::table('sessions')->count(),
        'jobs' => DB::table('jobs')->count(),
        'failed_jobs' => DB::table('failed_jobs')->count(),
        'messages' => Message::query()->count(),
    ];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
