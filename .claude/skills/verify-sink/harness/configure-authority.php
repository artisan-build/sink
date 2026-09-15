#!/usr/bin/env php
<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\AuthorityMode;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require getenv('APP_DIR_FOR_VERIFY').'/vendor/autoload.php';
$app = require getenv('APP_DIR_FOR_VERIFY').'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$authority = DB::table('bfc_authority')->where('key', InstallationAuthority::KEY)->first(['generation']);

if ($authority === null) {
    fwrite(STDERR, "Disposable installation authority is missing.\n");
    exit(1);
}

DB::table('bfc_authority')->where('key', InstallationAuthority::KEY)->update([
    'mode' => AuthorityMode::Managed->value,
    'generation' => ((int) $authority->generation) + 1,
    'issuer' => 'https://sink-verify-authority.test',
    'connection_id' => 'sink-verify-connection',
    'organization_id' => 'sink-verify-organization',
    'installation_id' => 'sink-verify-installation',
    'authority_base_url' => 'https://127.0.0.1:'.getenv('VERIFY_AUTHORITY_PORT'),
]);
fwrite(STDOUT, "managed authority configured for disposable installation\n");
