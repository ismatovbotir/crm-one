<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 04 section 16 (lead capture redesign): priority, sale type, cached lead score and the
     * duplicate-override reason on leads; follow-up tasks with due date and reminder; one
     * server-side form draft per user.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('priority', 10)->default('normal');
            $table->string('sale_type', 10)->nullable();
            $table->unsignedTinyInteger('score')->default(0);
            $table->text('duplicate_override_reason')->nullable();
        });

        Schema::create('lead_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at');
            $table->timestamp('remind_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['completed_at', 'remind_at']);
            $table->index(['assignee_id', 'completed_at', 'due_at']);
        });

        Schema::create('lead_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_drafts');
        Schema::dropIfExists('lead_follow_ups');

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['priority', 'sale_type', 'score', 'duplicate_override_reason']);
        });
    }
};
