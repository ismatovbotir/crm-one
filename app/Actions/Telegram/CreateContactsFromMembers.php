<?php

namespace App\Actions\Telegram;

use App\Actions\Customers\SaveContact;
use App\Enums\CustomerPortalRole;
use App\Enums\Locale;
use App\Enums\TelegramMemberRole;
use App\Enums\UserType;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\PortalMembership;
use App\Models\TelegramChat;
use App\Models\TelegramContact;
use App\Models\TelegramLink;
use App\Models\User;
use App\Services\Telegram\TelegramChats;
use Illuminate\Support\Facades\DB;

/**
 * "Create contacts from members" (Spec 04 section 17): each chosen member of a group linked to a
 * customer becomes a contact of that customer (name and Telegram username, matched by Telegram
 * user id). Optionally a portal user is prepared for them: viewer role, no e-mail (a reserved
 * `@telegram.invalid` placeholder that is never mailed), linked to their Telegram id, pending
 * until they sign in with the 6-digit code the bot sends when THEY ask for it on the portal login
 * page. Nothing is sent automatically. Staff, people of another company and existing portal users
 * are skipped with a reason. A person belongs to one company at a time.
 */
class CreateContactsFromMembers
{
    public function __construct(protected TelegramChats $chats, protected SaveContact $saveContact) {}

    /**
     * @param  list<int>  $telegramContactIds
     * @return array{created: int, invited: int, skipped: array<string, string>}
     */
    public function handle(TelegramChat $chat, array $telegramContactIds, bool $invite, User $by): array
    {
        $customer = $chat->customer;
        $result = ['created' => 0, 'invited' => 0, 'skipped' => []];

        if ($customer === null) {
            return $result;
        }

        $members = TelegramContact::query()
            ->whereIn('id', $telegramContactIds)
            ->whereHas('memberships', fn ($query) => $query->where('telegram_chat_id', $chat->id)->where('role', '!=', TelegramMemberRole::Left->value))
            ->with(['user', 'contact'])
            ->get();

        foreach ($members as $member) {
            $reason = $this->skipReason($member, $customer);

            if ($reason === null && $member->contact !== null && (! $invite || ! $customer->portal_enabled)) {
                $reason = 'exists';
            }

            if ($reason !== null) {
                $result['skipped'][$member->fullName()] = $reason;

                continue;
            }

            DB::transaction(function () use ($member, $customer, $invite, $by, $chat, &$result): void {
                $contact = $member->contact;

                if ($contact === null) {
                    $contact = $this->saveContact->handle($customer, [
                        'first_name' => mb_substr((string) ($member->first_name ?: ($member->username ?: 'Telegram')), 0, 100),
                        'last_name' => $member->last_name ? mb_substr($member->last_name, 0, 100) : null,
                        'phone' => $member->phone,
                        'telegram_username' => $member->username,
                        'is_primary' => ! $customer->contacts()->exists(),
                    ]);
                    $member->forceFill(['contact_id' => $contact->id])->save();
                    $result['created']++;
                }

                if ($invite && $customer->portal_enabled && $member->user_id === null) {
                    $this->invite($member, $customer, $contact->id, $by);
                    $result['invited']++;
                }

                $this->chats->audit($chat, 'telegram_member_to_contact', ['member' => $member->fullName(), 'telegram_user_id' => $member->telegram_user_id, 'contact_id' => $contact->id, 'portal_invited' => $invite && $customer->portal_enabled]);
            });
        }

        return $result;
    }

    /** Why a member cannot become a contact of this customer, or null. */
    public function skipReason(TelegramContact $member, Partner $customer): ?string
    {
        if ($member->user !== null && $member->user->isInternal()) {
            return 'staff';
        }

        if ($member->contact !== null && $member->contact->partner_id !== $customer->id) {
            return 'other_company';
        }

        if ($member->user !== null && $member->user->portalMemberships()->where('is_active', true)->where('customer_id', '!=', $customer->id)->exists()) {
            return 'other_company';
        }

        if ($member->contact !== null && $member->user !== null) {
            return 'exists';
        }

        return null;
    }

    protected function invite(TelegramContact $member, Partner $customer, int $contactId, User $by): void
    {
        $user = new User([
            'name' => mb_substr($member->fullName(), 0, 255),
            'email' => 'tg-'.$member->telegram_user_id.'@'.User::TELEGRAM_EMAIL_DOMAIN,
            'is_active' => true,
        ]);
        $user->type = UserType::Portal;
        $user->password = null;
        $user->locale = Locale::fromAvailable(mb_strtolower(substr((string) $member->language_code, 0, 2))) ?? Locale::default();
        $user->save();

        PortalMembership::query()->create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'contact_id' => $contactId,
            'roles' => [CustomerPortalRole::Viewer->value],
            'is_active' => true,
            'invited_by' => $by->id,
            'activated_at' => null, // pending until the first sign-in with the Telegram code
        ]);

        // Private chat id = Telegram user id. The person still has to open the bot to receive codes.
        if (! TelegramLink::query()->where('chat_id', $member->telegram_user_id)->exists()) {
            TelegramLink::query()->create([
                'user_id' => $user->id,
                'chat_id' => $member->telegram_user_id,
                'telegram_user_id' => $member->telegram_user_id,
                'username' => $member->username,
                'first_name' => $member->first_name,
                'linked_at' => now(),
                // Portal sign-in codes go to this exact chat, which is the proof (Spec 04 section 17).
                'verified_at' => now(),
                'verification_method' => 'group_member',
            ]);
        }

        $member->forceFill(['user_id' => $user->id])->save();
        $customer->logActivity('portal_access_granted', ['telegram_user_id' => $member->telegram_user_id, 'roles' => [CustomerPortalRole::Viewer->value], 'pending' => true]);
    }
}
