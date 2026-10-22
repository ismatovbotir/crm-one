<?php

namespace Tests\Feature\Admin;

use App\Enums\IntegrationAbility;
use App\Livewire\Settings\Integrations;
use App\Models\IntegrationToken;
use App\Models\Setting;
use App\Models\User;
use App\Services\OneC\SimpleToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithOneCApi;
use Tests\TestCase;

/**
 * Settings > Integrations: one plain bearer token for 1C that the admin can copy at any time and
 * refresh. No HMAC and no IP list; the previous token dies on refresh.
 */
class SimpleOneCTokenTest extends TestCase
{
    use InteractsWithOneCApi, RefreshDatabase;

    public function test_admin_generates_and_sees_the_token_with_a_copy_button(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Integrations::class)
            ->assertSee(__('integration.simple.none'))
            ->call('refreshSimpleToken')
            ->assertDontSee(__('integration.simple.none'))
            ->assertSeeHtml('data-simple-token-value')
            ->assertSee(__('integration.tokens.copy'))
            ->assertSee(__('integration.simple.refresh'));

        $plain = app(SimpleToken::class)->plain();
        $this->assertStringStartsWith(IntegrationToken::PREFIX, (string) $plain);

        // Still visible on a fresh page load: it is not a show-once token.
        Livewire::test(Integrations::class)->assertSeeHtml('value="'.$plain.'"');
        $this->assertStringNotContainsString($plain, (string) Setting::get('onec.simple_token'), 'Stored encrypted, not in clear.');
    }

    public function test_the_token_has_all_abilities_and_no_hmac_or_ip_limit_and_works_on_the_api(): void
    {
        $this->enableInbound();
        $this->actingAs(User::factory()->admin()->create());
        Livewire::test(Integrations::class)->call('refreshSimpleToken');

        $token = app(SimpleToken::class)->model();
        $this->assertSame(IntegrationAbility::values(), $token->abilities);
        $this->assertFalse($token->requiresSignature());
        $this->assertNull($token->allowed_ips);

        $this->onec('GET', '/ping', token: app(SimpleToken::class)->plain())->assertOk();
    }

    public function test_refresh_replaces_the_token_and_the_old_one_stops_working(): void
    {
        $this->enableInbound();
        $this->actingAs(User::factory()->admin()->create());
        $page = Livewire::test(Integrations::class)->call('refreshSimpleToken');
        $old = (string) app(SimpleToken::class)->plain();

        $page->call('refreshSimpleToken');
        $new = (string) app(SimpleToken::class)->plain();

        $this->assertNotSame($old, $new);
        $this->onec('GET', '/ping', token: $old)->assertStatus(401);
        $this->onec('GET', '/ping', token: $new)->assertOk();
        $this->assertSame(1, IntegrationToken::query()->active()->where('name', SimpleToken::NAME)->count());
    }

    public function test_an_expired_or_revoked_token_is_not_shown(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Livewire::test(Integrations::class)->call('refreshSimpleToken');
        app(SimpleToken::class)->model()->forceFill(['expires_at' => now()->subDay()])->save();

        $this->assertNull(app(SimpleToken::class)->plain());
        Livewire::test(Integrations::class)->assertSee(__('integration.simple.none'))->assertSee(__('integration.simple.generate'));
    }

    public function test_only_admins_can_refresh(): void
    {
        $this->actingAs(User::factory()->manager()->create());

        Livewire::test(Integrations::class)->assertForbidden();
        $this->assertNull(app(SimpleToken::class)->model());
    }
}
