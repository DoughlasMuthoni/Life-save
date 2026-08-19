<?php

use App\Domain\Finance\Enums\JournalType;
use App\Domain\Finance\Models\Journal;
use App\Domain\Finance\Support\Money;
use App\Domain\Shopping\Models\Merchant;
use App\Domain\Shopping\Models\Purchase;
use App\Domain\Shopping\Services\MerchantService;
use App\Domain\Shopping\Services\PurchaseService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.authenticated')] class extends Component
{
    public bool $showForm = false;

    public string $merchantName = '';

    public string $totalAmount = '';

    public string $purchasedAt = '';

    public string $journalId = '';

    public string $notes = '';

    public ?int $addingItemToPurchaseId = null;

    public string $itemName = '';

    public string $itemQuantity = '1';

    public string $itemUnitPrice = '';

    public function mount(): void
    {
        $this->purchasedAt = now()->format('Y-m-d');
    }

    public function getPurchasesProperty()
    {
        return Purchase::query()
            ->where('user_id', auth()->id())
            ->with(['merchant', 'items', 'journal'])
            ->latest('purchased_at')
            ->limit(30)
            ->get();
    }

    public function getMerchantsProperty()
    {
        return Merchant::query()->where('user_id', auth()->id())->orderBy('name')->get();
    }

    /**
     * Recent expense transactions not already linked to a purchase — the
     * candidates for "how was this paid".
     */
    public function getUnlinkedJournalsProperty()
    {
        $linkedJournalIds = Purchase::query()->where('user_id', auth()->id())->whereNotNull('journal_id')->pluck('journal_id');

        return Journal::query()
            ->where('user_id', auth()->id())
            ->where('journal_type', JournalType::EXPENSE)
            ->whereNotIn('id', $linkedJournalIds)
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get();
    }

    public function create(PurchaseService $purchases, MerchantService $merchants): void
    {
        $this->validate([
            'totalAmount' => ['required', 'string'],
            'purchasedAt' => ['required', 'date'],
            'merchantName' => ['nullable', 'string', 'max:255'],
            'journalId' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $merchant = $this->merchantName !== '' ? $merchants->findOrCreate(auth()->user(), $this->merchantName) : null;
        $journal = $this->journalId !== '' ? $this->unlinkedJournals->firstWhere('id', (int) $this->journalId) : null;

        try {
            $purchases->createPurchase(
                user: auth()->user(),
                totalAmountMinor: Money::toMinorUnits($this->totalAmount),
                purchasedAt: \Carbon\Carbon::parse($this->purchasedAt),
                merchant: $merchant,
                journal: $journal,
                notes: $this->notes ?: null,
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['totalAmount' => $e->getMessage()]);
        }

        $this->reset(['merchantName', 'totalAmount', 'journalId', 'notes']);
        $this->purchasedAt = now()->format('Y-m-d');
        $this->showForm = false;
    }

    public function startAddingItem(int $purchaseId): void
    {
        $this->addingItemToPurchaseId = $purchaseId;
        $this->itemName = '';
        $this->itemQuantity = '1';
        $this->itemUnitPrice = '';
    }

    public function cancelAddingItem(): void
    {
        $this->addingItemToPurchaseId = null;
    }

    public function confirmAddItem(PurchaseService $purchases): void
    {
        $this->validate([
            'itemName' => ['required', 'string', 'max:255'],
            'itemQuantity' => ['required', 'integer', 'min:1'],
            'itemUnitPrice' => ['required', 'string'],
        ]);

        $purchase = Purchase::where('user_id', auth()->id())->findOrFail($this->addingItemToPurchaseId);

        $purchases->addItem($purchase, $this->itemName, (int) $this->itemQuantity, Money::toMinorUnits($this->itemUnitPrice));

        $this->itemName = '';
        $this->itemQuantity = '1';
        $this->itemUnitPrice = '';
    }
};
?>

<div>
    <x-ui.page-header title="Shopping" subtitle="What you bought — separate from how it was paid.">
        <x-slot:actions>
            <x-ui.button wire:click="$set('showForm', true)" variant="primary">
                <x-icon name="plus" class="h-4 w-4" /> Log a purchase
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($showForm)
        <form wire:submit="create" class="mt-6 space-y-4 rounded-2xl border border-slate-200 bg-white p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Merchant <span class="text-slate-400">(optional)</span></label>
                    <input wire:model="merchantName" list="merchant-list" type="text" placeholder="e.g. Quickmart Juja" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    <datalist id="merchant-list">
                        @foreach ($this->merchants as $merchant)
                            <option value="{{ $merchant->name }}"></option>
                        @endforeach
                    </datalist>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Total</label>
                    <div class="relative mt-1">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm font-medium text-slate-400">KSh</span>
                        <input wire:model="totalAmount" type="text" inputmode="decimal" placeholder="4,350.00" class="block w-full rounded-lg border-slate-300 pl-11 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    </div>
                    @error('totalAmount') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Date</label>
                    <input wire:model="purchasedAt" type="date" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Paid via <span class="text-slate-400">(optional)</span></label>
                    <select wire:model="journalId" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <option value="">Not linked</option>
                        @foreach ($this->unlinkedJournals as $journal)
                            <option value="{{ $journal->id }}">
                                {{ $journal->occurred_at->format('M j') }} &mdash; {{ $journal->description ?: 'Expense' }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-400">Only unlinked expense transactions are listed.</p>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-slate-700">Notes <span class="text-slate-400">(optional)</span></label>
                    <input wire:model="notes" type="text" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                </div>
            </div>
            <div class="flex gap-3">
                <x-ui.button type="submit" variant="primary">Save</x-ui.button>
                <x-ui.button type="button" wire:click="$set('showForm', false)" variant="secondary">Cancel</x-ui.button>
            </div>
        </form>
    @endif

    <div class="mt-6 space-y-4">
        @forelse ($this->purchases as $purchase)
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-indigo-500">
                            <x-icon name="bag" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="break-words font-medium text-slate-900">{{ $purchase->merchant->name ?? 'Unspecified merchant' }}</p>
                            <p class="text-xs text-slate-400">
                                {{ $purchase->purchased_at->format('M j, Y') }}
                                @if ($purchase->journal)
                                    &middot; paid via {{ ucfirst($purchase->journal->journal_type->value) }}
                                @else
                                    &middot; not linked to a transaction
                                @endif
                            </p>
                            @if ($purchase->notes)
                                <p class="mt-1 break-words text-sm text-slate-500">{{ $purchase->notes }}</p>
                            @endif
                        </div>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="font-semibold text-slate-900">{{ Money::formatMinor($purchase->total_amount_minor) }}</p>
                        <button wire:click="startAddingItem({{ $purchase->id }})" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-700">
                            <x-icon name="plus" class="h-3 w-3" /> Add item
                        </button>
                    </div>
                </div>

                @if ($purchase->items->isNotEmpty())
                    <div class="mt-3 divide-y divide-slate-100 border-t border-slate-100 pt-3">
                        @foreach ($purchase->items as $item)
                            <div class="flex items-center justify-between py-1.5 text-sm">
                                <span class="text-slate-600">{{ $item->quantity }}&times; {{ $item->name }}</span>
                                <span class="text-slate-700">{{ Money::formatMinor($item->lineTotalMinor()) }}</span>
                            </div>
                        @endforeach
                        @if (! $purchase->itemsReconcileWithTotal())
                            <p class="flex items-center gap-1 pt-1 text-xs text-slate-400">
                                <x-icon name="info" class="h-3 w-3" />
                                Items total {{ Money::formatMinor($purchase->itemsTotalMinor()) }}, purchase total {{ Money::formatMinor($purchase->total_amount_minor) }}.
                            </p>
                        @endif
                    </div>
                @endif

                @if ($addingItemToPurchaseId === $purchase->id)
                    <form wire:submit="confirmAddItem" class="mt-3 rounded-lg bg-slate-50 p-4">
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div class="sm:col-span-1">
                                <label class="block text-xs font-medium text-slate-500">Item</label>
                                <input wire:model="itemName" type="text" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                @error('itemName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500">Quantity</label>
                                <input wire:model="itemQuantity" type="number" min="1" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                @error('itemQuantity') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500">Unit price</label>
                                <div class="relative mt-1">
                                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-xs font-medium text-slate-400">KSh</span>
                                    <input wire:model="itemUnitPrice" type="text" inputmode="decimal" class="block w-full rounded-lg border-slate-300 pl-10 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                </div>
                                @error('itemUnitPrice') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div class="mt-3 flex gap-3">
                            <x-ui.button type="submit" variant="primary" size="sm">Add</x-ui.button>
                            <x-ui.button type="button" wire:click="cancelAddingItem" variant="secondary" size="sm">Done</x-ui.button>
                        </div>
                    </form>
                @endif
            </div>
        @empty
            <x-ui.empty-state icon="bag" title="No purchases logged yet" />
        @endforelse
    </div>
</div>
