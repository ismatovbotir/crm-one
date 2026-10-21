<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 section 7.8 (DEC-10): versioned agreement templates per locale with whitelisted
     * `{{ var }}` placeholders and optional `{{#clause:code}}` blocks; published versions immutable.
     */
    public function up(): void
    {
        Schema::create('agreement_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('kind', 12);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('agreement_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('agreement_templates')->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('locale', 5);
            $table->longText('body');
            $table->json('variables')->nullable();
            $table->json('clauses')->nullable();
            $table->string('status', 10);
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->char('checksum', 64)->nullable();
            $table->timestamps();

            $table->unique(['template_id', 'version', 'locale']);
            $table->index(['template_id', 'locale', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreement_template_versions');
        Schema::dropIfExists('agreement_templates');
    }
};
