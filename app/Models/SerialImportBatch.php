<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded serial file (Spec 06 21.8) with its per-row preview; committed once per line.
 */
#[Fillable([
    'context_type', 'context_id', 'file_name', 'file_hash', 'disk_path', 'size_bytes', 'format', 'encoding_detected',
    'delimiter_detected', 'rows_total', 'rows_ok', 'rows_warning', 'rows_error', 'mode', 'status', 'created_by', 'expires_at',
])]
class SerialImportBatch extends Model
{
    use HasUuids;

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
        return ['committed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    /**
     * @return HasMany<SerialImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(SerialImportRow::class, 'batch_id')->orderBy('row_no');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function committer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by');
    }
}
