<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DB review 2026-10 F-20 / F-21: drop the customer sync columns unused since Spec 02 rev. 3
     * (the CRM never pushes to 1C) and make the customer outbox audit trail restrict instead of
     * cascading on a hard customer delete.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['sync_idempotency_key', 'sync_conflict_payload', 'last_pushed_at']);
        });

        Schema::table('customer_outbox', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_outbox', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->uuid('sync_idempotency_key')->nullable();
            $table->json('sync_conflict_payload')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
        });
    }
};
