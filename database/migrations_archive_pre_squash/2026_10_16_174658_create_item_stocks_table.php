<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 02 section 11.3: current stock balance per item and warehouse (no history).
     */
    public function up(): void
    {
        Schema::create('item_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('reserved', 15, 3)->default(0);
            $table->timestamp('as_of');
            $table->timestamp('received_at');
            $table->char('last_seen_snapshot_id', 36)->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'warehouse_id']);
            $table->index('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_stocks');
    }
};
