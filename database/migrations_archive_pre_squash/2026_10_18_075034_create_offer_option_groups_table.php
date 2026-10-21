<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 05 section 7.3 (DEC-1): one selection engine for alternative and optional lines.
     * Groups share the block ordering (`position`) with fixed lines.
     */
    public function up(): void
    {
        Schema::create('offer_option_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->uuid('stable_key');
            $table->unsignedSmallInteger('position');
            $table->string('kind', 12);
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('min_select');
            $table->unsignedTinyInteger('max_select');
            $table->timestamps();

            $table->index(['offer_id', 'position']);
            // DB review F-14: stable keys are unique per offer (copied across versions).
            $table->unique(['offer_id', 'stable_key']);
        });

        SchemaChecks::add('offer_option_groups', [
            'offer_groups_min_max_chk' => 'min_select <= max_select',
            'offer_groups_max_chk' => 'max_select >= 1',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_option_groups');
    }
};
