<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    private const SETTINGS_CHILDREN = [
        'nav.settings_appearance',
        'nav.settings_company',
        'nav.settings_integrations',
        'nav.settings_telegram',
    ];

    public function test_admin_sees_the_settings_group_with_its_children(): void
    {
        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-nav-group="settings"', false)
            ->assertSee('aria-controls="nav-group-settings"', false)
            ->assertSee('id="nav-group-settings"', false)
            ->assertSee(__('nav.settings'))
            ->assertSee(route('settings.appearance'), false);

        foreach (self::SETTINGS_CHILDREN as $key) {
            $response->assertSee(__($key));
        }
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admins_do_not_see_the_settings_group(UserRole $role): void
    {
        $response = $this->actingAs(User::factory()->role($role)->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('data-nav-group="settings"', false)
            ->assertDontSee(route('settings.appearance'), false);

        foreach (['nav.settings', ...self::SETTINGS_CHILDREN] as $key) {
            $response->assertDontSee(__($key));
        }

        // Hiding the link is cosmetic; the route itself stays admin-only.
        $this->get(route('settings.appearance'))->assertForbidden();
    }

    public function test_group_is_collapsed_when_no_child_is_active(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertSeeInOrder(['data-nav-group="settings"', 'aria-expanded="false"', 'aria-controls="nav-group-settings"', 'x-cloak'], false);
    }

    public function test_active_child_expands_the_group_and_is_highlighted(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.appearance'))
            ->assertOk()
            ->assertSeeInOrder(['data-nav-group="settings"', 'aria-expanded="true"', 'aria-controls="nav-group-settings"'], false)
            ->assertSeeInOrder(['id="nav-group-settings"', 'href="'.route('settings.appearance').'"', 'aria-current="page"', 'bg-brand-600'], false);
    }

    public function test_notification_settings_are_personal_not_an_admin_setting(): void
    {
        // Roadmap step 4: per-user preferences replace the planned admin "notifications" setting.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertSee(route('settings.telegram'), false)
            ->assertSee('data-notification-bell', false);
    }

    public function test_top_level_modules_use_the_same_definition(): void
    {
        $this->actingAs(User::factory()->sales()->create())
            ->get(route('dashboard'))
            ->assertSeeInOrder([__('nav.sales'), __('nav.dashboard'), __('nav.customers'), __('nav.agreements')])
            ->assertDontSee(__('nav.administration'));
    }

    /**
     * @return array<string, array{UserRole}>
     */
    public static function nonAdminRoles(): array
    {
        return [
            'sales' => [UserRole::Sales],
            'staff' => [UserRole::Staff],
        ];
    }

    /**
     * Spec 06 7.9 / 7.8: managers and finance reach Company and Agreement templates only.
     *
     * @return array<string, array{UserRole}>
     */
    public static function businessSettingsRoles(): array
    {
        return [
            'manager' => [UserRole::Manager],
            'finance' => [UserRole::Finance],
        ];
    }

    #[DataProvider('businessSettingsRoles')]
    public function test_managers_and_finance_see_only_the_business_settings(UserRole $role): void
    {
        $this->actingAs(User::factory()->role($role)->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-nav-group="settings"', false)
            ->assertSee(route('settings.company'), false)
            ->assertSee(route('settings.agreement-templates'), false)
            ->assertDontSee(route('settings.appearance'), false)
            ->assertDontSee(route('settings.integrations'), false)
            ->assertDontSee(__('nav.settings_telegram'));

        $this->get(route('settings.appearance'))->assertForbidden();
    }
}
