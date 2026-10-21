<?php

namespace App\Services\Equipment;

use App\Actions\Catalog\ManageItemImages;
use App\Enums\AssetSource;
use App\Enums\AssetStatus;
use App\Models\AssetEvent;
use App\Models\Customer;
use App\Models\CustomerAsset;
use App\Models\Delivery;
use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\Order;
use App\Models\Partner;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Notifications\ServiceClaimRefused;
use App\Services\Equipment\Exceptions\EquipmentException;
use App\Services\Stock\SerialNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Customer equipment register (Spec 09 §4): assets from our sales (automatic, verified), assets
 * registered by the customer or staff (claim rules R-1: a serial owned by another organization is
 * refused with a neutral message and a staff alert), status changes with the transition map and
 * the "in storage" flag. Every change appends to `asset_events`.
 */
class AssetRegister
{
    public function __construct(protected SerialNormalizer $normalizer) {}

    /**
     * Warranty end of a sold serial: the registry date, else sold_at + item warranty months, else none (Spec 09 §6).
     */
    public static function warrantyFor(ItemSerial $serial): ?CarbonImmutable
    {
        if ($serial->warranty_until !== null) {
            return CarbonImmutable::parse($serial->warranty_until);
        }

        $months = (int) ($serial->item?->warranty_months ?? 0);

        return $serial->sold_at !== null && $months > 0 ? CarbonImmutable::parse($serial->sold_at)->startOfDay()->addMonths($months) : null;
    }

    /**
     * A serial was sold on a posted delivery note: the customer's asset appears (or is linked).
     */
    public function fromSoldSerial(ItemSerial $serial, ?Delivery $delivery, ?Order $order, ?User $user): CustomerAsset
    {
        $serial->loadMissing('item');
        $attributes = [
            'partner_id' => $serial->customer_id,
            'item_id' => $serial->item_id,
            'serial_raw' => $serial->serial_raw,
            'serial_normalized' => $serial->serial_normalized,
            'sold_at' => $serial->sold_at,
            'order_id' => $order?->id,
            'delivery_id' => $delivery?->id,
            'warranty_until' => self::warrantyFor($serial)?->toDateString(),
        ];

        $asset = CustomerAsset::query()->where('item_serial_id', $serial->id)->first()
            ?? CustomerAsset::query()->where('partner_id', $serial->customer_id)->whereNull('item_serial_id')->where('serial_normalized', $serial->serial_normalized)
                ->where(fn ($q) => $q->whereNull('item_id')->orWhere('item_id', $serial->item_id))->first();

        if ($asset === null) {
            $asset = new CustomerAsset([...$attributes, 'source' => AssetSource::Sale, 'status' => AssetStatus::InUse]);
            $asset->forceFill(['item_serial_id' => $serial->id, 'verified_at' => now()])->save();
            $this->event($asset, 'sold', null, AssetStatus::InUse, null, null, $user);
        } else {
            $asset->fill($attributes)->forceFill(['item_serial_id' => $serial->id, 'verified_at' => $asset->verified_at ?? now()])->save();
            $this->event($asset, 'sold', null, null, null, null, $user);
        }

        // Unverified claims of the same serial by other organizations become disputed (staff review).
        $others = CustomerAsset::query()->where('partner_id', '!=', $serial->customer_id)->whereNull('item_serial_id')->whereNull('verified_at')
            ->where('serial_normalized', $serial->serial_normalized)->where(fn ($q) => $q->whereNull('item_id')->orWhere('item_id', $serial->item_id))->get();

        foreach ($others as $other) {
            $other->forceFill(['disputed_at' => now()])->save();
            $this->event($other, 'disputed', null, null, null, null, $user);
        }

        return $asset;
    }

    /**
     * Customer or staff registration of a unit (bought elsewhere, or not yet linked).
     *
     * @param  array{serial: string, item_id?: int|null, model_name?: string|null, location?: string|null, purchased_on?: string|null, notes?: string|null}  $data
     */
    public function register(Partner $organization, array $data, User $user, AssetSource $source, ?UploadedFile $photo = null): CustomerAsset
    {
        $normalized = $this->normalizer->normalize((string) $data['serial']);

        if ($normalized['value'] === null) {
            throw new EquipmentException('serial_invalid');
        }

        $serial = $normalized['value'];
        $item = filled($data['item_id'] ?? null) ? Item::query()->find((int) $data['item_id']) : null;

        if ($item === null && blank($data['model_name'] ?? null)) {
            throw new EquipmentException('model_required');
        }

        $compatible = fn ($q) => $item === null ? $q : $q->where(fn ($w) => $w->whereNull('item_id')->orWhere('item_id', $item->id));
        $sold = ItemSerial::query()->where('serial_normalized', $serial)->whereNotNull('customer_id')->whereNotNull('sold_at')
            ->when($item !== null, fn ($q) => $q->where('item_id', $item->id))->get();

        if ($sold->contains(fn (ItemSerial $row): bool => $row->customer_id !== $organization->id)) {
            $this->refuse($organization, $serial, $user, CustomerAsset::query()->whereIn('item_serial_id', $sold->pluck('id'))->first());
        }

        $foreign = CustomerAsset::query()->where('serial_normalized', $serial)->where('partner_id', '!=', $organization->id)->where($compatible)->first();

        if ($foreign !== null) {
            $this->refuse($organization, $serial, $user, $foreign);
        }

        $own = CustomerAsset::query()->where('serial_normalized', $serial)->where('partner_id', $organization->id)->where($compatible)->first();

        if ($own !== null) {
            throw new EquipmentException($own->source === AssetSource::Sale ? 'already_yours' : 'duplicate');
        }

        return DB::transaction(function () use ($organization, $data, $user, $source, $photo, $serial, $normalized, $item): CustomerAsset {
            $asset = new CustomerAsset([
                'partner_id' => $organization->id,
                'item_id' => $item?->id,
                'model_name' => $item === null ? mb_substr(trim((string) $data['model_name']), 0, 255) : null,
                'serial_raw' => $normalized['raw'],
                'serial_normalized' => $serial,
                'source' => $source,
                'status' => AssetStatus::InUse,
                'location' => filled($data['location'] ?? null) ? mb_substr(trim((string) $data['location']), 0, 255) : null,
                'purchased_on' => $data['purchased_on'] ?? null,
                'notes' => filled($data['notes'] ?? null) ? mb_substr(trim((string) $data['notes']), 0, 2000) : null,
                'created_by' => $user->id,
            ]);

            if ($source === AssetSource::StaffRegistered) {
                $asset->forceFill(['verified_at' => now(), 'verified_by' => $user->id]);
            }

            $asset->save();

            if ($photo !== null) {
                $asset->forceFill(['photo_path' => $this->storePhoto($photo, 'asset-photos/'.$asset->uuid)])->save();
            }

            $this->event($asset, 'registered', null, AssetStatus::InUse, null, null, $user);

            return $asset;
        });
    }

    public function verify(CustomerAsset $asset, User $user): void
    {
        if ($asset->verified_at !== null) {
            return;
        }

        $asset->forceFill(['verified_at' => now(), 'verified_by' => $user->id, 'disputed_at' => null])->save();
        $this->event($asset, 'verified', null, null, null, null, $user);
    }

    public function changeStatus(CustomerAsset $asset, AssetStatus $to, ?User $user, ?string $comment = null, ?ServiceTicket $ticket = null): void
    {
        $from = $asset->status;

        if ($from === $to) {
            return;
        }

        if (! $from->canTransitionTo($to)) {
            throw new EquipmentException('invalid_transition', ['from' => $from->label(), 'to' => $to->label()]);
        }

        $asset->forceFill(['status' => $to])->save();
        $this->event($asset, $ticket !== null ? 'ticket_status' : 'status_changed', $from, $to, $comment, $ticket, $user);
    }

    public function event(CustomerAsset $asset, string $event, ?AssetStatus $from, ?AssetStatus $to, ?string $comment, ?ServiceTicket $ticket, ?User $user): AssetEvent
    {
        return AssetEvent::query()->create([
            'customer_asset_id' => $asset->id,
            'event' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'comment' => filled($comment) ? mb_substr((string) $comment, 0, 2000) : null,
            'service_ticket_id' => $ticket?->id,
            'user_id' => $user?->id,
            'created_at' => now(),
        ]);
    }

    /** Re-encoded JPEG (1600 px) on the private disk; returns the path. */
    public function storePhoto(UploadedFile $file, string $directory): string
    {
        $images = app(ManageItemImages::class);
        $path = $directory.'/'.Str::random(24).'.jpg';
        Storage::disk('local')->put($path, $images->jpeg($images->decode($file), 1600));

        return $path;
    }

    /**
     * R-1: neutral refusal for the claimant, a staff alert and a history entry on the owner's asset.
     */
    protected function refuse(Partner $claimant, string $serial, User $user, ?CustomerAsset $existing): never
    {
        if ($existing !== null) {
            $this->event($existing, 'claim_refused', null, null, null, null, $user);
        }

        foreach (ServiceTeam::members() as $member) {
            $member->notify(new ServiceClaimRefused($claimant->name, $serial, $existing?->organization?->name));
        }

        throw new EquipmentException('claim_refused');
    }
}
