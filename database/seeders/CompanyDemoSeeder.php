<?php

namespace Database\Seeders;

use App\Models\CompanyBankAccount;
use App\Models\CompanySignatory;
use App\Models\Customer;
use App\Support\CompanyProfile;
use Illuminate\Database\Seeder;

/**
 * Demo company profile, bank accounts and signatory (the CEO signs, user decision) plus agreement
 * details for the demo customers, so agreements can be generated and sent in development.
 * Not for production: the real profile is entered in Settings > Company (go-live checklist).
 */
class CompanyDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (CompanySignatory::query()->exists()) {
            return;
        }

        CompanyProfile::save([
            'legal_name.uz' => '“RSG Trade” MChJ',
            'legal_name.ru' => 'ООО «RSG Trade»',
            'legal_name.en' => 'RSG Trade LLC',
            'address.uz' => 'Toshkent sh., Yunusobod tumani, Amir Temur ko‘chasi, 108',
            'address.ru' => 'г. Ташкент, Юнусабадский район, ул. Амира Темура, 108',
            'address.en' => '108 Amir Temur street, Yunusabad district, Tashkent',
            'city.uz' => 'Toshkent sh.',
            'city.ru' => 'г. Ташкент',
            'city.en' => 'Tashkent',
            'tax_id' => '305123456',
            'vat_code' => '326010012345',
            'oked' => '46900',
            'phone' => '+998712000000',
            'email' => 'info@rsg.test',
            'director' => 'Bakhtiyor Karimov',
            'chief_accountant' => 'Nodira Yusupova',
        ]);

        CompanySignatory::query()->create([
            'full_name' => 'Bakhtiyor Karimov',
            'position' => 'Director',
            'basis' => 'Charter',
            'is_default' => true,
            'is_active' => true,
        ]);

        CompanyBankAccount::query()->create(['bank_name' => 'JSCB “Kapitalbank”', 'mfo' => '01158', 'account_number' => '20208000900123456001', 'currency' => 'UZS', 'is_default' => true, 'is_active' => true]);
        CompanyBankAccount::query()->create(['bank_name' => 'JSCB “Kapitalbank”', 'mfo' => '01158', 'account_number' => '20208840900123456002', 'currency' => 'USD', 'is_default' => true, 'is_active' => true]);

        foreach (Customer::query()->orderBy('id')->get() as $index => $customer) {
            $customer->forceFill([
                'oked' => '47190',
                'signatory_name' => $customer->primaryContact?->fullName() ?? 'Director',
                'signatory_position' => 'Director',
                'signatory_basis' => 'Charter',
            ])->saveQuietly();

            $customer->bankAccounts()->create([
                'bank_name' => 'JSCB “Hamkorbank”',
                'mfo' => '00083',
                'account_number' => sprintf('2020800090%010d', 1000 + $index),
                'currency' => $customer->currency,
                'is_default' => true,
            ]);
        }
    }
}
