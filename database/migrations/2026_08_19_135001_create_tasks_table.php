<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deliberately minimal (CLAUDE.md §DAILY OPERATIONS: "keep the initial
     * productivity module intentionally simple") — no projects, no
     * recurrence, no subtasks. Those come later, and only after the
     * financial system is reliable.
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority'); // App\Domain\Support\Enums\Priority
            $table->date('due_date')->nullable();
            $table->string('status'); // App\Domain\Tasks\Enums\TaskStatus
            $table->dateTime('completed_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
