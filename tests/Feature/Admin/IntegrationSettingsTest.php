<?php

namespace Tests\Feature\Admin;

use App\Enums\OneCFeed;
use App\Enums\UserRole;
use App\Livewire\Settings\Integrations;
use App\Models\ActivityLog;
use App\Models\IntegrationToken;
use App\Models\User;
use App\Services\OneC\OneCSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithOneCApi;
use Tests\TestCase;

/**
 * Settings > Integrations (Spec 02 section 12, AC-I.9, BR-I.18a): admin-only token management
 * and inbound settings.
 */
class IntegrationSettingsTest extends TestCase
{
    use InteractsWithOneCApi, RefreshDatabase;

    public function test_admin_sees_the_page_from_the_sidebar(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee(__('integration.settings.title'))
            ->assertSee(url('/api/v1/integration/1c'))
            ->assertSee('href="'.route('settings.integrations').'"', false);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admins_are_refused(UserRole $role): void
    {
        $this->actingAs(User::factory()->role($role)->create());

        $this->get(route('settings.integrations'))->assertForbidden();
    }

    public static function nonAdminRoles(): array
    {
        return ['manager' => [UserRole::Manager], 'sales' => [UserRole::Sales], 'finance' => [UserRole::Finance]];
    }

    public function test_created_token_is_shown_once_and_only_its_hash_is_stored(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $component = Livewire::test(Integrations::class)
            ->set('tokenName', '1C production')
            ->set('tokenAbilities', ['items:write', 'stock:write'])
            ->set('tokenAllowedIps', "10.0.0.5/32\n192.168.1.0/24")
            ->call('createToken')
            ->assertHasNoErrors();

        $plain = $component->get('issuedToken');
        $this->assertStringStartsWith('1c_', $plain);
        $component->assertSee($plain);

        $token = IntegrationToken::query()->sole();
        $this->assertSame(hash('sha256', $plain), $token->token_hash);
        $this->assertSame(['items:write', 'stock:write'], $token->abilities);
        $this->assertSame(['10.0.0.5/32', '192.168.1.0/24'], $token->allowed_ips);
        $this->assertSame($admin->id, $token->created_by);
        $this->assertTrue($token->expires_at->between(now()->addDays(179), now()->addDays(181)));
        $this->assertSame('api_token_created', ActivityLog::query()->where('subject_type', 'integration_token')->sole()->event);

        $this->get(route('settings.integrations'))->assertOk()->assertDontSee($plain)->assertSee($token->token_prefix);
        $component->call('dismissIssued')->assertSet('issuedToken', null)->assertDontSee($plain);
    }

    public function test_a_token_open_to_any_address_must_use_request_signing(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Integrations::class)
            ->set('tokenAllowedIps', '')
            ->call('createToken')
            ->assertHasErrors('tokenWithSecret');

        Livewire::test(Integrations::class)
            ->set('tokenAllowedIps', '203.0.113.9')
            ->call('createToken')
            ->assertHasErrors('tokenWithSecret');

        $this->assertSame(0, IntegrationToken::query()->count());

        $component = Livewire::test(Integrations::class)
            ->set('tokenWithSecret', true)
            ->call('createToken')
            ->assertHasNoErrors();

        $this->assertNotNull($component->get('issuedSecret'));
        $this->assertTrue(IntegrationToken::query()->sole()->requiresSignature());
    }

    public function test_invalid_ip_and_lifetime_are_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Integrations::class)->set('tokenAllowedIps', '10.0.0.300')->call('createToken')->assertHasErrors('tokenAllowedIps');
        Livewire::test(Integrations::class)->set('tokenAllowedIps', '10.0.0.5')->set('tokenDays', 400)->call('createToken')->assertHasErrors(['tokenDays' => 'max']);
    }

    public function test_revoking_a_token_makes_the_next_api_call_401(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $token = $this->issueToken();

        $this->onec('GET', '/ping')->assertOk();

        Livewire::test(Integrations::class)->call('revokeToken', $token->id);

        $this->assertNotNull($token->fresh()->revoked_at);
        $this->assertSame('api_token_revoked', ActivityLog::query()->where('subject_type', 'integration_token')->latest('id')->first()->event);
        $this->onec('GET', '/ping')->assertUnauthorized();
    }

    public function test_rotation_issues_a_new_token_and_keeps_the_old_one_until_revoked(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $old = $this->issueToken(['items:write'], ['10.0.0.5']);

        $component = Livewire::test(Integrations::class)->call('rotateToken', $old->id);

        $new = IntegrationToken::query()->whereKeyNot($old->id)->sole();
        $this->assertSame(['items:write'], $new->abilities);
        $this->assertSame(['10.0.0.5'], $new->allowed_ips);
        $this->assertSame(hash('sha256', $component->get('issuedToken')), $new->token_hash);
        $this->assertTrue($old->fresh()->isActive());
    }

    public function test_issued_token_cannot_be_injected_by_the_client(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(Integrations::class)->set('issuedToken', 'x');
    }

    public function test_inbound_switch_and_stale_thresholds_are_saved(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Integrations::class)
            ->set('inboundEnabled', true)
            ->set('staleMinutes.stock', 45)
            ->call('saveSettings')
            ->assertHasNoErrors()
            ->assertRedirect(route('settings.integrations'));

        $settings = app(OneCSettings::class);
        $this->assertTrue($settings->inboundEnabled());
        $this->assertSame(45, $settings->staleMinutes(OneCFeed::Stock));

        Livewire::test(Integrations::class)->set('staleMinutes.stock', 0)->call('saveSettings')->assertHasErrors('staleMinutes.stock');
    }
}
