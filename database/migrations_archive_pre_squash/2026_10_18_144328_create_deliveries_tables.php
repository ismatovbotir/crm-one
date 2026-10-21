<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 7.6 (DEC-16): the delivery note is the sales document. Only posted notes add to
     * `order_lines.delivered_qty`; serialized lines carry one serial row per unit; posted notes are
     * immutable (corrections by adjustments or returns).
     */
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('number', 20)->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('status', 12)->index();
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('planned_on')->nullable();
            $table->date('delivered_on')->nullable();
            $table->unsignedBigInteger('shipment_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->char('onec_guid', 36)->nullable()->unique();
            $table->string('onec_number', 30)->nullable();
            $table->string('onec_state', 16)->default('not_sent');
            $table->timestamp('onec_acked_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });

        Schema::create('delivery_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_line_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->boolean('is_serialized')->default(false);
            $table->decimal('quantity', 15, 3)->default(0);
            $table->decimal('returned_qty', 15, 3)->default(0);
            $table->timestamps();

            $table->index(['delivery_id', 'position']);
            $table->index('order_line_id');
        });

        SchemaChecks::add('delivery_lines', ['delivery_lines_qty_chk' => 'quantity >= 0 and returned_qty <= quantity']);
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_lines');
        Schema::dropIfExists('deliveries');
    }
};
