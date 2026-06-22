<?php

use App\Livewire\Admin\Invitations;
use App\Livewire\Auth\AcceptInvitation;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::livewire('register/{token}', AcceptInvitation::class)->name('register.invitation');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('admin/invitations', Invitations::class)
        ->middleware('bfc.admin')
        ->name('invitations');
});

require __DIR__.'/settings.php';
