<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 04 section 17 (Telegram groups): chats the bot is in (private, group, supergroup,
     * channel), the Telegram people it has seen (identified by Telegram user id, never by display
     * name), who is a member of which chat (only members the bot has actually seen), and a minimal
     * append-only message log (ids only; the text lives in the lead activity). A group can be linked
     * to one customer; a Telegram person to one contact (one company at a time). The phone is only
     * stored when the person shares their own contact.
     */
    public function up(): void
    {
        Schema::create('telegram_chats', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id')->unique();
            $table->string('type', 20);
            $table->string('title')->nullable();
            $table->string('username', 64)->nullable();
            $table->string('bot_status', 20)->default('member');
            $table->unsignedInteger('member_count')->nullable();
            $table->timestamp('members_refreshed_at')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_message_at')->nullable();
            $table->foreignId('customer_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->foreignId('merged_into_id')->nullable()->index()->constrained('telegram_chats')->nullOnDelete();
            $table->timestamps();
            $table->index(['type', 'bot_status']);
        });

        Schema::create('telegram_contacts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_user_id')->unique();
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
            $table->string('username', 64)->nullable()->index();
            $table->string('language_code', 10)->nullable();
            $table->boolean('is_bot')->default(false);
            $table->string('phone', 20)->nullable();
            $table->foreignId('user_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('telegram_chat_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_chat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_contact_id')->index()->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('member');
            $table->string('source', 20);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['telegram_chat_id', 'telegram_contact_id']);
        });

        Schema::create('telegram_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_chat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_contact_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->bigInteger('message_id');
            $table->string('kind', 20);
            $table->unsignedBigInteger('edit_date')->default(0);
            $table->foreignId('lead_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->timestamp('received_at');
            $table->unique(['telegram_chat_id', 'message_id', 'kind', 'edit_date']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('telegram_username', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('telegram_username');
        });

        Schema::dropIfExists('telegram_messages');
        Schema::dropIfExists('telegram_chat_members');
        Schema::dropIfExists('telegram_contacts');
        Schema::dropIfExists('telegram_chats');
    }
};
