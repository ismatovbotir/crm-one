<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Support\PortalContext;
use App\Support\PortalScope;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Portal download of a request attachment (Spec 10 §8): only the company's portal users, and a
 * requester only for their own requests; served sandboxed with nosniff.
 */
class RequisitionFilesController extends Controller
{
    public function __invoke(string $requisition, Attachment $attachment, PortalScope $scope, PortalContext $context): StreamedResponse
    {
        $model = $scope->requisitions()->where('uuid', $requisition)->firstOrFail();
        abort_unless($context->canPurchase() || $model->requester_id === $context->user()->id, 404);
        abort_unless($attachment->attachable_type === $model->getMorphClass() && (int) $attachment->attachable_id === $model->id, 404);

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        return $disk->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, no-store',
        ], in_array($attachment->mime, ['application/pdf', 'image/jpeg', 'image/png'], true) ? 'inline' : 'attachment');
    }
}
