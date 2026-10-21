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
     * Core: users, auth, sessions, cache, queue, departments, settings, numbering, workflow and audit log, notifications, attachments, company details.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10)->default('internal');
            $table->string('name', 255);
            $table->string('email', 255);
            $table->string('phone', 32)->nullable();
            $table->string('position', 120)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password', 255)->nullable();
            $table->string('role', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('locale', 5)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('remember_token', 100)->nullable();
            $table->timestamps();
            $table->json('notification_preferences')->nullable();
            // Spec 04 section 16 (2026-10-20): per-user UI choices, e.g. the lead form layout.
            $table->json('ui_preferences')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->unsignedBigInteger('two_factor_last_step')->nullable();
            $table->index('phone');
            $table->index(['type', 'is_active']);
            $table->index('role');
            $table->unique('email');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 255);
            $table->string('token', 255);
            $table->timestamp('created_at')->nullable();
            $table->primary('email');
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id', 255);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity');
            $table->primary('id');
            $table->index('last_activity');
            $table->index('user_id');
        });

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key', 255);
            $table->mediumText('value');
            $table->bigInteger('expiration');
            $table->primary('key');
            $table->index('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key', 255);
            $table->string('owner', 255);
            $table->bigInteger('expiration');
            $table->primary('key');
            $table->index('expiration');
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue', 255);
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
            $table->index('queue');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id', 255);
            $table->string('name', 255);
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
            $table->primary('id');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 255);
            $table->string('connection', 255);
            $table->string('queue', 255);
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
            $table->unique('uuid');
            $table->index(['connection', 'queue', 'failed_at']);
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40);
            $table->json('name')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index('parent_id');
            $table->unique('code');
        });

        $now = now();
        foreach (['sales', 'purchase', 'logistics', 'finance', 'management', 'it', 'support', 'warehouse', 'service'] as $order => $code) {
            DB::table('departments')->insert(['code' => $code, 'name' => null, 'is_active' => true, 'sort_order' => ($order + 1) * 10, 'created_at' => $now, 'updated_at' => $now]);
        }

        Schema::create('department_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'is_primary']);
            $table->unique(['department_id', 'user_id']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 255);
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('key');
        });

        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('key', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_value')->default(0);
            $table->timestamps();
            $table->unique(['key', 'year']);
        });

        Schema::create('status_transitions', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id', 'created_at']);
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('causer_id');
            $table->index('created_at');
            $table->index(['subject_type', 'subject_id', 'created_at']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->char('id', 36);
            $table->string('type', 255);
            $table->string('notifiable_type', 255);
            $table->unsignedBigInteger('notifiable_id');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->primary('id');
            $table->index(['notifiable_type', 'notifiable_id']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->string('attachable_type', 40);
            $table->unsignedBigInteger('attachable_id');
            $table->string('purpose', 30);
            $table->string('disk', 20);
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('sha256');
            $table->unique('path');
            $table->unique('uuid');
            $table->index(['attachable_type', 'attachable_id']);
        });

        Schema::create('auth_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guard', 10);
            $table->string('event', 40);
            $table->string('channel', 10)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->char('identifier_hash', 64)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['event', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('login_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tender_invitation_link_id')->nullable();
            $table->string('purpose', 20);
            $table->string('channel', 10);
            $table->char('code_hash', 64);
            $table->timestamp('expires_at')->useCurrent();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
            $table->index(['tender_invitation_link_id', 'purpose']);
            $table->index(['user_id', 'purpose', 'consumed_at']);
        });

        Schema::create('company_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name', 255);
            $table->string('mfo', 5);
            $table->string('account_number', 20);
            $table->char('currency', 3);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $this->ascii($table->char('onec_guid', 36)->nullable());
            $table->timestamps();
            $table->unique('onec_guid');
            $table->index(['currency', 'is_default']);
        });

        Schema::create('company_signatories', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 255);
            $table->string('position', 255);
            $table->string('basis', 255)->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_signatories');
        Schema::dropIfExists('company_bank_accounts');
        Schema::dropIfExists('login_codes');
        Schema::dropIfExists('auth_events');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('status_transitions');
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('department_user');
        if (DB::getDriverName() === 'sqlite' && Schema::hasTable('departments')) {
            DB::table('departments')->update(['parent_id' => null]);
        }
        Schema::dropIfExists('departments');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
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
