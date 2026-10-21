<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 03 7.1/7.2 (O-3, O-6): portal memberships (user ↔ customer with portal roles; Phase 1 one
     * active membership per user, app-enforced), customer portal switches, append-only
     * authentication events (identifier failures stored only as a hash).
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('portal_enabled')->default(false)->after('preferred_locale');
            $table->bigInteger('portal_approval_threshold_minor')->nullable()->after('portal_enabled');
        });

        Schema::create('portal_memberships', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('counterparty_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->json('roles');
            $table->bigInteger('approval_limit_minor')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'customer_id']);
            $table->unique(['user_id', 'counterparty_id']);
            $table->index(['customer_id', 'is_active']);
        });

        Schema::create('auth_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guard', 10);
            $table->string('event', 24);
            $table->string('channel', 10)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->char('identifier_hash', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_events');
        Schema::dropIfExists('portal_memberships');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['portal_enabled', 'portal_approval_threshold_minor']);
        });
    }
};
