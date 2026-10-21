<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 05 section 7.5 (DEC-4): current customer choices, one row per selected line.
     */
    public function up(): void
    {
        Schema::create('offer_line_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_group_id')->constrained('offer_option_groups')->cascadeOnDelete();
            $table->foreignId('offer_line_id')->unique()->constrained('offer_lines')->cascadeOnDelete();
            $table->string('selected_by_actor', 12);
            $table->foreignId('selected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 12);
            $table->timestamp('selected_at');

            $table->index(['offer_id', 'option_group_id']);
            $table->index('option_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_line_selections');
    }
};
