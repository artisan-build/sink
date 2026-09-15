<?php

use Illuminate\Support\Facades\Route;

Route::middleware('bfc.auth')->group(function (): void {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});
