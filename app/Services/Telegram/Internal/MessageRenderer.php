<?php

namespace App\Services\Telegram\Internal;

use App\Enums\DiscrepancyReason;
use App\Enums\InternalEvent;
use App\Models\GoodsReceipt;
use App\Models\Lead;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Requisition;
use App\Models\ServiceTicket;
use App\Models\Tender;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

/**
 * Builds internal Telegram messages (Spec 03 section 23.3) in Telegram HTML parse mode:
 *  - minimal: event, number, status and a deep link to the CRM record (login required);
 *  - standard: adds customer/supplier name, owner, up to five item names with quantities and a
 *    truncated, scrubbed excerpt of customer-provided text;
 *  - amounts only when the destination includes them.
 * Never: phones, e-mails, serial numbers, bank details, tokens or portal codes. Everything dynamic
 * is HTML-escaped; the static markup is limited to <b> and <a>.
 */
class MessageRenderer
{
    public const EXCERPT_LENGTH = 200;

    public const MAX_ITEMS = 5;

    /** Telegram's limit is 4096 characters per message; leave room for the part marker. */
    public const MAX_LENGTH = 4000;

    /**
     * @param  array<string, mixed>  $context
     */
    public function render(InternalEvent $event, ?Model $subject, array $context, string $level, bool $amounts, string $locale): string
    {
        $previous = App::getLocale();
        App::setLocale($locale);

        try {
            $lines = ['<b>'.e($event->label($locale)).'</b>'];
            $lines = [...$lines, ...match (true) {
                $event->isReport() => $this->report($context, $level, $amounts),
                $subject instanceof Lead => $this->lead($subject, $level, $amounts),
                $subject instanceof Requisition => $this->requisition($subject, $level),
                $subject instanceof Offer => $this->offer($subject, $level, $amounts),
                $subject instanceof Order => $this->order($event, $subject, $context, $level, $amounts),
                $subject instanceof ServiceTicket => $this->ticket($subject, $level),
                $subject instanceof Tender => $this->tender($subject, $context, $level, $amounts),
                $subject instanceof GoodsReceipt => $this->receipt($event, $subject, $level),
                default => [],
            }];

            $url = $this->url($event, $subject);

            if ($url !== null) {
                $lines[] = '<a href="'.e($url).'">'.e(__('telegram.internal.open', [], $locale)).'</a>';
            }

            return implode("\n", array_filter($lines, fn (?string $line): bool => $line !== null && $line !== ''));
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * Splits on line boundaries so every part stays under the limit and keeps its tags closed
     * (each line is self-contained). A single overlong line is cut hard.
     *
     * @return list<string>
     */
    public static function split(string $html, int $limit = self::MAX_LENGTH): array
    {
        $parts = [];
        $current = '';

        foreach (explode("\n", $html) as $line) {
            if (mb_strlen($line) > $limit) {
                $line = e(mb_substr(strip_tags(html_entity_decode($line)), 0, $limit - 20)).'…';
            }

            if ($current !== '' && mb_strlen($current) + 1 + mb_strlen($line) > $limit) {
                $parts[] = $current;
                $current = $line;
            } else {
                $current = $current === '' ? $line : $current."\n".$line;
            }
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * Customer-provided text: no e-mails or phone numbers, single line, truncated, escaped.
     */
    public static function excerpt(?string $text): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        if ($text === '') {
            return null;
        }

        $text = (string) preg_replace('/[^\s@]+@[^\s@]+\.[^\s@]+/u', '[…]', $text);
        $text = (string) preg_replace('/\+?\d[\d\s\-()]{6,}\d/u', '[…]', $text);

        return '«'.e(Str::limit($text, self::EXCERPT_LENGTH, '…')).'»';
    }

    /**
     * @return list<string|null>
     */
    protected function lead(Lead $lead, string $level, bool $amounts): array
    {
        $lines = [e($lead->number).' · '.e($lead->status->label()).' · '.e($lead->source->label())];

        if ($level === 'standard') {
            $lines[] = $this->labelled('company', $lead->companyLabel());
            $lines[] = $this->labelled('owner', $lead->owner?->name ?? __('leads.unassigned'));
            $lines = [...$lines, ...$this->items($lead->items()->with('item.unit')->limit(self::MAX_ITEMS + 1)->get()->map(fn ($row): array => [$row->item?->name, (string) $row->quantity, $row->item?->unit?->code])->all())];
            $lines[] = self::excerpt($lead->need);
        }

        if ($amounts && $lead->est_value_minor !== null) {
            $lines[] = $this->labelled('amount', Money::of($lead->est_value_minor, $lead->currency)->format());
        }

        return $lines;
    }

    /**
     * @return list<string|null>
     */
    protected function requisition(Requisition $requisition, string $level): array
    {
        $lines = [e((string) $requisition->number).' · '.e($requisition->status->label())];

        if ($level === 'standard') {
            $lines[] = $this->labelled('customer', $requisition->organization?->name);
            $lines[] = $this->labelled('owner', $requisition->owner?->name);
            $lines = [...$lines, ...$this->items($requisition->lines()->limit(self::MAX_ITEMS + 1)->get()->map(fn ($line): array => [$line->label(), (string) $line->quantity, $line->unit])->all())];
            $lines[] = self::excerpt($requisition->notes);
        }

        return $lines;
    }

    /**
     * @return list<string|null>
     */
    protected function offer(Offer $offer, string $level, bool $amounts): array
    {
        $lines = [e($offer->displayNumber()).' · '.e($offer->status->label())];

        if ($level === 'standard') {
            $lines[] = $this->labelled('customer', $offer->customer?->name);
            $lines[] = $this->labelled('owner', $offer->owner?->name);
            $lines = [...$lines, ...$this->items($offer->lines()->limit(self::MAX_ITEMS + 1)->get()->map(fn ($line): array => [$line->name, (string) $line->quantity, $line->unit_code])->all())];
        }

        if ($amounts) {
            $lines[] = $this->labelled('amount', Money::of((int) ($offer->selected_gross_minor ?: $offer->fixed_gross_minor), $offer->currency)->format());
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<string|null>
     */
    protected function order(InternalEvent $event, Order $order, array $context, string $level, bool $amounts): array
    {
        $lines = [e((string) $order->number).' · '.e($order->status->label())];

        if ($event === InternalEvent::InvoiceOverdue && filled($context['invoice'] ?? null)) {
            $lines[] = $this->labelled('invoice', (string) $context['invoice']);
        }

        if ($level === 'standard') {
            $lines[] = $this->labelled('customer', $order->customer?->name);
            $lines[] = $this->labelled('owner', $order->owner?->name);

            if ($event !== InternalEvent::PaymentReceived && $event !== InternalEvent::InvoiceOverdue) {
                $lines = [...$lines, ...$this->items($order->lines()->limit(self::MAX_ITEMS + 1)->get()->map(fn ($line): array => [$line->name, (string) $line->quantity, $line->unit_code])->all())];
            }
        }

        if ($amounts) {
            $lines[] = match ($event) {
                InternalEvent::PaymentReceived => $this->labelled('amount', Money::of((int) ($context['amount_minor'] ?? 0), $order->currency)->format()),
                InternalEvent::InvoiceOverdue => $this->labelled('outstanding', Money::of(max(0, (int) $order->gross_minor - (int) $order->paid_minor), $order->currency)->format()),
                default => $this->labelled('amount', Money::of((int) $order->gross_minor, $order->currency)->format()),
            };
        }

        return $lines;
    }

    /**
     * @return list<string|null>
     */
    protected function ticket(ServiceTicket $ticket, string $level): array
    {
        $lines = [e((string) $ticket->number).' · '.e($ticket->status->label())];

        if ($level === 'standard') {
            $lines[] = $this->labelled('customer', $ticket->organization?->name);
            // Model name only, never the serial number.
            $lines[] = $this->labelled('equipment', $ticket->asset?->displayName());
            $lines[] = self::excerpt($ticket->problem);
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<string|null>
     */
    protected function tender(Tender $tender, array $context, string $level, bool $amounts): array
    {
        $lines = [e((string) $tender->number).' · '.e($tender->status->label())];

        if ($level === 'standard') {
            $lines[] = e(Str::limit((string) $tender->title, 150));
            $lines[] = $this->labelled('owner', $tender->owner?->name);
        }

        if (isset($context['deadline'])) {
            $lines[] = $this->labelled('deadline', (string) $context['deadline']);
        }

        if ($amounts && $tender->budget_minor !== null) {
            $lines[] = $this->labelled('amount', Money::of((int) $tender->budget_minor, (string) ($tender->budget_currency ?? config('crm.base_currency')))->format());
        }

        return $lines;
    }

    /**
     * @return list<string|null>
     */
    protected function receipt(InternalEvent $event, GoodsReceipt $receipt, string $level): array
    {
        $lines = [e((string) $receipt->number).' · '.e(__('telegram.internal.posted'))];
        $receiptLines = $receipt->lines()->with('item:id,name')->orderBy('position')->get();

        if ($event === InternalEvent::ReceiptDiscrepancy) {
            $discrepant = $receiptLines->whereNotNull('discrepancy_reason');
            $lines[] = $this->labelled('discrepancies', (string) $discrepant->count());

            if ($level === 'standard') {
                foreach ($discrepant->take(self::MAX_ITEMS) as $line) {
                    $reason = DiscrepancyReason::tryFrom((string) $line->discrepancy_reason)?->label() ?? (string) $line->discrepancy_reason;
                    $lines[] = '• '.e((string) $line->item?->name).': '.e(Quantity::trimmed((string) $line->expected_qty)).' → '.e(Quantity::trimmed((string) $line->received_qty)).' ('.e($reason).')';
                }
            }

            return $lines;
        }

        if ($level === 'standard') {
            $lines[] = $this->labelled('supplier', $receipt->counterparty_name);
            $lines = [...$lines, ...$this->items($receiptLines->take(self::MAX_ITEMS + 1)->map(fn ($line): array => [$line->item?->name, (string) $line->received_qty, null])->all())];
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $context  from ReportBuilder
     * @return list<string|null>
     */
    protected function report(array $context, string $level, bool $amounts): array
    {
        $lines = [e((string) ($context['period'] ?? ''))];

        foreach ((array) ($context['sections'] ?? []) as $section => $rows) {
            $lines[] = '<b>'.e(__('telegram.internal.report.'.$section)).'</b>';

            foreach ((array) $rows as $key => $value) {
                $isAmount = is_array($value) && isset($value['minor']);

                if ($isAmount && ! $amounts) {
                    continue;
                }

                if ($level === 'minimal' && str_starts_with((string) $key, 'detail_')) {
                    continue;
                }

                $display = $isAmount ? Money::of((int) $value['minor'], (string) $value['currency'])->format() : (string) $value;
                $lines[] = '• '.e(__('telegram.internal.report.rows.'.$key)).': '.e($display);
            }
        }

        return $lines;
    }

    /**
     * @param  list<array{0: string|null, 1: string, 2: string|null}>  $items
     * @return list<string>
     */
    protected function items(array $items): array
    {
        $lines = [];

        foreach (array_slice($items, 0, self::MAX_ITEMS) as [$name, $quantity, $unit]) {
            $lines[] = '• '.e(Str::limit((string) $name, 80)).' × '.e(Quantity::trimmed($quantity)).($unit ? ' '.e((string) $unit) : '');
        }

        if (count($items) > self::MAX_ITEMS) {
            $lines[] = '• …';
        }

        return $lines;
    }

    protected function labelled(string $key, ?string $value): ?string
    {
        return filled($value) ? e(__('telegram.internal.fields.'.$key)).': '.e(Str::limit((string) $value, 120)) : null;
    }

    /** Absolute deep link (route-generated); the CRM asks for login. */
    public function url(InternalEvent $event, ?Model $subject): ?string
    {
        if ($event->isReport()) {
            return route('dashboard');
        }

        return match (true) {
            $subject instanceof Lead => route('leads.show', $subject),
            $subject instanceof Requisition => route('requisitions.show', $subject),
            $subject instanceof Offer => route('offers.show', $subject),
            $subject instanceof Order => route('orders.show', $subject),
            $subject instanceof ServiceTicket => route('service.tickets.show', $subject),
            $subject instanceof Tender => route('tenders.show', $subject),
            $subject instanceof GoodsReceipt => route('goods-receipts.show', $subject),
            default => null,
        };
    }
}
