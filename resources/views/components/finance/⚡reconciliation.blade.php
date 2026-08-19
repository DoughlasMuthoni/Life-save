<?php

use App\Domain\Finance\Enums\ReconciliationStatus;
use App\Domain\Finance\Models\BalanceObservation;
use App\Domain\Finance\Services\ReconciliationService;
use App\Domain\Finance\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.authenticated')] class extends Component
{
    public ?int $resolvingId = null;

    public string $resolutionNote = '';

    public function getMismatchedProperty()
    {
        return $this->observationsWithStatus(ReconciliationStatus::MISMATCHED);
    }

    public function getResolvedProperty()
    {
        return $this->observationsWithStatus(ReconciliationStatus::RESOLVED, 10);
    }

    private function observationsWithStatus(ReconciliationStatus $status, int $limit = 50)
    {
        return BalanceObservation::query()
            ->where('user_id', auth()->id())
            ->where('reconciliation_status', $status)
            ->with('financialAccount')
            ->latest('observed_at')
            ->limit($limit)
            ->get();
    }

    public function startResolving(int $observationId): void
    {
        $this->resolvingId = $observationId;
        $this->resolutionNote = '';
    }

    public function cancelResolving(): void
    {
        $this->resolvingId = null;
    }

    public function confirmResolve(ReconciliationService $reconciliation): void
    {
        $this->validate(['resolutionNote' => ['required', 'string', 'min:3', 'max:500']]);

        $observation = BalanceObservation::where('user_id', auth()->id())->findOrFail($this->resolvingId);

        $reconciliation->resolve(auth()->user(), $observation, $this->resolutionNote);

        $this->resolvingId = null;
        session()->flash('status', 'Marked as resolved.');
    }
};
?>

<div>
    <h1 class="text-xl font-semibold text-slate-900">Reconciliation</h1>
    <p class="mt-1 text-sm text-slate-500">
        When a confirmed SMS reports a balance that doesn't match what the ledger calculates, it shows up here.
        This never changes an account's actual balance &mdash; it only flags the difference for you to look into.
    </p>

    @if (session('status'))
        <div class="mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    <div class="mt-6">
        <h2 class="text-sm font-semibold text-amber-700">Needs attention ({{ $this->mismatched->count() }})</h2>
        <div class="mt-3 space-y-4">
            @forelse ($this->mismatched as $observation)
                <div class="rounded-xl border border-amber-300 bg-amber-50/40 p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-slate-900">{{ $observation->financialAccount->name }}</p>
                            <p class="text-xs text-slate-500">{{ $observation->observed_at->format('M j, Y \a\t g:i A') }}</p>
                            <div class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
                                <span class="text-slate-500">SMS said</span>
                                <span class="font-medium text-slate-900">{{ Money::formatMinor($observation->observed_balance_minor) }}</span>
                                <span class="text-slate-500">Ledger calculates</span>
                                <span class="font-medium text-slate-900">{{ Money::formatMinor($observation->calculated_balance_minor) }}</span>
                                <span class="text-slate-500">Difference</span>
                                <span class="font-medium text-red-600">{{ Money::formatMinor($observation->difference_minor) }}</span>
                            </div>
                        </div>
                        <button wire:click="startResolving({{ $observation->id }})" class="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                            Mark resolved
                        </button>
                    </div>

                    @if ($resolvingId === $observation->id)
                        <form wire:submit="confirmResolve" class="mt-3 rounded-lg bg-white p-4">
                            <label class="block text-sm font-medium text-slate-700">What did you find?</label>
                            <input wire:model="resolutionNote" type="text" placeholder="e.g. Found a missed cash withdrawal, added it manually" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                            @error('resolutionNote') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            <div class="mt-3 flex gap-3">
                                <button type="submit" class="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700">Save</button>
                                <button type="button" wire:click="cancelResolving" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
                            </div>
                        </form>
                    @endif
                </div>
            @empty
                <p class="rounded-xl border border-dashed border-slate-300 bg-white px-6 py-8 text-center text-sm text-slate-500">
                    Nothing to reconcile right now.
                </p>
            @endforelse
        </div>
    </div>

    @if ($this->resolved->isNotEmpty())
        <div class="mt-8">
            <h2 class="text-sm font-semibold text-slate-700">Recently resolved</h2>
            <div class="mt-3 divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
                @foreach ($this->resolved as $observation)
                    <div class="px-6 py-3">
                        <p class="text-sm text-slate-900">{{ $observation->financialAccount->name }} &mdash; {{ Money::formatMinor($observation->difference_minor) }} difference</p>
                        <p class="text-xs text-slate-500">{{ $observation->resolution_note }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
