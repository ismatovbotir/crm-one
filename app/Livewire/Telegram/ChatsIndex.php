<?php

namespace App\Livewire\Telegram;

use App\Enums\TelegramChatType;
use App\Models\Setting;
use App\Models\TelegramChat;
use App\Services\Telegram\Exceptions\TelegramException;
use App\Services\Telegram\TelegramChats;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Settings > Telegram chats (Spec 04 section 17): the groups, supergroups and channels the bot is
 * or was in, with type, bot status, linked company, open lead and last message; managers merge
 * duplicate chats here. Rows the reader may not see (another rep's company) are left out.
 */
#[Layout('layouts.app')]
class ChatsIndex extends Component
{
    public const LIMIT = 300;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $filter = '';

    /** '' = groups and channels, group = groups and supergroups, channel, person (private chats, admins only). */
    #[Url(except: '')]
    public string $kind = '';

    public ?int $mergeId = null;

    public ?int $mergeIntoId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', TelegramChat::class);
    }

    /**
     * @return Collection<int, TelegramChat>
     */
    #[Computed]
    public function chats(): Collection
    {
        $term = trim($this->search);
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        $kind = $this->kind === 'person' && ! auth()->user()->isAdmin() ? '' : $this->kind;

        return TelegramChat::query()->with(['customer:id,name,owner_id', 'lead:id,number,owner_id,status'])
            ->when($kind === 'person', fn ($query) => $query->where('type', TelegramChatType::Private->value))
            ->when($kind === 'group', fn ($query) => $query->whereIn('type', [TelegramChatType::Group->value, TelegramChatType::Supergroup->value]))
            ->when($kind === 'channel', fn ($query) => $query->where('type', TelegramChatType::Channel->value))
            ->when($kind === '', fn ($query) => $query->where('type', '!=', TelegramChatType::Private->value))
            ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('username', 'like', $like)->orWhere('last_from_name', 'like', $like)
                ->when(ctype_digit(ltrim($term, '-')), fn ($ids) => $ids->orWhere('chat_id', (int) $term)->orWhere('last_from_id', (int) $term))))
            ->when($this->filter === 'unlinked', fn ($query) => $query->whereNull('customer_id')->whereNull('merged_into_id'))
            ->when($this->filter === 'removed', fn ($query) => $query->where('bot_status', 'removed'))
            ->orderByDesc('last_message_at')->limit(self::LIMIT)->get()
            ->filter(fn (TelegramChat $chat): bool => auth()->user()->can('view', $chat))->values();
    }

    public function merge(TelegramChats $chats): void
    {
        $duplicate = TelegramChat::query()->find($this->mergeId);
        $into = TelegramChat::query()->find($this->mergeIntoId);

        if ($duplicate === null || $into === null) {
            $this->addError('merge', __('telegram.chats.errors.merge_invalid'));

            return;
        }

        $this->authorize('manage', $duplicate);
        $this->authorize('manage', $into);

        try {
            $chats->merge($duplicate, $into, auth()->user());
        } catch (TelegramException $exception) {
            $this->addError('merge', __('telegram.chats.errors.'.$exception->reason));

            return;
        }

        $this->reset('mergeId', 'mergeIntoId');
        unset($this->chats);
        session()->flash('status', __('telegram.chats.merged', ['from' => $duplicate->displayName(), 'into' => $into->displayName()]));
    }

    public function render(): View
    {
        $mergeable = $this->chats->filter(fn (TelegramChat $chat): bool => $chat->merged_into_id === null && auth()->user()->can('manage', $chat))->values();

        return view('livewire.telegram.chats-index', [
            'mergeable' => $mergeable,
            'kinds' => array_filter(['' => __('telegram.chats.kinds.all'), 'group' => __('telegram.chats.kinds.group'), 'channel' => __('telegram.chats.kinds.channel'), 'person' => auth()->user()->isAdmin() ? __('telegram.chats.kinds.person') : null]),
            'botInfo' => ['id' => Setting::get('telegram.bot_id'), 'name' => Setting::get('telegram.bot_name'), 'username' => Setting::get('telegram.bot_username')],
        ])->title(__('telegram.chats.title'));
    }
}
