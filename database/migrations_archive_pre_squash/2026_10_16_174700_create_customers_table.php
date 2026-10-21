<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 01 section 6.3 + Spec 02 section 11.5. `tax_id` has a non-unique index: duplicates may
     * arrive from 1C; uniqueness for CRM-created customers is enforced by the application.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('origin', 10)->default('crm');
            $table->string('name');
            $table->string('name_normalized')->index();
            $table->string('legal_name')->nullable();
            $table->string('legal_type', 20)->default('legal_entity');
            $table->string('tax_id', 32)->nullable()->index();
            $table->char('country', 2)->default('UZ');
            $table->string('city', 120)->nullable();
            $table->text('address')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('phone', 32)->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('website')->nullable();
            $table->string('industry', 120)->nullable();
            $table->char('currency', 3);
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->foreignId('price_list_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('preferred_locale', 5)->nullable();
            $table->text('notes')->nullable();

            $table->string('sync_status', 15)->default('not_synced')->index();
            $table->string('sync_error')->nullable();
            $table->json('sync_error_fields')->nullable();
            $table->unsignedSmallInteger('sync_attempts')->default(0);
            $table->uuid('sync_idempotency_key')->nullable();
            $table->json('sync_conflict_payload')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->char('merged_into_external_id', 36)->nullable();
            $table->boolean('has_duplicate_tax_id')->default(false);

            $table->char('external_id', 36)->nullable()->unique();
            $table->string('external_code', 50)->nullable()->index();
            $table->string('external_version', 64)->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->char('external_hash', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('external_deleted_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
