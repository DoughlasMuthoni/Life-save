<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deliberately minimal, matching Tasks (Phase 9) and Notes (Phase 10a):
     * a habit is just a name, checked in once per day. No custom cadences
     * (weekly/every-N-days), no reminders. A check-in is immutable once
     * written (delete + recreate is how a mis-tap gets undone, mirroring
     * how the rest of this app never silently overwrites history) — it's
     * a simple event log, not a mutable "done today?" flag.
     */
    public function up(): void
    {
        Schema::create('habits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            $table->timestamps();
        });

        Schema::create('habit_check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('habit_id')->constrained()->cascadeOnDelete();

            $table->date('date');

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['habit_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habit_check_ins');
        Schema::dropIfExists('habits');
    }
};
