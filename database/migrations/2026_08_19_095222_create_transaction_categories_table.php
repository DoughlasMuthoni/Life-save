<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * User-facing labels (Groceries, Transport, Salary, ...) layered on top
     * of the chart of accounts. Each category posts to one INCOME or
     * EXPENSE ledger_account; TransactionService is responsible for keeping
     * category.type and ledger_account.type in agreement (CLAUDE.md §9).
     */
    public function up(): void
    {
        Schema::create('transaction_categories', function (Blueprint $table) {
            $table->id();
            // Nullable so a future seeder can ship shared default categories
            // that aren't owned by any one user; every category actually
            // usable by the (single) owner today will have this set.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('type'); // App\Domain\Finance\Enums\TransactionCategoryType
            $table->string('icon')->nullable();
            $table->string('color')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_categories');
    }
};
