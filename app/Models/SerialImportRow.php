<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Preview result of one imported row (Spec 06 21.8): ok, warning or error code with a message.
 */
#[Fillable(['batch_id', 'row_no', 'serial_raw', 'serial_normalized', 'warranty_until', 'note', 'is_damaged', 'result', 'message'])]
class SerialImportRow extends Model
{
    public $timestamps = false;

    /** Result codes that still allow the row to be committed. */
    public const OK_RESULTS = ['ok', 'numeric_cell', 'already_in_document'];

    public function isCommittable(): bool
    {
        return in_array($this->result, ['ok', 'numeric_cell'], true);
    }
}
