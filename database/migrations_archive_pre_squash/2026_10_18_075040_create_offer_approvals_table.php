<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 05 section 7.7 (simplified to one discount step per submission): the worst-case
     * discount that needed approval, who decided, and the content hash it is bound to.
     */
    public function up(): void
    {
        Schema::create('offer_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('required_bp');
            $table->unsignedSmallInteger('owner_limit_bp');
            $table->json('reasons')->nullable();
            $table->string('status', 10);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('comment')->nullable();
            $table->char('content_hash', 64);
            $table->timestamps();

            $table->index(['offer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_approvals');
    }
};
