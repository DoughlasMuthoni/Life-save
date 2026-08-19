<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    /**
     * Attempt to authenticate the single owner account.
     *
     * Rate limited per email+IP to blunt credential-stuffing / brute force
     * attempts against a system that holds financial data (CLAUDE.md §12).
     */
    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Please try again in {$seconds} seconds.",
            ]);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        session()->regenerate();

        $this->redirectRoute('dashboard', navigate: true);
    }
};
?>

<div class="flex min-h-screen items-center justify-center px-4">
    <div class="w-full max-w-sm">
        <div class="mb-8 text-center">
            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-blue-600 text-white font-semibold text-lg">
                LO
            </div>
            <h1 class="text-xl font-semibold text-slate-900">{{ config('app.name') }}</h1>
            <p class="mt-1 text-sm text-slate-500">Sign in to your account</p>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <form wire:submit="login" class="space-y-4">
                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700">Email</label>
                    <input
                        wire:model="email"
                        id="email"
                        type="email"
                        autocomplete="username"
                        required
                        autofocus
                        class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                    >
                    @error('email')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
                    <input
                        wire:model="password"
                        id="password"
                        type="password"
                        autocomplete="current-password"
                        required
                        class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                    >
                    @error('password')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input wire:model="remember" type="checkbox" class="rounded border-slate-300 text-blue-600 shadow-sm focus:ring-blue-500">
                    Remember me
                </label>

                <button
                    type="submit"
                    class="w-full rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                    wire:loading.attr="disabled"
                >
                    <span wire:loading.remove>Sign in</span>
                    <span wire:loading>Signing in&hellip;</span>
                </button>
            </form>
        </div>

        <p class="mt-6 text-center text-xs text-slate-400">
            This is a private, single-owner system. There is no self-service registration.
        </p>
    </div>
</div>
