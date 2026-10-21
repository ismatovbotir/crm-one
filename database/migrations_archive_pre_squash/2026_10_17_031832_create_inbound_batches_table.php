<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 02 section 11.4: every batch 1C sends (raw payload kept for audit/replay, pruned later),
     * with the stored response for idempotent replays.
     */
    public function up(): void
    {
        Schema::create('inbound_batches', function (Blueprint $table) {
            $table->id();
            $table->char('batch_id', 36)->unique();
            $table->foreignId('integration_token_id')->nullable()->constrained()->nullOnDelete();
            $table->string('endpoint', 20)->index();
            $table->string('mode', 10)->nullable();
            $table->char('snapshot_id', 36)->nullable()->index();
            $table->unsignedSmallInteger('snapshot_part')->nullable();
            $table->unsignedSmallInteger('snapshot_total_parts')->nullable();
            $table->string('status', 15)->index();
            $table->char('payload_hash', 64);
            $table->longText('payload')->nullable();
            $table->unsignedInteger('record_count')->default(0);
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->json('counts')->nullable();
            $table->json('rejected')->nullable();
            $table->json('errors')->nullable();
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body');
            $table->json('source')->nullable();
            $table->string('ip', 45)->nullable();
            $table->char('request_id', 36)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('reprocessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_batches');
    }
};
