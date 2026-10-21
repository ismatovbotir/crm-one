<?php

namespace App\Services\Tenders;

use App\Enums\TenderMethod;
use App\Enums\TenderStatus;
use App\Enums\TenderType;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\TenderLot;
use App\Models\TenderTransportSpec;
use App\Support\BusinessSettings;
use Carbon\CarbonImmutable;

/**
 * Completeness check (BR-T.6): the transport-tender skill's N1-N24 and the purchase-tender skill's
 * P1-P10, each a blocker or a warning, plus "R" blockers for required fields the codes do not name.
 * Submitting for approval needs 0 blockers and a comment per warning; publishing re-runs it.
 * Structural guarantees pass silently: N11/P10 (communications clause printed on every notice),
 * N22 (claims clause printed), N23/P6 (budget, targets and incumbent are internal-only columns
 * never rendered to bidders).
 *
 * Issues store a translation key + parameters (not text) so the panel follows the UI language.
 */
class CompletenessChecker
{
    public const BLOCKER = 'blocker';

    public const WARNING = 'warning';

    /** CMR liability limit 8.33 SDR/kg (~ USD 11.2/kg); above it cargo insurance is needed (N7). */
    private const CMR_LIMIT_USD_PER_KG = '11.20';

    /** Legal gross weight of one road unit (N14). */
    private const ROAD_MAX_GROSS_KG = '40000';

    /** @var list<array{code: string, level: string, key: string, params: array<string, string|int>, lot: int|null}> */
    private array $issues = [];

    public function __construct(protected TenderFx $fx) {}

    /**
     * @return list<array{code: string, level: string, key: string, params: array<string, string|int>, lot: int|null}>
     */
    public function check(Tender $tender, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $this->issues = [];
        $tender->loadMissing(['lots.items', 'lots.transportSpec', 'criteria', 'committee', 'invitations.counterparty', 'sources']);

        $this->header($tender, $now);

        if ($tender->type === TenderType::Transport) {
            foreach ($tender->lots as $lot) {
                $this->transportLot($tender, $lot);
            }
        } else {
            $this->purchase($tender);
        }

        return $this->issues;
    }

    /**
     * @param  list<array{code: string, level: string, key: string, params: array<string, string|int>, lot: int|null}>  $issues
     */
    public static function blockers(array $issues): int
    {
        return count(array_filter($issues, fn (array $issue): bool => $issue['level'] === self::BLOCKER));
    }

    /**
     * Warnings without an acknowledgement comment.
     *
     * @param  list<array{code: string, level: string, key: string, params: array<string, string|int>, lot: int|null}>  $issues
     * @param  array<string, string>|null  $acknowledged
     * @return list<string> issue keys (code or code:lot)
     */
    public static function unacknowledged(array $issues, ?array $acknowledged): array
    {
        $missing = [];

        foreach ($issues as $issue) {
            if ($issue['level'] !== self::WARNING) {
                continue;
            }

            $key = self::issueKey($issue);

            if (trim((string) ($acknowledged[$key] ?? '')) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * Translated text of an issue in the current locale (field names via tenders.spec.*).
     *
     * @param  array{code: string, level: string, key: string, params: array<string, string|int>, lot: int|null}  $issue
     */
    public static function message(array $issue): string
    {
        $params = $issue['params'];

        if (isset($params['field'])) {
            $params['field'] = __('tenders.spec.'.$params['field']);
        }

        return __('tenders.checks.'.$issue['key'], $params);
    }

    /**
     * @param  array{code: string, lot: int|null}  $issue
     */
    public static function issueKey(array $issue): string
    {
        return $issue['code'].($issue['lot'] !== null ? ':'.$issue['lot'] : '');
    }

    protected function header(Tender $tender, CarbonImmutable $now): void
    {
        $isTransport = $tender->type === TenderType::Transport;
        $dateCode = $isTransport ? 'N12' : 'R';

        if ($tender->owner_id === null) {
            $this->add('R', self::BLOCKER, 'owner');
        }

        if ($tender->lots->isEmpty()) {
            $this->add('R', self::BLOCKER, 'lots');
        }

        if (! is_array($tender->accepted_currencies) || $tender->accepted_currencies === []) {
            $this->add('R', self::BLOCKER, 'currencies');
        }

        if ((int) $tender->bid_validity_days < 1) {
            $this->add('R', self::BLOCKER, 'bid_validity');
        }

        // N17 / P5: payment terms.
        if (blank($tender->payment_terms)) {
            $this->add($isTransport ? 'N17' : 'P5', self::BLOCKER, 'payment_terms');
        }

        if (! $isTransport && (int) $tender->prepayment_bp > BusinessSettings::int('tenders.prepayment_policy_bp')) {
            $this->add('P5', self::WARNING, 'prepayment_policy', ['percent' => bcdiv((string) BusinessSettings::int('tenders.prepayment_policy_bp'), '100', 0)]);
        }

        // Criteria: at least one, weights sum to 100 %.
        $weights = (int) $tender->criteria->sum('weight_bp');

        if ($tender->criteria->isEmpty() || $weights !== 10000) {
            $this->add('R', self::BLOCKER, 'criteria_sum', ['sum' => bcdiv((string) $weights, '100', 2)]);
        }

        // Deadlines (BR-T.2).
        if ($tender->bids_due_at === null || $tender->clarification_deadline_at === null) {
            $this->add($dateCode, self::BLOCKER, 'deadlines_missing');
        } else {
            $minDays = $tender->urgent ? BusinessSettings::int('tenders.urgent_min_bid_working_days') : BusinessSettings::int('tenders.min_bid_working_days');
            $from = in_array($tender->status, [TenderStatus::Draft, TenderStatus::PendingApproval, TenderStatus::Approved], true) ? $now : ($tender->published_at ?? $now);

            if ($tender->bids_due_at->lessThan(TenderClock::addWorkingDays($from, $minDays))) {
                $this->add($dateCode, self::BLOCKER, 'deadline_too_soon', ['days' => $minDays]);
            }

            if ($tender->clarification_deadline_at->greaterThan($tender->bids_due_at->subHours(BusinessSettings::int('tenders.clarification_gap_hours')))) {
                $this->add($dateCode, self::BLOCKER, 'clarification_gap', ['hours' => BusinessSettings::int('tenders.clarification_gap_hours')]);
            }
        }

        if ($tender->urgent && blank($tender->urgent_justification)) {
            $this->add('R', self::BLOCKER, 'urgent_justification');
        }

        $singleSource = $tender->method === TenderMethod::SingleSource;

        if ($singleSource && blank($tender->single_source_justification)) {
            $this->add('R', self::BLOCKER, 'single_source_justification');
        }

        // N18 / P7: at least 3 qualified invitees (blocker above T1 unless single source).
        $budgetUzs = $tender->budget_minor !== null ? $this->fx->toUzs($tender, $tender->budget_minor, (string) ($tender->budget_currency ?? $tender->evaluation_currency)) : null;
        $aboveT1 = $budgetUzs !== null && $budgetUzs > BusinessSettings::int('tenders.t1_minor');
        $invitees = $tender->invitations->filter(fn ($invitation): bool => $invitation->counterparty?->status->canBid() ?? false)->count();
        $minInvitees = BusinessSettings::int('tenders.min_invitees');

        if ($invitees < ($singleSource ? 1 : $minInvitees)) {
            $this->add($isTransport ? 'N18' : 'P7', $aboveT1 && ! $singleSource ? self::BLOCKER : self::WARNING, 'invitees', ['count' => $invitees, 'min' => $minInvitees]);
        }

        // BR-T.9 / 7.3: committee of at least 2 above T1.
        if ($aboveT1 && $tender->committee->count() < 2) {
            $this->add('R', self::BLOCKER, 'committee_size');
        }
    }

    protected function transportLot(Tender $tender, TenderLot $lot): void
    {
        $spec = $lot->transportSpec;
        $n = $lot->number;

        if ($spec === null) {
            $this->add('R', self::BLOCKER, 'transport_spec', [], $n);

            return;
        }

        foreach (['origin_country', 'origin_city', 'origin_address', 'destination_country', 'destination_city', 'destination_address', 'readiness_date', 'pickup_from', 'pickup_to', 'delivery_to', 'mode', 'load_type', 'vehicle_type', 'cargo_description'] as $field) {
            if (blank($spec->{$field})) {
                $this->add('R', self::BLOCKER, 'field_required', ['field' => $field], $n);
            }
        }

        $crossBorder = $spec->isCrossBorder();

        if ($spec->cargo_value_minor === null || blank($spec->cargo_currency)) {
            $this->add('R', self::BLOCKER, 'field_required', ['field' => 'cargo_value'], $n);
        }

        // N1: HS codes, at least 6 digits, for cross-border cargo.
        $hsCodes = array_values(array_filter((array) $spec->hs_codes, fn ($code): bool => filled($code)));

        if ($crossBorder && ($hsCodes === [] || collect($hsCodes)->contains(fn ($code): bool => preg_match('/^\d{6,10}$/', (string) $code) !== 1))) {
            $this->add('N1', self::BLOCKER, 'n1', [], $n);
        }

        // N2: Incoterm + named place; sea terms make no sense for road/rail.
        if (blank($spec->incoterm) || blank($spec->incoterm_place)) {
            $this->add('N2', self::BLOCKER, 'n2', [], $n);
        } elseif (in_array($spec->mode, ['road', 'rail'], true) && in_array($spec->incoterm, ['FAS', 'FOB', 'CFR', 'CIF'], true)) {
            $this->add('N2', self::BLOCKER, 'n2_sea_term', ['term' => (string) $spec->incoterm], $n);
        }

        // N3: who pays duties, VAT, fees, broker, storage, terminal, port, inspection (cross-border).
        if ($crossBorder) {
            $payer = (array) $spec->charges_payer;
            $missing = array_filter(config('tenders.vocab.payer_charges'), fn (string $charge): bool => blank($payer[$charge] ?? null));

            if ($missing !== [] || blank($spec->export_clearance_by) || blank($spec->import_clearance_by)) {
                $this->add('N3', self::BLOCKER, 'n3', [], $n);
            }
        }

        // N4: free time (the downtime rate is a mandatory bid field).
        if ($spec->free_loading_hours === null || $spec->free_unloading_hours === null || ($crossBorder && $spec->free_customs_hours === null)) {
            $this->add('N4', self::WARNING, 'n4', [], $n);
        }

        // N5 / N6: tri-state answers.
        if ($spec->partial_allowed === null) {
            $this->add('N5', self::BLOCKER, 'n5', [], $n);
        }

        if ($spec->transshipment_allowed === null) {
            $this->add('N6', self::BLOCKER, 'n6', [], $n);
        }

        // N7: insurance; cargo above the CMR limit needs cargo insurance.
        if (blank($spec->insurance_by)) {
            $this->add('N7', self::BLOCKER, 'n7', [], $n);
        } elseif ($spec->insurance_by !== 'none' && $spec->insured_value_minor === null) {
            $this->add('N7', self::BLOCKER, 'n7_value', [], $n);
        } elseif ($spec->insurance_by === 'none' && $this->aboveCmrLimit($tender, $spec)) {
            $this->add('N7', self::BLOCKER, 'n7_cmr', [], $n);
        }

        // N8: driver and vehicle documents (cross-border).
        if ($crossBorder && empty($spec->driver_vehicle_documents)) {
            $this->add('N8', self::BLOCKER, 'n8', [], $n);
        }

        // N9: containers.
        if ($spec->isContainer() && (blank($spec->container_return_location) || $spec->container_free_days === null)) {
            $this->add('N9', self::BLOCKER, 'n9', [], $n);
        }

        if (blank($spec->force_majeure)) {
            $this->add('N10', self::WARNING, 'n10', [], $n);
        }

        // N12: realistic dates.
        if ($spec->readiness_date !== null && $spec->pickup_from !== null && $spec->readiness_date->greaterThan($spec->pickup_from)) {
            $this->add('N12', self::BLOCKER, 'n12_readiness', [], $n);
        }

        if ($spec->pickup_from !== null && $spec->pickup_to !== null && $spec->pickup_to->lessThan($spec->pickup_from)) {
            $this->add('N12', self::BLOCKER, 'n12_pickup', [], $n);
        }

        if ($spec->pickup_from !== null && $spec->delivery_to !== null && $spec->delivery_to->lessThan($spec->pickup_from)) {
            $this->add('N12', self::BLOCKER, 'n12_delivery', [], $n);
        }

        // N13: dangerous / temperature / oversize answered and detailed.
        if ($spec->is_dangerous === null || $spec->is_temperature_controlled === null || $spec->is_oversize === null) {
            $this->add('N13', self::BLOCKER, 'n13', [], $n);
        } else {
            if ($spec->is_dangerous && (blank($spec->un_number) || blank($spec->adr_class))) {
                $this->add('N13', self::BLOCKER, 'n13_dg', [], $n);
            }

            if ($spec->is_temperature_controlled && ($spec->temp_min_c === null || $spec->temp_max_c === null)) {
                $this->add('N13', self::BLOCKER, 'n13_temp', [], $n);
            }

            if ($spec->is_oversize && blank($spec->oversize_notes)) {
                $this->add('N13', self::BLOCKER, 'n13_oversize', [], $n);
            }
        }

        // N14: weight, volume, packages consistent.
        if ($spec->gross_kg === null || $spec->volume_m3 === null || $spec->packages_count === null) {
            $this->add('N14', self::BLOCKER, 'n14', [], $n);
        } else {
            if ($spec->net_kg !== null && bccomp((string) $spec->net_kg, (string) $spec->gross_kg, 3) > 0) {
                $this->add('N14', self::BLOCKER, 'n14_net', [], $n);
            }

            $units = max(1, (int) $spec->units_count);

            if ($spec->mode === 'road' && bccomp(bcdiv((string) $spec->gross_kg, (string) $units, 3), self::ROAD_MAX_GROSS_KG, 3) > 0) {
                $this->add('N14', self::BLOCKER, 'n14_overweight', [], $n);
            }
        }

        // N15: border crossing policy and sanctions screening (cross-border).
        if ($crossBorder && (blank($spec->border_policy) || ! $spec->sanctions_reviewed)) {
            $this->add('N15', self::BLOCKER, 'n15', [], $n);
        }

        if ($spec->isImport() && blank($spec->clearance_place)) {
            $this->add('N15', self::BLOCKER, 'n15_clearance', [], $n);
        }

        if (empty($spec->required_documents)) {
            $this->add('N16', self::BLOCKER, 'n16', [], $n);
        }

        if (blank($spec->penalties) && $spec->late_penalty_bp_per_day === null) {
            $this->add('N19', self::WARNING, 'n19', [], $n);
        }

        if ($tender->kind->value === 'lane_contract' && blank($spec->fuel_adjustment)) {
            $this->add('N20', self::BLOCKER, 'n20', [], $n);
        }

        if (blank($spec->loading_method) || blank($spec->loaded_by) || blank($spec->unloading_method) || blank($spec->unloaded_by)) {
            $this->add('N21', self::BLOCKER, 'n21', [], $n);
        }

        if (blank($spec->site_contacts)) {
            $this->add('N24', self::WARNING, 'n24', [], $n);
        }

        // N25-N27 (Spec 08 BR-PO, all warnings) for lots prefilled from a purchase order.
        if ($spec->purchase_order_id !== null) {
            $po = PurchaseOrder::query()->with(['lines', 'order'])->find($spec->purchase_order_id);

            if ($po !== null && $po->lines->contains(fn ($line): bool => $line->weight_source !== 'packing_list')) {
                $this->add('N25', self::WARNING, 'n25', [], $n);
            }

            $promised = $po?->order?->promised_delivery_on;

            if ($promised !== null && $spec->delivery_to !== null && $spec->delivery_to->toDateString() > $promised->toDateString()) {
                $this->add('N26', self::WARNING, 'n26', [], $n);
            }

            if ($tender->budget_minor === null) {
                $this->add('N27', self::WARNING, 'n27', [], $n);
            }
        }
    }

    protected function purchase(Tender $tender): void
    {
        foreach ($tender->lots as $lot) {
            if ($lot->items->isEmpty()) {
                $this->add('R', self::BLOCKER, 'lot_items', [], $lot->number);
            }

            foreach ($lot->items as $item) {
                $this->purchaseItem($item, $lot->number);
            }
        }

        if (blank($tender->incoterm) || blank($tender->incoterm_place)) {
            $this->add('P3', self::BLOCKER, 'p3');
        }

        if ($tender->delivery_warehouse_id === null && blank($tender->delivery_address)) {
            $this->add('R', self::BLOCKER, 'delivery_place');
        }

        if ($tender->is_import && blank($tender->required_certificates)) {
            $this->add('P4', self::BLOCKER, 'p4');
        }

        if (blank($tender->warranty_terms)) {
            $this->add('P8', self::WARNING, 'p8');
        }

        // P9: required dates later than the linked customer order's promised delivery.
        $promised = Order::query()->whereIn('id', $tender->sources->where('source_type', 'order')->pluck('source_id'))->whereNotNull('promised_delivery_on')->min('promised_delivery_on');

        if ($promised !== null) {
            $late = $tender->lots->flatMap->items->contains(fn (TenderItem $item): bool => $item->required_by !== null && $item->required_by->toDateString() > CarbonImmutable::parse($promised)->toDateString());

            if ($late) {
                $this->add('P9', self::WARNING, 'p9');
            }
        }
    }

    protected function purchaseItem(TenderItem $item, int $lot): void
    {
        if ($item->item_id === null && blank($item->specification)) {
            $this->add('P1', self::BLOCKER, 'p1', ['item' => $item->name], $lot);
        } elseif ($item->alternatives_allowed && blank($item->specification)) {
            $this->add('P1', self::BLOCKER, 'p1_equivalent', ['item' => $item->name], $lot);
        }

        $quantity = (string) $item->quantity;
        $fraction = explode('.', $quantity)[1] ?? '';

        if (blank($item->unit_code) || bccomp($quantity, '0', 3) <= 0 || strlen(rtrim($fraction, '0')) > (int) $item->unit_precision) {
            $this->add('P2', self::BLOCKER, 'p2', ['item' => $item->name], $lot);
        }
    }

    /** Cargo value per kg above the CMR liability limit (needs USD rate; unknown = not flagged). */
    protected function aboveCmrLimit(Tender $tender, TenderTransportSpec $spec): bool
    {
        if ($spec->cargo_value_minor === null || blank($spec->cargo_currency) || $spec->gross_kg === null || bccomp((string) $spec->gross_kg, '0', 3) <= 0) {
            return false;
        }

        $valueUzs = $this->fx->toUzs($tender, $spec->cargo_value_minor, (string) $spec->cargo_currency);
        $usdRate = $this->fx->toUzs($tender, 100, 'USD');

        if ($valueUzs === null || $usdRate === null || $usdRate === 0) {
            return false;
        }

        $usdPerKg = bcdiv(bcdiv((string) $valueUzs, (string) $usdRate, 6), (string) $spec->gross_kg, 6);

        return bccomp($usdPerKg, self::CMR_LIMIT_USD_PER_KG, 6) > 0;
    }

    /**
     * @param  array<string, string|int>  $params
     */
    protected function add(string $code, string $level, string $key, array $params = [], ?int $lot = null): void
    {
        $this->issues[] = ['code' => $code, 'level' => $level, 'key' => $key, 'params' => $params, 'lot' => $lot];
    }
}
