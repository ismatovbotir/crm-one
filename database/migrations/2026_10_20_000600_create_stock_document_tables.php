<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Squashed schema (2026-10-20), see docs/db-review-2026-10-19.md "Resolved by squash".
     * Stock documents: delivery notes, goods receipts (1C origin), document serial rows, stock-off acts, stock adjustments.
     */
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->string('number', 20);
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('status', 12);
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('planned_on')->nullable();
            $table->date('delivered_on')->nullable();
            $table->unsignedBigInteger('shipment_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $this->ascii($table->char('onec_guid', 36)->nullable());
            $table->string('onec_number', 30)->nullable();
            $table->string('onec_state', 16)->default('not_sent');
            $table->timestamp('onec_acked_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->timestamp('packed_at')->nullable();
            $table->foreignId('packed_by')->nullable()->constrained('users')->nullOnDelete();
            // Spec 06 section 31: self pick-up handover (who collected, their document).
            $table->string('pickup_person', 160)->nullable();
            $table->string('pickup_document', 60)->nullable();
            $table->index('warehouse_id');
            $table->unique('uuid');
            $table->index('status');
            $table->index(['order_id', 'status']);
            $table->unique('onec_guid');
            $table->unique('number');
        });

        Schema::create('delivery_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained('deliveries')->restrictOnDelete();
            $table->foreignId('order_line_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->boolean('is_serialized')->default(false);
            $table->decimal('quantity', 15, 3)->default(0);
            $table->decimal('returned_qty', 15, 3)->default(0);
            $table->timestamps();
            $table->index('item_id');
            $table->index('order_line_id');
            $table->index(['delivery_id', 'position']);
        });
        SchemaChecks::add('delivery_lines', [
            'delivery_lines_qty_chk' => '((`quantity` >= 0) and (`returned_qty` <= `quantity`))',
        ]);

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->string('number', 20);
            $table->string('status', 20);
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('counterparty_name', 255)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('partners')->restrictOnDelete();
            $table->unsignedBigInteger('return_of_delivery_id')->nullable();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->date('received_on');
            $table->string('supplier_doc_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $this->ascii($table->char('onec_guid', 36)->nullable());
            $table->string('onec_number', 30)->nullable();
            $table->string('onec_state', 16)->default('not_sent');
            $table->timestamp('onec_acked_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->string('origin', 10)->default('crm');
            $this->ascii($table->string('external_id', 36)->nullable());
            $table->string('external_number', 50)->nullable();
            $table->date('external_date')->nullable();
            $table->foreignId('supplier_partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->char('external_hash', 64)->nullable();
            $table->unsignedInteger('sync_version')->default(1);
            $table->timestamp('external_updated_at')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->string('review_reason', 60)->nullable();
            $table->index('warehouse_id');
            $table->unique('uuid');
            $table->index(['status', 'received_on']);
            $table->index('return_of_delivery_id');
            $table->index('origin');
            $table->unique('onec_guid');
            $table->unique('number');
            $table->index('needs_review');
            $table->unique('external_id');
            $table->index('customer_id');
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
            $this->ascii($table->string('external_line_id', 36)->nullable());
            $table->index('item_id');
            $table->index(['receipt_id', 'position']);
            $table->unique(['receipt_id', 'external_line_id'], 'goods_receipt_lines_receipt_external_unique');
        });
        SchemaChecks::add('goods_receipt_lines', [
            'goods_receipt_lines_qty_chk' => '((`received_qty` >= 0) and (`damaged_qty` >= 0) and (`damaged_qty` <= `received_qty`))',
        ]);

        Schema::create('document_line_serials', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 24);
            $table->unsignedBigInteger('document_id');
            $table->string('line_type', 24);
            $table->unsignedBigInteger('line_id');
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_serial_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('serial_number', 64);
            $table->string('serial_raw', 100);
            $table->string('status', 10);
            $table->foreignId('replaced_by_id')->nullable()->constrained('document_line_serials')->nullOnDelete();
            $table->string('active_key', 150)->nullable();
            $table->unsignedInteger('position')->default(1);
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->boolean('is_damaged')->default(false);
            $table->string('damage_note', 250)->nullable();
            $table->index('item_id');
            $table->index('item_serial_id');
            $table->unique('active_key');
            $table->index(['document_type', 'document_id']);
            $table->index(['line_type', 'line_id']);
        });

        Schema::create('stock_off_acts', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->string('number', 20);
            $table->string('status', 12);
            $table->string('reason', 24);
            $table->text('note')->nullable();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_partner_id')->nullable()->constrained('partners')->restrictOnDelete();
            $table->string('claim_status', 10)->default('none');
            $table->string('claim_ref', 60)->nullable();
            $table->text('claim_note')->nullable();
            $table->timestamp('claim_settled_at')->nullable();
            $table->json('totals')->nullable();
            $table->string('approval_level', 8)->default('head');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->string('onec_state', 12)->default('not_sent');
            $this->ascii($table->string('onec_guid', 36)->nullable());
            $table->string('onec_number', 30)->nullable();
            $table->timestamp('onec_acked_at')->nullable();
            $table->timestamps();
            $table->unique('onec_guid');
            $table->index('reason');
            $table->index('status');
            $table->unique('number');
            $table->unique('uuid');
            $table->index(['warehouse_id', 'status']);
            $table->index('goods_receipt_id');
        });

        Schema::create('stock_off_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('act_id')->constrained('stock_off_acts')->restrictOnDelete();
            $table->unsignedInteger('position')->default(1);
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->boolean('is_serialized')->default(false);
            $table->decimal('quantity', 15, 3)->default(0);
            $table->foreignId('receipt_line_id')->nullable()->constrained('goods_receipt_lines')->restrictOnDelete();
            $table->bigInteger('unit_cost_minor')->nullable();
            $table->bigInteger('value_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index('receipt_line_id');
            $table->index('item_id');
            $table->index(['act_id', 'position']);
        });
        SchemaChecks::add('stock_off_lines', [
            'stock_off_lines_qty_chk' => 'quantity >= 0',
            'stock_off_lines_cost_chk' => 'unit_cost_minor is null or unit_cost_minor >= 0',
            'stock_off_lines_value_chk' => 'value_minor is null or value_minor >= 0',
            'stock_off_lines_currency_chk' => '(value_minor is null) = (currency is null)',
        ]);

        Schema::create('stock_off_line_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('line_id')->constrained('stock_off_lines')->restrictOnDelete();
            $table->foreignId('item_serial_id')->constrained()->restrictOnDelete();
            $table->string('serial_number', 64);
            $table->string('active_key', 40)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique('active_key');
            $table->index('item_serial_id');
            $table->unique(['line_id', 'item_serial_id']);
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->string('number', 20);
            $table->string('type', 20);
            $table->string('status', 16);
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique('number');
            $table->unique('uuid');
            $table->index('type');
            $table->index('status');
            $table->index(['warehouse_id', 'status']);
            $table->index('delivery_id');
        });

        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjustment_id')->constrained('stock_adjustments')->restrictOnDelete();
            $table->unsignedInteger('position')->default(1);
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3)->default(0);
            $table->string('serial_raw', 100)->nullable();
            $table->string('serial_normalized', 64)->nullable();
            $table->foreignId('old_item_serial_id')->nullable()->constrained('item_serials')->restrictOnDelete();
            $table->foreignId('new_item_serial_id')->nullable()->constrained('item_serials')->restrictOnDelete();
            $table->foreignId('delivery_line_id')->nullable()->constrained('delivery_lines')->restrictOnDelete();
            $table->string('old_serial_outcome', 12)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index('item_id');
            $table->index(['adjustment_id', 'position']);
            $table->index('old_item_serial_id');
            $table->index('new_item_serial_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_lines');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('stock_off_line_serials');
        Schema::dropIfExists('stock_off_lines');
        Schema::dropIfExists('stock_off_acts');
        Schema::dropIfExists('document_line_serials');
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('delivery_lines');
        Schema::dropIfExists('deliveries');
    }

    /**
     * 1C identifiers (GUIDs, document/line ids) are ASCII: 1 byte per character in MySQL indexes
     * instead of 4. Case-insensitive like before (ascii_general_ci). SQLite has no charsets.
     */
    private function ascii(ColumnDefinition $column): ColumnDefinition
    {
        return DB::getDriverName() === 'mysql' ? $column->charset('ascii')->collation('ascii_general_ci') : $column;
    }
};
