<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a deterministic parser (or, later, AI) extracted from a
     * financial_message, awaiting human confirmation. Mutable while in
     * PENDING_REVIEW/DUPLICATE (the user can edit account/category/amount
     * before confirming — CLAUDE.md §7); frozen once CONFIRMED or REJECTED,
     * enforced at the model layer.
     */
    public function up(): void
    {
        Schema::create('proposed_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_message_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('transaction_type'); // App\Domain\Ingestion\Enums\ExtractedTransactionType

            // Source account (e.g. the M-Pesa wallet). Destination is only
            // meaningful for transfer-shaped types (e.g. a cash withdrawal);
            // category is only meaningful for income/expense-shaped types.
            $table->foreignId('financial_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('destination_financial_account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();
            $table->foreignId('transaction_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fee_category_id')->nullable()->constrained('transaction_categories')->nullOnDelete();

            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->char('currency', 3)->default('KES');

            $table->string('counterparty')->nullable();
            $table->dateTime('transaction_time');
            $table->unsignedBigInteger('reported_balance_minor')->nullable();
            $table->text('description')->nullable();

            $table->string('status'); // App\Domain\Ingestion\Enums\ProposedTransactionStatus
            $table->foreignId('duplicate_of_message_id')->nullable()->constrained('financial_messages')->nullOnDelete();
            $table->foreignId('journal_id')->nullable()->constrained()->restrictOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposed_transactions');
    }
};
