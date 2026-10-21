<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 01 section 6.8 + Spec 02 A4: one price list per 1C price type; `is_default` is CRM-local.
     */
    public function up(): void
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
    }

    public function down(): void
    {
        Schema::dropIfExists('price_lists');
    }
};
