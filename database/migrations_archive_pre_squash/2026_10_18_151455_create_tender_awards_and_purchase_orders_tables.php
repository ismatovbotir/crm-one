<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 03 7.3 / BR-T.10-11: award per lot (one active award per lot, enforced by the unique
     * `active_lot_id`), append-only approval decisions (publish and award; Spec 08 `tender_decisions`)
     * and the purchase order created from an accepted purchase award (one per award, unique FK).
     * Spec 08 extends purchase orders with manual POs and cargo data.
     */
    public function up(): void
    {
        Schema::create('tender_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->restrictOnDelete();
            $table->foreignId('lot_id')->constrained('tender_lots')->restrictOnDelete();
            $table->foreignId('bid_id')->constrained('tender_bids')->restrictOnDelete();
            $table->foreignId('bid_lot_id')->constrained('tender_bid_lots')->restrictOnDelete();
            $table->foreignId('counterparty_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->bigInteger('landed_cost_minor');
            $table->char('evaluation_currency', 3);
            $table->unsignedSmallInteger('rank_at_proposal')->nullable();
            $table->text('justification')->nullable();
            $table->string('status', 12);
            $table->json('required_levels');
            $table->unsignedBigInteger('active_lot_id')->nullable()->unique();
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('proposed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('accept_due_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->text('acceptance_note')->nullable();
            $table->foreignId('acceptance_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tender_id', 'status']);
        });

        Schema::create('tender_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->restrictOnDelete();
            $table->foreignId('award_id')->nullable()->constrained('tender_awards')->restrictOnDelete();
            $table->string('subject', 12);
            $table->string('level', 10);
            $table->string('decision', 12);
            $table->text('comment')->nullable();
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tender_id', 'subject']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('number', 20)->unique();
            $table->string('source', 14)->default('tender_award');
            $table->foreignId('tender_award_id')->nullable()->unique()->constrained('tender_awards')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('counterparties')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);
            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor')->default(0);
            $table->string('incoterm', 3)->nullable();
            $table->string('incoterm_place')->nullable();
            $table->foreignId('destination_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->date('readiness_date')->nullable();
            $table->date('required_delivery_date')->nullable();
            $table->text('payment_terms')->nullable();
            $table->unsignedSmallInteger('payment_days')->nullable();
            $table->unsignedSmallInteger('prepayment_bp')->nullable();
            $table->string('onec_number', 30)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('supplier_id');
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tender_item_id')->nullable()->constrained('tender_items')->nullOnDelete();
            $table->string('sku', 60)->nullable();
            $table->string('name');
            $table->string('unit_code', 20)->nullable();
            $table->unsignedTinyInteger('unit_precision')->default(3);
            $table->decimal('quantity', 15, 3);
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('line_total_minor');
            $table->string('hs_code', 10)->nullable();
            $table->string('offered_brand', 120)->nullable();
            $table->string('offered_model', 120)->nullable();
            $table->date('required_by')->nullable();
            $table->timestamps();

            $table->index(['purchase_order_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('tender_decisions');
        Schema::dropIfExists('tender_awards');
    }
};
