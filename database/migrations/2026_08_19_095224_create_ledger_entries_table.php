<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Individual debit/credit postings. amount_minor is always a positive
     * BIGINT in minor currency units (CLAUDE.md §10 — never FLOAT/DOUBLE);
     * `side` carries the direction. A journal's entries must sum to zero
     * per currency (debits == credits) — enforced in LedgerService, not
     * here, because MySQL can't express that invariant declaratively.
     *
     * No `updated_at`: once written, a ledger entry is never modified.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();

            // Optional: which user-facing category this leg belongs to.
            // Only meaningful for entries posting to an INCOME/EXPENSE
            // ledger account.
            $table->foreignId('transaction_category_id')->nullable()->constrained()->nullOnDelete();

            $table->string('side'); // App\Domain\Finance\Enums\LedgerEntrySide
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('KES');

            $table->timestamp('created_at')->useCurrent();

            $table->index(['ledger_account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
