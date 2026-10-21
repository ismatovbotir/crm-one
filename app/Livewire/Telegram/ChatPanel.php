<?php

namespace App\Livewire\Telegram;

use App\Actions\Telegram\CreateContactsFromMembers;
use App\Enums\TelegramMemberRole;
use App\Models\Customer;
use App\Models\TelegramChat;
use App\Models\TelegramChatMember;
use App\Services\Telegram\Exceptions\TelegramException;
use App\Services\Telegram\TelegramChats;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A Telegram group on the lead page and the customer card (Spec 04 section 17): badge, member
 * count, the members the bot has seen, and the manager actions: create the company from the
 * group, link to an existing customer, unlink, refresh members (on demand) and create contacts
 * (optionally portal users, pending) from chosen members. All names are escaped on display.
 */
class ChatPanel extends Component
{
    #[Locked]
    public int $chatId;

    public string $customerSearch = '';

    public ?int $linkCustomerId = null;

    /** @var list<int> */
    public array $selected = [];

    public bool $invite = false;

    public function mount(int $chatId): void
    {
        $this->chatId = $chatId;
        $this->authorize('view', $this->chat);
    }

    #[Computed]
    public function chat(): TelegramChat
    {
        return TelegramChat::query()->with(['customer', 'lead'])->findOrFail($this->chatId);
    }

    /**
     * @return Collection<int, TelegramChatMember>
     */
    #[Computed]
    public function members(): Collection
    {
        return TelegramChatMember::query()->where('telegram_chat_id', $this->chatId)
            ->with(['telegramContact.contact.customer:id,name', 'telegramContact.user'])
            ->orderByRaw("case role when 'creator' then 0 when 'admin' then 1 when 'member' then 2 else 3 end")
            ->orderByDesc('last_seen_at')->limit(300)->get();
    }

    /**
     * @return Collection<int, Customer>
     */
    #[Computed]
    public function customerOptions(): Collection
    {
        $term = trim($this->customerSearch);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        return Customer::query()->visibleTo(auth()->user())->notDeletedInOneC()
            ->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('tax_id', 'like', $like))
            ->orderBy('name')->limit(10)->get(['id', 'name', 'tax_id']);
    }

    public function refreshMembers(TelegramChats $chats): void
    {
        $this->authorize('manage', $this->chat);

        try {
            $result = $chats->refreshMembers($this->chat, auth()->user());
            session()->flash('status', __('telegram.groups.refreshed', ['admins' => $result['admins'], 'count' => $result['count'] ?? '—']));
        } catch (TelegramException $exception) {
            $this->addError('telegram', __('telegram.groups.errors.api', ['reason' => $exception->reason]));
        }

        unset($this->chat, $this->members);
    }

    public function linkCustomer(TelegramChats $chats): void
    {
        $this->authorize('manage', $this->chat);
        $customer = $this->linkCustomerId !== null ? Customer::query()->visibleTo(auth()->user())->notDeletedInOneC()->find($this->linkCustomerId) : null;

        if ($customer === null || ! auth()->user()->can('update', $customer)) {
            $this->addError('linkCustomerId', __('telegram.groups.errors.customer'));

            return;
        }

        $chats->linkCustomer($this->chat, $customer, auth()->user());
        $this->reset('linkCustomerId', 'customerSearch');
        unset($this->chat, $this->members);
        session()->flash('status', __('telegram.groups.linked', ['name' => $customer->name]));
    }

    public function unlinkCustomer(TelegramChats $chats): void
    {
        $this->authorize('manage', $this->chat);
        abort_unless($this->chat->customer === null || auth()->user()->can('update', $this->chat->customer), 403);
        $chats->unlinkCustomer($this->chat, auth()->user());
        unset($this->chat, $this->members);
        session()->flash('status', __('telegram.groups.unlinked'));
    }

    public function createContacts(CreateContactsFromMembers $action): void
    {
        $this->authorize('createContacts', $this->chat);
        $this->validate(['selected' => ['required', 'array', 'min:1', 'max:100'], 'selected.*' => ['integer']], [], ['selected' => __('telegram.groups.members')]);
        $invite = $this->invite && auth()->user()->can('managePortal', $this->chat->customer);
        $result = $action->handle($this->chat, array_map('intval', $this->selected), $invite, auth()->user());
        $this->reset('selected', 'invite');
        unset($this->chat, $this->members);

        session()->flash('status', __('telegram.groups.contacts_created', ['created' => $result['created'], 'invited' => $result['invited'], 'skipped' => count($result['skipped'])]));
    }

    public function render(TelegramChats $chats): View
    {
        $chat = $this->chat;
        $conflicts = [];

        foreach ($this->members as $member) {
            if ($member->telegramContact !== null && $member->role !== TelegramMemberRole::Left) {
                $others = $chats->otherCompanies($member->telegramContact, $chat->customer_id);

                if ($others->isNotEmpty()) {
                    $conflicts[$member->id] = $others->pluck('name')->join(', ');
                }
            }
        }

        $user = auth()->user();

        return view('livewire.telegram.chat-panel', [
            'chat' => $chat,
            'conflicts' => $conflicts,
            'canManage' => $user->can('manage', $chat),
            'canCreateContacts' => $user->can('createContacts', $chat),
            'canInvite' => $chat->customer !== null && $chat->customer->portal_enabled && $user->can('managePortal', $chat->customer),
            'canCreateCustomer' => $chat->customer === null && $chat->lead !== null && $chat->lead->customer_id === null && $user->can('create', Customer::class) && $user->can('update', $chat->lead),
            'activeCount' => $this->members->where('role', '!=', TelegramMemberRole::Left)->count(),
            'skipReason' => fn (TelegramChatMember $member): ?string => $chat->customer !== null && $member->telegramContact !== null ? app(CreateContactsFromMembers::class)->skipReason($member->telegramContact, $chat->customer) : null,
        ]);
    }
}
