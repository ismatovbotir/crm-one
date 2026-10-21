<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 7.10 (DEC-6): proformas issued by the CRM and tax documents (VAT invoice, act,
     * waybill) issued in 1C / e-invoicing and registered here. Lines snapshot codes from the order
     * line; only registered tax documents move `invoiced_qty`. Never deleted once issued.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('type', 12);
            $table->string('status', 10);
            $table->string('source', 16);
            $table->string('number', 40);
            $table->date('issued_on');
            $table->date('due_on')->nullable();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
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
            $table->char('onec_guid', 36)->nullable()->unique();
            $table->string('onec_state', 16)->default('not_sent');
            $table->json('code_warnings')->nullable();
            $table->timestamps();

            $table->unique(['type', 'number', 'issued_on']);
            $table->index('order_id');
            $table->index(['customer_id', 'status']);
            $table->index(['type', 'issued_on']);
            $table->index('period');
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_line_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('delivery_line_id')->nullable();
            $table->unsignedSmallInteger('position');
            $table->char('item_external_id', 36)->nullable();
            $table->string('sku', 64);
            $table->string('name');
            $table->string('unit_code', 16)->nullable();
            $table->string('unit_name', 64);
            $table->unsignedSmallInteger('tax_rate_bp');
            $table->boolean('is_vat_exempt')->default(false);
            $table->string('accountant_code', 64)->nullable()->index();
            $table->string('class_code', 32)->nullable()->index();
            $table->string('package_code', 32)->nullable();
            $table->decimal('quantity', 15, 3);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedSmallInteger('discount_bp')->default(0);
            $table->unsignedBigInteger('line_net_minor');
            $table->unsignedBigInteger('line_vat_minor');
            $table->unsignedBigInteger('line_gross_minor');
            $table->timestamps();

            $table->index('order_line_id');
        });

        SchemaChecks::add('invoice_lines', ['invoice_lines_qty_chk' => 'quantity > 0']);
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
