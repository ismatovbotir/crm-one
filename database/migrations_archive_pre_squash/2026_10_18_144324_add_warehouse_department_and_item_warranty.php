<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 21.2: department `warehouse` (head = warehouse head, specialist = stock manager,
     * viewer) for existing installs (new installs seed it with the standard departments), and the
     * CRM-local item warranty in months used for `warranty_until` of sold serials.
     */
    public function up(): void
    {
        DB::table('departments')->insertOrIgnore([
            'code' => 'warehouse',
            'name' => null,
            'is_active' => true,
            'sort_order' => 80,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('items', function (Blueprint $table) {
            $table->unsignedSmallInteger('warranty_months')->nullable()->after('is_serialized');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('warranty_months');
        });
    }
};
