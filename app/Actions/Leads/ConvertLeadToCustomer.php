<?php

namespace App\Actions\Leads;

use App\Actions\Customers\SaveContact;
use App\Enums\CustomerOrigin;
use App\Enums\LeadStatus;
use App\Enums\LegalType;
use App\Enums\Locale;
use App\Enums\OfferStatus;
use App\Enums\SaleType;
use App\Enums\SyncStatus;
use App\Enums\TelegramChatType;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Offer;
use App\Models\Partner;
use App\Models\TelegramChat;
use App\Models\TelegramContact;
use App\Models\User;
use App\Notifications\LeadConvertedToCustomer;
use App\Services\Leads\Exceptions\LeadActionException;
use App\Services\Leads\LeadConversionMatcher;
use App\Services\Offers\Exceptions\OfferActionException;
use App\Services\OneC\Customers\CustomerOutbox;
use App\Services\OneC\OneCSettings;
use App\Services\Organizations\OrganizationRoles;
use App\Services\Telegram\TelegramChats;
use Illuminate\Support\Facades\DB;

/**
 * "Convert to customer" (Spec 04 section 18): one transaction locks the lead and
 *  1. creates the customer from the lead (mode `create`), links an existing customer (`link`) or
 *     adds the customer role to a supplier/carrier-only organization (`add_role`); a tax-ID match
 *     blocks `create`, and existing organization fields change only where the manager chose the
 *     lead value (per-field diff);
 *  2. turns the lead contact into an organization contact unless one with the same e-mail or phone
 *     exists;
 *  3. records acquisition source/campaign and the source lead on the customer (only when empty);
 *  4. re-points legacy draft offers of the lead to the customer (offers go only to partners);
 *  5. marks the lead converted (converted_at, converted_by, customer_id, conversion_kind) through
 *     TransitionLead; a second attempt fails with `already_converted`;
 *  6. links the lead's Telegram private chat or group to the customer;
 *  7. options: first offer from the interests, pending portal invitation (nothing is sent),
 *     customer available for 1C to pull (otherwise it is held; the CRM never calls 1C).
 * Notifications to the lead and customer owners go out after the commit.
 */
class ConvertLeadToCustomer
{
    public const MODES = ['create', 'link', 'add_role'];

    public function __construct(
        protected LeadConversionMatcher $matcher,
        protected TransitionLead $transition,
        protected SaveContact $saveContact,
        protected OrganizationRoles $roles,
        protected CustomerOutbox $outbox,
        protected CreateOfferFromInterests $fromInterests,
    ) {}

    /**
     * @param  array{mode: string, partner_id?: int|null, fields?: array<string, string>, sale_type?: string, legal_type?: string, not_duplicate?: bool, create_offer?: bool, invite_portal?: bool, onec_pull?: bool}  $input
     * @return array{customer: Customer, kind: string, contact: Contact|null, offer: Offer|null, notes: list<string>, repointed: list<string>}
     */
    public function handle(Lead $lead, User $user, array $input): array
    {
        $mode = (string) ($input['mode'] ?? 'create');

        if (! in_array($mode, self::MODES, true)) {
            throw new LeadActionException('conversion_invalid');
        }

        $result = DB::transaction(function () use ($lead, $user, $input, $mode): array {
            $fresh = Lead::query()->with(['items.item.unit', 'contact'])->lockForUpdate()->findOrFail($lead->id);

            if ($fresh->status === LeadStatus::Converted) {
                throw new LeadActionException('already_converted');
            }

            if (! $fresh->status->isOpen()) {
                throw new LeadActionException('read_only');
            }

            $hold = ! (bool) ($input['onec_pull'] ?? false);
            $notes = [];

            [$customer, $changed] = match ($mode) {
                'create' => [$this->createCustomer($fresh, $user, $input, $hold), []],
                'link' => $this->linkExisting($fresh, $user, $input),
                default => $this->addRole($fresh, $user, $input, $hold),
            };

            $contact = $this->contactFor($fresh, $customer);

            if ($customer->acquisition_source === null && $customer->source_lead_id === null) {
                $customer->forceFill(['acquisition_source' => $fresh->source->value, 'acquisition_campaign' => $fresh->campaign, 'source_lead_id' => $fresh->id])->saveQuietly();
            }

            $repointed = $this->repointOffers($fresh, $customer, $contact);

            $this->transition->handle($fresh, LeadStatus::Converted, $user, 'customer:'.$customer->name, [
                'customer_id' => $customer->id,
                'contact_id' => $contact?->id,
                'converted_by' => $user->id,
                'conversion_kind' => $mode === 'create' ? 'created' : ($mode === 'link' ? 'linked' : 'role_added'),
                'currency' => $fresh->currency ?: $customer->currency,
            ]);

            $telegram = $this->linkTelegram($fresh, $customer, $contact, $user);

            if ($telegram !== null) {
                $notes[] = $telegram;
            }

            if ((bool) ($input['invite_portal'] ?? false)) {
                $notes[] = $this->requestPortalInvite($contact, $user);
            }

            if (! $hold && $mode === 'link' && filled($customer->tax_id) && ! $customer->sync_with_1c) {
                $customer->forceFill(['sync_with_1c' => true])->save();
            }

            $fresh->logActivity('converted_to_customer', [
                'mode' => $mode,
                'customer' => $customer->name,
                'customer_id' => $customer->id,
                'contact' => $contact?->fullName(),
                'fields' => $changed,
                'offers' => $repointed,
                'onec_pull' => ! $hold,
            ]);
            $customer->logActivity('converted_from_lead', ['lead' => $fresh->number, 'mode' => $mode, 'fields' => $changed]);

            $offer = null;

            if ((bool) ($input['create_offer'] ?? false)) {
                if (! $user->can('create', Offer::class)) {
                    $notes[] = __('leads.conversion.notes.offer_denied');
                } else {
                    try {
                        ['offer' => $offer, 'skipped' => $skipped] = $this->fromInterests->handle($user, $customer->fresh(), $contact, $fresh->items, $fresh);
                        array_push($notes, ...$skipped);
                    } catch (OfferActionException $exception) {
                        $notes[] = __('leads.errors.offer_failed', ['reason' => $exception->getMessage()]);
                    }
                }
            }

            $lead->setRawAttributes($fresh->getAttributes(), true);

            return ['customer' => $customer->fresh(), 'kind' => $mode, 'contact' => $contact, 'offer' => $offer, 'notes' => $notes, 'repointed' => $repointed];
        });

        $this->notifyOwners($lead, $result['customer'], $user);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function createCustomer(Lead $lead, User $user, array $input, bool $hold): Customer
    {
        $values = $this->matcher->leadValues($lead);

        if ($values['name'] === null) {
            throw new LeadActionException('company_required');
        }

        $existing = $this->matcher->taxIdMatch($lead);

        if ($existing !== null) {
            throw new LeadActionException('tax_id_match', ['name' => $existing->name]);
        }

        $country = strtoupper((string) config('crm.default_country', 'UZ'));

        if ($country === 'UZ' && $values['tax_id'] !== null && preg_match('/^\d{9}$/', $values['tax_id']) !== 1) {
            throw new LeadActionException('tax_id_invalid');
        }

        $warnings = $this->matcher->matches($lead)->reject(fn (array $match): bool => $match['action'] === 'unavailable' && ! in_array('tax_id', $match['reasons'], true));

        if ($warnings->isNotEmpty() && ! (bool) ($input['not_duplicate'] ?? false)) {
            throw new LeadActionException('possible_duplicate');
        }

        $create = fn (): Customer => Customer::query()->create([
            'origin' => CustomerOrigin::Crm,
            'name' => $values['name'],
            'legal_type' => LegalType::tryFrom((string) ($input['legal_type'] ?? '')) ?? LegalType::LegalEntity,
            'tax_id' => $values['tax_id'],
            'country' => $country,
            'industry' => $values['industry'],
            'email' => $values['email'],
            'phone' => $values['phone'],
            'currency' => $lead->currency ?: (string) config('crm.base_currency'),
            'owner_id' => $lead->owner_id ?? $user->id,
            'preferred_locale' => $values['preferred_locale'],
            'sale_type' => SaleType::tryFrom((string) ($input['sale_type'] ?? '')) ?? SaleType::B2b,
            // Spec 02 rev. 4: synced with 1C only when the option is ticked and there is a tax ID.
            'sync_with_1c' => ! $hold && $values['tax_id'] !== null,
            'sync_status' => SyncStatus::NotSynced,
            'sync_error' => app(OneCSettings::class)->customersEnabled() ? null : 'integration_disabled',
        ]);

        return $create();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: Customer, 1: list<string>}
     */
    protected function linkExisting(Lead $lead, User $user, array $input): array
    {
        $customer = Customer::query()->find((int) ($input['partner_id'] ?? 0));

        if ($customer === null) {
            throw new LeadActionException('organization_invalid');
        }

        if ($customer->trashed() || $customer->isDeletedInOneC()) {
            throw new LeadActionException('customer_unavailable');
        }

        $changed = $this->applyFields($lead, $customer, $user, (array) ($input['fields'] ?? []));

        return [$customer, $changed];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: Customer, 1: list<string>}
     */
    protected function addRole(Lead $lead, User $user, array $input, bool $hold): array
    {
        $organization = Partner::query()->find((int) ($input['partner_id'] ?? 0));

        if ($organization === null || $organization->is_customer) {
            throw new LeadActionException('organization_invalid');
        }

        if ($organization->trashed() || $organization->isDeletedInOneC()) {
            throw new LeadActionException('customer_unavailable');
        }

        $run = function () use ($organization, $lead, $user, $input): array {
            if ($organization->owner_id === null) {
                $organization->forceFill(['owner_id' => $lead->owner_id ?? $user->id])->save();
            }

            $customer = $this->roles->addCustomerRole($organization, $user);
            $customer->forceFill(['sale_type' => SaleType::tryFrom((string) ($input['sale_type'] ?? '')) ?? SaleType::B2b])->save();
            // The role was just added by this user: filling its fields needs no separate edit right.
            $changed = $this->applyFields($lead, $customer, $user, (array) ($input['fields'] ?? []), true);

            return [$customer, $changed];
        };

        [$customer, $changed] = $run();

        if (! $hold && filled($customer->tax_id) && ! $customer->sync_with_1c) {
            $customer->forceFill(['sync_with_1c' => true])->save();
        }

        return [$customer->fresh(), $changed];
    }

    /**
     * Applies the fields where the manager chose the lead value; everything else is kept.
     *
     * @param  array<string, string>  $choices  field => lead|keep
     * @return list<string> changed fields
     */
    protected function applyFields(Lead $lead, Partner $customer, User $user, array $choices, bool $mayUpdate = false): array
    {
        $diff = collect($this->matcher->diff($lead, Partner::withTrashed()->findOrFail($customer->id)))->keyBy('field');
        $values = $this->matcher->leadValues($lead);
        $updates = [];

        foreach ($choices as $field => $choice) {
            if ($choice === 'lead' && $diff->has($field)) {
                $updates[$field] = $values[$field];
            }
        }

        if ($updates === []) {
            return [];
        }

        if (! $mayUpdate && ! $user->can('update', $customer)) {
            throw new LeadActionException('customer_update_denied');
        }

        $customer->fill($updates)->save();

        return array_keys($updates);
    }

    /**
     * The organization contact for the lead person: the linked one, an existing one with the same
     * e-mail or phone, or a new one (primary when the organization has none).
     */
    protected function contactFor(Lead $lead, Partner $customer): ?Contact
    {
        if ($lead->contact !== null && $lead->contact->partner_id === $customer->id && ! $lead->contact->trashed()) {
            return $lead->contact;
        }

        $email = filled($lead->contact_email) ? mb_strtolower(trim((string) $lead->contact_email)) : null;
        $phone = Customer::normalizePhone($lead->contact_phone);

        if ($email !== null || $phone !== null) {
            $existing = Contact::query()->where('partner_id', $customer->id)
                ->where(fn ($query) => $query->when($email !== null, fn ($q) => $q->orWhere('email', $email))->when($phone !== null, fn ($q) => $q->orWhere('phone', $phone)))
                ->orderByDesc('is_primary')->orderBy('id')->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $name = trim((string) $lead->contact_name);

        if ($name === '' && $email === null && $phone === null) {
            return $customer->primaryContact;
        }

        [$first, $last] = array_pad(explode(' ', $name !== '' ? $name : (string) ($email ?? $phone), 2), 2, null);

        return $this->saveContact->handle($customer, [
            'first_name' => mb_substr($first, 0, 255),
            'last_name' => filled($last) ? mb_substr(trim($last), 0, 255) : null,
            'position' => $lead->contact_position,
            'email' => $email,
            'phone' => $phone,
            'is_primary' => ! $customer->contacts()->where('is_primary', true)->exists(),
            'telegram_username' => $lead->telegram_chat_id !== null && $lead->telegram_chat_id > 0 ? $lead->telegram_username : null,
            'preferred_locale' => Locale::tryFrom((string) $lead->locale)?->value,
        ]);
    }

    /**
     * Legacy draft offers of the lead move to the customer (and its contact).
     *
     * @return list<string> offer numbers
     */
    protected function repointOffers(Lead $lead, Partner $customer, ?Contact $contact): array
    {
        $numbers = [];

        foreach (Offer::query()->where('lead_id', $lead->id)->where('customer_id', '!=', $customer->id)->get() as $offer) {
            // Offers go only to partners (2026-10-19): only legacy drafts can still need a new customer.
            if ($offer->status !== OfferStatus::Draft) {
                continue;
            }

            $previous = $offer->customer_id;
            $offer->forceFill(['customer_id' => $customer->id, 'contact_id' => $contact?->id ?? $customer->primaryContact?->id])->save();
            $offer->logActivity('customer_repointed', ['lead' => $lead->number, 'previous_customer_id' => $previous, 'customer' => $customer->name]);
            $numbers[] = $offer->displayNumber();
        }

        return $numbers;
    }

    /**
     * Links the lead's Telegram chat (private or group) to the customer; later messages from it
     * open leads attached to the customer. Returns a note when the chat belongs to another one.
     */
    protected function linkTelegram(Lead $lead, Partner $customer, ?Contact $contact, User $user): ?string
    {
        if ($lead->telegram_chat_id === null) {
            return null;
        }

        $chat = TelegramChat::query()->where('chat_id', $lead->telegram_chat_id)->first();

        if ($chat === null && $lead->telegram_chat_id > 0) {
            $chat = TelegramChat::query()->create([
                'chat_id' => $lead->telegram_chat_id,
                'type' => TelegramChatType::Private,
                'title' => mb_substr((string) ($lead->contact_name ?? $lead->company_name), 0, 255) ?: null,
                'username' => $lead->telegram_username,
                'first_seen_at' => $lead->created_at ?? now(),
            ]);
        }

        if ($chat !== null && $chat->merged_into_id !== null) {
            $chat = TelegramChat::query()->find($chat->merged_into_id) ?? $chat;
        }

        if ($chat !== null) {
            if ($chat->customer_id !== null && $chat->customer_id !== $customer->id) {
                return __('leads.conversion.notes.telegram_other_customer', ['chat' => $chat->displayName()]);
            }

            if ($chat->customer_id === null) {
                app(TelegramChats::class)->linkCustomer($chat, $customer, $user);
            }
        }

        if ($lead->telegram_user_id !== null && $contact !== null) {
            TelegramContact::query()->where('telegram_user_id', $lead->telegram_user_id)->whereNull('contact_id')->update(['contact_id' => $contact->id]);
        }

        return null;
    }

    protected function requestPortalInvite(?Contact $contact, User $user): string
    {
        if ($contact === null || blank($contact->email)) {
            return __('leads.conversion.notes.invite_no_email');
        }

        $contact->forceFill(['portal_invite_requested_at' => now(), 'portal_invite_requested_by' => $user->id])->saveQuietly();

        return __('leads.conversion.notes.invite_pending', ['name' => $contact->fullName()]);
    }

    protected function notifyOwners(Lead $lead, Partner $customer, User $by): void
    {
        $ids = array_unique(array_filter([$lead->owner_id, $customer->owner_id]));

        foreach (User::query()->whereKey($ids)->where('is_active', true)->get() as $owner) {
            if ($owner->id !== $by->id) {
                $owner->notify(new LeadConvertedToCustomer($lead, $customer, $by->name));
            }
        }
    }
}
