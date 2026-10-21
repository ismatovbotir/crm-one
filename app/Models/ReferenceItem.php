<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Entry of a managed reference list (Spec 03 section 24.7): industry, currency, contact position,
 * supplier category. Names in uz/ru/en; entries are deactivated, never deleted while used.
 * `source`: seed | admin | user (typed in a form, reviewable) | legacy (migrated free text).
 *
 * @property array<string, string> $names
 */
#[Fillable(['list', 'code', 'names', 'is_active', 'sort_order', 'source', 'created_by'])]
class ReferenceItem extends Model
{
    use LogsActivity;

    public const LISTS = ['industry', 'currency', 'contact_position', 'supplier_category'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['names' => 'array', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function name(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return (string) ($this->names[$locale] ?? $this->names['uz'] ?? $this->names['en'] ?? $this->code);
    }

    /**
     * @param  Builder<ReferenceItem>  $query
     */
    public function scopeOfList(Builder $query, string $list): void
    {
        $query->where('list', $list)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Active entries of a list (plus the entry with $keepId, so a stored legacy value still shows).
     *
     * @return Collection<int, ReferenceItem>
     */
    public static function options(string $list, ?int $keepId = null): Collection
    {
        return self::query()->ofList($list)->where(fn (Builder $q) => $q->where('is_active', true)->when($keepId !== null, fn (Builder $w) => $w->orWhere('id', $keepId)))->get();
    }

    /** Used by a partner, supplier profile or contact (then it can only be deactivated). */
    public function isUsed(): bool
    {
        return match ($this->list) {
            'industry' => Partner::withTrashed()->where('industry_id', $this->id)->exists(),
            'supplier_category' => Counterparty::withTrashed()->where('supplier_category_id', $this->id)->exists(),
            'currency' => Partner::withTrashed()->where('currency', $this->code)->exists() || Offer::query()->where('currency', $this->code)->exists(),
            'contact_position' => Contact::withTrashed()->whereIn('position', array_values($this->names))->exists(),
            default => false,
        };
    }
}
