<?php

namespace Tests\Feature\Catalog;

use App\Enums\SerialSource;
use App\Enums\SerialStatus;
use App\Enums\UserRole;
use App\Livewire\Catalog\Items\Show as ItemShow;
use App\Livewire\Catalog\LabelPrint;
use App\Livewire\Settings\LabelTemplateEditor;
use App\Livewire\Settings\LabelTemplates;
use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\LabelTemplate;
use App\Models\User;
use App\Support\Labels\BarcodeSvg;
use App\Support\Labels\Code128;
use App\Support\Labels\Ean13;
use App\Support\Labels\LabelData;
use App\Support\Labels\LabelLayout;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LabelPrintingTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_code128_symbol_has_eleven_modules(): void
    {
        foreach (range(32, 126) as $code) {
            // start + symbol + checksum = 33 modules, stop = 13
            $this->assertSame(46, strlen(Code128::modules(chr($code))), 'char '.$code);
        }

        foreach (range(0, 99) as $pair) {
            $this->assertSame(57, strlen(Code128::modules(sprintf('%02d%02d', $pair, $pair))), 'pair '.$pair);
        }
    }

    public function test_code128_uses_subset_c_for_digit_runs(): void
    {
        $this->assertSame([105, 12, 34, 56, 78, 47, 106], Code128::symbols('12345678'));
        $this->assertSame(104, Code128::symbols('AB12')[0]);
    }

    public function test_ean13_validates_and_encodes_95_modules(): void
    {
        $this->assertTrue(Ean13::canEncode('5901234123457'));
        $this->assertFalse(Ean13::canEncode('5901234123458'));
        $this->assertSame('5901234123457', Ean13::normalize('590123412345'));
        $modules = Ean13::modules('5901234123457');
        $this->assertSame(95, strlen($modules));
        $this->assertStringStartsWith('101', $modules);
        $this->assertStringEndsWith('101', $modules);
        $this->assertSame('01010', substr($modules, 45, 5));
    }

    public function test_barcode_symbology_resolution(): void
    {
        $this->assertSame('ean13', BarcodeSvg::resolve('auto', '5901234123457'));
        $this->assertSame('code128', BarcodeSvg::resolve('auto', '00-00001234'));
        $this->assertSame('code128', BarcodeSvg::resolve('ean13', 'ABC'));
        $this->assertSame('code128', BarcodeSvg::resolve('code128', '5901234123457'));
        $this->assertNull(BarcodeSvg::resolve('auto', ''));
        $this->assertStringContainsString('<svg', (string) BarcodeSvg::render('auto', 'ABC-1'));
    }

    public function test_layout_sanitizer_whitelists_and_clamps(): void
    {
        $clean = LabelLayout::sanitize([
            ['type' => 'script', 'x' => 1],
            'not-an-array',
            ['type' => 'text', 'field' => 'password', 'x' => -5, 'y' => 999, 'w' => 500, 'h' => 'abc', 'font_size' => 9999, 'align' => 'justify', 'id' => '<b>x'],
            ['type' => 'price', 'small_valign' => 'middle', 'small_scale' => 1],
        ], 58, 40);

        $this->assertCount(2, $clean);
        $this->assertSame('name', $clean[0]['field']);
        $this->assertSame(0.0, $clean[0]['x']);
        $this->assertSame(58.0, $clean[0]['w']);
        $this->assertLessThanOrEqual(40.0, $clean[0]['y'] + $clean[0]['h']);
        $this->assertSame(120.0, $clean[0]['font_size']);
        $this->assertSame('left', $clean[0]['align']);
        $this->assertSame('bx', $clean[0]['id']);
        $this->assertSame('top', $clean[1]['small_valign']);
        $this->assertSame(20, $clean[1]['small_scale']);
    }

    public function test_price_has_no_coins_and_is_split_into_thousand_groups(): void
    {
        $this->assertSame(['1', '250', '001'], LabelData::priceParts(Money::of(125000050, 'UZS'))['groups']);
        $this->assertSame(['1', '250', '000'], LabelData::priceParts(Money::of(125000049, 'UZS'))['groups']);
        $this->assertSame(['999'], LabelData::priceParts(Money::of(99900, 'UZS'))['groups']);
        $this->assertSame(['0'], LabelData::priceParts(Money::of(0, 'UZS'))['groups']);
        $this->assertSame('UZS', LabelData::priceParts(Money::of(100, 'UZS'))['currency']);
        $this->assertArrayNotHasKey('decimals', LabelData::priceParts(Money::of(100, 'UZS')));
        $this->assertNull(LabelData::priceParts(null));
    }

    public function test_only_the_last_three_digits_are_smaller_and_positioned(): void
    {
        $values = ['fields' => LabelData::sample()['fields'], 'price' => LabelData::priceParts(Money::of(125000000, 'UZS'))];
        $element = LabelLayout::sanitize([['type' => 'price', 'w' => 40, 'h' => 12, 'small_last_digits' => true, 'small_currency' => false, 'small_scale' => 50, 'small_valign' => 'bottom']], 58, 40)[0];
        $html = view('components.label-element', ['element' => $element, 'values' => $values, 'logo' => null])->render();

        $this->assertStringContainsString("1\u{00A0}250\u{00A0}<span style=\"font-size:50%;vertical-align:text-bottom;\">000</span>\u{00A0}UZS</span>", $html);
        $this->assertSame(1, substr_count($html, 'font-size:50%'));
        $this->assertStringNotContainsString(',', strip_tags($html), 'No coins are printed.');
    }

    public function test_small_currency_and_top_baseline_positions(): void
    {
        $values = ['fields' => LabelData::sample()['fields'], 'price' => LabelData::priceParts(Money::of(125000000, 'UZS'))];

        foreach (['top' => 'text-top', 'baseline' => 'baseline', 'bottom' => 'text-bottom'] as $position => $css) {
            $element = LabelLayout::sanitize([['type' => 'price', 'w' => 40, 'h' => 12, 'small_last_digits' => true, 'small_currency' => true, 'small_valign' => $position]], 58, 40)[0];
            $html = view('components.label-element', ['element' => $element, 'values' => $values, 'logo' => null])->render();

            $this->assertSame(2, substr_count($html, 'vertical-align:'.$css), $position);
        }
    }

    public function test_short_prices_and_switched_off_option_stay_plain(): void
    {
        $short = ['fields' => LabelData::sample()['fields'], 'price' => LabelData::priceParts(Money::of(45000, 'UZS'))];
        $on = LabelLayout::sanitize([['type' => 'price', 'w' => 40, 'h' => 12, 'small_last_digits' => true, 'show_currency' => false]], 58, 40)[0];
        $off = ['small_last_digits' => false] + $on;
        $long = ['fields' => $short['fields'], 'price' => LabelData::priceParts(Money::of(125000000, 'UZS'))];

        $render = fn (array $element, array $values): string => view('components.label-element', ['element' => $element, 'values' => $values, 'logo' => null])->render();

        $this->assertStringNotContainsString('font-size:55%', $render($on, $short), 'A price under 1 000 has no group to shrink.');
        $this->assertStringNotContainsString('font-size:55%', $render($off, $long));
        $this->assertSame('450', trim(strip_tags($render($on, $short))));
    }

    public function test_admin_manages_templates_and_others_are_forbidden(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('settings.label-templates'))->assertOk()->assertSee(__('labels.templates.title'));

        Livewire::test(LabelTemplates::class)
            ->set('name', 'Shelf 58x40')
            ->set('width', '58')
            ->set('height', '40')
            ->call('create')
            ->assertRedirect();

        $template = LabelTemplate::query()->firstOrFail();
        $this->assertTrue($template->is_default);
        $this->assertNotEmpty($template->elements);
        $this->get(route('settings.label-templates.edit', $template))->assertOk()->assertSee('data-ref="stage"', false);

        $this->actingAs(User::factory()->role(UserRole::Sales)->create());
        $this->get(route('settings.label-templates'))->assertForbidden();
        $this->get(route('settings.label-templates.edit', $template))->assertForbidden();
    }

    #[DataProvider('invalidSizes')]
    public function test_template_size_is_validated(string $width, string $height): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(LabelTemplates::class)->set('name', 'X')->set('width', $width)->set('height', $height)->call('create')->assertHasErrors();
        $this->assertSame(0, LabelTemplate::query()->count());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidSizes(): array
    {
        return ['too small' => ['5', '5'], 'too big' => ['900', '40'], 'text' => ['abc', '40']];
    }

    public function test_designer_saves_a_sanitised_layout(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $template = LabelTemplate::factory()->create();

        Livewire::test(LabelTemplateEditor::class, ['template' => $template])
            ->call('save', 'Renamed', 70, 50, [
                ['type' => 'text', 'field' => 'sku', 'x' => 3, 'y' => 3, 'w' => 30, 'h' => 5],
                ['type' => 'evil'],
            ])
            ->assertDispatched('label-designer-saved', ok: true);

        $template->refresh();
        $this->assertSame('Renamed', $template->name);
        $this->assertSame(70.0, $template->width_mm);
        $this->assertCount(1, $template->elements);
        $this->assertSame('sku', $template->elements[0]['field']);
    }

    public function test_designer_rejects_a_bad_size(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $template = LabelTemplate::factory()->create(['name' => 'Keep']);

        Livewire::test(LabelTemplateEditor::class, ['template' => $template])
            ->call('save', 'Changed', 5, 5, [])
            ->assertDispatched('label-designer-saved', ok: false);

        $this->assertSame('Keep', $template->fresh()->name);
    }

    public function test_designer_renders_an_element_with_the_shared_partial(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $template = LabelTemplate::factory()->create();

        $result = Livewire::test(LabelTemplateEditor::class, ['template' => $template])
            ->call('renderElement', ['id' => 'a1', 'type' => 'barcode', 'field' => 'barcode', 'x' => 1, 'y' => 1, 'w' => 30, 'h' => 10], 58, 40)
            ->effects['returns'][0] ?? null;

        $this->assertSame('a1', $result['element']['id']);
        $this->assertStringContainsString('<svg', $result['html']);
        $this->assertStringContainsString('5901234123457', $result['html']);
    }

    public function test_print_screen_finds_items_by_name_sku_barcode_and_serial(): void
    {
        $this->actingAs(User::factory()->manager()->create());
        LabelTemplate::factory()->default()->create();
        $item = Item::factory()->priced('125000.50')->create(['name' => 'Cordless drill', 'sku' => 'DR-100', 'barcode' => '5901234123457']);
        $serial = $this->serialFor($item, 'sn-77-abc');
        Item::factory()->create(['name' => 'Other thing', 'sku' => 'ZZ-1']);

        $page = Livewire::test(LabelPrint::class);

        foreach (['drill', 'DR-100', '5901234123457'] as $term) {
            $page->set('search', $term)->assertSee('Cordless drill')->assertDontSee('Other thing');
        }

        $page->set('search', 'SN-77')->assertSee('Cordless drill')->assertSee($serial->serial_raw);
        $page->call('add', $item->id, $serial->id)->assertSet('queue', [['item_id' => $item->id, 'serial_id' => $serial->id, 'copies' => 1]]);
    }

    public function test_scanner_enter_adds_exact_barcode_and_counts_copies(): void
    {
        $this->actingAs(User::factory()->manager()->create());
        LabelTemplate::factory()->default()->create();
        $item = Item::factory()->create(['barcode' => '4006381333931']);

        Livewire::test(LabelPrint::class)
            ->set('search', '4006381333931')->call('addFirst')
            ->set('search', '4006381333931')->call('addFirst')
            ->assertSet('queue', [['item_id' => $item->id, 'serial_id' => null, 'copies' => 2]])
            ->assertSet('search', '');
    }

    public function test_copies_are_clamped_and_job_size_is_limited(): void
    {
        $this->actingAs(User::factory()->manager()->create());
        LabelTemplate::factory()->default()->create();
        $item = Item::factory()->create();

        Livewire::test(LabelPrint::class)
            ->call('add', $item->id)
            ->set('queue.0.copies', 99999)
            ->assertSet('queue.0.copies', LabelPrint::MAX_COPIES)
            ->set('queue.0.copies', 0)
            ->assertSet('queue.0.copies', 1);
    }

    public function test_print_opens_the_sheet_at_exact_label_size(): void
    {
        $this->actingAs(User::factory()->manager()->create());
        $template = LabelTemplate::factory()->default()->create(['width_mm' => 58, 'height_mm' => 40]);
        $item = Item::factory()->priced('125000.50')->create(['name' => 'Cordless drill', 'barcode' => '5901234123457']);

        $component = Livewire::test(LabelPrint::class)
            ->call('add', $item->id)
            ->set('queue.0.copies', 3)
            ->call('print')
            ->assertRedirectContains('/labels/sheet?job=');

        parse_str((string) parse_url($component->effects['redirect'], PHP_URL_QUERY), $query);

        $response = $this->get(route('labels.sheet', ['job' => $query['job']]))->assertOk();
        $response->assertSee('size: 58mm 40mm', false)->assertSee('Cordless drill')->assertSee('<svg', false);
        $this->assertSame(3, substr_count($response->getContent(), 'class="label-page'));
        $this->assertSame($template->id, session('label_jobs')[$query['job']]['template_id']);
    }

    public function test_sheet_needs_a_valid_job_and_a_logged_in_user(): void
    {
        $this->get(route('labels.sheet', ['job' => 'nope']))->assertRedirect();

        $this->actingAs(User::factory()->manager()->create());
        $this->get(route('labels.sheet', ['job' => 'nope']))->assertNotFound();
    }

    public function test_manager_saves_the_item_barcode_and_it_is_validated(): void
    {
        $this->actingAs(User::factory()->manager()->create());
        $item = Item::factory()->create();

        Livewire::test(ItemShow::class, ['item' => $item])->set('barcode', 'ABC-123')->call('saveLocalFields')->assertHasNoErrors();
        $this->assertSame('ABC-123', $item->fresh()->barcode);
        $this->assertSame('ABC-123', LabelData::barcodeValue($item->fresh()));

        Livewire::test(ItemShow::class, ['item' => $item])->set('barcode', "bad\u{00e9}")->call('saveLocalFields')->assertHasErrors('barcode');
        Livewire::test(ItemShow::class, ['item' => $item])->set('barcode', '')->call('saveLocalFields')->assertHasNoErrors();
        $this->assertNull($item->fresh()->barcode);
        $this->assertSame($item->sku, LabelData::barcodeValue($item->fresh()));
    }

    private function serialFor(Item $item, string $raw): ItemSerial
    {
        return ItemSerial::query()->create([
            'item_id' => $item->id,
            'serial_raw' => $raw,
            'serial_normalized' => strtoupper($raw),
            'status' => SerialStatus::Available,
            'source' => SerialSource::OpeningBalance,
            'received_at' => now(),
        ]);
    }
}
