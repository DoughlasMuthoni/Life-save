<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A balance an SMS *claimed* an account had, alongside what the ledger
     * independently calculates for that same point in time. Never used to
     * overwrite an account's actual balance (CLAUDE.md §"Balance
     * Reconciliation") — this table only ever compares and flags.
     *
     * observed_balance_minor / calculated_balance_minor / difference_minor
     * / observed_at are a snapshot fact and immutable once written; only
     * reconciliation_status (and the resolution fields) may change, when
     * the user reviews a mismatch.
     */
    public function up(): void
    {
        Schema::create('balance_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_message_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedBigInteger('observed_balance_minor');
            $table->bigInteger('calculated_balance_minor');
            $table->bigInteger('difference_minor');
            $table->dateTime('observed_at');

            $table->string('reconciliation_status'); // App\Domain\Finance\Enums\ReconciliationStatus
            $table->dateTime('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'financial_account_id']);
            $table->index(['user_id', 'reconciliation_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_observations');
    }
};
