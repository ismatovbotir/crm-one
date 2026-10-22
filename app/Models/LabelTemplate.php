<?php

namespace App\Models;

use App\Support\Labels\LabelLayout;
use Database\Factories\LabelTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A price-tag / label layout: physical size in millimetres and the positioned elements
 * (text, price, barcode, logo, line, box) built in Settings > Label templates.
 *
 * @property float $width_mm
 * @property float $height_mm
 * @property list<array<string, mixed>> $elements
 */
#[Fillable(['name', 'width_mm', 'height_mm', 'elements', 'is_default', 'created_by'])]
class LabelTemplate extends Model
{
    /** @use HasFactory<LabelTemplateFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width_mm' => 'float',
            'height_mm' => 'float',
            'elements' => 'array',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The template used when the print screen has no explicit choice: the flagged default, else the first.
     */
    public static function defaultTemplate(): ?self
    {
        return static::query()->orderByDesc('is_default')->orderBy('name')->first();
    }

    /**
     * Make this the only default template.
     */
    public function markAsDefault(): void
    {
        static::query()->where('id', '!=', $this->id)->where('is_default', true)->update(['is_default' => false]);
        $this->forceFill(['is_default' => true])->save();
    }

    /**
     * Replace the layout with a sanitised one (clamped to the label size).
     *
     * @param  array<int|string, mixed>  $elements
     */
    public function setLayout(array $elements): void
    {
        $this->elements = LabelLayout::sanitize($elements, $this->width_mm, $this->height_mm);
    }
}
