<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 03 7.3 (T-2, DEC-11): one tender engine for purchase and transport tenders: header,
     * sources (orders, later purchase orders), lots, purchase items and the 1:1 transport spec
     * (field list = transport-tender skill section 2). Internal-only columns (budget, target prices,
     * incumbent) are never rendered to bidders (BR-T.12).
     */
    public function up(): void
    {
        Schema::create('tenders', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('number', 20)->unique();
            $table->string('type', 12);
            $table->string('status', 24);
            $table->string('method', 16)->default('rfq');
            $table->string('kind', 16)->default('spot');
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notice_locale', 5)->default('ru');
            $table->char('evaluation_currency', 3)->default('UZS');
            $table->json('accepted_currencies');
            $table->json('fx_rates')->nullable();
            $table->foreignId('fx_set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fx_set_at')->nullable();
            // Internal only (BR-T.12).
            $table->bigInteger('budget_minor')->nullable();
            $table->char('budget_currency', 3)->nullable();
            $table->foreignId('incumbent_counterparty_id')->nullable()->constrained('counterparties')->nullOnDelete();
            $table->boolean('incumbent_disclosed')->default(false);
            $table->bigInteger('incumbent_rate_minor')->nullable();
            // Commercial terms.
            $table->text('payment_terms')->nullable();
            $table->unsignedSmallInteger('payment_days')->nullable();
            $table->unsignedSmallInteger('prepayment_bp')->nullable();
            $table->unsignedSmallInteger('bid_validity_days')->default(30);
            $table->boolean('confidential')->default(true);
            $table->boolean('urgent')->default(false);
            $table->text('urgent_justification')->nullable();
            $table->text('single_source_justification')->nullable();
            // Purchase header (purchase-tender skill section 2).
            $table->string('incoterm', 3)->nullable();
            $table->string('incoterm_place')->nullable();
            $table->foreignId('delivery_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->text('delivery_address')->nullable();
            $table->boolean('partial_deliveries_allowed')->nullable();
            $table->boolean('is_import')->default(false);
            $table->text('required_certificates')->nullable();
            $table->text('warranty_terms')->nullable();
            // Evaluation inputs (transport landed cost, skill section 5).
            $table->decimal('expected_downtime_days', 5, 2)->default(1);
            // Lane contracts (N20).
            $table->date('lane_period_from')->nullable();
            $table->date('lane_period_to')->nullable();
            $table->unsignedSmallInteger('shipments_per_month')->nullable();
            // Timeline (stored UTC, shown Asia/Tashkent UTC+5, BR-T.13).
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('clarification_deadline_at')->nullable();
            $table->timestamp('bids_due_at')->nullable()->index();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('awarded_at')->nullable();
            $table->timestamp('contracted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->unsignedSmallInteger('round')->default(1);
            $table->foreignId('parent_tender_id')->nullable()->constrained('tenders')->nullOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->json('completeness')->nullable();
            $table->json('acknowledged_warnings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'status']);
            $table->index('owner_id');
        });

        Schema::create('tender_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id');
            $table->timestamps();

            $table->unique(['tender_id', 'source_type', 'source_id']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('tender_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['tender_id', 'number']);
        });

        Schema::create('tender_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id')->constrained('tender_lots')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 60)->nullable();
            $table->string('name');
            $table->string('unit_code', 20)->nullable();
            $table->unsignedTinyInteger('unit_precision')->default(3);
            $table->decimal('quantity', 15, 3);
            $table->text('specification')->nullable();
            $table->boolean('alternatives_allowed')->default(false);
            $table->date('required_by')->nullable();
            $table->string('hs_code', 10)->nullable();
            // Internal only (BR-T.12).
            $table->bigInteger('target_price_minor')->nullable();
            $table->timestamps();

            $table->index(['lot_id', 'position']);
        });

        Schema::create('tender_transport_specs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id')->unique()->constrained('tender_lots')->cascadeOnDelete();
            // Route.
            foreach (['origin', 'destination'] as $side) {
                $table->char($side.'_country', 2)->nullable();
                $table->string($side.'_city', 120)->nullable();
                $table->text($side.'_address')->nullable();
                $table->string($side.'_postcode', 20)->nullable();
                $table->decimal($side.'_lat', 9, 6)->nullable();
                $table->decimal($side.'_lng', 9, 6)->nullable();
            }
            $table->string('border_crossing', 120)->nullable();
            $table->string('border_policy', 16)->nullable();
            $table->string('clearance_place', 160)->nullable();
            $table->json('avoid_countries')->nullable();
            $table->boolean('sanctions_reviewed')->default(false);
            // Dates.
            $table->date('readiness_date')->nullable();
            $table->date('pickup_from')->nullable();
            $table->date('pickup_to')->nullable();
            $table->date('delivery_from')->nullable();
            $table->date('delivery_to')->nullable();
            $table->unsignedSmallInteger('max_transit_days')->nullable();
            // Mode and equipment (tri-state booleans: null = unanswered).
            $table->string('mode', 12)->nullable();
            $table->string('load_type', 12)->nullable();
            $table->string('vehicle_type', 80)->nullable();
            $table->unsignedSmallInteger('units_count')->default(1);
            $table->boolean('transshipment_allowed')->nullable();
            $table->boolean('partial_allowed')->nullable();
            // Cargo.
            $table->text('cargo_description')->nullable();
            $table->json('hs_codes')->nullable();
            $table->decimal('gross_kg', 12, 3)->nullable();
            $table->decimal('net_kg', 12, 3)->nullable();
            $table->decimal('volume_m3', 12, 3)->nullable();
            $table->unsignedInteger('packages_count')->nullable();
            $table->string('package_type', 30)->nullable();
            $table->unsignedInteger('pallets_count')->nullable();
            $table->string('pallet_type', 30)->nullable();
            $table->text('dimensions')->nullable();
            $table->boolean('stackable')->nullable();
            $table->bigInteger('cargo_value_minor')->nullable();
            $table->char('cargo_currency', 3)->nullable();
            $table->boolean('is_dangerous')->nullable();
            $table->string('un_number', 10)->nullable();
            $table->string('adr_class', 10)->nullable();
            $table->string('packing_group', 5)->nullable();
            $table->boolean('is_temperature_controlled')->nullable();
            $table->decimal('temp_min_c', 4, 1)->nullable();
            $table->decimal('temp_max_c', 4, 1)->nullable();
            $table->boolean('is_oversize')->nullable();
            $table->text('oversize_notes')->nullable();
            $table->text('special_handling')->nullable();
            // Handling.
            $table->string('loading_method', 12)->nullable();
            $table->string('loading_equipment', 80)->nullable();
            $table->string('loaded_by', 16)->nullable();
            $table->string('unloading_method', 12)->nullable();
            $table->string('unloading_equipment', 80)->nullable();
            $table->string('unloaded_by', 16)->nullable();
            $table->unsignedSmallInteger('free_loading_hours')->nullable();
            $table->unsignedSmallInteger('free_customs_hours')->nullable();
            $table->unsignedSmallInteger('free_unloading_hours')->nullable();
            $table->string('container_owner', 3)->nullable();
            $table->unsignedSmallInteger('container_free_days')->nullable();
            $table->string('container_return_location', 160)->nullable();
            // Responsibility.
            $table->string('incoterm', 3)->nullable();
            $table->string('incoterm_place')->nullable();
            $table->string('export_clearance_by', 16)->nullable();
            $table->string('import_clearance_by', 16)->nullable();
            $table->string('importer_of_record', 160)->nullable();
            $table->json('charges_payer')->nullable();
            $table->string('insurance_by', 16)->nullable();
            $table->bigInteger('insured_value_minor')->nullable();
            $table->string('insurance_cover', 40)->nullable();
            // Documents.
            $table->json('required_documents')->nullable();
            $table->json('driver_vehicle_documents')->nullable();
            $table->text('tracking_requirement')->nullable();
            // SLA.
            $table->text('penalties')->nullable();
            $table->unsignedSmallInteger('late_penalty_bp_per_day')->nullable();
            $table->unsignedSmallInteger('penalty_cap_bp')->nullable();
            $table->text('fuel_adjustment')->nullable();
            $table->text('force_majeure')->nullable();
            // Shared with the winner after award only.
            $table->text('site_contacts')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_transport_specs');
        Schema::dropIfExists('tender_items');
        Schema::dropIfExists('tender_lots');
        Schema::dropIfExists('tender_sources');
        Schema::dropIfExists('tenders');
    }
};
