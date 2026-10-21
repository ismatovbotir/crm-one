<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 section 26.4 (2026-10-19): damage is recorded per serial on a goods receipt; only
     * flagged serials are quarantined. Imported files may carry an optional `damaged` column.
     */
    public function up(): void
    {
        Schema::table('document_line_serials', function (Blueprint $table) {
            $table->boolean('is_damaged')->default(false);
            $table->string('damage_note', 250)->nullable();
        });

        Schema::table('serial_import_rows', function (Blueprint $table) {
            $table->boolean('is_damaged')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('serial_import_rows', function (Blueprint $table) {
            $table->dropColumn('is_damaged');
        });

        Schema::table('document_line_serials', function (Blueprint $table) {
            $table->dropColumn(['is_damaged', 'damage_note']);
        });
    }
};
