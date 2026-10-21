<?php

namespace Tests\Feature;

use App\Documents\DocumentFormatter;
use App\Documents\DocumentLanguage;
use App\Documents\DocumentRenderer;
use App\Documents\HasDocumentLanguage;
use App\Documents\InteractsWithDocumentLanguage;
use App\Enums\Locale;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentPrintingTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_open_print_screens(): void
    {
        $this->get(route('print.sample'))->assertRedirect(route('login'));
    }

    public function test_document_defaults_to_the_interface_language(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('print.sample'))
            ->assertOk()
            ->assertSee('data-document-language="uz"', false)
            ->assertSee(__('documents.offer.title', [], 'uz'));
    }

    #[DataProvider('documentLanguages')]
    public function test_document_prints_in_the_chosen_language_independent_of_the_ui(Locale $ui, Locale $document): void
    {
        $user = User::factory()->create(['locale' => $ui]);

        $response = $this->actingAs($user)
            ->get(route('print.sample', ['lang' => $document->value]))
            ->assertOk()
            ->assertSee('<html lang="'.$ui->value.'"', false)
            ->assertSee('lang="'.$document->value.'"', false)
            // Document body in the document language...
            ->assertSee(__('documents.offer.title', [], $document->value))
            ->assertSee(__('documents.total_with_vat', [], $document->value))
            ->assertSee(__('documents.units.pcs', [], $document->value))
            ->assertSee(__('users.roles.sales', [], $document->value))
            // ...toolbar in the interface language.
            ->assertSee(__('common.print.print_button', [], $ui->value))
            ->assertSee('window.print()', false);

        if ($ui !== $document) {
            $response->assertDontSee(__('documents.offer.title', [], $ui->value));
        }

        $this->assertSame($ui->value, app()->getLocale(), 'Rendering must restore the interface locale.');
    }

    /**
     * @return array<string, array{Locale, Locale}>
     */
    public static function documentLanguages(): array
    {
        return [
            'uz ui, ru document' => [Locale::Uz, Locale::Ru],
            'ru ui, en document' => [Locale::Ru, Locale::En],
            'en ui, uz document' => [Locale::En, Locale::Uz],
            'same language' => [Locale::Ru, Locale::Ru],
        ];
    }

    public function test_print_screen_has_the_language_picker_with_the_current_choice(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('print.sample', ['lang' => 'en']))
            ->assertSee('data-print-language-picker', false)
            ->assertSee(route('print.sample', ['lang' => 'uz']), false)
            ->assertSee(route('print.sample', ['lang' => 'ru']), false)
            ->assertSeeInOrder(['aria-current="true"', '>EN</a>'], false);
    }

    public function test_unsupported_document_language_falls_back_to_the_interface_language(): void
    {
        $this->actingAs(User::factory()->create(['locale' => Locale::Ru]))
            ->get(route('print.sample', ['lang' => 'de']))
            ->assertOk()
            ->assertSee('data-document-language="ru"', false);
    }

    public function test_sample_totals_and_amount_in_words_are_computed_without_floats(): void
    {
        // 4 x 3 250 000 - 5% = 12 350 000; 12.5 x 180 000 = 2 250 000; 350 x 4 200 - 10% = 1 323 000
        // subtotal 15 923 000, VAT 12% 1 910 760, total 17 833 760.
        $this->actingAs(User::factory()->create())
            ->get(route('print.sample', ['lang' => 'en']))
            ->assertSee('15,923,000.00')
            ->assertSee('1,910,760.00')
            ->assertSee('17,833,760.00')
            ->assertSee('Seventeen million eight hundred thirty-three thousand seven hundred sixty sums and 00 tiyins');
    }

    public function test_renderer_switches_carbon_and_restores_the_locale(): void
    {
        app()->setLocale('en');
        $html = (string) app(DocumentRenderer::class)->render('documents.sample', [
            'offer' => [
                'number' => 'T-1', 'date' => '2026-10-16', 'valid_until' => '2026-11-15', 'currency' => 'UZS',
                'seller' => ['name' => 'S', 'address' => 'A', 'tax_id' => '1'],
                'customer' => ['name' => 'C', 'address' => 'B', 'tax_id' => '2'],
                'prepared_by' => User::factory()->make(), 'lines' => [],
                'subtotal' => '0', 'vat_rate' => '12', 'vat' => '0', 'total' => '0',
            ],
        ], Locale::Ru);

        $this->assertStringContainsString('15 ноября 2026 г.', $html);
        $this->assertStringContainsString('Коммерческое предложение', $html);
        $this->assertSame('en', app()->getLocale());
        $this->assertSame('en', Carbon::getLocale());
    }

    public function test_uzbek_dates_use_latin_script(): void
    {
        app()->setLocale('uz');

        $this->assertSame('uz_Latn', Carbon::getLocale());
        $this->assertSame('2026-yil 16-oktabr', (new DocumentFormatter(Locale::Uz))->longDate('2026-10-16'));
    }

    public function test_language_resolution_order(): void
    {
        app()->setLocale('uz');

        $customer = new class extends Model implements HasDocumentLanguage
        {
            use InteractsWithDocumentLanguage;

            protected $guarded = [];
        };

        $this->assertSame(Locale::Uz, DocumentLanguage::resolve());
        $this->assertSame(Locale::Uz, DocumentLanguage::resolve(null, $customer));

        $customer->preferred_locale = 'en';
        $this->assertSame(Locale::En, DocumentLanguage::resolve(null, $customer));
        $this->assertSame(Locale::Ru, DocumentLanguage::resolve('ru', $customer), 'An explicit choice wins.');
        $this->assertSame(Locale::En, DocumentLanguage::resolve('xx', $customer), 'Invalid choices are ignored.');

        $customer->preferred_locale = 'fr';
        $this->assertSame(Locale::Uz, DocumentLanguage::resolve(null, $customer), 'Unsupported preferences are ignored.');
    }

    #[DataProvider('numberFormats')]
    public function test_numbers_and_money_are_formatted_per_locale(Locale $locale, string $money, string $quantity, string $percent): void
    {
        $formatter = new DocumentFormatter($locale);

        $this->assertSame($money, $formatter->money('1234567.505', 'uzs'));
        $this->assertSame($quantity, $formatter->quantity('12.500'));
        $this->assertSame($percent, $formatter->percent('12'));
    }

    /**
     * @return array<string, array{Locale, string, string, string}>
     */
    public static function numberFormats(): array
    {
        return [
            'uz' => [Locale::Uz, "1\u{00A0}234\u{00A0}567,51\u{00A0}UZS", '12,5', "12\u{00A0}%"],
            'ru' => [Locale::Ru, "1\u{00A0}234\u{00A0}567,51\u{00A0}UZS", '12,5', "12\u{00A0}%"],
            'en' => [Locale::En, "1,234,567.51\u{00A0}UZS", '12.5', '12%'],
        ];
    }

    public function test_rounding_is_half_away_from_zero_on_decimal_strings(): void
    {
        $this->assertSame('2.35', DocumentFormatter::round('2.345', 2));
        $this->assertSame('2.34', DocumentFormatter::round('2.3449', 2));
        $this->assertSame('-2.35', DocumentFormatter::round('-2.345', 2));
        $this->assertSame('0.30', DocumentFormatter::round('0.295', 2));
        $this->assertSame('0', (new DocumentFormatter(Locale::En))->number('-0.001', 0));
    }

    public function test_non_decimal_input_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DocumentFormatter(Locale::En))->number('1e3');
    }

    #[DataProvider('amountsInWords')]
    public function test_amount_in_words(Locale $locale, string $amount, string $currency, string $expected): void
    {
        app()->setLocale('en');

        $this->assertSame($expected, (new DocumentFormatter($locale))->amountInWords($amount, $currency));
    }

    /**
     * @return array<string, array{Locale, string, string, string}>
     */
    public static function amountsInWords(): array
    {
        return [
            'uz' => [Locale::Uz, '21451121.07', 'UZS', 'Yigirma bir million to‘rt yuz ellik bir ming bir yuz yigirma bir so‘m 07 tiyin'],
            'uz zero' => [Locale::Uz, '0', 'UZS', 'Nol so‘m 00 tiyin'],
            'ru one thousand (feminine)' => [Locale::Ru, '1001', 'RUB', 'Одна тысяча один рубль 00 копеек'],
            'ru plural forms' => [Locale::Ru, '2002.02', 'UZS', 'Две тысячи два сума 02 тийина'],
            'ru teens' => [Locale::Ru, '11512', 'USD', 'Одиннадцать тысяч пятьсот двенадцать долларов США 00 центов'],
            'en' => [Locale::En, '1000000.01', 'USD', 'One million US dollars and 01 cent'],
            'en hyphenated tens' => [Locale::En, '99', 'EUR', 'Ninety-nine euros and 00 cents'],
            'unknown currency' => [Locale::En, '5', 'KZT', 'Five KZT and 00/100'],
        ];
    }
}
