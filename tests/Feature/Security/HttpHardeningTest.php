<?php

namespace Tests\Feature\Security;

use App\Livewire\Users\Form as UserForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Security headers, error pages, locale endpoint, print route input, Livewire tampering.
 * Tests marked "FINDING" fail against the current code and pass once the fix is applied.
 */
class HttpHardeningTest extends TestCase
{
    use RefreshDatabase;

    /**
     * FINDING (Medium): no clickjacking / sniffing / referrer headers on any response (CWE-1021, CWE-693).
     */
    public function test_security_headers_are_sent(): void
    {
        $response = $this->get(route('login'));

        $frameAncestors = str_contains((string) $response->headers->get('Content-Security-Policy'), 'frame-ancestors');
        $this->assertTrue($response->headers->has('X-Frame-Options') || $frameAncestors, 'No X-Frame-Options / frame-ancestors');
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertNotNull($response->headers->get('Referrer-Policy'));
    }

    public function test_exceptions_do_not_leak_internals_when_debug_is_off(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/__security-boom', fn () => throw new \RuntimeException('internal-secret-detail'));

        $this->get('/__security-boom')
            ->assertStatus(500)
            ->assertDontSee('internal-secret-detail')
            ->assertDontSee('RuntimeException');
    }

    /**
     * FINDING (Low/Info): redirect()->back() trusts the Referer header (CWE-601). Not exploitable
     * cross-site today because CSRF protects POST /locale, but should only ever go to our own host.
     */
    public function test_locale_switch_never_redirects_off_site(): void
    {
        $response = $this->withHeader('referer', 'https://evil.example/phish')
            ->post(route('locale.update'), ['locale' => 'ru']);

        $this->assertStringStartsNotWith('https://evil.example', (string) $response->headers->get('Location'));
    }

    #[DataProvider('hostileLocales')]
    public function test_locale_endpoint_rejects_hostile_values(mixed $locale): void
    {
        $this->post(route('locale.update'), ['locale' => $locale])->assertSessionHasErrors('locale');
        $this->assertNotSame($locale, session('locale'));
    }

    public static function hostileLocales(): array
    {
        return [
            'path traversal' => ['../../.env'],
            'html' => ['<script>alert(1)</script>'],
            'array' => [['ru']],
        ];
    }

    #[DataProvider('hostileLangParams')]
    public function test_print_lang_parameter_is_not_reflected_or_fatal(string $query): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/print/sample?'.$query)
            ->assertOk()
            ->assertDontSee('<svg/onload', false)
            ->assertDontSee('../', false);
    }

    public static function hostileLangParams(): array
    {
        return [
            'xss' => ['lang='.urlencode('"><svg/onload=alert(1)>')],
            'traversal' => ['lang='.urlencode('../../../.env')],
            'array' => ['lang[]=ru'],
        ];
    }

    public function test_guests_cannot_reach_internal_routes(): void
    {
        foreach (['/dashboard', '/users', '/settings/appearance', '/print/sample', '/customers', '/items', '/departments'] as $uri) {
            $this->get($uri)->assertRedirect(route('login'));
        }
    }

    public function test_portal_users_are_signed_out_of_internal_routes(): void
    {
        $this->actingAs(User::factory()->portal()->create());

        $this->get('/print/sample')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[DataProvider('lockedUserFormProperties')]
    public function test_user_form_identity_and_realm_cannot_be_tampered(string $property, mixed $value): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create();
        $this->actingAs($admin);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(UserForm::class, ['user' => $other])->set($property, $value);
    }

    public static function lockedUserFormProperties(): array
    {
        return [
            'userId' => ['userId', 1],
            'type' => ['type', 'portal'],
        ];
    }

    public function test_admin_cannot_escalate_or_change_own_role_via_livewire_payload(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(UserForm::class, ['user' => $admin])
            ->set('role', 'sales')
            ->call('save')
            ->assertForbidden();

        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_non_admin_cannot_create_an_admin_through_the_component(): void
    {
        $this->actingAs(User::factory()->manager()->create());

        Livewire::test(UserForm::class)->assertForbidden();
        $this->assertSame(0, User::query()->where('email', 'evil@example.com')->count());
    }
}
