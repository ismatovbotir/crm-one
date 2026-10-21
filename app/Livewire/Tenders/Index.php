<?php

namespace App\Livewire\Tenders;

use App\Actions\Tenders\ManageTender;
use App\Enums\TenderKind;
use App\Enums\TenderMethod;
use App\Enums\TenderStatus;
use App\Enums\TenderType;
use App\Models\Tender;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tenders > Purchase / Transportation (Spec 03 section 9): number, title, status, owner, deadlines
 * in UTC+5, bid count (metadata only before opening), lots; filters; new tender.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Locked]
    public string $type = 'transport';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: false)]
    public bool $mine = false;

    public bool $creating = false;

    /** @var array{title: string, method: string, kind: string} */
    public array $form = ['title' => '', 'method' => 'rfq', 'kind' => 'spot'];

    public function mount(string $type): void
    {
        $this->type = TenderType::from($type)->value;
        $this->authorize('viewType', [Tender::class, $this->type]);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'mine'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, Tender>
     */
    #[Computed]
    public function tenders(): LengthAwarePaginator
    {
        $search = trim($this->search);
        $user = auth()->user();

        return Tender::query()->visibleTo($user)->where('type', $this->type)
            ->with(['owner', 'requester'])
            ->withCount(['lots', 'bids as bids_count' => fn (Builder $q) => $q->where('status', '!=', 'superseded')])
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('number', 'like', '%'.$search.'%')->orWhere('title', 'like', '%'.$search.'%')))
            ->when(TenderStatus::tryFrom($this->status) !== null, fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->mine, fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('owner_id', $user->id)->orWhere('requested_by', $user->id)))
            ->latest('id')
            ->paginate(config('crm.per_page'));
    }

    public function create(ManageTender $manage): void
    {
        $type = TenderType::from($this->type);
        $this->authorize('create', [Tender::class, $type->value]);
        $this->validate([
            'form.title' => ['required', 'string', 'max:255'],
            'form.method' => ['required', Rule::enum(TenderMethod::class)],
            'form.kind' => ['required', Rule::enum(TenderKind::class)],
        ], [], ['form.title' => __('tenders.fields.title')]);

        $tender = $manage->create($type, $this->form, auth()->user());

        $this->redirectRoute('tenders.show', $tender, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.tenders.index', [
            'statuses' => TenderStatus::options(),
            'methods' => TenderMethod::options(),
            'kinds' => TenderKind::options(),
            'tenderType' => TenderType::from($this->type),
        ])->title(__('tenders.index_title.'.$this->type));
    }
}
