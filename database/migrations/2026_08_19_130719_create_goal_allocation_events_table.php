<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allocation history, not just a number (CLAUDE.md §SAVINGS). A goal's
     * currently-allocated amount is always the sum of its ALLOCATE events
     * minus its RELEASE events — never a mutable running total. Immutable
     * once written, like the ledger tables; a reallocation between two
     * goals is a RELEASE from one plus an ALLOCATE to the other (two rows),
     * not a special third event type, so it reuses the exact same
     * validation and derivation logic as any other allocate/release.
     */
    public function up(): void
    {
        Schema::create('goal_allocation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();

            $table->string('event_type'); // App\Domain\Goals\Enums\AllocationEventType
            $table->unsignedBigInteger('amount_minor');
            $table->text('note')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['goal_id', 'created_at']);
            $table->index(['financial_account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_allocation_events');
    }
};
