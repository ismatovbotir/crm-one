<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 7.13 (DEC-4): append-only ledger of every money event (signed minor units +
     * currency), written in the same transaction as the cached balances. No updated_at.
     */
    public function up(): void
    {
        Schema::create('accounting_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('entry_type', 24);
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
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

            $table->index(['customer_id', 'occurred_on']);
            $table->index('order_id');
            $table->index('payment_id');
            $table->index('period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_entries');
    }
};
