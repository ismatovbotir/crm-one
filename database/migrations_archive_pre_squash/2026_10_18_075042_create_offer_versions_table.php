<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 05 section 7.8 (DEC-2): the immutable snapshot of what the customer received, written
     * once when a version is sent. The hash proves what was offered.
     */
    public function up(): void
    {
        Schema::create('offer_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('root_offer_id')->constrained('offers')->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            $table->char('content_hash', 64);
            $table->json('payload');
            $table->timestamp('sent_at');
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 20)->nullable();
            $table->string('document_locale', 5)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_versions');
    }
};
