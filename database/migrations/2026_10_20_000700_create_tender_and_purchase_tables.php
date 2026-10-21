<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Squashed schema (2026-10-20), see docs/db-review-2026-10-19.md "Resolved by squash".
     * Tenders (purchase and transport), bids, comparisons, awards and purchase orders.
     */
    public function up(): void
    {
        Schema::create('tenders', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->string('number', 20);
            $table->string('type', 12);
            $table->string('status', 24);
            $table->string('method', 16)->default('rfq');
            $table->string('kind', 16)->default('spot');
            $table->string('title', 255);
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
            $table->bigInteger('budget_minor')->nullable();
            $table->char('budget_currency', 3)->nullable();
            $table->foreignId('incumbent_counterparty_id')->nullable()->constrained('counterparties')->nullOnDelete();
            $table->boolean('incumbent_disclosed')->default(false);
            $table->bigInteger('incumbent_rate_minor')->nullable();
            $table->text('payment_terms')->nullable();
            $table->unsignedSmallInteger('payment_days')->nullable();
            $table->unsignedSmallInteger('prepayment_bp')->nullable();
            $table->unsignedSmallInteger('bid_validity_days')->default(30);
            $table->boolean('confidential')->default(true);
            $table->boolean('link_verification_required')->default(true);
            $table->boolean('urgent')->default(false);
            $table->text('urgent_justification')->nullable();
            $table->text('single_source_justification')->nullable();
            $table->string('incoterm', 3)->nullable();
            $table->string('incoterm_place', 255)->nullable();
            $table->foreignId('delivery_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->text('delivery_address')->nullable();
            $table->boolean('partial_deliveries_allowed')->nullable();
            $table->boolean('is_import')->default(false);
            $table->text('required_certificates')->nullable();
            $table->text('warranty_terms')->nullable();
            $table->decimal('expected_downtime_days', 5, 2)->default(1);
            $table->date('lane_period_from')->nullable();
            $table->date('lane_period_to')->nullable();
            $table->unsignedSmallInteger('shipments_per_month')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('clarification_deadline_at')->nullable();
            $table->timestamp('bids_due_at')->nullable();
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
            $table->string('decision_level', 10)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('requested_by');
            $table->index('department_id');
            $table->index('parent_tender_id');
            $table->index('bids_due_at');
            $table->unique('number');
            $table->unique('uuid');
            $table->index('owner_id');
            $table->index(['type', 'status']);
        });

        Schema::create('tender_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id');
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
            $table->unique(['tender_id', 'source_type', 'source_id']);
        });

        Schema::create('tender_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('title', 255);
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
            $table->string('name', 255);
            $table->string('unit_code', 20)->nullable();
            $table->unsignedTinyInteger('unit_precision')->default(3);
            $table->decimal('quantity', 15, 3);
            $table->text('specification')->nullable();
            $table->boolean('alternatives_allowed')->default(false);
            $table->date('required_by')->nullable();
            $table->string('hs_code', 10)->nullable();
            $table->bigInteger('target_price_minor')->nullable();
            $table->timestamps();
            $table->index('item_id');
            $table->index(['lot_id', 'position']);
        });

        Schema::create('tender_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->boolean('is_knockout')->default(false);
            $table->timestamps();
            $table->index('tender_id');
        });

        Schema::create('tender_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('label', 255)->nullable();
            $table->unsignedSmallInteger('weight_bp');
            $table->string('method', 20);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['tender_id', 'code']);
        });

        Schema::create('tender_committee', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 8)->default('member');
            $table->timestamp('coi_declared_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
            $table->unique(['tender_id', 'user_id']);
        });

        Schema::create('tender_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->foreignId('counterparty_id')->constrained('counterparties')->restrictOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->string('status', 16)->default('invited');
            $table->string('channel_preference', 10)->default('employee');
            $table->string('contact_email', 255)->nullable();
            $table->string('notice_locale', 5)->nullable();
            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->boolean('needs_addendum_confirmation')->default(false);
            $table->timestamps();
            $table->index('counterparty_id');
            $table->unique(['tender_id', 'counterparty_id']);
        });

        Schema::create('tender_invitation_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_invitation_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sent_via', 10)->nullable();
            $table->string('sent_to', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 255)->nullable();
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->unsignedSmallInteger('distinct_ip_count')->default(0);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique('token_hash');
            $table->index(['tender_invitation_id', 'revoked_at']);
        });

        Schema::create('tender_link_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained('tender_invitation_links')->cascadeOnDelete();
            $table->string('event', 20);
            $table->char('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['link_id', 'event']);
        });

        Schema::create('tender_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->foreignId('counterparty_id')->nullable()->constrained('counterparties')->nullOnDelete();
            $table->foreignId('asked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('received_via', 12)->nullable();
            $table->text('question');
            $table->text('answer')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index('counterparty_id');
            $table->index(['tender_id', 'published_at']);
        });

        Schema::create('tender_addenda', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('summary', 255);
            $table->text('changes')->nullable();
            $table->timestamp('previous_bids_due_at')->nullable();
            $table->timestamp('new_bids_due_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['tender_id', 'version']);
        });

        Schema::create('tender_bids', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('tender_id')->constrained()->restrictOnDelete();
            $table->foreignId('counterparty_id')->constrained('counterparties')->restrictOnDelete();
            $table->foreignId('invitation_id')->constrained('tender_invitations')->restrictOnDelete();
            $table->unsignedSmallInteger('round_no')->default(1);
            $table->unsignedSmallInteger('version');
            $table->string('status', 16);
            $table->string('channel', 10);
            $table->foreignId('link_id')->nullable()->constrained('tender_invitation_links')->nullOnDelete();
            $table->char('submission_uuid', 36)->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('currency', 3);
            $table->date('valid_until')->nullable();
            $table->unsignedSmallInteger('payment_days')->nullable();
            $table->unsignedSmallInteger('prepayment_bp')->default(0);
            $table->boolean('subcontracting')->default(false);
            $table->string('subcontractor_name', 255)->nullable();
            $table->text('notes')->nullable();
            $table->text('exclusions')->nullable();
            $table->boolean('conditions_accepted')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->boolean('needs_confirmation')->default(false);
            $table->text('correction_reason')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamp('disqualified_at')->nullable();
            $table->foreignId('disqualified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('disqualification_reason')->nullable();
            $table->timestamps();
            $table->index('link_id');
            $table->index('invitation_id');
            $table->index('counterparty_id');
            $table->unique('submission_uuid');
            $table->unique('uuid');
            $table->index(['tender_id', 'status']);
            $table->unique(['tender_id', 'counterparty_id', 'round_no', 'version']);
        });

        Schema::create('tender_bid_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bid_id')->constrained('tender_bids')->cascadeOnDelete();
            $table->string('received_via', 12);
            $table->timestamp('received_at');
            $table->string('sender_name', 255);
            $table->string('sender_contact', 255)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique('bid_id');
        });

        Schema::create('tender_bid_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bid_id')->constrained('tender_bids')->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained('tender_lots')->restrictOnDelete();
            $table->unsignedTinyInteger('variant_no')->default(1);
            $table->string('variant_label', 255)->nullable();
            $table->bigInteger('total_minor')->default(0);
            $table->unsignedSmallInteger('transit_days')->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->string('route', 255)->nullable();
            $table->string('border_crossing', 120)->nullable();
            $table->string('vehicle_offered', 120)->nullable();
            $table->bigInteger('downtime_rate_minor_per_day')->nullable();
            $table->string('payment_terms_offered', 255)->nullable();
            $table->text('exceptions')->nullable();
            $table->bigInteger('landed_cost_minor')->nullable();
            $table->boolean('uses_estimates')->default(false);
            $table->decimal('score', 6, 3)->nullable();
            $table->unsignedSmallInteger('rank')->nullable();
            $table->json('knockout_reasons')->nullable();
            $table->json('breakdown')->nullable();
            $table->timestamps();
            $table->index('lot_id');
            $table->unique(['bid_id', 'lot_id', 'variant_no']);
        });

        Schema::create('tender_bid_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bid_lot_id')->constrained('tender_bid_lots')->cascadeOnDelete();
            $table->foreignId('tender_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('charge_code', 30)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('basis', 20)->nullable();
            $table->decimal('quantity', 15, 3)->default(1);
            $table->bigInteger('unit_price_minor')->nullable();
            $table->bigInteger('amount_minor')->default(0);
            $table->string('inclusion', 14)->default('included');
            $table->bigInteger('estimate_minor')->nullable();
            $table->foreignId('estimate_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('offered_brand', 120)->nullable();
            $table->string('offered_model', 120)->nullable();
            $table->boolean('compliant')->default(true);
            $table->text('deviation')->nullable();
            $table->timestamps();
            $table->index('tender_item_id');
            $table->index('bid_lot_id');
        });
        SchemaChecks::add('tender_bid_lines', [
            'tender_bid_lines_amount_chk' => '((`amount_minor` >= 0) and ((`estimate_minor` is null) or (`estimate_minor` >= 0)))',
        ]);

        Schema::create('tender_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bid_lot_id')->constrained('tender_bid_lots')->cascadeOnDelete();
            $table->foreignId('criterion_id')->constrained('tender_criteria')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->decimal('score', 4, 2);
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->index('criterion_id');
            $table->unique(['bid_lot_id', 'criterion_id', 'user_id']);
        });
        SchemaChecks::add('tender_scores', [
            'tender_scores_range_chk' => '((`score` >= 0) and (`score` <= 10))',
        ]);

        Schema::create('tender_comparisons', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('tender_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('round_no')->default(1);
            $table->unsignedSmallInteger('version');
            $table->string('status', 12);
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
            $table->unique('uuid');
            $table->unique(['tender_id', 'round_no', 'version']);
        });

        Schema::create('tender_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->restrictOnDelete();
            $table->foreignId('lot_id')->constrained('tender_lots')->restrictOnDelete();
            $table->foreignId('bid_id')->constrained('tender_bids')->restrictOnDelete();
            $table->foreignId('bid_lot_id')->constrained('tender_bid_lots')->restrictOnDelete();
            $table->foreignId('comparison_id')->nullable()->constrained('tender_comparisons')->nullOnDelete();
            $table->foreignId('counterparty_id')->constrained('counterparties')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->bigInteger('landed_cost_minor');
            $table->char('evaluation_currency', 3);
            $table->unsignedSmallInteger('rank_at_proposal')->nullable();
            $table->text('justification')->nullable();
            $table->string('status', 12);
            $table->json('required_levels');
            $table->unsignedBigInteger('active_lot_id')->nullable();
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('proposed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('accept_due_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->text('acceptance_note')->nullable();
            $table->foreignId('acceptance_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('counterparty_id');
            $table->index('bid_id');
            $table->index('lot_id');
            $table->index('comparison_id');
            $table->index(['tender_id', 'status']);
            $table->unique('active_lot_id');
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->string('number', 20);
            $table->string('source', 14)->default('tender_award');
            $table->foreignId('tender_award_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('counterparties')->restrictOnDelete();
            $table->string('supplier_order_ref', 60)->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);
            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor')->default(0);
            $table->string('incoterm', 3)->nullable();
            $table->string('incoterm_place', 255)->nullable();
            $table->char('pickup_country', 2)->nullable();
            $table->string('pickup_city', 120)->nullable();
            $table->text('pickup_address')->nullable();
            $table->foreignId('destination_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->date('readiness_date')->nullable();
            $table->date('required_delivery_date')->nullable();
            $table->text('payment_terms')->nullable();
            $table->json('documents_available')->nullable();
            $table->text('special_handling')->nullable();
            $table->unsignedSmallInteger('payment_days')->nullable();
            $table->unsignedSmallInteger('prepayment_bp')->nullable();
            $table->string('onec_number', 30)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->index('department_id');
            $table->index('owner_id');
            $table->index('order_id');
            $table->index('readiness_date');
            $table->unique('tender_award_id');
            $table->unique('number');
            $table->unique('uuid');
            $table->index('supplier_id');
            $table->index('status');
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tender_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 60)->nullable();
            $table->string('name', 255);
            $table->string('unit_code', 20)->nullable();
            $table->unsignedTinyInteger('unit_precision')->default(3);
            $table->decimal('quantity', 15, 3);
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('line_total_minor');
            $table->string('hs_code', 10)->nullable();
            $table->char('country_of_origin', 2)->nullable();
            $table->decimal('gross_weight_kg', 12, 3)->nullable();
            $table->decimal('net_weight_kg', 12, 3)->nullable();
            $table->decimal('volume_m3', 12, 3)->nullable();
            $table->unsignedInteger('packages_count')->nullable();
            $table->string('package_type', 30)->nullable();
            $table->string('weight_source', 14)->default('master');
            $table->string('offered_brand', 120)->nullable();
            $table->string('offered_model', 120)->nullable();
            $table->date('required_by')->nullable();
            $table->timestamps();
            $table->index('tender_item_id');
            $table->index('item_id');
            $table->index(['purchase_order_id', 'position']);
        });

        Schema::create('tender_transport_specs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id')->constrained('tender_lots')->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->char('origin_country', 2)->nullable();
            $table->string('origin_city', 120)->nullable();
            $table->text('origin_address')->nullable();
            $table->string('origin_postcode', 20)->nullable();
            $table->decimal('origin_lat', 9, 6)->nullable();
            $table->decimal('origin_lng', 9, 6)->nullable();
            $table->char('destination_country', 2)->nullable();
            $table->string('destination_city', 120)->nullable();
            $table->text('destination_address')->nullable();
            $table->string('destination_postcode', 20)->nullable();
            $table->decimal('destination_lat', 9, 6)->nullable();
            $table->decimal('destination_lng', 9, 6)->nullable();
            $table->string('border_crossing', 120)->nullable();
            $table->string('border_policy', 16)->nullable();
            $table->string('clearance_place', 160)->nullable();
            $table->json('avoid_countries')->nullable();
            $table->boolean('sanctions_reviewed')->default(false);
            $table->date('readiness_date')->nullable();
            $table->date('pickup_from')->nullable();
            $table->date('pickup_to')->nullable();
            $table->date('delivery_from')->nullable();
            $table->date('delivery_to')->nullable();
            $table->unsignedSmallInteger('max_transit_days')->nullable();
            $table->string('mode', 12)->nullable();
            $table->string('load_type', 12)->nullable();
            $table->string('vehicle_type', 80)->nullable();
            $table->unsignedSmallInteger('units_count')->default(1);
            $table->boolean('transshipment_allowed')->nullable();
            $table->boolean('partial_allowed')->nullable();
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
            $table->string('incoterm', 3)->nullable();
            $table->string('incoterm_place', 255)->nullable();
            $table->string('export_clearance_by', 16)->nullable();
            $table->string('import_clearance_by', 16)->nullable();
            $table->string('importer_of_record', 160)->nullable();
            $table->json('charges_payer')->nullable();
            $table->string('insurance_by', 16)->nullable();
            $table->bigInteger('insured_value_minor')->nullable();
            $table->string('insurance_cover', 40)->nullable();
            $table->json('required_documents')->nullable();
            $table->json('driver_vehicle_documents')->nullable();
            $table->text('tracking_requirement')->nullable();
            $table->text('penalties')->nullable();
            $table->unsignedSmallInteger('late_penalty_bp_per_day')->nullable();
            $table->unsignedSmallInteger('penalty_cap_bp')->nullable();
            $table->text('fuel_adjustment')->nullable();
            $table->text('force_majeure')->nullable();
            $table->text('site_contacts')->nullable();
            $table->json('prefilled_from')->nullable();
            $table->timestamps();
            $table->index('purchase_order_id');
            $table->unique('lot_id');
        });

        Schema::create('tender_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->restrictOnDelete();
            $table->foreignId('award_id')->nullable()->constrained('tender_awards')->restrictOnDelete();
            $table->foreignId('comparison_id')->nullable()->constrained('tender_comparisons')->restrictOnDelete();
            $table->char('comparison_hash', 64)->nullable();
            $table->json('selected')->nullable();
            $table->string('subject', 12);
            $table->string('level', 10);
            $table->string('decision', 12);
            $table->text('comment')->nullable();
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->string('confirmed_via', 10)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('comparison_id');
            $table->index(['tender_id', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_decisions');
        Schema::dropIfExists('tender_transport_specs');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('tender_awards');
        Schema::dropIfExists('tender_comparisons');
        Schema::dropIfExists('tender_scores');
        Schema::dropIfExists('tender_bid_lines');
        Schema::dropIfExists('tender_bid_lots');
        Schema::dropIfExists('tender_bid_sources');
        Schema::dropIfExists('tender_bids');
        Schema::dropIfExists('tender_addenda');
        Schema::dropIfExists('tender_questions');
        Schema::dropIfExists('tender_link_events');
        Schema::dropIfExists('tender_invitation_links');
        Schema::dropIfExists('tender_invitations');
        Schema::dropIfExists('tender_committee');
        Schema::dropIfExists('tender_criteria');
        Schema::dropIfExists('tender_requirements');
        Schema::dropIfExists('tender_items');
        Schema::dropIfExists('tender_lots');
        Schema::dropIfExists('tender_sources');
        Schema::dropIfExists('tenders');
    }
};
