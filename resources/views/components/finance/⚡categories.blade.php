<?php

use App\Domain\Finance\Enums\TransactionCategoryType;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Finance\Services\TransactionCategoryService;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.authenticated')] class extends Component
{
    public string $name = '';

    public string $type = '';

    public bool $showForm = false;

    public function getIncomeCategoriesProperty()
    {
        return $this->categoriesOfType(TransactionCategoryType::INCOME);
    }

    public function getExpenseCategoriesProperty()
    {
        return $this->categoriesOfType(TransactionCategoryType::EXPENSE);
    }

    private function categoriesOfType(TransactionCategoryType $type)
    {
        return TransactionCategory::query()
            ->where('user_id', auth()->id())
            ->where('type', $type)
            ->orderBy('name')
            ->get();
    }

    public function create(TransactionCategoryService $categories): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:income,expense'],
        ]);

        $categories->createCategory(
            user: auth()->user(),
            name: $this->name,
            type: TransactionCategoryType::from($this->type),
        );

        $this->reset(['name', 'type']);
        $this->showForm = false;
    }
};
?>

<div>
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Categories</h1>
            <p class="mt-1 text-sm text-slate-500">Labels for where money comes from and where it goes.</p>
        </div>
        <button wire:click="$set('showForm', true)" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
            + Add category
        </button>
    </div>

    @if ($showForm)
        <form wire:submit="create" class="mt-6 space-y-4 rounded-xl border border-slate-200 bg-white p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Name</label>
                    <input wire:model="name" type="text" placeholder="e.g. Groceries" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Type</label>
                    <select wire:model="type" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                        <option value="">Select&hellip;</option>
                        <option value="income">Income</option>
                        <option value="expense">Expense</option>
                    </select>
                    @error('type') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Save category</button>
                <button type="button" wire:click="$set('showForm', false)" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
            </div>
        </form>
    @endif

    <div class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white">
            <h2 class="border-b border-slate-200 px-6 py-3 text-sm font-semibold text-slate-700">Income</h2>
            <div class="divide-y divide-slate-100">
                @forelse ($this->incomeCategories as $category)
                    <p class="px-6 py-3 text-sm text-slate-900">{{ $category->name }}</p>
                @empty
                    <p class="px-6 py-6 text-center text-sm text-slate-500">No income categories yet.</p>
                @endforelse
            </div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white">
            <h2 class="border-b border-slate-200 px-6 py-3 text-sm font-semibold text-slate-700">Expense</h2>
            <div class="divide-y divide-slate-100">
                @forelse ($this->expenseCategories as $category)
                    <p class="px-6 py-3 text-sm text-slate-900">{{ $category->name }}</p>
                @empty
                    <p class="px-6 py-6 text-center text-sm text-slate-500">No expense categories yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
