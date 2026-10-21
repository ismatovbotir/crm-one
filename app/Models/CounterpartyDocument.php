<?php

namespace App\Models;

use App\Enums\CounterpartyDocumentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Qualification document (Spec 03 7.2): number, expiry, verification; the file is an attachment.
 * Expiry sends a qualified counterparty back to pending qualification (tenders:tick).
 *
 * @property CounterpartyDocumentType $type
 * @property CarbonImmutable|null $valid_until
 */
#[Fillable(['counterparty_id', 'type', 'number', 'valid_until', 'verified_by', 'verified_at', 'note', 'created_by'])]
class CounterpartyDocument extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CounterpartyDocumentType::class,
            'valid_until' => 'immutable_date',
            'verified_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Counterparty, $this>
     */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @return MorphOne<Attachment, $this>
     */
    public function attachment(): MorphOne
    {
        return $this->morphOne(Attachment::class, 'attachable')->latestOfMany();
    }

    public function isExpired(?CarbonImmutable $today = null): bool
    {
        $today ??= CarbonImmutable::now((string) config('tenders.timezone'))->startOfDay();

        return $this->valid_until !== null && $this->valid_until->toDateString() < $today->toDateString();
    }

    /** Expires within the warning window (30 days). */
    public function isExpiringSoon(?CarbonImmutable $today = null): bool
    {
        $today ??= CarbonImmutable::now((string) config('tenders.timezone'))->startOfDay();

        return $this->valid_until !== null && ! $this->isExpired($today) && $this->valid_until->toDateString() <= $today->addDays(30)->toDateString();
    }
}
