<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 02 section 11.4: "last received" per feed; drives staleness alerts (BR-I.21).
     */
    public function up(): void
    {
        Schema::create('integration_feeds', function (Blueprint $table) {
            $table->string('feed', 20)->primary();
            $table->timestamp('last_received_at')->nullable();
            $table->char('last_batch_id', 36)->nullable();
            $table->timestamp('last_full_snapshot_at')->nullable();
            $table->string('cursor')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_feeds');
    }
};
