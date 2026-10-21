<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Squashed schema (2026-10-20): foreign keys between domains that point "forward" to a table
     * created by a later file (or form a cycle, e.g. partners.source_lead_id <-> leads.customer_id).
     */
    public function up(): void
    {
        Schema::table('login_codes', function (Blueprint $table) {
            $table->foreign('tender_invitation_link_id')->references('id')->on('tender_invitation_links')->cascadeOnDelete();
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->foreign('source_lead_id')->references('id')->on('leads')->nullOnDelete();
        });

        Schema::table('item_serials', function (Blueprint $table) {
            $table->foreign('sold_order_line_id')->references('id')->on('order_lines')->restrictOnDelete();
        });

        Schema::table('offers', function (Blueprint $table) {
            $table->foreign('requisition_id')->references('id')->on('requisitions');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('framework_agreement_id')->references('id')->on('agreements')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['framework_agreement_id']);
        });

        Schema::table('offers', function (Blueprint $table) {
            $table->dropForeign(['requisition_id']);
        });

        Schema::table('item_serials', function (Blueprint $table) {
            $table->dropForeign(['sold_order_line_id']);
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->dropForeign(['source_lead_id']);
        });

        Schema::table('login_codes', function (Blueprint $table) {
            $table->dropForeign(['tender_invitation_link_id']);
        });
    }
};
