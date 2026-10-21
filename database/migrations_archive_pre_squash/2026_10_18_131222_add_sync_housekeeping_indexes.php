<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DB review 2026-10 N1 (F-02, F-04, F-06), adapted to rev. 4 (price_list_items no longer exist):
     * snapshot finalizer index on stock, retention index on inbound batches (drops two
     * non-selective indexes), time and causer indexes on the activity log.
     */
    public function up(): void
    {
        Schema::table('item_stocks', function (Blueprint $table) {
            $table->index(['warehouse_id', 'last_seen_snapshot_id'], 'item_stocks_wh_seen_index');
            // The single warehouse_id index is a prefix of the new one (the FK keeps an index).
            $table->dropIndex(['warehouse_id']);
        });

        Schema::table('inbound_batches', function (Blueprint $table) {
            $table->index('received_at');
            $table->dropIndex(['endpoint']);
            $table->dropIndex(['status']);
        });

        Schema::table('activity_log', function (Blueprint $table) {
            $table->index('created_at');

            // MySQL already has the implicit FK index on causer_id; SQLite has none.
            if (DB::getDriverName() !== 'mysql') {
                $table->index('causer_id');
            }
        });

        Schema::table('inbound_snapshots', function (Blueprint $table) {
            $table->index('finalized_at');
        });
    }

    public function down(): void
    {
        Schema::table('inbound_snapshots', function (Blueprint $table) {
            $table->dropIndex(['finalized_at']);
        });

        Schema::table('activity_log', function (Blueprint $table) {
            if (DB::getDriverName() !== 'mysql') {
                $table->dropIndex(['causer_id']);
            }

            $table->dropIndex(['created_at']);
        });

        Schema::table('inbound_batches', function (Blueprint $table) {
            $table->index('status');
            $table->index('endpoint');
            $table->dropIndex(['received_at']);
        });

        Schema::table('item_stocks', function (Blueprint $table) {
            $table->index('warehouse_id');
            $table->dropIndex('item_stocks_wh_seen_index');
        });
    }
};
