<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One-time 6-digit code (Spec 03 7.1 `login_codes`, generalised by Spec 08 10.2): for a user or a
 * bid link, per purpose. Only an HMAC of the code is stored; 10 minutes, 5 attempts, single use.
 *
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $consumed_at
 * @property CarbonImmutable|null $invalidated_at
 */
#[Fillable(['user_id', 'tender_invitation_link_id', 'purpose', 'channel', 'code_hash', 'expires_at', 'attempts', 'consumed_at', 'invalidated_at', 'ip', 'user_agent'])]
class LoginCode extends Model
{
    /** @var list<string> */
    protected $hidden = ['code_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'invalidated_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->invalidated_at === null && $this->expires_at->isFuture();
    }
}
