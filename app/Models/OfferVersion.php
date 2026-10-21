<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Immutable published snapshot of a sent offer version (Spec 05 7.8). Never updated.
 *
 * @property array<string, mixed> $payload
 */
#[Fillable(['offer_id', 'root_offer_id', 'version', 'content_hash', 'payload', 'sent_at', 'sent_by', 'channel', 'document_locale', 'created_at'])]
class OfferVersion extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Published offer versions are immutable.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
