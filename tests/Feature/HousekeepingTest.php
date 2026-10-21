<?php

namespace Tests\Feature;

use App\Models\SerialImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Scheduled housekeeping (job 10 go-live): expired one-time codes, Telegram tokens and webhook ids
 * are pruned; serial import files are deleted after the retention period while batch metadata
 * stays, and preview rows of imports that were never committed are removed.
 */
class HousekeepingTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_prune_removes_only_expired_rows(): void
    {
        $user = User::factory()->create();
        DB::table('login_codes')->insert([
            ['user_id' => $user->id, 'purpose' => 'portal_login', 'channel' => 'email', 'code_hash' => str_repeat('a', 64), 'expires_at' => now()->subDays(2), 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $user->id, 'purpose' => 'portal_login', 'channel' => 'email', 'code_hash' => str_repeat('b', 64), 'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('telegram_updates')->insert([['update_id' => 1, 'received_at' => now()->subDays(8)], ['update_id' => 2, 'received_at' => now()]]);

        $this->artisan('auth:prune')->assertSuccessful();

        $this->assertSame(1, DB::table('login_codes')->count());
        $this->assertSame([2], DB::table('telegram_updates')->pluck('update_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_serial_import_files_are_deleted_after_retention(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('serial-imports/old.csv', 'SN1');
        Storage::disk('local')->put('serial-imports/new.csv', 'SN2');
        $base = ['context_type' => 'goods_receipt_line', 'context_id' => 1, 'file_hash' => str_repeat('c', 64), 'size_bytes' => 3, 'format' => 'csv', 'status' => 'validated'];
        $old = SerialImportBatch::query()->create([...$base, 'file_name' => 'old.csv', 'disk_path' => 'serial-imports/old.csv', 'expires_at' => now()->subDay()]);
        $fresh = SerialImportBatch::query()->create([...$base, 'file_name' => 'new.csv', 'disk_path' => 'serial-imports/new.csv', 'expires_at' => now()->addDays(10)]);
        DB::table('serial_import_rows')->insert(['batch_id' => $old->id, 'row_no' => 1, 'serial_raw' => 'SN1', 'result' => 'ok']);

        $this->artisan('stock:prune-serial-imports')->assertSuccessful();

        Storage::disk('local')->assertMissing('serial-imports/old.csv');
        Storage::disk('local')->assertExists('serial-imports/new.csv');
        $this->assertNull($old->fresh()->disk_path);
        $this->assertSame('old.csv', $old->fresh()->file_name);
        $this->assertSame(0, DB::table('serial_import_rows')->where('batch_id', $old->id)->count());
        $this->assertSame('serial-imports/new.csv', $fresh->fresh()->disk_path);
    }
}
