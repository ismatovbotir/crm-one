<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\SyncsWithOneC;
use App\Services\OneC\Customers\CustomerOutbox;
use App\Services\OneC\Inbound\OneCSyncContext;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Contact person of a partner (Spec 01 section 6.4, Spec 03 section 24: `partner_id`). At most one
 * primary per partner.
 */
#[Fillable(['partner_id', 'first_name', 'last_name', 'position', 'email', 'phone', 'is_primary', 'notes', 'telegram_username', 'preferred_locale', 'portal_invite_requested_at', 'portal_invite_requested_by'])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory, HasUuids, LogsActivity, SoftDeletes, SyncsWithOneC;

    protected static function booted(): void
    {
        static::saving(function (Contact $contact): void {
            $contact->email = filled($contact->email) ? mb_strtolower(trim($contact->email)) : null;
            $contact->phone = Customer::normalizePhone($contact->phone);
        });

        // Spec 02 rev. 3: contacts travel inside the customer aggregate; CRM-side changes re-queue it.
        $queue = function (Contact $contact, bool $changed): void {
            // Fresh copy: a cached relation would carry a stale sync state.
            if ($changed && ! OneCSyncContext::active() && ($customer = Customer::withTrashed()->find($contact->partner_id)) !== null) {
                app(CustomerOutbox::class)->queue($customer, CustomerOutboxEntry::REASON_CONTACTS, auth()->user());
            }
        };

        static::created(fn (Contact $contact) => $queue($contact, true));
        static::updated(fn (Contact $contact) => $queue($contact, array_intersect(array_keys($contact->getChanges()), CustomerOutbox::SHARED_CONTACT_FIELDS) !== []));
        static::deleted(fn (Contact $contact) => $queue($contact, true));
        static::restored(fn (Contact $contact) => $queue($contact, true));
    }

    /**
     * Single display name (supplier/carrier screens, Spec 03 section 22: contacts are one table).
     */
    public function getNameAttribute(): string
    {
        return $this->fullName();
    }

    /** Telegram handle (supplier contacts used `telegram`). */
    public function getTelegramAttribute(): ?string
    {
        return $this->telegram_username;
    }

    /**
     * Splits a single name into first and last name ("Ali Valiyev" => ["Ali", "Valiyev"]).
     *
     * @return array{0: string, 1: string|null}
     */
    public static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [''];

        return [mb_substr($parts[0], 0, 100), isset($parts[1]) ? mb_substr($parts[1], 0, 100) : null];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'portal_invite_requested_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'partner_id')->withTrashed();
    }

    /**
     * The organization of the contact, whatever its roles.
     *
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id')->withTrashed();
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /**
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return ['uuid', 'external_hash', 'synced_at', 'external_updated_at', 'primary_key'];
    }
}
