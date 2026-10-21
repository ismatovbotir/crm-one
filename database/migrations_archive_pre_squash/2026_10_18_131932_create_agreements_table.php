<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 section 7.8: agreements (order, framework, addendum) with a frozen variables
     * snapshot, review, sending, signing (scan verified by an internal user, DEC-11), activation,
     * expiry, renewal and termination. One non-cancelled order agreement per order (generated key).
     */
    public function up(): void
    {
        Schema::create('agreements', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('number', 30)->unique();
            $table->string('kind', 12);
            $table->string('status', 20)->index();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
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
            $table->string('customer_signatory_name')->nullable();
            $table->string('customer_signatory_position')->nullable();
            $table->string('customer_signatory_basis')->nullable();
            $table->foreignId('signed_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->date('terminated_on')->nullable();
            $table->string('termination_reason_code', 30)->nullable();
            $table->text('termination_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->json('reminders_sent')->nullable();
            $table->char('onec_guid', 36)->nullable()->unique();
            $table->string('onec_number', 30)->nullable();
            $table->string('onec_state', 16)->default('not_sent');
            $table->timestamp('onec_acked_at')->nullable();
            $table->timestamps();

            // One non-cancelled order agreement per order (Spec 06 BR-AG.3; generated unique key, DB review 4.4 pattern).
            $table->unsignedBigInteger('active_order_key')->nullable()
                ->virtualAs("case when kind = 'order' and status <> 'cancelled' then order_id end");
            $table->unique('active_order_key', 'agreements_one_active_per_order');

            $table->index(['customer_id', 'status']);
            $table->index(['status', 'ends_on']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('framework_agreement_id')->references('id')->on('agreements')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['framework_agreement_id']);
        });

        Schema::dropIfExists('agreements');
    }
};
