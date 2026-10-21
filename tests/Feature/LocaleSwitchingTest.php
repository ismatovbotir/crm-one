<?php

namespace Tests\Feature;

use App\Enums\Locale;
use App\Enums\UserRole;
use App\Livewire\Users\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocaleSwitchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_interface_language_is_uzbek(): void
    {
        $this->assertSame('uz', config('app.locale'));
        $this->assertSame('uz', config('app.fallback_locale'));

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('<html lang="uz"', false)
            ->assertSee(__('auth.sign_in_intro', [], 'uz'));
    }

    #[DataProvider('guestPages')]
    public function test_language_switcher_is_on_guest_pages(string $uri): void
    {
        $this->get($uri)
            ->assertOk()
            ->assertSee('data-locale-switcher', false)
            ->assertSee(route('locale.update'), false);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function guestPages(): array
    {
        return [
            'login' => ['/login'],
            'forgot password' => ['/forgot-password'],
            'reset password' => ['/reset-password/some-token'],
        ];
    }

    public function test_language_switcher_is_in_the_admin_topbar(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-locale-switcher', false);
    }

    public function test_guest_can_switch_language_for_the_session(): void
    {
        $this->from(route('login'))
            ->post(route('locale.update'), ['locale' => 'ru'])
            ->assertRedirect(route('login'))
            ->assertSessionHas(Locale::SESSION_KEY, 'ru');

        $this->get(route('login'))
            ->assertSee('<html lang="ru"', false)
            ->assertSee(__('auth.sign_in_intro', [], 'ru'))
            ->assertDontSee(__('auth.sign_in_intro', [], 'uz'));
    }

    public function test_same_host_referer_keeps_its_path_and_query(): void
    {
        $this->actingAs(User::factory()->create())
            ->withHeader('referer', url('/customers?search=acme'))
            ->post(route('locale.update'), ['locale' => 'ru'])
            ->assertRedirect(url('/customers?search=acme'));
    }

    #[DataProvider('offSiteReferers')]
    public function test_off_site_referers_fall_back_to_the_dashboard(string $referer): void
    {
        $this->withHeader('referer', $referer)
            ->post(route('locale.update'), ['locale' => 'ru'])
            ->assertRedirect(route('dashboard'));
    }

    public static function offSiteReferers(): array
    {
        return [
            'other host' => ['https://evil.example/phish'],
            'protocol relative' => ['//evil.example/phish'],
            'backslash trick' => ['http://localhost\\@evil.example/'],
            'javascript' => ['javascript:alert(1)'],
            'other port' => ['http://localhost:8081/dashboard'],
        ];
    }

    /** Spec 03 section 17.10: the switcher changes the session; "remember" also saves the default. */
    public function test_authenticated_user_choice_is_saved_on_the_user_only_when_remembered(): void
    {
        $user = User::factory()->create();
        $this->assertNull($user->locale);

        $this->actingAs($user)->from(route('dashboard'))->post(route('locale.update'), ['locale' => 'ru'])->assertSessionHas(Locale::SESSION_KEY, 'ru');
        $this->assertNull($user->fresh()->locale, 'Session only.');

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('locale.update'), ['locale' => 'en', 'remember' => '1'])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas(Locale::SESSION_KEY, 'en');

        $this->assertSame(Locale::En, $user->fresh()->locale);

        $this->get(route('dashboard'))
            ->assertSee('<html lang="en"', false)
            ->assertSee(__('dashboard.workflow', [], 'en'));
    }

    public function test_explicit_session_choice_wins_and_the_default_applies_without_one(): void
    {
        $user = User::factory()->create(['locale' => Locale::Ru]);

        $this->actingAs($user)
            ->withSession([Locale::SESSION_KEY => 'en'])
            ->get(route('dashboard'))
            ->assertSee('<html lang="en"', false);

        $this->flushSession();
        $this->actingAs($user)->get(route('dashboard'))
            ->assertSee('<html lang="ru"', false)
            ->assertSee(__('dashboard.workflow', [], 'ru'));
    }

    public function test_user_without_preference_follows_the_session(): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession([Locale::SESSION_KEY => 'en'])
            ->get(route('dashboard'))
            ->assertSee('<html lang="en"', false);
    }

    #[DataProvider('invalidLocales')]
    public function test_unsupported_locale_is_rejected(mixed $locale): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('locale.update'), ['locale' => $locale])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('locale')
            ->assertSessionMissing(Locale::SESSION_KEY);

        $this->assertNull($user->fresh()->locale);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidLocales(): array
    {
        return [
            'unknown' => ['de'],
            'uppercase' => ['RU'],
            'empty' => [''],
            'array' => [['ru']],
        ];
    }

    public function test_tampered_session_locale_falls_back_to_uzbek(): void
    {
        $this->withSession([Locale::SESSION_KEY => '../../etc'])
            ->get(route('login'))
            ->assertSee('<html lang="uz"', false);
    }

    public function test_role_labels_and_validation_messages_follow_the_locale(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        app()->setLocale('ru');

        $this->assertSame('Администратор', UserRole::Admin->label());

        Livewire::test(Form::class)
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required'])
            ->assertSee('Поле «Имя» обязательно для заполнения.');
    }
}
