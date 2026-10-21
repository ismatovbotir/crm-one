<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contact person of a supplier/carrier (receives invitations and, later, bid links).
 */
#[Fillable(['counterparty_id', 'name', 'position', 'phone', 'email', 'telegram', 'preferred_locale', 'is_primary'])]
class CounterpartyContact extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /**
     * @return BelongsTo<Counterparty, $this>
     */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class)->withTrashed();
    }
}
