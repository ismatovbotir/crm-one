<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 21.5 (DEC-17): CRM-master serial registry and its append-only history. Serials are
     * created only by posted stock documents and never deleted. Unique per item; `global_key`
     * enforces optional global uniqueness without a second table.
     */
    public function up(): void
    {
        Schema::create('item_serials', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->string('serial_raw', 100);
            $table->string('serial_normalized', 64)->index();
            $table->string('global_key', 64)->nullable()->unique();
            $table->string('status', 16)->index();
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source', 16);
            $table->unsignedBigInteger('received_receipt_line_id')->nullable()->index();
            $table->timestamp('received_at')->nullable();
            $table->string('reserved_by_line_type', 24)->nullable();
            $table->unsignedBigInteger('reserved_by_line_id')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->unsignedBigInteger('sold_delivery_line_id')->nullable()->index();
            $table->foreignId('sold_order_line_id')->nullable()->constrained('order_lines')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('sold_at')->nullable();
            $table->date('warranty_until')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['item_id', 'serial_normalized']);
            $table->index(['item_id', 'warehouse_id', 'status']);
            $table->index(['reserved_by_line_type', 'reserved_by_line_id']);
            $table->index('customer_id');
        });

        Schema::create('serial_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_serial_id')->constrained()->restrictOnDelete();
            $table->string('event', 20);
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->unsignedBigInteger('warehouse_from_id')->nullable();
            $table->unsignedBigInteger('warehouse_to_id')->nullable();
            $table->string('document_type', 24)->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->unsignedBigInteger('line_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['item_serial_id', 'created_at']);
            $table->index(['document_type', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serial_events');
        Schema::dropIfExists('item_serials');
    }
};
