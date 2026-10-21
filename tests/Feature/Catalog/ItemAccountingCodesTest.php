<?php

namespace Tests\Feature\Catalog;

use App\Livewire\Catalog\Items\Index;
use App\Livewire\Catalog\Items\Show;
use App\Models\Item;
use App\Models\User;
use App\Support\ItemCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithOneCApi;
use Tests\TestCase;

/**
 * Spec 02 BR-I.13a / BR-I.13b / AC-I.47d: accountant code, IKPU class code, package code and the
 * serial-tracking flag: optional in the 1C payload, never wiped by an empty 1C value, editable by
 * finance/admin, searchable/filterable on the items list.
 */
class ItemAccountingCodesTest extends TestCase
{
    use InteractsWithOneCApi, RefreshDatabase;

    public function test_rule_class_is_lenient_digits_only(): void
    {
        $this->assertNull(ItemCodes::classCodeError('08708001001000000'));
        $this->assertNull(ItemCodes::classCodeError('0870'));
        $this->assertTrue(ItemCodes::classCodeHasUnusualLength('0870'));
        $this->assertFalse(ItemCodes::classCodeHasUnusualLength('08708001001000000'));
        $this->assertSame('invalid_format', ItemCodes::classCodeError('0870A'));
        $this->assertSame('too_long', ItemCodes::classCodeError(str_repeat('1', 18)));
        $this->assertSame('invalid_format', ItemCodes::packageCodeError('15-03'));
        $this->assertNull(ItemCodes::packageCodeError('0001503778'));
        $this->assertSame('too_long', ItemCodes::accountantCodeError(str_repeat('a', 65)));
        $this->assertNull(ItemCodes::clean('   '));
    }

    public function test_1c_payload_accepts_absent_values_and_never_wipes_crm_values(): void
    {
        $this->enableInbound();
        $this->issueToken();

        // Backward compatible: no new fields at all.
        $this->seedItemsThroughApi(['00-1']);
        $item = Item::query()->where('sku', '00-1')->firstOrFail();
        $this->assertNull($item->class_code);
        $this->assertFalse($item->is_serialized);

        // Values entered in the CRM ...
        $item->update(['accountant_code' => 'ACC-1', 'class_code' => '11111111111111111', 'package_code' => '222', 'is_serialized' => true]);

        // ... survive an empty/null/absent value from 1C.
        $this->onec('POST', '/items', $this->envelope(['items' => [$this->itemRecord('00-1', [
            'name' => 'Renamed', 'accountant_code' => '', 'class_code' => null, 'is_serialized' => null, 'updated_at' => '2026-10-17T09:00:00+05:00',
        ])]]))->assertStatus(202);

        $item->refresh();
        $this->assertSame('Renamed', $item->name);
        $this->assertSame(['ACC-1', '11111111111111111', '222', true], [$item->accountant_code, $item->class_code, $item->package_code, $item->is_serialized]);

        // A non-empty value (or explicit boolean) from 1C overwrites; leading zeros are kept.
        $this->onec('POST', '/items', $this->envelope(['items' => [$this->itemRecord('00-1', [
            'class_code' => '08708001001000000', 'package_code' => '0001503778', 'is_serialized' => false, 'updated_at' => '2026-10-17T10:00:00+05:00',
        ])]]))->assertStatus(202);

        $item->refresh();
        $this->assertSame(['ACC-1', '08708001001000000', '0001503778', false], [$item->accountant_code, $item->class_code, $item->package_code, $item->is_serialized]);
    }

    public function test_1c_payload_with_invalid_codes_rejects_the_record(): void
    {
        $this->enableInbound();
        $this->issueToken();

        $this->onec('POST', '/items', $this->envelope([
            'units' => [$this->unitRecord()],
            'items' => [
                $this->itemRecord('00-1', ['class_code' => '0870A']),
                $this->itemRecord('00-2', ['package_code' => 15]),
                $this->itemRecord('00-3', ['is_serialized' => 'yes']),
                $this->itemRecord('00-4', ['class_code' => '08708001001000000', 'is_serialized' => true]),
            ],
        ]))->assertStatus(207)
            ->assertJsonPath('rejected.0.errors.0.code', 'invalid_format')
            ->assertJsonPath('rejected.1.errors.0.code', 'must_be_string')
            ->assertJsonPath('rejected.2.errors.0.code', 'must_be_boolean')
            ->assertJsonCount(3, 'rejected');

        $this->assertTrue(Item::query()->where('sku', '00-4')->firstOrFail()->is_serialized);
    }

    public function test_finance_and_admin_edit_codes_and_serial_flag_sales_and_manager_cannot(): void
    {
        $item = Item::factory()->create();

        Livewire::actingAs(User::factory()->finance()->create())->test(Show::class, ['item' => $item])
            ->set('tab', 'codes')
            ->set('accountant_code', ' 41.01-77 ')
            ->set('class_code', '0870')
            ->assertSee(__('catalog.items.class_code_length_warning'))
            ->set('package_code', '0015')
            ->set('is_serialized', true)
            ->call('saveAccountingFields')
            ->assertHasNoErrors();

        $item->refresh();
        $this->assertSame(['41.01-77', '0870', '0015', true], [$item->accountant_code, $item->class_code, $item->package_code, $item->is_serialized]);

        Livewire::actingAs(User::factory()->admin()->create())->test(Show::class, ['item' => $item])
            ->set('class_code', '12AB')
            ->call('saveAccountingFields')
            ->assertHasErrors('class_code');

        foreach ([User::factory()->sales()->create(), User::factory()->manager()->create()] as $user) {
            $this->assertFalse($user->can('updateAccountingCodes', $item));
            $this->assertFalse($user->can('updateSerialTracking', $item));

            $attempt = Livewire::actingAs($user)->test(Show::class, ['item' => $item])
                ->set('accountant_code', 'HACK')
                ->call('saveAccountingFields');

            // Managers may edit the CRM-local warranty months (Spec 06 21.5) but never the codes.
            $user->can('updateWarranty', $item) ? $attempt->assertOk() : $attempt->assertForbidden();
        }

        $this->assertSame('41.01-77', $item->fresh()->accountant_code);
    }

    public function test_items_list_searches_codes_and_filters_serialized(): void
    {
        $this->actingAs(User::factory()->sales()->create());
        $serial = Item::factory()->create(['name' => 'Router', 'accountant_code' => 'ACC-ROUTER', 'class_code' => '08471300001000000', 'is_serialized' => true]);
        $plain = Item::factory()->create(['name' => 'Cable', 'package_code' => '7777']);

        Livewire::test(Index::class)
            ->set('search', 'ACC-ROUTER')->assertSee('Router')->assertDontSee('Cable')
            ->set('search', '0847130')->assertSee('Router')
            ->set('search', '7777')->assertSee('Cable')->assertDontSee('Router')
            ->set('search', '')
            ->set('serialized', 'yes')->assertSee('Router')->assertDontSee('Cable')
            ->set('serialized', 'no')->assertSee('Cable')->assertDontSee('Router')
            ->set('serialized', '')
            ->set('showCodes', true)->assertSee('08471300001000000')->assertSee('7777');

        $this->get(route('items.show', $serial))->assertSee('data-serialized-badge', false)->assertSee('ACC-ROUTER');
        $this->get(route('items.show', $plain))->assertDontSee('data-serialized-badge', false);
    }
}
