<?php

namespace App\Http\Controllers;

use App\Models\ItemImage;
use App\Models\OfferLine;
use App\Models\OrderLine;
use App\Models\User;
use App\Support\PortalContext;
use App\Support\PortalScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pictures from the private disk (Spec 01 section 15, Spec 05 section 16). Item pictures: any
 * signed-in staff user with catalog access or portal user. Offer and order line snapshots: staff who
 * may view the offer / order, or a portal user of that customer. Unknown or foreign → 404.
 * Served as image/jpeg with nosniff and a private cache.
 */
class MediaController extends Controller
{
    public function itemImage(ItemImage $image, string $variant): Response
    {
        abort_unless(in_array($variant, ['thumb', 'medium', 'full'], true), 404);
        $staff = Auth::guard('web')->user();
        abort_unless(($staff instanceof User && $staff->isInternal() && $staff->can('view', $image->item)) || $this->portalUser() !== null, 404);

        return $this->file($image->pathFor($variant));
    }

    public function offerLineImage(OfferLine $line): Response
    {
        $offer = $line->offer;
        $staff = Auth::guard('web')->user();
        $allowed = ($staff instanceof User && $staff->isInternal() && $staff->can('view', $offer))
            || ($this->portalUser() !== null && $this->portalScope()->offers()->whereKey($offer->id)->exists());
        abort_unless($allowed && $line->image_path !== null, 404);

        return $this->file($line->image_path);
    }

    public function orderLineImage(OrderLine $line): Response
    {
        $order = $line->order;
        $staff = Auth::guard('web')->user();
        $allowed = ($staff instanceof User && $staff->isInternal() && $staff->can('view', $order))
            || ($this->portalUser() !== null && $this->portalScope()->orders()->whereKey($order->id)->exists());
        abort_unless($allowed && $line->image_path !== null, 404);

        return $this->file($line->image_path);
    }

    protected function portalUser(): ?User
    {
        $user = Auth::guard('portal')->user();
        $membership = $user instanceof User && $user->isPortal() && $user->is_active ? $user->activeMembership() : null;

        if ($membership === null) {
            return null;
        }

        app(PortalContext::class)->set($user, $membership);

        return $user;
    }

    protected function portalScope(): PortalScope
    {
        return app(PortalScope::class);
    }

    protected function file(string $path): Response
    {
        $disk = Storage::disk(ItemImage::DISK);
        abort_unless(str_ends_with($path, '.jpg') && $disk->exists($path), 404);

        return response((string) $disk->get($path), 200, [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
            'Content-Disposition' => 'inline',
        ]);
    }
}
