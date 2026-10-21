<?php

namespace App\Livewire\Agreements;

use App\Enums\AgreementKind;
use App\Enums\AgreementStatus;
use App\Models\Agreement;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Agreements list with the accountant work queues (Spec 06 4.6 / 10.2): to send, in review,
 * awaiting signature (days waiting), signed scans to verify, expiring within 90 days.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    public const QUEUES = ['to_send', 'in_review', 'awaiting_signature', 'scans_to_verify', 'expiring'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $kind = '';

    #[Url(except: '')]
    public string $queue = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Agreement::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'kind', 'queue'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'status', 'kind', 'queue']);
        $this->resetPage();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function queueCounts(): array
    {
        $counts = [];

        foreach (self::QUEUES as $queue) {
            $counts[$queue] = $this->applyQueue(Agreement::query()->visibleTo(auth()->user()), $queue)->count();
        }

        return $counts;
    }

    /**
     * @return LengthAwarePaginator<int, Agreement>
     */
    #[Computed]
    public function agreements(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return $this->applyQueue(Agreement::query()->visibleTo(auth()->user()), $this->queue)
            ->with(['customer', 'order'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.$search.'%';
                $query->where(fn (Builder $q) => $q->where('number', 'like', $term)
                    ->orWhereHas('customer', fn (Builder $c) => $c->withTrashed()->where('name', 'like', $term)->orWhere('legal_name', 'like', $term)));
            })
            ->when(AgreementStatus::tryFrom($this->status) !== null, fn (Builder $query) => $query->where('status', $this->status))
            ->when(AgreementKind::tryFrom($this->kind) !== null, fn (Builder $query) => $query->where('kind', $this->kind))
            ->latest('updated_at')
            ->latest('id')
            ->paginate(config('crm.per_page'));
    }

    /**
     * @param  Builder<Agreement>  $query
     * @return Builder<Agreement>
     */
    protected function applyQueue(Builder $query, string $queue): Builder
    {
        $today = CarbonImmutable::now((string) config('orders.timezone'))->startOfDay();

        return match ($queue) {
            'to_send' => $query->where(fn (Builder $q) => $q->where('status', AgreementStatus::Draft)->orWhere(fn (Builder $r) => $r->where('status', AgreementStatus::InReview)->whereNotNull('approved_at'))),
            'in_review' => $query->where('status', AgreementStatus::InReview)->whereNull('approved_at'),
            'awaiting_signature' => $query->where('status', AgreementStatus::SentForSignature)->whereNull('customer_signed_uploaded_at'),
            'scans_to_verify' => $query->where('status', AgreementStatus::SentForSignature)->whereNotNull('customer_signed_uploaded_at'),
            'expiring' => $query->where('status', AgreementStatus::Active)->whereDate('ends_on', '<=', $today->addDays(90)->toDateString()),
            default => $query,
        };
    }

    public function render(): View
    {
        return view('livewire.agreements.index', [
            'statuses' => AgreementStatus::options(),
            'kinds' => AgreementKind::options(),
        ])->title(__('agreements.title'));
    }
}
