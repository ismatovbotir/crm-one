<?php

namespace Tests\Feature\Telegram;

use App\Livewire\Settings\Telegram;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DBA review (L-item): `telegram:prune` deletes Telegram logs past the retention set in Settings >
 * Telegram; messages linked to a lead and posts still in flight are kept.
 */
class TelegramPruneTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_logs_are_pruned_and_lead_messages_and_open_posts_are_kept(): void
    {
        $old = now()->subDays(400);
        $recent = now()->subDays(10);
        $chat = DB::table('telegram_chats')->insertGetId(['chat_id' => 1001, 'type' => 'private', 'first_seen_at' => $old, 'created_at' => $old, 'updated_at' => $old]);
        $lead = Lead::factory()->create();
        $destination = DB::table('telegram_destinations')->insertGetId(['name' => 'Sales group', 'chat_id' => -100500, 'created_at' => $old, 'updated_at' => $old]);

        DB::table('telegram_updates')->insert([['update_id' => 1, 'received_at' => $old], ['update_id' => 2, 'received_at' => $recent]]);
        DB::table('telegram_messages')->insert([
            ['telegram_chat_id' => $chat, 'message_id' => 1, 'kind' => 'text', 'edit_date' => 0, 'lead_id' => null, 'received_at' => $old],
            ['telegram_chat_id' => $chat, 'message_id' => 2, 'kind' => 'text', 'edit_date' => 0, 'lead_id' => $lead->id, 'received_at' => $old],
            ['telegram_chat_id' => $chat, 'message_id' => 3, 'kind' => 'text', 'edit_date' => 0, 'lead_id' => null, 'received_at' => $recent],
        ]);
        $post = fn (string $key, string $status, $at): array => ['telegram_destination_id' => $destination, 'event' => 'payment.received', 'event_key' => $key, 'chat_id' => -100500, 'status' => $status, 'created_at' => $at, 'updated_at' => $at];
        DB::table('telegram_posts')->insert([$post('a', 'sent', $old), $post('b', 'failed', $old), $post('c', 'retrying', $old), $post('d', 'sent', $recent)]);

        $this->artisan('telegram:prune')->assertSuccessful();

        $this->assertSame([2], DB::table('telegram_updates')->pluck('update_id')->all());
        $this->assertSame([2, 3], DB::table('telegram_messages')->orderBy('message_id')->pluck('message_id')->map(fn ($id): int => (int) $id)->all(), 'Lead messages are kept.');
        $this->assertSame(['c', 'd'], DB::table('telegram_posts')->orderBy('event_key')->pluck('event_key')->all(), 'Posts in flight are kept.');
        $this->assertSame(1, DB::table('telegram_chats')->count(), 'Chats are kept.');
    }

    public function test_retention_comes_from_settings_with_a_floor_of_thirty_days(): void
    {
        $admin = User::factory()->admin()->create();
        Livewire::actingAs($admin)->test(Telegram::class)
            ->set('retentionDays', '10')->call('save')->assertHasErrors(['retentionDays'])
            ->set('retentionDays', '60')->call('save')->assertHasNoErrors();
        $this->assertSame('60', Setting::get('operations.retention.telegram_days'));

        DB::table('telegram_updates')->insert([['update_id' => 1, 'received_at' => now()->subDays(61)], ['update_id' => 2, 'received_at' => now()->subDays(59)]]);
        $this->artisan('telegram:prune')->assertSuccessful();
        $this->assertSame([2], DB::table('telegram_updates')->pluck('update_id')->all());
    }
}
