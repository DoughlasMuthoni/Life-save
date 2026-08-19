<?php

use App\Domain\Finance\Enums\TransactionCategoryType;
use App\Domain\Finance\Models\FinancialAccount;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Finance\Support\Money;
use App\Domain\Ingestion\Enums\ParseStatus;
use App\Domain\Ingestion\Enums\ProposedTransactionStatus;
use App\Domain\Ingestion\Enums\TransactionShape;
use App\Domain\Ingestion\Models\FinancialMessage;
use App\Domain\Ingestion\Models\ProposedTransaction;
use App\Domain\Ingestion\Services\FinancialMessageIngestionService;
use App\Domain\Ingestion\Services\ProposedTransactionConfirmationService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.authenticated')] class extends Component
{
    public string $pasteText = '';

    /** @var array<int, array<string, string>> */
    public array $formData = [];

    public function mount(): void
    {
        $this->seedFormData($this->pendingProposals->concat($this->duplicateProposals));
    }

    public function getAccountsProperty()
    {
        return FinancialAccount::query()->where('user_id', auth()->id())->where('is_active', true)->get();
    }

    public function getIncomeCategoriesProperty()
    {
        return TransactionCategory::query()->where('user_id', auth()->id())->where('type', TransactionCategoryType::INCOME)->orderBy('name')->get();
    }

    public function getExpenseCategoriesProperty()
    {
        return TransactionCategory::query()->where('user_id', auth()->id())->where('type', TransactionCategoryType::EXPENSE)->orderBy('name')->get();
    }

    public function getPendingProposalsProperty()
    {
        return $this->proposalsWithStatus(ProposedTransactionStatus::PENDING_REVIEW);
    }

    public function getDuplicateProposalsProperty()
    {
        return $this->proposalsWithStatus(ProposedTransactionStatus::DUPLICATE);
    }

    public function getNeedsReviewMessagesProperty()
    {
        return FinancialMessage::query()
            ->where('user_id', auth()->id())
            ->where('parse_status', ParseStatus::NEEDS_REVIEW)
            ->latest('created_at')
            ->limit(50)
            ->get();
    }

    private function proposalsWithStatus(ProposedTransactionStatus $status)
    {
        return ProposedTransaction::query()
            ->where('user_id', auth()->id())
            ->where('status', $status)
            ->with(['financialMessage', 'duplicateOfMessage'])
            ->latest('transaction_time')
            ->limit(50)
            ->get();
    }

    private function seedFormData(Collection $proposals): void
    {
        foreach ($proposals as $proposal) {
            if (isset($this->formData[$proposal->id])) {
                continue;
            }

            $this->formData[$proposal->id] = [
                'financial_account_id' => (string) ($proposal->financial_account_id ?? ''),
                'destination_financial_account_id' => (string) ($proposal->destination_financial_account_id ?? ''),
                'transaction_category_id' => (string) ($proposal->transaction_category_id ?? ''),
                'fee_category_id' => (string) ($proposal->fee_category_id ?? ''),
            ];
        }
    }

    public function parseMessages(FinancialMessageIngestionService $ingestion): void
    {
        $this->validate(['pasteText' => ['required', 'string', 'min:5']]);

        $messages = $ingestion->ingestBatch(auth()->user(), $this->pasteText);

        $this->seedFormData(
            $messages->map(fn ($m) => $m->proposedTransaction)->filter()
        );

        $parsed = $messages->filter(fn ($m) => $m->parse_status === ParseStatus::PARSED)->count();
        $needsReview = $messages->count() - $parsed;

        $this->pasteText = '';
        session()->flash('status', "{$messages->count()} message(s) processed: {$parsed} parsed, {$needsReview} need review.");
    }

    public function confirmProposal(int $proposalId, ProposedTransactionConfirmationService $confirmation): void
    {
        $proposal = ProposedTransaction::where('user_id', auth()->id())->findOrFail($proposalId);
        $shape = $proposal->transaction_type->shape();
        $row = $this->formData[$proposalId] ?? [];

        $overrides = ['financial_account_id' => $this->intOrNull($row['financial_account_id'] ?? null)];

        if ($shape === TransactionShape::TRANSFER) {
            $overrides['destination_financial_account_id'] = $this->intOrNull($row['destination_financial_account_id'] ?? null);
        } else {
            $overrides['transaction_category_id'] = $this->intOrNull($row['transaction_category_id'] ?? null);
        }

        if ($proposal->fee_minor > 0) {
            $overrides['fee_category_id'] = $this->intOrNull($row['fee_category_id'] ?? null);
        }

        try {
            $confirmation->confirm(auth()->user(), $proposal, $overrides);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(["formData.{$proposalId}.financial_account_id" => $e->getMessage()]);
        }

        unset($this->formData[$proposalId]);
        session()->flash('status', 'Transaction confirmed and posted to your ledger.');
    }

    public function rejectProposal(int $proposalId, ProposedTransactionConfirmationService $confirmation): void
    {
        $proposal = ProposedTransaction::where('user_id', auth()->id())->findOrFail($proposalId);

        $confirmation->reject(auth()->user(), $proposal);

        unset($this->formData[$proposalId]);
        session()->flash('status', 'Proposed transaction rejected.');
    }

    private function intOrNull(?string $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
};
?>

<div>
    <h1 class="text-xl font-semibold text-slate-900">Paste Financial Messages</h1>
    <p class="mt-1 text-sm text-slate-500">
        Paste M-Pesa SMS text directly below &mdash; no documents, no screenshots. Separate multiple messages with a blank line.
    </p>

    @if (session('status'))
        <div class="mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    <form wire:submit="parseMessages" class="mt-6 rounded-xl border border-slate-200 bg-white p-4">
        <textarea
            wire:model="pasteText"
            rows="6"
            placeholder="QGH7XI9K2L Confirmed. Ksh1,500.00 sent to JOHN MWANGI 0712345678 on 31/5/25 at 1:41 PM. New M-PESA balance is Ksh3,450.00."
            class="block w-full rounded-lg border-slate-300 font-mono text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
        ></textarea>
        @error('pasteText') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        <div class="mt-3 flex justify-between">
            <p class="text-xs text-slate-400">Only M-Pesa messages are parsed automatically in this version.</p>
            <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700" wire:loading.attr="disabled">
                Parse Messages
            </button>
        </div>
    </form>

    {{-- Ready to review --}}
    <div class="mt-8">
        <h2 class="text-sm font-semibold text-slate-700">Ready to review ({{ $this->pendingProposals->count() }})</h2>
        <div class="mt-3 space-y-4">
            @forelse ($this->pendingProposals as $proposal)
                @include('components.finance.messages.partials.proposal-card', ['proposal' => $proposal, 'duplicate' => false, 'accounts' => $this->accounts, 'incomeCategories' => $this->incomeCategories, 'expenseCategories' => $this->expenseCategories])
            @empty
                <p class="rounded-xl border border-dashed border-slate-300 bg-white px-6 py-8 text-center text-sm text-slate-500">
                    Nothing waiting for review.
                </p>
            @endforelse
        </div>
    </div>

    {{-- Possible duplicates --}}
    @if ($this->duplicateProposals->isNotEmpty())
        <div class="mt-8">
            <h2 class="text-sm font-semibold text-amber-700">Possible duplicates ({{ $this->duplicateProposals->count() }})</h2>
            <div class="mt-3 space-y-4">
                @foreach ($this->duplicateProposals as $proposal)
                    @include('components.finance.messages.partials.proposal-card', ['proposal' => $proposal, 'duplicate' => true, 'accounts' => $this->accounts, 'incomeCategories' => $this->incomeCategories, 'expenseCategories' => $this->expenseCategories])
                @endforeach
            </div>
        </div>
    @endif

    {{-- Needs review --}}
    @if ($this->needsReviewMessages->isNotEmpty())
        <div class="mt-8">
            <h2 class="text-sm font-semibold text-slate-700">Unknown / Needs review ({{ $this->needsReviewMessages->count() }})</h2>
            <p class="mt-1 text-xs text-slate-400">
                No parser recognized these yet (not M-Pesa, or an unrecognized M-Pesa format). They are kept as evidence but no transaction was proposed.
            </p>
            <div class="mt-3 divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
                @foreach ($this->needsReviewMessages as $message)
                    <p class="px-6 py-3 font-mono text-xs text-slate-600">{{ \Illuminate\Support\Str::limit($message->raw_text, 200) }}</p>
                @endforeach
            </div>
        </div>
    @endif
</div>
