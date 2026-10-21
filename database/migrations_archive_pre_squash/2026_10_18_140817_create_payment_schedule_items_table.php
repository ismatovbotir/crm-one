<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 7.7 (DEC-5): the payment schedule drives receivables. Items are superseded, never
     * deleted; Σ active items = order gross. `due_on` null = balance not yet due (on-delivery items
     * get their dates when deliveries are posted, job 5).
     */
    public function up(): void
    {
        Schema::create('payment_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('agreement_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 12);
            $table->string('label')->nullable();
            $table->unsignedBigInteger('delivery_id')->nullable();
            $table->unsignedSmallInteger('percent_bp')->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->date('due_on')->nullable();
            $table->unsignedSmallInteger('position')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('payment_schedule_items')->nullOnDelete();
            $table->timestamps();

            $table->index(['order_id', 'superseded_at', 'position']);
            $table->index(['due_on', 'superseded_at']);
        });

        SchemaChecks::add('payment_schedule_items', ['payment_schedule_items_amount_chk' => 'amount_minor > 0']);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_schedule_items');
    }
};
