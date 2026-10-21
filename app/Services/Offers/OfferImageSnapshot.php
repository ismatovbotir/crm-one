<?php

namespace App\Services\Offers;

use App\Models\ItemImage;
use App\Models\Offer;
use App\Models\OfferLine;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Picture snapshot of a sent offer (Spec 05 section 16): when the offer is sent, the 320 px picture
 * of every line (the referenced picture, else the item's current main picture) is copied to
 * `offer-images/…` and the line keeps that path, so later changes to the item pictures never
 * alter a sent offer. Snapshot files are never deleted with item pictures.
 */
class OfferImageSnapshot
{
    public function snapshot(Offer $offer): void
    {
        foreach ($offer->lines()->get() as $line) {
            $this->line($line);
        }
    }

    public function line(OfferLine $line): void
    {
        $image = ($line->item_image_id !== null ? ItemImage::query()->find($line->item_image_id) : null)
            ?? ItemImage::query()->where('item_id', $line->item_id)->where('position', 0)->first();
        $disk = Storage::disk(ItemImage::DISK);

        if ($image === null || ! $disk->exists($image->medium_path)) {
            $line->forceFill(['image_path' => null])->saveQuietly();

            return;
        }

        $path = 'offer-images/'.now()->format('Y/m').'/'.Str::random(40).'.jpg';
        $disk->copy($image->medium_path, $path);
        $line->forceFill(['image_path' => $path, 'item_image_id' => $image->id])->saveQuietly();
    }
}
