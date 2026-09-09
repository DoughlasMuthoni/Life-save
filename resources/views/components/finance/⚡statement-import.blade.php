<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Ingestion\Enums\ParseStatus;
use App\Domain\Ingestion\Enums\ProposedTransactionStatus;
use App\Domain\Ingestion\Exceptions\UnprocessablePdfException;
use App\Domain\Ingestion\Services\FinancialMessageIngestionService;
use App\Domain\Ingestion\Services\PdfStatementTextExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.authenticated')] class extends Component
{
    use WithFileUploads;

    public string $statementText = '';

    public ?UploadedFile $statementFile = null;

    /** @var array{total: int, parsed: int, duplicates: int, needsReview: int}|null */
    public ?array $summary = null;

    /**
     * Rate limited per user (CLAUDE.md §12) — one import can mean
     * hundreds of rows and hundreds of DB writes in a single request;
     * nothing should let that be re-triggered repeatedly in a short
     * window. Shared by both the paste and upload paths.
     */
    public function import(
        FinancialMessageIngestionService $ingestion,
        PdfStatementTextExtractor $pdfExtractor,
        AuditLogger $auditLogger,
    ): void {
        $this->validate([
            'statementFile' => ['nullable', 'file', 'mimes:pdf', 'max:15360'], // 15MB
            'statementText' => ['nullable', 'string', 'min:20'],
        ]);

        if (! $this->statementFile && trim($this->statementText) === '') {
            throw ValidationException::withMessages([
                'statementText' => 'Paste the statement text, or upload the statement PDF, below.',
            ]);
        }

        $throttleKey = 'statement-import:'.auth()->id();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'statementText' => "Too many imports in a short time. Please try again in {$seconds} seconds.",
            ]);
        }

        RateLimiter::hit($throttleKey, 300);

        $uploadedFilename = null;

        if ($this->statementFile) {
            $uploadedFilename = $this->statementFile->getClientOriginalName();

            try {
                // CLAUDE.md §7a: the PDF is never persisted — text is
                // extracted from Livewire's temp upload and the temp
                // file is deleted immediately in the finally block
                // below, success or failure.
                $text = $pdfExtractor->extractText($this->statementFile->getRealPath());
            } catch (UnprocessablePdfException $e) {
                $this->statementFile = null;

                throw ValidationException::withMessages(['statementFile' => $e->userMessage]);
            } finally {
                $this->statementFile?->delete();
            }
        } else {
            $text = $this->statementText;
        }

        $messages = $ingestion->ingestStatementBatch(auth()->user(), $text);

        if ($messages->isEmpty()) {
            $field = $uploadedFilename ? 'statementFile' : 'statementText';

            throw ValidationException::withMessages([
                $field => 'No statement rows were recognized. Make sure the "Detailed Statement" table (with Receipt No. and Completion Time columns) is present.',
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

        if ($uploadedFilename) {
            $auditLogger->record(AuditAction::STATEMENT_PDF_IMPORTED, data: [
                'filename' => $uploadedFilename,
                'rows_found' => $this->summary['total'],
                'rows_parsed' => $this->summary['parsed'],
            ]);
        }

        $this->statementText = '';
        $this->statementFile = null;
    }
};
?>

<div>
    <x-ui.page-header
        title="Import Statement"
        subtitle="Paste an M-Pesa statement's Detailed Statement text, or upload the statement PDF directly."
    />

    <div class="mt-4 flex items-start gap-2 rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-800">
        <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0" />
        <span>
            This first pass recognizes the common transaction shapes (transfers, payments, pay bill, bundle
            purchases, withdrawals, M-Shwari moves, and their fee rows) — anything it doesn't recognize (reversals,
            Fuliza-flavored rows, a few rarer shapes) is stored as evidence under "Needs review" on the
            <a href="{{ route('finance.messages') }}" class="font-medium underline">Messages</a> page rather than
            silently skipped. Uploaded PDFs must be unencrypted and text-based (not a scanned image) — the file
            itself is never stored, only its text is used.
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
        <label for="statementFile" class="block text-sm font-medium text-slate-700">Upload statement PDF</label>
        <input
            wire:model="statementFile"
            id="statementFile"
            type="file"
            accept="application/pdf"
            class="mt-2 block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-800 shadow-sm file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-white hover:file:bg-blue-700"
        />
        <div wire:loading wire:target="statementFile" class="mt-1.5 text-xs text-slate-400">Uploading…</div>
        @error('statementFile') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror

        <div class="my-5 flex items-center gap-3">
            <div class="h-px flex-1 bg-slate-200"></div>
            <span class="text-xs font-medium tracking-wide text-slate-400 uppercase">Or paste text instead</span>
            <div class="h-px flex-1 bg-slate-200"></div>
        </div>

        <label for="statementText" class="block text-sm font-medium text-slate-700">Statement text</label>
        <textarea
            wire:model="statementText"
            id="statementText"
            rows="8"
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
