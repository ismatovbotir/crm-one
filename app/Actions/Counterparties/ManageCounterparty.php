<?php

namespace App\Actions\Counterparties;

use App\Actions\Customers\SaveContact;
use App\Enums\CounterpartyDocumentType;
use App\Enums\CounterpartyStatus;
use App\Models\Contact;
use App\Models\Counterparty;
use App\Models\CounterpartyDocument;
use App\Models\CounterpartyRating;
use App\Models\Customer;
use App\Models\User;
use App\Services\Attachments\StoreAttachment;
use App\Services\Organizations\OrganizationRoles;
use App\Services\Tenders\Exceptions\TenderActionException;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Counterparty master data (Spec 03 T-1): create/update, contacts, qualification documents with
 * expiry, status changes (qualify needs a verified, unexpired document; suspend/blacklist need a
 * reason), ratings (12-month rolling average) and the nightly expiry check.
 */
class ManageCounterparty
{
    public function __construct(protected StoreAttachment $attachments) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(?Counterparty $counterparty, array $data, User $user): Counterparty
    {
        if (! ($data['is_supplier'] ?? false) && ! ($data['is_carrier'] ?? false)) {
            throw new TenderActionException('counterparty_role_required');
        }

        // Spec 03 section 22: one organization per tax ID; an existing one gets the role instead.
        if (array_key_exists('tax_id', $data) && ($existing = app(OrganizationRoles::class)->findByTaxId($data['tax_id'], $counterparty?->partner_id)) !== null) {
            throw new TenderActionException('organization_exists', ['name' => $existing->name]);
        }

        // Identity of an organization that is also a customer is edited on the customer card.
        if ($counterparty !== null && $counterparty->identityLocked()) {
            $data = array_diff_key($data, array_flip(Customer::IDENTITY_FIELDS));
        }

        if ($counterparty === null) {
            return Counterparty::query()->create([
                ...$data,
                'status' => CounterpartyStatus::Prospect,
                'owner_id' => $user->id,
                'department_id' => $user->activeDepartments()->first()?->id,
            ]);
        }

        $counterparty->update($data);

        return $counterparty;
    }

    /**
     * A contact of the organization (Spec 03 section 22: one contacts table for every role).
     *
     * @param  array{name: string, position?: string|null, phone?: string|null, email?: string|null, telegram?: string|null, preferred_locale?: string|null, is_primary?: bool}  $data
     */
    public function saveContact(Counterparty $counterparty, ?Contact $contact, array $data): Contact
    {
        [$first, $last] = Contact::splitName((string) $data['name']);
        $attributes = [
            'first_name' => $first,
            'last_name' => $last,
            'position' => $data['position'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'telegram_username' => filled($data['telegram'] ?? null) ? mb_substr(ltrim((string) $data['telegram'], '@'), 0, 64) : null,
            'preferred_locale' => $data['preferred_locale'] ?? null,
            'is_primary' => ($data['is_primary'] ?? false) || ! $counterparty->contacts()->exists(),
        ];

        $contact = app(SaveContact::class)->handle($counterparty->organization, $attributes, $contact);
        $counterparty->logActivity('contact_saved', ['name' => $contact->fullName()]);

        return $contact;
    }

    public function removeContact(Counterparty $counterparty, Contact $contact): void
    {
        $counterparty->logActivity('contact_removed', ['name' => $contact->fullName()]);
        $contact->delete();
    }

    public function addDocument(Counterparty $counterparty, CounterpartyDocumentType $type, ?string $number, ?string $validUntil, ?UploadedFile $file, User $user): CounterpartyDocument
    {
        return DB::transaction(function () use ($counterparty, $type, $number, $validUntil, $file, $user): CounterpartyDocument {
            $document = $counterparty->documents()->create([
                'type' => $type,
                'number' => $number,
                'valid_until' => $validUntil,
                'created_by' => $user->id,
            ]);

            if ($file !== null) {
                $this->attachments->handle($file, $document, 'counterparty_document', $user, (int) config('tenders.evidence_max_kb'), 'documentFile');
            }

            $counterparty->logActivity('document_added', ['type' => $type->value, 'number' => $number, 'valid_until' => $validUntil]);

            return $document;
        });
    }

    public function verifyDocument(CounterpartyDocument $document, User $user): void
    {
        $document->forceFill(['verified_by' => $user->id, 'verified_at' => now()])->save();
        $document->counterparty->logActivity('document_verified', ['type' => $document->type->value, 'number' => $document->number]);
    }

    public function changeStatus(Counterparty $counterparty, CounterpartyStatus $to, ?string $reason, User $user): Counterparty
    {
        if (! $counterparty->status->canTransitionTo($to)) {
            throw new TenderActionException('invalid_transition', ['from' => $counterparty->status->label(), 'to' => $to->label()]);
        }

        if (in_array($to, [CounterpartyStatus::Suspended, CounterpartyStatus::Blacklisted], true) && blank($reason)) {
            throw new TenderActionException('reason_required');
        }

        if ($to === CounterpartyStatus::Qualified) {
            $today = CarbonImmutable::now((string) config('tenders.timezone'))->startOfDay();
            $valid = $counterparty->documents()->whereNotNull('verified_at')->get()->contains(fn (CounterpartyDocument $document): bool => ! $document->isExpired($today));

            if (! $valid) {
                throw new TenderActionException('qualification_needs_document');
            }
        }

        $counterparty->forceFill([
            'status' => $to,
            'status_reason' => $reason,
            'qualified_at' => $to === CounterpartyStatus::Qualified ? now() : $counterparty->qualified_at,
            'qualified_by' => $to === CounterpartyStatus::Qualified ? $user->id : $counterparty->qualified_by,
        ])->save();

        return $counterparty;
    }

    /**
     * @param  array{on_time?: bool|null, quality: int, communication: int, documents: int, comment?: string|null}  $data
     */
    public function rate(Counterparty $counterparty, array $data, User $user, ?string $subjectType = null, ?int $subjectId = null): CounterpartyRating
    {
        foreach (['quality', 'communication', 'documents'] as $field) {
            if ($data[$field] < 1 || $data[$field] > 5) {
                throw new TenderActionException('rating_range');
            }
        }

        return DB::transaction(function () use ($counterparty, $data, $user, $subjectType, $subjectId): CounterpartyRating {
            $rating = $counterparty->ratings()->create([
                ...$data,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'rated_by' => $user->id,
                'created_at' => now(),
            ]);

            $this->refreshRating($counterparty);

            return $rating;
        });
    }

    /** Rolling 12-month average of (quality + communication + documents) / 3, 1-5. */
    public function refreshRating(Counterparty $counterparty): void
    {
        $rows = $counterparty->ratings()->where('created_at', '>=', now()->subYear())->get();
        $average = $rows->isEmpty() ? null : bcdiv((string) $rows->sum(fn (CounterpartyRating $r): int => $r->quality + $r->communication + $r->documents), (string) ($rows->count() * 3), 2);

        $counterparty->forceFill(['rating' => $average])->saveQuietly();
    }

    /**
     * Nightly (tenders:tick): qualified counterparties with an expired verified document fall back
     * to pending qualification (Spec 03 6.2).
     */
    public function expireDocuments(): int
    {
        $today = CarbonImmutable::now((string) config('tenders.timezone'))->startOfDay();
        $count = 0;

        Counterparty::query()->where('status', CounterpartyStatus::Qualified)
            ->whereHas('documents', fn ($q) => $q->whereNotNull('valid_until')->whereDate('valid_until', '<', $today->toDateString()))
            ->each(function (Counterparty $counterparty) use (&$count): void {
                $counterparty->forceFill(['status' => CounterpartyStatus::PendingQualification, 'status_reason' => 'document_expired'])->save();
                $count++;
            });

        return $count;
    }
}
