<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 section 28 (user decision 2026-10-19, simplified): a "power of attorney received"
     * checkbox on the order (who ticked it, when, optional note). Required by default for legal
     * entities, government bodies and sole proprietors on B2B orders; while it is required and not
     * ticked the tax invoice (счёт-фактура) cannot be registered, unless an authorized role
     * overrides with a reason. Existing orders are not made to require it.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('poa_required')->default(false);
            $table->timestamp('poa_received_at')->nullable();
            $table->foreignId('poa_received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('poa_note', 255)->nullable();
            $table->timestamp('poa_waived_at')->nullable();
            $table->foreignId('poa_waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('poa_waiver_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['poa_received_by']);
            $table->dropForeign(['poa_waived_by']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['poa_required', 'poa_received_at', 'poa_received_by', 'poa_note', 'poa_waived_at', 'poa_waived_by', 'poa_waiver_reason']);
        });
    }
};
