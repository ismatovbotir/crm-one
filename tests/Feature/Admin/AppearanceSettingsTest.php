<?php

namespace Tests\Feature\Admin;

use App\Enums\ThemeColor;
use App\Enums\ThemePreset;
use App\Enums\UserRole;
use App\Livewire\Settings\Appearance;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppearanceSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_appearance_settings(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.appearance'))
            ->assertOk()
            ->assertSee(__('settings.colors.primary_hover.label'))
            ->assertSee(__('settings.presets.default'))
            ->assertSee(__('settings.contrast.white_on_primary'));
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admins_get_403(UserRole $role): void
    {
        $this->actingAs(User::factory()->role($role)->create());

        $this->get(route('settings.appearance'))->assertForbidden();
        Livewire::test(Appearance::class)->assertForbidden();
    }

    public function test_settings_link_is_only_shown_to_admins(): void
    {
        $this->actingAs(User::factory()->sales()->create())
            ->get(route('dashboard'))
            ->assertDontSee(route('settings.appearance'));

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertSee(route('settings.appearance'));
    }

    public function test_defaults_are_red_silver_white_and_pass_contrast(): void
    {
        $this->assertSame('#e51932', ThemeColor::Primary->default());
        $this->assertSame('#d5d8de', ThemeColor::Sidebar->default());
        $this->assertSame('#1f2937', ThemeColor::SidebarText->default());
        $this->assertSame('#ffffff', ThemeColor::Surface->default());
        $this->assertSame(ThemeColor::defaults(), ThemeColor::current());
    }

    public function test_every_preset_passes_contrast_checks(): void
    {
        foreach (ThemePreset::cases() as $preset) {
            foreach (ThemeColor::contrastChecks($preset->colors()) as $check) {
                $this->assertTrue($check['passes'], "{$preset->value}: {$check['label']} is {$check['ratio']}:1");
            }
        }

        foreach (ThemeColor::contrastChecks(ThemeColor::defaults()) as $check) {
            $this->assertTrue($check['passes'], "default: {$check['label']} is {$check['ratio']}:1");
        }
    }

    public function test_contrast_ratio_matches_wcag_reference_values(): void
    {
        $this->assertEqualsWithDelta(21.0, ThemeColor::contrastRatio('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(4.65, ThemeColor::contrastRatio('#ffffff', '#e51932'), 0.01);
        $this->assertEqualsWithDelta(1.0, ThemeColor::contrastRatio('#777777', '#777777'), 0.001);
    }

    public function test_admin_can_save_colors(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(Appearance::class)
            ->set('colors', ThemePreset::Navy->colors())
            ->set('colors.primary', '#2563EB ')
            ->set('colors.sidebar_text', '#ffffff')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('settings.appearance'));

        $this->assertSame('#2563eb', Setting::get('theme.primary'));
        $this->assertSame('#0f172a', Setting::get('theme.sidebar'));
        $this->assertSame($admin->id, Setting::where('key', 'theme.primary')->value('updated_by'));
    }

    public function test_applying_a_preset_only_fills_the_form(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Appearance::class)
            ->call('applyPreset', ThemePreset::Mono->value)
            ->assertSet('colors', ThemePreset::Mono->colors());

        $this->assertSame(0, Setting::count());
    }

    public function test_invalid_hex_is_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Appearance::class)
            ->set('colors.primary', 'red')
            ->set('colors.surface', '#fff')
            ->set('colors.text', '#12345g')
            ->set('colors.border', '')
            ->call('save')
            ->assertHasErrors(['colors.primary', 'colors.surface', 'colors.text', 'colors.border']);

        $this->assertSame(0, Setting::count());
    }

    public function test_low_contrast_is_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Appearance::class)
            ->set('colors.primary', '#ff9999')
            ->set('colors.sidebar_text', '#c0c4cc')
            ->call('save')
            ->assertHasErrors(['contrast'])
            ->assertSee(__('settings.contrast.white_on_primary'))
            ->assertSee(__('settings.contrast.sidebar_text_on_sidebar'));

        $this->assertSame(0, Setting::count());
    }

    public function test_saved_values_appear_in_rendered_layout_css(): void
    {
        Setting::factory()->themeColor(ThemeColor::Primary, '#1d4ed8')->create();
        Setting::factory()->themeColor(ThemeColor::Sidebar, '#c0c4cc')->create();

        $this->actingAs(User::factory()->sales()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('--color-brand-600:#1d4ed8', false)
            ->assertSee('--color-sidebar:#c0c4cc', false)
            ->assertSee('<meta name="theme-color" content="#c0c4cc">', false);

        // Guest (login) layout gets the same overrides.
        auth()->logout();
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('--color-brand-600:#1d4ed8', false);
    }

    public function test_unsanitized_stored_values_never_reach_the_page(): void
    {
        Setting::factory()->themeColor(ThemeColor::Primary, '#000}</style><script>alert(1)</script>')->create();

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('alert(1)', false)
            ->assertSee('--color-brand-600:#e51932', false);
    }

    public function test_cache_is_invalidated_on_save(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->assertSame('#e51932', ThemeColor::current()['primary']);
        $this->assertTrue(Cache::has(Setting::CACHE_KEY));

        Livewire::test(Appearance::class)
            ->set('colors.primary', '#b00020')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(Cache::has(Setting::CACHE_KEY));
        $this->assertSame('#b00020', ThemeColor::current()['primary']);
    }

    public function test_admin_can_reset_to_defaults(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Setting::factory()->themeColor(ThemeColor::Primary, '#1d4ed8')->create();
        $this->assertSame('#1d4ed8', ThemeColor::current()['primary']);

        Livewire::test(Appearance::class)
            ->assertSet('colors.primary', '#1d4ed8')
            ->call('resetToDefaults')
            ->assertRedirect(route('settings.appearance'));

        $this->assertSame(0, Setting::where('key', 'like', 'theme.%')->count());
        $this->assertSame(ThemeColor::defaults(), ThemeColor::current());
    }

    public function test_default_logo_is_rendered_in_layouts(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('images/logo.jpg', false)
            ->assertSee('alt="RSG"', false)
            ->assertSee('rel="icon"', false);
    }

    #[DataProvider('validLogos')]
    public function test_admin_can_upload_logo(string $name): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Appearance::class)
            ->set('logo', UploadedFile::fake()->image($name, 640, 320))
            ->call('uploadLogo')
            ->assertHasNoErrors()
            ->assertRedirect(route('settings.appearance'));

        $path = Setting::get(Setting::LOGO_KEY);
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        // Re-encoded to PNG under a random name and scaled to at most 512 px.
        $this->assertStringEndsWith('.png', $path);
        $size = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame([512, 256, 'image/png'], [$size[0], $size[1], $size['mime']]);

        $this->get(route('dashboard'))->assertSee('storage/'.$path, false);
    }

    public static function validLogos(): array
    {
        return [['logo.png'], ['logo.jpg'], ['logo.webp']];
    }

    public function test_svg_logos_are_no_longer_accepted(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Appearance::class)
            ->set('logo', UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="#e51932"/></svg>'))
            ->call('uploadLogo')
            ->assertHasErrors(['logo' => 'mimes']);

        $this->assertNull(Setting::get(Setting::LOGO_KEY));
    }

    public function test_a_legacy_svg_logo_path_is_not_served(): void
    {
        Setting::putMany([Setting::LOGO_KEY => 'branding/legacy.svg']);

        $this->assertFalse(Setting::hasCustomLogo());
        $this->assertStringEndsWith(Setting::DEFAULT_LOGO, Setting::logoUrl());
    }

    public function test_logo_upload_validation(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Appearance::class)
            ->call('uploadLogo')
            ->assertHasErrors(['logo' => 'required']);

        Livewire::test(Appearance::class)
            ->set('logo', UploadedFile::fake()->image('logo.png')->size(2049))
            ->call('uploadLogo')
            ->assertHasErrors(['logo' => 'max']);

        Livewire::test(Appearance::class)
            ->set('logo', UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'))
            ->call('uploadLogo')
            ->assertHasErrors(['logo' => 'mimes']);

        Livewire::test(Appearance::class)
            ->set('logo', UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>'))
            ->call('uploadLogo')
            ->assertHasErrors(['logo']);

        $this->assertNull(Setting::get(Setting::LOGO_KEY));
    }

    public function test_admin_can_reset_logo(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->admin()->create());

        Storage::disk('public')->put('branding/custom.png', 'x');
        Setting::putMany([Setting::LOGO_KEY => 'branding/custom.png']);

        Livewire::test(Appearance::class)
            ->call('resetLogo')
            ->assertRedirect(route('settings.appearance'));

        $this->assertNull(Setting::get(Setting::LOGO_KEY));
        Storage::disk('public')->assertMissing('branding/custom.png');
        $this->assertStringEndsWith('images/logo.jpg', Setting::logoUrl());
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admins_cannot_upload_or_reset_logo(UserRole $role): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->role($role)->create());

        // The component refuses to mount, so no action (save, upload, reset) is reachable.
        Livewire::test(Appearance::class)->assertForbidden();

        $this->assertNull(Setting::get(Setting::LOGO_KEY));
        $this->assertFalse($this->app->make('auth')->user()->can('manage', Setting::class));
    }

    public static function nonAdminRoles(): array
    {
        return [
            'manager' => [UserRole::Manager],
            'sales' => [UserRole::Sales],
            'finance' => [UserRole::Finance],
        ];
    }
}
