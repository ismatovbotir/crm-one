<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 03 section 23 (internal Telegram notifications):
     *  - telegram_chats.purpose: customer (default, every existing chat) | internal_notifications;
     *  - telegram_destinations: company groups (optionally a forum topic) the bot posts to, with
     *    language, amounts switch and the per-event routing/content level (JSON);
     *  - telegram_posts: one row per event and destination (dedupe key), status and the hash of the
     *    rendered message only (never the text).
     */
    public function up(): void
    {
        Schema::table('telegram_chats', function (Blueprint $table) {
            $table->string('purpose', 30)->default('customer')->index();
        });

        Schema::create('telegram_destinations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->bigInteger('chat_id')->index();
            $table->unsignedInteger('message_thread_id')->nullable();
            $table->char('locale', 2)->default('uz');
            $table->boolean('include_amounts')->default(false);
            $table->json('events')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_error', 40)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('telegram_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_destination_id')->constrained()->cascadeOnDelete();
            $table->string('event', 60);
            $table->string('event_key', 190);
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->bigInteger('chat_id');
            $table->unsignedInteger('message_thread_id')->nullable();
            $table->string('status', 20)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('parts')->default(1);
            $table->char('message_hash', 64)->nullable();
            $table->string('error', 40)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['telegram_destination_id', 'event_key']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_posts');
        Schema::dropIfExists('telegram_destinations');

        Schema::table('telegram_chats', function (Blueprint $table) {
            $table->dropIndex(['purpose']);
        });

        Schema::table('telegram_chats', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
