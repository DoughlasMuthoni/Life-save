<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional itemization of a purchase. Never invented from an M-Pesa
     * SMS (CLAUDE.md §SHOPPING: "Do not invent item-level details from an
     * M-Pesa SMS") — items only ever come from the user typing them in.
     * No line_total column: it's quantity * unit_price_minor, derived at
     * read time rather than stored.
     */
    public function up(): void
    {
        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price_minor');
            $table->string('category')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
    }
};
