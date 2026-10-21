<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 03 section 22 (reverses DEC-12): the `customers` table becomes the organizations table
     * with role flags; `counterparties` becomes the supplier/carrier profile of an organization
     * (1:1, ids unchanged); supplier contacts move into `contacts`; portal memberships get the
     * organization; the merge log keeps every dedupe decision and conflict.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_customer')->default(true)->index();
            $table->boolean('is_supplier')->default(false)->index();
            $table->boolean('is_carrier')->default(false)->index();
        });

        Schema::table('counterparties', function (Blueprint $table) {
            // Indexed, not unique: legacy duplicate counterparties (same tax ID) share one organization
            // until merged by hand (Spec 03 D22-8); new profiles are 1:1 by application rule.
            $table->foreignId('organization_id')->nullable()->index()->constrained('customers');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('preferred_locale', 5)->nullable();
            $table->unsignedBigInteger('legacy_counterparty_contact_id')->nullable()->unique();
        });

        Schema::table('portal_memberships', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->index()->constrained('customers');
        });

        Schema::create('organization_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('customers');
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

        Schema::table('portal_memberships', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropIndex(['organization_id']);
        });

        Schema::table('portal_memberships', function (Blueprint $table) {
            $table->dropColumn('organization_id');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(['legacy_counterparty_contact_id']);
            $table->dropColumn(['preferred_locale', 'legacy_counterparty_contact_id']);
        });

        Schema::table('counterparties', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropIndex(['organization_id']);
        });

        Schema::table('counterparties', function (Blueprint $table) {
            $table->dropColumn('organization_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['is_customer']);
            $table->dropIndex(['is_supplier']);
            $table->dropIndex(['is_carrier']);
            $table->dropColumn(['is_customer', 'is_supplier', 'is_carrier']);
        });
    }
};
