<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 04 section 6 / Spec 05 Q-10: `offers.lead_id` and `orders.lead_id` (plain indexed columns
     * until now, null on every row) get their foreign keys to `leads`.
     */
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
        });

        Schema::table('offers', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
        });
    }
};
