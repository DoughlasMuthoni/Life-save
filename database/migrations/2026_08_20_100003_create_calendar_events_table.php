<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deliberately minimal, matching the rest of Phase 10: a plain dated
     * event, optionally timed. No recurrence, no external calendar sync,
     * no invitees/attendees.
     */
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->date('event_date');
            $table->time('event_time')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
