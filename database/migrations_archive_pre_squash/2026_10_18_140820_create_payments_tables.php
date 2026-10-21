<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 7.11/7.12 (DEC-3): payments are immutable (model guard; corrections by void and new
     * payment, refunds as outgoing payments) and allocations are append-only (deallocation =
     * reversal row with a negative amount).
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('number', 20)->unique();
            $table->string('direction', 8);
            $table->string('kind', 10);
            $table->string('status', 16)->index();
            $table->string('source', 8);
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->date('paid_on')->index();
            $table->string('method', 16);
            $table->foreignId('company_bank_account_id')->nullable()->constrained('company_bank_accounts')->nullOnDelete();
            $table->string('payer_name');
            $table->string('payer_tax_id', 32)->nullable();
            $table->string('payer_account', 34)->nullable();
            $table->string('reference_number', 64)->nullable()->index();
            $table->text('purpose')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('refund_of_payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->foreignId('proof_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->unsignedBigInteger('allocated_minor')->default(0);
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->char('period', 7)->index();
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
            $table->char('onec_guid', 36)->nullable()->unique();
            $table->string('onec_number', 40)->nullable();
            $table->boolean('onec_posted')->nullable();
            $table->string('onec_version', 64)->nullable();
            $table->timestamp('onec_updated_at')->nullable();
            $table->timestamp('onec_acked_at')->nullable();
            $table->string('onec_error')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'paid_on']);
            $table->index(['source', 'status']);
        });

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
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('payment_allocations')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->char('period', 7);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['payment_id', 'order_id']);
            $table->index('order_id');
        });

        SchemaChecks::add('payments', [
            'payments_amount_chk' => 'amount_minor > 0',
            'payments_allocated_chk' => 'allocated_minor + refunded_minor <= amount_minor',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
