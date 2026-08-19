<?php

use App\Domain\Finance\Models\FinancialAccount;
use App\Domain\Finance\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.authenticated')] class extends Component
{
    public function getAccountsProperty()
    {
        return FinancialAccount::query()
            ->where('user_id', auth()->id())
            ->where('is_active', true)
            ->get();
    }
};
?>

<div>
    <h1 class="text-xl font-semibold text-slate-900">Welcome, {{ auth()->user()->name }}</h1>
    <p class="mt-1 text-sm text-slate-500">
        The full financial dashboard (CLAUDE.md &sect;18, Phase 7) lands once savings, reports and reconciliation
        exist. For now, here's what's actually in the ledger.
    </p>

    @if ($this->accounts->isEmpty())
        <div class="mt-6 rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center">
            <p class="text-sm text-slate-600">You don't have any financial accounts yet.</p>
            <a href="{{ route('finance.accounts') }}" class="mt-3 inline-block rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                Add your first account
            </a>
        </div>
    @else
        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->accounts as $account)
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-sm text-slate-500">{{ $account->name }}</p>
                    <p class="mt-1 text-lg font-semibold text-slate-900">
                        {{ Money::formatMinor($account->balanceMinor(), $account->currency) }}
                    </p>
                </div>
            @endforeach
        </div>

        <div class="mt-6 flex gap-3 text-sm">
            <a href="{{ route('finance.transactions') }}" class="font-medium text-blue-600 hover:text-blue-700">View transactions &rarr;</a>
            <a href="{{ route('finance.accounts') }}" class="font-medium text-blue-600 hover:text-blue-700">Manage accounts &rarr;</a>
        </div>
    @endif
</div>
