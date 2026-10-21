<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 section 26 (user decision 2026-10-19): supplier goods receipts originate in 1C.
     * 1C pushes the receipt (its document id, number and date, supplier by tax ID, warehouse,
     * expected lines); the CRM stock manager only completes it. `origin` = onec | crm (customer
     * returns, opening balances and serial registrations stay CRM-created; existing rows stay `crm`).
     */
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->string('origin', 10)->default('crm')->index();
            $table->string('external_id', 36)->nullable()->unique();
            $table->string('external_number', 50)->nullable();
            $table->date('external_date')->nullable();
            $table->foreignId('supplier_partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->char('external_hash', 64)->nullable();
        });

        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->string('external_line_id', 36)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->dropIndex(['external_line_id']);
        });
        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->dropColumn('external_line_id');
        });
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropForeign(['supplier_partner_id']);
            $table->dropUnique(['external_id']);
            $table->dropIndex(['origin']);
        });
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropColumn(['origin', 'external_id', 'external_number', 'external_date', 'supplier_partner_id', 'external_hash']);
        });
    }
};
