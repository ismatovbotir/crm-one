<?php

namespace App\Livewire\Portal;

use App\Livewire\Concerns\ManagesTelegramLink;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Support\PortalContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Portal "My cabinet" (Spec 03 section 9): company, roles and the own Telegram link (used for
 * Telegram login codes).
 */
#[Layout('layouts.portal')]
class Cabinet extends Component
{
    use ManagesTelegramLink;

    protected function cabinetUser(): User
    {
        return app(PortalContext::class)->user();
    }

    public function render(TelegramClient $client, PortalContext $context): View
    {
        return view('livewire.cabinet', ['link' => $this->telegramLink(), 'botEnabled' => $client->enabled(), 'user' => $this->cabinetUser(), 'membership' => $context->membership()])->title(__('telegram.cabinet.title'));
    }
}
