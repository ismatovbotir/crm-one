<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementChannel;
use App\Enums\AgreementStatus;
use App\Enums\Locale;
use App\Models\Agreement;
use App\Models\AgreementDispatch;
use App\Models\AgreementVersion;
use App\Models\Contact;
use App\Models\User;
use App\Services\Agreements\AgreementRenderer;
use App\Services\Agreements\Exceptions\AgreementActionException;
use Illuminate\Support\Facades\DB;

/**
 * Send an agreement for signature (Spec 06 BR-AG.4, AC-14): from draft when no review is needed,
 * from review once approved. The text is rendered per print language and stored immutably in
 * `agreement_versions` with its sha256 fingerprint; each channel gets a dispatch row. Re-sending a
 * sent agreement only adds dispatches for the same version. Portal and e-mail link channels are
 * enabled with the customer portal (job 8); print and courier work now.
 */
class SendAgreement
{
    public function __construct(
        protected TransitionAgreement $transition,
        protected ReviewAgreement $review,
        protected GenerateAgreement $generate,
        protected AgreementRenderer $renderer,
    ) {}

    /**
     * @param  list<AgreementChannel>  $channels
     */
    public function handle(Agreement $agreement, User $user, array $channels, ?int $recipientContactId = null): Agreement
    {
        if ($channels === []) {
            throw new AgreementActionException('channel_required');
        }

        foreach ($channels as $channel) {
            if (! in_array($channel, AgreementChannel::available(), true)) {
                throw new AgreementActionException('channel_unavailable', ['channel' => $channel->label()]);
            }
        }

        return DB::transaction(function () use ($agreement, $user, $channels, $recipientContactId): Agreement {
            $agreement = Agreement::query()->lockForUpdate()->findOrFail($agreement->id);

            if ($recipientContactId !== null && ! Contact::query()->whereKey($recipientContactId)->where('partner_id', $agreement->customer_id)->exists()) {
                throw new AgreementActionException('recipient_foreign');
            }

            if (in_array(AgreementChannel::Portal, $channels, true) && ! $agreement->customer?->portal_enabled) {
                throw new AgreementActionException('portal_not_enabled');
            }

            if ($agreement->status !== AgreementStatus::SentForSignature) {
                $agreement = $this->prepareFirstSend($agreement, $user);
            }

            foreach ($channels as $channel) {
                AgreementDispatch::query()->create([
                    'agreement_id' => $agreement->id,
                    'version' => $agreement->version,
                    'channel' => $channel,
                    'recipient_contact_id' => $recipientContactId,
                    'locale' => $agreement->document_locale,
                    'sent_at' => now(),
                    'sent_by' => $user->id,
                ]);
            }

            return $agreement;
        });
    }

    protected function prepareFirstSend(Agreement $agreement, User $user): Agreement
    {
        if ($agreement->status === AgreementStatus::Draft) {
            $agreement = $this->generate->refresh($agreement);

            if ($agreement->is_non_standard) {
                throw new AgreementActionException('review_required');
            }
        } elseif ($agreement->status === AgreementStatus::InReview) {
            if ($agreement->approved_at === null) {
                throw new AgreementActionException('not_approved');
            }
        } else {
            throw new AgreementActionException('invalid_transition', ['from' => $agreement->status->label(), 'to' => AgreementStatus::SentForSignature->label()]);
        }

        $this->review->assertValid($agreement);

        foreach ($agreement->printLocales() as $locale) {
            $html = $this->renderer->preview($agreement, Locale::from($locale));

            AgreementVersion::query()->create([
                'agreement_id' => $agreement->id,
                'version' => $agreement->version,
                'locale' => $locale,
                'html' => $html,
                'sha256' => hash('sha256', $html),
                'rendered_at' => now(),
                'rendered_by' => $user->id,
            ]);
        }

        return $this->transition->handle($agreement, AgreementStatus::SentForSignature, $user, null, ['sent_at' => now(), 'sent_by' => $user->id]);
    }
}
