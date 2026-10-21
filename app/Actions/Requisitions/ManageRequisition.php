<?php

namespace App\Actions\Requisitions;

use App\Actions\Offers\AddOfferLine;
use App\Actions\Offers\CreateOffer;
use App\Authorization\PermissionMap;
use App\Enums\DataScope;
use App\Enums\RequisitionStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Item;
use App\Models\NumberSequence;
use App\Models\Offer;
use App\Models\Partner;
use App\Models\Requisition;
use App\Models\RequisitionComment;
use App\Models\RequisitionLine;
use App\Models\StatusTransition;
use App\Models\User;
use App\Notifications\RequisitionUpdated;
use App\Services\Requisitions\Exceptions\RequisitionException;
use App\Services\Requisitions\PortalCatalog;
use App\Support\BusinessSettings;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Customer requisitions (Spec 10): drafts and lines (catalog item or category-level need), the
 * client-side review by the purchase manager (return, approve, reject, merge, split, send to us),
 * and our side (clarifications, map category lines to items, decline, create the offer). Status
 * changes are logged and notified. No prices are read or written here.
 */
class ManageRequisition
{
    public const MAX_LINES = 100;

    public function __construct(protected PortalCatalog $catalog) {}

    /**
     * @param  array{title?: string|null, delivery_location?: string|null, needed_by?: string|null, notes?: string|null}  $data
     */
    public function create(Partner $organization, User $requester, array $data = []): Requisition
    {
        return DB::transaction(function () use ($organization, $requester, $data): Requisition {
            $year = (int) CarbonImmutable::now((string) config('offers.timezone', 'Asia/Tashkent'))->format('Y');
            $requisition = Requisition::query()->create([
                'partner_id' => $organization->id,
                'number' => NumberSequence::format('RQ', $year, NumberSequence::next('RQ', $year)),
                'status' => RequisitionStatus::Draft,
                'requester_id' => $requester->id,
                ...$this->header($data),
            ]);
            $this->log($requisition, null, RequisitionStatus::Draft, $requester, null);

            return $requisition;
        });
    }

    /**
     * @param  array{title?: string|null, delivery_location?: string|null, needed_by?: string|null, notes?: string|null}  $data
     */
    public function updateHeader(Requisition $requisition, array $data, User $user): void
    {
        $this->assertClientEditable($requisition);
        $requisition->fill($this->header($data))->save();
    }

    public function addItemLine(Requisition $requisition, Item $item, string $quantity, ?string $neededBy, User $user): RequisitionLine
    {
        $this->assertClientEditable($requisition);

        if (! $this->catalog->isVisible($requisition->customer, $item->id)) {
            throw new RequisitionException('item_not_available');
        }

        $existing = $requisition->lines()->where('kind', RequisitionLine::KIND_ITEM)->where('item_id', $item->id)->first();

        if ($existing !== null) {
            $existing->forceFill(['quantity' => bcadd((string) $existing->quantity, $this->quantity($quantity), Quantity::SCALE)])->save();

            return $existing;
        }

        return $this->newLine($requisition, ['kind' => RequisitionLine::KIND_ITEM, 'item_id' => $item->id, 'quantity' => $this->quantity($quantity), 'needed_by' => $neededBy, 'unit' => $item->unit?->code]);
    }

    public function addCategoryLine(Requisition $requisition, Category $category, string $description, string $quantity, ?string $unit, ?string $neededBy, User $user): RequisitionLine
    {
        $this->assertClientEditable($requisition);

        if (! in_array($category->id, $this->catalog->visibleCategoryIds($requisition->customer), true)) {
            throw new RequisitionException('category_not_available');
        }

        return $this->newLine($requisition, [
            'kind' => RequisitionLine::KIND_CATEGORY,
            'category_id' => $category->id,
            'description' => mb_substr(trim($description), 0, 1000),
            'quantity' => $this->quantity($quantity),
            'unit' => filled($unit) ? mb_substr(trim((string) $unit), 0, 30) : null,
            'needed_by' => $neededBy,
        ]);
    }

    public function updateLine(RequisitionLine $line, string $quantity, ?string $neededBy, User $user): void
    {
        $this->assertClientEditable(Requisition::query()->findOrFail($line->requisition_id));
        $line->forceFill(['quantity' => $this->quantity($quantity), 'needed_by' => $neededBy ?: null])->save();
    }

    public function removeLine(RequisitionLine $line, User $user): void
    {
        $this->assertClientEditable(Requisition::query()->findOrFail($line->requisition_id));
        $line->delete();
    }

    public function submit(Requisition $requisition, User $user): void
    {
        if ($requisition->lines()->count() === 0) {
            throw new RequisitionException('no_lines');
        }

        $this->transition($requisition, RequisitionStatus::Submitted, $user, ['submitted_at' => now(), 'return_comment' => null]);
        $this->notifyPurchaseManagers($requisition, 'requisition_submitted');
    }

    public function returnToRequester(Requisition $requisition, User $manager, string $comment): void
    {
        if (blank($comment)) {
            throw new RequisitionException('comment_required');
        }

        $this->transition($requisition, RequisitionStatus::Returned, $manager, ['return_comment' => mb_substr($comment, 0, 2000), 'purchase_manager_id' => $manager->id], $comment);
        $this->comment($requisition, $manager, $comment, 'client');
        $this->notifyRequester($requisition, 'requisition_returned');
    }

    public function approve(Requisition $requisition, User $manager): void
    {
        $this->transition($requisition, RequisitionStatus::Approved, $manager, ['approved_at' => now(), 'purchase_manager_id' => $manager->id]);
        $this->notifyRequester($requisition, 'requisition_approved');
    }

    public function reject(Requisition $requisition, User $manager, string $reason): void
    {
        if (blank($reason)) {
            throw new RequisitionException('reason_required');
        }

        $this->transition($requisition, RequisitionStatus::Rejected, $manager, ['reject_reason' => mb_substr($reason, 0, 2000), 'purchase_manager_id' => $manager->id, 'decided_at' => now()], $reason);
        $this->notifyRequester($requisition, 'requisition_rejected');
    }

    public function cancel(Requisition $requisition, User $user): void
    {
        $this->transition($requisition, RequisitionStatus::Cancelled, $user, ['decided_at' => now()]);
    }

    /**
     * "Send to supplier": the request becomes visible to us, assigned to the customer's owner.
     *
     * @param  bool  $isPortalAdmin  portal admins are not bound by the quantity limit (D10-6)
     */
    public function sendToSupplier(Requisition $requisition, User $manager, bool $isPortalAdmin): void
    {
        if ($requisition->lines()->count() === 0) {
            throw new RequisitionException('no_lines');
        }

        $limit = BusinessSettings::int('requisitions.pm_max_line_quantity');

        if ($limit > 0 && ! $isPortalAdmin && $requisition->lines()->get()->contains(fn (RequisitionLine $line): bool => bccomp((string) $line->quantity, (string) $limit, Quantity::SCALE) > 0)) {
            throw new RequisitionException('over_quantity_limit', ['limit' => (string) $limit]);
        }

        if ($requisition->status === RequisitionStatus::Submitted || $requisition->status === RequisitionStatus::Draft) {
            $this->transition($requisition, RequisitionStatus::Approved, $manager, ['approved_at' => now(), 'purchase_manager_id' => $manager->id, 'submitted_at' => $requisition->submitted_at ?? now()]);
        }

        $owner = $requisition->customer?->owner;
        $this->transition($requisition->fresh(), RequisitionStatus::Received, $manager, ['sent_at' => now(), 'purchase_manager_id' => $manager->id, 'owner_id' => $owner !== null && $owner->is_active ? $owner->id : null]);
        $requisition->customer?->logActivity('requisition_received', ['number' => $requisition->number]);

        $fresh = $requisition->fresh();
        $recipients = $fresh->owner !== null ? collect([$fresh->owner]) : User::query()->internal()->active()->get()->filter(fn (User $user): bool => PermissionMap::scopeFor($user, 'requisitions.manage') === DataScope::All);

        foreach ($recipients as $recipient) {
            $recipient->notify(new RequisitionUpdated($fresh, 'requisition_received', false));
        }
    }

    /**
     * Consolidates several requests of one company into a new approved request (D10 §5).
     *
     * @param  Collection<int, Requisition>  $requisitions
     */
    public function merge(Collection $requisitions, User $manager): Requisition
    {
        if ($requisitions->count() < 2 || $requisitions->pluck('partner_id')->unique()->count() !== 1
            || $requisitions->contains(fn (Requisition $r): bool => ! in_array($r->status, [RequisitionStatus::Submitted, RequisitionStatus::Approved], true))) {
            throw new RequisitionException('merge_invalid');
        }

        return DB::transaction(function () use ($requisitions, $manager): Requisition {
            $first = $requisitions->first();
            $merged = $this->create($first->customer, $manager, [
                'title' => __('requisitions.merged_title', ['numbers' => $requisitions->pluck('number')->join(', ')]),
                'delivery_location' => $first->delivery_location,
                'needed_by' => $requisitions->pluck('needed_by')->filter()->min()?->toDateString(),
            ]);
            $position = 0;
            $byItem = [];

            foreach ($requisitions as $requisition) {
                foreach ($requisition->lines()->get() as $line) {
                    if ($line->kind === RequisitionLine::KIND_ITEM && isset($byItem[$line->item_id])) {
                        $target = $byItem[$line->item_id];
                        $target->forceFill(['quantity' => bcadd((string) $target->quantity, (string) $line->quantity, Quantity::SCALE), 'needed_by' => collect([$target->needed_by, $line->needed_by])->filter()->min()])->save();

                        continue;
                    }

                    $copy = RequisitionLine::query()->create([
                        ...$line->only(['kind', 'item_id', 'category_id', 'description', 'quantity', 'unit', 'needed_by']),
                        'requisition_id' => $merged->id,
                        'source_line_id' => $line->id,
                        'position' => ++$position,
                    ]);

                    if ($line->kind === RequisitionLine::KIND_ITEM) {
                        $byItem[$line->item_id] = $copy;
                    }
                }

                $this->transition($requisition, RequisitionStatus::Merged, $manager, ['decided_at' => now()], $merged->number);
                $requisition->forceFill(['merged_into_id' => $merged->id])->save();
            }

            $this->transition($merged, RequisitionStatus::Approved, $manager, ['approved_at' => now(), 'submitted_at' => now(), 'purchase_manager_id' => $manager->id]);

            return $merged->fresh();
        });
    }

    /**
     * Moves the chosen lines into a new request with the same status.
     *
     * @param  list<int>  $lineIds
     */
    public function split(Requisition $requisition, array $lineIds, User $manager): Requisition
    {
        $lines = $requisition->lines()->whereIn('id', $lineIds)->get();

        if (! in_array($requisition->status, [RequisitionStatus::Submitted, RequisitionStatus::Approved], true) || $lines->isEmpty() || $lines->count() === $requisition->lines()->count()) {
            throw new RequisitionException('split_invalid');
        }

        return DB::transaction(function () use ($requisition, $lines, $manager): Requisition {
            $new = $this->create($requisition->customer, $requisition->requester, $requisition->only(['title', 'delivery_location', 'notes']) + ['needed_by' => $requisition->needed_by?->toDateString()]);
            $new->forceFill(['split_from_id' => $requisition->id, 'status' => $requisition->status, 'submitted_at' => $requisition->submitted_at, 'approved_at' => $requisition->approved_at, 'purchase_manager_id' => $manager->id])->save();
            RequisitionLine::query()->whereKey($lines->modelKeys())->update(['requisition_id' => $new->id]);
            $this->log($new, RequisitionStatus::Draft, $requisition->status, $manager, 'split_from:'.$requisition->number);
            $requisition->logActivity('requisition_split', ['into' => $new->number, 'lines' => $lines->count()]);

            return $new;
        });
    }

    public function comment(Requisition $requisition, User $user, string $body, string $audience): RequisitionComment
    {
        $comment = $requisition->comments()->create(['user_id' => $user->id, 'body' => mb_substr(trim($body), 0, 5000), 'audience' => $audience === 'client' ? 'client' : 'shared', 'created_at' => now()]);

        if ($comment->audience === 'shared') {
            if ($user->isInternal()) {
                $this->notifyClient($requisition, 'requisition_clarification');
            } elseif ($requisition->owner !== null) {
                $requisition->owner->notify(new RequisitionUpdated($requisition, 'requisition_customer_comment', false));
            }
        }

        return $comment;
    }

    /** Our sales maps a category-level line to a catalog item (any active item; visibility does not apply to us). */
    public function mapLine(RequisitionLine $line, Item $item, User $user): void
    {
        $requisition = Requisition::query()->findOrFail($line->requisition_id);

        if (! $line->isCategoryLine() || ! in_array($requisition->status, [RequisitionStatus::Received, RequisitionStatus::Quoting], true)) {
            throw new RequisitionException('map_invalid');
        }

        $line->forceFill(['mapped_item_id' => $item->id])->save();
        $requisition->logActivity('line_mapped', ['line' => $line->id, 'item' => $item->sku]);
    }

    public function decline(Requisition $requisition, User $user, string $reason): void
    {
        if (blank($reason)) {
            throw new RequisitionException('reason_required');
        }

        $this->transition($requisition, RequisitionStatus::Declined, $user, ['decline_reason' => mb_substr($reason, 0, 2000), 'decided_at' => now()], $reason);
        $this->notifyClient($requisition, 'requisition_declined');
    }

    /**
     * "Create offer from request" (Spec 10 §6): a draft offer with every item line and every mapped
     * category line, prices from the item sale price (the offer rules apply from here on).
     */
    public function createOffer(Requisition $requisition, User $user): Offer
    {
        if (! in_array($requisition->status, [RequisitionStatus::Received, RequisitionStatus::Quoting], true)) {
            throw new RequisitionException('offer_invalid');
        }

        $lines = $requisition->lines()->with(['item', 'mappedItem'])->get();

        if ($lines->contains(fn (RequisitionLine $line): bool => $line->isCategoryLine() && $line->mapped_item_id === null)) {
            throw new RequisitionException('unmapped_lines');
        }

        return DB::transaction(function () use ($requisition, $user, $lines): Offer {
            $offer = app(CreateOffer::class)->handle($user, $requisition->customer, null, ['customer_notes' => $requisition->notes]);
            $offer->forceFill(['requisition_id' => $requisition->id])->save();

            foreach ($lines as $line) {
                $item = $line->offerItem();

                if ($item !== null) {
                    app(AddOfferLine::class)->handle($offer->refresh(), $item, (string) $line->quantity, $user);
                }
            }

            if ($requisition->status === RequisitionStatus::Received) {
                $this->transition($requisition, RequisitionStatus::Quoting, $user, [], $offer->displayNumber());
            }

            $requisition->logActivity('offer_created', ['offer' => $offer->displayNumber()]);

            return $offer->fresh();
        });
    }

    public function markQuoting(Requisition $requisition, User $user): void
    {
        $this->transition($requisition, RequisitionStatus::Quoting, $user);
    }

    /**
     * Offer status changes drive the request (Spec 10 §4): sent → offered, accepted → ordered,
     * rejected / expired → quoting again.
     */
    public function offerChanged(Offer $offer, string $offerStatus, ?User $user): void
    {
        $requisition = $offer->requisition_id !== null ? Requisition::query()->find($offer->requisition_id) : null;

        if ($requisition === null) {
            return;
        }

        $target = match ($offerStatus) {
            'sent' => RequisitionStatus::Offered,
            'accepted' => RequisitionStatus::Ordered,
            'rejected', 'expired' => RequisitionStatus::Quoting,
            default => null,
        };

        if ($target === RequisitionStatus::Offered && $requisition->status === RequisitionStatus::Received) {
            $this->transition($requisition, RequisitionStatus::Quoting, $user);
            $requisition->refresh();
        }

        if ($target === null || ! $requisition->status->canTransitionTo($target)) {
            return;
        }

        $this->transition($requisition, $target, $user, $target === RequisitionStatus::Ordered ? ['decided_at' => now()] : [], $offer->displayNumber());

        if ($target === RequisitionStatus::Offered) {
            $this->notifyClient($requisition->fresh(), 'requisition_offered');
        }
    }

    protected function transition(Requisition $requisition, RequisitionStatus $to, ?User $user, array $attributes = [], ?string $reason = null): void
    {
        $from = $requisition->status;

        if (! $from->canTransitionTo($to)) {
            throw new RequisitionException('invalid_transition', ['from' => $from->label(), 'to' => $to->label()]);
        }

        $requisition->forceFill([...$attributes, 'status' => $to])->save();
        $this->log($requisition, $from, $to, $user, $reason);
    }

    protected function log(Requisition $requisition, ?RequisitionStatus $from, RequisitionStatus $to, ?User $user, ?string $reason): void
    {
        StatusTransition::query()->create([
            'subject_type' => $requisition->getMorphClass(),
            'subject_id' => $requisition->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'user_id' => $user?->id,
            'reason' => filled($reason) ? mb_substr((string) $reason, 0, 1000) : null,
            'created_at' => now(),
        ]);
    }

    protected function assertClientEditable(Requisition $requisition): void
    {
        if (! $requisition->status->isClientEditable()) {
            throw new RequisitionException('not_editable');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function newLine(Requisition $requisition, array $attributes): RequisitionLine
    {
        if ($requisition->lines()->count() >= self::MAX_LINES) {
            throw new RequisitionException('too_many_lines', ['max' => (string) self::MAX_LINES]);
        }

        return $requisition->lines()->create([...$attributes, 'position' => (int) $requisition->lines()->max('position') + 1]);
    }

    protected function quantity(string $quantity): string
    {
        $value = Quantity::normalize($quantity);

        if (! Quantity::isDecimal($value) || bccomp($value, '0', Quantity::SCALE) <= 0 || bccomp($value, '1000000', Quantity::SCALE) > 0) {
            throw new RequisitionException('quantity_invalid');
        }

        return Quantity::toStorage($value);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function header(array $data): array
    {
        return [
            'title' => filled($data['title'] ?? null) ? mb_substr(trim((string) $data['title']), 0, 255) : null,
            'delivery_location' => filled($data['delivery_location'] ?? null) ? mb_substr(trim((string) $data['delivery_location']), 0, 500) : null,
            'needed_by' => filled($data['needed_by'] ?? null) ? $data['needed_by'] : null,
            'notes' => filled($data['notes'] ?? null) ? mb_substr(trim((string) $data['notes']), 0, 5000) : null,
        ];
    }

    protected function notifyPurchaseManagers(Requisition $requisition, string $key): void
    {
        foreach ($this->portalUsers($requisition, ['purchase_manager', 'portal_admin']) as $user) {
            if ($user->id !== $requisition->requester_id) {
                $user->notify(new RequisitionUpdated($requisition, $key, true));
            }
        }
    }

    protected function notifyRequester(Requisition $requisition, string $key): void
    {
        $requisition->requester?->notify(new RequisitionUpdated($requisition, $key, true));
    }

    protected function notifyClient(Requisition $requisition, string $key): void
    {
        $recipients = collect([$requisition->requester, $requisition->purchaseManager])->filter()->unique('id');

        foreach ($recipients as $user) {
            $user->notify(new RequisitionUpdated($requisition, $key, true));
        }
    }

    /**
     * @param  list<string>  $roles
     * @return Collection<int, User>
     */
    protected function portalUsers(Requisition $requisition, array $roles): Collection
    {
        return User::query()->where('is_active', true)
            ->whereHas('portalMemberships', fn ($q) => $q->where('customer_id', $requisition->partner_id)->where('is_active', true)
                ->where(function ($r) use ($roles): void {
                    foreach ($roles as $role) {
                        $r->orWhereJsonContains('roles', $role);
                    }
                }))
            ->get();
    }
}
