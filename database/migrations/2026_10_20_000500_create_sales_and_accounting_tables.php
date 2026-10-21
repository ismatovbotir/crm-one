<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Squashed schema (2026-10-20), see docs/db-review-2026-10-19.md "Resolved by squash".
     * Sales and accounting: offers, orders, reservations, agreements, periods, FX rates, schedules, invoices, payments, allocations, ledger.
     */
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->string('number', 20);
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('root_offer_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->foreignId('previous_version_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->foreignId('superseded_by_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->string('status', 20);
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained('partners')->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->char('currency', 3);
            $table->boolean('prices_include_vat')->default(false);
            $table->date('valid_until');
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->text('payment_terms_text')->nullable();
            $table->text('delivery_terms_text')->nullable();
            $table->text('warranty_text')->nullable();
            $table->text('intro_text')->nullable();
            $table->text('customer_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->string('document_locale', 5)->nullable();
            $table->unsignedBigInteger('fixed_net_minor')->default(0);
            $table->unsignedBigInteger('fixed_vat_minor')->default(0);
            $table->unsignedBigInteger('fixed_gross_minor')->default(0);
            $table->unsignedBigInteger('selected_net_minor')->default(0);
            $table->unsignedBigInteger('selected_vat_minor')->default(0);
            $table->unsignedBigInteger('selected_gross_minor')->default(0);
            $table->unsignedBigInteger('range_min_gross_minor')->default(0);
            $table->unsignedBigInteger('range_max_gross_minor')->default(0);
            $table->unsignedBigInteger('recommended_gross_minor')->nullable();
            $table->unsignedBigInteger('pipeline_value_minor')->default(0);
            $table->unsignedSmallInteger('max_discount_bp')->default(0);
            $table->boolean('selection_complete')->default(false);
            $table->unsignedInteger('selection_revision')->default(0);
            $table->char('content_hash', 64)->nullable();
            $table->char('approved_hash', 64)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sent_channel', 20)->nullable();
            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accepted_via', 20)->nullable();
            $table->text('acceptance_note')->nullable();
            $table->char('accepted_hash', 64)->nullable();
            $table->json('accepted_selection')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('reject_reason_code', 30)->nullable();
            $table->text('reject_reason_text')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('requisition_id')->nullable();
            $table->index('requisition_id');
            $table->unique('uuid');
            $table->index('updated_at');
            $table->index(['status', 'valid_until']);
            $table->index(['root_offer_id', 'version']);
            $table->index('previous_version_id');
            $table->index(['owner_id', 'updated_at']);
            $table->index(['owner_id', 'status']);
            $table->unique(['number', 'version']);
            $table->index('lead_id');
            $table->index(['customer_id', 'status']);
            $table->index('contact_id');
        });
        SchemaChecks::add('offers', [
            'offers_max_discount_chk' => '(`max_discount_bp` <= 10000)',
            'offers_range_chk' => '(`range_min_gross_minor` <= `range_max_gross_minor`)',
            'offers_version_chk' => '(`version` >= 1)',
        ]);

        Schema::create('offer_option_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->char('stable_key', 36);
            $table->unsignedSmallInteger('position');
            $table->string('kind', 12);
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('min_select');
            $table->unsignedTinyInteger('max_select');
            $table->timestamps();
            $table->unique(['offer_id', 'stable_key']);
            $table->index(['offer_id', 'position']);
        });
        SchemaChecks::add('offer_option_groups', [
            'offer_groups_max_chk' => '(`max_select` >= 1)',
            'offer_groups_min_max_chk' => '(`min_select` <= `max_select`)',
        ]);

        Schema::create('offer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_group_id')->nullable()->constrained('offer_option_groups')->cascadeOnDelete();
            $table->char('stable_key', 36);
            $table->unsignedSmallInteger('position');
            $table->char('option_label', 1)->nullable();
            $table->boolean('is_recommended')->default(false);
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $this->ascii($table->char('item_external_id', 36)->nullable());
            $table->string('sku', 64);
            $table->string('article', 64)->nullable();
            $table->string('accountant_code', 64)->nullable();
            $table->string('class_code', 32)->nullable();
            $table->string('package_code', 32)->nullable();
            $table->boolean('is_serialized')->default(false);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('unit_code', 16)->nullable();
            $table->string('unit_name', 64);
            $table->unsignedTinyInteger('unit_precision')->default(0);
            $table->unsignedSmallInteger('tax_rate_bp');
            $table->boolean('is_vat_exempt')->default(false);
            $table->string('price_source', 24);
            $table->unsignedBigInteger('list_unit_price_minor')->nullable();
            $table->unsignedBigInteger('cost_price_minor')->nullable();
            $table->decimal('quantity', 15, 3);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedSmallInteger('discount_bp')->default(0);
            $table->unsignedSmallInteger('effective_discount_bp')->default(0);
            $table->unsignedBigInteger('line_discount_minor')->default(0);
            $table->unsignedBigInteger('line_net_minor')->default(0);
            $table->unsignedBigInteger('line_vat_minor')->default(0);
            $table->unsignedBigInteger('line_gross_minor')->default(0);
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->string('stock_band', 12)->nullable();
            $table->decimal('stock_available_qty', 15, 3)->nullable();
            $table->timestamp('stock_checked_at')->nullable();
            $table->string('alternative_reason', 24)->nullable();
            $table->string('alternative_reason_note', 255)->nullable();
            $table->text('comparison_notes')->nullable();
            $table->text('internal_note')->nullable();
            $table->timestamps();
            $table->foreignId('item_image_id')->nullable()->constrained()->nullOnDelete();
            $table->string('image_path', 255)->nullable();
            $table->index(['option_group_id', 'position']);
            $table->unique(['offer_id', 'stable_key']);
            $table->index(['offer_id', 'position']);
            $table->index('item_id');
        });
        SchemaChecks::add('offer_lines', [
            'offer_lines_discount_chk' => '(`discount_bp` <= 10000)',
            'offer_lines_eff_discount_chk' => '(`effective_discount_bp` <= 10000)',
            'offer_lines_qty_chk' => '(`quantity` > 0)',
            'offer_lines_tax_chk' => '(`tax_rate_bp` <= 10000)',
        ]);

        Schema::create('offer_line_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_group_id')->constrained('offer_option_groups')->cascadeOnDelete();
            $table->foreignId('offer_line_id')->constrained()->cascadeOnDelete();
            $table->string('selected_by_actor', 12);
            $table->foreignId('selected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 12);
            $table->timestamp('selected_at');
            $table->unique('offer_line_id');
            $table->index('option_group_id');
            $table->index(['offer_id', 'option_group_id']);
        });

        Schema::create('offer_selection_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('option_group_id')->nullable();
            $table->unsignedBigInteger('offer_line_id')->nullable();
            $table->string('event', 20);
            $table->string('actor', 12);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('channel', 12)->nullable();
            $table->text('note')->nullable();
            $table->text('evidence_note')->nullable();
            $table->unsignedInteger('selection_revision')->default(0);
            $table->timestamp('created_at');
            $table->index('offer_line_id');
            $table->index(['offer_id', 'created_at']);
        });

        Schema::create('offer_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('required_bp');
            $table->unsignedSmallInteger('owner_limit_bp');
            $table->json('reasons')->nullable();
            $table->string('status', 20);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('comment')->nullable();
            $table->char('content_hash', 64);
            $table->timestamps();
            $table->index(['offer_id', 'status']);
        });

        Schema::create('offer_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            $table->foreignId('root_offer_id')->constrained('offers')->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            $table->char('content_hash', 64);
            $table->json('payload');
            $table->timestamp('sent_at');
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 20)->nullable();
            $table->string('document_locale', 5)->nullable();
            $table->timestamp('created_at');
            $table->index('root_offer_id');
            $table->unique('offer_id');
        });

        Schema::create('agreement_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40);
            $table->string('name', 255);
            $table->string('kind', 12);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('code');
        });

        Schema::create('agreement_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('agreement_templates')->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('locale', 5);
            $table->longText('body');
            $table->json('variables')->nullable();
            $table->json('clauses')->nullable();
            $table->string('status', 10);
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->char('checksum', 64)->nullable();
            $table->timestamps();
            $table->index(['template_id', 'locale', 'status']);
            $table->unique(['template_id', 'version', 'locale']);
        });

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->char('period', 7);
            $table->string('status', 8);
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->json('checklist')->nullable();
            $table->timestamps();
            $table->unique('period');
        });

        Schema::create('fx_rates', function (Blueprint $table) {
            $table->id();
            $table->char('currency', 3);
            $table->date('rate_date');
            $table->decimal('rate', 18, 8);
            $table->string('source', 12)->default('cbu_manual');
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['currency', 'rate_date']);
        });
        SchemaChecks::add('fx_rates', [
            'fx_rates_rate_chk' => '(`rate` > 0)',
        ]);

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->string('number', 20);
            $table->string('status', 24);
            $table->string('payment_status', 16)->default('unpaid');
            // Spec 06 section 31: null for manual orders (source = manual); unique among offer orders.
            $table->foreignId('offer_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('offer_version')->nullable();
            $table->char('offer_accepted_hash', 64)->nullable();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained('partners')->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('billing_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('framework_agreement_id')->nullable();
            $table->char('currency', 3);
            $table->boolean('prices_include_vat')->default(false);
            $table->unsignedBigInteger('net_minor');
            $table->unsignedBigInteger('vat_minor');
            $table->unsignedBigInteger('gross_minor');
            $table->unsignedBigInteger('paid_minor')->default(0);
            $table->unsignedBigInteger('invoiced_gross_minor')->default(0);
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->unsignedSmallInteger('prepayment_bp')->nullable();
            $table->text('payment_terms_text')->nullable();
            $table->text('delivery_terms_text')->nullable();
            $table->text('warranty_text')->nullable();
            $table->text('delivery_address')->nullable();
            $table->date('requested_delivery_on')->nullable();
            $table->date('promised_delivery_on')->nullable();
            $table->string('document_locale', 5)->nullable();
            $table->boolean('agreement_required')->default(true);
            $table->string('agreement_waiver_reason', 255)->nullable();
            $table->boolean('addendum_pending')->default(false);
            $table->json('hold_reasons')->nullable();
            $table->boolean('on_hold')->default(false);
            $table->boolean('shortage_acknowledged')->default(false);
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('invoiced_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $this->ascii($table->char('onec_guid', 36)->nullable());
            $table->string('onec_number', 30)->nullable();
            $table->string('onec_state', 16)->default('not_sent');
            $table->timestamp('onec_acked_at')->nullable();
            $table->string('onec_error', 255)->nullable();
            $table->timestamps();
            $table->string('sale_type', 10)->default('b2b');
            $table->timestamp('packed_at')->nullable();
            $table->boolean('poa_required')->default(false);
            $table->timestamp('poa_received_at')->nullable();
            $table->foreignId('poa_received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('poa_note', 255)->nullable();
            $table->timestamp('poa_waived_at')->nullable();
            $table->foreignId('poa_waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('poa_waiver_reason', 500)->nullable();
            // Spec 06 section 31: manual orders, delivery or self pick-up, one warehouse per order.
            $table->string('source', 10)->default('offer');
            $table->string('delivery_method', 10)->default('delivery');
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->unique('uuid');
            $table->index('updated_at');
            $table->index(['status', 'promised_delivery_on']);
            $table->index(['status', 'payment_status']);
            $table->index(['owner_id', 'status']);
            $table->unique('onec_guid');
            $table->index('on_hold');
            $table->unique('offer_id');
            $table->index('source', 'orders_source_index');
            $table->index('warehouse_id', 'orders_warehouse_id_index');
            $table->unique('number');
            $table->index('lead_id');
            $table->index('framework_agreement_id');
            $table->index(['customer_id', 'status']);
            $table->index('contact_id');
        });
        SchemaChecks::add('orders', [
            'orders_paid_chk' => '(`paid_minor` >= 0)',
            'orders_prepayment_chk' => '((`prepayment_bp` is null) or (`prepayment_bp` <= 10000))',
        ]);

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('offer_line_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('offer_option_group_id')->nullable();
            $table->string('option_label', 8)->nullable();
            $table->string('group_title', 255)->nullable();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $this->ascii($table->char('item_external_id', 36)->nullable());
            $table->string('sku', 64);
            $table->string('name', 255);
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('unit_code', 16)->nullable();
            $table->string('unit_name', 64);
            $table->unsignedTinyInteger('unit_precision')->default(0);
            $table->unsignedSmallInteger('tax_rate_bp');
            $table->boolean('is_vat_exempt')->default(false);
            $table->string('accountant_code', 64)->nullable();
            $table->string('class_code', 32)->nullable();
            $table->string('package_code', 32)->nullable();
            $table->boolean('is_serialized')->default(false);
            $table->decimal('quantity', 15, 3);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedSmallInteger('discount_bp')->default(0);
            $table->unsignedBigInteger('line_net_minor');
            $table->unsignedBigInteger('line_vat_minor');
            $table->unsignedBigInteger('line_gross_minor');
            $table->unsignedBigInteger('cost_price_minor')->nullable();
            $table->decimal('delivered_qty', 15, 3)->default(0);
            $table->decimal('invoiced_qty', 15, 3)->default(0);
            $table->decimal('cancelled_qty', 15, 3)->default(0);
            $table->decimal('returned_qty', 15, 3)->default(0);
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->boolean('backorder')->default(false);
            $table->timestamps();
            $table->string('image_path', 255)->nullable();
            $table->unsignedBigInteger('added_by_amendment_id')->nullable();
            $table->index('warehouse_id');
            $table->index('offer_line_id');
            $table->index('accountant_code');
            $table->index('item_id');
            $table->index(['order_id', 'position']);
        });
        SchemaChecks::add('order_lines', [
            'order_lines_delivered_chk' => '((`delivered_qty` + `cancelled_qty`) <= `quantity`)',
            'order_lines_invoiced_chk' => '(`invoiced_qty` <= `delivered_qty`)',
            'order_lines_qty_chk' => '(`quantity` > 0)',
        ]);

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->string('status', 16);
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason', 30)->nullable();
            $table->timestamps();
            $table->index('warehouse_id');
            $table->index('order_line_id');
            $table->index(['item_id', 'warehouse_id', 'status']);
        });
        SchemaChecks::add('stock_reservations', [
            'stock_reservations_qty_chk' => '(`quantity` > 0)',
        ]);

        Schema::create('agreements', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->string('number', 30);
            $table->string('kind', 12);
            $table->string('status', 20);
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('partners')->restrictOnDelete();
            $table->foreignId('parent_agreement_id')->nullable()->constrained('agreements')->restrictOnDelete();
            $table->foreignId('renewal_of_id')->nullable()->constrained('agreements')->restrictOnDelete();
            $table->foreignId('renewed_by_id')->nullable()->constrained('agreements')->nullOnDelete();
            $table->foreignId('template_id')->constrained('agreement_templates')->restrictOnDelete();
            $table->unsignedSmallInteger('template_version');
            $table->string('document_locale', 5);
            $table->string('secondary_locale', 5)->nullable();
            $table->json('variables');
            $table->json('clause_options')->nullable();
            $table->boolean('is_non_standard')->default(false);
            $table->json('non_standard_reasons')->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->unsignedSmallInteger('notice_days')->default(30);
            $table->unsignedBigInteger('value_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('customer_signed_uploaded_at')->nullable();
            $table->date('signed_on')->nullable();
            $table->foreignId('signed_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('our_signatory_id')->nullable()->constrained('company_signatories')->nullOnDelete();
            $table->string('customer_signatory_name', 255)->nullable();
            $table->string('customer_signatory_position', 255)->nullable();
            $table->string('customer_signatory_basis', 255)->nullable();
            $table->foreignId('signed_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->date('terminated_on')->nullable();
            $table->string('termination_reason_code', 30)->nullable();
            $table->text('termination_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->json('reminders_sent')->nullable();
            $this->ascii($table->char('onec_guid', 36)->nullable());
            $table->string('onec_number', 30)->nullable();
            $table->string('onec_state', 16)->default('not_sent');
            $table->timestamp('onec_acked_at')->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('active_order_key')->nullable()->virtualAs('case when kind = \'order\' and status <> \'cancelled\' then order_id end');
            $table->index('template_id');
            $table->index('renewal_of_id');
            $table->index('parent_agreement_id');
            $table->index('order_id');
            $table->unique('onec_guid');
            $table->index('status');
            $table->unique('number');
            $table->unique('uuid');
            $table->index(['status', 'ends_on']);
            $table->index(['customer_id', 'status']);
            $table->unique('active_order_key', 'agreements_one_active_per_order');
        });

        // Spec 06 section 6.4 / 7.4: order amendments after the order started (changes as a json diff).
        Schema::create('order_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('status', 16);
            $table->json('changes');
            $table->string('reason', 500);
            $table->boolean('needs_approval')->default(false);
            $table->unsignedSmallInteger('required_discount_bp')->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->unsignedInteger('revision_from')->nullable();
            $table->unsignedInteger('revision_to')->nullable();
            $table->json('totals_before')->nullable();
            $table->json('totals_after')->nullable();
            $table->foreignId('addendum_agreement_id')->nullable()->constrained('agreements')->nullOnDelete();
            $table->timestamps();
            $table->unique(['order_id', 'number']);
            $table->index(['order_id', 'status']);
            $table->index('status');
        });

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
            $table->string('recipient_email', 255)->nullable();
            $table->string('locale', 5);
            $table->char('share_token_hash', 64)->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('sent_at');
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique('share_token_hash');
            $table->index(['agreement_id', 'sent_at']);
        });

        Schema::create('payment_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('agreement_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 12);
            $table->string('label', 255)->nullable();
            $table->unsignedBigInteger('delivery_id')->nullable();
            $table->unsignedSmallInteger('percent_bp')->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->date('due_on')->nullable();
            $table->unsignedSmallInteger('position')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('payment_schedule_items')->nullOnDelete();
            $table->timestamps();
            $table->index('agreement_id');
            $table->index(['due_on', 'superseded_at']);
            $table->index(['order_id', 'superseded_at', 'position']);
        });
        SchemaChecks::add('payment_schedule_items', [
            'payment_schedule_items_amount_chk' => '(`amount_minor` > 0)',
        ]);

        // Spec 06 7.14 / BR-AC.17: one reminder per schedule item, stage and channel (idempotent).
        Schema::create('dunning_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_item_id')->constrained('payment_schedule_items')->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('partners')->restrictOnDelete();
            $table->string('stage', 10);
            $table->string('channel', 10);
            $table->string('recipient', 255)->nullable();
            $table->string('locale', 5)->nullable();
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->char('currency', 3);
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->unique(['schedule_item_id', 'stage', 'channel']);
            $table->index(['order_id', 'stage']);
            $table->index('customer_id');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->string('type', 12);
            $table->string('status', 10);
            $table->string('source', 16);
            $table->string('number', 40);
            $table->date('issued_on');
            $table->date('due_on')->nullable();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('partners')->restrictOnDelete();
            $table->foreignId('agreement_id')->nullable()->constrained()->nullOnDelete();
            $table->char('currency', 3);
            $table->boolean('prices_include_vat')->default(false);
            $table->unsignedBigInteger('net_minor');
            $table->unsignedBigInteger('vat_minor');
            $table->unsignedBigInteger('gross_minor');
            $table->unsignedBigInteger('requested_amount_minor')->nullable();
            $table->unsignedBigInteger('paid_minor')->default(0);
            $table->foreignId('bank_account_id')->nullable()->constrained('company_bank_accounts')->nullOnDelete();
            $table->string('document_locale', 5)->nullable();
            $table->string('esf_id', 64)->nullable();
            $table->string('esf_status', 16)->nullable();
            $table->string('external_ref', 40)->nullable();
            $table->foreignId('attachment_id')->nullable()->constrained()->nullOnDelete();
            $table->char('period', 7);
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('void_requested_at')->nullable();
            $table->foreignId('void_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('void_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $this->ascii($table->char('onec_guid', 36)->nullable());
            $table->string('onec_state', 16)->default('not_sent');
            $table->json('code_warnings')->nullable();
            $table->timestamps();
            $table->index('agreement_id');
            $table->unique('onec_guid');
            $table->unique('uuid');
            $table->index('period');
            $table->index(['type', 'issued_on']);
            $table->index(['customer_id', 'status']);
            $table->index('order_id');
            $table->unique(['type', 'number', 'issued_on']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_line_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('delivery_line_id')->nullable();
            $table->unsignedSmallInteger('position');
            $this->ascii($table->char('item_external_id', 36)->nullable());
            $table->string('sku', 64);
            $table->string('name', 255);
            $table->string('unit_code', 16)->nullable();
            $table->string('unit_name', 64);
            $table->unsignedSmallInteger('tax_rate_bp');
            $table->boolean('is_vat_exempt')->default(false);
            $table->string('accountant_code', 64)->nullable();
            $table->string('class_code', 32)->nullable();
            $table->string('package_code', 32)->nullable();
            $table->decimal('quantity', 15, 3);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedSmallInteger('discount_bp')->default(0);
            $table->unsignedBigInteger('line_net_minor');
            $table->unsignedBigInteger('line_vat_minor');
            $table->unsignedBigInteger('line_gross_minor');
            $table->timestamps();
            $table->index('invoice_id');
            $table->index('class_code');
            $table->index('accountant_code');
            $table->index('order_line_id');
        });
        SchemaChecks::add('invoice_lines', [
            'invoice_lines_qty_chk' => '(`quantity` > 0)',
        ]);

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->string('number', 20);
            $table->string('direction', 8);
            $table->string('kind', 10);
            $table->string('status', 16);
            $table->string('source', 8);
            $table->foreignId('customer_id')->nullable()->constrained('partners')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->date('paid_on');
            $table->string('method', 16);
            $table->foreignId('company_bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payer_name', 255);
            $table->string('payer_tax_id', 32)->nullable();
            $table->string('payer_account', 34)->nullable();
            $table->string('reference_number', 64)->nullable();
            $table->text('purpose')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('refund_of_payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->foreignId('proof_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->unsignedBigInteger('allocated_minor')->default(0);
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->char('period', 7);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->foreignId('void_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('void_requested_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('match_state', 20)->nullable();
            $table->boolean('posted_in_locked_period')->default(false);
            $this->ascii($table->char('onec_guid', 36)->nullable());
            $table->string('onec_number', 40)->nullable();
            $table->boolean('onec_posted')->nullable();
            $table->string('onec_version', 64)->nullable();
            $table->timestamp('onec_updated_at')->nullable();
            $table->timestamp('onec_acked_at')->nullable();
            $table->string('onec_error', 255)->nullable();
            $table->timestamps();
            $table->foreignId('planned_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->unique('uuid');
            $table->index('status');
            $table->index(['source', 'status']);
            $table->index('refund_of_payment_id');
            $table->index('reference_number');
            $table->index('recorded_by');
            $table->index('period');
            $table->index('paid_on');
            $table->unique('onec_guid');
            $table->unique('number');
            $table->index(['customer_id', 'paid_on']);
        });
        SchemaChecks::add('payments', [
            'payments_allocated_chk' => '((`allocated_minor` + `refunded_minor`) <= `amount_minor`)',
            'payments_amount_chk' => '(`amount_minor` > 0)',
        ]);

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->bigInteger('order_amount_minor');
            $table->decimal('fx_rate', 18, 8)->nullable();
            $table->date('fx_rate_date')->nullable();
            $table->string('fx_source', 12)->nullable();
            $table->foreignId('reverses_id')->nullable()->constrained('payment_allocations')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->char('period', 7);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index('invoice_id');
            $table->unique('reverses_id');
            $table->index('order_id');
            $table->index(['payment_id', 'order_id']);
        });

        Schema::create('accounting_entries', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->string('entry_type', 24);
            $table->foreignId('customer_id')->nullable()->constrained('partners')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('allocation_id')->nullable()->constrained('payment_allocations')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->date('occurred_on');
            $table->char('period', 7);
            $table->string('source', 8);
            $table->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reverses_entry_id')->nullable()->constrained('accounting_entries')->restrictOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('reverses_entry_id');
            $table->index('allocation_id');
            $table->index('invoice_id');
            $table->unique('uuid');
            $table->index('period');
            $table->index('payment_id');
            $table->index('order_id');
            $table->index(['customer_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite' && Schema::hasTable('accounting_entries')) {
            DB::table('accounting_entries')->update(['reverses_entry_id' => null]);
        }
        Schema::dropIfExists('accounting_entries');
        if (DB::getDriverName() === 'sqlite' && Schema::hasTable('payment_allocations')) {
            DB::table('payment_allocations')->update(['reverses_id' => null]);
        }
        Schema::dropIfExists('payment_allocations');
        if (DB::getDriverName() === 'sqlite' && Schema::hasTable('payments')) {
            DB::table('payments')->update(['refund_of_payment_id' => null]);
        }
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('dunning_events');
        Schema::dropIfExists('payment_schedule_items');
        Schema::dropIfExists('agreement_dispatches');
        Schema::dropIfExists('order_amendments');
        Schema::dropIfExists('agreement_versions');
        if (DB::getDriverName() === 'sqlite' && Schema::hasTable('agreements')) {
            DB::table('agreements')->update(['renewal_of_id' => null, 'parent_agreement_id' => null]);
        }
        Schema::dropIfExists('agreements');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('fx_rates');
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('agreement_template_versions');
        Schema::dropIfExists('agreement_templates');
        Schema::dropIfExists('offer_versions');
        Schema::dropIfExists('offer_approvals');
        Schema::dropIfExists('offer_selection_events');
        Schema::dropIfExists('offer_line_selections');
        Schema::dropIfExists('offer_lines');
        Schema::dropIfExists('offer_option_groups');
        Schema::dropIfExists('offers');
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
