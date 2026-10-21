<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 7.15 / 9.2 (DEC-14): transactional outbox of CRM documents for 1C. 1C polls
     * `GET /outbox/pending?feed=` and acks; the CRM never calls 1C. `id` is the monotonic
     * sequence exposed to 1C. Order-feed events of customers without a 1C GUID wait as
     * `blocked_customer`.
     */
    public function up(): void
    {
        Schema::create('onec_outbox', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 32)->index();
            $table->string('aggregate_type', 16);
            $table->unsignedBigInteger('aggregate_id');
            $table->uuid('aggregate_uuid');
            $table->unsignedInteger('aggregate_version')->default(1);
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('feed', 10);
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->string('status', 16);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->timestamp('first_delivered_at')->nullable();
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamp('acked_at')->nullable();
            $table->string('ack_result', 16)->nullable();
            $table->json('ack_payload')->nullable();
            $table->char('onec_guid', 36)->nullable();
            $table->string('onec_number', 40)->nullable();
            $table->string('posting_status', 10)->nullable();
            $table->timestamp('posting_updated_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['feed', 'status', 'id']);
            $table->index(['aggregate_type', 'aggregate_id', 'status']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onec_outbox');
    }
};
