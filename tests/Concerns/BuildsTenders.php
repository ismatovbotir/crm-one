<?php

namespace Tests\Concerns;

use App\Actions\Tenders\EvaluateTender;
use App\Actions\Tenders\ManageTender;
use App\Actions\Tenders\ManageTenderParticipants;
use App\Actions\Tenders\SubmitBid;
use App\Actions\Tenders\TenderWorkflow;
use App\Enums\DepartmentRole;
use App\Enums\TenderType;
use App\Models\Counterparty;
use App\Models\Item;
use App\Models\Tender;
use App\Models\TenderBid;
use App\Models\TenderInvitation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Tenders\CompletenessChecker;
use App\Services\Tenders\TenderClock;
use Carbon\CarbonImmutable;

/**
 * Tender fixtures through the real Actions (Spec 03): department users, a complete transport or
 * purchase draft (0 blockers), publication, employee-entered bids, closing at the deadline.
 */
trait BuildsTenders
{
    protected function logistics(DepartmentRole $role = DepartmentRole::Specialist): User
    {
        return User::factory()->staff()->inDepartment('logistics', $role)->create();
    }

    protected function purchaser(DepartmentRole $role = DepartmentRole::Specialist): User
    {
        return User::factory()->staff()->inDepartment('purchase', $role)->create();
    }

    protected function ceo(): User
    {
        return User::factory()->staff()->inDepartment('management', DepartmentRole::Head)->create();
    }

    /**
     * A transport draft that passes every blocker (warnings acknowledged by the caller).
     *
     * @param  array<string, mixed>  $header
     */
    protected function transportTender(User $owner, array $header = [], int $carriers = 3): Tender
    {
        $manage = app(ManageTender::class);
        $tender = $manage->create(TenderType::Transport, ['title' => 'Istanbul → Tashkent, 1 FTL'], $owner);
        $now = CarbonImmutable::now();
        $manage->updateHeader($tender, [
            'payment_terms' => '100% within 15 days after delivery and original CMR',
            'payment_days' => 15,
            'clarification_deadline_at' => TenderClock::addWorkingDays($now, 3),
            'bids_due_at' => TenderClock::addWorkingDays($now, 5),
            ...$header,
        ]);
        $manage->updateTransportSpec($tender->lots()->first(), $this->completeTransportSpec());

        $participants = app(ManageTenderParticipants::class);

        foreach (Counterparty::factory()->carrier()->count($carriers)->create() as $carrier) {
            $participants->invite($tender, $carrier, $owner);
        }

        return $tender->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    protected function completeTransportSpec(): array
    {
        return [
            'origin_country' => 'TR', 'origin_city' => 'Istanbul', 'origin_address' => 'Hadımköy, Arnavutköy',
            'destination_country' => 'UZ', 'destination_city' => 'Tashkent', 'destination_address' => 'Sergeli, warehouse 1',
            'border_policy' => 'carrier_choice', 'clearance_place' => 'Tashkent inland customs post', 'sanctions_reviewed' => true,
            'readiness_date' => $this->day(10), 'pickup_from' => $this->day(10), 'pickup_to' => $this->day(12), 'delivery_to' => $this->day(25),
            'max_transit_days' => 12, 'mode' => 'road', 'load_type' => 'ftl', 'vehicle_type' => 'Tent 82-92 m3', 'units_count' => 1,
            'transshipment_allowed' => false, 'partial_allowed' => false,
            'cargo_description' => 'Network equipment on pallets', 'hs_codes' => ['8517620009'], 'gross_kg' => '8000', 'net_kg' => '7600',
            'volume_m3' => '40', 'packages_count' => 20, 'package_type' => 'pallet', 'stackable' => false,
            'cargo_value_minor' => 5_000_000, 'cargo_currency' => 'USD', 'is_dangerous' => false, 'is_temperature_controlled' => false, 'is_oversize' => false,
            'loading_method' => 'rear', 'loaded_by' => 'shipper', 'unloading_method' => 'rear', 'unloaded_by' => 'consignee',
            'free_loading_hours' => 24, 'free_customs_hours' => 48, 'free_unloading_hours' => 24,
            'incoterm' => 'FCA', 'incoterm_place' => 'Istanbul, Hadımköy', 'export_clearance_by' => 'seller', 'import_clearance_by' => 'our_broker',
            'charges_payer' => array_fill_keys(config('tenders.vocab.payer_charges'), 'buyer'),
            'insurance_by' => 'buyer', 'insured_value_minor' => 5_000_000, 'insurance_cover' => 'ICC A',
            'required_documents' => ['cmr', 'invoice', 'packing_list'], 'driver_vehicle_documents' => ['permits', 'cmr_insurance'],
            'penalties' => '0.5% of freight per day late, cap 10%', 'force_majeure' => 'Border closures: costs shared 50/50',
            'site_contacts' => 'Origin: Mehmet +90 555 000 00 00',
        ];
    }

    /**
     * A purchase draft with one catalog line (0 blockers).
     *
     * @param  array<string, mixed>  $header
     */
    protected function purchaseTender(User $owner, array $header = [], int $suppliers = 3): Tender
    {
        $manage = app(ManageTender::class);
        $tender = $manage->create(TenderType::Purchase, ['title' => 'Routers for Q4'], $owner);
        $now = CarbonImmutable::now();
        $manage->updateHeader($tender, [
            'payment_terms' => '30 days after delivery',
            'payment_days' => 30,
            'incoterm' => 'DAP',
            'incoterm_place' => 'Tashkent, our warehouse',
            'delivery_warehouse_id' => Warehouse::factory()->create()->id,
            'warranty_terms' => '24 months',
            'clarification_deadline_at' => TenderClock::addWorkingDays($now, 3),
            'bids_due_at' => TenderClock::addWorkingDays($now, 5),
            ...$header,
        ]);
        $manage->saveItem($tender->lots()->first(), null, ['item_id' => Item::factory()->create(['name' => 'Router AX'])->id, 'quantity' => '10', 'specification' => 'Wi-Fi 6, 4 ports']);

        $participants = app(ManageTenderParticipants::class);

        foreach (Counterparty::factory()->supplier()->count($suppliers)->create() as $supplier) {
            $participants->invite($tender, $supplier, $owner);
        }

        return $tender->fresh();
    }

    /**
     * Submit (acknowledging every warning), approve and publish.
     */
    protected function publish(Tender $tender, User $owner, User $approver): Tender
    {
        $workflow = app(TenderWorkflow::class);
        $issues = $workflow->runCheck($tender);
        $acks = [];

        foreach ($issues as $issue) {
            if ($issue['level'] === 'warning') {
                $acks[CompletenessChecker::issueKey($issue)] = 'Accepted for this shipment';
            }
        }

        $workflow->submitForApproval($tender, $owner, $acks);
        $workflow->decidePublication($tender->fresh(), $approver, true, null);

        return $workflow->publish($tender->fresh(), $owner);
    }

    /**
     * Employee-entered transport bid: freight (+ optional extra charges), transit days, downtime rate.
     *
     * @param  array<string, array{inclusion: string, amount?: string}>  $extraCharges
     * @param  array<string, mixed>  $header
     */
    protected function transportBid(Tender $tender, TenderInvitation $invitation, User $enterer, string $freight, string $currency = 'UZS', int $transitDays = 10, string $downtime = '0', array $extraCharges = [], array $header = []): TenderBid
    {
        $lot = $tender->lots()->first();

        return app(SubmitBid::class)->handle($invitation, [
            'currency' => $currency,
            'valid_until' => CarbonImmutable::now()->addDays(90)->toDateString(),
            'payment_days' => 15,
            'conditions_accepted' => true,
            'lots' => [$lot->id => [
                'transit_days' => $transitDays,
                'downtime_rate' => $downtime,
                'lines' => ['freight' => ['inclusion' => 'included', 'amount' => $freight], ...$extraCharges],
            ]],
            'source' => [
                'received_via' => 'phone',
                'received_at' => CarbonImmutable::now()->subMinute(),
                'sender_name' => 'Dispatcher',
                'note' => 'Quoted by phone, confirmed rate and transit time.',
            ],
            ...$header,
        ], $enterer);
    }

    /**
     * Travel past the deadline, close (opens the bids) and let finance freeze the CBU rates.
     *
     * @param  array<string, string>|null  $rates  currency => UZS per unit; null = do not freeze
     */
    protected function closeAfterDeadline(Tender $tender, ?array $rates = ['USD' => '12650', 'EUR' => '13700']): Tender
    {
        $this->travelTo($tender->fresh()->bids_due_at->addMinute());
        $tender = app(TenderWorkflow::class)->close($tender->fresh());

        if ($rates !== null) {
            app(EvaluateTender::class)->setFx($tender, $rates, now()->toDateString(), User::factory()->finance()->create());
        }

        return $tender->fresh();
    }

    protected function day(int $offset): string
    {
        return CarbonImmutable::now((string) config('tenders.timezone'))->addDays($offset)->toDateString();
    }
}
