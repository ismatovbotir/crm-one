<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 section 7.3: order lines are snapshots of the offer's fixed + selected lines (codes,
     * serial flag, amounts verbatim). Delivered/invoiced/cancelled quantities are maintained by
     * delivery notes and tax documents; CHECKs back the Action guards on MySQL.
     */
    public function up(): void
    {
        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('offer_line_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('offer_option_group_id')->nullable();
            $table->string('option_label', 8)->nullable();
            $table->string('group_title')->nullable();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->char('item_external_id', 36)->nullable();
            $table->string('sku', 64);
            $table->string('name');
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('unit_code', 16)->nullable();
            $table->string('unit_name', 64);
            $table->unsignedTinyInteger('unit_precision')->default(0);
            $table->unsignedSmallInteger('tax_rate_bp');
            $table->boolean('is_vat_exempt')->default(false);
            $table->string('accountant_code', 64)->nullable()->index();
            $table->string('class_code', 32)->nullable();
            $table->string('package_code', 32)->nullable();
            $table->boolean('is_serialized')->default(false);
            $table->decimal('quantity', 15, 3);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedSmallInteger('discount_bp')->default(0);
            $table->unsignedBigInteger('line_net_minor');
            $table->unsignedBigInteger('line_vat_minor');
            $table->unsignedBigInteger('line_gross_minor');
            $table->unsignedBigInteger('cost_price_minor')->nullable();
            $table->decimal('delivered_qty', 15, 3)->default(0);
            $table->decimal('invoiced_qty', 15, 3)->default(0);
            $table->decimal('cancelled_qty', 15, 3)->default(0);
            $table->decimal('returned_qty', 15, 3)->default(0);
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->boolean('backorder')->default(false);
            $table->timestamps();

            $table->index(['order_id', 'position']);
            $table->index('item_id');
        });

        SchemaChecks::add('order_lines', [
            'order_lines_qty_chk' => 'quantity > 0',
            'order_lines_delivered_chk' => 'delivered_qty + cancelled_qty <= quantity',
            'order_lines_invoiced_chk' => 'invoiced_qty <= delivered_qty',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
    }
};
