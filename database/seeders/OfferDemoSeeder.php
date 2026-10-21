<?php

namespace Database\Seeders;

use App\Actions\Offers\AcceptOffer;
use App\Actions\Offers\AddOfferLine;
use App\Actions\Offers\CloseOffer;
use App\Actions\Offers\CreateOffer;
use App\Actions\Offers\ManageOptionGroups;
use App\Actions\Offers\RecordCustomerSelection;
use App\Actions\Offers\SendOffer;
use App\Actions\Offers\SubmitOfferForApproval;
use App\Actions\Offers\UpdateOfferHeader;
use App\Actions\Offers\UpdateOfferLine;
use App\Enums\ItemAlternativeRelation;
use App\Enums\OptionGroupKind;
use App\Enums\SelectionChannel;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemAlternative;
use App\Models\Offer;
use App\Models\OfferLine;
use App\Models\OfferOptionGroup;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Demo offers built through the real Actions (Spec 05): one sent with an alternative group and
 * a recorded choice, one draft with an optional group, one waiting for discount approval, one
 * accepted, one cancelled; plus CRM-local catalog analogue links. Runs once (skips when offers
 * exist). Never runs in production.
 */
class OfferDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Skipping demo offers in production.');

            return;
        }

        if (Offer::withTrashed()->exists()) {
            return;
        }

        $sam = User::query()->where('email', 'sales@crm.test')->first();
        $sara = User::query()->where('email', 'sales2@crm.test')->first();
        $maria = User::query()->where('email', 'manager@crm.test')->first();
        $items = Item::query()->get()->keyBy('sku');

        if ($sam === null || $sara === null || $maria === null || $items->isEmpty()) {
            $this->command?->warn('Seed users, catalog and customers first.');

            return;
        }

        DB::transaction(function () use ($sam, $sara, $maria, $items): void {
            $this->analogues($items, $maria);

            $customer = fn (string $taxId): Customer => Customer::query()->where('tax_id', $taxId)->firstOrFail();

            // 1. Sent, alternative group, customer chose by phone (Alpha Trade, wholesale list, net prices).
            $offer = $this->offer($sam, $customer('301234567'), 'Supply and installation of office network equipment.');
            $this->line($offer, $sam, $items['00-00002002'], '2');
            $routers = app(ManageOptionGroups::class)->add($offer, OptionGroupKind::Alternative, 'Core router for the head office', $sam);
            $a = $this->line($offer, $sam, $items['00-00001001'], '1', $routers, ['alternative_reason' => 'cheaper_analogue', 'comparison_notes' => '1 Gbit/s, no LTE backup', 'lead_time_days' => 3]);
            $b = $this->line($offer, $sam, $items['00-00001002'], '1', $routers, ['is_recommended' => true, 'comparison_notes' => 'LTE backup channel, 24 months warranty', 'lead_time_days' => 7, 'internal_note' => 'Margin is better on RX-500']);
            $this->line($offer, $sam, $items['00-00005001'], '8');
            app(SendOffer::class)->handle($offer->refresh(), $sam, SelectionChannel::Email);
            app(RecordCustomerSelection::class)->handle($offer->refresh(), $sam, [$routers->id => [$b->id]], SelectionChannel::Phone, 'Aziz Karimov confirmed option B by phone');

            // 2. Draft with an optional group (Samarqand Savdo, retail list, prices incl. VAT).
            $offer = $this->offer($sam, $customer('302765432'), 'Network for the new warehouse.');
            $this->line($offer, $sam, $items['00-00002001'], '4');
            $this->line($offer, $sam, $items['00-00004001'], '300');
            $addons = app(ManageOptionGroups::class)->add($offer, OptionGroupKind::Optional, 'Optional services', $sam);
            $this->line($offer, $sam, $items['00-00005002'], '4', $addons, ['comparison_notes' => 'On-site survey before installation']);
            $this->line($offer, $sam, $items['00-00006001'], '12', $addons, ['comparison_notes' => '12 months, business hours']);

            // 3. Waiting for discount approval: 8% on an option exceeds Sara's 5% limit.
            $offer = $this->offer($sara, $customer('303111222'), 'Switching equipment for the mining site.');
            $switches = app(ManageOptionGroups::class)->add($offer, OptionGroupKind::Alternative, 'Distribution switch', $sara);
            $this->line($offer, $sara, $items['00-00002002'], '3', $switches, ['is_recommended' => true, 'alternative_reason' => 'customer_request']);
            $this->line($offer, $sara, $items['00-00002003'], '3', $switches, ['alternative_reason' => 'upgrade', 'discount_bp' => 800, 'comparison_notes' => 'PoE on all 48 ports']);
            $this->line($offer, $sara, $items['00-00003001'], '500');
            app(SubmitOfferForApproval::class)->handle($offer->refresh(), $sara);

            // 4. Accepted: alternative chosen and acceptance recorded from a signed paper copy.
            $offer = $this->offer($maria, $customer('305777888'), 'Wi-Fi upgrade for the shopping mall.');
            $this->line($offer, $maria, $items['00-00006002'], '12');
            $choice = app(ManageOptionGroups::class)->add($offer, OptionGroupKind::Alternative, 'Access routers', $maria);
            $this->line($offer, $maria, $items['00-00001003'], '10', $choice, ['alternative_reason' => 'cheaper_analogue']);
            $chosen = $this->line($offer, $maria, $items['00-00001001'], '10', $choice, ['is_recommended' => true]);
            app(SendOffer::class)->handle($offer->refresh(), $maria, SelectionChannel::Paper);
            app(RecordCustomerSelection::class)->handle($offer->refresh(), $maria, [$choice->id => [$chosen->id]], SelectionChannel::Paper, null, 'Signed offer scan received on '.now()->toDateString());
            app(AcceptOffer::class)->handle($offer->refresh(), $maria, SelectionChannel::Paper, null, 'Signed offer with option 2B ticked');

            // 5. Cancelled draft.
            $offer = $this->offer($sara, $customer('307444555'), null);
            $this->line($offer, $sara, $items['00-00003003'], '20');
            app(CloseOffer::class)->cancel($offer->refresh(), $sara, 'Customer postponed the project');
        });
    }

    protected function offer(User $owner, Customer $customer, ?string $intro): Offer
    {
        $offer = app(CreateOffer::class)->handle($owner, $customer);

        return app(UpdateOfferHeader::class)->handle($offer, $owner, array_filter([
            'intro_text' => $intro,
            'payment_terms_text' => '50% prepayment, balance within 10 banking days after delivery.',
            'delivery_terms_text' => 'Delivery to the customer\'s warehouse in Tashkent.',
            'warranty_text' => '12 months from delivery.',
        ]));
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    protected function line(Offer $offer, User $user, Item $item, string $quantity, ?OfferOptionGroup $group = null, array $changes = []): OfferLine
    {
        $line = app(AddOfferLine::class)->handle($offer->refresh(), $item, $quantity, $user, $group);

        return $changes === [] ? $line : app(UpdateOfferLine::class)->handle($line, $user, $changes);
    }

    /**
     * @param  Collection<string, Item>  $items
     */
    protected function analogues($items, User $by): void
    {
        foreach ([
            ['00-00001001', '00-00001002', ItemAlternativeRelation::Upgrade],
            ['00-00002002', '00-00002003', ItemAlternativeRelation::Upgrade],
            ['00-00003001', '00-00003002', ItemAlternativeRelation::Analogue],
        ] as [$from, $to, $relation]) {
            if (! isset($items[$from], $items[$to])) {
                continue;
            }

            foreach ([[$items[$from], $items[$to], $relation], [$items[$to], $items[$from], $relation->inverse()]] as [$item, $alternative, $rel]) {
                ItemAlternative::query()->firstOrCreate(
                    ['item_id' => $item->id, 'alternative_item_id' => $alternative->id],
                    ['relation' => $rel, 'is_mutual' => true, 'source' => 'crm', 'status' => 'approved', 'created_by' => $by->id, 'approved_by' => $by->id],
                );
            }
        }
    }
}
