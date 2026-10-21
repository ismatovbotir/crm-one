<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 02 rev. 3 (section 10, BR-I.7/I.8): 1C is on a private network and is never called by
     * the CRM. CRM customers waiting for 1C sit in this outbox; 1C polls it (the id is the cursor)
     * and acknowledges each entry. Rows are never deleted (audit).
     */
    public function up(): void
    {
        Schema::create('customer_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 20);
            $table->foreignId('queued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('queued_at');
            $table->timestamp('first_delivered_at')->nullable();
            $table->timestamp('last_delivered_at')->nullable();
            $table->unsignedInteger('delivery_count')->default(0);
            $table->timestamp('acked_at')->nullable();
            $table->string('ack_status', 10)->nullable();
            $table->char('ack_external_id', 36)->nullable();
            $table->json('ack_error')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->string('superseded_reason', 30)->nullable();
            $table->timestamps();

            $table->index(['acked_at', 'superseded_at', 'id']);
            $table->index(['customer_id', 'id']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('sync_queued_at')->nullable()->after('sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('sync_queued_at');
        });

        Schema::dropIfExists('customer_outbox');
    }
};
