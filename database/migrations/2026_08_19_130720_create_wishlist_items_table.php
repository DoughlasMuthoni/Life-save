<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CLAUDE.md §WISHLIST. No `amount_allocated` column on purpose: it's
     * always derived from the linked goal's allocation total (via
     * Goal::allocatedAmountMinor()) when linked_goal_id is set, and zero
     * otherwise — storing it separately would be a second source of truth
     * that could drift from the goal it's supposed to reflect.
     */
    public function up(): void
    {
        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('estimated_price_minor');
            $table->string('category')->nullable();
            $table->string('priority'); // App\Domain\Support\Enums\Priority
            $table->date('target_purchase_date')->nullable();

            $table->foreignId('linked_goal_id')->nullable()->constrained('goals')->nullOnDelete();

            $table->string('status'); // App\Domain\Wishlist\Enums\WishlistStatus
            $table->dateTime('purchased_at')->nullable();
            $table->foreignId('purchased_journal_id')->nullable()->constrained('journals')->nullOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_items');
    }
};
