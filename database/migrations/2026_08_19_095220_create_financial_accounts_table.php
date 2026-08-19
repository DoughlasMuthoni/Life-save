<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Real-world accounts the user actually holds money in (M-Pesa,
     * M-Shwari, a bank account, cash). Each one owns exactly one ASSET-type
     * ledger_account, which is what postings actually touch — this table
     * carries the human-facing metadata (provider, display name) and
     * deliberately nothing that affects the ledger's arithmetic.
     */
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->unique()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('provider'); // App\Domain\Finance\Enums\FinancialAccountProvider
            $table->string('account_identifier')->nullable(); // e.g. masked phone/account number, never a full PAN
            $table->char('currency', 3)->default('KES');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_accounts');
    }
};
