<?php

namespace App\Notifications;

use App\Enums\Locale;
use App\Enums\NotificationEvent;
use App\Models\User;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base of all notification-center messages (roadmap step 4): one event, a title and a body as
 * translation keys with parameters (rendered in the reader's language: in-app at display time,
 * e-mail and Telegram in the recipient's language), and a link. Channels follow the preferences.
 */
abstract class CrmNotification extends Notification
{
    abstract public function event(): NotificationEvent;

    /**
     * Message parameters, translated where needed into $locale.
     *
     * @return array<string, string|int>
     */
    abstract protected function params(string $locale): array;

    abstract protected function path(): string;

    /** Message keys under notifications.messages (default: the event; variants override). */
    public function messageKey(): string
    {
        return $this->event()->value;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? app(NotificationPreferences::class)->channels($notifiable, $this->event()) : ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['event' => $this->messageKey(), 'params' => $this->params(Locale::default()->value), 'url' => $this->path()];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $this->localeOf($notifiable);

        return (new MailMessage)
            ->subject(__('notifications.messages.'.$this->messageKey().'.title', $this->params($locale), $locale))
            ->line(__('notifications.messages.'.$this->messageKey().'.body', $this->params($locale), $locale))
            ->action(__('notifications.open', [], $locale), url($this->path()));
    }

    public function toTelegram(object $notifiable): string
    {
        $locale = $this->localeOf($notifiable);
        $params = array_map(fn ($value): string => e((string) $value), $this->params($locale));

        return '<b>'.__('notifications.messages.'.$this->messageKey().'.title', $params, $locale).'</b>'."\n"
            .__('notifications.messages.'.$this->messageKey().'.body', $params, $locale)."\n".e(url($this->path()));
    }

    protected function localeOf(object $notifiable): string
    {
        return ($notifiable instanceof User && $notifiable->locale instanceof Locale ? $notifiable->locale : Locale::default())->value;
    }
}
