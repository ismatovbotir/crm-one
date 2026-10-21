<?php

namespace Tests\Feature\Admin;

use App\Livewire\Settings\DataImport;
use App\Livewire\Settings\SetupWizard;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Unit;
use App\Models\User;
use App\Services\Maintenance\SetupChecklist;
use App\Support\BusinessSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Go-live tooling (roadmap step 7): customer/item import with preview, the guarded demo-data wipe
 * and the setup checklist.
 */
class GoLiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_import_previews_then_creates_only_valid_rows(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->sales()->create(['email' => 'owner@example.uz']);
        Customer::factory()->create(['tax_id' => '301234567']);
        $csv = "Name;Tax ID;Phone;Email;Owner Email;Contact First Name;Sale Type\n"
            ."Alpha LLC;305555555;901234567;info@alpha.uz;owner@example.uz;Aziz;b2b\n"
            ."Existing LLC;301234567;;;;;\n"
            ."Repeat LLC;305555555;;;;;\n"
            .";;;bad-mail;;;\n"
            ."Formula LLC;;;;;;=HYPERLINK(1)\n";

        $component = Livewire::actingAs($admin)->test(DataImport::class)
            ->set('kind', 'customers')
            ->set('file', UploadedFile::fake()->createWithContent('customers.csv', $csv))
            ->call('preview')
            ->assertHasNoErrors()
            ->assertSee(__('import.status.duplicate'))
            ->assertSee(__('import.messages.email_invalid'))
            ->assertSee(__('import.messages.formula'));

        $this->assertSame(1, Customer::query()->count());

        $component->call('commit')->assertSee(__('import.done_customers', ['count' => 1]));

        $customer = Customer::query()->where('tax_id', '305555555')->sole();
        $this->assertSame('Alpha LLC', $customer->name);
        $this->assertSame($owner->id, $customer->owner_id);
        $this->assertSame('+998901234567', $customer->phone);
        $this->assertSame('Aziz', $customer->contacts()->sole()->first_name);
        $this->assertSame(2, Customer::query()->count());
        $this->assertDatabaseHas('activity_log', ['subject_id' => $customer->id, 'event' => 'imported']);
    }

    public function test_a_preview_cannot_be_committed_twice_or_after_expiry(): void
    {
        $admin = User::factory()->admin()->create();
        $component = Livewire::actingAs($admin)->test(DataImport::class)
            ->set('file', UploadedFile::fake()->createWithContent('c.csv', "name\nBeta LLC\n"))
            ->call('preview');
        Cache::flush();

        $component->call('commit')->assertHasErrors('file');
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_item_import_requires_the_1c_guid_and_upserts_by_it(): void
    {
        $admin = User::factory()->admin()->create();
        Unit::factory()->create(['code' => '796']);
        $guid = (string) Str::uuid();
        $csv = "guid,code,name,type,unit_code,vat_rate,sale_price,sale_currency\n"
            ."{$guid},A-1,Router,product,796,12,1500000,UZS\n"
            .",A-2,No guid,product,796,12,,\n"
            .(string) Str::uuid().",A-3,Bad unit,product,XXX,12,,\n";

        Livewire::actingAs($admin)->test(DataImport::class)
            ->set('kind', 'items')
            ->set('file', UploadedFile::fake()->createWithContent('items.csv', $csv))
            ->call('preview')
            ->assertSee(__('import.messages.unit_unknown', ['unit' => 'XXX']))
            ->call('commit')
            ->assertSee(__('import.done_items', ['created' => 1, 'updated' => 0, 'failed' => 0]));

        $item = Item::query()->sole();
        $this->assertSame('Router', $item->name);
        $this->assertSame($guid, $item->external_id);
    }

    public function test_import_and_setup_are_admin_only(): void
    {
        $sales = User::factory()->sales()->create();

        $this->actingAs($sales)->get(route('settings.import'))->assertForbidden();
        $this->actingAs($sales)->get(route('settings.setup'))->assertForbidden();
        $this->actingAs($sales)->get(route('settings.import.template', 'customers'))->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('settings.import'))->assertOk();
        $this->assertStringContainsString('name;legal_name;tax_id', $this->actingAs($admin)->get(route('settings.import.template', 'customers'))->assertOk()->streamedContent());
        $this->actingAs($admin)->get(route('settings.import.template', 'other'))->assertNotFound();
    }

    public function test_wipe_refuses_when_real_users_own_records(): void
    {
        $real = User::factory()->sales()->create(['email' => 'real@company.uz']);
        Customer::factory()->ownedBy($real)->create();

        $this->artisan('crm:wipe-demo')->assertFailed();
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_wipe_removes_demo_data_and_demo_users_only(): void
    {
        $demo = User::factory()->sales()->create(['email' => 'sales@crm.test']);
        $keep = User::factory()->admin()->create(['email' => 'admin@company.uz']);
        Customer::factory()->ownedBy($demo)->count(2)->create();
        $item = Item::factory()->create();

        $this->artisan('crm:wipe-demo')->expectsConfirmation('Delete all demo business data? This cannot be undone.', 'yes')->assertSuccessful();

        $this->assertSame(0, Customer::query()->count());
        $this->assertModelMissing($demo);
        $this->assertModelExists($keep);
        $this->assertModelExists($item);
    }

    public function test_wipe_needs_force_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('crm:wipe-demo')->assertFailed();
        $this->artisan('crm:wipe-demo --allow-real --force')->expectsConfirmation('Delete all demo business data? This cannot be undone.', 'yes')->assertSuccessful();

        $real = User::factory()->sales()->create(['email' => 'real@company.uz']);
        Customer::factory()->ownedBy($real)->create();
        $this->artisan('crm:wipe-demo --allow-real --force')->assertFailed();
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_setup_checklist_reports_status_and_toggles_two_factor(): void
    {
        $admin = User::factory()->admin()->create();
        $checklist = app(SetupChecklist::class);
        $steps = collect($checklist->steps())->keyBy('key');

        $this->assertFalse($checklist->ready());
        $this->assertFalse($steps['scheduler']['done']);

        Cache::put(SetupChecklist::HEARTBEAT_KEY, now()->timestamp, 60);
        $this->assertTrue(collect($checklist->steps())->firstWhere('key', 'scheduler')['done']);

        Livewire::actingAs($admin)->test(SetupWizard::class)
            ->assertSee(__('setup.steps.company.title'))
            ->assertSee(__('setup.not_ready'))
            ->call('toggleTwoFactor')
            ->assertSee(__('setup.two_factor_on'));

        $this->assertTrue(BusinessSettings::bool('security.enforce_two_factor'));
        $this->assertDatabaseHas('activity_log', ['event' => 'two_factor_enforcement_changed']);
    }
}
