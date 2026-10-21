<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DB review 2026-10 S1 (F-03, F-04): the raw 1C payload (1-2 MB) moves out of the hot
     * inbound_batches row into inbound_batch_payloads. Retention deletes only the payload row; the
     * batch row (hash, counts, stored response for idempotent replay) is kept. Backfilled in
     * chunks; the old column is dropped in the same migration (code and schema ship together).
     */
    public function up(): void
    {
        Schema::create('inbound_batch_payloads', function (Blueprint $table) {
            $table->unsignedBigInteger('inbound_batch_id')->primary();
            $table->foreign('inbound_batch_id')->references('id')->on('inbound_batches')->cascadeOnDelete();
            $table->longText('payload');
            $table->timestamp('created_at')->index();
        });

        DB::table('inbound_batches')->whereNotNull('payload')->select(['id', 'payload', 'received_at'])->orderBy('id')->chunkById(200, function ($rows): void {
            DB::table('inbound_batch_payloads')->insert($rows->map(fn (object $row): array => [
                'inbound_batch_id' => $row->id,
                'payload' => $row->payload,
                'created_at' => $row->received_at ?? now(),
            ])->all());
        });

        Schema::table('inbound_batches', function (Blueprint $table) {
            $table->dropColumn('payload');
        });
    }

    public function down(): void
    {
        Schema::table('inbound_batches', function (Blueprint $table) {
            $table->longText('payload')->nullable()->after('payload_hash');
        });

        DB::table('inbound_batch_payloads')->orderBy('inbound_batch_id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('inbound_batches')->where('id', $row->inbound_batch_id)->update(['payload' => $row->payload]);
            }
        }, 'inbound_batch_id');

        Schema::dropIfExists('inbound_batch_payloads');
    }
};
