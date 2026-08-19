<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Common goal structure (CLAUDE.md §GOALS) — deliberately generic
     * (target_value/unit rather than a money-only column) so non-financial
     * goal types can reuse this table later without a schema change, but
     * only goal_type=SAVINGS has real behavior wired up in V1. There is no
     * `allocated_amount` column: a savings goal's progress is derived from
     * goal_allocation_events, the same "derive, don't cache" rule used for
     * account balances (CLAUDE.md §9).
     */
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('goal_type'); // App\Domain\Goals\Enums\GoalType
            $table->unsignedBigInteger('target_value');
            $table->string('unit')->default('KES_MINOR');

            // The user's planned/assumed monthly contribution — the basis
            // for "remaining months" projections and wishlist affordability
            // scenarios. Nullable: a goal can exist before the user has
            // decided a pace for it.
            $table->unsignedBigInteger('monthly_contribution_minor')->nullable();

            $table->date('target_date')->nullable();
            $table->string('status'); // App\Domain\Goals\Enums\GoalStatus
            $table->string('priority'); // App\Domain\Support\Enums\Priority
            $table->dateTime('completed_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
