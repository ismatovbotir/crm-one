<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 03 7.1 (O-4): one Telegram chat per user and one user per chat, single-use link tokens
     * (sha256, 10 minutes) and processed webhook update ids (dedupe, pruned after 7 days).
     * No message contents are stored.
     */
    public function up(): void
    {
        Schema::create('telegram_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->bigInteger('chat_id')->unique();
            $table->bigInteger('telegram_user_id');
            $table->string('username', 64)->nullable()->index();
            $table->string('first_name', 120)->nullable();
            $table->string('phone', 20)->nullable()->index();
            $table->timestamp('linked_at');
            $table->timestamp('blocked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_link_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_updates', function (Blueprint $table) {
            $table->unsignedBigInteger('update_id')->primary();
            $table->timestamp('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_updates');
        Schema::dropIfExists('telegram_link_tokens');
        Schema::dropIfExists('telegram_links');
    }
};
