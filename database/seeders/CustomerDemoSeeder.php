<?php

namespace Database\Seeders;

use App\Enums\CustomerOrigin;
use App\Enums\LegalType;
use App\Enums\SyncStatus;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo customers with contacts for local development: owned by the seeded sales reps and manager,
 * one with payment terms, one archived, one as if pulled from 1C.
 * Idempotent (matched by tax ID). Never runs in production.
 */
class CustomerDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Skipping demo customers in production.');

            return;
        }

        // One transaction: atomic and much faster on SQLite.
        DB::transaction(fn () => $this->seedDemoData());
    }

    private function seedDemoData(): void
    {

        $sam = User::query()->where('email', 'sales@crm.test')->first();
        $sara = User::query()->where('email', 'sales2@crm.test')->first() ?? $sam;
        $maria = User::query()->where('email', 'manager@crm.test')->first() ?? $sam;

        if ($sam === null) {
            $this->command?->warn('Seed users first (DatabaseSeeder).');

            return;
        }

        // [name, legal name, tax id, city, phone, email, owner, locale, extra, contacts]
        $customers = [
            ['Alpha Trade', '"ALPHA TRADE" MChJ', '301234567', 'Tashkent', '+998712001010', 'info@alpha-trade.uz', $sam, 'uz', ['payment_terms_days' => 30],
                [['Aziz', 'Karimov', 'Purchasing manager', 'aziz@alpha-trade.uz', '+998901112233', true], ['Dilnoza', 'Rahimova', 'Accountant', 'buh@alpha-trade.uz', null, false]]],
            ['Samarqand Savdo', '"SAMARQAND SAVDO" MChJ', '302765432', 'Samarkand', '+998662203040', 'office@samsavdo.uz', $sam, 'uz', [],
                [['Bekzod', 'Tursunov', 'Director', 'bekzod@samsavdo.uz', '+998935554433', true]]],
            ['Navoiy Mining Service', 'OOO "Navoiy Mining Service"', '303111222', 'Navoiy', '+998792230011', 'supply@nms.uz', $sara, 'ru', ['payment_terms_days' => 45],
                [['Ирина', 'Ким', 'Начальник снабжения', 'i.kim@nms.uz', '+998977771122', true]]],
            ['Fergana Textile', '"FERGANA TEXTILE" AJ', '304555666', 'Fergana', '+998732245566', null, $sara, 'uz', [],
                [['Sherzod', 'Aliyev', 'IT manager', null, '+998914443322', true]]],
            ['Tashkent City Mall', 'LLC "Tashkent City Mall"', '305777888', 'Tashkent', '+998712345678', 'it@tcm.uz', $maria, 'en', [],
                [['John', 'Miller', 'Facility manager', 'john.miller@tcm.uz', '+998998887766', true]]],
            ['Bukhara Hotels Group', null, '306123123', 'Bukhara', '+998652211223', 'procurement@bhg.uz', $sam, null, [],
                [['Malika', 'Sodiqova', 'Procurement', 'malika@bhg.uz', null, true], ['Rustam', 'Jurayev', 'Chief engineer', null, '+998905556677', false]]],
            ['Khorezm Agro', '"KHOREZM AGRO" QK', '307444555', 'Urgench', null, 'agro@khorezm.uz', $sara, 'uz', [],
                []],
            ['Andijan Auto Parts', null, '308999000', 'Andijan', '+998742256789', 'parts@andijanauto.uz', $maria, 'ru', [],
                [['Олег', 'Пак', 'Закупщик', 'oleg@andijanauto.uz', '+998935550011', true]]],
            ['Alpha Trading Group', null, '309000111', 'Tashkent', '+998712001011', 'sales@alphagroup.uz', $sara, null, [],
                [['Nodir', 'Ergashev', 'Sales director', 'nodir@alphagroup.uz', null, true]]],
        ];

        foreach ($customers as [$name, $legal, $taxId, $city, $phone, $email, $owner, $locale, $extra, $contacts]) {
            $customer = Customer::withTrashed()->updateOrCreate(['tax_id' => $taxId], [
                'origin' => CustomerOrigin::Crm,
                'name' => $name,
                'legal_name' => $legal,
                'legal_type' => LegalType::LegalEntity,
                'country' => 'UZ',
                'city' => $city,
                'phone' => $phone,
                'email' => $email,
                'currency' => 'UZS',
                'owner_id' => $owner->id,
                'preferred_locale' => $locale,
                'sync_status' => SyncStatus::NotSynced,
                ...array_filter($extra, fn (mixed $value): bool => $value !== null),
            ]);

            foreach ($contacts as [$first, $last, $position, $contactEmail, $contactPhone, $primary]) {
                Contact::query()->updateOrCreate(
                    ['partner_id' => $customer->id, 'first_name' => $first, 'last_name' => $last],
                    ['position' => $position, 'email' => $contactEmail, 'phone' => $contactPhone, 'is_primary' => $primary],
                );
            }
        }

        // As if pulled from 1C (Spec 02): linked by GUID, tax ID locked in the form.
        Customer::withTrashed()->updateOrCreate(['tax_id' => '310222333'], [
            'origin' => CustomerOrigin::Onec,
            'name' => 'Uzbek Steel Distribution',
            'legal_name' => '"UZBEK STEEL DISTRIBUTION" MChJ',
            'legal_type' => LegalType::LegalEntity,
            'country' => 'UZ',
            'city' => 'Tashkent',
            'currency' => 'UZS',
            'owner_id' => $maria->id,
            'sync_status' => SyncStatus::Synced,
            'external_id' => '6f1c2a10-0000-4000-8000-000000012345',
            'external_code' => '000012345',
            'synced_at' => now()->subHour(),
        ]);

        // Archived example: creating a new customer with this tax ID offers "restore" instead.
        $archived = Customer::withTrashed()->updateOrCreate(['tax_id' => '311333444'], [
            'origin' => CustomerOrigin::Crm,
            'name' => 'Old Partner Logistics',
            'legal_type' => LegalType::SoleProprietor,
            'country' => 'UZ',
            'city' => 'Jizzakh',
            'currency' => 'UZS',
            'owner_id' => $sam->id,
            'sync_status' => SyncStatus::NotSynced,
        ]);

        if (! $archived->trashed()) {
            $archived->delete();
        }
    }
}
