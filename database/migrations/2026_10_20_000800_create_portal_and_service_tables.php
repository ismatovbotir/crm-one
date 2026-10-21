<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Squashed schema (2026-10-20), see docs/db-review-2026-10-19.md "Resolved by squash".
     * Customer portal and service: memberships, catalogue visibility, requisitions, customer equipment, service tickets.
     */
    public function up(): void
    {
        Schema::create('portal_memberships', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('partners')->restrictOnDelete();
            $table->foreignId('counterparty_id')->nullable()->constrained('counterparties')->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->json('roles');
            $table->bigInteger('approval_limit_minor')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->foreignId('partner_id')->nullable()->constrained();
            $table->index('partner_id');
            $table->unique('uuid');
            $table->unique(['user_id', 'customer_id']);
            $table->unique(['user_id', 'counterparty_id']);
            $table->index(['customer_id', 'is_active']);
            $table->index('counterparty_id');
            $table->index('contact_id');
        });

        Schema::create('catalog_visibility_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('mode', 10);
            $table->unsignedBigInteger('category_key')->virtualAs('coalesce(category_id, 0)');
            $table->unsignedBigInteger('item_key')->virtualAs('coalesce(item_id, 0)');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index('partner_id');
            $table->index('item_id');
            $table->index('category_id');
            $table->unique(['partner_id', 'category_key', 'item_key'], 'catalog_visibility_rules_scope_unique');
        });

        Schema::create('requisitions', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->string('number', 30);
            $table->foreignId('partner_id')->constrained();
            $table->string('status', 20)->default('draft');
            $table->foreignId('requester_id')->constrained('users');
            $table->foreignId('purchase_manager_id')->nullable()->constrained('users');
            $table->string('title', 255)->nullable();
            $table->string('delivery_location', 500)->nullable();
            $table->date('needed_by')->nullable();
            $table->text('notes')->nullable();
            $table->text('return_comment')->nullable();
            $table->text('reject_reason')->nullable();
            $table->text('decline_reason')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users');
            $table->foreignId('merged_into_id')->nullable()->constrained('requisitions');
            $table->foreignId('split_from_id')->nullable()->constrained('requisitions');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index('partner_id');
            $table->index('split_from_id');
            $table->index('merged_into_id');
            $table->index('owner_id');
            $table->index('requester_id');
            $table->index('status');
            $table->unique('number');
            $table->unique('uuid');
        });

        Schema::create('requisition_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->foreignId('item_id')->nullable()->constrained();
            $table->foreignId('category_id')->nullable()->constrained('categories');
            $table->string('description', 1000)->nullable();
            $table->decimal('quantity', 15, 3);
            $table->string('unit', 30)->nullable();
            $table->date('needed_by')->nullable();
            $table->foreignId('mapped_item_id')->nullable()->constrained('items');
            $table->foreignId('source_line_id')->nullable()->constrained('requisition_lines')->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index('source_line_id');
            $table->index('mapped_item_id');
            $table->index('category_id');
            $table->index('item_id');
            $table->index('requisition_id');
        });

        Schema::create('requisition_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->text('body');
            $table->string('audience', 10)->default('shared');
            $table->timestamp('created_at')->nullable();
            $table->index('requisition_id');
        });

        Schema::create('customer_assets', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->foreignId('partner_id')->constrained();
            $table->foreignId('item_serial_id')->nullable()->constrained();
            $table->foreignId('item_id')->nullable()->constrained();
            $table->string('model_name', 255)->nullable();
            $table->string('serial_raw', 100);
            $table->string('serial_normalized', 64);
            $table->string('source', 30);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->string('status', 30)->default('in_use');
            $table->string('location', 255)->nullable();
            $table->date('purchased_on')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->foreignId('order_id')->nullable()->constrained();
            $table->foreignId('delivery_id')->nullable()->constrained('deliveries');
            $table->date('warranty_until')->nullable();
            $table->timestamp('warranty_reminded_at')->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['partner_id', 'status']);
            $table->index('warranty_until');
            $table->index('delivery_id');
            $table->index('order_id');
            $table->index('serial_normalized');
            $table->index('item_id');
            $table->unique('item_serial_id');
            $table->unique('uuid');
        });

        Schema::create('service_tickets', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->string('number', 30);
            $table->foreignId('partner_id')->constrained();
            $table->foreignId('customer_asset_id')->constrained();
            $table->string('status', 30)->default('new');
            $table->string('urgency', 20)->default('normal');
            $table->text('problem');
            $table->string('contact_name', 255)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('location', 255)->nullable();
            $table->string('source', 20)->default('portal');
            $table->bigInteger('telegram_chat_id')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users');
            $table->foreignId('assigned_to')->nullable()->constrained('users');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('respond_due_at')->nullable();
            $table->timestamp('repair_due_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->boolean('under_warranty')->default(false);
            $table->date('warranty_until')->nullable();
            $table->text('resolution')->nullable();
            $table->bigInteger('labor_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('replacement_note')->nullable();
            $table->text('reject_reason')->nullable();
            $table->string('onec_state', 20)->nullable();
            $this->ascii($table->char('onec_guid', 36)->nullable());
            $table->string('onec_number', 40)->nullable();
            $table->timestamp('onec_acked_at')->nullable();
            $table->timestamps();
            $table->timestamp('queued_at')->nullable();
            $table->unsignedInteger('queue_rank')->nullable();
            $table->foreignId('queue_item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->string('queue_need', 255)->nullable();
            $table->date('queue_eta')->nullable();
            $table->index(['status', 'queued_at']);
            $table->unique('uuid');
            $table->index('partner_id');
            $table->unique('number');
            $table->index('customer_asset_id');
            $table->index('assigned_to');
            $table->unique('onec_guid');
        });

        Schema::create('service_ticket_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained();
            $table->string('description', 255);
            $table->decimal('quantity', 15, 3);
            $table->bigInteger('unit_price_minor')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index('item_id');
            $table->index('service_ticket_id');
        });

        Schema::create('service_ticket_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->text('body');
            $table->boolean('is_internal')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->index('service_ticket_id');
        });

        Schema::create('service_ticket_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_ticket_id')->constrained()->cascadeOnDelete();
            $table->string('path', 255);
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->nullable();
            $table->index('service_ticket_id');
        });

        Schema::create('asset_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_asset_id')->constrained()->cascadeOnDelete();
            $table->string('event', 30);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('service_ticket_id')->nullable()->constrained();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->timestamp('created_at')->nullable();
            $table->index('service_ticket_id');
            $table->index('customer_asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_events');
        Schema::dropIfExists('service_ticket_photos');
        Schema::dropIfExists('service_ticket_notes');
        Schema::dropIfExists('service_ticket_parts');
        Schema::dropIfExists('service_tickets');
        Schema::dropIfExists('customer_assets');
        Schema::dropIfExists('requisition_comments');
        Schema::dropIfExists('requisition_lines');
        Schema::dropIfExists('requisitions');
        Schema::dropIfExists('catalog_visibility_rules');
        Schema::dropIfExists('portal_memberships');
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
