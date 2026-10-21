<?php

namespace App\Livewire\Counterparties;

use App\Actions\Counterparties\ManageCounterparty;
use App\Enums\CounterpartyDocumentType;
use App\Enums\CounterpartyStatus;
use App\Models\Contact;
use App\Models\Counterparty;
use App\Models\CounterpartyDocument;
use App\Models\TenderInvitation;
use App\Services\Tenders\Exceptions\TenderActionException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Counterparty card (Spec 03 section 9): details, contacts, qualification documents with expiry
 * badges and files, status actions, ratings, tender history and the change log.
 */
#[Layout('layouts.app')]
class Show extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $counterpartyId;

    public string $tab = 'details';

    /** @var array<string, mixed> */
    public array $details = [];

    /** @var array{name: string, position: string, phone: string, email: string, telegram: string, preferred_locale: string, is_primary: bool} */
    public array $contact = ['name' => '', 'position' => '', 'phone' => '', 'email' => '', 'telegram' => '', 'preferred_locale' => '', 'is_primary' => false];

    /** @var array{type: string, number: string, valid_until: string} */
    public array $document = ['type' => 'registration', 'number' => '', 'valid_until' => ''];

    /** @var TemporaryUploadedFile|null */
    public $documentFile = null;

    public string $statusTarget = '';

    public string $statusReason = '';

    /** @var array{quality: int, communication: int, documents: int, on_time: string, comment: string} */
    public array $rating = ['quality' => 5, 'communication' => 5, 'documents' => 5, 'on_time' => '', 'comment' => ''];

    public function mount(Counterparty $counterparty): void
    {
        $this->authorize('view', $counterparty);
        $this->counterpartyId = $counterparty->id;
        $this->fillDetails();
    }

    #[Computed]
    public function counterparty(): Counterparty
    {
        return Counterparty::withTrashed()->with(['contacts', 'documents.attachment', 'documents.verifier', 'ratings.rater'])->findOrFail($this->counterpartyId);
    }

    /**
     * @return Collection<int, TenderInvitation>
     */
    #[Computed]
    public function invitations(): Collection
    {
        return $this->counterparty->invitations()->with('tender')->latest('id')->limit(50)->get()
            ->filter(fn ($invitation): bool => $invitation->tender !== null && auth()->user()->can('view', $invitation->tender))->values();
    }

    public function saveDetails(ManageCounterparty $manage): void
    {
        $this->authorize('update', $this->counterparty);
        $this->validate([
            'details.name' => ['required', 'string', 'max:255'],
            'details.legal_name' => ['nullable', 'string', 'max:255'],
            'details.tax_id' => ['nullable', 'string', 'max:20'],
            'details.country' => ['nullable', 'string', 'size:2'],
            'details.city' => ['nullable', 'string', 'max:120'],
            'details.address' => ['nullable', 'string', 'max:1000'],
            'details.phone' => ['nullable', 'string', 'max:32'],
            'details.email' => ['nullable', 'email', 'max:255'],
            'details.website' => ['nullable', 'string', 'max:255'],
            'details.is_supplier' => ['boolean'],
            'details.is_carrier' => ['boolean'],
            'details.fleet_size' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'details.has_tir' => ['boolean'],
            'details.has_adr' => ['boolean'],
            'details.preferred_locale' => ['nullable', 'in:uz,ru,en'],
            'details.notes' => ['nullable', 'string', 'max:5000'],
        ], [], ['details.name' => __('counterparties.fields.name'), 'details.email' => __('counterparties.fields.email')]);

        $data = array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : $value, $this->details);
        $data['country'] = $data['country'] !== null ? strtoupper((string) $data['country']) : null;

        try {
            $manage->save($this->counterparty, $data, auth()->user());
        } catch (TenderActionException $exception) {
            $this->addError('details.is_supplier', $exception->getMessage());

            return;
        }

        session()->flash('status', __('counterparties.flash.saved'));
        unset($this->counterparty);
    }

    public function saveContact(ManageCounterparty $manage): void
    {
        $this->authorize('update', $this->counterparty);
        $this->validate([
            'contact.name' => ['required', 'string', 'max:255'],
            'contact.position' => ['nullable', 'string', 'max:120'],
            'contact.phone' => ['nullable', 'string', 'max:32'],
            'contact.email' => ['nullable', 'email', 'max:255'],
            'contact.telegram' => ['nullable', 'string', 'max:64'],
            'contact.preferred_locale' => ['nullable', 'in:uz,ru,en'],
            'contact.is_primary' => ['boolean'],
        ], [], ['contact.name' => __('counterparties.contacts.name'), 'contact.email' => __('counterparties.fields.email')]);

        $manage->saveContact($this->counterparty, null, array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : $value, $this->contact));
        $this->reset('contact');
        session()->flash('status', __('counterparties.flash.contact_saved'));
        unset($this->counterparty);
    }

    public function removeContact(ManageCounterparty $manage, int $contactId): void
    {
        $this->authorize('update', $this->counterparty);
        $manage->removeContact($this->counterparty, Contact::query()->where('partner_id', $this->counterparty->partner_id)->findOrFail($contactId));
        unset($this->counterparty);
    }

    public function addDocument(ManageCounterparty $manage): void
    {
        $this->authorize('update', $this->counterparty);
        $this->validate([
            'document.type' => ['required', Rule::enum(CounterpartyDocumentType::class)],
            'document.number' => ['nullable', 'string', 'max:60'],
            'document.valid_until' => ['nullable', 'date'],
            'documentFile' => ['nullable', 'file', 'max:'.(int) config('tenders.evidence_max_kb')],
        ], [], ['document.type' => __('counterparties.documents.type'), 'documentFile' => __('counterparties.documents.file')]);

        try {
            $manage->addDocument($this->counterparty, CounterpartyDocumentType::from($this->document['type']), $this->document['number'] ?: null, $this->document['valid_until'] ?: null, $this->documentFile, auth()->user());
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        }

        $this->reset('document', 'documentFile');
        session()->flash('status', __('counterparties.flash.document_added'));
        unset($this->counterparty);
    }

    public function verifyDocument(ManageCounterparty $manage, int $documentId): void
    {
        $this->authorize('qualify', $this->counterparty);
        $manage->verifyDocument(CounterpartyDocument::query()->where('counterparty_id', $this->counterpartyId)->findOrFail($documentId), auth()->user());
        unset($this->counterparty);
    }

    public function changeStatus(ManageCounterparty $manage): void
    {
        $this->authorize('qualify', $this->counterparty);
        $target = CounterpartyStatus::tryFrom($this->statusTarget) ?? abort(422);

        try {
            $manage->changeStatus($this->counterparty, $target, trim($this->statusReason) ?: null, auth()->user());
        } catch (TenderActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        $this->reset('statusTarget', 'statusReason');
        session()->flash('status', __('counterparties.flash.status_changed'));
        unset($this->counterparty);
    }

    public function rate(ManageCounterparty $manage): void
    {
        $this->authorize('rate', $this->counterparty);
        $this->validate([
            'rating.quality' => ['required', 'integer', 'between:1,5'],
            'rating.communication' => ['required', 'integer', 'between:1,5'],
            'rating.documents' => ['required', 'integer', 'between:1,5'],
            'rating.on_time' => ['nullable', 'in:0,1'],
            'rating.comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $manage->rate($this->counterparty, [
            'quality' => (int) $this->rating['quality'],
            'communication' => (int) $this->rating['communication'],
            'documents' => (int) $this->rating['documents'],
            'on_time' => $this->rating['on_time'] === '' ? null : $this->rating['on_time'] === '1',
            'comment' => trim($this->rating['comment']) ?: null,
        ], auth()->user());
        $this->reset('rating');
        session()->flash('status', __('counterparties.flash.rated'));
        unset($this->counterparty);
    }

    protected function fillDetails(): void
    {
        $c = $this->counterparty;
        $this->details = [
            'name' => $c->name, 'legal_name' => (string) $c->legal_name, 'tax_id' => (string) $c->tax_id, 'country' => (string) $c->country,
            'city' => (string) $c->city, 'address' => (string) $c->address, 'phone' => (string) $c->phone, 'email' => (string) $c->email,
            'website' => (string) $c->website, 'is_supplier' => $c->is_supplier, 'is_carrier' => $c->is_carrier, 'fleet_size' => $c->fleet_size,
            'has_tir' => $c->has_tir, 'has_adr' => $c->has_adr, 'preferred_locale' => (string) $c->preferred_locale, 'notes' => (string) $c->notes,
        ];
    }

    public function render(): View
    {
        return view('livewire.counterparties.show', [
            'documentTypes' => CounterpartyDocumentType::options(),
            'targets' => collect(CounterpartyStatus::cases())->filter(fn (CounterpartyStatus $s): bool => $this->counterparty->status->canTransitionTo($s))->mapWithKeys(fn (CounterpartyStatus $s): array => [$s->value => $s->label()])->all(),
        ])->title($this->counterparty->name);
    }
}
