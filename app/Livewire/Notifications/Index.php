<?php

namespace App\Livewire\Notifications;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Notification center (roadmap step 4): the user's in-app notifications, newest first; filter
 * unread; open (marks read), mark read, mark all read. Only the signed-in user's notifications.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: false)]
    public bool $unread = false;

    public function updatedUnread(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, DatabaseNotification>
     */
    #[Computed]
    public function notifications(): LengthAwarePaginator
    {
        return auth()->user()->notifications()->when($this->unread, fn ($query) => $query->whereNull('read_at'))->paginate(config('crm.per_page'));
    }

    public function open(string $id): void
    {
        $notification = auth()->user()->notifications()->findOrFail($id);
        $notification->markAsRead();
        $url = (string) ($notification->data['url'] ?? '');

        // Only local paths: no "//host", no "/\host" (browsers treat it as "//"), no control characters.
        $this->redirect(preg_match('#^/(?![/\\\\])#', $url) === 1 && ! preg_match('/[[:cntrl:]]/', $url) ? $url : route('notifications.index'), navigate: true);
    }

    public function markRead(string $id): void
    {
        auth()->user()->notifications()->findOrFail($id)->markAsRead();
        unset($this->notifications);
    }

    public function markAllRead(): void
    {
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);
        unset($this->notifications);
    }

    public function render(): View
    {
        return view('livewire.notifications.index')->title(__('notifications.title'));
    }
}
