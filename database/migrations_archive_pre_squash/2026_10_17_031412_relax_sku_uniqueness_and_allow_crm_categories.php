<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 02 BR-I.1 / BR-I.13: 1C owns the item code, so `items.sku` is a plain (non-unique)
     * index; identity is `external_id` (1C GUID, still unique). Duplicate codes are shown as an
     * informational warning only.
     *
     * Categories: `external_id` becomes nullable so a CRM-created category (no 1C GUID) can exist;
     * the 3-level depth limit applies only to those, 1C groups mirror any depth.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropUnique(['sku']);
            $table->index('sku');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->char('external_id', 36)->nullable()->change();
        });
    }

    /**
     * Fails if duplicate SKUs or CRM-created categories exist by then (honest rollback).
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->char('external_id', 36)->nullable(false)->change();
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['sku']);
            $table->unique('sku');
        });
    }
};
