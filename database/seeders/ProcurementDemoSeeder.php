<?php

namespace Database\Seeders;

use App\Actions\Counterparties\ManageCounterparty;
use App\Actions\Tenders\ManageTender;
use App\Actions\Tenders\ManageTenderParticipants;
use App\Enums\CounterpartyDocumentType;
use App\Enums\CounterpartyStatus;
use App\Enums\TenderType;
use App\Models\Counterparty;
use App\Models\Tender;
use App\Models\User;
use App\Services\Tenders\TenderClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demo suppliers and carriers (some qualified with a verified document) and one transport tender
 * draft owned by the logistics specialist, so the procurement screens have data. Idempotent.
 */
class ProcurementDemoSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::query()->where('email', 'logistics@crm.test')->first();

        if ($owner === null) {
            return;
        }

        $counterparties = [
            ['name' => 'Silk Road Trans LLC', 'country' => 'UZ', 'city' => 'Tashkent', 'is_carrier' => true, 'qualified' => true, 'locale' => 'ru'],
            ['name' => 'Anatolia Lojistik A.Ş.', 'country' => 'TR', 'city' => 'Istanbul', 'is_carrier' => true, 'qualified' => true, 'locale' => 'en'],
            ['name' => 'KazTransit Group', 'country' => 'KZ', 'city' => 'Almaty', 'is_carrier' => true, 'qualified' => false, 'locale' => 'ru'],
            ['name' => 'Euro Freight Sp. z o.o.', 'country' => 'PL', 'city' => 'Warsaw', 'is_carrier' => true, 'qualified' => false, 'locale' => 'en'],
            ['name' => 'NetSupply FZE', 'country' => 'AE', 'city' => 'Dubai', 'is_supplier' => true, 'qualified' => true, 'locale' => 'en'],
            ['name' => 'Toshkent Elektro Savdo', 'country' => 'UZ', 'city' => 'Tashkent', 'is_supplier' => true, 'qualified' => true, 'locale' => 'uz'],
            ['name' => 'Shenzhen Tech Components', 'country' => 'CN', 'city' => 'Shenzhen', 'is_supplier' => true, 'qualified' => false, 'locale' => 'en'],
        ];

        foreach ($counterparties as $data) {
            $counterparty = Counterparty::query()->firstOrCreate(['name' => $data['name']], [
                'country' => $data['country'],
                'city' => $data['city'],
                'is_supplier' => $data['is_supplier'] ?? false,
                'is_carrier' => $data['is_carrier'] ?? false,
                'status' => $data['qualified'] ? CounterpartyStatus::Qualified : CounterpartyStatus::Prospect,
                'preferred_locale' => $data['locale'],
                'email' => 'tenders@'.strtolower(preg_replace('/[^a-z]/i', '', $data['name'])).'.test',
                'owner_id' => $owner->id,
            ]);

            if ($data['qualified'] && ! $counterparty->documents()->exists()) {
                $counterparty->documents()->create([
                    'type' => ($data['is_carrier'] ?? false) ? CounterpartyDocumentType::CmrInsurance : CounterpartyDocumentType::Registration,
                    'number' => 'DEMO-'.$counterparty->id,
                    'valid_until' => CarbonImmutable::now()->addYear()->toDateString(),
                    'verified_by' => $owner->id,
                    'verified_at' => now(),
                ]);
            }

            if (! $counterparty->contacts()->exists()) {
                app(ManageCounterparty::class)->saveContact($counterparty, null, ['name' => 'Tender desk', 'email' => $counterparty->email, 'preferred_locale' => $data['locale'], 'is_primary' => true]);
            }
        }

        if (Tender::query()->exists()) {
            return;
        }

        $manage = app(ManageTender::class);
        $tender = $manage->create(TenderType::Transport, ['title' => 'Istanbul → Tashkent, 1 FTL network equipment'], $owner);
        $manage->updateHeader($tender, [
            'payment_terms' => '100% within 15 days after delivery and original CMR',
            'payment_days' => 15,
            'clarification_deadline_at' => TenderClock::addWorkingDays(CarbonImmutable::now(), 4),
            'bids_due_at' => TenderClock::addWorkingDays(CarbonImmutable::now(), 6),
        ]);
        $manage->updateTransportSpec($tender->lots()->first(), [
            'origin_country' => 'TR', 'origin_city' => 'Istanbul', 'origin_address' => 'Hadımköy, Arnavutköy',
            'destination_country' => 'UZ', 'destination_city' => 'Tashkent', 'destination_address' => 'Sergeli, main warehouse',
            'mode' => 'road', 'load_type' => 'ftl', 'vehicle_type' => 'Tent 82-92 m3', 'units_count' => 1,
            'cargo_description' => 'Network equipment on pallets', 'gross_kg' => '8000', 'volume_m3' => '40', 'packages_count' => 20,
            'cargo_value_minor' => 5_000_000, 'cargo_currency' => 'USD', 'incoterm' => 'FCA', 'incoterm_place' => 'Istanbul',
        ]);

        $participants = app(ManageTenderParticipants::class);

        foreach (Counterparty::query()->where('is_carrier', true)->whereIn('status', ['qualified', 'prospect'])->orderBy('id')->limit(3)->get() as $carrier) {
            $participants->invite($tender, $carrier, $owner);
        }
    }
}
