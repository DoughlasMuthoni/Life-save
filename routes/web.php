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

    Route::prefix('finance')->name('finance.')->group(function () {
        Route::livewire('/messages', 'finance.messages.paste')->name('messages');
        Route::livewire('/accounts', 'finance.accounts')->name('accounts');
        Route::livewire('/categories', 'finance.categories')->name('categories');
        Route::livewire('/transactions', 'finance.transactions')->name('transactions');
        Route::livewire('/reconciliation', 'finance.reconciliation')->name('reconciliation');
        Route::livewire('/income/new', 'finance.record-income')->name('income.create');
        Route::livewire('/expenses/new', 'finance.record-expense')->name('expenses.create');
        Route::livewire('/transfers/new', 'finance.record-transfer')->name('transfers.create');
    });
});
