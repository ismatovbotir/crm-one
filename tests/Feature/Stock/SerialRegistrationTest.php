<?php

namespace Tests\Feature\Stock;

use App\Actions\Stock\RegisterSerials;
use App\Enums\ReceiptSource;
use App\Livewire\Catalog\Items\Show as ItemShow;
use App\Livewire\Stock\SerialRegistration;
use App\Models\ActivityLog;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

/**
 * "Add / import serials" (user requirement 2026-10-18): one-by-one entry, CSV import with preview
 * and error report, duplicates in the batch and in the registry, the locked item on the item page,
 * permissions and the Catalogue menu entry.
 */
class SerialRegistrationTest extends TestCase
{
    use BuildsStock, RefreshDatabase;

    private User $stock;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->stock = User::factory()->stockManager()->create();
        $this->warehouse = Warehouse::factory()->create(['name' => 'Main']);
    }

    public function test_single_entry_flow_saves_a_posted_manual_receipt(): void
    {
        $item = $this->serializedItem($this->warehouse);

        $component = Livewire::actingAs($this->stock)->test(SerialRegistration::class)
            ->call('openDialog')
            ->call('selectItem', $item->id)
            ->set('warehouseId', $this->warehouse->id)
            ->set('entry', ' sn-a001 ')->call('addSerial')
            ->set('entry', 'SNA002')->call('addSerial')
            ->set('entry', 'WRONG1')->call('addSerial')
            ->assertSee('SN-A001')
            ->assertSee(__('stock.register.count', ['count' => 3]))
            ->call('removeSerial', 2)
            ->assertSee(__('stock.register.count', ['count' => 2]));

        $component->call('save')->assertHasNoErrors()->assertDispatched('serials-registered');

        $receipt = GoodsReceipt::query()->sole();
        $this->assertSame([ReceiptSource::ManualRegistration, 'posted'], [$receipt->source_type, $receipt->status->value]);
        $this->assertSame(['SN-A001', 'SNA002'], ItemSerial::query()->orderBy('serial_normalized')->pluck('serial_normalized')->all());
        $this->assertSame($this->warehouse->id, ItemSerial::query()->first()->warehouse_id);
        $log = ActivityLog::query()->where('event', 'serials_registered')->sole();
        $this->assertSame([2, $receipt->number, $this->stock->id], [$log->properties['count'], $log->properties['receipt'], $log->causer_id]);
    }

    public function test_file_import_previews_duplicates_and_invalid_rows_and_saves_only_a_clean_batch(): void
    {
        $item = $this->serializedItem($this->warehouse);
        $this->receiveSerials($this->stock, $this->warehouse, $item, ['OLD001']);
        $csv = "serial_number\nNEW001\nNEW002\nNEW002\nOLD001\nСН0003\n";

        $component = Livewire::actingAs($this->stock)->test(SerialRegistration::class)
            ->call('openDialog')
            ->call('selectItem', $item->id)
            ->set('file', UploadedFile::fake()->createWithContent('serials.csv', $csv))
            ->assertSee(__('stock.register.valid', ['count' => 2]))
            ->assertSee(__('stock.register.duplicates', ['count' => 2]))
            ->assertSee(__('stock.register.invalid', ['count' => 1]));

        $this->assertSame(['ok', 'ok', 'duplicate_in_file', 'already_registered', 'cyrillic'], array_column($component->get('rows'), 'result'));

        $component->call('save');
        $this->assertSame(1, ItemSerial::query()->count(), 'Nothing saved while the batch has errors.');
        $this->assertSame(1, GoodsReceipt::query()->count());

        $report = $component->call('downloadErrors');
        $report->assertFileDownloaded('serial-errors.csv');

        $component->call('removeSerial', 4)->call('removeSerial', 3)->call('removeSerial', 2)->call('save')->assertHasNoErrors();
        $this->assertSame(['NEW001', 'NEW002', 'OLD001'], ItemSerial::query()->orderBy('serial_normalized')->pluck('serial_normalized')->all());
        $this->assertSame('import', ActivityLog::query()->where('event', 'serials_registered')->sole()->properties['origin']);
    }

    /** Security audit 3 (roadmap step 9): the error report never contains a live formula. */
    public function test_error_report_neutralizes_formulas(): void
    {
        $item = $this->serializedItem($this->warehouse);

        $component = Livewire::actingAs($this->stock)->test(SerialRegistration::class)
            ->call('openDialog')
            ->call('selectItem', $item->id)
            ->set('file', UploadedFile::fake()->createWithContent('serials.csv', 'serial_number
=HYPERLINK(1)
GOOD01
'));

        ob_start();
        $component->instance()->downloadErrors()->sendContent();
        $csv = (string) ob_get_clean();

        $this->assertStringContainsString("'=HYPERLINK(1)", $csv);
        $this->assertDoesNotMatchRegularExpression('/(^|;)"?=/m', $csv);
    }

    public function test_file_type_and_row_cap_are_enforced(): void
    {
        $item = $this->serializedItem($this->warehouse);
        Setting::putMany(['serials.import_max_rows' => '2']);

        Livewire::actingAs($this->stock)->test(SerialRegistration::class)
            ->call('openDialog')->call('selectItem', $item->id)
            ->set('file', UploadedFile::fake()->createWithContent('evil.html', '<html></html>'))
            ->assertHasErrors('file')
            ->set('file', UploadedFile::fake()->createWithContent('many.csv', "serial_number\nAAA001\nAAA002\nAAA003\n"))
            ->assertHasErrors('file');
    }

    public function test_item_page_preselects_and_locks_the_item(): void
    {
        $item = $this->serializedItem($this->warehouse);
        $other = $this->serializedItem($this->warehouse, '5', ['sku' => 'OTHER', 'name' => 'Other']);
        $plain = Item::factory()->create(['is_serialized' => false]);
        $this->receiveSerials($this->stock, $this->warehouse, $item, ['ITM001', 'ITM002']);

        $this->actingAs($this->stock)->get(route('items.show', ['item' => $item, 'tab' => 'serials']))->assertOk()
            ->assertSee('data-item-serials', false)->assertSee('ITM001')->assertSee(__('stock.register.button'));
        $this->actingAs($this->stock)->get(route('items.show', ['item' => $plain, 'tab' => 'serials']))->assertOk()->assertSee('data-serials-off', false);

        Livewire::actingAs($this->stock)->test(SerialRegistration::class, ['itemId' => $item->id, 'itemLocked' => true])
            ->call('openDialog')
            ->assertSee('data-item-locked="true"', false)
            ->call('selectItem', $other->id)
            ->assertSet('itemId', $item->id)
            ->set('itemId', $other->id)
            ->assertForbidden();

        Livewire::actingAs($this->stock)->test(ItemShow::class, ['item' => $item])->assertOk();
    }

    public function test_permissions_and_menu(): void
    {
        $item = $this->serializedItem($this->warehouse);
        $plain = Item::factory()->create(['is_serialized' => false]);
        $sales = User::factory()->sales()->create();

        Livewire::actingAs($sales)->test(SerialRegistration::class)
            ->assertDontSee(__('stock.register.button'))
            ->call('openDialog')->assertForbidden();

        // Spec 06 SoD-S4: admin sees the list read-only and can neither open the dialog nor register.
        $admin = User::factory()->admin()->create();
        Livewire::actingAs($admin)->test(SerialRegistration::class)
            ->assertDontSee(__('stock.register.button'))
            ->call('openDialog')->assertForbidden();
        $this->actingAs($admin)->get(route('serials.index'))->assertOk()->assertDontSee(__('stock.register.button'));
        $this->assertFalse($admin->can('register', ItemSerial::class));

        try {
            app(RegisterSerials::class)->register($item, $this->warehouse, ['SOD-ADMIN-1'], $admin);
            $this->fail('Admin must not create a goods receipt through serial registration.');
        } catch (AuthorizationException) {
            // MySQL review: the column is serial_normalized (a quoted 'serial' was a string literal on SQLite).
            $this->assertSame(0, ItemSerial::query()->where('serial_normalized', 'SOD-ADMIN-1')->count());
            $this->assertSame(0, ItemSerial::query()->where('item_id', $item->id)->count(), 'No serial was created for the item.');
        }

        Livewire::actingAs($this->stock)->test(SerialRegistration::class)
            ->assertSee(__('stock.register.button'))
            ->call('openDialog')->assertOk()
            ->call('selectItem', $plain->id)->assertNotFound();

        $this->actingAs($this->stock)->get(route('serials.index'))->assertOk()
            ->assertSee(__('stock.register.button'))
            ->assertSeeInOrder(['data-nav-group="catalog"', route('serials.index')], false)
            ->assertDontSee('/catalog/units', false);
        $this->actingAs($this->stock)->get(route('serials.index', ['item' => $item->id]))->assertOk();
    }
}
