<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    //
};
?>

<div class="mx-auto max-w-3xl px-4 py-10">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Welcome, {{ auth()->user()->name }}</h1>
            <p class="mt-1 text-sm text-slate-500">
                Signed in as {{ auth()->user()->email }}. The real financial dashboard lands in a later phase
                (see CLAUDE.md &sect;18) &mdash; this placeholder just confirms auth is wired up correctly.
            </p>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button
                type="submit"
                class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50"
            >
                Sign out
            </button>
        </form>
    </div>
</div>
