<?php

namespace App\Notifications;

use App\Enums\DunningStage;
use App\Enums\Locale;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Payment reminder e-mail to the customer's billing contact (Spec 06 BR-AC.17), only when
 * `accounting.customer_reminders_enabled` is on, in the order's document language. It names the
 * order, the amount and the due date; it never carries bank details.
 */
class CustomerPaymentReminder extends Notification
{
    public function __construct(public Order $order, public DunningStage $stage, public int $outstandingMinor, public string $dueOn, public int $daysOverdue, public Locale $language) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $this->language->value;
        $key = $this->stage->isOverdue() ? 'overdue' : 'pre_due';
        $params = [
            'number' => $this->order->number,
            'amount' => Money::of($this->outstandingMinor, $this->order->currency)->format(),
            'date' => $this->dueOn,
            'days' => $this->daysOverdue,
        ];

        return (new MailMessage)
            ->subject(__('accounting.dunning.mail.'.$key.'.subject', $params, $locale))
            ->line(__('accounting.dunning.mail.'.$key.'.body', $params, $locale))
            ->line(__('accounting.dunning.mail.ignore_if_paid', [], $locale));
    }
}
