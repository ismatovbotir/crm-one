<?php

namespace App\Livewire\Offers;

use App\Actions\Offers\ManageOptionGroups;
use App\Actions\Offers\MoveOfferBlock;
use App\Actions\Offers\RemoveOfferLine;
use App\Actions\Offers\UpdateOfferHeader;
use App\Actions\Offers\UpdateOfferLine;
use App\Enums\AlternativeReason;
use App\Enums\ItemAlternativeRelation;
use App\Enums\Locale;
use App\Enums\OptionGroupKind;
use App\Livewire\Concerns\HasDocumentTabs;
use App\Models\Contact;
use App\Models\Item;
use App\Models\Offer;
use App\Models\OfferLine;
use App\Models\OfferOptionGroup;
use App\Services\Offers\Calculation\CalculatorGroup;
use App\Services\Offers\Calculation\CalculatorLine;
use App\Services\Offers\DiscountRules;
use App\Services\Offers\Exceptions\OfferActionException;
use App\Services\Offers\OfferCalculator;
use App\Services\Offers\OfferValidator;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Offer builder (Spec 05 10.2): header, fixed lines, alternative and optional groups, live
 * server-side totals with the price range, validation list, approval hint and a customer preview
 * identical to what the customer sees. Every change goes through an Action (authorised,
 * recalculated server-side); client values are never trusted for totals (AC-9).
 *
 * Standard document tabs (user decision 2026-10-20): Header (header form), Items (catalog search
 * as the isolated Offers\ItemPicker, groups, lines, totals, checks), Customer preview and Other
 * (related documents, approvals, history). All tabs share this component's state, so unsaved
 * header or line edits survive a tab switch; the Header tab shows a marker while unsaved.
 */
#[Layout('layouts.app')]
class Builder extends Component
{
    use HasDocumentTabs;

    #[Locked]
    public int $offerId;

    #[Url(except: 'items')]
    public string $tab = 'items';

    /** Header form. */
    public ?int $contact_id = null;

    public string $currency = '';

    public string $valid_until = '';

    public string $payment_terms_days = '';

    public string $payment_terms_text = '';

    public string $delivery_terms_text = '';

    public string $warranty_text = '';

    public string $intro_text = '';

    public string $customer_notes = '';

    public string $internal_notes = '';

    public string $document_locale = '';

    #[Locked]
    public ?int $targetGroupId = null;

    /** @var array<int, array<string, mixed>> line id => editable values */
    public array $lineInputs = [];

    /** @var array<int, array<string, mixed>> group id => editable values */
    public array $groupInputs = [];

    /** Customer preview choices (never saved). @var array<int, int|string|list<int|string>> */
    public array $previewSelection = [];

    public function mount(Offer $offer): void
    {
        $this->authorize('update', $offer);

        $this->offerId = $offer->id;
        $this->syncHeader();
        $this->syncInputs();
    }

    #[Computed]
    public function offer(): Offer
    {
        return Offer::query()->with(['lines.item', 'lines.itemImage', 'groups', 'customer', 'contact', 'owner'])->findOrFail($this->offerId);
    }

    /**
     * @return SupportCollection<int, array<string, mixed>>
     */
    #[Computed]
    public function blocks(): SupportCollection
    {
        return $this->offer->blocks();
    }

    /**
     * @return Collection<int, Contact>
     */
    #[Computed]
    public function contacts(): Collection
    {
        return Contact::query()->where('partner_id', $this->offer->customer_id)->orderByDesc('is_primary')->orderBy('first_name')->get();
    }

    /**
     * @return list<array{code: string, key: string, level: string, params: array<string, string|int>}>
     */
    #[Computed]
    public function issues(): array
    {
        return app(OfferValidator::class)->validate($this->offer);
    }

    /**
     * @return array{required: bool, owner_limit: int, max: int, below_cost: bool, cost: int|null, margin: int|null}
     */
    #[Computed]
    public function approval(): array
    {
        $rules = app(DiscountRules::class);
        $offer = $this->offer;
        $selected = $offer->selectedLineIds();
        $basis = $offer->lines->filter(fn (OfferLine $line): bool => $line->option_group_id === null || in_array($line->id, $selected, true) || $line->is_recommended);
        $cost = $basis->contains(fn (OfferLine $line): bool => $line->cost_price_minor === null) ? null : $basis->sum(fn (OfferLine $line): int => (int) $line->costNetMinor());

        return [
            'required' => $rules->requiresApproval($offer),
            'owner_limit' => $rules->limitFor($offer->owner),
            'max' => $offer->max_discount_bp,
            'below_cost' => $rules->hasBelowCost($offer),
            'cost' => $cost,
            'margin' => $cost === null ? null : $basis->sum('line_net_minor') - $cost,
        ];
    }

    /**
     * Totals of the customer preview for the (unsaved) preview choices.
     *
     * @return array{fixed: int, min: int, max: int, recommended: int|null, selected: int, selected_net: int, selected_vat: int, complete: bool}
     */
    #[Computed]
    public function previewTotals(): array
    {
        $offer = $this->offer;
        $calculation = app(OfferCalculator::class)->calculate(
            $offer->lines->map(fn (OfferLine $line): CalculatorLine => new CalculatorLine($line->id, (string) $line->quantity, $line->unit_price_minor, $line->discount_bp, $line->tax_rate_bp, $line->option_group_id, $line->is_recommended, $line->list_unit_price_minor))->all(),
            $offer->groups->map(fn (OfferOptionGroup $group): CalculatorGroup => new CalculatorGroup($group->id, $group->kind, $group->min_select, $group->max_select))->all(),
            $offer->prices_include_vat,
            $this->previewSelectedIds(),
        );

        return [
            'fixed' => $calculation->fixedGross, 'min' => $calculation->rangeMinGross, 'max' => $calculation->rangeMaxGross,
            'recommended' => $calculation->recommendedGross, 'selected' => $calculation->selectedGross,
            'selected_net' => $calculation->selectedNet, 'selected_vat' => $calculation->selectedVat, 'complete' => $calculation->selectionComplete,
        ];
    }

    /**
     * @return list<int>
     */
    public function previewSelectedIds(): array
    {
        $valid = $this->offer->lines->whereNotNull('option_group_id')->pluck('id')->all();

        return collect($this->previewSelection)
            ->flatten()
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => in_array($id, $valid, true))
            ->unique()
            ->values()
            ->all();
    }

    public function saveHeader(UpdateOfferHeader $update): void
    {
        $offer = $this->authorizeEdit();

        $validated = $this->validate([
            'contact_id' => ['nullable', 'integer'],
            'currency' => ['required', 'in:'.implode(',', array_keys((array) config('crm.currencies')))],
            'valid_until' => ['required', 'date'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'payment_terms_text' => ['nullable', 'string', 'max:2000'],
            'delivery_terms_text' => ['nullable', 'string', 'max:2000'],
            'warranty_text' => ['nullable', 'string', 'max:2000'],
            'intro_text' => ['nullable', 'string', 'max:5000'],
            'customer_notes' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'document_locale' => ['nullable', 'in:'.implode(',', array_column(Locale::cases(), 'value'))],
        ], [], $this->headerAttributes());

        $this->run(fn () => $update->handle($offer, auth()->user(), [
            ...array_map(fn (mixed $value) => is_string($value) && trim($value) === '' ? null : $value, $validated),
            'currency' => $validated['currency'],
            'valid_until' => $validated['valid_until'],
        ]), 'offers.flash.header_saved');
    }

    public function targetGroup(?int $groupId): void
    {
        $this->targetGroupId = $groupId !== null && $this->offer->groups->contains('id', $groupId) ? $groupId : null;
    }

    /** The item picker added a line (Spec 05; bug fix 2026-10-20): refresh lines and totals only. */
    #[On('offer-line-added')]
    public function lineAdded(): void
    {
        session()->flash('status', __('offers.flash.line_added'));

        // Unsaved edits of the other lines and groups stay; only the new line gets its inputs.
        $lineInputs = $this->lineInputs;
        $groupInputs = $this->groupInputs;
        $this->refreshState(keepInputs: false);
        $this->lineInputs = array_replace($this->lineInputs, array_intersect_key($lineInputs, $this->lineInputs));
        $this->groupInputs = array_replace($this->groupInputs, array_intersect_key($groupInputs, $this->groupInputs));
    }

    /** True while the header form differs from the saved offer (marker on the Header tab). */
    public function headerIsDirty(): bool
    {
        $offer = $this->offer;
        $saved = [
            'contact_id' => (string) $offer->contact_id,
            'currency' => (string) $offer->currency,
            'valid_until' => $offer->valid_until->toDateString(),
            'payment_terms_days' => (string) $offer->payment_terms_days,
        ];

        foreach (['payment_terms_text', 'delivery_terms_text', 'warranty_text', 'intro_text', 'customer_notes', 'internal_notes', 'document_locale'] as $field) {
            $saved[$field] = (string) $offer->{$field};
        }

        foreach ($saved as $field => $value) {
            if (trim((string) $this->{$field}) !== trim($value)) {
                return true;
            }
        }

        return false;
    }

    protected function documentTabs(): array
    {
        return [
            'header' => $this->documentTab(__('common.document_tabs.header'), null, $this->headerIsDirty() ? __('common.document_tabs.unsaved') : null),
            'items' => $this->documentTab(__('common.document_tabs.items'), $this->offer->lines->count()),
            'preview' => __('common.document_tabs.preview'),
            'other' => __('common.document_tabs.other'),
        ];
    }

    protected function documentTabAliases(): array
    {
        return ['edit' => 'items'];
    }

    public function addGroup(string $kind, ManageOptionGroups $groups): void
    {
        $offer = $this->authorizeEdit();
        $groupKind = OptionGroupKind::tryFrom($kind);

        if ($groupKind === null || ! $groupKind->isGroup()) {
            return;
        }

        $this->run(function () use ($groups, $offer, $groupKind): void {
            $group = $groups->add($offer, $groupKind, '', auth()->user());
            $this->targetGroupId = $group->id;
        }, 'offers.flash.group_added');
    }

    /**
     * Saves one line when any of its inputs changes (wire:model.blur).
     */
    public function updatedLineInputs(mixed $value, string $key): void
    {
        [$lineId] = explode('.', $key);

        if (ctype_digit((string) $lineId)) {
            $this->saveLine((int) $lineId);
        }
    }

    public function saveLine(int $lineId): void
    {
        $offer = $this->authorizeEdit();
        $line = $offer->lines->firstWhere('id', $lineId);

        if ($line === null) {
            return;
        }

        $input = $this->lineInputs[$lineId] ?? [];
        $data = [];

        try {
            $data['unit_price_minor'] = Money::fromMajor((string) ($input['unit_price'] ?? ''), $offer->currency)->amount;
        } catch (InvalidArgumentException) {
            $this->addError("lineInputs.{$lineId}.unit_price", __('offers.errors.price_format'));

            return;
        }

        $discount = str_replace(',', '.', trim((string) ($input['discount'] ?? '0')));

        if ($discount === '') {
            $discount = '0';
        }

        if (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $discount) !== 1 || bccomp($discount, '100', 2) > 0) {
            $this->addError("lineInputs.{$lineId}.discount", __('offers.errors.discount_range'));

            return;
        }

        $leadTime = trim((string) ($input['lead_time_days'] ?? ''));

        if ($leadTime !== '' && (! ctype_digit($leadTime) || (int) $leadTime > 3650)) {
            $this->addError("lineInputs.{$lineId}.lead_time_days", __('offers.errors.lead_time'));

            return;
        }

        $data += [
            'quantity' => (string) ($input['quantity'] ?? ''),
            'discount_bp' => (int) bcmul($discount, '100', 0),
            'lead_time_days' => $leadTime === '' ? null : (int) $leadTime,
            'alternative_reason' => filled($input['alternative_reason'] ?? null) ? (string) $input['alternative_reason'] : null,
            'alternative_reason_note' => $this->text($input['alternative_reason_note'] ?? null, 255),
            'comparison_notes' => $this->text($input['comparison_notes'] ?? null, 2000),
            'internal_note' => $this->text($input['internal_note'] ?? null, 2000),
        ];

        $this->resetErrorBag("lineInputs.{$lineId}.*");
        $this->run(fn () => app(UpdateOfferLine::class)->handle($line, auth()->user(), $data), null, null, "lineInputs.{$lineId}.quantity");
    }

    public function setRecommended(int $lineId): void
    {
        $offer = $this->authorizeEdit();
        $line = $offer->lines->firstWhere('id', $lineId);

        if ($line !== null) {
            $this->run(fn () => app(UpdateOfferLine::class)->handle($line, auth()->user(), ['is_recommended' => ! $line->is_recommended]));
        }
    }

    public function removeLine(int $lineId, RemoveOfferLine $remove): void
    {
        $offer = $this->authorizeEdit();
        $line = $offer->lines->firstWhere('id', $lineId);

        if ($line !== null) {
            $this->run(fn () => $remove->handle($line, auth()->user()), 'offers.flash.line_removed');
        }
    }

    public function saveGroup(int $groupId, ManageOptionGroups $groups): void
    {
        $offer = $this->authorizeEdit();
        $group = $offer->groups->firstWhere('id', $groupId);
        $input = $this->groupInputs[$groupId] ?? [];

        if ($group === null) {
            return;
        }

        if (! ctype_digit((string) ($input['min_select'] ?? '')) || ! ctype_digit((string) ($input['max_select'] ?? ''))) {
            $this->addError("groupInputs.{$groupId}.min_select", __('offers.errors.min_max_invalid', ['count' => $offer->lines->where('option_group_id', $groupId)->count()]));

            return;
        }

        $this->run(fn () => $groups->update($group, [
            'title' => (string) ($input['title'] ?? ''),
            'description' => $this->text($input['description'] ?? null, 2000),
            'min_select' => (int) $input['min_select'],
            'max_select' => (int) $input['max_select'],
        ], auth()->user()), 'offers.flash.group_saved', null, "groupInputs.{$groupId}.min_select");
    }

    public function removeGroup(int $groupId, ManageOptionGroups $groups): void
    {
        $offer = $this->authorizeEdit();
        $group = $offer->groups->firstWhere('id', $groupId);

        if ($group !== null) {
            $this->targetGroupId = $this->targetGroupId === $groupId ? null : $this->targetGroupId;
            $this->run(fn () => $groups->remove($group, auth()->user()), 'offers.flash.group_removed');
        }
    }

    public function convertToAlternatives(int $lineId, ManageOptionGroups $groups): void
    {
        $offer = $this->authorizeEdit();
        $line = $offer->lines->firstWhere('id', $lineId);

        if ($line !== null) {
            $this->run(function () use ($groups, $line): void {
                $this->targetGroupId = $groups->convertToAlternatives($line, auth()->user())->id;
            }, 'offers.flash.group_added');
        }
    }

    public function ungroup(int $groupId, ManageOptionGroups $groups): void
    {
        $offer = $this->authorizeEdit();
        $group = $offer->groups->firstWhere('id', $groupId);

        if ($group !== null) {
            $this->targetGroupId = null;
            $this->run(fn () => $groups->ungroup($group, auth()->user()), 'offers.flash.group_removed');
        }
    }

    public function moveBlock(string $type, int $id, int $direction, MoveOfferBlock $move): void
    {
        $offer = $this->authorizeEdit();

        if (in_array($type, ['line', 'group'], true)) {
            $this->run(fn () => $move->block($offer, $type, $id, $direction, auth()->user()));
        }
    }

    public function moveOption(int $lineId, int $direction, MoveOfferBlock $move): void
    {
        $offer = $this->authorizeEdit();
        $line = $offer->lines->firstWhere('id', $lineId);

        if ($line !== null) {
            $this->run(fn () => $move->option($line, $direction, auth()->user()));
        }
    }

    /**
     * Runs an action, maps business errors to a flash (or field) error, refreshes state.
     */
    protected function run(callable $action, ?string $successKey = null, ?string $reset = null, ?string $errorField = null): void
    {
        try {
            $action();
        } catch (OfferActionException $exception) {
            $errorField !== null ? $this->addError($errorField, $exception->getMessage()) : session()->flash('error', $exception->getMessage());
            $this->refreshState(keepInputs: $errorField !== null);

            return;
        }

        if ($successKey !== null) {
            session()->flash('status', __($successKey));
        }

        if ($reset !== null) {
            $this->reset($reset);
        }

        $this->refreshState();
    }

    protected function refreshState(bool $keepInputs = false): void
    {
        unset($this->offer, $this->blocks, $this->issues, $this->approval, $this->previewTotals);

        if (! $this->offer->status->isEditable()) {
            $this->redirectRoute('offers.show', $this->offer, navigate: true);

            return;
        }

        if (! $keepInputs) {
            $this->syncInputs();
        }
    }

    protected function authorizeEdit(): Offer
    {
        $offer = $this->offer;
        $this->authorize('update', $offer);

        return $offer;
    }

    protected function syncHeader(): void
    {
        $offer = $this->offer;
        $this->contact_id = $offer->contact_id;
        $this->currency = $offer->currency;
        $this->valid_until = $offer->valid_until->toDateString();
        $this->payment_terms_days = (string) $offer->payment_terms_days;

        foreach (['payment_terms_text', 'delivery_terms_text', 'warranty_text', 'intro_text', 'customer_notes', 'internal_notes', 'document_locale'] as $field) {
            $this->{$field} = (string) $offer->{$field};
        }
    }

    protected function syncInputs(): void
    {
        $this->lineInputs = [];
        $this->groupInputs = [];

        foreach ($this->offer->lines as $line) {
            $this->lineInputs[$line->id] = [
                'quantity' => rtrim(rtrim((string) $line->quantity, '0'), '.'),
                'unit_price' => Money::of($line->unit_price_minor, $this->offer->currency)->toMajor(),
                'discount' => rtrim(rtrim(bcdiv((string) $line->discount_bp, '100', 2), '0'), '.'),
                'lead_time_days' => (string) $line->lead_time_days,
                'alternative_reason' => (string) $line->alternative_reason?->value,
                'alternative_reason_note' => (string) $line->alternative_reason_note,
                'comparison_notes' => (string) $line->comparison_notes,
                'internal_note' => (string) $line->internal_note,
            ];
        }

        foreach ($this->offer->groups as $group) {
            $this->groupInputs[$group->id] = [
                'title' => $group->title,
                'description' => (string) $group->description,
                'min_select' => (string) $group->min_select,
                'max_select' => (string) $group->max_select,
            ];
        }
    }

    protected function text(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * @return array<string, string>
     */
    protected function headerAttributes(): array
    {
        $attributes = [];

        foreach (['contact_id', 'currency', 'valid_until', 'payment_terms_days', 'payment_terms_text', 'delivery_terms_text', 'warranty_text', 'intro_text', 'customer_notes', 'internal_notes', 'document_locale'] as $field) {
            $attributes[$field] = __('offers.fields.'.$field);
        }

        return $attributes;
    }

    public function render(): View
    {
        return view('livewire.offers.builder', [
            'reasons' => AlternativeReason::options(),
            'locales' => Locale::available(),
            'currencies' => array_keys((array) config('crm.currencies')),
            'relations' => ItemAlternativeRelation::options(),
        ])->title(__('offers.builder.title', ['number' => $this->offer->displayNumber()]));
    }
}
