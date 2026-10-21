<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 21.4/21.5: goods receipts (supplier delivery, opening balance, customer return; PO and
     * shipment sources join with tenders/transport). Posted receipts are immutable; no prices.
     */
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('number', 20)->unique();
            $table->string('status', 12)->index();
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('counterparty_name')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('return_of_delivery_id')->nullable()->index();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->date('received_on');
            $table->string('supplier_doc_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('onec_guid', 36)->nullable()->unique();
            $table->string('onec_number', 30)->nullable();
            $table->string('onec_state', 16)->default('not_sent');
            $table->timestamp('onec_acked_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['status', 'received_on']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained('goods_receipts')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->boolean('is_serialized')->default(false);
            $table->unsignedBigInteger('return_of_delivery_line_id')->nullable();
            $table->decimal('expected_qty', 15, 3)->nullable();
            $table->decimal('received_qty', 15, 3)->default(0);
            $table->decimal('damaged_qty', 15, 3)->default(0);
            $table->string('condition', 10)->default('good');
            $table->string('discrepancy_reason', 16)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['receipt_id', 'position']);
            $table->index('item_id');
        });

        SchemaChecks::add('goods_receipt_lines', [
            'goods_receipt_lines_qty_chk' => 'received_qty >= 0 and damaged_qty >= 0 and damaged_qty <= received_qty',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
    }
};
