<?php

namespace Tests\Feature\Api\OneC;

use App\Enums\IntegrationAbility;
use App\Models\InboundBatch;
use App\Models\IntegrationToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithOneCApi;
use Tests\TestCase;

/**
 * Spec 02 AC-I.1..I.9, BR-I.18/I.18a: token auth, abilities, IP allowlist, HMAC, HTTPS,
 * statelessness and the enable switch of the 1C inbound API.
 */
class AuthTest extends TestCase
{
    use InteractsWithOneCApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableInbound();
    }

    #[DataProvider('badTokens')]
    public function test_missing_unknown_revoked_or_expired_tokens_get_a_generic_401(string $case): void
    {
        $token = $this->issueToken();

        $presented = match ($case) {
            'missing' => '',
            'unknown' => IntegrationToken::PREFIX.str_repeat('x', 48),
            'revoked' => tap($this->plainToken, fn () => $token->revoke()),
            'expired' => tap($this->plainToken, fn () => $token->forceFill(['expires_at' => now()->subMinute()])->save()),
        };

        $this->onec('POST', '/items', $this->envelope(['units' => [$this->unitRecord()]]), $presented)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated')
            ->assertJsonPath('error.message', 'Invalid or missing API token.');

        $this->assertSame(0, InboundBatch::query()->count());
    }

    public static function badTokens(): array
    {
        return [['missing'], ['unknown'], ['revoked'], ['expired']];
    }

    /**
     * @param  list<string>  $abilities
     */
    #[DataProvider('abilityMatrix')]
    public function test_each_endpoint_requires_its_ability(string $path, array $abilities, int $status): void
    {
        $this->issueToken($abilities);

        $body = match ($path) {
            '/items' => $this->envelope(['units' => [$this->unitRecord()]]),
            '/item-stock' => $this->envelope(['as_of' => '2026-10-16T14:10:00+05:00', 'warehouses' => [$this->warehouseRecord()]]),
            '/batch' => $this->envelope(['items' => ['units' => [$this->unitRecord()]], 'stock' => ['as_of' => '2026-10-16T14:10:00+05:00', 'warehouses' => [$this->warehouseRecord()]]]),
        };

        $response = $this->onec('POST', $path, $body)->assertStatus($status);

        if ($status === 403) {
            $response->assertJsonPath('error.code', 'forbidden_ability');
        }
    }

    public static function abilityMatrix(): array
    {
        return [
            'items token on stock' => ['/item-stock', ['items:write'], 403],
            'items token on items' => ['/items', ['items:write'], 202],
            'stock token on items' => ['/items', ['stock:write'], 403],
            'stock token on stock' => ['/item-stock', ['stock:write'], 202],
            'batch needs every section ability' => ['/batch', ['items:write'], 403],
            'batch with both abilities' => ['/batch', ['items:write', 'stock:write'], 202],
        ];
    }

    public function test_ping_works_for_any_valid_token_and_lists_its_abilities(): void
    {
        $this->issueToken([IntegrationAbility::StockWrite->value]);

        $this->onec('GET', '/ping')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('token.abilities', ['stock:write'])
            ->assertJsonPath('integration_enabled', true)
            ->assertHeader('X-Request-Id');
    }

    public function test_ip_allowlist_accepts_cidr_and_refuses_other_addresses(): void
    {
        $this->issueToken(ips: ['10.0.0.5/32']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.6'])
            ->onec('GET', '/ping')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ip_not_allowed');

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->onec('GET', '/ping')->assertOk();
    }

    public function test_a_spoofed_forwarded_for_header_from_an_untrusted_peer_is_ignored(): void
    {
        $this->issueToken(ips: ['10.0.0.5/32']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
            ->onec('GET', '/ping', headers: ['X-Forwarded-For' => '10.0.0.5'])
            ->assertForbidden();
    }

    public function test_unsigned_calls_from_a_public_address_need_a_signature(): void
    {
        $this->issueToken();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->onec('GET', '/ping')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'signature_required');
    }

    public function test_signed_requests_are_verified_and_rejected_when_wrong_or_skewed(): void
    {
        $token = $this->issueToken(withSecret: true);
        $body = $this->envelope(['units' => [$this->unitRecord()]]);
        $raw = json_encode($body);
        $sign = fn (int $time, string $secret): array => ['X-Timestamp' => (string) $time, 'X-Signature' => 'sha256='.hash_hmac('sha256', $time.'.'.$raw, $secret)];

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->onec('POST', '/items', $body, headers: $sign(now()->getTimestamp(), 'wrong-secret'))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_signature');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->onec('POST', '/items', $body, headers: $sign(now()->subMinutes(10)->getTimestamp(), $token->signing_secret))
            ->assertUnauthorized();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->onec('POST', '/items', $body, headers: $sign(now()->getTimestamp(), $token->signing_secret))
            ->assertStatus(202);
    }

    public function test_plain_http_is_refused_in_production(): void
    {
        $this->issueToken();
        $this->app['env'] = 'production';

        $this->onec('GET', '/ping')->assertForbidden()->assertJsonPath('error.code', 'https_required');
    }

    public function test_the_api_is_stateless_and_ignores_a_staff_session(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->postJson('/api/v1/integration/1c/items', $this->envelope(['units' => [$this->unitRecord()]]));

        $response->assertUnauthorized();
        $this->assertSame([], $response->headers->getCookies(), 'The API must not set cookies');
    }

    public function test_disabled_integration_answers_503_and_stores_nothing(): void
    {
        $this->issueToken();
        $this->enableInbound(false);

        $this->onec('POST', '/items', $this->envelope(['units' => [$this->unitRecord()]]))
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'integration_disabled');

        $this->assertSame(0, InboundBatch::query()->count());
        $this->onec('GET', '/ping')->assertOk()->assertJsonPath('integration_enabled', false);
    }

    public function test_last_used_is_recorded(): void
    {
        $token = $this->issueToken();

        $this->onec('GET', '/ping')->assertOk();

        $this->assertNotNull($token->fresh()->last_used_at);
        $this->assertSame('127.0.0.1', $token->fresh()->last_used_ip);
    }

    public function test_only_the_hash_of_a_token_is_stored(): void
    {
        $token = $this->issueToken();

        $this->assertSame(hash('sha256', $this->plainToken), $token->fresh()->token_hash);
        $this->assertDatabaseMissing('integration_tokens', ['token_hash' => $this->plainToken]);
        $this->assertStringStartsWith('1c_', $this->plainToken);
        $this->assertSame(51, strlen($this->plainToken));
    }

    public function test_a_token_cannot_be_issued_without_expiry_beyond_the_maximum(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        IntegrationToken::issue('Too long', IntegrationAbility::values(), null, 366);
    }
}
