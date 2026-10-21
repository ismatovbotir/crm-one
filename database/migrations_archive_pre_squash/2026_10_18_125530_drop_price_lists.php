<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pricing simplification (Spec 01/02 rev. 4): price lists, dated/tiered price rows and the
     * customer price list are gone (sale prices were backfilled onto items by the previous
     * migration). Columns referencing price_lists go first, then the tables. down() recreates the
     * empty structure only (data is not restored).
     */
    public function up(): void
    {
        foreach (['offer_lines', 'offers', 'customers'] as $tableName) {
            if (Schema::hasColumn($tableName, 'price_list_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('price_list_id');
                });
            }
        }

        if (Schema::hasColumn('offer_lines', 'price_list_external_id')) {
            Schema::table('offer_lines', function (Blueprint $table) {
                $table->dropColumn('price_list_external_id');
            });
        }

        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
    }

    public function down(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->char('currency', 3)->index();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('prices_include_vat')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
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
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->decimal('min_qty', 15, 3)->default(0);
            $table->unsignedBigInteger('price_minor');
            $table->date('valid_from')->default('1970-01-01');
            $table->timestamp('external_updated_at')->nullable();
            $table->char('last_seen_snapshot_id', 36)->nullable();
            $table->timestamps();
            $table->unique(['price_list_id', 'item_id', 'min_qty', 'valid_from']);
            $table->index(['item_id', 'price_list_id', 'valid_from']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('price_list_id')->nullable()->after('payment_terms_days')->constrained()->nullOnDelete();
        });

    }
};
