<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 section 26: a 1C goods receipt may create a missing supplier (by tax ID) or item
     * (by 1C key); such records are flagged for review until a person completes them.
     */
    public function up(): void
    {
        foreach (['partners', 'items'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->boolean('needs_review')->default(false)->index();
                $blueprint->string('review_reason', 60)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['partners', 'items'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropIndex(['needs_review']);
            });
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn(['needs_review', 'review_reason']);
            });
        }
    }
};
