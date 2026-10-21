<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable rendered agreement text per locale, written when the agreement is sent (DEC-10).
 */
#[Fillable(['agreement_id', 'version', 'locale', 'html', 'sha256', 'rendered_at', 'rendered_by'])]
class AgreementVersion extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Rendered agreement versions are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Rendered agreement versions are immutable.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['rendered_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Agreement, $this>
     */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }
}
