<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 02 BR-I.19: one row per integration call. Never bodies, personal data or tokens
     * (only the token prefix).
     */
    public function up(): void
    {
        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbound_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 10);
            $table->string('level', 10);
            $table->string('method', 10);
            $table->string('path');
            $table->unsignedSmallInteger('http_status');
            $table->unsignedInteger('duration_ms');
            $table->char('request_id', 36)->nullable()->index();
            $table->string('token_prefix', 12)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->string('message')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
    }
};
