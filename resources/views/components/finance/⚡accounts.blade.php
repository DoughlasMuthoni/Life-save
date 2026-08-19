<?php

use App\Domain\Finance\Enums\FinancialAccountProvider;
use App\Domain\Finance\Models\FinancialAccount;
use App\Domain\Finance\Services\FinancialAccountService;
use App\Domain\Finance\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.authenticated')] class extends Component
{
    public string $name = '';

    public string $provider = '';

    public string $currency = 'KES';

    public string $accountIdentifier = '';

    public bool $showForm = false;

    public function providers(): array
    {
        return FinancialAccountProvider::cases();
    }

    public function getAccountsProperty()
    {
        return FinancialAccount::query()
            ->where('user_id', auth()->id())
            ->latest()
            ->get();
    }

    public function create(FinancialAccountService $accounts): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'provider' => ['required', 'string', 'in:'.implode(',', array_map(fn ($c) => $c->value, FinancialAccountProvider::cases()))],
            'currency' => ['required', 'string', 'size:3'],
            'accountIdentifier' => ['nullable', 'string', 'max:255'],
        ]);

        $accounts->createAccount(
            user: auth()->user(),
            name: $this->name,
            provider: FinancialAccountProvider::from($this->provider),
            currency: strtoupper($this->currency),
            accountIdentifier: $this->accountIdentifier ?: null,
        );

        $this->reset(['name', 'provider', 'accountIdentifier']);
        $this->currency = 'KES';
        $this->showForm = false;
    }
};
?>

<div>
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Financial Accounts</h1>
            <p class="mt-1 text-sm text-slate-500">The real places your money actually lives.</p>
        </div>
        <button
            wire:click="$set('showForm', true)"
            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
        >
            + Add account
        </button>
    </div>

    @if ($showForm)
        <form wire:submit="create" class="mt-6 space-y-4 rounded-xl border border-slate-200 bg-white p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Name</label>
                    <input wire:model="name" type="text" placeholder="e.g. M-Pesa" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Provider</label>
                    <select wire:model="provider" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                        <option value="">Select&hellip;</option>
                        @foreach ($this->providers() as $case)
                            <option value="{{ $case->value }}">{{ ucwords(str_replace('_', ' ', $case->value)) }}</option>
                        @endforeach
                    </select>
                    @error('provider') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Currency</label>
                    <input wire:model="currency" type="text" maxlength="3" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm uppercase">
                    @error('currency') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Identifier <span class="text-slate-400">(optional, e.g. last 4 digits)</span></label>
                    <input wire:model="accountIdentifier" type="text" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                    @error('accountIdentifier') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Save account</button>
                <button type="button" wire:click="$set('showForm', false)" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
            </div>
        </form>
    @endif

    <div class="mt-6 divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
        @forelse ($this->accounts as $account)
            <div class="flex items-center justify-between px-6 py-4">
                <div>
                    <p class="font-medium text-slate-900">{{ $account->name }}</p>
                    <p class="text-sm text-slate-500">{{ ucwords(str_replace('_', ' ', $account->provider->value)) }} &middot; {{ $account->currency }}</p>
                </div>
                <p class="text-lg font-semibold text-slate-900">{{ Money::formatMinor($account->balanceMinor(), $account->currency) }}</p>
            </div>
        @empty
            <p class="px-6 py-8 text-center text-sm text-slate-500">No accounts yet.</p>
        @endforelse
    </div>
</div>
