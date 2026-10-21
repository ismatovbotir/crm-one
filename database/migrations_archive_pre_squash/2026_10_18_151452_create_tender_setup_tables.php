<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 03 7.3: evaluation criteria (weights locked at publish), knock-out requirements, the
     * evaluation committee (conflict-of-interest declaration), invitations, Q&A and addenda.
     */
    public function up(): void
    {
        Schema::create('tender_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('label')->nullable();
            $table->unsignedSmallInteger('weight_bp');
            $table->string('method', 20);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['tender_id', 'code']);
        });

        Schema::create('tender_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->boolean('is_knockout')->default(false);
            $table->timestamps();
        });

        Schema::create('tender_committee', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 8)->default('member');
            $table->timestamp('coi_declared_at')->nullable();
            $table->timestamps();

            $table->unique(['tender_id', 'user_id']);
        });

        Schema::create('tender_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->foreignId('counterparty_id')->constrained()->restrictOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->string('status', 16)->default('invited');
            $table->string('channel_preference', 10)->default('employee');
            $table->string('contact_email')->nullable();
            $table->string('notice_locale', 5)->nullable();
            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->boolean('needs_addendum_confirmation')->default(false);
            $table->timestamps();

            $table->unique(['tender_id', 'counterparty_id']);
            $table->index('counterparty_id');
        });

        Schema::create('tender_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('received_via', 12)->nullable();
            $table->text('question');
            $table->text('answer')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['tender_id', 'published_at']);
        });

        Schema::create('tender_addenda', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('summary');
            $table->text('changes')->nullable();
            $table->timestamp('previous_bids_due_at')->nullable();
            $table->timestamp('new_bids_due_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['tender_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_addenda');
        Schema::dropIfExists('tender_questions');
        Schema::dropIfExists('tender_invitations');
        Schema::dropIfExists('tender_committee');
        Schema::dropIfExists('tender_requirements');
        Schema::dropIfExists('tender_criteria');
    }
};
