<?php

namespace App\Services\Notifications;

use App\Authorization\PermissionMap;
use App\Enums\InternalEvent;
use App\Enums\OfferStatus;
use App\Enums\TenderStatus;
use App\Models\Delivery;
use App\Models\Lead;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Tender;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use App\Notifications\NewTelegramLead;
use App\Notifications\OfferExpiring;
use App\Notifications\PaymentReceived;
use App\Notifications\ShippedUnpaid;
use App\Notifications\TenderDeadline;
use App\Services\Telegram\Internal\InternalNotifications;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sends the key events of the notification center (roadmap step 4). Recipients are the users the
 * policies allow to act (approvers) or the record owner; the actor never notifies themself.
 * Failures to deliver never break the business action (they are reported and swallowed).
 */
class Notifier
{
    public function offerApproval(Offer $offer, User $actor): void
    {
        $this->send($this->staff()->filter(fn (User $user): bool => $user->id !== $actor->id && $user->can('approve', $offer)),
            new ApprovalRequested('offer', $offer->displayNumber(), $actor->name, route('offers.show', $offer, false)));
    }

    public function tenderPublication(Tender $tender, User $actor): void
    {
        $this->send($this->staff()->filter(fn (User $user): bool => $user->id !== $actor->id && $user->can('approvePublication', $tender)),
            new ApprovalRequested('tender_publication', (string) $tender->number, $actor->name, route('tenders.show', $tender, false)));
    }

    public function tenderAward(Tender $tender, User $actor): void
    {
        $this->send($this->staff()->filter(fn (User $user): bool => $user->id !== $actor->id && $user->can('decideAward', $tender)),
            new ApprovalRequested('tender_award', (string) $tender->number, $actor->name, route('approvals.index', [], false)));
        app(InternalNotifications::class)->publish(InternalEvent::TenderAwardApproval, $tender, [], 'tender.award_approval:'.$tender->id.':'.now()->format('YmdHi'));
    }

    public function leadAssigned(Lead $lead, User $actor): void
    {
        if ($lead->owner_id !== null && $lead->owner_id !== $actor->id) {
            $this->send(User::query()->whereKey($lead->owner_id)->where('is_active', true)->get(), new NewTelegramLead($lead));
        }
    }

    public function paymentReceived(Order $order, int $amountMinor, ?User $actor): void
    {
        app(InternalNotifications::class)->publish(InternalEvent::PaymentReceived, $order, ['amount_minor' => $amountMinor], 'payment.received:'.$order->id.':'.$order->paid_minor.':'.$amountMinor.':'.now()->format('YmdHis'));

        if ($order->owner_id !== null && $order->owner_id !== $actor?->id) {
            $this->send(User::query()->whereKey($order->owner_id)->where('is_active', true)->get(), new PaymentReceived($order->fresh(), $amountMinor));
        }
    }

    /**
     * Spec 06 section 29: an order that is not fully paid was shipped — the accountants (and the
     * order owner) hear about it for follow-up. Company groups get the existing delivery event.
     */
    public function shippedUnpaid(Order $order, Delivery $delivery, ?User $actor): void
    {
        $recipients = $this->staff()->filter(fn (User $user): bool => $user->id !== $actor?->id
            && ($user->id === $order->owner_id || PermissionMap::allows($user, 'payments.record')));
        $this->send($recipients, new ShippedUnpaid($order->fresh(), $delivery));
    }

    /**
     * Daily scan: tenders closing within 24 h and open offers expiring within 2 days, each once.
     *
     * @return array{tenders: int, offers: int}
     */
    public function scanDeadlines(): array
    {
        $counts = ['tenders' => 0, 'offers' => 0];

        Tender::query()->where('status', TenderStatus::Published)->whereBetween('bids_due_at', [now(), now()->addDay()])->each(function (Tender $tender) use (&$counts): void {
            if (Cache::add('notify:tender-deadline:'.$tender->id.':'.$tender->bids_due_at?->timestamp, true, now()->addDays(3)) && $tender->owner_id !== null) {
                $this->send(User::query()->whereKey($tender->owner_id)->where('is_active', true)->get(), new TenderDeadline($tender));
                $counts['tenders']++;
            }

            if ($tender->bids_due_at !== null) {
                app(InternalNotifications::class)->publish(InternalEvent::TenderDeadline, $tender, ['deadline' => $tender->bids_due_at->timezone('Asia/Tashkent')->format('d.m.Y H:i')], 'tender.deadline:'.$tender->id.':'.$tender->bids_due_at->timestamp);
            }
        });

        Offer::query()->whereIn('status', [OfferStatus::Sent, OfferStatus::Viewed, OfferStatus::PartiallySelected])
            ->whereDate('valid_until', '<=', now()->addDays(2)->toDateString())->whereDate('valid_until', '>=', now()->toDateString())
            ->with('customer')->each(function (Offer $offer) use (&$counts): void {
                if (Cache::add('notify:offer-expiring:'.$offer->id.':'.$offer->valid_until?->toDateString(), true, now()->addDays(4))) {
                    $this->send(User::query()->whereKey($offer->owner_id)->where('is_active', true)->get(), new OfferExpiring($offer));
                    $counts['offers']++;
                }
            });

        return $counts;
    }

    /**
     * @return Collection<int, User>
     */
    protected function staff(): Collection
    {
        return User::query()->where('is_active', true)->get()->filter(fn (User $user): bool => $user->isInternal());
    }

    /**
     * @param  iterable<User>  $users
     */
    protected function send(iterable $users, object $notification): void
    {
        foreach ($users as $user) {
            try {
                $user->notify($notification);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
