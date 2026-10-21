<?php

namespace Tests\Feature\Offers;

use App\Actions\Offers\AcceptOffer;
use App\Actions\Offers\RecordCustomerSelection;
use App\Actions\Offers\SendOffer;
use App\Enums\Locale;
use App\Enums\SelectionChannel;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsOffers;
use Tests\TestCase;

/**
 * Spec 05 section 11, AC-25..AC-27: the printed offer through DocumentRenderer in uz/ru/en
 * independent of the interface language, readable in black-and-white, no internal data.
 */
class OfferPrintTest extends TestCase
{
    use BuildsOffers, RefreshDatabase;

    /**
     * @return array<string, array{Locale, Locale}>
     */
    public static function languages(): array
    {
        return [
            'uz document, ru ui' => [Locale::Uz, Locale::Ru],
            'ru document, en ui' => [Locale::Ru, Locale::En],
            'en document, uz ui' => [Locale::En, Locale::Uz],
        ];
    }

    /** AC-25 / AC-27 */
    #[DataProvider('languages')]
    public function test_blank_form_prints_boxes_letters_tick_boxes_and_footnote(Locale $document, Locale $ui): void
    {
        $rep = User::factory()->sales()->create(['locale' => $ui]);
        ['offer' => $offer] = $this->fixtureOffer($rep);
        $offer->update(['internal_notes' => 'INTERNAL-ONLY-NOTE']);

        $response = $this->actingAs($rep)->get(route('offers.print', ['offer' => $offer, 'lang' => $document->value]))
            ->assertOk()
            ->assertSee('data-document-language="'.$document->value.'"', false)
            ->assertSee('<html lang="'.$ui->value.'"', false)
            ->assertSee('data-mode="blank"', false)
            ->assertSee(__('documents.offer.alternative_heading', [], $document->value))
            ->assertSee(__('documents.offer.optional_heading', [], $document->value))
            ->assertSee(__('documents.offer.recommended', [], $document->value))
            ->assertSee(__('documents.offer.not_added_note', [], $document->value))
            ->assertSee(__('documents.offer.customer_selection', [], $document->value))
            ->assertSee(__('documents.offer.total_fixed', [], $document->value))
            ->assertSee(__('documents.offer.reasons.cheaper_analogue', [], $document->value))
            ->assertSee('data-print-option="2A"', false)
            ->assertSee('data-print-option="2B"', false)
            ->assertSee('data-print-option="2C"', false)
            ->assertSee('data-print-option="3A"', false)
            ->assertSee('data-print-range', false)
            ->assertSee(__('common.print.print_button', [], $ui->value))
            ->assertDontSee('INTERNAL-ONLY-NOTE')
            ->assertDontSee('SECRET-MARGIN-NOTE');

        $this->assertSame(5, substr_count($response->getContent(), 'data-tick-box'), 'One empty tick box per option.');
        $this->assertDoesNotMatchRegularExpression('/documents\.offer\.[a-z_]+/', $response->getContent(), 'No raw translation keys.');
        $this->assertSame($ui->value, app()->getLocale());
    }

    public function test_language_defaults_to_the_offer_then_the_customer(): void
    {
        $rep = User::factory()->sales()->create(['locale' => Locale::Uz]);
        $customer = Customer::factory()->ownedBy($rep)->create(['preferred_locale' => 'ru']);
        $offer = $this->draftOffer($rep, $customer);
        $this->addLine($offer, $this->pricedItem('10.00'));

        $this->actingAs($rep)->get(route('offers.print', $offer))->assertSee('data-document-language="ru"', false);

        $offer->update(['document_locale' => 'en']);
        $this->actingAs($rep)->get(route('offers.print', $offer))->assertSee('data-document-language="en"', false);
    }

    /** AC-26 */
    public function test_selected_mode_after_acceptance_prints_only_chosen_lines_with_amount_in_words(): void
    {
        $rep = User::factory()->sales()->create();
        ['offer' => $offer, 'alternative' => $alt, 'lines' => $lines] = $this->fixtureOffer($rep);
        app(SendOffer::class)->handle($offer, $rep, SelectionChannel::Email);
        app(RecordCustomerSelection::class)->handle($offer->fresh(), $rep, [$alt->id => [$lines['C']->id]], SelectionChannel::Email, 'mail');
        app(AcceptOffer::class)->handle($offer->fresh(), $rep, SelectionChannel::Email, 'mail');

        $this->actingAs($rep)->get(route('offers.print', ['offer' => $offer, 'lang' => 'en', 'selection' => 'selected']))
            ->assertOk()
            ->assertSee('data-mode="selected"', false)
            ->assertSee('Compressor analogue')
            ->assertDontSee('Compressor budget')
            ->assertDontSee('Compressor premium')
            ->assertDontSee('data-tick-box', false)
            ->assertSee('1,848.00')
            ->assertSee('One thousand eight hundred forty-eight sums and 00 tiyins');

        $this->actingAs($rep)->get(route('offers.print', ['offer' => $offer, 'lang' => 'ru', 'selection' => 'current']))
            ->assertSee(__('documents.offer.selected', [], 'ru'))
            ->assertSee(__('documents.offer.not_selected', [], 'ru'));
    }

    public function test_accountant_code_is_printed_only_behind_the_flag_and_tax_codes_never(): void
    {
        $rep = User::factory()->sales()->create();
        $offer = $this->draftOffer($rep);
        $this->addLine($offer, $this->pricedItem('10.00', ['accountant_code' => 'ACC-PRINT-1', 'class_code' => '08517620001000000', 'package_code' => '1503778']));

        $this->actingAs($rep)->get(route('offers.print', $offer))->assertDontSee('ACC-PRINT-1')->assertDontSee('08517620001000000');

        config(['documents.show_accountant_code' => true]);

        $this->actingAs($rep)->get(route('offers.print', ['offer' => $offer, 'lang' => 'en']))
            ->assertSee('ACC-PRINT-1')
            ->assertSee(__('documents.offer.accountant_code', [], 'en'))
            ->assertDontSee('08517620001000000')
            ->assertDontSee('1503778');
    }
}
