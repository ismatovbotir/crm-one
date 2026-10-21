<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 05 section 7.4: an offer line with the item snapshot taken when it was added (later
     * catalog or price changes never alter it), its own quantity/price/discount/VAT and the
     * amounts computed by OfferCalculator. option_group_id null = fixed line.
     */
    public function up(): void
    {
        Schema::create('offer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_group_id')->nullable()->constrained('offer_option_groups')->cascadeOnDelete();
            $table->uuid('stable_key');
            $table->unsignedSmallInteger('position');
            $table->char('option_label', 1)->nullable();
            $table->boolean('is_recommended')->default(false);
            $table->foreignId('item_id')->constrained()->restrictOnDelete();

            // Snapshot (Spec 01 section 5 + Spec 02 handoff + BR-I.13a/b).
            $table->char('item_external_id', 36)->nullable();
            $table->string('sku', 64);
            $table->string('article', 64)->nullable();
            $table->string('accountant_code', 64)->nullable();
            $table->string('class_code', 32)->nullable();
            $table->string('package_code', 32)->nullable();
            $table->boolean('is_serialized')->default(false);
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('unit_code', 16)->nullable();
            $table->string('unit_name', 64);
            $table->unsignedTinyInteger('unit_precision')->default(0);
            $table->unsignedSmallInteger('tax_rate_bp');
            $table->boolean('is_vat_exempt')->default(false);
            $table->string('price_source', 24);
            $table->unsignedBigInteger('list_unit_price_minor')->nullable();
            // Cost basis (stock cost price, else purchase price; Spec 01/02 rev. 4) for margin and the below-cost check.
            $table->unsignedBigInteger('cost_price_minor')->nullable();

            $table->decimal('quantity', 15, 3);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedSmallInteger('discount_bp')->default(0);
            $table->unsignedSmallInteger('effective_discount_bp')->default(0);
            $table->unsignedBigInteger('line_discount_minor')->default(0);
            $table->unsignedBigInteger('line_net_minor')->default(0);
            $table->unsignedBigInteger('line_vat_minor')->default(0);
            $table->unsignedBigInteger('line_gross_minor')->default(0);

            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->string('stock_band', 12)->nullable();
            $table->decimal('stock_available_qty', 15, 3)->nullable();
            $table->timestamp('stock_checked_at')->nullable();

            $table->string('alternative_reason', 24)->nullable();
            $table->string('alternative_reason_note')->nullable();
            $table->text('comparison_notes')->nullable();
            $table->text('internal_note')->nullable();
            $table->timestamps();

            $table->index(['offer_id', 'position']);
            $table->index(['option_group_id', 'position']);
            $table->index('item_id');
            $table->unique(['offer_id', 'stable_key']);
        });

        SchemaChecks::add('offer_lines', [
            'offer_lines_qty_chk' => 'quantity > 0',
            'offer_lines_discount_chk' => 'discount_bp <= 10000',
            'offer_lines_eff_discount_chk' => 'effective_discount_bp <= 10000',
            'offer_lines_tax_chk' => 'tax_rate_bp <= 10000',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_lines');
    }
};
