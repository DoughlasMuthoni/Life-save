<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raw pasted SMS text, preserved exactly as evidence (CLAUDE.md §7).
     * Fully immutable once created — enforced at the model layer, same
     * pattern as the ledger tables.
     */
    public function up(): void
    {
        Schema::create('financial_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->text('raw_text');
            $table->text('normalized_text');
            $table->char('message_hash', 64); // sha256 of normalized_text

            $table->string('provider'); // App\Domain\Ingestion\Enums\MessageProvider
            $table->string('parser_type')->nullable(); // e.g. 'MpesaParser'
            $table->string('parser_version')->nullable();
            $table->string('parse_status'); // App\Domain\Ingestion\Enums\ParseStatus
            $table->unsignedTinyInteger('confidence')->nullable(); // 0-100, never a float

            $table->string('external_transaction_id')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'message_hash']);
            // Deliberately NOT a unique constraint: the raw text is evidence
            // and must be storable even when it's an exact re-paste or
            // shares a transaction code with an earlier message (e.g. the
            // user pastes the same SMS twice by accident). Duplicate
            // detection flags this at the application layer
            // (DuplicateDetectionService) instead of rejecting the insert —
            // CLAUDE.md is explicit that duplicates are flagged, never
            // silently dropped.
            $table->index(['user_id', 'provider', 'external_transaction_id'], 'financial_messages_user_provider_extid_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_messages');
    }
};
