<?php

declare(strict_types=1);

use ArtisanBuild\SinkServer\Http\Controllers\CapabilitiesController;
use ArtisanBuild\SinkServer\Http\Controllers\IngestController;
use Illuminate\Support\Facades\Route;

Route::post('ingest', [IngestController::class, 'ingest'])->name('sink-server.ingest');
Route::get('capabilities', CapabilitiesController::class)->name('sink-server.capabilities');
