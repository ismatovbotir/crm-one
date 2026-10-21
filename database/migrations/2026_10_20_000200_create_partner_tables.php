<?php

use App\Support\ReferenceSeed;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Squashed schema (2026-10-20), see docs/db-review-2026-10-19.md "Resolved by squash".
     * Partners (one organizations table with customer/supplier/carrier roles), contacts, bank accounts, supplier profiles, reference lists and geo places.
     */
    public function up(): void
    {
        Schema::create('reference_items', function (Blueprint $table) {
            $table->id();
            $table->string('list', 30);
            $table->string('code', 60);
            $table->json('names');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('source', 10)->default('admin');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['list', 'is_active', 'sort_order']);
            $table->unique(['list', 'code']);
        });

        Schema::create('geo_places', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10);
            $table->string('code', 80);
            $table->foreignId('parent_id')->nullable()->constrained('geo_places')->nullOnDelete();
            $table->char('country', 2)->default('UZ');
            $table->json('names');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique('code');
            $table->index(['kind', 'parent_id']);
        });

        ReferenceSeed::install();

        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->string('origin', 10)->default('crm');
            $table->string('name', 255);
            $table->string('name_normalized', 255);
            $table->string('legal_name', 255)->nullable();
            $table->string('legal_type', 20)->default('legal_entity');
            $table->string('tax_id', 32)->nullable();
            $table->char('country', 2)->default('UZ');
            $table->string('city', 120)->nullable();
            $table->text('address')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('industry', 120)->nullable();
            $table->char('currency', 3);
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('preferred_locale', 5)->nullable();
            $table->boolean('portal_enabled')->default(false);
            $table->bigInteger('portal_approval_threshold_minor')->nullable();
            $table->text('notes')->nullable();
            $table->string('sync_status', 15)->default('not_synced');
            $table->timestamp('sync_queued_at')->nullable();
            $table->string('sync_error', 255)->nullable();
            $table->json('sync_error_fields')->nullable();
            $table->unsignedSmallInteger('sync_attempts')->default(0);
            $this->ascii($table->char('merged_into_external_id', 36)->nullable());
            $table->boolean('has_duplicate_tax_id')->default(false);
            $this->ascii($table->char('external_id', 36)->nullable());
            $table->string('external_code', 50)->nullable();
            $table->string('external_version', 64)->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->char('external_hash', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('external_deleted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->string('crm_tax_key', 32)->nullable()->virtualAs('case when origin = \'crm\' and deleted_at is null and tax_id is not null then tax_id end');
            $table->string('vat_registration_code', 20)->nullable();
            $table->string('oked', 10)->nullable();
            $table->string('signatory_name', 255)->nullable();
            $table->string('signatory_position', 255)->nullable();
            $table->string('signatory_basis', 255)->nullable();
            $table->unsignedBigInteger('credit_limit_minor')->nullable();
            $table->char('credit_limit_currency', 3)->nullable();
            $table->boolean('manual_hold')->default(false);
            $table->string('manual_hold_reason', 255)->nullable();
            $table->string('sale_type', 10)->default('b2b');
            $table->boolean('is_customer')->default(true);
            $table->boolean('is_supplier')->default(false);
            $table->boolean('is_carrier')->default(false);
            $table->string('acquisition_source', 20)->nullable();
            $table->string('acquisition_campaign', 120)->nullable();
            $table->unsignedBigInteger('source_lead_id')->nullable();
            $table->boolean('telegram_service_updates')->default(true);
            $table->boolean('sync_with_1c')->default(false);
            $table->foreignId('industry_id')->nullable()->constrained('reference_items')->nullOnDelete();
            $table->foreignId('region_id')->nullable()->constrained('geo_places')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('geo_places')->nullOnDelete();
            $table->boolean('needs_review')->default(false);
            $table->string('review_reason', 60)->nullable();
            $table->index('needs_review');
            $table->unique('uuid');
            $table->index('tax_id');
            $table->index('sync_status');
            $table->index('source_lead_id');
            $table->index('phone');
            $table->index(['owner_id', 'name'], 'partners_owner_name_index');
            $table->index('name_normalized');
            $table->index('is_supplier');
            $table->index('is_customer');
            $table->index('is_carrier');
            $table->unique('external_id');
            $table->index('external_code');
            $table->index('email');
            $table->unique('crm_tax_key', 'partners_crm_tax_unique');
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->string('first_name', 120);
            $table->string('last_name', 120)->nullable();
            $table->string('position', 120)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 32)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();
            $this->ascii($table->char('external_id', 36)->nullable());
            $table->string('external_code', 50)->nullable();
            $table->string('external_version', 64)->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->char('external_hash', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('external_deleted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->boolean('is_billing_contact')->default(false);
            $table->boolean('is_signatory')->default(false);
            $table->string('telegram_username', 64)->nullable();
            $table->string('preferred_locale', 5)->nullable();
            $table->unsignedBigInteger('legacy_counterparty_contact_id')->nullable();
            $table->timestamp('portal_invite_requested_at')->nullable();
            $table->foreignId('portal_invite_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('primary_key')->nullable()->virtualAs('case when is_primary = 1 and deleted_at is null then partner_id end');
            $table->index(['partner_id', 'is_primary']);
            $table->unique('uuid');
            $table->index('phone');
            $table->unique('primary_key', 'contacts_one_primary_unique');
            $table->unique('legacy_counterparty_contact_id');
            $table->unique('external_id');
            $table->index('external_code');
            $table->index('email');
        });

        Schema::create('customer_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('partners')->restrictOnDelete();
            $table->string('bank_name', 255);
            $table->string('mfo', 5);
            $table->string('account_number', 20);
            $table->char('currency', 3);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->index(['customer_id', 'currency']);
        });

        Schema::create('counterparties', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->string('name', 255);
            $table->string('legal_name', 255)->nullable();
            $table->string('tax_id', 20)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('city', 120)->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('website', 255)->nullable();
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
            $this->ascii($table->char('external_id', 36)->nullable());
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('partner_id')->nullable()->constrained();
            $table->foreignId('supplier_category_id')->nullable()->constrained('reference_items')->nullOnDelete();
            $table->unique('uuid');
            $table->index('tax_id');
            $table->index('partner_id');
            $table->index('owner_id');
            $table->index('name');
            $table->index(['is_supplier', 'status']);
            $table->index(['is_carrier', 'status']);
            $table->unique('external_id');
            $table->index('department_id');
        });

        Schema::create('counterparty_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('counterparty_id')->constrained('counterparties')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('position', 120)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('telegram', 64)->nullable();
            $table->string('preferred_locale', 5)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->index('counterparty_id');
        });

        Schema::create('counterparty_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('counterparty_id')->constrained('counterparties')->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('number', 60)->nullable();
            $table->date('valid_until')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('counterparty_id');
            $table->index('valid_until');
        });

        Schema::create('counterparty_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('counterparty_id')->constrained('counterparties')->cascadeOnDelete();
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

        Schema::create('organization_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('partners');
            $table->foreignId('counterparty_id')->constrained('counterparties');
            $table->string('action', 20);
            $table->string('tax_id', 32)->nullable();
            $table->json('conflicts')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('counterparty_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_merges');
        Schema::dropIfExists('counterparty_ratings');
        Schema::dropIfExists('counterparty_documents');
        Schema::dropIfExists('counterparty_contacts');
        Schema::dropIfExists('counterparties');
        Schema::dropIfExists('customer_bank_accounts');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('partners');
        Schema::dropIfExists('geo_places');
        Schema::dropIfExists('reference_items');
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
