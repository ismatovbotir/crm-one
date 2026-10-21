<?php

namespace App\Actions\Tenders;

use App\Enums\CriterionMethod;
use App\Enums\TenderKind;
use App\Enums\TenderMethod;
use App\Enums\TenderStatus;
use App\Enums\TenderType;
use App\Models\Department;
use App\Models\Item;
use App\Models\NumberSequence;
use App\Models\Order;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\TenderLot;
use App\Models\TenderTransportSpec;
use App\Models\User;
use App\Services\Tenders\Exceptions\TenderActionException;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tender drafts (Spec 03 T-2, wizard of section 9): create (or raise a transport request from a
 * customer order), take ownership, header, lots, purchase items, transport spec, criteria and
 * requirements. Structure changes only while the tender is a draft; after publishing changes go
 * through addenda (BR-T.3). Numbers PT-/TT-YYYY-NNNNN are assigned at creation, never reused.
 */
class ManageTender
{
    /** Header columns editable in the draft. */
    public const HEADER = [
        'title', 'description', 'method', 'kind', 'notice_locale', 'evaluation_currency', 'accepted_currencies', 'budget_minor',
        'budget_currency', 'incumbent_counterparty_id', 'incumbent_disclosed', 'incumbent_rate_minor', 'payment_terms', 'payment_days',
        'prepayment_bp', 'bid_validity_days', 'confidential', 'link_verification_required', 'urgent', 'urgent_justification', 'single_source_justification',
        'incoterm', 'incoterm_place', 'delivery_warehouse_id', 'delivery_address', 'partial_deliveries_allowed', 'is_import',
        'required_certificates', 'warranty_terms', 'expected_downtime_days', 'lane_period_from', 'lane_period_to', 'shipments_per_month',
        'clarification_deadline_at', 'bids_due_at',
    ];

    /**
     * @param  array{title: string, method?: string, kind?: string}  $data
     */
    public function create(TenderType $type, array $data, User $user, bool $asRequest = false, ?Order $order = null): Tender
    {
        return DB::transaction(function () use ($type, $data, $user, $asRequest, $order): Tender {
            $year = (int) CarbonImmutable::now((string) config('tenders.timezone'))->year;
            $number = NumberSequence::format($type->prefix(), $year, NumberSequence::next('tender_'.$type->value, $year));
            $department = Department::query()->where('code', $type->departmentCode())->value('id');

            $tender = Tender::query()->create([
                'number' => $number,
                'type' => $type,
                'status' => TenderStatus::Draft,
                'method' => TenderMethod::tryFrom((string) ($data['method'] ?? '')) ?? TenderMethod::Rfq,
                'kind' => TenderKind::tryFrom((string) ($data['kind'] ?? '')) ?? TenderKind::Spot,
                'title' => $data['title'],
                'department_id' => $department,
                'owner_id' => $asRequest ? null : $user->id,
                'requested_by' => $user->id,
                'notice_locale' => 'ru',
                'evaluation_currency' => 'UZS',
                'accepted_currencies' => config('tenders.default_currencies'),
                'bid_validity_days' => 30,
            ]);

            TransitionTender::record($tender, null, TenderStatus::Draft, $user, $asRequest ? 'requested' : null);

            foreach (config('tenders.default_criteria.'.$type->value, []) as $code => [$weight, $method]) {
                $tender->criteria()->create(['code' => $code, 'weight_bp' => $weight, 'method' => $method, 'position' => count($tender->criteria()->get())]);
            }

            $lot = $tender->lots()->create(['number' => 1, 'title' => $data['title']]);

            if ($type === TenderType::Transport) {
                $lot->transportSpec()->create($order !== null ? $this->prefillFromOrder($order) : ['destination_country' => 'UZ']);
            }

            if ($order !== null) {
                $tender->sources()->create(['source_type' => 'order', 'source_id' => $order->id]);

                if ($type === TenderType::Purchase) {
                    $this->addOrderLines($lot, $order);
                }
            }

            return $tender;
        });
    }

    /** A logistics/purchase employee takes an unowned request (Spec 03 US-12). */
    public function takeOwnership(Tender $tender, User $user): Tender
    {
        if ($tender->owner_id !== null) {
            throw new TenderActionException('already_owned');
        }

        $tender->update(['owner_id' => $user->id]);
        $tender->logActivity('ownership_taken');

        return $tender;
    }

    /**
     * @param  array<string, mixed>  $data  subset of HEADER
     */
    public function updateHeader(Tender $tender, array $data): Tender
    {
        $this->assertDraft($tender);
        $data = array_intersect_key($data, array_flip(self::HEADER));

        if (isset($data['accepted_currencies'])) {
            $data['accepted_currencies'] = array_values(array_intersect(array_map('strtoupper', (array) $data['accepted_currencies']), config('tenders.currencies')));
        }

        $tender->forceFill($data)->save();

        return $tender;
    }

    public function addLot(Tender $tender, string $title): TenderLot
    {
        $this->assertDraft($tender);

        return DB::transaction(function () use ($tender, $title): TenderLot {
            $lot = $tender->lots()->create(['number' => (int) $tender->lots()->max('number') + 1, 'title' => $title]);

            if ($tender->type === TenderType::Transport) {
                $previous = $tender->lots()->where('id', '!=', $lot->id)->orderByDesc('number')->first()?->transportSpec;
                $lot->transportSpec()->create($previous !== null ? array_intersect_key($previous->getAttributes(), array_flip(TenderTransportSpec::EDITABLE)) : ['destination_country' => 'UZ']);
            }

            return $lot;
        });
    }

    public function updateLot(TenderLot $lot, string $title, ?string $description): void
    {
        $this->assertDraft($lot->tender);
        $lot->update(['title' => $title, 'description' => $description]);
    }

    public function removeLot(TenderLot $lot): void
    {
        $this->assertDraft($lot->tender);

        if ($lot->tender->lots()->count() <= 1) {
            throw new TenderActionException('last_lot');
        }

        $lot->delete();
    }

    /**
     * @param  array{item_id?: int|null, name?: string|null, unit_code?: string|null, unit_precision?: int|null, quantity: string, specification?: string|null, alternatives_allowed?: bool, required_by?: string|null, hs_code?: string|null, target_price_minor?: int|null}  $data
     */
    public function saveItem(TenderLot $lot, ?TenderItem $line, array $data): TenderItem
    {
        $this->assertDraft($lot->tender);

        if ($lot->tender->type !== TenderType::Purchase) {
            throw new TenderActionException('wrong_type');
        }

        if (! Quantity::isDecimal(Quantity::normalize((string) $data['quantity']))) {
            throw new TenderActionException('quantity_invalid');
        }

        $catalog = isset($data['item_id']) ? Item::query()->with('unit')->find($data['item_id']) : null;
        $attributes = [
            'item_id' => $catalog?->id,
            'sku' => $catalog?->sku,
            'name' => $catalog?->name ?? trim((string) ($data['name'] ?? '')),
            'unit_code' => $catalog?->unit?->code ?? ($data['unit_code'] ?? null),
            'unit_precision' => $catalog?->unit?->precision ?? (int) ($data['unit_precision'] ?? 3),
            'quantity' => Quantity::toStorage((string) $data['quantity']),
            'specification' => $data['specification'] ?? null,
            'alternatives_allowed' => (bool) ($data['alternatives_allowed'] ?? false),
            'required_by' => $data['required_by'] ?? null,
            'hs_code' => $data['hs_code'] ?? null,
            'target_price_minor' => $data['target_price_minor'] ?? null,
        ];

        if ($attributes['name'] === '') {
            throw new TenderActionException('item_name_required');
        }

        if ($line === null) {
            return $lot->items()->create([...$attributes, 'position' => (int) $lot->items()->max('position') + 1]);
        }

        $line->update($attributes);

        return $line;
    }

    public function removeItem(TenderItem $line): void
    {
        $this->assertDraft($line->lot->tender);
        $line->delete();
    }

    /**
     * @param  array<string, mixed>  $data  subset of TenderTransportSpec::EDITABLE
     */
    public function updateTransportSpec(TenderLot $lot, array $data): TenderTransportSpec
    {
        $this->assertDraft($lot->tender);
        $spec = $lot->transportSpec ?? $lot->transportSpec()->create([]);
        $spec->forceFill(array_intersect_key($data, array_flip(TenderTransportSpec::EDITABLE)))->save();

        return $spec;
    }

    /**
     * Replace the criteria (weights in basis points; the completeness check requires a sum of 10000).
     *
     * @param  list<array{code: string, label?: string|null, weight_bp: int, method: string}>  $criteria
     */
    public function setCriteria(Tender $tender, array $criteria): void
    {
        $this->assertDraft($tender);

        DB::transaction(function () use ($tender, $criteria): void {
            $tender->criteria()->delete();

            foreach (array_values($criteria) as $position => $criterion) {
                $tender->criteria()->create([
                    'code' => $criterion['code'],
                    'label' => $criterion['label'] ?? null,
                    'weight_bp' => max(0, (int) $criterion['weight_bp']),
                    'method' => CriterionMethod::from($criterion['method']),
                    'position' => $position,
                ]);
            }

            $tender->logActivity('criteria_changed', ['criteria' => array_map(fn (array $c): string => $c['code'].' '.bcdiv((string) $c['weight_bp'], '100', 2).'%', $criteria)]);
        });
    }

    public function addRequirement(Tender $tender, string $text, bool $knockout): void
    {
        $this->assertDraft($tender);
        $tender->requirements()->create(['text' => $text, 'is_knockout' => $knockout]);
    }

    public function removeRequirement(Tender $tender, int $requirementId): void
    {
        $this->assertDraft($tender);
        $tender->requirements()->whereKey($requirementId)->delete();
    }

    public function assertDraft(Tender $tender): void
    {
        if (! $tender->status->isEditable()) {
            throw new TenderActionException('not_draft');
        }
    }

    /**
     * Transport request prefill from a customer order (Spec 03 6.3): destination, delivery promise,
     * cargo description and value.
     *
     * @return array<string, mixed>
     */
    protected function prefillFromOrder(Order $order): array
    {
        $order->loadMissing('lines');

        return [
            'destination_country' => 'UZ',
            'destination_address' => $order->delivery_address,
            'delivery_to' => $order->promised_delivery_on?->toDateString(),
            'cargo_description' => $order->lines->map(fn ($line): string => $line->name.' × '.Quantity::trimmed((string) $line->quantity))->implode('; '),
            'cargo_value_minor' => $order->net_minor,
            'cargo_currency' => $order->currency,
        ];
    }

    protected function addOrderLines(TenderLot $lot, Order $order): void
    {
        foreach ($order->lines as $position => $line) {
            $lot->items()->create([
                'position' => $position + 1,
                'item_id' => $line->item_id,
                'sku' => $line->sku,
                'name' => $line->name,
                'unit_code' => $line->unit_code,
                'unit_precision' => (int) ($line->unit_precision ?? 3),
                'quantity' => (string) $line->quantity,
                'required_by' => $order->promised_delivery_on?->toDateString(),
            ]);
        }
    }
}
