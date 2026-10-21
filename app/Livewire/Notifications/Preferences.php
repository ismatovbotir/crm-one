<?php

namespace App\Livewire\Notifications;

use App\Enums\NotificationEvent;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Notification preferences (roadmap step 4): per event, e-mail and Telegram on or off; in-app is
 * always on. Telegram choices apply only while the account is linked.
 */
#[Layout('layouts.app')]
class Preferences extends Component
{
    /** @var array<string, array<string, bool>> */
    public array $choices = [];

    public function mount(NotificationPreferences $preferences): void
    {
        foreach (NotificationEvent::cases() as $event) {
            foreach (NotificationEvent::CHANNELS as $channel) {
                $this->choices[$event->value][$channel] = $preferences->wants(auth()->user(), $event, $channel);
            }
        }
    }

    public function save(NotificationPreferences $preferences): void
    {
        $preferences->save(auth()->user(), $this->choices);
        session()->flash('status', __('notifications.preferences.saved'));
    }

    public function render(NotificationPreferences $preferences): View
    {
        return view('livewire.notifications.preferences', [
            'events' => NotificationEvent::cases(),
            'telegramLinked' => $preferences->telegramAvailable(auth()->user()),
        ])->title(__('notifications.preferences.title'));
    }
}
