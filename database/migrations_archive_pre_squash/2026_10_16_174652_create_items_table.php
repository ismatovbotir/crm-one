<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 01 section 6.7 as amended by Spec 02 A3: no base price; 1C-owned fields plus
     * CRM-local `min_order_qty` / `order_step`. SKU (= 1C code) is unique per the build brief.
     */
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->string('article', 64)->nullable()->index();
            $table->string('name')->index();
            $table->text('description')->nullable();
            $table->string('type', 20);
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('tax_rate_bp');
            $table->boolean('is_vat_exempt')->default(false);
            $table->decimal('min_order_qty', 15, 3)->nullable();
            $table->decimal('order_step', 15, 3)->nullable();
            $table->boolean('is_active')->default(true)->index();

            $table->char('external_id', 36)->unique();
            $table->string('external_code', 50)->nullable()->index();
            $table->string('external_version', 64)->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->char('external_hash', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('external_deleted_at')->nullable();
            $table->char('last_seen_snapshot_id', 36)->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
