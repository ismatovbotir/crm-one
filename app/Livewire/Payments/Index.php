<?php

namespace App\Livewire\Payments;

use App\Enums\MatchState;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Payments list with the accountant queues (Spec 06 4.6 / 10.2): pending approval, unallocated,
 * possible duplicates, unmatched (no customer), pending 1C confirmation.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    public const QUEUES = ['pending_approval', 'unallocated', 'possible_duplicate', 'unmatched', 'pending_1c'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $source = '';

    #[Url(except: '')]
    public string $queue = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Payment::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'source', 'queue'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'status', 'source', 'queue']);
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
            $counts[$queue] = $this->applyQueue(Payment::query()->visibleTo(auth()->user()), $queue)->count();
        }

        return $counts;
    }

    /**
     * @return LengthAwarePaginator<int, Payment>
     */
    #[Computed]
    public function payments(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return $this->applyQueue(Payment::query()->visibleTo(auth()->user()), $this->queue)
            ->with('customer')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.$search.'%';
                $query->where(fn (Builder $q) => $q->where('number', 'like', $term)->orWhere('reference_number', 'like', $term)->orWhere('payer_name', 'like', $term)->orWhere('onec_number', 'like', $term)
                    ->orWhereHas('customer', fn (Builder $c) => $c->withTrashed()->where('name', 'like', $term)));
            })
            ->when(PaymentStatus::tryFrom($this->status) !== null, fn (Builder $query) => $query->where('status', $this->status))
            ->when(PaymentSource::tryFrom($this->source) !== null, fn (Builder $query) => $query->where('source', $this->source))
            ->latest('paid_on')
            ->latest('id')
            ->paginate(config('crm.per_page'));
    }

    /**
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    protected function applyQueue(Builder $query, string $queue): Builder
    {
        return match ($queue) {
            'pending_approval' => $query->where(fn (Builder $waiting) => $waiting->where('status', PaymentStatus::PendingApproval)
                ->orWhere(fn (Builder $void) => $void->whereNotNull('void_requested_at')->where('status', '!=', PaymentStatus::Voided))),
            'unallocated' => $query->where('kind', 'receipt')->whereIn('status', [PaymentStatus::Recorded, PaymentStatus::Confirmed])->whereNotNull('customer_id')->whereRaw('allocated_minor + refunded_minor < amount_minor'),
            'possible_duplicate' => $query->where('match_state', MatchState::PossibleDuplicate),
            'unmatched' => $query->whereNull('customer_id')->whereNotIn('status', [PaymentStatus::Voided]),
            'pending_1c' => $query->where('source', PaymentSource::Crm)->where('status', PaymentStatus::Recorded),
            default => $query,
        };
    }

    public function render(): View
    {
        return view('livewire.payments.index', [
            'statuses' => PaymentStatus::options(),
            'sources' => PaymentSource::options(),
        ])->title(__('accounting.payments.title'));
    }
}
