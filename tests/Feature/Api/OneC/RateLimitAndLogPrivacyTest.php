<?php

namespace Tests\Feature\Api\OneC;

use App\Models\InboundBatch;
use App\Models\IntegrationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\InteractsWithOneCApi;
use Tests\TestCase;

/**
 * Spec 02 AC-I.7 (rate limit) and AC-I.48 / BR-I.19 (no token leakage in logs).
 */
class RateLimitAndLogPrivacyTest extends TestCase
{
    use InteractsWithOneCApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableInbound();
        $this->issueToken();
    }

    public function test_requests_above_the_per_token_limit_get_429_with_retry_after(): void
    {
        config(['onec.inbound.rate_per_minute' => 3]);

        foreach (range(1, 3) as $attempt) {
            $this->onec('GET', '/ping')->assertOk();
        }

        $this->onec('GET', '/ping')
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('error.code', 'rate_limited');

        // The limit is per token: a second token still gets through.
        $this->issueToken();
        $this->onec('GET', '/ping')->assertOk();
    }

    public function test_every_call_is_logged_without_body_query_or_token(): void
    {
        $messages = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$messages): void {
            $messages[] = $event->message.json_encode($event->context);
        });

        $this->onec('POST', '/items?debug=1', $this->envelope(['units' => [$this->unitRecord()], 'items' => [$this->itemRecord('00-1', ['unit_id' => $this->guid('unit-x')])]]))->assertStatus(202);
        $this->onec('GET', '/ping', token: $this->plainToken.'tampered')->assertUnauthorized();

        $logs = IntegrationLog::query()->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(['/api/v1/integration/1c/items', 202, 'info'], [$logs[0]->path, $logs[0]->http_status, $logs[0]->level]);
        $this->assertSame($this->token->token_prefix, $logs[0]->token_prefix);
        $this->assertSame(InboundBatch::query()->value('id'), $logs[0]->inbound_batch_id);
        $this->assertSame(['unauthenticated', 401], [$logs[1]->error_code, $logs[1]->http_status]);

        $haystack = json_encode([
            DB::table('integration_logs')->get(),
            DB::table('inbound_batches')->get(),
            DB::table('integration_tokens')->get(),
            DB::table('activity_log')->get(),
            $messages,
        ]);

        $this->assertStringNotContainsString($this->plainToken, $haystack);
        $this->assertStringNotContainsString(substr($this->plainToken, 11), $haystack);
    }
}
