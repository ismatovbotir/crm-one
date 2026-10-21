<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 05 section 7.6: append-only audit of every choice (who, when, how, evidence).
     */
    public function up(): void
    {
        Schema::create('offer_selection_events', function (Blueprint $table) {
            $table->id();
            // Evidence: restrict (DB review F-13). Group/line/contact ids are plain columns on purpose (history survives line removal).
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('option_group_id')->nullable();
            $table->unsignedBigInteger('offer_line_id')->nullable()->index();
            $table->string('event', 20);
            $table->string('actor', 12);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('channel', 12)->nullable();
            $table->text('note')->nullable();
            $table->text('evidence_note')->nullable();
            $table->unsignedInteger('selection_revision')->default(0);
            $table->timestamp('created_at');

            $table->index(['offer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_selection_events');
    }
};
