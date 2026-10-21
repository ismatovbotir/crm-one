<?php

namespace App\Actions\Tenders;

use App\Enums\BidChannel;
use App\Enums\BidStatus;
use App\Enums\ChargeCode;
use App\Enums\ChargeInclusion;
use App\Enums\InvitationStatus;
use App\Enums\TenderStatus;
use App\Enums\TenderType;
use App\Models\Tender;
use App\Models\TenderBid;
use App\Models\TenderInvitation;
use App\Models\TenderInvitationLink;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Attachments\StoreAttachment;
use App\Services\Tenders\BidLinks;
use App\Services\Tenders\Exceptions\TenderActionException;
use App\Services\Tenders\LandedCostCalculator;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bid entered by an employee from a quote received off-platform (BR-T.14, Spec 08 8.1 channel A):
 * evidence is mandatory (file, or a note of at least 20 characters for phone/meeting), received
 * at ≤ now and ≤ the deadline. The deadline is checked in a transaction with the tender row
 * locked against server time (BR-T.4); a revision creates version + 1 with a reason and
 * supersedes the previous one. Totals are computed here from the lines (client values ignored),
 * the canonical payload is hashed (sha256 receipt), and only metadata reaches the activity log.
 */
class SubmitBid
{
    public function __construct(protected StoreAttachment $attachments) {}

    /**
     * @param  array{
     *     currency: string, valid_until?: string|null, payment_days?: int|string|null, prepayment_percent?: string|null,
     *     subcontracting?: bool, subcontractor_name?: string|null, notes?: string|null, exclusions?: string|null, conditions_accepted?: bool,
     *     correction_reason?: string|null,
     *     lots: array<int, array{transit_days?: int|string|null, lead_time_days?: int|string|null, route?: string|null, border_crossing?: string|null, vehicle_offered?: string|null, downtime_rate?: string|null, payment_terms_offered?: string|null, exceptions?: string|null, lines?: array<string|int, array<string, mixed>>}>,
     *     source: array{received_via: string, received_at: CarbonImmutable|string|null, sender_name: string, sender_contact?: string|null, note?: string|null}
     * }  $data
     * @param  list<UploadedFile>  $evidence
     */
    public function handle(TenderInvitation $invitation, array $data, User $user, array $evidence = []): TenderBid
    {
        return $this->guarded($invitation, fn (): TenderBid => $this->store($invitation, $data, $user, null, null, $evidence));
    }

    /**
     * Bid submitted by the carrier/supplier through its unique link (Spec 08 8.2, channel B): no
     * evidence block, revisions without a reason, idempotent per `submission_uuid` (AC-15).
     *
     * @param  array<string, mixed>  $data  same shape as handle() without `source`
     * @param  list<UploadedFile>  $files  bid attachments (quote, certificates)
     */
    public function fromLink(TenderInvitationLink $link, array $data, ?string $submissionUuid, array $files = []): TenderBid
    {
        $invitation = $link->invitation;

        return $this->guarded($invitation, fn (): TenderBid => $this->store($invitation, $data, null, $link, $submissionUuid, $files));
    }

    /**
     * @param  callable(): TenderBid  $store
     */
    protected function guarded(TenderInvitation $invitation, callable $store): TenderBid
    {
        try {
            return $store();
        } catch (TenderActionException $exception) {
            if ($exception->key === 'deadline_passed') {
                // Logged after the rollback so the refusal survives (AC-20).
                $invitation->tender->logActivity('late_bid_rejected', ['counterparty' => $invitation->counterparty?->name, 'at' => now()->toIso8601String()]);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $evidence
     */
    protected function store(TenderInvitation $invitation, array $data, ?User $user, ?TenderInvitationLink $link, ?string $submissionUuid, array $evidence): TenderBid
    {
        return DB::transaction(function () use ($invitation, $data, $user, $link, $submissionUuid, $evidence): TenderBid {
            $tender = Tender::query()->lockForUpdate()->findOrFail($invitation->tender_id);
            $now = CarbonImmutable::now();
            $channel = $link !== null ? BidChannel::Link : BidChannel::Employee;

            if ($submissionUuid !== null && ($existing = TenderBid::query()->where('submission_uuid', $submissionUuid)->first()) !== null) {
                if ($existing->invitation_id !== $invitation->id) {
                    abort(404);
                }

                return $existing; // Double submit: one version (AC-15).
            }

            if ($link !== null && ! $link->isActive()) {
                throw new TenderActionException('link_inactive');
            }

            if ($tender->status !== TenderStatus::Published || $tender->bids_due_at === null || ! $now->lessThan($tender->bids_due_at)) {
                throw new TenderActionException('deadline_passed');
            }

            if ($invitation->status === InvitationStatus::Declined) {
                throw new TenderActionException('invitation_declined');
            }

            if (! $invitation->counterparty->status->canBid()) {
                throw new TenderActionException('counterparty_cannot_bid');
            }

            $currency = strtoupper((string) $data['currency']);

            if (! in_array($currency, (array) $tender->accepted_currencies, true)) {
                throw new TenderActionException('currency_not_accepted', ['currency' => $currency]);
            }

            $source = $channel === BidChannel::Employee ? $this->source($tender, $data['source'], $evidence, $now) : null;
            $previous = TenderBid::query()->where('tender_id', $tender->id)->where('counterparty_id', $invitation->counterparty_id)
                ->where('round_no', $tender->round)->where('status', BidStatus::Submitted)->lockForUpdate()->first();

            if ($previous !== null && $channel === BidChannel::Employee && blank($data['correction_reason'] ?? null)) {
                throw new TenderActionException('correction_reason_required');
            }

            $lots = $this->lots($tender, $data['lots'] ?? [], $currency);
            $version = (int) TenderBid::query()->where('tender_id', $tender->id)->where('counterparty_id', $invitation->counterparty_id)->where('round_no', $tender->round)->max('version') + 1;
            $prepaymentBp = $this->basisPoints($data['prepayment_percent'] ?? null);

            $header = [
                'currency' => $currency,
                'valid_until' => filled($data['valid_until'] ?? null) ? CarbonImmutable::parse((string) $data['valid_until'])->toDateString() : null,
                'payment_days' => filled($data['payment_days'] ?? null) ? max(0, (int) $data['payment_days']) : null,
                'prepayment_bp' => $prepaymentBp,
                'subcontracting' => (bool) ($data['subcontracting'] ?? false),
                'subcontractor_name' => $data['subcontractor_name'] ?? null,
                'notes' => $data['notes'] ?? null,
                'exclusions' => $data['exclusions'] ?? null,
                'conditions_accepted' => (bool) ($data['conditions_accepted'] ?? false),
            ];

            $payload = [
                'tender' => $tender->number,
                'tender_version' => $tender->version,
                'counterparty' => $invitation->counterparty_id,
                'round' => $tender->round,
                'version' => $version,
                'submitted_at' => $now->utc()->toIso8601String(),
                'header' => $header,
                'lots' => $lots,
            ];
            $hash = hash('sha256', self::canonicalJson($payload));

            $previous?->forceFill(['status' => BidStatus::Superseded])->save();

            $bid = TenderBid::query()->create([
                ...$header,
                'tender_id' => $tender->id,
                'counterparty_id' => $invitation->counterparty_id,
                'invitation_id' => $invitation->id,
                'round_no' => $tender->round,
                'version' => $version,
                'status' => BidStatus::Submitted,
                'channel' => $channel,
                'link_id' => $link?->id,
                'submission_uuid' => $submissionUuid,
                'entered_by' => $user?->id,
                'submitted_at' => $now,
                'payload_hash' => $hash,
                'correction_reason' => $previous !== null && filled($data['correction_reason'] ?? null) ? trim((string) $data['correction_reason']) : null,
            ]);

            if ($source !== null) {
                $bid->source()->create([...$source, 'entered_by' => $user?->id, 'created_at' => $now]);
            }

            foreach ($lots as $lotId => $lot) {
                $bidLot = $bid->lots()->create([...array_diff_key($lot, ['lines' => true]), 'lot_id' => $lotId]);

                foreach ($lot['lines'] as $line) {
                    $bidLot->lines()->create($line);
                }
            }

            foreach ($evidence as $file) {
                $this->attachments->handle($file, $bid, $channel === BidChannel::Employee ? 'bid_evidence' : 'bid_attachment', $user, (int) config('tenders.evidence_max_kb'), 'evidence');
            }

            $invitation->update(['status' => InvitationStatus::Submitted, 'needs_addendum_confirmation' => false]);
            $tender->logActivity('bid_received', [
                'counterparty' => $invitation->counterparty->name,
                'version' => $version,
                'channel' => $channel->value,
                'hash' => $hash,
            ]);

            if ($link !== null) {
                app(BidLinks::class)->event($link, 'submitted');
            }

            return $bid;
        });
    }

    public function withdraw(TenderBid $bid, ?User $user, string $reason): TenderBid
    {
        return DB::transaction(function () use ($bid, $user, $reason): TenderBid {
            $tender = Tender::query()->lockForUpdate()->findOrFail($bid->tender_id);

            if ($tender->status !== TenderStatus::Published || $tender->bids_due_at === null || ! now()->lessThan($tender->bids_due_at)) {
                throw new TenderActionException('deadline_passed');
            }

            if ($bid->status !== BidStatus::Submitted) {
                throw new TenderActionException('bid_not_current');
            }

            if (trim($reason) === '') {
                throw new TenderActionException('reason_required');
            }

            $bid->forceFill(['status' => BidStatus::Withdrawn, 'withdrawn_at' => now()])->save();
            $bid->invitation->update(['status' => InvitationStatus::Withdrawn]);
            $tender->logActivity('bid_withdrawn', ['counterparty' => $bid->counterparty?->name, 'version' => $bid->version, 'by' => $user?->name, 'reason' => $reason]);

            return $bid;
        });
    }

    /**
     * Canonical JSON for the receipt hash (Spec 08 BR-E.2): keys sorted recursively, strings for
     * decimals, no escaping of unicode or slashes.
     *
     * @param  array<mixed>  $payload
     */
    public static function canonicalJson(array $payload): string
    {
        $sort = function (array $value) use (&$sort): array {
            ksort($value);

            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $sort($item);
                }
            }

            return $value;
        };

        return (string) json_encode($sort($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param  array{received_via: string, received_at: CarbonImmutable|string|null, sender_name: string, sender_contact?: string|null, note?: string|null}  $source
     * @param  list<UploadedFile>  $evidence
     * @return array{received_via: string, received_at: CarbonImmutable, sender_name: string, sender_contact: string|null, note: string|null}
     */
    protected function source(Tender $tender, array $source, array $evidence, CarbonImmutable $now): array
    {
        $via = (string) ($source['received_via'] ?? '');

        if (! in_array($via, config('tenders.vocab.received_via'), true)) {
            throw new TenderActionException('evidence_channel');
        }

        $receivedAt = $source['received_at'] instanceof CarbonImmutable ? $source['received_at'] : (filled($source['received_at']) ? CarbonImmutable::parse((string) $source['received_at']) : null);

        if ($receivedAt === null || $receivedAt->greaterThan($now) || $receivedAt->greaterThan($tender->bids_due_at)) {
            throw new TenderActionException('evidence_time');
        }

        if (blank($source['sender_name'] ?? null)) {
            throw new TenderActionException('evidence_sender');
        }

        $note = trim((string) ($source['note'] ?? ''));
        $noteIsEnough = in_array($via, ['phone', 'meeting'], true) && mb_strlen($note) >= (int) config('tenders.evidence_note_min');

        if ($evidence === [] && ! $noteIsEnough) {
            throw new TenderActionException('evidence_required', ['min' => (int) config('tenders.evidence_note_min')]);
        }

        return [
            'received_via' => $via,
            'received_at' => $receivedAt->utc(),
            'sender_name' => trim((string) $source['sender_name']),
            'sender_contact' => $source['sender_contact'] ?? null,
            'note' => $note === '' ? null : $note,
        ];
    }

    /**
     * Normalised lots and lines with amounts in minor units of the bid currency.
     *
     * @param  array<int, array<string, mixed>>  $input
     * @return array<int, array<string, mixed>>
     */
    protected function lots(Tender $tender, array $input, string $currency): array
    {
        $tender->loadMissing('lots.items');
        $result = [];

        foreach ($tender->lots as $lot) {
            $row = $input[$lot->id] ?? null;

            if (! is_array($row) || ! ($row['offered'] ?? true)) {
                continue;
            }

            $lines = $tender->type === TenderType::Transport ? $this->transportLines($row['lines'] ?? [], $currency) : $this->purchaseLines($lot->items, $row['lines'] ?? [], $currency);

            if ($lines === []) {
                continue;
            }

            $total = $tender->type === TenderType::Transport
                ? array_sum(array_map(fn (array $line): int => $line['inclusion'] === ChargeInclusion::Included->value ? $line['amount_minor'] : 0, $lines))
                : array_sum(array_column($lines, 'amount_minor'));

            $result[$lot->id] = [
                'variant_no' => 1,
                'total_minor' => $total,
                'transit_days' => filled($row['transit_days'] ?? null) ? max(0, (int) $row['transit_days']) : null,
                'lead_time_days' => filled($row['lead_time_days'] ?? null) ? max(0, (int) $row['lead_time_days']) : null,
                'route' => $row['route'] ?? null,
                'border_crossing' => $row['border_crossing'] ?? null,
                'vehicle_offered' => $row['vehicle_offered'] ?? null,
                'downtime_rate_minor_per_day' => filled($row['downtime_rate'] ?? null) ? $this->money((string) $row['downtime_rate'], $currency) : null,
                'payment_terms_offered' => $row['payment_terms_offered'] ?? null,
                'exceptions' => $row['exceptions'] ?? null,
                'lines' => $lines,
            ];
        }

        if ($result === []) {
            throw new TenderActionException('bid_empty');
        }

        return $result;
    }

    /**
     * @param  array<string|int, array<string, mixed>>  $input  charge code => {inclusion, amount, basis, description}
     * @return list<array<string, mixed>>
     */
    protected function transportLines(array $input, string $currency): array
    {
        $lines = [];

        foreach (ChargeCode::cases() as $code) {
            $row = $input[$code->value] ?? null;

            if (! is_array($row) || blank($row['inclusion'] ?? null)) {
                continue;
            }

            $inclusion = ChargeInclusion::tryFrom((string) $row['inclusion']) ?? throw new TenderActionException('inclusion_invalid');
            $amount = 0;

            if ($inclusion === ChargeInclusion::Included) {
                if (blank($row['amount'] ?? null)) {
                    throw new TenderActionException('charge_amount_required', ['charge' => $code->label()]);
                }

                $amount = $this->money((string) $row['amount'], $currency);
            }

            if ($code === ChargeCode::Other && $inclusion === ChargeInclusion::Included && blank($row['description'] ?? null)) {
                throw new TenderActionException('other_description_required');
            }

            $lines[] = [
                'charge_code' => $code->value,
                'inclusion' => $inclusion->value,
                'description' => $row['description'] ?? null,
                'basis' => $row['basis'] ?? null,
                'quantity' => '1.000',
                'unit_price_minor' => $amount,
                'amount_minor' => $amount,
            ];
        }

        return $lines;
    }

    /**
     * @param  iterable<TenderItem>  $items
     * @param  array<string|int, array<string, mixed>>  $input  tender item id => {unit_price, quantity, offered_brand, offered_model, compliant, deviation}
     * @return list<array<string, mixed>>
     */
    protected function purchaseLines(iterable $items, array $input, string $currency): array
    {
        $lines = [];

        foreach ($items as $item) {
            $row = $input[$item->id] ?? null;

            if (! is_array($row) || blank($row['unit_price'] ?? null)) {
                continue;
            }

            $quantity = filled($row['quantity'] ?? null) ? Quantity::normalize((string) $row['quantity']) : (string) $item->quantity;

            if (! Quantity::isDecimal($quantity) || bccomp($quantity, '0', 3) <= 0) {
                throw new TenderActionException('quantity_invalid');
            }

            $unit = $this->money((string) $row['unit_price'], $currency);
            $lines[] = [
                'tender_item_id' => $item->id,
                'quantity' => Quantity::toStorage($quantity),
                'unit_price_minor' => $unit,
                'amount_minor' => LandedCostCalculator::roundHalfUp(bcmul((string) $unit, $quantity, 6)),
                'inclusion' => ChargeInclusion::Included->value,
                'offered_brand' => $row['offered_brand'] ?? null,
                'offered_model' => $row['offered_model'] ?? null,
                'compliant' => (bool) ($row['compliant'] ?? true),
                'deviation' => $row['deviation'] ?? null,
            ];
        }

        return $lines;
    }

    protected function money(string $major, string $currency): int
    {
        try {
            $money = Money::fromMajor($major, $currency);
        } catch (InvalidArgumentException) {
            throw new TenderActionException('amount_invalid', ['value' => $major]);
        }

        if ($money->amount < 0) {
            throw new TenderActionException('amount_invalid', ['value' => $major]);
        }

        return $money->amount;
    }

    protected function basisPoints(?string $percent): int
    {
        if (blank($percent)) {
            return 0;
        }

        $value = Quantity::normalize((string) $percent);

        if (! is_numeric($value) || bccomp($value, '0', 2) < 0 || bccomp($value, '100', 2) > 0) {
            throw new TenderActionException('percent_invalid');
        }

        return (int) bcmul($value, '100', 0);
    }
}
