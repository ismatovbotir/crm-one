<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 section 7.2: orders are created only from an accepted offer (`offer_id` unique,
     * DEC-1); amounts copied verbatim from OfferToOrderPayload. Never deleted (restrict FKs).
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('number', 20)->unique();
            $table->string('status', 24);
            $table->string('payment_status', 16)->default('unpaid');
            $table->foreignId('offer_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('offer_version');
            $table->char('offer_accepted_hash', 64)->nullable();
            $table->unsignedBigInteger('lead_id')->nullable()->index();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
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
            $table->string('agreement_waiver_reason')->nullable();
            $table->boolean('addendum_pending')->default(false);
            $table->json('hold_reasons')->nullable();
            $table->boolean('on_hold')->default(false)->index();
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
            $table->char('onec_guid', 36)->nullable()->unique();
            $table->string('onec_number', 30)->nullable();
            $table->string('onec_state', 16)->default('not_sent');
            $table->timestamp('onec_acked_at')->nullable();
            $table->string('onec_error')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['owner_id', 'status']);
            $table->index(['status', 'payment_status']);
            $table->index(['status', 'promised_delivery_on']);
            $table->index('updated_at');
        });

        SchemaChecks::add('orders', [
            'orders_paid_chk' => 'paid_minor >= 0',
            'orders_prepayment_chk' => 'prepayment_bp is null or prepayment_bp <= 10000',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
