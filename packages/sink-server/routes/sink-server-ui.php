<?php

declare(strict_types=1);

use ArtisanBuild\SinkServer\Http\Controllers\DestroyMessageController;
use ArtisanBuild\SinkServer\Http\Controllers\MessageAttachmentController;
use ArtisanBuild\SinkServer\Http\Controllers\MessageBodyController;
use ArtisanBuild\SinkServer\Http\Controllers\MessageRawController;
use ArtisanBuild\SinkServer\Http\Controllers\PurgeMessagesController;
use Illuminate\Support\Facades\Route;

Route::livewire('inbox', 'sink-server::inbox-list')->name('sink.inbox');
Route::delete('inbox', PurgeMessagesController::class)->middleware('bfc.admin')->name('sink.inbox.purge');
Route::get('inbox/{message}/body', MessageBodyController::class)->name('sink.message.body');
Route::get('inbox/{message}/raw', MessageRawController::class)->name('sink.message.raw');
Route::get('inbox/{message}/attachments/{attachment}', MessageAttachmentController::class)->name('sink.message.attachment');
Route::delete('inbox/{message}', DestroyMessageController::class)->middleware('bfc.admin')->name('sink.message.destroy');
Route::livewire('inbox/{message}', 'sink-server::message-detail')->name('sink.message');
