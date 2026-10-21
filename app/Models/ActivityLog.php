<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One row of the append-only change log. Rows are never updated or deleted by the app.
 *
 * @property array{old?: array<string, mixed>, new?: array<string, mixed>}|array<string, mixed>|null $properties
 */
#[Fillable(['subject_type', 'subject_id', 'causer_id', 'event', 'properties'])]
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'activity_log';

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('The activity log is append-only.'));
        static::deleting(fn (): never => throw new LogicException('The activity log is append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Translated event label, falling back to the raw event name for unknown events.
     */
    public function eventLabel(): string
    {
        $key = 'activity.events.'.$this->event;

        return trans()->has($key) ? __($key) : $this->event;
    }

    /**
     * Changed fields as [field label, old, new] for the timeline.
     *
     * @return list<array{field: string, old: string, new: string}>
     */
    public function changes(): array
    {
        $properties = $this->properties ?? [];
        $new = $properties['new'] ?? [];
        $old = $properties['old'] ?? [];
        $rows = [];

        foreach ($new as $field => $value) {
            $key = 'activity.fields.'.$field;
            $rows[] = [
                'field' => trans()->has($key) ? __($key) : (string) $field,
                'old' => self::stringify($old[$field] ?? null),
                'new' => self::stringify($value),
            ];
        }

        foreach (array_diff_key($properties, array_flip(['old', 'new'])) as $field => $value) {
            $key = 'activity.fields.'.$field;
            $rows[] = ['field' => trans()->has($key) ? __($key) : (string) $field, 'old' => '', 'new' => self::stringify($value)];
        }

        return $rows;
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? '1' : '0',
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }
}
