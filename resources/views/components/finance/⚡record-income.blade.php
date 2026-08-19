<?php

use App\Domain\Finance\Models\FinancialAccount;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Finance\Enums\TransactionCategoryType;
use App\Domain\Finance\Services\TransactionService;
use App\Domain\Finance\Support\Money;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.authenticated')] class extends Component
{
    public string $financialAccountId = '';

    public string $categoryId = '';

    public string $amount = '';

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

    public function getCategoriesProperty()
    {
        return TransactionCategory::query()
            ->where('user_id', auth()->id())
            ->where('type', TransactionCategoryType::INCOME)
            ->orderBy('name')
            ->get();
    }

    public function save(TransactionService $transactions): void
    {
        $this->validate([
            'financialAccountId' => ['required', 'integer'],
            'categoryId' => ['required', 'integer'],
            'amount' => ['required', 'string'],
            'occurredAt' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $account = $this->accounts->firstWhere('id', (int) $this->financialAccountId);
        $category = $this->categories->firstWhere('id', (int) $this->categoryId);

        if (! $account) {
            throw ValidationException::withMessages(['financialAccountId' => 'Invalid account selection.']);
        }

        if (! $category) {
            throw ValidationException::withMessages(['categoryId' => 'Invalid category selection.']);
        }

        try {
            $amountMinor = Money::toMinorUnits($this->amount);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        $transactions->recordIncome(
            user: auth()->user(),
            account: $account,
            category: $category,
            amountMinor: $amountMinor,
            occurredAt: Carbon::parse($this->occurredAt),
            description: $this->description ?: null,
        );

        session()->flash('status', 'Income recorded.');

        $this->redirectRoute('finance.transactions', navigate: true);
    }
};
?>

<div class="mx-auto max-w-lg">
    <h1 class="text-xl font-semibold text-slate-900">Record income</h1>
    <p class="mt-1 text-sm text-slate-500">Money that came into one of your accounts.</p>

    @if ($this->accounts->isEmpty() || $this->categories->isEmpty())
        <div class="mt-6 rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600">
            You need at least one financial account and one income category before recording income.
            <div class="mt-3 flex justify-center gap-3">
                <a href="{{ route('finance.accounts') }}" class="font-medium text-blue-600 hover:text-blue-700">Add an account</a>
                <a href="{{ route('finance.categories') }}" class="font-medium text-blue-600 hover:text-blue-700">Add a category</a>
            </div>
        </div>
    @else
        <form wire:submit="save" class="mt-6 space-y-4 rounded-xl border border-slate-200 bg-white p-6">
            <div>
                <label class="block text-sm font-medium text-slate-700">Account</label>
                <select wire:model="financialAccountId" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                    <option value="">Select&hellip;</option>
                    @foreach ($this->accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </select>
                @error('financialAccountId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Category</label>
                <select wire:model="categoryId" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                    <option value="">Select&hellip;</option>
                    @foreach ($this->categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
                @error('categoryId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Amount (KES)</label>
                <input wire:model="amount" type="text" inputmode="decimal" placeholder="e.g. 5000.00" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                @error('amount') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
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
                    Record income
                </button>
                <a href="{{ route('finance.transactions') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</a>
            </div>
        </form>
    @endif
</div>
