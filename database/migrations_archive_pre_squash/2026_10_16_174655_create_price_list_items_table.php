<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 01 section 6.9 + Spec 02 section 11.2: dated price rows (history and future prices kept).
     */
    public function up(): void
    {
        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->decimal('min_qty', 15, 3)->default(0);
            $table->unsignedBigInteger('price_minor');
            $table->date('valid_from')->default('1970-01-01');
            $table->timestamp('external_updated_at')->nullable();
            $table->char('last_seen_snapshot_id', 36)->nullable();
            $table->timestamps();

            $table->unique(['price_list_id', 'item_id', 'min_qty', 'valid_from']);
            $table->index(['item_id', 'price_list_id', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_items');
    }
};
