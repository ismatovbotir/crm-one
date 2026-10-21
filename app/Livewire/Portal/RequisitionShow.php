<?php

namespace App\Livewire\Portal;

use App\Actions\Requisitions\ManageRequisition;
use App\Enums\CustomerPortalRole;
use App\Enums\RequisitionStatus;
use App\Models\Requisition;
use App\Models\RequisitionLine;
use App\Services\Attachments\StoreAttachment;
use App\Services\Requisitions\Exceptions\RequisitionException;
use App\Support\PortalContext;
use App\Support\PortalScope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * One request in the portal (Spec 10): header and lines (no prices), requester actions (submit,
 * resubmit, cancel), purchase-manager actions (edit quantities, return, approve, reject, split,
 * send to supplier), comments (client-internal or shared with us), attachments, and the linked
 * offer once it was sent. Requesters only open their own requests; foreign ids are 404.
 */
#[Layout('layouts.portal')]
class RequisitionShow extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $requisitionId;

    /** @var array{title: string, delivery_location: string, needed_by: string, notes: string} */
    public array $header = ['title' => '', 'delivery_location' => '', 'needed_by' => '', 'notes' => ''];

    /** @var array<int, string> line id => quantity */
    public array $quantities = [];

    public string $decisionText = '';

    public string $comment = '';

    public bool $commentShared = false;

    /** @var list<int> */
    public array $splitLines = [];

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public function mount(string $requisition, PortalScope $scope, PortalContext $context): void
    {
        $model = $scope->requisitions()->where('uuid', $requisition)->firstOrFail();
        abort_unless($context->canPurchase() || $model->requester_id === $context->user()->id, 404);
        $this->requisitionId = $model->id;
        $this->header = ['title' => (string) $model->title, 'delivery_location' => (string) $model->delivery_location, 'needed_by' => (string) $model->needed_by?->toDateString(), 'notes' => (string) $model->notes];
    }

    #[Computed]
    public function requisition(): Requisition
    {
        return app(PortalScope::class)->requisitions()->with(['lines.item:id,name,sku', 'lines.category:id,name,portal_names', 'lines.mappedItem:id,name,sku', 'comments.user:id,name', 'requester:id,name', 'purchaseManager:id,name', 'attachments'])->findOrFail($this->requisitionId);
    }

    protected function isRequester(PortalContext $context): bool
    {
        return $this->requisition->requester_id === $context->user()->id;
    }

    public function saveHeader(PortalContext $context, ManageRequisition $manage): void
    {
        $this->authorizeEdit($context);
        $this->validate(['header.title' => ['nullable', 'string', 'max:255'], 'header.delivery_location' => ['nullable', 'string', 'max:500'], 'header.needed_by' => ['nullable', 'date'], 'header.notes' => ['nullable', 'string', 'max:5000']]);
        $this->run(fn () => $manage->updateHeader($this->requisition, $this->header, $context->user()));
    }

    public function saveQuantity(int $lineId, PortalContext $context, ManageRequisition $manage): void
    {
        $this->authorizeEdit($context);
        $line = RequisitionLine::query()->where('requisition_id', $this->requisitionId)->findOrFail($lineId);
        $this->run(fn () => $manage->updateLine($line, (string) ($this->quantities[$lineId] ?? $line->quantity), $line->needed_by?->toDateString(), $context->user()));
        unset($this->quantities[$lineId]);
    }

    public function removeLine(int $lineId, PortalContext $context, ManageRequisition $manage): void
    {
        $this->authorizeEdit($context);
        $line = RequisitionLine::query()->where('requisition_id', $this->requisitionId)->findOrFail($lineId);
        $this->run(fn () => $manage->removeLine($line, $context->user()));
    }

    public function submit(PortalContext $context, ManageRequisition $manage): void
    {
        abort_unless($this->isRequester($context) && $context->canRequest(), 403);
        $key = 'portal-requisition-submit|'.$context->user()->id;

        if (RateLimiter::tooManyAttempts($key, 30)) {
            $this->addError('decisionText', __('requisitions.errors.rate_limited'));

            return;
        }

        RateLimiter::hit($key, 3600);
        $this->run(fn () => $manage->submit($this->requisition, $context->user()), 'requisitions.flash.submitted');
    }

    public function approve(PortalContext $context, ManageRequisition $manage): void
    {
        abort_unless($context->canPurchase(), 403);
        $this->run(fn () => $manage->approve($this->requisition, $context->user()), 'requisitions.flash.approved');
    }

    public function returnToRequester(PortalContext $context, ManageRequisition $manage): void
    {
        abort_unless($context->canPurchase(), 403);
        $this->run(fn () => $manage->returnToRequester($this->requisition, $context->user(), $this->decisionText), 'requisitions.flash.returned');
    }

    public function reject(PortalContext $context, ManageRequisition $manage): void
    {
        abort_unless($context->canPurchase(), 403);
        $this->run(fn () => $manage->reject($this->requisition, $context->user(), $this->decisionText), 'requisitions.flash.rejected');
    }

    public function cancel(PortalContext $context, ManageRequisition $manage): void
    {
        abort_unless(($this->isRequester($context) && $context->canRequest()) || $context->canPurchase(), 403);
        $this->run(fn () => $manage->cancel($this->requisition, $context->user()), 'requisitions.flash.cancelled');
    }

    public function send(PortalContext $context, ManageRequisition $manage): void
    {
        abort_unless($context->canPurchase(), 403);
        $this->run(fn () => $manage->sendToSupplier($this->requisition, $context->user(), $context->hasRole(CustomerPortalRole::PortalAdmin)), 'requisitions.flash.sent');
    }

    public function split(PortalContext $context, ManageRequisition $manage): void
    {
        abort_unless($context->canPurchase(), 403);

        try {
            $new = $manage->split($this->requisition, array_map('intval', $this->splitLines), $context->user());
        } catch (RequisitionException $exception) {
            $this->addError('splitLines', $exception->getMessage());

            return;
        }

        session()->flash('status', __('requisitions.flash.split', ['number' => $new->number]));
        $this->redirectRoute('portal.requisitions.show', $new, navigate: true);
    }

    public function addComment(PortalContext $context, ManageRequisition $manage): void
    {
        abort_unless($context->canRequest() || $context->canPurchase(), 403);
        $this->validate(['comment' => ['required', 'string', 'max:5000']]);
        $shared = $this->commentShared && $this->requisition->status->isWithSupplier();
        $manage->comment($this->requisition, $context->user(), $this->comment, $shared ? 'shared' : 'client');
        $this->reset('comment', 'commentShared');
        unset($this->requisition);
    }

    public function attach(PortalContext $context, StoreAttachment $attachments): void
    {
        $this->authorizeEdit($context);
        abort_if($this->requisition->attachments->count() >= 5, 422, __('requisitions.errors.too_many_files'));
        $this->validate(['file' => ['required', 'file', 'max:10240']]);
        $attachments->handle($this->file, $this->requisition, 'requisition', $context->user(), 10240, 'file');
        $this->reset('file');
        unset($this->requisition);
    }

    protected function authorizeEdit(PortalContext $context): void
    {
        $status = $this->requisition->status;
        $requesterEdit = $this->isRequester($context) && $context->canRequest() && in_array($status, [RequisitionStatus::Draft, RequisitionStatus::Returned], true);
        $managerEdit = $context->canPurchase() && $status->isClientEditable();
        abort_unless($requesterEdit || $managerEdit, 403);
    }

    protected function run(callable $action, string $flash = 'requisitions.flash.saved'): void
    {
        try {
            $action();
            session()->flash('status', __($flash));
        } catch (RequisitionException $exception) {
            $this->addError('decisionText', $exception->getMessage());
        }

        unset($this->requisition);
    }

    public function render(PortalContext $context): View
    {
        $requisition = $this->requisition;
        $isRequester = $this->isRequester($context);
        $status = $requisition->status;

        return view('livewire.portal.requisition-show', [
            'requisition' => $requisition,
            'canEdit' => ($isRequester && $context->canRequest() && in_array($status, [RequisitionStatus::Draft, RequisitionStatus::Returned], true)) || ($context->canPurchase() && $status->isClientEditable()),
            'canSubmit' => $isRequester && $context->canRequest() && in_array($status, [RequisitionStatus::Draft, RequisitionStatus::Returned], true),
            'canPurchase' => $context->canPurchase(),
            'canCancel' => (($isRequester && $context->canRequest()) || $context->canPurchase()) && $status->canTransitionTo(RequisitionStatus::Cancelled),
            'offer' => $requisition->sentOffer(),
            'comments' => $requisition->comments,
        ])->title($requisition->number);
    }
}
