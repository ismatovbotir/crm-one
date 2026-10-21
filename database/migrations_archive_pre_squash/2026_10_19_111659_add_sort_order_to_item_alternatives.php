<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Item page "Analogues" (Spec 01 section 17): the order in which analogues are offered.
     */
    public function up(): void
    {
        Schema::table('item_alternatives', function (Blueprint $table) {
            $table->unsignedSmallInteger('sort_order')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('item_alternatives', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
