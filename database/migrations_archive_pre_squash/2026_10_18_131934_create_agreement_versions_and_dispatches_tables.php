<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 section 7.8: the immutable rendered text per locale written when an agreement is sent
     * (sha256 = document fingerprint), and every dispatch (channel, recipient, viewed/downloaded).
     */
    public function up(): void
    {
        Schema::create('agreement_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('locale', 5);
            $table->longText('html');
            $table->char('sha256', 64);
            $table->timestamp('rendered_at');
            $table->foreignId('rendered_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unique(['agreement_id', 'version', 'locale']);
        });

        Schema::create('agreement_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('channel', 12);
            $table->foreignId('recipient_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_email')->nullable();
            $table->string('locale', 5);
            $table->char('share_token_hash', 64)->nullable()->unique();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('sent_at');
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['agreement_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreement_dispatches');
        Schema::dropIfExists('agreement_versions');
    }
};
