<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only event of a bid link (opened, otp_sent, verified, verify_failed, submitted,
 * withdrawn, declined, revoked...): IP stored only as a keyed hash, never amounts.
 */
#[Fillable(['link_id', 'event', 'ip_hash', 'user_agent', 'created_at'])]
class TenderLinkEvent extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Link events are append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
