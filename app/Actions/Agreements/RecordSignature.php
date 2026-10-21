<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementStatus;
use App\Enums\HoldReason;
use App\Models\Agreement;
use App\Models\CompanySignatory;
use App\Models\Order;
use App\Models\User;
use App\Services\Agreements\Exceptions\AgreementActionException;
use App\Services\Attachments\StoreAttachment;
use App\Services\OneC\Outbox\OneCOutbox;
use App\Services\Orders\OrderHolds;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Signing (Spec 06 DEC-11, BR-AG.7, AC-15): anyone allowed may upload the signed scan (it only
 * sets `customer_signed_uploaded_at`); an internal accountant/manager/finance user then confirms
 * both signatures → `signed`, and `active` at once when the term has started. Signing a renewal
 * marks the renewed agreement `renewed`; signing clears the `agreement_unsigned` hold of the order.
 */
class RecordSignature
{
    public function __construct(protected TransitionAgreement $transition, protected StoreAttachment $attachments, protected OrderHolds $holds) {}

    public function upload(Agreement $agreement, User $user, UploadedFile $file): Agreement
    {
        $agreement = $agreement->fresh();

        if ($agreement->status !== AgreementStatus::SentForSignature) {
            throw new AgreementActionException('not_sent');
        }

        $attachment = $this->attachments->handle($file, $agreement, 'signed_scan', $user, (int) config('agreements.scan_max_kb'), 'scan');
        $agreement->forceFill(['signed_attachment_id' => $attachment->id, 'customer_signed_uploaded_at' => now()])->save();
        $agreement->logActivity('scan_uploaded', ['file' => $attachment->original_name, 'sha256' => $attachment->sha256]);

        return $agreement;
    }

    /**
     * @param  array{signed_on: string, customer_signatory_name: string, customer_signatory_position?: string|null, customer_signatory_basis?: string|null, our_signatory_id?: int|null}  $data
     */
    public function confirm(Agreement $agreement, User $user, array $data): Agreement
    {
        return DB::transaction(function () use ($agreement, $user, $data): Agreement {
            $agreement = Agreement::query()->lockForUpdate()->findOrFail($agreement->id);
            $today = CarbonImmutable::now((string) config('orders.timezone'))->startOfDay();
            $signedOn = CarbonImmutable::parse($data['signed_on'], (string) config('orders.timezone'))->startOfDay();

            if ($agreement->status !== AgreementStatus::SentForSignature) {
                throw new AgreementActionException('not_sent');
            }

            if ($agreement->signed_attachment_id === null) {
                throw new AgreementActionException('scan_required');
            }

            if ($signedOn->gt($today)) {
                throw new AgreementActionException('signed_in_future');
            }

            $signatoryId = $data['our_signatory_id'] ?? $agreement->our_signatory_id;

            if ($signatoryId === null || ! CompanySignatory::query()->whereKey($signatoryId)->where('is_active', true)->exists()) {
                throw new AgreementActionException('signatory_invalid');
            }

            $startsOn = $agreement->starts_on ?? $signedOn;
            $endsOn = $agreement->ends_on ?? $startsOn->addMonths((int) config('agreements.default_term_months'))->subDay();

            $this->transition->handle($agreement, AgreementStatus::Signed, $user, null, [
                'signed_on' => $signedOn->toDateString(),
                'signed_confirmed_by' => $user->id,
                'our_signatory_id' => $signatoryId,
                'customer_signatory_name' => $data['customer_signatory_name'],
                'customer_signatory_position' => $data['customer_signatory_position'] ?? null,
                'customer_signatory_basis' => $data['customer_signatory_basis'] ?? null,
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn->toDateString(),
            ]);

            $this->activateIfStarted($agreement->fresh(), $today);
            app(OneCOutbox::class)->publishAgreement('agreement.signed', $agreement->fresh());
            $this->clearOrderHold($agreement);

            return $agreement->fresh();
        });
    }

    /**
     * signed → active once `starts_on` is reached; a renewal being activated retires its predecessor.
     */
    public function activateIfStarted(Agreement $agreement, CarbonImmutable $today): void
    {
        if ($agreement->status !== AgreementStatus::Signed || $agreement->starts_on === null || $agreement->starts_on->toDateString() > $today->toDateString()) {
            return;
        }

        $this->transition->handle($agreement, AgreementStatus::Active, null, 'starts_on_reached');

        $previous = $agreement->renewalOf;

        if ($previous !== null && $previous->status === AgreementStatus::Active) {
            $this->transition->handle($previous, AgreementStatus::Renewed, null, 'renewed_by:'.$agreement->number, ['renewed_by_id' => $agreement->id]);
        }
    }

    protected function clearOrderHold(Agreement $agreement): void
    {
        if ($agreement->order_id === null) {
            return;
        }

        $order = Order::query()->find($agreement->order_id);

        if ($order !== null && $this->holds->isActive($order, HoldReason::AgreementUnsigned)) {
            $this->holds->clear($order, HoldReason::AgreementUnsigned);
            $order->save();
        }
    }
}
