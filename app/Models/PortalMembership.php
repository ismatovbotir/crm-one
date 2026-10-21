<?php

namespace App\Models;

use App\Enums\CustomerPortalRole;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A portal user's access to one customer (Spec 03 DEC-3, 7.2): roles, optional approval limit,
 * active flag. Phase 1: at most one active membership per user (PortalAccess enforces it).
 *
 * @property list<string> $roles
 */
#[Fillable(['user_id', 'customer_id', 'counterparty_id', 'contact_id', 'roles', 'approval_limit_minor', 'is_active', 'invited_by', 'activated_at', 'deactivated_at', 'deactivated_by'])]
class PortalMembership extends Model
{
    use HasUuids, LogsActivity;

    /**
     * Spec 03 section 22: a membership points to an organization; `customer_id` (customer portal)
     * or `counterparty_id` (supplier/carrier) is the role context.
     */
    protected static function booted(): void
    {
        static::saving(function (PortalMembership $membership): void {
            $membership->partner_id = $membership->customer_id
                ?? ($membership->counterparty_id !== null ? Counterparty::withTrashed()->whereKey($membership->counterparty_id)->value('partner_id') : null);
        });
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
        return [
            'roles' => 'array',
            'approval_limit_minor' => 'integer',
            'is_active' => 'boolean',
            'activated_at' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    public function hasRole(CustomerPortalRole ...$roles): bool
    {
        foreach ($roles as $role) {
            if (in_array($role->value, (array) $this->roles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return ['uuid'];
    }
}
