<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 03 7.2 (T-1, DEC-12): suppliers and carriers are counterparties, separate from customers.
     * Contacts, qualification documents with expiry (files as attachments) and ratings.
     */
    public function up(): void
    {
        Schema::create('counterparties', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('tax_id', 20)->nullable()->index();
            $table->char('country', 2)->nullable();
            $table->string('city', 120)->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->boolean('is_supplier')->default(false);
            $table->boolean('is_carrier')->default(false);
            $table->string('status', 24)->default('prospect');
            $table->text('status_reason')->nullable();
            $table->decimal('rating', 4, 2)->nullable();
            $table->unsignedInteger('fleet_size')->nullable();
            $table->json('vehicle_types')->nullable();
            $table->json('lanes')->nullable();
            $table->boolean('has_tir')->default(false);
            $table->boolean('has_adr')->default(false);
            $table->string('preferred_locale', 5)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('qualified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('qualified_at')->nullable();
            $table->text('notes')->nullable();
            // Spec 02 sync columns: null until 1C sends counterparties (roadmap, pull-only).
            $table->char('external_id', 36)->nullable()->unique();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_supplier', 'status']);
            $table->index(['is_carrier', 'status']);
            $table->index('name');
        });

        Schema::create('counterparty_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('counterparty_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('position', 120)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('telegram', 64)->nullable();
            $table->string('preferred_locale', 5)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('counterparty_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('counterparty_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('number', 60)->nullable();
            $table->date('valid_until')->nullable()->index();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('counterparty_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('counterparty_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->boolean('on_time')->nullable();
            $table->unsignedTinyInteger('quality');
            $table->unsignedTinyInteger('communication');
            $table->unsignedTinyInteger('documents');
            $table->text('comment')->nullable();
            $table->foreignId('rated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['counterparty_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counterparty_ratings');
        Schema::dropIfExists('counterparty_documents');
        Schema::dropIfExists('counterparty_contacts');
        Schema::dropIfExists('counterparties');
    }
};
