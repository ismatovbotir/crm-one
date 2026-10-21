<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CRM-local catalog analogues (Spec 05 section 7.10, DEC-11): items stay read-only (1C), the
     * links between them belong to the CRM. `source = onec` is reserved for a future 1C feed.
     */
    public function up(): void
    {
        Schema::create('item_alternatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alternative_item_id')->constrained('items')->cascadeOnDelete();
            $table->string('relation', 12);
            $table->boolean('is_mutual')->default(true);
            $table->string('default_reason', 24)->nullable();
            $table->text('note')->nullable();
            $table->string('source', 8)->default('crm');
            $table->string('status', 10)->default('approved');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['item_id', 'alternative_item_id']);
            $table->index('alternative_item_id');
        });

        SchemaChecks::add('item_alternatives', ['item_alternatives_self_chk' => 'item_id <> alternative_item_id']);
    }

    public function down(): void
    {
        Schema::dropIfExists('item_alternatives');
    }
};
