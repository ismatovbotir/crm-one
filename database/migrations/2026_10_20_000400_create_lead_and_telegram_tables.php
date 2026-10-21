<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Squashed schema (2026-10-20), see docs/db-review-2026-10-19.md "Resolved by squash".
     * Leads and Telegram (bot chats, contacts, messages, user links, internal notification destinations).
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->string('number', 20);
            $table->string('status', 20);
            $table->string('source', 20);
            $table->string('campaign', 120)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('partners')->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('company_name', 255)->nullable();
            $table->string('tax_id', 20)->nullable();
            $table->string('industry', 120)->nullable();
            $table->string('contact_name', 255)->nullable();
            $table->string('contact_position', 120)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->text('need')->nullable();
            $table->unsignedBigInteger('est_value_minor')->nullable();
            $table->char('currency', 3);
            $table->date('expected_close_date')->nullable();
            $table->boolean('budget_confirmed')->default(false);
            $table->boolean('authority_confirmed')->default(false);
            $table->boolean('need_confirmed')->default(false);
            $table->boolean('timeline_confirmed')->default(false);
            $table->string('close_reason', 40)->nullable();
            $table->text('close_note')->nullable();
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('conversion_kind', 20)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('anonymised_at')->nullable();
            $table->foreignId('merged_into_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->bigInteger('telegram_chat_id')->nullable();
            $table->bigInteger('telegram_user_id')->nullable();
            $table->string('telegram_username', 64)->nullable();
            $table->string('locale', 5)->nullable();
            $table->string('priority', 10)->default('normal');
            $table->string('sale_type', 10)->nullable();
            $table->unsignedTinyInteger('score')->default(0);
            $table->text('duplicate_override_reason')->nullable();
            $table->index(['status', 'converted_at']);
            $table->index(['status', 'closed_at']);
            $table->unique('uuid');
            $table->index('telegram_chat_id');
            $table->index('tax_id');
            $table->index(['status', 'last_activity_at']);
            $table->index(['owner_id', 'status']);
            $table->unique('number');
            $table->index('customer_id');
            $table->index('contact_phone');
            $table->index('contact_id');
            $table->index('contact_email');
        });

        Schema::create('lead_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 3)->default(1);
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->index('item_id');
            $table->unique(['lead_id', 'item_id']);
        });

        Schema::create('lead_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at');
            $table->timestamp('remind_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('lead_id');
            $table->index(['assignee_id', 'completed_at', 'due_at']);
            $table->index(['completed_at', 'remind_at']);
        });

        Schema::create('lead_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('payload');
            $table->timestamps();
            $table->unique('user_id');
        });

        Schema::create('telegram_chats', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id');
            $table->string('type', 20);
            $table->string('title', 255)->nullable();
            $table->string('username', 64)->nullable();
            $table->string('bot_status', 20)->default('member');
            $table->unsignedInteger('member_count')->nullable();
            $table->timestamp('members_refreshed_at')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_message_at')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('merged_into_id')->nullable()->constrained('telegram_chats')->nullOnDelete();
            $table->timestamps();
            $table->string('purpose', 30)->default('customer');
            $table->index('purpose');
            $table->index('merged_into_id');
            $table->index('lead_id');
            $table->index('customer_id');
            $table->unique('chat_id');
            $table->index(['type', 'bot_status']);
        });

        Schema::create('telegram_contacts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_user_id');
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
            $table->string('username', 64)->nullable();
            $table->string('language_code', 10)->nullable();
            $table->boolean('is_bot')->default(false);
            $table->string('phone', 20)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index('contact_id');
            $table->index('user_id');
            $table->index('username');
            $table->unique('telegram_user_id');
        });

        Schema::create('telegram_chat_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_chat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_contact_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('member');
            $table->string('source', 20);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->index('telegram_contact_id');
            $table->unique(['telegram_chat_id', 'telegram_contact_id'], 'tg_chat_members_chat_contact_unique');
        });

        Schema::create('telegram_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_chat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_contact_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('message_id');
            $table->string('kind', 20);
            $table->unsignedBigInteger('edit_date')->default(0);
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('received_at');
            $table->index('lead_id');
            $table->index('telegram_contact_id');
            $table->unique(['telegram_chat_id', 'message_id', 'kind', 'edit_date'], 'tg_messages_chat_msg_kind_edit_unique');
        });

        Schema::create('telegram_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('chat_id');
            $table->bigInteger('telegram_user_id');
            $table->string('username', 64)->nullable();
            $table->string('first_name', 120)->nullable();
            $table->string('phone', 20)->nullable();
            $table->timestamp('linked_at');
            $table->timestamp('blocked_at')->nullable();
            $table->timestamps();
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_method', 20)->nullable();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('verify_code_hash', 64)->nullable();
            $table->timestamp('verify_code_expires_at')->nullable();
            $table->unsignedTinyInteger('verify_attempts')->default(0);
            $table->string('force_reason', 500)->nullable();
            $table->index('username');
            $table->unique('user_id');
            $table->index('phone');
            $table->unique('chat_id');
        });

        Schema::create('telegram_link_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
            $table->unique('token_hash');
        });

        Schema::create('telegram_updates', function (Blueprint $table) {
            $table->unsignedBigInteger('update_id');
            $table->timestamp('received_at');
            $table->primary('update_id');
        });

        Schema::create('telegram_destinations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->bigInteger('chat_id');
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
            $table->index('chat_id');
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
            $table->index(['status', 'created_at']);
            $table->unique(['telegram_destination_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_posts');
        Schema::dropIfExists('telegram_destinations');
        Schema::dropIfExists('telegram_updates');
        Schema::dropIfExists('telegram_link_tokens');
        Schema::dropIfExists('telegram_links');
        Schema::dropIfExists('telegram_messages');
        Schema::dropIfExists('telegram_chat_members');
        Schema::dropIfExists('telegram_contacts');
        Schema::dropIfExists('telegram_chats');
        Schema::dropIfExists('lead_drafts');
        Schema::dropIfExists('lead_follow_ups');
        Schema::dropIfExists('lead_items');
        Schema::dropIfExists('leads');
    }
};
