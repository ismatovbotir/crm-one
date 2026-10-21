<?php

namespace App\Http\Controllers;

use App\Documents\DocumentLanguage;
use App\Documents\DocumentRenderer;
use App\Models\CustomerAsset;
use App\Models\ServiceTicket;
use App\Models\ServiceTicketPhoto;
use App\Models\User;
use App\Support\CompanyProfile;
use App\Support\PortalContext;
use App\Support\PortalScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Spec 09 files: the service acceptance receipt (staff and portal), the company's equipment CSV
 * (portal, formula-safe) and photos (staff with view rights, or a portal user of that company).
 */
class ServiceDocumentsController extends Controller
{
    public function staffReceipt(Request $request, ServiceTicket $ticket, DocumentRenderer $renderer): View
    {
        Gate::authorize('view', $ticket);

        return $this->receipt($request, $ticket, $renderer, route('service.tickets.show', $ticket));
    }

    public function portalReceipt(Request $request, ServiceTicket $ticket, PortalScope $scope, DocumentRenderer $renderer): View
    {
        abort_unless($scope->serviceTickets()->whereKey($ticket->id)->exists(), 404);

        return $this->receipt($request, $ticket, $renderer, route('portal.equipment.show', $ticket->asset));
    }

    public function portalExport(PortalScope $scope, PortalContext $context): StreamedResponse
    {
        $assets = $scope->assets()->with('item:id,name')->orderBy('id')->get();
        $context->user()->logActivity('equipment_exported', ['count' => $assets->count()]);

        return response()->streamDownload(function () use ($assets): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['item', 'serial', 'status', 'location', 'source', 'verified', 'sold_or_purchased', 'warranty_until'], ';');

            foreach ($assets as $asset) {
                fputcsv($out, array_map(fn ($value): string => TenderPrintController::csvSafe((string) $value), [
                    $asset->displayName(), $asset->serial_raw, $asset->status->label(), $asset->location,
                    $asset->source->value, $asset->isVerified() ? 'yes' : 'no',
                    $asset->sold_at?->toDateString() ?? $asset->purchased_on?->toDateString(), $asset->warranty_until?->toDateString(),
                ]), ';');
            }

            fclose($out);
        }, 'equipment-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function assetPhoto(CustomerAsset $asset): Response
    {
        abort_unless($asset->photo_path !== null && $this->mayView($asset->partner_id, fn (User $staff): bool => $staff->can('view', $asset)), 404);

        return $this->image($asset->photo_path);
    }

    public function ticketPhoto(ServiceTicketPhoto $photo): Response
    {
        $ticket = ServiceTicket::query()->findOrFail($photo->service_ticket_id);
        abort_unless($this->mayView($ticket->partner_id, fn (User $staff): bool => $staff->can('view', $ticket)), 404);

        return $this->image($photo->path);
    }

    protected function receipt(Request $request, ServiceTicket $ticket, DocumentRenderer $renderer, string $backUrl): View
    {
        $ticket->load(['asset.item', 'organization']);
        $language = DocumentLanguage::resolve($request->query('lang'), $ticket->customer);

        return $renderer->print('documents.service_receipt', [
            'ticket' => $ticket,
            'seller' => CompanyProfile::seller($language),
        ], $language, __('documents.service_receipt.title', [], $language->value).' '.$ticket->number, $backUrl);
    }

    /**
     * @param  callable(User): bool  $staffCheck
     */
    protected function mayView(int $organizationId, callable $staffCheck): bool
    {
        $staff = Auth::guard('web')->user();

        if ($staff instanceof User && $staff->isInternal()) {
            return $staffCheck($staff);
        }

        $portal = Auth::guard('portal')->user();
        $membership = $portal instanceof User && $portal->isPortal() && $portal->is_active ? $portal->activeMembership() : null;

        return $membership !== null && (int) $membership->customer_id === $organizationId;
    }

    protected function image(string $path): Response
    {
        $disk = Storage::disk('local');
        abort_unless(str_ends_with($path, '.jpg') && $disk->exists($path), 404);

        return response((string) $disk->get($path), 200, ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600', 'Content-Disposition' => 'inline']);
    }
}
