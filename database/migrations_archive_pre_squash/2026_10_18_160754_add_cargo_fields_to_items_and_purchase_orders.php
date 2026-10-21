<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 08 10.1 (08-1): CRM-local cargo master data on items (HS code ≠ class_code, DEC-8;
     * weights/volume per base unit), manual purchase orders with pickup point and readiness, and
     * cargo data on PO lines (prefilled from the item master, editable from the packing list).
     * Pickup points are stored on the PO (counterparty_locations deferred).
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('hs_code', 10)->nullable()->after('package_code')->index();
            $table->decimal('gross_weight_kg', 12, 4)->nullable()->after('hs_code');
            $table->decimal('net_weight_kg', 12, 4)->nullable()->after('gross_weight_kg');
            $table->decimal('volume_m3', 12, 6)->nullable()->after('net_weight_kg');
            $table->char('country_of_origin', 2)->nullable()->after('volume_m3');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('supplier_order_ref', 60)->nullable()->after('supplier_id');
            $table->char('pickup_country', 2)->nullable()->after('incoterm_place');
            $table->string('pickup_city', 120)->nullable()->after('pickup_country');
            $table->text('pickup_address')->nullable()->after('pickup_city');
            $table->json('documents_available')->nullable()->after('payment_terms');
            $table->text('special_handling')->nullable()->after('documents_available');
            $table->index('readiness_date');
        });

        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->char('country_of_origin', 2)->nullable()->after('hs_code');
            $table->decimal('gross_weight_kg', 12, 3)->nullable()->after('country_of_origin');
            $table->decimal('net_weight_kg', 12, 3)->nullable()->after('gross_weight_kg');
            $table->decimal('volume_m3', 12, 3)->nullable()->after('net_weight_kg');
            $table->unsignedInteger('packages_count')->nullable()->after('volume_m3');
            $table->string('package_type', 30)->nullable()->after('packages_count');
            $table->string('weight_source', 14)->default('master')->after('package_type');
        });

        Schema::table('tender_transport_specs', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->after('lot_id')->constrained()->nullOnDelete();
            $table->json('prefilled_from')->nullable()->after('site_contacts');
        });
    }

    public function down(): void
    {
        Schema::table('tender_transport_specs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
            $table->dropColumn('prefilled_from');
        });

        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->dropColumn(['country_of_origin', 'gross_weight_kg', 'net_weight_kg', 'volume_m3', 'packages_count', 'package_type', 'weight_source']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex(['readiness_date']);
            $table->dropColumn(['supplier_order_ref', 'pickup_country', 'pickup_city', 'pickup_address', 'documents_available', 'special_handling']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['hs_code']);
            $table->dropColumn(['hs_code', 'gross_weight_kg', 'net_weight_kg', 'volume_m3', 'country_of_origin']);
        });
    }
};
