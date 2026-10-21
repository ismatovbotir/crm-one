<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 04 section 18 (lead → customer conversion) and Spec 09 section 14 (Telegram group updates):
     *  - leads: who converted and how (created | linked | role_added; null for the offer hand-off);
     *  - customers: acquisition source/campaign and the lead it came from (kept, never overwritten),
     *    and the per-customer switch for service ticket updates in its Telegram groups;
     *  - contacts: a pending portal invitation (nothing is sent until staff grant access).
     * Additive and nullable/defaulted only; existing rows are untouched.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('converted_by')->nullable()->after('converted_at')->constrained('users')->nullOnDelete();
            $table->string('conversion_kind', 20)->nullable()->after('converted_by');
            $table->index(['status', 'converted_at']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('acquisition_source', 20)->nullable();
            $table->string('acquisition_campaign', 120)->nullable();
            $table->foreignId('source_lead_id')->nullable()->index()->constrained('leads')->nullOnDelete();
            $table->boolean('telegram_service_updates')->default(true);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('portal_invite_requested_at')->nullable();
            $table->foreignId('portal_invite_requested_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign(['portal_invite_requested_by']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['portal_invite_requested_by', 'portal_invite_requested_at']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['source_lead_id']);
            $table->dropIndex(['source_lead_id']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['source_lead_id', 'acquisition_source', 'acquisition_campaign', 'telegram_service_updates']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['converted_by']);
            $table->dropIndex(['status', 'converted_at']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['converted_by', 'conversion_kind']);
        });
    }
};
