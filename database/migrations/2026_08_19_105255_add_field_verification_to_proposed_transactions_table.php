<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-field verification state for AI-derived proposals (CLAUDE.md §8:
     * "important financial fields should ideally have field-level
     * confidence or validation states"). Null for deterministically-parsed
     * proposals — regex extraction only ever captures substrings that
     * already exist in the source text, so per-field verification is
     * trivially true by construction and not worth storing.
     */
    public function up(): void
    {
        Schema::table('proposed_transactions', function (Blueprint $table) {
            $table->json('field_verification')->nullable()->after('reported_balance_minor');
        });
    }

    public function down(): void
    {
        Schema::table('proposed_transactions', function (Blueprint $table) {
            $table->dropColumn('field_verification');
        });
    }
};
