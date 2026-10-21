<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only record of one integration call (Spec 02 BR-I.19): method, path without query,
 * status, duration, request id, token prefix, error code. Never bodies, personal data or tokens.
 */
#[Fillable([
    'inbound_batch_id', 'direction', 'level', 'method', 'path', 'http_status', 'duration_ms', 'request_id',
    'token_prefix', 'error_code', 'message', 'ip', 'created_at',
])]
class IntegrationLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /**
     * @return Builder<IntegrationLog>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('onec.inbound.log_retention_days')));
    }
}
