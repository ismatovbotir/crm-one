<?php

namespace Database\Seeders;

use App\Actions\Departments\BackfillDepartmentMemberships;
use App\Enums\DepartmentRole;
use App\Enums\UserRole;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Dev/local only: one user per role with a well-known password, department memberships
     * and staff examples, plus the demo catalog and customers. Model events stay on so the
     * same guards, normalisation and change log apply as in the app. Never run in production.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Skipping dev user seeding in production.');

            return;
        }

        $users = [
            ['name' => 'Admin', 'email' => 'admin@crm.test', 'role' => UserRole::Admin],
            ['name' => 'Maria Manager', 'email' => 'manager@crm.test', 'role' => UserRole::Manager],
            ['name' => 'Sam Sales', 'email' => 'sales@crm.test', 'role' => UserRole::Sales],
            ['name' => 'Sara Sales', 'email' => 'sales2@crm.test', 'role' => UserRole::Sales],
            ['name' => 'Fiona Finance', 'email' => 'finance@crm.test', 'role' => UserRole::Finance],
            ['name' => 'Anvar Accountant', 'email' => 'accountant@crm.test', 'role' => UserRole::Accountant],
            ['name' => 'Otabek Omborchi', 'email' => 'stock@crm.test', 'role' => UserRole::StockManager],
            ['name' => 'Lola Logistics', 'email' => 'logistics@crm.test', 'role' => UserRole::Staff, 'department' => ['logistics', DepartmentRole::Specialist]],
            ['name' => 'Laziz Logistics Head', 'email' => 'logistics.head@crm.test', 'role' => UserRole::Staff, 'department' => ['logistics', DepartmentRole::Head]],
            ['name' => 'Pavel Purchase', 'email' => 'purchase@crm.test', 'role' => UserRole::Staff, 'department' => ['purchase', DepartmentRole::Head]],
            ['name' => 'Sultan Support', 'email' => 'support@crm.test', 'role' => UserRole::Staff, 'department' => ['support', DepartmentRole::Viewer]],
        ];

        foreach ($users as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'role' => $data['role'],
                    'is_active' => true,
                    'password' => 'dev-password-change-me',
                    'email_verified_at' => now(),
                ],
            );

            if (isset($data['department']) && ! $user->departments()->exists()) {
                [$code, $role] = $data['department'];
                $user->departments()->attach(Department::query()->where('code', $code)->value('id'), ['role' => $role->value, 'is_primary' => true]);
            }
        }

        (new BackfillDepartmentMemberships)->handle();

        $this->call([
            CatalogDemoSeeder::class,
            CustomerDemoSeeder::class,
            CompanyDemoSeeder::class,
            AgreementTemplateSeeder::class,
            OfferDemoSeeder::class,
            LeadDemoSeeder::class,
            IntegrationTokenDemoSeeder::class,
            ProcurementDemoSeeder::class,
        ]);
    }
}
