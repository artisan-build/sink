#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $argument, $matches) === 1) {
        $options[$matches[1]] = $matches[2] ?? true;
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

$email = (string) ($options['email'] ?? 'admin@verify.test');
$name = (string) ($options['name'] ?? 'Verify Admin');
$password = (string) ($options['password'] ?? 'verify-password');

$user = User::query()->where('email', $email)->first() ?? new User;
$user->forceFill([
    'name' => $name,
    'email' => $email,
    'password' => $password,
    'email_verified_at' => now(),
    'is_admin' => isset($options['admin']),
])->save();

echo json_encode([
    'database' => $database,
    'user_id' => $user->getKey(),
    'email' => $user->email,
    'is_admin' => $user->is_admin,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
