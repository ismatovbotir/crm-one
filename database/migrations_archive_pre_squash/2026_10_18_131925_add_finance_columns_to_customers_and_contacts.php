<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 section 7.9: CRM-only customer finance data (never pushed to 1C): VAT code, OKED,
     * signatory, credit limit, manual hold; customer bank accounts; billing/signatory contact flags.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('vat_registration_code', 20)->nullable();
            $table->string('oked', 10)->nullable();
            $table->string('signatory_name')->nullable();
            $table->string('signatory_position')->nullable();
            $table->string('signatory_basis')->nullable();
            $table->unsignedBigInteger('credit_limit_minor')->nullable();
            $table->char('credit_limit_currency', 3)->nullable();
            $table->boolean('manual_hold')->default(false);
            $table->string('manual_hold_reason')->nullable();
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('is_billing_contact')->default(false);
            $table->boolean('is_signatory')->default(false);
        });

        Schema::create('customer_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('bank_name');
            $table->string('mfo', 5);
            $table->string('account_number', 20);
            $table->char('currency', 3);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['customer_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_bank_accounts');

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['is_billing_contact', 'is_signatory']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'vat_registration_code', 'oked', 'signatory_name', 'signatory_position', 'signatory_basis',
                'credit_limit_minor', 'credit_limit_currency', 'manual_hold', 'manual_hold_reason',
            ]);
        });
    }
};
