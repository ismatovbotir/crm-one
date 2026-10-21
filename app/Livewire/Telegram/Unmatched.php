<?php

namespace App\Livewire\Telegram;

use App\Authorization\PermissionMap;
use App\Models\TelegramContact;
use App\Models\User;
use App\Services\Telegram\Exceptions\TelegramMatchException;
use App\Services\Telegram\TelegramLinks;
use App\Services\Telegram\TelegramMatching;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Telegram users the bot has seen and nobody is linked to (Spec 03 section 17.10). In the user form
 * (`userId` set) the admin links one to that user and sees the link status, "Verified in person"
 * and unlink; on Settings > Telegram each row offers "Match to user" (choose a staff user). Links
 * made here stay pending until the user confirms them with the bot code.
 */
class Unmatched extends Component
{
    use WithPagination;

    #[Locked]
    public ?int $userId = null;

    public string $search = '';

    public bool $includeCustomerGroups = false;

    /** @var array<int, string> contact id => chosen staff user id (Settings view) */
    public array $chosen = [];

    public string $forceReason = '';

    public function mount(?int $userId = null): void
    {
        $this->authorize('viewAny', User::class);

        if ($userId !== null) {
            $this->authorize('update', User::query()->findOrFail($userId));
        }

        $this->userId = $userId;
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'includeCustomerGroups'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function user(): ?User
    {
        return $this->userId !== null ? User::query()->with('telegramLink')->find($this->userId) : null;
    }

    /**
     * @return LengthAwarePaginator<int, TelegramContact>
     */
    #[Computed]
    public function contacts(): LengthAwarePaginator
    {
        return app(TelegramMatching::class)->unmatched($this->search, $this->includeCustomerGroups)->paginate(config('crm.per_page'));
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function staff(): Collection
    {
        return User::query()->internal()->active()->whereDoesntHave('telegramLink')->orderBy('name')->get(['id', 'name']);
    }

    public function link(int $contactId, TelegramMatching $matching): void
    {
        $target = $this->userId !== null ? $this->user : User::query()->find((int) ($this->chosen[$contactId] ?? 0));

        if ($target === null) {
            $this->addError('contact-'.$contactId, __('telegram.matching.errors.user_invalid'));

            return;
        }

        $this->authorize('update', $target);

        try {
            $matching->match(TelegramContact::query()->findOrFail($contactId), $target, auth()->user());
        } catch (TelegramMatchException $exception) {
            $this->addError('contact-'.$contactId, $exception->getMessage());

            return;
        }

        unset($this->contacts, $this->user, $this->staff);
        session()->flash('status', __('telegram.matching.linked_pending', ['name' => $target->name]));
    }

    public function forceVerify(TelegramMatching $matching): void
    {
        $user = $this->user;
        abort_if($user === null, 404);
        $this->authorize('update', $user);
        $this->resetErrorBag('forceReason');

        try {
            $matching->forceVerify($user, auth()->user(), $this->forceReason);
        } catch (TelegramMatchException $exception) {
            $this->addError('forceReason', $exception->getMessage());

            return;
        }

        $this->reset('forceReason');
        unset($this->user);
        session()->flash('status', __('telegram.matching.verified_in_person'));
    }

    public function unlink(TelegramLinks $links): void
    {
        $user = $this->user;
        abort_if($user === null, 404);
        $this->authorize('update', $user);
        $links->unlink($user, auth()->user());
        unset($this->user, $this->contacts);
        session()->flash('status', __('users.telegram_unlinked', ['name' => $user->name]));
    }

    public function render(): View
    {
        return view('livewire.telegram.unmatched', [
            'link' => $this->user?->telegramLink,
            'canForce' => PermissionMap::allows(auth()->user(), 'users.telegram.force_link'),
        ]);
    }
}
