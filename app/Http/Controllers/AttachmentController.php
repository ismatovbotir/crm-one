<?php

namespace App\Http\Controllers;

use App\Models\Agreement;
use App\Models\Attachment;
use App\Models\CounterpartyDocument;
use App\Models\Payment;
use App\Models\Requisition;
use App\Models\ServiceTicket;
use App\Models\StockOffAct;
use App\Models\TenderBid;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private attachment download (Spec 06 section 13): authorized through the owning record's policy
 * (signed agreement scans: whoever may see the agreement; payment proofs: only users who may see
 * bank details), served with nosniff and a sandbox CSP; downloads are logged.
 */
class AttachmentController extends Controller
{
    public function __invoke(Attachment $attachment): StreamedResponse
    {
        $owner = $attachment->attachable;

        abort_if($owner === null, 404);

        if ($owner instanceof Agreement) {
            Gate::authorize('downloadScan', $owner);
            $owner->logActivity('scan_downloaded', ['file' => $attachment->original_name]);
        } elseif ($owner instanceof Payment) {
            Gate::authorize('view', $owner);
            Gate::authorize('viewSensitive', $owner);
            $owner->logActivity('proof_downloaded', ['file' => $attachment->original_name]);
        } elseif ($owner instanceof TenderBid) {
            // Sealed until opening except for the employee who entered it (BR-T.5); never an admin.
            Gate::authorize('viewContent', $owner);
            $owner->tender->logActivity('bid_file_downloaded', ['counterparty' => $owner->counterparty?->name, 'file' => $attachment->original_name]);
        } elseif ($owner instanceof CounterpartyDocument) {
            Gate::authorize('downloadDocument', $owner->counterparty);
            $owner->counterparty->logActivity('document_downloaded', ['file' => $attachment->original_name]);
        } elseif ($owner instanceof Requisition) {
            // Spec 10: staff who may view the request (the portal uses its own route).
            Gate::authorize('view', $owner);
        } elseif ($owner instanceof StockOffAct) {
            // Spec 06 27: evidence of a stock-off act, for whoever may see the act.
            Gate::authorize('view', $owner);
            $owner->logActivity('evidence_downloaded', ['file' => $attachment->original_name]);
        } elseif ($owner instanceof ServiceTicket) {
            // Spec 09: service documents are staff-side (the customer gets the printed receipt).
            Gate::authorize('view', $owner);
            $owner->logActivity('document_downloaded', ['file' => $attachment->original_name]);
        } else {
            abort(404);
        }

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        $inline = in_array($attachment->mime, ['application/pdf', 'image/jpeg', 'image/png'], true);

        return $disk->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, no-store',
        ], $inline ? 'inline' : 'attachment');
    }
}
