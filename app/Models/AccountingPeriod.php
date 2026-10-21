<?php

namespace App\Models;

use App\Enums\PeriodStatus;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Month of the books (Spec 06 7.14): no row = open; locked by finance, reopened with a reason.
 *
 * @property PeriodStatus $status
 */
#[Fillable(['period', 'status', 'locked_by', 'locked_at', 'reopened_by', 'reopened_at', 'reopen_reason', 'checklist'])]
class AccountingPeriod extends Model
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PeriodStatus::class,
            'locked_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
            'checklist' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
