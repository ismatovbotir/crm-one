<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 05 section 7.1. Each version is its own row (DEC-2) sharing `number`; money in minor
     * units of `currency`; totals are written only by the server-side calculator. `lead_id` is a
     * plain nullable column until Spec 04 (leads) adds the foreign key.
     */
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('number', 20);
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('root_offer_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->foreignId('previous_version_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->foreignId('superseded_by_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->string('status', 20);
            $table->unsignedBigInteger('lead_id')->nullable()->index();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
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

            $table->unique(['number', 'version']);
            $table->index(['customer_id', 'status']);
            $table->index(['owner_id', 'status']);
            $table->index(['status', 'valid_until']);
            $table->index(['root_offer_id', 'version']);
            // DB review F-15: the list sorts by updated_at (own scope filters by owner).
            $table->index(['owner_id', 'updated_at']);
            $table->index('updated_at');
        });

        SchemaChecks::add('offers', [
            'offers_range_chk' => 'range_min_gross_minor <= range_max_gross_minor',
            'offers_max_discount_chk' => 'max_discount_bp <= 10000',
            'offers_version_chk' => 'version >= 1',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
