<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 section 29 (user decision 2026-10-19): the simple "payment for orders" form records
     * one payment per ticked order. A payment that waits for the second person's approval keeps
     * the order it is meant for and is allocated to it automatically when approved.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('planned_order_id')->nullable()->constrained('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['planned_order_id']);
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('planned_order_id');
        });
    }
};
