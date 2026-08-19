<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The chart of accounts. This is the pure double-entry primitive:
     * every LedgerEntry posts to exactly one of these. Real-world financial
     * accounts (financial_accounts) and user-facing categories
     * (transaction_categories) both point at one of these rather than
     * being ledger accounts themselves.
     */
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type'); // App\Domain\Finance\Enums\LedgerAccountType
            $table->char('currency', 3)->default('KES');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
