<?php

namespace App\Models;

use App\Enums\CriterionMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evaluation criterion with weight in basis points (sum 10000); locked after publishing (BR-T.3).
 *
 * @property CriterionMethod $method
 */
#[Fillable(['tender_id', 'code', 'label', 'weight_bp', 'method', 'position'])]
class TenderCriterion extends Model
{
    protected $table = 'tender_criteria';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['method' => CriterionMethod::class, 'weight_bp' => 'integer', 'position' => 'integer'];
    }

    /**
     * @return BelongsTo<Tender, $this>
     */
    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class)->withTrashed();
    }

    public function displayLabel(): string
    {
        if (filled($this->label)) {
            return (string) $this->label;
        }

        return trans()->has('tenders.criteria.'.$this->code) ? __('tenders.criteria.'.$this->code) : $this->code;
    }
}
