<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Three deliberately minimal, independent logs — weight, workouts,
     * meals — per the user's explicit scoping of Health for V1. No
     * calorie/macro computation, no structured training plans, no unit
     * preference system (kg / minutes only).
     */
    public function up(): void
    {
        Schema::create('weight_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->date('recorded_at');
            $table->decimal('weight_kg', 5, 2);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'recorded_at']);
        });

        Schema::create('workout_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->date('performed_at');
            $table->string('type');
            $table->unsignedInteger('duration_minutes');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'performed_at']);
        });

        Schema::create('meal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->dateTime('eaten_at');
            $table->string('meal_type')->nullable(); // App\Domain\Health\Enums\MealType
            $table->text('description');

            $table->timestamps();

            $table->index(['user_id', 'eaten_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_entries');
        Schema::dropIfExists('workout_entries');
        Schema::dropIfExists('weight_entries');
    }
};
