<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 section 7.5 (DEC-8): soft reservations in the CRM against the 1C stock snapshot.
     * `mirrored_in_1c` rows (1C reserves after acking the order) are no longer subtracted.
     */
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->string('status', 16);
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason', 30)->nullable();
            $table->timestamps();

            $table->index(['item_id', 'warehouse_id', 'status']);
            $table->index('order_line_id');
        });

        SchemaChecks::add('stock_reservations', ['stock_reservations_qty_chk' => 'quantity > 0']);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
