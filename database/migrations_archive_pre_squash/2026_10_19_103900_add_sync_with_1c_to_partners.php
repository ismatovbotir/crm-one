<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 02 rev. 4: 1C sync is optional per partner. `sync_with_1c` defaults to false for every new
     * partner. Existing partners that are already in the exchange (they have a 1C GUID or any
     * customer outbox row) get true so nothing silently stops; the rest stay false.
     */
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->boolean('sync_with_1c')->default(false)->index();
        });

        DB::table('partners')
            ->where(fn ($query) => $query->whereNotNull('external_id')->orWhereIn('id', DB::table('customer_outbox')->select('customer_id')))
            ->update(['sync_with_1c' => true]);
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropIndex(['sync_with_1c']);
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('sync_with_1c');
        });
    }
};
