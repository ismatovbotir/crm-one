<?php

namespace App\Livewire\Agreements;

use App\Actions\Agreements\CancelAgreement;
use App\Actions\Agreements\GenerateAgreement;
use App\Actions\Agreements\RecordSignature;
use App\Actions\Agreements\ReissueAgreement;
use App\Actions\Agreements\RenewAgreement;
use App\Actions\Agreements\ReviewAgreement;
use App\Actions\Agreements\SendAgreement;
use App\Actions\Agreements\TerminateAgreement;
use App\Actions\Agreements\UpdateAgreementDraft;
use App\Enums\AgreementChannel;
use App\Enums\AgreementKind;
use App\Enums\Locale;
use App\Models\ActivityLog;
use App\Models\Agreement;
use App\Models\CompanySignatory;
use App\Models\StatusTransition;
use App\Services\Agreements\AgreementRenderer;
use App\Services\Agreements\AgreementValidator;
use App\Services\Agreements\Exceptions\AgreementActionException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Agreement detail and editor (Spec 06 10.2): draft settings (second language, term, renewal,
 * notice, signatory, optional clauses), validator list and non-standard flags, preview per
 * language (stored text once sent), actions Submit / Approve / Return / Send / Upload scan /
 * Confirm signed / Re-issue / Cancel / Terminate / Renew / Addendum, dispatches and timeline.
 */
#[Layout('layouts.app')]
class Show extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $agreementId;

    #[Url(except: 'document')]
    public string $tab = 'document';

    #[Url(except: '')]
    public string $lang = '';

    #[Url(except: 0)]
    public int $version = 0;

    /** send | approve | return | upload | signed | reissue | cancel | terminate */
    public string $panel = '';

    /** @var array{secondary_locale: string, starts_on: string, ends_on: string, auto_renew: bool, notice_days: int|string, our_signatory_id: string} */
    public array $form = ['secondary_locale' => '', 'starts_on' => '', 'ends_on' => '', 'auto_renew' => false, 'notice_days' => 30, 'our_signatory_id' => ''];

    /** @var array<string, bool> */
    public array $clauses = [];

    /** @var list<string> */
    public array $channels = [];

    public string $recipientContactId = '';

    public string $comment = '';

    /** @var TemporaryUploadedFile|null */
    public $scan = null;

    /** @var array{signed_on: string, customer_signatory_name: string, customer_signatory_position: string, customer_signatory_basis: string, our_signatory_id: string} */
    public array $signature = ['signed_on' => '', 'customer_signatory_name' => '', 'customer_signatory_position' => '', 'customer_signatory_basis' => '', 'our_signatory_id' => ''];

    /** @var array{date: string, reason_code: string, reason: string} */
    public array $termination = ['date' => '', 'reason_code' => '', 'reason' => ''];

    public function mount(Agreement $agreement): void
    {
        $this->authorize('view', $agreement);

        $this->agreementId = $agreement->id;
        $this->tab = in_array($this->tab, ['document', 'dispatches', 'timeline'], true) ? $this->tab : 'document';
        $this->fillForm();
    }

    #[Computed]
    public function agreement(): Agreement
    {
        return Agreement::query()->with(['customer.contacts', 'order', 'template', 'parent', 'renewalOf', 'ourSignatory', 'signedAttachment'])->findOrFail($this->agreementId);
    }

    /**
     * @return list<array{key: string, params: array<string, string>}>
     */
    #[Computed]
    public function problems(): array
    {
        return $this->agreement->status->isEditable() ? app(AgreementValidator::class)->errors($this->agreement) : [];
    }

    /**
     * @return list<array{code: string, label: string, default: bool}>
     */
    #[Computed]
    public function clauseDefinitions(): array
    {
        return app(AgreementValidator::class)->templateVersion($this->agreement, $this->agreement->document_locale)?->clauses ?? [];
    }

    /**
     * @return array{html: string, sha256: string|null, stored: bool, version: int, locale: string}
     */
    #[Computed]
    public function document(): array
    {
        $agreement = $this->agreement;
        $locale = Locale::fromAvailable($this->lang) ?? Locale::from($agreement->document_locale);

        return [...app(AgreementRenderer::class)->html($agreement, $locale, $this->version > 0 ? $this->version : null), 'locale' => $locale->value];
    }

    /**
     * @return Collection<int, CompanySignatory>
     */
    #[Computed]
    public function signatories(): Collection
    {
        return CompanySignatory::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('full_name')->get();
    }

    /**
     * @return list<int>
     */
    #[Computed]
    public function storedVersions(): array
    {
        return $this->agreement->renderedVersions()->distinct()->pluck('version')->map(fn (mixed $v): int => (int) $v)->sort()->values()->all();
    }

    /**
     * @return SupportCollection<int, array{at: \DateTimeInterface, kind: string, model: object}>
     */
    #[Computed]
    public function timeline(): SupportCollection
    {
        $agreement = $this->agreement;
        $items = collect();

        foreach (StatusTransition::query()->with('user')->where('subject_type', 'agreement')->where('subject_id', $agreement->id)->get() as $transition) {
            $items->push(['at' => $transition->created_at, 'kind' => 'transition', 'model' => $transition]);
        }

        if (auth()->user()->can('viewActivity', $agreement)) {
            foreach (ActivityLog::query()->with('causer')->where('subject_type', 'agreement')->where('subject_id', $agreement->id)->whereNotIn('event', ['status_changed', 'created', 'updated'])->get() as $entry) {
                $items->push(['at' => $entry->created_at, 'kind' => 'activity', 'model' => $entry]);
            }
        }

        return $items->sortByDesc(fn (array $item) => $item['at']->getTimestamp())->values();
    }

    public function openPanel(string $panel): void
    {
        $this->resetValidation();
        $this->reset(['comment', 'channels', 'recipientContactId', 'scan', 'termination']);
        $this->panel = in_array($panel, ['send', 'approve', 'return', 'upload', 'signed', 'reissue', 'cancel', 'terminate'], true) ? $panel : '';

        if ($panel === 'signed') {
            $agreement = $this->agreement;
            $this->signature = [
                'signed_on' => now((string) config('orders.timezone'))->toDateString(),
                'customer_signatory_name' => (string) ($agreement->customer?->signatory_name ?? ''),
                'customer_signatory_position' => (string) ($agreement->customer?->signatory_position ?? ''),
                'customer_signatory_basis' => (string) ($agreement->customer?->signatory_basis ?? ''),
                'our_signatory_id' => (string) ($agreement->our_signatory_id ?? ''),
            ];
        }
    }

    public function closePanel(): void
    {
        $this->panel = '';
        $this->resetValidation();
    }

    public function saveDraft(UpdateAgreementDraft $update): void
    {
        $this->authorize('update', $this->agreement);
        $data = $this->validate([
            'form.secondary_locale' => ['nullable', 'in:'.implode(',', array_column(Locale::cases(), 'value'))],
            'form.starts_on' => ['required', 'date'],
            'form.ends_on' => ['required', 'date', 'after_or_equal:form.starts_on'],
            'form.auto_renew' => ['boolean'],
            'form.notice_days' => ['required', 'integer', 'min:0', 'max:365'],
            'form.our_signatory_id' => ['nullable', 'integer'],
        ], [], [
            'form.secondary_locale' => __('agreements.fields.secondary_locale'),
            'form.starts_on' => __('agreements.fields.starts_on'),
            'form.ends_on' => __('agreements.fields.ends_on'),
            'form.notice_days' => __('agreements.fields.notice_days'),
            'form.our_signatory_id' => __('agreements.fields.our_signatory'),
        ])['form'];

        $this->run(fn () => $update->handle($this->agreement, [
            'secondary_locale' => $data['secondary_locale'] ?: null,
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'auto_renew' => (bool) $data['auto_renew'],
            'notice_days' => (int) $data['notice_days'],
            'our_signatory_id' => $data['our_signatory_id'] !== '' && $data['our_signatory_id'] !== null ? (int) $data['our_signatory_id'] : null,
            'clause_options' => array_map(fn (mixed $value): bool => (bool) $value, $this->clauses),
        ]), 'agreements.flash.saved');
    }

    public function submit(ReviewAgreement $review): void
    {
        $this->authorize('submit', $this->agreement);
        $this->run(fn () => $review->submit($this->agreement, auth()->user()), 'agreements.flash.submitted');
    }

    public function approve(ReviewAgreement $review): void
    {
        $this->authorize('approve', $this->agreement);
        $this->run(fn () => $review->approve($this->agreement, auth()->user(), trim($this->comment) ?: null), 'agreements.flash.approved');
    }

    public function returnToDraft(ReviewAgreement $review): void
    {
        $this->authorize('approve', $this->agreement);
        $this->validate(['comment' => ['required', 'string', 'max:2000']], [], ['comment' => __('agreements.fields.comment')]);
        $this->run(fn () => $review->returnToDraft($this->agreement, auth()->user(), trim($this->comment)), 'agreements.flash.returned');
    }

    public function send(SendAgreement $send): void
    {
        $this->authorize('send', $this->agreement);
        $this->validate([
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['in:'.implode(',', array_map(fn (AgreementChannel $c): string => $c->value, AgreementChannel::available()))],
            'recipientContactId' => ['nullable', 'integer'],
        ], [], ['channels' => __('agreements.fields.channels'), 'recipientContactId' => __('agreements.fields.recipient')]);

        $channels = array_map(fn (string $value): AgreementChannel => AgreementChannel::from($value), $this->channels);
        $this->run(fn () => $send->handle($this->agreement, auth()->user(), $channels, $this->recipientContactId !== '' ? (int) $this->recipientContactId : null), 'agreements.flash.sent');
    }

    public function uploadScan(RecordSignature $signature): void
    {
        $this->authorize('uploadScan', $this->agreement);
        $this->validate(['scan' => ['required', 'file', 'max:'.(int) config('agreements.scan_max_kb')]], [], ['scan' => __('agreements.fields.scan')]);
        $this->run(fn () => $signature->upload($this->agreement, auth()->user(), $this->scan), 'agreements.flash.scan_uploaded');
        $this->scan = null;
    }

    public function confirmSigned(RecordSignature $signature): void
    {
        $this->authorize('confirmSigned', $this->agreement);
        $data = $this->validate([
            'signature.signed_on' => ['required', 'date'],
            'signature.customer_signatory_name' => ['required', 'string', 'max:255'],
            'signature.customer_signatory_position' => ['nullable', 'string', 'max:255'],
            'signature.customer_signatory_basis' => ['nullable', 'string', 'max:255'],
            'signature.our_signatory_id' => ['required', 'integer'],
        ], [], [
            'signature.signed_on' => __('agreements.fields.signed_on'),
            'signature.customer_signatory_name' => __('agreements.fields.customer_signatory_name'),
            'signature.customer_signatory_position' => __('agreements.fields.customer_signatory_position'),
            'signature.customer_signatory_basis' => __('agreements.fields.customer_signatory_basis'),
            'signature.our_signatory_id' => __('agreements.fields.our_signatory'),
        ])['signature'];

        $this->run(fn () => $signature->confirm($this->agreement, auth()->user(), [...$data, 'our_signatory_id' => (int) $data['our_signatory_id']]), 'agreements.flash.signed');
    }

    public function reissue(ReissueAgreement $reissue): void
    {
        $this->authorize('reissue', $this->agreement);
        $this->validate(['comment' => ['required', 'string', 'max:500']], [], ['comment' => __('agreements.fields.reissue_reason')]);
        $this->run(fn () => $reissue->handle($this->agreement, auth()->user(), trim($this->comment)), 'agreements.flash.reissued');
        $this->version = 0;
        $this->fillForm();
    }

    public function cancel(CancelAgreement $cancel): void
    {
        $this->authorize('cancel', $this->agreement);
        $this->validate(['comment' => ['required', 'string', 'max:2000']], [], ['comment' => __('agreements.fields.cancel_reason')]);
        $this->run(fn () => $cancel->handle($this->agreement, auth()->user(), trim($this->comment)), 'agreements.flash.cancelled');
    }

    public function terminate(TerminateAgreement $terminate): void
    {
        $this->authorize('terminate', $this->agreement);
        $data = $this->validate([
            'termination.date' => ['required', 'date'],
            'termination.reason_code' => ['required', 'in:'.implode(',', TerminateAgreement::REASONS)],
            'termination.reason' => ['required', 'string', 'max:2000'],
        ], [], [
            'termination.date' => __('agreements.fields.terminated_on'),
            'termination.reason_code' => __('agreements.fields.termination_reason_code'),
            'termination.reason' => __('agreements.fields.termination_reason'),
        ])['termination'];

        $this->run(fn () => $terminate->handle($this->agreement, auth()->user(), $data['date'], $data['reason_code'], trim($data['reason'])), 'agreements.flash.terminated');
    }

    public function renew(RenewAgreement $renew): void
    {
        $this->authorize('renew', $this->agreement);
        $this->redirectToNew(fn (): Agreement => $renew->handle($this->agreement, auth()->user()));
    }

    public function addAddendum(GenerateAgreement $generate): void
    {
        $this->authorize('addAddendum', $this->agreement);
        $agreement = $this->agreement;
        $this->redirectToNew(fn (): Agreement => $generate->handle(AgreementKind::Addendum, $agreement->customer, null, auth()->user(), [
            'parent' => $agreement,
            'locale' => $agreement->document_locale,
            'secondary_locale' => $agreement->secondary_locale,
        ]));
    }

    protected function redirectToNew(callable $create): void
    {
        try {
            $agreement = $create();
        } catch (AgreementActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', __('agreements.flash.generated', ['number' => $agreement->number]));
        $this->redirectRoute('agreements.show', $agreement, navigate: true);
    }

    protected function fillForm(): void
    {
        $agreement = $this->agreement;
        $this->form = [
            'secondary_locale' => (string) ($agreement->secondary_locale ?? ''),
            'starts_on' => (string) $agreement->starts_on?->toDateString(),
            'ends_on' => (string) $agreement->ends_on?->toDateString(),
            'auto_renew' => (bool) $agreement->auto_renew,
            'notice_days' => (int) $agreement->notice_days,
            'our_signatory_id' => (string) ($agreement->our_signatory_id ?? ''),
        ];
        $this->clauses = array_map(fn (mixed $value): bool => (bool) $value, $agreement->clause_options ?? []);
    }

    protected function run(callable $action, string $successKey): void
    {
        try {
            $action();
        } catch (AgreementActionException $exception) {
            session()->flash('error', $exception->getMessage());
            $this->refresh();

            return;
        }

        session()->flash('status', __($successKey));
        $this->panel = '';
        $this->refresh();
        $this->fillForm();
    }

    protected function refresh(): void
    {
        unset($this->agreement, $this->problems, $this->clauseDefinitions, $this->document, $this->storedVersions, $this->timeline, $this->signatories);
    }

    public function render(): View
    {
        return view('livewire.agreements.show', [
            'locales' => Locale::cases(),
            'availableChannels' => AgreementChannel::available(),
            'allChannels' => AgreementChannel::cases(),
            'terminationReasons' => TerminateAgreement::REASONS,
        ])->title($this->agreement->number);
    }
}
