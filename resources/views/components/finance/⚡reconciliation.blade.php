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
    <x-ui.page-header
        title="Reconciliation"
        subtitle="When a confirmed SMS reports a balance that doesn't match the ledger, it shows up here — this never changes an account's actual balance, it only flags the difference."
    />

    @if (session('status'))
        <div class="mt-4 flex items-center gap-2 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700">
            <x-icon name="check-circle" class="h-4 w-4" /> {{ session('status') }}
        </div>
    @endif

    <div class="mt-6">
        <h2 class="flex items-center gap-1.5 text-sm font-semibold text-amber-700">
            <x-icon name="warning" class="h-4 w-4" /> Needs attention ({{ $this->mismatched->count() }})
        </h2>
        <div class="mt-3 space-y-4">
            @forelse ($this->mismatched as $observation)
                <div class="rounded-2xl border border-amber-200 bg-amber-50/50 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="break-words text-sm font-medium text-slate-900">{{ $observation->financialAccount->name }}</p>
                            <p class="text-xs text-slate-500">{{ $observation->observed_at->format('M j, Y \a\t g:i A') }}</p>
                            <div class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
                                <span class="text-slate-500">SMS said</span>
                                <span class="font-medium text-slate-900">{{ Money::formatMinor($observation->observed_balance_minor) }}</span>
                                <span class="text-slate-500">Ledger calculates</span>
                                <span class="font-medium text-slate-900">{{ Money::formatMinor($observation->calculated_balance_minor) }}</span>
                                <span class="text-slate-500">Difference</span>
                                <span class="font-semibold text-red-600">{{ Money::formatMinor($observation->difference_minor) }}</span>
                            </div>
                        </div>
                        <x-ui.button wire:click="startResolving({{ $observation->id }})" variant="secondary" size="sm">
                            Mark resolved
                        </x-ui.button>
                    </div>

                    @if ($resolvingId === $observation->id)
                        <form wire:submit="confirmResolve" class="mt-3 rounded-lg bg-white p-4">
                            <label class="block text-sm font-medium text-slate-700">What did you find?</label>
                            <input wire:model="resolutionNote" type="text" placeholder="e.g. Found a missed cash withdrawal, added it manually" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                            @error('resolutionNote') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            <div class="mt-3 flex gap-3">
                                <x-ui.button type="submit" variant="primary" size="sm">Save</x-ui.button>
                                <x-ui.button type="button" wire:click="cancelResolving" variant="secondary" size="sm">Cancel</x-ui.button>
                            </div>
                        </form>
                    @endif
                </div>
            @empty
                <x-ui.empty-state icon="check-circle" title="Nothing to reconcile right now" />
            @endforelse
        </div>
    </div>

    @if ($this->resolved->isNotEmpty())
        <x-ui.section title="Recently resolved" class="mt-8">
            <div class="divide-y divide-slate-100">
                @foreach ($this->resolved as $observation)
                    <div class="flex items-start gap-3 px-5 py-3">
                        <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0 text-green-500" />
                        <div class="min-w-0 flex-1">
                            <p class="break-words text-sm text-slate-900">{{ $observation->financialAccount->name }} &mdash; {{ Money::formatMinor($observation->difference_minor) }} difference</p>
                            <p class="break-words text-xs text-slate-500">{{ $observation->resolution_note }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.section>
    @endif
</div>
