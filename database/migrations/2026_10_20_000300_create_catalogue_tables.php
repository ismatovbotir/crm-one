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
     * Catalogue: units, categories, items, analogues, images, warehouses, stock mirror, serial registry and serial imports.
     */
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16);
            $table->string('name', 64);
            $table->unsignedTinyInteger('precision')->default(0);
            $table->boolean('is_active')->default(true);
            $this->ascii($table->char('external_id', 36));
            $table->string('external_code', 50)->nullable();
            $table->string('external_version', 64)->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->char('external_hash', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('external_deleted_at')->nullable();
            $this->ascii($table->char('last_seen_snapshot_id', 36)->nullable());
            $table->timestamps();
            $table->index('last_seen_snapshot_id');
            $table->index('external_code');
            $table->unique('external_id');
            $table->unique('code');
        });
        SchemaChecks::add('units', [
            'units_precision_chk' => '(`precision` <= 3)',
        ]);

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name', 120);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $this->ascii($table->char('external_id', 36)->nullable());
            $table->string('external_code', 50)->nullable();
            $table->string('external_version', 64)->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->char('external_hash', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('external_deleted_at')->nullable();
            $this->ascii($table->char('last_seen_snapshot_id', 36)->nullable());
            $table->timestamps();
            $table->boolean('portal_visible')->default(true);
            $table->json('portal_names')->nullable();
            $table->integer('portal_sort')->default(0);
            $table->index('parent_id');
            $table->index('last_seen_snapshot_id');
            $table->unique('external_id');
            $table->index('external_code');
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64);
            $table->string('article', 64)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->string('accountant_code', 64)->nullable();
            $table->string('class_code', 32)->nullable();
            $table->string('package_code', 32)->nullable();
            $table->string('hs_code', 10)->nullable();
            $table->decimal('gross_weight_kg', 12, 4)->nullable();
            $table->decimal('net_weight_kg', 12, 4)->nullable();
            $table->decimal('volume_m3', 12, 6)->nullable();
            $table->char('country_of_origin', 2)->nullable();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('type', 20);
            $table->foreignId('category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('tax_rate_bp');
            $table->boolean('is_vat_exempt')->default(false);
            $table->boolean('is_serialized')->default(false);
            $table->unsignedSmallInteger('warranty_months')->nullable();
            $table->unsignedBigInteger('sale_price_minor')->nullable();
            $table->char('sale_currency', 3)->nullable();
            $table->unsignedBigInteger('purchase_price_minor')->nullable();
            $table->char('purchase_currency', 3)->nullable();
            $table->boolean('prices_include_vat')->default(false);
            $table->decimal('min_order_qty', 15, 3)->nullable();
            $table->decimal('order_step', 15, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $this->ascii($table->char('external_id', 36));
            $table->string('external_code', 50)->nullable();
            $table->string('external_version', 64)->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->char('external_hash', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('external_deleted_at')->nullable();
            $this->ascii($table->char('last_seen_snapshot_id', 36)->nullable());
            $table->timestamps();
            $table->softDeletes();
            $table->boolean('portal_visible')->default(true);
            $table->boolean('needs_review')->default(false);
            $table->string('review_reason', 60)->nullable();
            $table->index('needs_review');
            $table->index('category_id');
            $table->index('unit_id');
            $table->index('hs_code');
            $table->index('is_serialized');
            $table->index('package_code');
            $table->index('class_code');
            $table->index('accountant_code');
            $table->index('sku');
            $table->index('last_seen_snapshot_id');
            $table->index('external_code');
            $table->unique('external_id');
            $table->index('is_active');
            $table->index('name');
            $table->index('article');
            $table->index('barcode');
            $table->index(['is_active', 'category_id']);
        });
        SchemaChecks::add('items', [
            'items_moq_chk' => '((`min_order_qty` is null) or (`min_order_qty` > 0))',
            'items_purchase_currency_chk' => '((`purchase_price_minor` is null) or (`purchase_currency` is not null))',
            'items_sale_currency_chk' => '((`sale_price_minor` is null) or (`sale_currency` is not null))',
            'items_step_chk' => '((`order_step` is null) or (`order_step` > 0))',
            'items_tax_bp_chk' => '(`tax_rate_bp` <= 10000)',
        ]);

        Schema::create('item_alternatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alternative_item_id')->constrained('items')->cascadeOnDelete();
            $table->string('relation', 12);
            $table->boolean('is_mutual')->default(true);
            $table->string('default_reason', 24)->nullable();
            $table->text('note')->nullable();
            $table->string('source', 8)->default('crm');
            $table->string('status', 10)->default('approved');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->index('alternative_item_id');
            $table->unique(['item_id', 'alternative_item_id']);
        });
        SchemaChecks::add('item_alternatives', [
            'item_alternatives_self_chk' => '(`item_id` <> `alternative_item_id`)',
        ]);

        Schema::create('item_images', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(0);
            $table->string('path', 255);
            $table->string('medium_path', 255);
            $table->string('thumb_path', 255);
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('uuid');
            $table->index(['item_id', 'position']);
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->boolean('is_active')->default(true);
            $this->ascii($table->char('external_id', 36));
            $table->string('external_code', 50)->nullable();
            $table->string('external_version', 64)->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->char('external_hash', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('external_deleted_at')->nullable();
            $this->ascii($table->char('last_seen_snapshot_id', 36)->nullable());
            $table->timestamps();
            $table->index('last_seen_snapshot_id');
            $table->index('external_code');
            $table->unique('external_id');
        });

        Schema::create('item_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('reserved', 15, 3)->default(0);
            $table->bigInteger('cost_minor')->nullable();
            $table->char('cost_currency', 3)->nullable();
            $table->timestamp('as_of')->useCurrent();
            $table->timestamp('received_at')->useCurrent();
            $this->ascii($table->char('last_seen_snapshot_id', 36)->nullable());
            $table->timestamps();
            $table->index(['warehouse_id', 'last_seen_snapshot_id'], 'item_stocks_wh_seen_index');
            $table->unique(['item_id', 'warehouse_id']);
        });
        SchemaChecks::add('item_stocks', [
            'item_stocks_reserved_chk' => '(`reserved` >= 0)',
        ]);

        Schema::create('item_serials', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->string('serial_raw', 100);
            $table->string('serial_normalized', 64);
            $table->string('global_key', 64)->nullable();
            $table->string('status', 16);
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source', 16);
            $table->unsignedBigInteger('received_receipt_line_id')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('reserved_by_line_type', 24)->nullable();
            $table->unsignedBigInteger('reserved_by_line_id')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->unsignedBigInteger('sold_delivery_line_id')->nullable();
            $table->unsignedBigInteger('sold_order_line_id')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('partners')->restrictOnDelete();
            $table->timestamp('sold_at')->nullable();
            $table->date('warranty_until')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
            $table->index('warehouse_id');
            $table->index('sold_order_line_id');
            $table->index('sold_delivery_line_id');
            $table->index('received_receipt_line_id');
            $table->index('status');
            $table->unique('global_key');
            $table->index('serial_normalized');
            $table->unique('uuid');
            $table->index('customer_id');
            $table->index(['reserved_by_line_type', 'reserved_by_line_id']);
            $table->index(['item_id', 'warehouse_id', 'status']);
            $table->unique(['item_id', 'serial_normalized']);
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
            $table->string('reason', 255)->nullable();
            $table->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['document_type', 'document_id']);
            $table->index(['item_serial_id', 'created_at']);
        });

        Schema::create('serial_import_batches', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->string('context_type', 24);
            $table->unsignedBigInteger('context_id');
            $table->string('file_name', 255);
            $table->char('file_hash', 64);
            $table->string('disk_path', 255)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->string('format', 8);
            $table->string('encoding_detected', 20)->nullable();
            $table->string('delimiter_detected', 4)->nullable();
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_ok')->default(0);
            $table->unsignedInteger('rows_warning')->default(0);
            $table->unsignedInteger('rows_error')->default(0);
            $table->string('mode', 16)->default('all_or_nothing');
            $table->string('status', 12);
            $table->string('committed_key', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('committed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique('committed_key');
            $table->unique('uuid');
            $table->index(['context_type', 'context_id']);
        });

        Schema::create('serial_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('serial_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_no');
            $table->string('serial_raw', 120)->nullable();
            $table->string('serial_normalized', 64)->nullable();
            $table->date('warranty_until')->nullable();
            $table->string('note', 255)->nullable();
            $table->string('result', 24);
            $table->string('message', 255)->nullable();
            $table->boolean('is_damaged')->default(false);
            $table->index(['batch_id', 'row_no']);
        });

        Schema::create('label_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->decimal('width_mm', 6, 2);
            $table->decimal('height_mm', 6, 2);
            $table->json('elements');
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('is_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_templates');
        Schema::dropIfExists('serial_import_rows');
        Schema::dropIfExists('serial_import_batches');
        Schema::dropIfExists('serial_events');
        Schema::dropIfExists('item_serials');
        Schema::dropIfExists('item_stocks');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('item_images');
        Schema::dropIfExists('item_alternatives');
        Schema::dropIfExists('items');
        if (DB::getDriverName() === 'sqlite' && Schema::hasTable('categories')) {
            DB::table('categories')->update(['parent_id' => null]);
        }
        Schema::dropIfExists('categories');
        Schema::dropIfExists('units');
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
