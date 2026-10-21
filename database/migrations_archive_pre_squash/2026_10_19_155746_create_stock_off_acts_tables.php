<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 section 27 (user decision 2026-10-19): stock write-off / damage act ("stock-off act").
     * One document for every stock-off event (damage at receipt, in storage, in transit, loss, theft,
     * expiry, quality rejection, internal use, samples, rejected returns). Draft → submitted →
     * approved → posted (immutable), rejected or cancelled. Posting writes the picked serials off,
     * lowers the CRM stock mirror and makes `stock_off.posted` available for 1C to pull.
     */
    public function up(): void
    {
        Schema::create('stock_off_acts', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('number', 20)->unique();
            $table->string('status', 12)->index();
            $table->string('reason', 24)->index();
            $table->text('note')->nullable();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_partner_id')->nullable()->constrained('partners')->nullOnDelete();
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
            $table->string('onec_guid', 36)->nullable()->unique();
            $table->string('onec_number', 30)->nullable();
            $table->timestamp('onec_acked_at')->nullable();
            $table->timestamps();

            $table->index(['goods_receipt_id']);
            $table->index(['warehouse_id', 'status']);
        });

        Schema::create('stock_off_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('act_id')->constrained('stock_off_acts')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(1);
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->boolean('is_serialized')->default(false);
            $table->decimal('quantity', 15, 3)->default(0);
            $table->foreignId('receipt_line_id')->nullable()->constrained('goods_receipt_lines')->nullOnDelete();
            $table->bigInteger('unit_cost_minor')->nullable();
            $table->bigInteger('value_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['act_id', 'position']);
            $table->index(['item_id']);
            $table->index(['receipt_line_id']);
        });

        Schema::create('stock_off_line_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('line_id')->constrained('stock_off_lines')->cascadeOnDelete();
            $table->foreignId('item_serial_id')->constrained()->restrictOnDelete();
            $table->string('serial_number', 64);
            // Set while the act is open: one serial sits on at most one open act.
            $table->string('active_key', 40)->nullable()->unique();
            $table->timestamp('created_at')->nullable();

            $table->unique(['line_id', 'item_serial_id']);
            $table->index(['item_serial_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_off_line_serials');
        Schema::dropIfExists('stock_off_lines');
        Schema::dropIfExists('stock_off_acts');
    }
};
