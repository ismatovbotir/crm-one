<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 21.5: serial rows of stock document lines (the registration grid). `active_key` is
     * unique while the row is draft/posted, so one serial appears once per document across lines;
     * posted rows are immutable (model guard).
     */
    public function up(): void
    {
        Schema::create('document_line_serials', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 24);
            $table->unsignedBigInteger('document_id');
            $table->string('line_type', 24);
            $table->unsignedBigInteger('line_id');
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_serial_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('serial_number', 64);
            $table->string('serial_raw', 100);
            $table->string('status', 10);
            $table->foreignId('replaced_by_id')->nullable()->constrained('document_line_serials')->nullOnDelete();
            $table->string('active_key', 150)->nullable()->unique();
            $table->unsignedInteger('position')->default(1);
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['line_type', 'line_id']);
            $table->index(['document_type', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_line_serials');
    }
};
