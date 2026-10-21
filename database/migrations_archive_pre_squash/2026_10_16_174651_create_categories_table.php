<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 01 section 6.6 + Spec 02: item groups mirrored from 1C; `sort_order` is CRM-local.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name', 120);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->char('external_id', 36)->unique();
            $table->string('external_code', 50)->nullable()->index();
            $table->string('external_version', 64)->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->char('external_hash', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('external_deleted_at')->nullable();
            $table->char('last_seen_snapshot_id', 36)->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
