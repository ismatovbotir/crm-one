<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 02 rev. 5 (document linking): every 1C-pushed document answers with the CRM id, number
     * and version; updates are matched by CRM id then 1C id, stale ones (older updated_at) are
     * rejected; a change that cannot be applied (receipt already posted, cancel with serials) is
     * flagged for the stock manager instead of rewriting data.
     */
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->unsignedInteger('sync_version')->default(1);
            $table->timestamp('external_updated_at')->nullable();
            $table->boolean('needs_review')->default(false)->index();
            $table->string('review_reason', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropIndex(['needs_review']);
        });
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropColumn(['sync_version', 'external_updated_at', 'needs_review', 'review_reason']);
        });
    }
};
