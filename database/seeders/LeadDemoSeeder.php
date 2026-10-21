<?php

namespace Database\Seeders;

use App\Actions\Leads\LeadWorkflow;
use App\Actions\Leads\SaveLead;
use App\Enums\LeadActivityType;
use App\Enums\LeadCloseReason;
use App\Enums\LeadStatus;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Demo leads built through the real Actions (Spec 04): a new enquiry from an exhibition (free-text
 * company, interested items), a contacted lead linked to a customer, a qualified lead ready for
 * "Create offer", a lost lead with a reason and a stale lead. Runs once (skips when leads exist).
 * Never runs in production.
 */
class LeadDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Skipping demo leads in production.');

            return;
        }

        if (Lead::query()->exists()) {
            return;
        }

        $sam = User::query()->where('email', 'sales@crm.test')->first();
        $sara = User::query()->where('email', 'sales2@crm.test')->first();
        $customers = Customer::query()->whereNotNull('tax_id')->with('primaryContact')->orderBy('id')->limit(3)->get();
        $items = Item::query()->sellable()->orderBy('id')->limit(3)->get();

        if ($sam === null || $sara === null || $customers->count() < 2 || $items->isEmpty()) {
            $this->command?->warn('Seed users, catalog and customers first.');

            return;
        }

        $save = app(SaveLead::class);
        $workflow = app(LeadWorkflow::class);

        DB::transaction(function () use ($save, $workflow, $sam, $sara, $customers, $items): void {
            Auth::setUser($sam);

            $save->handle($sam, [
                'source' => 'exhibition', 'campaign' => 'UzBuild 2026', 'company_name' => 'Navoiy Kon Texnika MChJ', 'tax_id' => '307654321',
                'industry' => 'Mining', 'contact_name' => 'Jasur Tursunov', 'contact_position' => 'Procurement lead', 'contact_phone' => '+998 91 555 12 34',
                'need' => 'Servers and network equipment for a new site office.', 'est_value_minor' => 9000000000, 'currency' => 'UZS',
            ], $items->mapWithKeys(fn (Item $item, int $index): array => [$item->id => (string) ($index + 1)])->all());

            $contacted = $save->handle($sam, [
                'source' => 'phone', 'customer_id' => $customers[0]->id, 'contact_id' => $customers[0]->primaryContact?->id,
                'contact_phone' => $customers[0]->primaryContact ? null : '+998 90 111 22 33',
                'need' => 'Annual replacement of office laptops.', 'est_value_minor' => 25000000000, 'currency' => 'UZS', 'budget_confirmed' => true, 'need_confirmed' => true,
            ], [$items[0]->id => '10']);
            $workflow->markContacted($contacted, $sam);
            $workflow->logActivity($contacted, $sam, LeadActivityType::Call, 'Spoke with the IT manager; waiting for the budget approval from the director.');

            if ($customers[1]->primaryContact !== null) {
                $qualified = $save->handle($sam, [
                    'source' => 'referral', 'customer_id' => $customers[1]->id, 'contact_id' => $customers[1]->primaryContact->id,
                    'need' => 'Two storage arrays with installation.', 'est_value_minor' => 40000000000, 'currency' => 'UZS', 'expected_close_date' => now()->addMonth()->toDateString(),
                    'budget_confirmed' => true, 'authority_confirmed' => true, 'need_confirmed' => true, 'timeline_confirmed' => true,
                ], [$items->last()->id => '2']);
                $workflow->qualify($qualified, $sam);
            }

            Auth::setUser($sara);
            $lost = $save->handle($sara, [
                'source' => 'website', 'company_name' => 'Farg‘ona Agro Servis', 'contact_name' => 'Malika Yusupova', 'contact_email' => 'malika@fargona-agro.uz',
                'need' => 'Price request for 5 printers.', 'currency' => 'UZS',
            ], []);
            $workflow->close($lost, $sara, LeadStatus::Lost, LeadCloseReason::Price, null);

            $stale = $save->handle($sara, [
                'source' => 'email', 'company_name' => 'Termiz Logistics', 'contact_email' => 'info@termiz-logistics.uz', 'need' => 'GPS trackers for 40 trucks.', 'currency' => 'UZS',
            ], []);
            $stale->forceFill(['last_activity_at' => now()->subDays(20), 'created_at' => now()->subDays(20)])->saveQuietly();
        });

        Auth::forgetUser();
    }
}
