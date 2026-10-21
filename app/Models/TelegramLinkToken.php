<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Single-use deep-link token for /start (Spec 03 4.5): sha256 at rest, 10 minutes.
 *
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $consumed_at
 */
#[Fillable(['user_id', 'token_hash', 'expires_at', 'consumed_at'])]
class TelegramLinkToken extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime'];
    }
}
