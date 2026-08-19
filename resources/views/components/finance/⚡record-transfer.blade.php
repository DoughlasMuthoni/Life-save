<?php

use App\Domain\Finance\Models\FinancialAccount;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Finance\Enums\TransactionCategoryType;
use App\Domain\Finance\Services\TransferService;
use App\Domain\Finance\Support\Money;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.authenticated')] class extends Component
{
    public string $fromAccountId = '';

    public string $toAccountId = '';

    public string $amount = '';

    public bool $hasFee = false;

    public string $feeCategoryId = '';

    public string $feeAmount = '';

    public string $occurredAt;

    public string $description = '';

    public function mount(): void
    {
        $this->occurredAt = now()->format('Y-m-d\TH:i');
    }

    public function getAccountsProperty()
    {
        return FinancialAccount::query()->where('user_id', auth()->id())->where('is_active', true)->get();
    }

    public function getFeeCategoriesProperty()
    {
        return TransactionCategory::query()
            ->where('user_id', auth()->id())
            ->where('type', TransactionCategoryType::EXPENSE)
            ->orderBy('name')
            ->get();
    }

    public function save(TransferService $transfers): void
    {
        $rules = [
            'fromAccountId' => ['required', 'integer', 'different:toAccountId'],
            'toAccountId' => ['required', 'integer'],
            'amount' => ['required', 'string'],
            'occurredAt' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];

        if ($this->hasFee) {
            $rules['feeCategoryId'] = ['required', 'integer'];
            $rules['feeAmount'] = ['required', 'string'];
        }

        $this->validate($rules);

        $fromAccount = $this->accounts->firstWhere('id', (int) $this->fromAccountId);
        $toAccount = $this->accounts->firstWhere('id', (int) $this->toAccountId);

        if (! $fromAccount || ! $toAccount) {
            throw ValidationException::withMessages(['fromAccountId' => 'Invalid account selection.']);
        }

        try {
            $amountMinor = Money::toMinorUnits($this->amount);
            $feeMinor = $this->hasFee ? Money::toMinorUnits($this->feeAmount) : 0;
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        $feeCategory = $this->hasFee
            ? $this->feeCategories->firstWhere('id', (int) $this->feeCategoryId)
            : null;

        if ($this->hasFee && ! $feeCategory) {
            throw ValidationException::withMessages(['feeCategoryId' => 'Invalid fee category selection.']);
        }

        $transfers->recordTransfer(
            user: auth()->user(),
            from: $fromAccount,
            to: $toAccount,
            amountMinor: $amountMinor,
            feeCategory: $feeCategory,
            feeMinor: $feeMinor,
            occurredAt: Carbon::parse($this->occurredAt),
            description: $this->description ?: null,
        );

        session()->flash('status', 'Transfer recorded.');

        $this->redirectRoute('finance.transactions', navigate: true);
    }
};
?>

<div class="mx-auto max-w-lg">
    <h1 class="text-xl font-semibold text-slate-900">Record transfer</h1>
    <p class="mt-1 text-sm text-slate-500">Moving money between your own accounts. Never counted as income or expense.</p>

    @if ($this->accounts->count() < 2)
        <div class="mt-6 rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600">
            You need at least two financial accounts to record a transfer.
            <div class="mt-3">
                <a href="{{ route('finance.accounts') }}" class="font-medium text-blue-600 hover:text-blue-700">Add an account</a>
            </div>
        </div>
    @else
        <form wire:submit="save" class="mt-6 space-y-4 rounded-xl border border-slate-200 bg-white p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-slate-700">From</label>
                    <select wire:model="fromAccountId" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                        <option value="">Select&hellip;</option>
                        @foreach ($this->accounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </select>
                    @error('fromAccountId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">To</label>
                    <select wire:model="toAccountId" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                        <option value="">Select&hellip;</option>
                        @foreach ($this->accounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </select>
                    @error('toAccountId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Amount (KES)</label>
                <input wire:model="amount" type="text" inputmode="decimal" placeholder="e.g. 20000.00" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                @error('amount') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-slate-400">This is the amount that arrives in the destination account.</p>
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input wire:model.live="hasFee" type="checkbox" class="rounded border-slate-300 text-blue-600 shadow-sm">
                This transfer had a fee
            </label>

            @if ($hasFee)
                <div class="grid grid-cols-1 gap-4 rounded-lg bg-slate-50 p-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Fee category</label>
                        <select wire:model="feeCategoryId" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                            <option value="">Select&hellip;</option>
                            @foreach ($this->feeCategories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                        @error('feeCategoryId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Fee amount (KES)</label>
                        <input wire:model="feeAmount" type="text" inputmode="decimal" placeholder="e.g. 15.00" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                        @error('feeAmount') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            @endif

            <div>
                <label class="block text-sm font-medium text-slate-700">Date &amp; time</label>
                <input wire:model="occurredAt" type="datetime-local" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                @error('occurredAt') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Description <span class="text-slate-400">(optional)</span></label>
                <input wire:model="description" type="text" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                @error('description') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex gap-3">
                <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700" wire:loading.attr="disabled">
                    Record transfer
                </button>
                <a href="{{ route('finance.transactions') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</a>
            </div>
        </form>
    @endif
</div>
