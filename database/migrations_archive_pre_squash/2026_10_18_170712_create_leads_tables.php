<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 04 section 6: leads (number LD-YYYY-NNNNN, owner, source, free-text company/contact
     * until a customer is linked, BANT flags, money in minor units + currency, close reason,
     * workflow timestamps) and the interested catalogue items.
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 20)->unique();
            $table->string('status', 20);
            $table->string('source', 20);
            $table->string('campaign', 120)->nullable();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('company_name')->nullable();
            $table->string('tax_id', 20)->nullable()->index();
            $table->string('industry', 120)->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_position', 120)->nullable();
            $table->string('contact_email')->nullable()->index();
            $table->string('contact_phone', 32)->nullable()->index();
            $table->text('need')->nullable();
            $table->unsignedBigInteger('est_value_minor')->nullable();
            $table->char('currency', 3);
            $table->date('expected_close_date')->nullable();
            $table->boolean('budget_confirmed')->default(false);
            $table->boolean('authority_confirmed')->default(false);
            $table->boolean('need_confirmed')->default(false);
            $table->boolean('timeline_confirmed')->default(false);
            $table->string('close_reason', 40)->nullable();
            $table->text('close_note')->nullable();
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'status']);
            $table->index(['status', 'last_activity_at']);
        });

        Schema::create('lead_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 3)->default(1);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['lead_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_items');
        Schema::dropIfExists('leads');
    }
};
