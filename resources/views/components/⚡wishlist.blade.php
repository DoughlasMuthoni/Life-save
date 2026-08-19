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
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Wishlist</h1>
            <p class="mt-1 text-sm text-slate-500">Track progress, stay disciplined, and turn plans into reality.</p>
        </div>
        <button wire:click="$set('showForm', true)" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
            + Add to wishlist
        </button>
    </div>

    @if (session('status'))
        <div class="mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    @if ($showForm)
        <form wire:submit="create" class="mt-6 space-y-4 rounded-xl border border-slate-200 bg-white p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-slate-700">Item</label>
                    <input wire:model="name" type="text" placeholder="e.g. MacBook Air M2" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Estimated price (KES)</label>
                    <input wire:model="estimatedPrice" type="text" inputmode="decimal" placeholder="160000.00" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                    @error('estimatedPrice') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Priority</label>
                    <select wire:model="priority" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                        <option value="low">Low</option>
                        <option value="medium">Medium</option>
                        <option value="high">High</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Category <span class="text-slate-400">(optional)</span></label>
                    <input wire:model="category" type="text" placeholder="e.g. Electronics" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Target purchase date <span class="text-slate-400">(optional)</span></label>
                    <input wire:model="targetPurchaseDate" type="date" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-slate-700">Link to a savings goal <span class="text-slate-400">(optional)</span></label>
                    <select wire:model="linkedGoalId" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                        <option value="">None</option>
                        @foreach ($this->goals as $goal)
                            <option value="{{ $goal->id }}">{{ $goal->title }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-400">Linking a goal enables the affordability estimate below.</p>
                </div>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Save</button>
                <button type="button" wire:click="$set('showForm', false)" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
            </div>
        </form>
    @endif

    <div class="mt-6 space-y-4">
        @forelse ($this->activeItems as $item)
            @php $scenarios = $this->affordability($item, app(WishlistAffordabilityService::class)); @endphp
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <p class="font-medium text-slate-900">{{ $item->name }}</p>
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">{{ ucfirst($item->priority->value) }}</span>
                            @if ($item->category)
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">{{ $item->category }}</span>
                            @endif
                        </div>
                        <p class="mt-1 text-sm text-slate-600">{{ Money::formatMinor($item->estimated_price_minor) }}</p>
                        @if ($item->linkedGoal)
                            <p class="text-xs text-slate-400">
                                {{ Money::formatMinor($item->amountAllocatedMinor()) }} set aside via &ldquo;{{ $item->linkedGoal->title }}&rdquo;
                                &middot; {{ Money::formatMinor($item->remainingAmountMinor()) }} remaining
                            </p>
                        @endif
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <button wire:click="markPurchased({{ $item->id }})" class="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700">Mark purchased</button>
                        <button wire:click="cancelItem({{ $item->id }})" wire:confirm="Remove this from your wishlist?" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
                    </div>
                </div>

                @if ($scenarios)
                    <div class="mt-4 grid grid-cols-3 gap-3 border-t border-slate-100 pt-4">
                        @foreach (['conservative' => 'Conservative', 'current_trend' => 'Current Trend', 'aggressive' => 'Aggressive'] as $key => $label)
                            <div class="rounded-lg bg-slate-50 p-3 text-center">
                                <p class="text-xs font-medium text-slate-500">{{ $label }}</p>
                                <p class="mt-1 text-sm font-semibold text-slate-900">
                                    @if ($scenarios[$key]['months'] === 0)
                                        Affordable now
                                    @elseif ($scenarios[$key]['months'] === null)
                                        &mdash;
                                    @else
                                        {{ $scenarios[$key]['months'] }} {{ Str::plural('month', $scenarios[$key]['months']) }}
                                    @endif
                                </p>
                                <p class="text-xs text-slate-400">at {{ Money::formatMinor($scenarios[$key]['monthly_amount_minor']) }}/mo</p>
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
            <p class="rounded-xl border border-dashed border-slate-300 bg-white px-6 py-8 text-center text-sm text-slate-500">
                Nothing on your wishlist yet.
            </p>
        @endforelse
    </div>

    @if ($this->historyItems->isNotEmpty())
        <div class="mt-8">
            <h2 class="text-sm font-semibold text-slate-700">History</h2>
            <div class="mt-3 divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
                @foreach ($this->historyItems as $item)
                    <div class="flex items-center justify-between px-6 py-3">
                        <p class="text-sm text-slate-900">{{ $item->name }}</p>
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">{{ ucfirst($item->status->value) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
