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
    <x-ui.page-header title="Categories" subtitle="Labels for where money comes from and where it goes.">
        <x-slot:actions>
            <x-ui.button wire:click="$set('showForm', true)" variant="primary">
                <x-icon name="plus" class="h-4 w-4" /> Add category
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($showForm)
        <form wire:submit="create" class="mt-6 space-y-4 rounded-2xl border border-slate-200 bg-white p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Name</label>
                    <input wire:model="name" type="text" placeholder="e.g. Groceries" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Type</label>
                    <select wire:model="type" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <option value="">Select&hellip;</option>
                        <option value="income">Income</option>
                        <option value="expense">Expense</option>
                    </select>
                    @error('type') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex gap-3">
                <x-ui.button type="submit" variant="primary">Save category</x-ui.button>
                <x-ui.button type="button" wire:click="$set('showForm', false)" variant="secondary">Cancel</x-ui.button>
            </div>
        </form>
    @endif

    <div class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-2">
        <x-ui.section>
            <x-slot:title>
                <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-green-500"></span> Income</span>
            </x-slot:title>
            <div class="divide-y divide-slate-100">
                @forelse ($this->incomeCategories as $category)
                    <p class="flex items-center gap-2 px-5 py-3 text-sm text-slate-900">
                        <x-icon name="tag" class="h-4 w-4 text-slate-400" /> {{ $category->name }}
                    </p>
                @empty
                    <p class="px-5 py-8 text-center text-sm text-slate-500">No income categories yet.</p>
                @endforelse
            </div>
        </x-ui.section>
        <x-ui.section>
            <x-slot:title>
                <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-red-400"></span> Expense</span>
            </x-slot:title>
            <div class="divide-y divide-slate-100">
                @forelse ($this->expenseCategories as $category)
                    <p class="flex items-center gap-2 px-5 py-3 text-sm text-slate-900">
                        <x-icon name="tag" class="h-4 w-4 text-slate-400" /> {{ $category->name }}
                    </p>
                @empty
                    <p class="px-5 py-8 text-center text-sm text-slate-500">No expense categories yet.</p>
                @endforelse
            </div>
        </x-ui.section>
    </div>
</div>
