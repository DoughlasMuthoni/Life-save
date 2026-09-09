<?php

use App\Domain\Ingestion\Enums\ParseStatus;
use App\Domain\Ingestion\Enums\ProposedTransactionStatus;
use App\Domain\Ingestion\Services\FinancialMessageIngestionService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.authenticated')] class extends Component
{
    public string $statementText = '';

    /** @var array{total: int, parsed: int, duplicates: int, needsReview: int}|null */
    public ?array $summary = null;

    /**
     * Rate limited per user (CLAUDE.md §12) — one import can mean
     * hundreds of rows and hundreds of DB writes in a single request;
     * nothing should let that be re-triggered repeatedly in a short
     * window.
     */
    public function import(FinancialMessageIngestionService $ingestion): void
    {
        $this->validate(['statementText' => ['required', 'string', 'min:20']]);

        $throttleKey = 'statement-import:'.auth()->id();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'statementText' => "Too many imports in a short time. Please try again in {$seconds} seconds.",
            ]);
        }

        RateLimiter::hit($throttleKey, 300);

        $messages = $ingestion->ingestStatementBatch(auth()->user(), $this->statementText);

        if ($messages->isEmpty()) {
            throw ValidationException::withMessages([
                'statementText' => 'No statement rows were recognized in that paste. Make sure you copied the "Detailed Statement" table — the columns need Receipt No. and Completion Time to be recognized as statement rows at all.',
            ]);
        }

        $parsed = $messages->filter(fn ($m) => $m->parse_status === ParseStatus::PARSED)->count();
        $duplicates = $messages->filter(fn ($m) => $m->proposedTransaction?->status === ProposedTransactionStatus::DUPLICATE)->count();

        $this->summary = [
            'total' => $messages->count(),
            'parsed' => $parsed,
            'duplicates' => $duplicates,
            'needsReview' => $messages->count() - $parsed,
        ];

        $this->statementText = '';
    }
};
?>

<div>
    <x-ui.page-header
        title="Import Statement"
        subtitle="Paste the text of an M-Pesa statement's Detailed Statement table — never upload the PDF itself."
    />

    <div class="mt-4 flex items-start gap-2 rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-800">
        <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0" />
        <span>
            Open the statement in your own PDF viewer, select and copy the "Detailed Statement" table, and paste it
            below. This first pass recognizes the common transaction shapes (transfers, payments, pay bill, bundle
            purchases, withdrawals, M-Shwari moves, and their fee rows) — anything it doesn't recognize (reversals,
            Fuliza-flavored rows, a few rarer shapes) is stored as evidence under "Needs review" on the
            <a href="{{ route('finance.messages') }}" class="font-medium underline">Messages</a> page rather than
            silently skipped.
        </span>
    </div>

    @if ($summary)
        <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <x-ui.stat-card icon="list" label="Rows found" color="blue">{{ $summary['total'] }}</x-ui.stat-card>
            <x-ui.stat-card icon="check-circle" label="Parsed" color="green">{{ $summary['parsed'] }}</x-ui.stat-card>
            <x-ui.stat-card icon="warning" label="Needs review" color="amber">{{ $summary['needsReview'] }}</x-ui.stat-card>
            <x-ui.stat-card icon="scale" label="Possible duplicates" color="slate">{{ $summary['duplicates'] }}</x-ui.stat-card>
        </div>
        <div class="mt-4">
            <x-ui.button :href="route('finance.messages')" variant="primary">
                Review on the Messages page <x-icon name="arrow-right" class="h-4 w-4" />
            </x-ui.button>
        </div>
    @endif

    <form wire:submit="import" class="mt-6 rounded-2xl border border-slate-200 bg-white p-5">
        <label for="statementText" class="block text-sm font-medium text-slate-700">Statement text</label>
        <textarea
            wire:model="statementText"
            id="statementText"
            rows="10"
            placeholder="Receipt No.        Completion Time                  Details               Transaction Status     Paid In           Withdrawn              Balance&#10;UI7JK5FYNQ           2026-09-07 22:47:17   Customer Bundle Purchase to       Completed                                         -20.00                  527.61&#10;..."
            class="mt-2 block w-full resize-y rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 font-mono text-sm leading-relaxed text-slate-800 placeholder:text-slate-400 shadow-sm transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-500/20 focus:outline-none"
        ></textarea>
        @error('statementText') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
        <div class="mt-3 flex items-center justify-between">
            <p class="flex items-center gap-1.5 text-xs text-slate-400">
                <x-icon name="lock" class="h-3.5 w-3.5" /> Nothing posts to your ledger until you confirm each row on the Messages page.
            </p>
            <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled">
                <x-icon name="download" class="h-4 w-4" /> Import
            </x-ui.button>
        </div>
    </form>
</div>
