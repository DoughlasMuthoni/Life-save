<?php

use App\Http\Controllers\Auth\LogoutController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

Route::middleware('guest')->group(function () {
    Route::livewire('/login', 'auth.login')->name('login');
});

Route::middleware('auth')->group(function () {
    Route::livewire('/dashboard', 'dashboard')->name('dashboard');
    Route::post('/logout', LogoutController::class)->name('logout');
});
