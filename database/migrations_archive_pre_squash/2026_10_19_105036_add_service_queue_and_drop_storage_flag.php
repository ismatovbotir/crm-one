<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 09 section 15 (user correction 2026-10-19): "out of stock" = the service request waits in a
     * queue. Tickets get the queue fields (entered at, manual rank within priority, needed item or
     * text, estimated date); the asset "in storage" flag is removed. Flagged assets (if any) get an
     * `asset_events` row `storage_flag_removed` first, so nothing disappears silently. Tickets already
     * waiting for parts enter the queue at their last update time.
     */
    public function up(): void
    {
        Schema::table('service_tickets', function (Blueprint $table) {
            $table->timestamp('queued_at')->nullable();
            $table->unsignedInteger('queue_rank')->nullable();
            $table->foreignId('queue_item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->string('queue_need', 255)->nullable();
            $table->date('queue_eta')->nullable();
            $table->index(['status', 'queued_at']);
        });

        DB::table('service_tickets')->where('status', 'waiting_for_parts')->update(['queued_at' => DB::raw('updated_at')]);

        foreach (DB::table('customer_assets')->where('in_storage', true)->pluck('id') as $assetId) {
            DB::table('asset_events')->insert(['customer_asset_id' => $assetId, 'event' => 'storage_flag_removed', 'comment' => 'Spec 09 section 15', 'created_at' => now()]);
        }

        Schema::table('customer_assets', function (Blueprint $table) {
            $table->dropColumn('in_storage');
        });
    }

    public function down(): void
    {
        Schema::table('customer_assets', function (Blueprint $table) {
            $table->boolean('in_storage')->default(false);
        });

        DB::table('customer_assets')->whereIn('id', DB::table('asset_events')->where('event', 'storage_flag_removed')->select('customer_asset_id'))->update(['in_storage' => true]);
        DB::table('asset_events')->where('event', 'storage_flag_removed')->delete();

        Schema::table('service_tickets', function (Blueprint $table) {
            $table->dropIndex(['status', 'queued_at']);
            $table->dropForeign(['queue_item_id']);
        });

        Schema::table('service_tickets', function (Blueprint $table) {
            $table->dropColumn(['queued_at', 'queue_rank', 'queue_item_id', 'queue_need', 'queue_eta']);
        });
    }
};
