<?php

use App\Domain\Finance\Support\Money;
use App\Domain\Goals\Enums\GoalStatus;
use App\Domain\Goals\Models\Goal;
use App\Domain\Support\Enums\Priority;
use App\Domain\Wishlist\Enums\WishlistStatus;
use App\Domain\Wishlist\Models\WishlistItem;
use App\Domain\Wishlist\Services\WishlistAffordabilityService;
use App\Domain\Wishlist\Services\WishlistService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.authenticated')] class extends Component
{
    public bool $showForm = false;

    public string $name = '';

    public string $estimatedPrice = '';

    public string $priority = 'medium';

    public string $category = '';

    public string $targetPurchaseDate = '';

    public string $linkedGoalId = '';

    public function getActiveItemsProperty()
    {
        return WishlistItem::query()
            ->where('user_id', auth()->id())
            ->whereIn('status', [WishlistStatus::CONSIDERING, WishlistStatus::SAVING, WishlistStatus::READY])
            ->with('linkedGoal')
            ->orderByDesc('priority')
            ->get();
    }

    public function getHistoryItemsProperty()
    {
        return WishlistItem::query()
            ->where('user_id', auth()->id())
            ->whereIn('status', [WishlistStatus::PURCHASED, WishlistStatus::CANCELLED])
            ->latest('updated_at')
            ->limit(10)
            ->get();
    }

    public function getGoalsProperty()
    {
        return Goal::query()->where('user_id', auth()->id())->where('status', GoalStatus::ACTIVE)->get();
    }

    public function affordability(WishlistItem $item, WishlistAffordabilityService $service): ?array
    {
        return $service->calculate($item);
    }

    public function create(WishlistService $wishlist): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'estimatedPrice' => ['required', 'string'],
            'priority' => ['required', 'string', 'in:low,medium,high'],
            'category' => ['nullable', 'string', 'max:255'],
            'targetPurchaseDate' => ['nullable', 'date'],
            'linkedGoalId' => ['nullable', 'integer'],
        ]);

        $goal = $this->linkedGoalId !== '' ? $this->goals->firstWhere('id', (int) $this->linkedGoalId) : null;

        $wishlist->createItem(
            user: auth()->user(),
            name: $this->name,
            estimatedPriceMinor: Money::toMinorUnits($this->estimatedPrice),
            priority: Priority::from($this->priority),
            category: $this->category ?: null,
            targetPurchaseDate: $this->targetPurchaseDate !== '' ? \Carbon\Carbon::parse($this->targetPurchaseDate) : null,
            linkedGoal: $goal,
        );

        $this->reset(['name', 'estimatedPrice', 'category', 'targetPurchaseDate', 'linkedGoalId']);
        $this->priority = 'medium';
        $this->showForm = false;
    }

    public function markPurchased(int $itemId, WishlistService $wishlist): void
    {
        $item = WishlistItem::where('user_id', auth()->id())->findOrFail($itemId);
        $wishlist->markPurchased($item);
        session()->flash('status', 'Marked as purchased.');
    }

    public function cancelItem(int $itemId, WishlistService $wishlist): void
    {
        $item = WishlistItem::where('user_id', auth()->id())->findOrFail($itemId);
        $wishlist->setStatus($item, WishlistStatus::CANCELLED);
    }
};
?>

<div>
    <x-ui.page-header title="Wishlist" subtitle="Track progress, stay disciplined, and turn plans into reality.">
        <x-slot:actions>
            <x-ui.button wire:click="$set('showForm', true)" variant="primary">
                <x-icon name="plus" class="h-4 w-4" /> Add to wishlist
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <div class="mt-4 flex items-center gap-2 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700">
            <x-icon name="check-circle" class="h-4 w-4" /> {{ session('status') }}
        </div>
    @endif

    @if ($showForm)
        <form wire:submit="create" class="mt-6 space-y-4 rounded-2xl border border-slate-200 bg-white p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-slate-700">Item</label>
                    <input wire:model="name" type="text" placeholder="e.g. MacBook Air M2" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Estimated price</label>
                    <div class="relative mt-1">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm font-medium text-slate-400">KSh</span>
                        <input wire:model="estimatedPrice" type="text" inputmode="decimal" placeholder="160,000.00" class="block w-full rounded-lg border-slate-300 pl-11 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    </div>
                    @error('estimatedPrice') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Priority</label>
                    <select wire:model="priority" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <option value="low">Low</option>
                        <option value="medium">Medium</option>
                        <option value="high">High</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Category <span class="text-slate-400">(optional)</span></label>
                    <input wire:model="category" type="text" placeholder="e.g. Electronics" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Target purchase date <span class="text-slate-400">(optional)</span></label>
                    <input wire:model="targetPurchaseDate" type="date" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-slate-700">Link to a savings goal <span class="text-slate-400">(optional)</span></label>
                    <select wire:model="linkedGoalId" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <option value="">None</option>
                        @foreach ($this->goals as $goal)
                            <option value="{{ $goal->id }}">{{ $goal->title }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-400">Linking a goal enables the affordability estimate below.</p>
                </div>
            </div>
            <div class="flex gap-3">
                <x-ui.button type="submit" variant="primary">Save</x-ui.button>
                <x-ui.button type="button" wire:click="$set('showForm', false)" variant="secondary">Cancel</x-ui.button>
            </div>
        </form>
    @endif

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
        @forelse ($this->activeItems as $item)
            @php $scenarios = $this->affordability($item, app(WishlistAffordabilityService::class)); @endphp
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-pink-50 text-pink-500">
                            <x-icon name="heart" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="break-words font-medium text-slate-900">{{ $item->name }}</p>
                            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                <x-ui.badge :color="['low' => 'slate', 'medium' => 'amber', 'high' => 'red'][$item->priority->value]">{{ ucfirst($item->priority->value) }}</x-ui.badge>
                                @if ($item->category)
                                    <x-ui.badge color="slate">{{ $item->category }}</x-ui.badge>
                                @endif
                            </div>
                            <p class="mt-1.5 text-lg font-semibold text-slate-900">{{ Money::formatMinor($item->estimated_price_minor) }}</p>
                            @if ($item->linkedGoal)
                                <p class="break-words text-xs text-slate-400">
                                    {{ Money::formatMinor($item->amountAllocatedMinor()) }} set aside via &ldquo;{{ $item->linkedGoal->title }}&rdquo;
                                    &middot; {{ Money::formatMinor($item->remainingAmountMinor()) }} remaining
                                </p>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="mt-3 flex gap-2">
                    <x-ui.button wire:click="markPurchased({{ $item->id }})" variant="primary" size="sm" class="flex-1">
                        <x-icon name="check" class="h-3.5 w-3.5" /> Mark purchased
                    </x-ui.button>
                    <x-ui.button wire:click="cancelItem({{ $item->id }})" wire:confirm="Remove this from your wishlist?" variant="secondary" size="sm">
                        Cancel
                    </x-ui.button>
                </div>

                @if ($scenarios)
                    <div class="mt-4 grid grid-cols-3 gap-2 border-t border-slate-100 pt-4">
                        @foreach (['conservative' => 'Conservative', 'current_trend' => 'Current Trend', 'aggressive' => 'Aggressive'] as $key => $label)
                            <div class="rounded-lg bg-slate-50 p-2.5 text-center">
                                <p class="text-xs font-medium text-slate-500">{{ $label }}</p>
                                <p class="mt-1 text-sm font-semibold text-slate-900">
                                    @if ($scenarios[$key]['months'] === 0)
                                        Now
                                    @elseif ($scenarios[$key]['months'] === null)
                                        &mdash;
                                    @else
                                        {{ $scenarios[$key]['months'] }}mo
                                    @endif
                                </p>
                                <p class="text-xs text-slate-400">{{ Money::formatMinor($scenarios[$key]['monthly_amount_minor']) }}/mo</p>
                            </div>
                        @endforeach
                    </div>
                @elseif ($item->linkedGoal)
                    <p class="mt-3 border-t border-slate-100 pt-3 text-xs text-slate-400">
                        Set a planned monthly contribution on &ldquo;{{ $item->linkedGoal->title }}&rdquo; to see an affordability estimate.
                    </p>
                @endif
            </div>
        @empty
            <div class="sm:col-span-2">
                <x-ui.empty-state icon="heart" title="Nothing on your wishlist yet" description="Add something you're saving toward." />
            </div>
        @endforelse
    </div>

    @if ($this->historyItems->isNotEmpty())
        <x-ui.section title="History" class="mt-8">
            <div class="divide-y divide-slate-100">
                @foreach ($this->historyItems as $item)
                    <div class="flex items-center justify-between px-5 py-3">
                        <p class="text-sm text-slate-900">{{ $item->name }}</p>
                        <x-ui.badge :color="$item->status->value === 'purchased' ? 'green' : 'slate'">{{ ucfirst($item->status->value) }}</x-ui.badge>
                    </div>
                @endforeach
            </div>
        </x-ui.section>
    @endif
</div>
