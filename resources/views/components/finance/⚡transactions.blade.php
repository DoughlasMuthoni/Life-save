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
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Transactions</h1>
            <p class="mt-1 text-sm text-slate-500">Everything posted to your ledger, most recent first.</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('finance.income.create') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">+ Income</a>
            <a href="{{ route('finance.expenses.create') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">+ Expense</a>
            <a href="{{ route('finance.transfers.create') }}" class="rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700">+ Transfer</a>
        </div>
    </div>

    @if (session('status'))
        <div class="mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    <div class="mt-6 divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
        @forelse ($this->journals as $journal)
            <div class="px-6 py-4">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium text-slate-900">{{ ucfirst($journal->journal_type->value) }}</span>
                            @if ($journal->is_reversed)
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">Reversed</span>
                            @endif
                            @if ($journal->journal_type->value === 'reversal')
                                <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">Reversal</span>
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
                        <input wire:model="reversalReason" type="text" placeholder="e.g. Entered twice by mistake" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                        @error('reversalReason') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        <div class="mt-3 flex gap-3">
                            <button type="submit" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700">Confirm reversal</button>
                            <button type="button" wire:click="cancelReversal" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
                        </div>
                    </form>
                @endif
            </div>
        @empty
            <p class="px-6 py-10 text-center text-sm text-slate-500">No transactions yet.</p>
        @endforelse
    </div>

    <div class="mt-4">
        {{ $this->journals->links() }}
    </div>
</div>
