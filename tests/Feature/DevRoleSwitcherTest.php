<?php

namespace Tests\Feature;

use App\Http\Controllers\DevRoleSwitchController;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Development-only role switcher (user request 2026-10-19): never registered or usable outside
 * APP_ENV=local; in local it signs in as another active staff user and remembers the first one.
 */
class DevRoleSwitcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_switcher_is_absent_outside_local(): void
    {
        $this->assertFalse(Route::has('dev.switch-user'));
        $this->actingAs(User::factory()->admin()->create())->get(route('dashboard'))->assertOk()->assertDontSee('data-dev-role-switcher', false);
        $this->post('/dev/switch-user', ['user_id' => 1])->assertNotFound();

        $this->expectException(NotFoundHttpException::class);
        app(DevRoleSwitchController::class)(Request::create('/dev/switch-user', 'POST', ['user_id' => 1]));
    }

    public function test_in_local_it_signs_in_as_the_chosen_staff_user_and_remembers_the_first(): void
    {
        $admin = User::factory()->admin()->create();
        $sales = User::factory()->sales()->create();
        $portal = User::factory()->portal()->create();
        $this->app['env'] = 'local';
        $this->actingAs($admin);

        $request = Request::create('/dev/switch-user', 'POST', ['user_id' => $sales->id]);
        $request->setLaravelSession($this->app['session.store']);
        $request->setUserResolver(fn () => $admin);
        app(DevRoleSwitchController::class)($request);

        $this->assertSame($sales->id, Auth::guard('web')->id());
        $this->assertSame($admin->id, session(DevRoleSwitchController::SESSION_KEY));

        $back = Request::create('/dev/switch-user', 'POST', ['user_id' => $admin->id]);
        $back->setLaravelSession($this->app['session.store']);
        $back->setUserResolver(fn () => $sales);
        app(DevRoleSwitchController::class)($back);
        $this->assertSame($admin->id, Auth::guard('web')->id());
        $this->assertNull(session(DevRoleSwitchController::SESSION_KEY));

        $this->expectException(ModelNotFoundException::class);
        $portalRequest = Request::create('/dev/switch-user', 'POST', ['user_id' => $portal->id]);
        $portalRequest->setLaravelSession($this->app['session.store']);
        app(DevRoleSwitchController::class)($portalRequest);
    }
}
