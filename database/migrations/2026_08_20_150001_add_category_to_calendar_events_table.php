<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive, nullable — existing events are simply uncategorized until
     * edited (CLAUDE.md §22: additive changes are low risk).
     */
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->string('category')->nullable()->after('event_time'); // App\Domain\Calendar\Enums\CalendarEventCategory
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
