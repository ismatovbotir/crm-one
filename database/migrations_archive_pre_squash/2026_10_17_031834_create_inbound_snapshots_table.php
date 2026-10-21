<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 02 section 7.4: multi-part full snapshots; deactivation runs only after the last part.
     */
    public function up(): void
    {
        Schema::create('inbound_snapshots', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('endpoint', 20);
            $table->json('scope')->nullable();
            $table->unsignedSmallInteger('total_parts');
            $table->json('parts_processed')->nullable();
            $table->string('status', 15)->index();
            $table->json('result')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_snapshots');
    }
};
