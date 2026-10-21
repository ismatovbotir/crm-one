<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 21.8: serial import batches (CSV/XLSX) with per-row preview results. Files live on
     * the private disk and are deleted after the retention period; metadata stays. `committed_key`
     * makes the same file commit only once per document line.
     */
    public function up(): void
    {
        Schema::create('serial_import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('context_type', 24);
            $table->unsignedBigInteger('context_id');
            $table->string('file_name');
            $table->char('file_hash', 64);
            $table->string('disk_path')->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->string('format', 8);
            $table->string('encoding_detected', 20)->nullable();
            $table->string('delimiter_detected', 4)->nullable();
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_ok')->default(0);
            $table->unsignedInteger('rows_warning')->default(0);
            $table->unsignedInteger('rows_error')->default(0);
            $table->string('mode', 16)->default('all_or_nothing');
            $table->string('status', 12);
            $table->string('committed_key', 120)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('committed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['context_type', 'context_id']);
        });

        Schema::create('serial_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('serial_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_no');
            $table->string('serial_raw', 120)->nullable();
            $table->string('serial_normalized', 64)->nullable();
            $table->date('warranty_until')->nullable();
            $table->string('note')->nullable();
            $table->string('result', 24);
            $table->string('message')->nullable();

            $table->index(['batch_id', 'row_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serial_import_rows');
        Schema::dropIfExists('serial_import_batches');
    }
};
