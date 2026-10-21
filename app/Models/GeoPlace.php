<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Region (viloyat) or city/district of Uzbekistan (Spec 03 section 24.7), names in uz/ru/en.
 *
 * @property array<string, string> $names
 */
#[Fillable(['kind', 'code', 'parent_id', 'country', 'names', 'sort_order', 'is_active'])]
class GeoPlace extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['names' => 'array', 'is_active' => 'boolean'];
    }

    public function name(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return (string) ($this->names[$locale] ?? $this->names['uz'] ?? $this->code);
    }

    /**
     * @return Collection<int, GeoPlace>
     */
    public static function regions(): Collection
    {
        return self::query()->where('kind', 'region')->where('is_active', true)->orderBy('sort_order')->get();
    }

    /**
     * @return Collection<int, GeoPlace>
     */
    public static function citiesOf(?int $regionId): Collection
    {
        return $regionId === null ? collect() : self::query()->where('kind', 'city')->where('parent_id', $regionId)->where('is_active', true)->orderBy('sort_order')->get();
    }
}
