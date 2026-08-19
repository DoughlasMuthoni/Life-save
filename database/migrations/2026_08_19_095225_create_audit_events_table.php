<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only trail of important actions (CLAUDE.md §13). Never write
     * passwords, API keys, or auth secrets into `data`.
     */
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action'); // App\Domain\Audit\Enums\AuditAction
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('data')->nullable();
            $table->string('ip_address')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['user_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
