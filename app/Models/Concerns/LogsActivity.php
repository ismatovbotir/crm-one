<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;
use App\Services\OneC\Inbound\OneCSyncContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Writes the append-only change log (Spec 01 BR-14): who did what and, for updates,
 * old/new values of the changed attributes only. Secrets and bookkeeping columns are excluded.
 *
 * @mixin Model
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(function (Model $model): void {
            /** @var static $model */
            if (OneCSyncContext::active()) {
                return; // Spec 02 7.3: creations from 1C are audited by the inbound batch row.
            }

            $model->logActivity($model->activityEventForCreate(), ['new' => $model->activityFilter($model->getAttributes())]);
        });

        static::updated(function (Model $model): void {
            /** @var static $model */
            $changed = $model->activityFilter($model->getChanges());

            if ($changed === []) {
                return;
            }

            $old = [];

            foreach (array_keys($changed) as $key) {
                $old[$key] = $model->getRawOriginal($key);
            }

            $event = $model->activityEventForUpdate(array_keys($changed));

            if (OneCSyncContext::active()) {
                $event = OneCSyncContext::eventFor($event, $changed);
            }

            $model->logActivity($event, ['old' => $old, 'new' => $changed]);
        });

        static::deleted(function (Model $model): void {
            /** @var static $model */
            $softDeleted = in_array(SoftDeletes::class, class_uses_recursive($model), true) && ! $model->isForceDeleting();

            $model->logActivity($softDeleted ? 'archived' : 'deleted');
        });

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restored(function (Model $model): void {
                /** @var static $model */
                $model->logActivity('restored');
            });
        }
    }

    /**
     * @return MorphMany<ActivityLog, $this>
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'subject')->latest('created_at')->latest('id');
    }

    /**
     * Append a row to the change log for this record, attributed to the signed-in user (null = system).
     *
     * @param  array<string, mixed>|null  $properties
     */
    public function logActivity(string $event, ?array $properties = null): ActivityLog
    {
        return ActivityLog::query()->create([
            'subject_type' => $this->getMorphClass(),
            'subject_id' => $this->getKey(),
            'causer_id' => auth()->id(),
            'event' => $event,
            'properties' => $properties,
        ]);
    }

    /**
     * Event name for a creation; models override it (e.g. `api_token_created`).
     */
    protected function activityEventForCreate(): string
    {
        return 'created';
    }

    /**
     * Event name for an update; models override it to emit e.g. `owner_changed` or `price_changed`.
     *
     * @param  list<string>  $changedKeys
     */
    protected function activityEventForUpdate(array $changedKeys): string
    {
        if ($changedKeys === ['is_active']) {
            return $this->getAttribute('is_active') ? 'activated' : 'deactivated';
        }

        return 'updated';
    }

    /**
     * Attributes never written to the log.
     *
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function activityFilter(array $attributes): array
    {
        $excluded = [
            'id', 'created_at', 'updated_at', 'deleted_at', 'password', 'remember_token',
            ...$this->getHidden(),
            ...$this->activityExcept(),
        ];

        return array_diff_key($attributes, array_flip($excluded));
    }
}
