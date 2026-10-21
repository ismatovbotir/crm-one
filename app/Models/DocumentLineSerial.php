<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A serial row of a stock document line (Spec 06 21.5): `draft` while the document is a draft,
 * `posted` (immutable) after posting, `replaced` / `voided` when taken out. `active_key` (unique)
 * keeps one serial once per document.
 */
#[Fillable([
    'document_type', 'document_id', 'line_type', 'line_id', 'item_id', 'item_serial_id', 'serial_number', 'serial_raw', 'status',
    'replaced_by_id', 'active_key', 'position', 'import_batch_id', 'created_by', 'created_at', 'is_damaged', 'damage_note',
])]
class DocumentLineSerial extends Model
{
    public const UPDATED_AT = null;

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    public const REPLACED = 'replaced';

    public const VOIDED = 'voided';

    protected static function booted(): void
    {
        static::updating(function (DocumentLineSerial $row): void {
            if ($row->getRawOriginal('status') === self::POSTED && $row->isDirty(['serial_number', 'item_serial_id', 'item_id', 'line_id', 'is_damaged', 'damage_note'])) {
                throw new LogicException('Posted serial rows are immutable.');
            }
        });
        static::deleting(function (DocumentLineSerial $row): void {
            if ($row->getRawOriginal('status') !== self::DRAFT) {
                throw new LogicException('Only draft serial rows can be removed.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'is_damaged' => 'boolean'];
    }

    public static function activeKey(string $documentType, int $documentId, int $itemId, string $serial): string
    {
        return $documentType.':'.$documentId.':'.$itemId.':'.$serial;
    }

    /**
     * @return BelongsTo<ItemSerial, $this>
     */
    public function serial(): BelongsTo
    {
        return $this->belongsTo(ItemSerial::class, 'item_serial_id');
    }
}
