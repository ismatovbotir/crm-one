<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Squashed schema (2026-10-20), see docs/db-review-2026-10-19.md "Resolved by squash".
     * 1C integration: tokens, feeds, request log, inbound batches/payloads/snapshots, customer and document outboxes.
     */
    public function up(): void
    {
        Schema::create('integration_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('token_prefix', 12);
            $table->char('token_hash', 64);
            $table->json('abilities');
            $table->json('allowed_ips')->nullable();
            $table->text('signing_secret')->nullable();
            $table->timestamp('expires_at')->useCurrent();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('token_hash');
            $table->index('token_prefix');
        });

        Schema::create('integration_feeds', function (Blueprint $table) {
            $table->string('feed', 20);
            $table->timestamp('last_received_at')->nullable();
            $this->ascii($table->char('last_batch_id', 36)->nullable());
            $table->timestamp('last_full_snapshot_at')->nullable();
            $table->string('cursor', 255)->nullable();
            $table->timestamps();
            $table->primary('feed');
        });

        Schema::create('inbound_batches', function (Blueprint $table) {
            $table->id();
            $this->ascii($table->char('batch_id', 36));
            $table->foreignId('integration_token_id')->nullable()->constrained()->nullOnDelete();
            $table->string('endpoint', 20);
            $table->string('mode', 10)->nullable();
            $this->ascii($table->char('snapshot_id', 36)->nullable());
            $table->unsignedSmallInteger('snapshot_part')->nullable();
            $table->unsignedSmallInteger('snapshot_total_parts')->nullable();
            $table->string('status', 15);
            $table->char('payload_hash', 64);
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
            $this->ascii($table->char('request_id', 36)->nullable());
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('reprocessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('received_at');
            $table->index('snapshot_id');
            $table->unique('batch_id');
            $table->index(['status', 'received_at']);
        });

        Schema::create('inbound_batch_payloads', function (Blueprint $table) {
            $table->foreignId('inbound_batch_id')->constrained('inbound_batches')->cascadeOnDelete();
            $table->longText('payload');
            $table->timestamp('created_at')->useCurrent();
            $table->primary('inbound_batch_id');
            $table->index('created_at');
        });

        Schema::create('inbound_snapshots', function (Blueprint $table) {
            $this->ascii($table->char('id', 36));
            $table->string('endpoint', 20);
            $table->json('scope')->nullable();
            $table->unsignedSmallInteger('total_parts');
            $table->json('parts_processed')->nullable();
            $table->string('status', 15);
            $table->json('result')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->primary('id');
            $table->index('finalized_at');
            $table->index('status');
        });

        Schema::create('customer_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('partners')->restrictOnDelete();
            $table->string('reason', 20);
            $table->foreignId('queued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('queued_at')->useCurrent();
            $table->timestamp('first_delivered_at')->nullable();
            $table->timestamp('last_delivered_at')->nullable();
            $table->unsignedInteger('delivery_count')->default(0);
            $table->timestamp('acked_at')->nullable();
            $table->string('ack_status', 10)->nullable();
            $this->ascii($table->char('ack_external_id', 36)->nullable());
            $table->json('ack_error')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->string('superseded_reason', 30)->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'id']);
            $table->index(['acked_at', 'superseded_at', 'id']);
        });

        Schema::create('onec_outbox', function (Blueprint $table) {
            $table->id();
            $this->ascii($table->char('event_id', 36));
            $table->string('event_type', 32);
            $table->string('aggregate_type', 16);
            $table->unsignedBigInteger('aggregate_id');
            $table->char('aggregate_uuid', 36);
            $table->unsignedInteger('aggregate_version')->default(1);
            $table->foreignId('customer_id')->nullable()->constrained('partners')->restrictOnDelete();
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
            $this->ascii($table->char('onec_guid', 36)->nullable());
            $table->string('onec_number', 40)->nullable();
            $table->string('posting_status', 10)->nullable();
            $table->timestamp('posting_updated_at')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->nullable();
            $table->index('event_type');
            $table->unique('event_id');
            $table->index(['customer_id', 'status']);
            $table->index(['aggregate_type', 'aggregate_id', 'status']);
            $table->index(['feed', 'status', 'id']);
        });

        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbound_batch_id')->nullable()->constrained('inbound_batches')->nullOnDelete();
            $table->string('direction', 10);
            $table->string('level', 10);
            $table->string('method', 10);
            $table->string('path', 255);
            $table->unsignedSmallInteger('http_status');
            $table->unsignedInteger('duration_ms');
            $this->ascii($table->char('request_id', 36)->nullable());
            $table->string('token_prefix', 12)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->string('message', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('inbound_batch_id');
            $table->index('created_at');
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
        Schema::dropIfExists('onec_outbox');
        Schema::dropIfExists('customer_outbox');
        Schema::dropIfExists('inbound_snapshots');
        Schema::dropIfExists('inbound_batch_payloads');
        Schema::dropIfExists('inbound_batches');
        Schema::dropIfExists('integration_feeds');
        Schema::dropIfExists('integration_tokens');
    }

    /**
     * 1C identifiers (GUIDs, document/line ids) are ASCII: 1 byte per character in MySQL indexes
     * instead of 4. Case-insensitive like before (ascii_general_ci). SQLite has no charsets.
     */
    private function ascii(ColumnDefinition $column): ColumnDefinition
    {
        return DB::getDriverName() === 'mysql' ? $column->charset('ascii')->collation('ascii_general_ci') : $column;
    }
};
