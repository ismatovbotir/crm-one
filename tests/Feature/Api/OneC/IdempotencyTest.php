<?php

namespace Tests\Feature\Api\OneC;

use App\Jobs\OneC\ProcessInboundBatch;
use App\Models\InboundBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOneCApi;
use Tests\TestCase;

/**
 * Spec 02 7.2 step 3, AC-I.14..I.15: batch_id idempotency.
 */
class IdempotencyTest extends TestCase
{
    use InteractsWithOneCApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableInbound();
        $this->issueToken();
    }

    public function test_identical_replay_returns_the_stored_response_without_requeueing(): void
    {
        Queue::fake();
        $body = $this->envelope(['units' => [$this->unitRecord()], 'items' => [$this->itemRecord('00-1'), $this->itemRecord('00-2', ['type' => 'kit'])]]);

        $first = $this->onec('POST', '/items', $body, headers: ['Idempotency-Key' => $body['batch_id']])->assertStatus(207);
        $second = $this->onec('POST', '/items', $body, headers: ['Idempotency-Key' => $body['batch_id']]);

        $second->assertStatus(207)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertEquals($first->json(), $second->json(), 'Same response (JSON key order is not guaranteed on MySQL).');
        $this->assertSame(1, InboundBatch::query()->count());
        Queue::assertPushed(ProcessInboundBatch::class, 1);
    }

    public function test_same_batch_id_with_a_different_body_is_409(): void
    {
        $body = $this->envelope(['units' => [$this->unitRecord()]]);
        $this->onec('POST', '/items', $body)->assertStatus(202);

        $body['units'][0]['name'] = 'piece';

        $this->onec('POST', '/items', $body)->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');
    }

    public function test_idempotency_key_must_equal_batch_id(): void
    {
        $this->onec('POST', '/items', $this->envelope(['units' => [$this->unitRecord()]]), headers: ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.Idempotency-Key', ['must_equal_batch_id']);
    }

    public function test_another_token_cannot_read_a_stored_response_by_replaying(): void
    {
        $body = $this->envelope(['units' => [$this->unitRecord()]]);
        $this->onec('POST', '/items', $body)->assertStatus(202);
        $firstToken = $this->plainToken;

        $this->issueToken();
        $this->assertNotSame($firstToken, $this->plainToken);

        $this->onec('POST', '/items', $body)
            ->assertStatus(409)
            ->assertJsonMissingPath('accepted');
    }

    public function test_replay_of_a_processed_batch_does_not_apply_it_twice(): void
    {
        $body = $this->envelope(['units' => [$this->unitRecord()], 'items' => [$this->itemRecord('00-1')]]);

        $this->onec('POST', '/items', $body)->assertStatus(202);
        $this->onec('POST', '/items', $body)->assertStatus(202)->assertHeader('Idempotent-Replayed', 'true');

        $this->assertEquals(['created' => 2, 'updated' => 0, 'unchanged' => 0, 'stale' => 0, 'deactivated' => 0, 'failed' => 0, 'rejected' => 0], InboundBatch::query()->sole()->counts);
    }
}
