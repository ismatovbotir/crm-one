<?php

namespace App\Services\Tenders;

use App\Enums\InvitationStatus;
use App\Enums\Locale;
use App\Enums\TenderStatus;
use App\Models\TenderInvitation;
use App\Models\TenderInvitationLink;
use App\Models\TenderLinkEvent;
use App\Models\User;
use App\Services\Auth\OneTimeCodes;
use App\Services\Tenders\Exceptions\TenderActionException;
use App\Support\BusinessSettings;
use App\Support\CompanyProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Unique bid links (Spec 08 8.2, DEC-3): 32 random bytes as base64url (256-bit), only sha256 at
 * rest, lookup by hash with hash_equals, one active link per invitation (regenerate revokes),
 * expiry = deadline + grace, open/verification events with keyed IP hashes, OTP verification to
 * the invited contact (purpose `bid_link_verify`). The plain token exists only in the return
 * value of issue() and in the e-mail sent to the invited contact.
 */
class BidLinks
{
    public const OTP_PURPOSE = 'bid_link_verify';

    public function __construct(protected OneTimeCodes $codes) {}

    /**
     * @return array{link: TenderInvitationLink, token: string}
     */
    public function issue(TenderInvitation $invitation, User $user, string $sentVia): array
    {
        $tender = $invitation->tender;

        if ($tender->status !== TenderStatus::Published || $tender->bids_due_at === null || $tender->bids_due_at->isPast()) {
            throw new TenderActionException('link_not_allowed');
        }

        if ($invitation->status === InvitationStatus::Declined) {
            throw new TenderActionException('invitation_declined');
        }

        if ($sentVia === 'email' && blank($invitation->contact_email)) {
            throw new TenderActionException('link_no_email');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $link = DB::transaction(function () use ($invitation, $user, $sentVia, $token, $tender): TenderInvitationLink {
            TenderInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            TenderInvitationLink::query()->where('tender_invitation_id', $invitation->id)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_by' => $user->id, 'revoke_reason' => 'regenerated']);

            $link = TenderInvitationLink::query()->create([
                'tender_invitation_id' => $invitation->id,
                'token_hash' => hash('sha256', $token),
                'created_by' => $user->id,
                'sent_via' => $sentVia,
                'sent_to' => $sentVia === 'email' ? self::mask((string) $invitation->contact_email) : null,
                'sent_at' => now(),
                'expires_at' => $tender->bids_due_at->addHours(BusinessSettings::int('tenders.link_grace_hours')),
            ]);

            if (in_array($invitation->status, [InvitationStatus::Invited, InvitationStatus::NoResponse], true)) {
                $invitation->update(['status' => InvitationStatus::LinkSent]);
            }

            $tender->logActivity('bid_link_sent', ['counterparty' => $invitation->counterparty?->name, 'via' => $sentVia]);

            return $link;
        });

        if ($sentVia === 'email') {
            $this->mailLink($invitation, $token);
        }

        return ['link' => $link, 'token' => $token];
    }

    public function revoke(TenderInvitationLink $link, User $user, string $reason): void
    {
        if (trim($reason) === '') {
            throw new TenderActionException('reason_required');
        }

        $link->forceFill(['revoked_at' => now(), 'revoked_by' => $user->id, 'revoke_reason' => trim($reason)])->save();
        $this->event($link, 'revoked');
        $link->invitation->tender->logActivity('bid_link_revoked', ['counterparty' => $link->invitation->counterparty?->name]);
    }

    /**
     * Active link for a token, or null (invalid, revoked and expired look the same: generic 404).
     */
    public function resolve(string $token): ?TenderInvitationLink
    {
        if (strlen($token) < 40 || strlen($token) > 64) {
            return null;
        }

        $hash = hash('sha256', $token);
        $link = TenderInvitationLink::query()->where('token_hash', $hash)->first();

        if ($link === null || ! hash_equals($link->token_hash, $hash) || ! $link->isActive()) {
            return null;
        }

        return $link;
    }

    public function recordOpen(TenderInvitationLink $link, Request $request): void
    {
        $this->event($link, 'opened', $request);
        $distinct = TenderLinkEvent::query()->where('link_id', $link->id)->whereNotNull('ip_hash')->distinct()->count('ip_hash');
        $link->forceFill([
            'first_opened_at' => $link->first_opened_at ?? now(),
            'last_opened_at' => now(),
            'open_count' => $link->open_count + 1,
            'distinct_ip_count' => $distinct,
        ])->save();

        $invitation = $link->invitation;

        if (in_array($invitation->status, [InvitationStatus::Invited, InvitationStatus::LinkSent], true)) {
            $invitation->update(['status' => InvitationStatus::Opened, 'first_viewed_at' => $invitation->first_viewed_at ?? now()]);
        }
    }

    /** Whether the link may submit, withdraw or decline now (published, before the deadline). */
    public function canSubmit(TenderInvitationLink $link): bool
    {
        $tender = $link->invitation->tender;

        return $link->isActive() && $tender->status === TenderStatus::Published && $tender->bids_due_at !== null && $tender->bids_due_at->isFuture()
            && $link->invitation->status !== InvitationStatus::Declined;
    }

    public function sendCode(TenderInvitationLink $link, Locale $locale): void
    {
        $invitation = $link->invitation;

        if (blank($invitation->contact_email)) {
            throw new TenderActionException('link_no_email');
        }

        $code = $this->codes->issue(self::OTP_PURPOSE, 'email', link: $link);
        $this->event($link, 'otp_sent');

        $this->send((string) $invitation->contact_email, $locale, 'mail.bid-link-code', ['code' => $code, 'tender' => $invitation->tender], 'bid_link.mail.code_subject');
    }

    public function verifyCode(TenderInvitationLink $link, string $code): bool
    {
        $valid = $this->codes->verify(self::OTP_PURPOSE, $code, link: $link);
        $this->event($link, $valid ? 'verified' : 'verify_failed');

        if ($valid) {
            $link->forceFill(['verified_at' => now()])->save();
        }

        return $valid;
    }

    public function event(TenderInvitationLink $link, string $event, ?Request $request = null): void
    {
        $request ??= request();

        TenderLinkEvent::query()->create([
            'link_id' => $link->id,
            'event' => $event,
            'ip_hash' => $request?->ip() !== null ? hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')) : null,
            'user_agent' => substr((string) $request?->userAgent(), 0, 255) ?: null,
            'created_at' => now(),
        ]);
    }

    public static function mask(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 2).'***@'.$domain;
    }

    protected function mailLink(TenderInvitation $invitation, string $token): void
    {
        $locale = Locale::fromAvailable($invitation->notice_locale) ?? Locale::default();
        $this->send((string) $invitation->contact_email, $locale, 'mail.bid-link', [
            'url' => route('bid.show', ['token' => $token]),
            'tender' => $invitation->tender,
            'counterparty' => $invitation->counterparty,
        ], 'bid_link.mail.invitation_subject');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function send(string $to, Locale $locale, string $view, array $data, string $subjectKey): void
    {
        $previous = App::getLocale();
        App::setLocale($locale->value);

        try {
            $tender = $data['tender'];
            $data['company'] = CompanyProfile::seller($locale)['name'];
            $data['deadline'] = $tender->localTime($tender->bids_due_at)?->format('Y-m-d H:i').' ('.TenderClock::label().')';
            $subject = __($subjectKey, ['number' => $tender->number]);
            Mail::send($view, $data, fn ($message) => $message->to($to)->subject($subject));
        } finally {
            App::setLocale($previous);
        }
    }
}
