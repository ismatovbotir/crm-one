<?php

namespace App\Models;

use App\Enums\InternalEvent;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company Telegram group (optionally one forum topic) the bot posts internal notifications to
 * (Spec 03 section 23.1). `events` maps an event value to its content level (minimal|standard);
 * a missing event is not posted. Amounts appear only when `include_amounts` is on. Every change
 * is audited.
 *
 * @property array<string, string>|null $events
 */
#[Fillable(['name', 'chat_id', 'message_thread_id', 'locale', 'include_amounts', 'events', 'is_active', 'last_success_at', 'last_error', 'last_error_at', 'created_by', 'updated_by'])]
class TelegramDestination extends Model
{
    use LogsActivity;

    public const LEVELS = ['minimal', 'standard'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['locale' => 'uz', 'include_amounts' => false, 'is_active' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'message_thread_id' => 'integer',
            'include_amounts' => 'boolean',
            'is_active' => 'boolean',
            'events' => 'array',
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    /** Content level for the event, or null when the event is not routed here. */
    public function levelFor(InternalEvent $event): ?string
    {
        $level = $this->events[$event->value] ?? null;

        return in_array($level, self::LEVELS, true) ? $level : null;
    }

    /**
     * Default routing for a new destination: every event at the minimal level, except the weekly
     * report (Spec 03 section 23.6).
     *
     * @return array<string, string>
     */
    public static function defaultEvents(): array
    {
        $events = [];

        foreach (InternalEvent::cases() as $event) {
            if ($event !== InternalEvent::ReportWeekly) {
                $events[$event->value] = 'minimal';
            }
        }

        return $events;
    }

    public function chat(): ?TelegramChat
    {
        return TelegramChat::query()->where('chat_id', $this->chat_id)->first();
    }

    /**
     * @return HasMany<TelegramPost, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(TelegramPost::class);
    }

    /**
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return ['last_success_at', 'last_error', 'last_error_at', 'updated_by'];
    }
}
