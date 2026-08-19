<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One financial event = one Journal = two or more balanced
     * LedgerEntry postings. Journals (and their entries) are append-only:
     * nothing in application code updates a posted journal's financial
     * substance. Corrections create a new REVERSAL journal that points
     * back here via reversed_journal_id (CLAUDE.md §6).
     */
    public function up(): void
    {
        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('journal_type'); // App\Domain\Finance\Enums\JournalType
            $table->text('description')->nullable();
            $table->dateTime('occurred_at');

            // Where this journal came from. 'manual' today; later phases add
            // 'financial_message' etc. Kept as plain nullable strings (not a
            // real polymorphic relation) since source_id has no single
            // target table yet — revisit once ingestion (Phase 3) lands.
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            // Reversal bookkeeping. A journal that has been reversed is
            // never deleted or edited — only flagged, with a pointer to the
            // reversal journal that offsets it.
            $table->foreignId('reversed_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->boolean('is_reversed')->default(false);

            $table->timestamp('created_at')->useCurrent();
            // No updated_at: journals are immutable once posted. The one
            // sanctioned mutation (is_reversed / reversed_journal_id, set by
            // ReversalService) is metadata about status, not a rewrite of
            // financial substance, and is applied via a scoped update, never
            // a general-purpose Journal::update().

            $table->index(['user_id', 'occurred_at']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journals');
    }
};
