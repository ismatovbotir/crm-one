<?php

namespace App\Models;

use App\Enums\OptionGroupKind;
use Database\Factories\OfferOptionGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Alternative or optional group of offer lines (Spec 05 7.3). `stable_key` survives revisions.
 *
 * @property OptionGroupKind $kind
 */
#[Fillable(['offer_id', 'stable_key', 'position', 'kind', 'title', 'description', 'min_select', 'max_select'])]
class OfferOptionGroup extends Model
{
    /** @use HasFactory<OfferOptionGroupFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => OptionGroupKind::class,
            'position' => 'integer',
            'min_select' => 'integer',
            'max_select' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class)->withTrashed();
    }

    /**
     * @return HasMany<OfferLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OfferLine::class, 'option_group_id')->orderBy('position')->orderBy('id');
    }

    public function isAlternative(): bool
    {
        return $this->kind === OptionGroupKind::Alternative;
    }
}
