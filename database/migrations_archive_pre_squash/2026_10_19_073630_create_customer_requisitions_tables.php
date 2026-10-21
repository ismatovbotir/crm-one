<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 10: price-free portal catalog (CRM-local portal flags/names on 1C categories and items,
     * per-customer allow/deny rules) and customer requisitions (requests from the client's team,
     * consolidated by their purchase manager and sent to us; answered with an offer linked by
     * `offers.requisition_id`).
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('portal_visible')->default(true);
            $table->json('portal_names')->nullable();
            $table->integer('portal_sort')->default(0);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->boolean('portal_visible')->default(true);
        });

        Schema::create('catalog_visibility_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->index()->constrained('customers')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->index()->constrained('categories')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->index()->constrained('items')->cascadeOnDelete();
            $table->string('mode', 10);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['organization_id', 'category_id', 'item_id']);
        });

        Schema::create('requisitions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 30)->unique();
            $table->foreignId('organization_id')->index()->constrained('customers');
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('requester_id')->index()->constrained('users');
            $table->foreignId('purchase_manager_id')->nullable()->constrained('users');
            $table->string('title', 255)->nullable();
            $table->string('delivery_location', 500)->nullable();
            $table->date('needed_by')->nullable();
            $table->text('notes')->nullable();
            $table->text('return_comment')->nullable();
            $table->text('reject_reason')->nullable();
            $table->text('decline_reason')->nullable();
            $table->foreignId('owner_id')->nullable()->index()->constrained('users');
            $table->foreignId('merged_into_id')->nullable()->index()->constrained('requisitions');
            $table->foreignId('split_from_id')->nullable()->index()->constrained('requisitions');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('requisition_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->index()->constrained('requisitions')->cascadeOnDelete();
            $table->string('kind', 10);
            $table->foreignId('item_id')->nullable()->index()->constrained('items');
            $table->foreignId('category_id')->nullable()->index()->constrained('categories');
            $table->string('description', 1000)->nullable();
            $table->decimal('quantity', 15, 3);
            $table->string('unit', 30)->nullable();
            $table->date('needed_by')->nullable();
            $table->foreignId('mapped_item_id')->nullable()->index()->constrained('items');
            $table->foreignId('source_line_id')->nullable()->index()->constrained('requisition_lines')->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('requisition_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->index()->constrained('requisitions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->text('body');
            $table->string('audience', 10)->default('shared');
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('offers', function (Blueprint $table) {
            $table->foreignId('requisition_id')->nullable()->index()->constrained('requisitions');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropForeign(['requisition_id']);
            $table->dropIndex(['requisition_id']);
        });

        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('requisition_id');
        });

        Schema::dropIfExists('requisition_comments');
        Schema::dropIfExists('requisition_lines');
        Schema::dropIfExists('requisitions');
        Schema::dropIfExists('catalog_visibility_rules');

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('portal_visible');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['portal_visible', 'portal_names', 'portal_sort']);
        });
    }
};
