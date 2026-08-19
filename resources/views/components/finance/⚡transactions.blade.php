<?php

use App\Domain\Finance\Models\Journal;
use App\Domain\Finance\Services\ReversalService;
use App\Domain\Finance\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.authenticated')] class extends Component
{
    use WithPagination;

    public ?int $reversingJournalId = null;

    public string $reversalReason = '';

    public function getJournalsProperty()
    {
        return Journal::query()
            ->where('user_id', auth()->id())
            ->with(['entries.ledgerAccount', 'entries.transactionCategory'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(20);
    }

    public function startReversal(int $journalId): void
    {
        $this->reversingJournalId = $journalId;
        $this->reversalReason = '';
    }

    public function cancelReversal(): void
    {
        $this->reversingJournalId = null;
    }

    public function confirmReversal(ReversalService $reversals): void
    {
        $this->validate([
            'reversalReason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $journal = Journal::where('user_id', auth()->id())->findOrFail($this->reversingJournalId);

        $reversals->reverseJournal(auth()->user(), $journal, $this->reversalReason);

        $this->reversingJournalId = null;
        session()->flash('status', 'Transaction reversed.');
    }
};
?>

<div>
    <x-ui.page-header title="Transactions" subtitle="Everything posted to your ledger, most recent first.">
        <x-slot:actions>
            <x-ui.button :href="route('finance.income.create')" variant="secondary" size="sm">
                <x-icon name="trend-up" class="h-3.5 w-3.5 text-green-600" /> Income
            </x-ui.button>
            <x-ui.button :href="route('finance.expenses.create')" variant="secondary" size="sm">
                <x-icon name="trend-down" class="h-3.5 w-3.5 text-red-600" /> Expense
            </x-ui.button>
            <x-ui.button :href="route('finance.transfers.create')" variant="primary" size="sm">
                <x-icon name="arrow-path" class="h-3.5 w-3.5" /> Transfer
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <div class="mt-4 flex items-center gap-2 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700">
            <x-icon name="check-circle" class="h-4 w-4" /> {{ session('status') }}
        </div>
    @endif

    @if ($this->journals->isEmpty())
        <x-ui.empty-state icon="list" title="No transactions yet" description="Record your first income, expense, or transfer to see it here." class="mt-6" />
    @else
        <div class="mt-6 divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
            @foreach ($this->journals as $journal)
                @php
                    $typeMeta = match ($journal->journal_type->value) {
                        'income' => ['icon' => 'trend-up', 'color' => 'green'],
                        'expense' => ['icon' => 'trend-down', 'color' => 'red'],
                        'transfer' => ['icon' => 'arrow-path', 'color' => 'blue'],
                        'reversal' => ['icon' => 'arrow-path', 'color' => 'amber'],
                        default => ['icon' => 'list', 'color' => 'slate'],
                    };
                    $iconBg = ['green' => 'bg-green-50 text-green-600', 'red' => 'bg-red-50 text-red-600', 'blue' => 'bg-blue-50 text-blue-600', 'amber' => 'bg-amber-50 text-amber-600', 'slate' => 'bg-slate-100 text-slate-500'][$typeMeta['color']];
                @endphp
                <div class="px-5 py-4">
                    <div class="flex items-start gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full {{ $iconBg }}">
                            <x-icon :name="$typeMeta['icon']" class="h-4.5 w-4.5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-medium text-slate-900">{{ ucfirst($journal->journal_type->value) }}</span>
                                @if ($journal->is_reversed)
                                    <x-ui.badge color="slate">Reversed</x-ui.badge>
                                @endif
                                @if ($journal->journal_type->value === 'reversal')
                                    <x-ui.badge color="amber">Reversal</x-ui.badge>
                                @endif
                            </div>
                            <p class="text-xs text-slate-500">{{ $journal->occurred_at->format('M j, Y \a\t g:i A') }}</p>
                            @if ($journal->description)
                                <p class="mt-1 text-sm text-slate-600">{{ $journal->description }}</p>
                            @endif
                            <div class="mt-2 space-y-0.5">
                                @foreach ($journal->entries as $entry)
                                    <p class="text-xs text-slate-500">
                                        <span class="font-medium {{ $entry->side->value === 'debit' ? 'text-slate-700' : 'text-slate-500' }}">
                                            {{ strtoupper($entry->side->value) }}
                                        </span>
                                        {{ $entry->ledgerAccount->name }}
                                        <span class="text-slate-400">&mdash;</span>
                                        {{ Money::formatMinor($entry->amount_minor, $entry->currency) }}
                                    </p>
                                @endforeach
                            </div>
                        </div>

                        @if (! $journal->is_reversed)
                            <button
                                wire:click="startReversal({{ $journal->id }})"
                                class="shrink-0 text-xs font-medium text-red-600 hover:text-red-700"
                            >
                                Reverse
                            </button>
                        @endif
                    </div>

                    @if ($reversingJournalId === $journal->id)
                        <form wire:submit="confirmReversal" class="mt-3 rounded-lg bg-red-50 p-4">
                            <label class="block text-sm font-medium text-slate-700">Why are you reversing this?</label>
                            <input wire:model="reversalReason" type="text" placeholder="e.g. Entered twice by mistake" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-red-500 focus:ring-red-500 sm:text-sm">
                            @error('reversalReason') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            <div class="mt-3 flex gap-3">
                                <x-ui.button type="submit" variant="danger-solid" size="sm">Confirm reversal</x-ui.button>
                                <x-ui.button type="button" wire:click="cancelReversal" variant="secondary" size="sm">Cancel</x-ui.button>
                            </div>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-4">
            {{ $this->journals->links() }}
        </div>
    @endif
</div>
