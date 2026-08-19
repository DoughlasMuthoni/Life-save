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
            ->where('type', TransactionCategoryType::EXPENSE)
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

        $transactions->recordExpense(
            user: auth()->user(),
            account: $account,
            category: $category,
            amountMinor: $amountMinor,
            occurredAt: Carbon::parse($this->occurredAt),
            description: $this->description ?: null,
        );

        session()->flash('status', 'Expense recorded.');

        $this->redirectRoute('finance.transactions', navigate: true);
    }
};
?>

<div class="mx-auto max-w-lg">
    <div class="flex items-center gap-3">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600">
            <x-icon name="trend-down" class="h-5 w-5" />
        </span>
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Record expense</h1>
            <p class="text-sm text-slate-500">Money that left one of your accounts.</p>
        </div>
    </div>

    @if ($this->accounts->isEmpty() || $this->categories->isEmpty())
        <x-ui.empty-state icon="wallet" title="A bit more setup needed" description="You need at least one financial account and one expense category before recording an expense." class="mt-6">
            <x-slot:actions>
                <x-ui.button :href="route('finance.accounts')" variant="secondary" size="sm">Add an account</x-ui.button>
                <x-ui.button :href="route('finance.categories')" variant="secondary" size="sm">Add a category</x-ui.button>
            </x-slot:actions>
        </x-ui.empty-state>
    @else
        <form wire:submit="save" class="mt-6 space-y-4 rounded-2xl border border-slate-200 bg-white p-6">
            <div>
                <label class="block text-sm font-medium text-slate-700">Account</label>
                <select wire:model="financialAccountId" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    <option value="">Select&hellip;</option>
                    @foreach ($this->accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </select>
                @error('financialAccountId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Category</label>
                <select wire:model="categoryId" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    <option value="">Select&hellip;</option>
                    @foreach ($this->categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
                @error('categoryId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Amount</label>
                <div class="relative mt-1">
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm font-medium text-slate-400">KSh</span>
                    <input wire:model="amount" type="text" inputmode="decimal" placeholder="2,450.00" class="block w-full rounded-lg border-slate-300 pl-11 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                </div>
                @error('amount') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Date &amp; time</label>
                <input wire:model="occurredAt" type="datetime-local" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                @error('occurredAt') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Description <span class="text-slate-400">(optional)</span></label>
                <input wire:model="description" type="text" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                @error('description') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex gap-3 pt-2">
                <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled">
                    Record expense
                </x-ui.button>
                <x-ui.button :href="route('finance.transactions')" variant="secondary">Cancel</x-ui.button>
            </div>
        </form>
    @endif
</div>
