<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 section 7.9: our bank accounts (printed on agreements and proformas) and the people
     * who sign for the company (default: the director / CEO, Q-7 answered: the CEO signs).
     * Company profile fields themselves live in the settings table (`company.*`).
     */
    public function up(): void
    {
        Schema::create('company_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name');
            $table->string('mfo', 5);
            $table->string('account_number', 20);
            $table->char('currency', 3);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->char('onec_guid', 36)->nullable()->unique();
            $table->timestamps();

            $table->index(['currency', 'is_default']);
        });

        Schema::create('company_signatories', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('position');
            $table->string('basis')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_signatories');
        Schema::dropIfExists('company_bank_accounts');
    }
};
