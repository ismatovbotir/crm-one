<?php

namespace App\Livewire;

use App\Livewire\Concerns\ManagesTelegramLink;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Staff "My cabinet" (Spec 03 section 9): own Telegram link.
 */
#[Layout('layouts.app')]
class Cabinet extends Component
{
    use ManagesTelegramLink;

    protected function cabinetUser(): User
    {
        return auth()->user();
    }

    public function render(TelegramClient $client): View
    {
        return view('livewire.cabinet', ['link' => $this->telegramLink(), 'botEnabled' => $client->enabled(), 'user' => $this->cabinetUser()])->title(__('telegram.cabinet.title'));
    }
}
