<?php

namespace App\Livewire\Settings;

use App\Actions\Telegram\ManageInternalTelegram;
use App\Enums\InternalEvent;
use App\Enums\Locale;
use App\Enums\TelegramBotStatus;
use App\Models\Setting;
use App\Models\TelegramChat;
use App\Models\TelegramDestination;
use App\Models\TelegramPost;
use App\Services\Telegram\Internal\InternalNotifications;
use App\Services\Telegram\Internal\InternalScheduler;
use App\Services\Telegram\Internal\InternalTelegramException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Settings > Telegram, "Company groups" (Spec 03 section 23): response groups (destinations) with
 * topic, language and amounts switch, the event routing matrix with content levels, test message,
 * bot status/last post/last error, the report schedule and the post log. Gate
 * `manageTelegramNotifications` (settings.manage, admins by default).
 */
class TelegramNotifications extends Component
{
    /** @var array{id: int|null, name: string, chat_id: string, message_thread_id: string, locale: string, include_amounts: bool, is_active: bool} */
    public array $form = ['id' => null, 'name' => '', 'chat_id' => '', 'message_thread_id' => '', 'locale' => 'uz', 'include_amounts' => false, 'is_active' => true];

    public bool $showForm = false;

    public bool $confirmLeadClose = false;

    public string $leadWarning = '';

    /** @var array<int, array<string, string>> destination id => event case name => level|'' (case names: no dots in Livewire paths) */
    public array $matrix = [];

    public string $dailyTime = '18:00';

    public string $weeklyDay = 'mon';

    public string $weeklyTime = '09:00';

    public function mount(): void
    {
        $this->authorize('manageTelegramNotifications');
        $this->dailyTime = InternalScheduler::time('telegram.internal.daily_time', '18:00');
        $this->weeklyDay = in_array($day = (string) (Setting::get('telegram.internal.weekly_day') ?? config('telegram.internal.weekly_day')), InternalScheduler::DAYS, true) ? $day : 'mon';
        $this->weeklyTime = InternalScheduler::time('telegram.internal.weekly_time', '09:00');
        $this->loadMatrix();
    }

    /**
     * @return Collection<int, TelegramDestination>
     */
    #[Computed]
    public function destinations(): Collection
    {
        return TelegramDestination::query()->orderBy('id')->get();
    }

    /**
     * Groups the bot is in (still a member or admin), as candidates for a response group.
     *
     * @return Collection<int, TelegramChat>
     */
    #[Computed]
    public function candidates(): Collection
    {
        return TelegramChat::query()->groups()->whereNull('merged_into_id')->where('bot_status', '!=', TelegramBotStatus::Removed->value)->orderBy('title')->get();
    }

    protected function loadMatrix(): void
    {
        $this->matrix = [];

        foreach ($this->destinations as $destination) {
            foreach (InternalEvent::cases() as $event) {
                $this->matrix[$destination->id][$event->name] = (string) ($destination->levelFor($event) ?? '');
            }
        }
    }

    public function newDestination(?int $chatId = null): void
    {
        $this->authorize('manageTelegramNotifications');
        $chat = $chatId !== null ? $this->candidates->firstWhere('chat_id', $chatId) : null;
        $this->form = ['id' => null, 'name' => $chat?->displayName() ?? ($this->destinations->isEmpty() ? __('telegram.internal.default_name') : ''), 'chat_id' => $chat !== null ? (string) $chat->chat_id : '', 'message_thread_id' => '', 'locale' => 'uz', 'include_amounts' => false, 'is_active' => true];
        $this->showForm = true;
        $this->confirmLeadClose = false;
        $this->leadWarning = '';
        $this->resetErrorBag();
    }

    public function editDestination(int $id): void
    {
        $this->authorize('manageTelegramNotifications');
        $destination = TelegramDestination::query()->findOrFail($id);
        $this->form = ['id' => $destination->id, 'name' => $destination->name, 'chat_id' => (string) $destination->chat_id, 'message_thread_id' => (string) ($destination->message_thread_id ?? ''), 'locale' => $destination->locale, 'include_amounts' => $destination->include_amounts, 'is_active' => $destination->is_active];
        $this->showForm = true;
        $this->confirmLeadClose = false;
        $this->leadWarning = '';
        $this->resetErrorBag();
    }

    public function saveDestination(ManageInternalTelegram $manage): void
    {
        $this->authorize('manageTelegramNotifications');
        $this->validate([
            'form.name' => ['required', 'string', 'max:120'],
            'form.chat_id' => ['required', 'regex:/^-?\d{1,20}$/'],
            'form.message_thread_id' => ['nullable', 'integer', 'min:1'],
            'form.locale' => ['required', 'in:'.implode(',', array_column(Locale::cases(), 'value'))],
        ], [], ['form.name' => __('telegram.internal.fields_ui.name'), 'form.chat_id' => __('telegram.internal.fields_ui.chat_id'), 'form.message_thread_id' => __('telegram.internal.fields_ui.thread')]);

        try {
            $manage->saveDestination($this->form, auth()->user(), $this->form['id'] !== null ? TelegramDestination::query()->findOrFail($this->form['id']) : null, $this->confirmLeadClose);
        } catch (InternalTelegramException $exception) {
            if ($exception->key === 'open_lead') {
                $this->leadWarning = $exception->getMessage();
            } else {
                $this->addError('form.chat_id', $exception->getMessage());
            }

            return;
        }

        $this->showForm = false;
        $this->leadWarning = '';
        unset($this->destinations, $this->candidates);
        $this->loadMatrix();
        session()->flash('status', __('telegram.internal.saved'));
    }

    public function deleteDestination(ManageInternalTelegram $manage, int $id): void
    {
        $this->authorize('manageTelegramNotifications');
        $manage->deleteDestination(TelegramDestination::query()->findOrFail($id), auth()->user());
        unset($this->destinations);
        $this->loadMatrix();
        session()->flash('status', __('telegram.internal.deleted'));
    }

    public function saveRouting(ManageInternalTelegram $manage): void
    {
        $this->authorize('manageTelegramNotifications');

        foreach ($this->destinations as $destination) {
            $levels = [];

            foreach (InternalEvent::cases() as $event) {
                $levels[$event->value] = $this->matrix[$destination->id][$event->name] ?? '';
            }

            $manage->saveRouting($destination, $levels, auth()->user());
        }

        unset($this->destinations);
        $this->loadMatrix();
        session()->flash('status', __('telegram.internal.routing_saved'));
    }

    public function sendTest(InternalNotifications $notifications, int $id): void
    {
        $this->authorize('manageTelegramNotifications');
        $notifications->test(TelegramDestination::query()->findOrFail($id));
        unset($this->destinations);
        session()->flash('status', __('telegram.internal.test_queued'));
    }

    public function saveSchedule(): void
    {
        $this->authorize('manageTelegramNotifications');
        $this->validate([
            'dailyTime' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'weeklyTime' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'weeklyDay' => ['required', 'in:'.implode(',', InternalScheduler::DAYS)],
        ], [], ['dailyTime' => __('telegram.internal.schedule.daily'), 'weeklyTime' => __('telegram.internal.schedule.weekly_time')]);

        Setting::putMany(['telegram.internal.daily_time' => $this->dailyTime, 'telegram.internal.weekly_day' => $this->weeklyDay, 'telegram.internal.weekly_time' => $this->weeklyTime], auth()->user());
        session()->flash('status', __('telegram.internal.schedule_saved'));
    }

    public function render(): View
    {
        $chats = TelegramChat::query()->whereIn('chat_id', $this->destinations->pluck('chat_id'))->get()->keyBy('chat_id');

        return view('livewire.settings.telegram-notifications', [
            'events' => InternalEvent::cases(),
            'chats' => $chats,
            'posts' => TelegramPost::query()->with('destination:id,name')->latest('id')->limit(30)->get(),
            'locales' => Locale::cases(),
        ]);
    }
}
