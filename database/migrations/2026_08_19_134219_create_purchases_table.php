<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "What was bought" — related to but distinct from a financial
     * transaction, which answers "how was it paid" (CLAUDE.md §SHOPPING).
     * journal_id is nullable (a purchase can be logged before, or without
     * ever being tied to, a specific financial transaction) and unique
     * (at most one purchase per journal — a journal is one payment, not
     * several separate shopping trips).
     *
     * total_amount_minor is independently entered, not derived from
     * purchase_items — a purchase may be logged without itemizing at all,
     * and the total is what was actually paid (which may include things
     * items don't, like a tip or a delivery fee).
     */
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('journal_id')->nullable()->unique()->constrained()->nullOnDelete();

            $table->unsignedBigInteger('total_amount_minor');
            $table->dateTime('purchased_at');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'purchased_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
