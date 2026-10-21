<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 08 8.2 / 10.2 (08-4): unique bid links (only sha256 of the 256-bit token stored, one
     * active per invitation), append-only link events (no amounts, IP hashed), one-time codes
     * (Spec 03 `login_codes`, generalised: a user or a bid link; purpose; only the code hash), and
     * the link channel columns on bids (idempotent `submission_uuid`).
     */
    public function up(): void
    {
        Schema::table('tenders', function (Blueprint $table) {
            $table->boolean('link_verification_required')->default(true)->after('confidential');
            $table->string('decision_level', 10)->nullable()->after('acknowledged_warnings');
        });

        Schema::create('tender_invitation_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_invitation_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sent_via', 10)->nullable();
            $table->string('sent_to')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason')->nullable();
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->unsignedSmallInteger('distinct_ip_count')->default(0);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['tender_invitation_id', 'revoked_at']);
        });

        Schema::create('tender_link_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained('tender_invitation_links')->cascadeOnDelete();
            $table->string('event', 20);
            $table->char('ip_hash', 64)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['link_id', 'event']);
        });

        Schema::create('login_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('tender_invitation_link_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('purpose', 20);
            $table->string('channel', 10);
            $table->char('code_hash', 64);
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purpose', 'consumed_at']);
            $table->index(['tender_invitation_link_id', 'purpose']);
        });

        Schema::table('tender_bids', function (Blueprint $table) {
            $table->foreignId('link_id')->nullable()->after('channel')->constrained('tender_invitation_links')->nullOnDelete();
            $table->uuid('submission_uuid')->nullable()->unique()->after('link_id');
        });
    }

    public function down(): void
    {
        Schema::table('tender_bids', function (Blueprint $table) {
            $table->dropConstrainedForeignId('link_id');
            $table->dropUnique(['submission_uuid']);
            $table->dropColumn('submission_uuid');
        });

        Schema::dropIfExists('login_codes');
        Schema::dropIfExists('tender_link_events');
        Schema::dropIfExists('tender_invitation_links');

        Schema::table('tenders', function (Blueprint $table) {
            $table->dropColumn(['link_verification_required', 'decision_level']);
        });
    }
};
