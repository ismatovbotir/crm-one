<?php

namespace Tests\Concerns;

use App\Actions\Agreements\ManageAgreementTemplates;
use App\Actions\Offers\AcceptOffer;
use App\Actions\Offers\RecordCustomerSelection;
use App\Actions\Offers\SendOffer;
use App\Enums\SelectionChannel;
use App\Models\CompanyBankAccount;
use App\Models\CompanySignatory;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\OfferLine;
use App\Models\Order;
use App\Models\User;
use App\Support\CompanyProfile;
use Database\Seeders\AgreementTemplateSeeder;

/**
 * Orders through the real path (offer → send → choices → accept → CreateOrderFromOffer) and the
 * company/customer data agreements need.
 */
trait BuildsOrders
{
    use BuildsOffers;

    /**
     * Spec 06 AC-1 fixture: fixed line 1, alternative 2C and optional 3B chosen.
     *
     * @return array{order: Order, offer: Offer, lines: array<string, OfferLine>}
     */
    protected function acceptedFixtureOrder(User $rep, ?Customer $customer = null): array
    {
        $fixture = $this->fixtureOffer($rep, $customer);
        $offer = $fixture['offer'];
        app(SendOffer::class)->handle($offer, $rep, SelectionChannel::Email);
        app(RecordCustomerSelection::class)->handle($offer->fresh(), $rep, [$fixture['alternative']->id => [$fixture['lines']['C']->id], $fixture['optional']->id => [$fixture['lines']['Y']->id]], SelectionChannel::Email, 'mail');
        app(AcceptOffer::class)->handle($offer->fresh(), $rep, SelectionChannel::Email, 'Signed reply');

        return ['order' => Order::query()->where('offer_id', $offer->id)->firstOrFail(), 'offer' => $offer->fresh(), 'lines' => $fixture['lines']];
    }

    /** One goods line (1 000.00 UZS net, VAT 12%) with the given quantity and stock, accepted. */
    protected function simpleOrder(User $rep, string $quantity = '10', ?string $stock = '100', ?Customer $customer = null): Order
    {
        $offer = $this->draftOffer($rep, $customer);
        $this->addLine($offer, $this->pricedItem('1000.00', ['name' => 'Pump'], $stock), $quantity);
        app(SendOffer::class)->handle($offer->fresh(), $rep, SelectionChannel::Email);
        app(AcceptOffer::class)->handle($offer->fresh(), $rep, SelectionChannel::Email, 'mail');

        return Order::query()->where('offer_id', $offer->id)->firstOrFail();
    }

    /** Company profile, UZS bank account and the CEO as default signatory. */
    protected function setUpCompany(): CompanySignatory
    {
        CompanyProfile::save([
            'legal_name.uz' => 'RSG MChJ', 'legal_name.ru' => 'ООО «RSG»', 'legal_name.en' => 'RSG LLC',
            'address.uz' => 'Toshkent', 'address.ru' => 'Ташкент', 'address.en' => 'Tashkent',
            'city.uz' => 'Toshkent', 'city.ru' => 'г. Ташкент', 'city.en' => 'Tashkent',
            'tax_id' => '305123456',
        ]);
        CompanyBankAccount::query()->create(['bank_name' => 'Kapitalbank', 'mfo' => '01158', 'account_number' => '20208000900123456001', 'currency' => 'UZS', 'is_default' => true, 'is_active' => true]);

        return CompanySignatory::query()->create(['full_name' => 'Bakhtiyor Karimov', 'position' => 'Director', 'basis' => 'Charter', 'is_default' => true, 'is_active' => true]);
    }

    /** A customer with everything an agreement needs (legal name, INN, address, signatory, bank). */
    protected function agreementReadyCustomer(User $owner, array $attributes = []): Customer
    {
        $customer = Customer::factory()->ownedBy($owner)->create([
            'legal_name' => 'Buyer LLC', 'currency' => 'UZS', 'signatory_name' => 'Ali Valiyev', 'signatory_position' => 'Director', 'signatory_basis' => 'Charter',
            ...$attributes,
        ]);
        $customer->bankAccounts()->create(['bank_name' => 'Hamkorbank', 'mfo' => '00083', 'account_number' => '20208000900000001001', 'currency' => 'UZS', 'is_default' => true]);

        return $customer;
    }

    protected function seedAgreementTemplates(): void
    {
        if (! User::query()->exists()) {
            User::factory()->finance()->create();
        }

        app(AgreementTemplateSeeder::class)->run(app(ManageAgreementTemplates::class));
    }
}
