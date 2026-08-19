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

    public function providerIcon(FinancialAccountProvider $provider): string
    {
        return match ($provider) {
            FinancialAccountProvider::MPESA, FinancialAccountProvider::KCB_MPESA => 'phone',
            FinancialAccountProvider::MSHWARI => 'flag',
            FinancialAccountProvider::BANK => 'bank',
            FinancialAccountProvider::CASH => 'cash',
            FinancialAccountProvider::OTHER => 'wallet',
        };
    }

    public function providerColor(FinancialAccountProvider $provider): string
    {
        return match ($provider) {
            FinancialAccountProvider::MPESA => 'green',
            FinancialAccountProvider::KCB_MPESA => 'blue',
            FinancialAccountProvider::MSHWARI => 'purple',
            FinancialAccountProvider::BANK => 'amber',
            FinancialAccountProvider::CASH => 'slate',
            FinancialAccountProvider::OTHER => 'slate',
        };
    }
};
?>

<div>
    <x-ui.page-header title="Financial Accounts" subtitle="The real places your money actually lives.">
        <x-slot:actions>
            <x-ui.button wire:click="$set('showForm', true)" variant="primary">
                <x-icon name="plus" class="h-4 w-4" /> Add account
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($showForm)
        <form wire:submit="create" class="mt-6 space-y-4 rounded-2xl border border-slate-200 bg-white p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Name</label>
                    <input wire:model="name" type="text" placeholder="e.g. M-Pesa" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Provider</label>
                    <select wire:model="provider" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <option value="">Select&hellip;</option>
                        @foreach ($this->providers() as $case)
                            <option value="{{ $case->value }}">{{ ucwords(str_replace('_', ' ', $case->value)) }}</option>
                        @endforeach
                    </select>
                    @error('provider') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Currency</label>
                    <input wire:model="currency" type="text" maxlength="3" class="mt-1 block w-full rounded-lg border-slate-300 uppercase shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    @error('currency') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Identifier <span class="text-slate-400">(optional, e.g. last 4 digits)</span></label>
                    <input wire:model="accountIdentifier" type="text" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    @error('accountIdentifier') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex gap-3">
                <x-ui.button type="submit" variant="primary">Save account</x-ui.button>
                <x-ui.button type="button" wire:click="$set('showForm', false)" variant="secondary">Cancel</x-ui.button>
            </div>
        </form>
    @endif

    @if ($this->accounts->isEmpty())
        <x-ui.empty-state icon="wallet" title="No accounts yet" description="Add your first account to start tracking your money." class="mt-6" />
    @else
        <div class="mt-6 divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
            @foreach ($this->accounts as $account)
                @php
                    $colorClasses = ['green' => 'bg-green-50 text-green-600', 'blue' => 'bg-blue-50 text-blue-600', 'purple' => 'bg-purple-50 text-purple-600', 'amber' => 'bg-amber-50 text-amber-600', 'slate' => 'bg-slate-100 text-slate-600'][$this->providerColor($account->provider)];
                @endphp
                <div class="flex items-center gap-4 px-6 py-4">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $colorClasses }}">
                        <x-icon :name="$this->providerIcon($account->provider)" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-slate-900">{{ $account->name }}</p>
                        <p class="text-sm text-slate-500">{{ ucwords(str_replace('_', ' ', $account->provider->value)) }} &middot; {{ $account->currency }}</p>
                    </div>
                    <p class="shrink-0 text-lg font-semibold text-slate-900">{{ Money::formatMinor($account->balanceMinor(), $account->currency) }}</p>
                </div>
            @endforeach
        </div>
    @endif
</div>
