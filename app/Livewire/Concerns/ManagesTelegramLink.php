<?php

namespace App\Livewire\Concerns;

use App\Models\TelegramLink;
use App\Models\User;
use App\Services\Telegram\Exceptions\TelegramMatchException;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramLinks;
use App\Services\Telegram\TelegramMatching;

/**
 * "My cabinet" Telegram section for staff and portal users (Spec 03 4.5): link through a
 * single-use deep link, see the status (linked, blocked), unlink.
 */
trait ManagesTelegramLink
{
    /** Deep link shown after "Link" (single use, 10 minutes). */
    public string $deepLink = '';

    /** Code from the bot that confirms an administrator's link (Spec 03 section 17.10). */
    public string $verificationCode = '';

    public bool $codeSent = false;

    abstract protected function cabinetUser(): User;

    public function createTelegramLink(TelegramLinks $links, TelegramClient $client): void
    {
        if (! $client->enabled()) {
            session()->flash('error', __('telegram.cabinet.bot_off'));

            return;
        }

        $this->deepLink = (string) ($links->createToken($this->cabinetUser())['url'] ?? '');
    }

    public function unlinkTelegram(TelegramLinks $links): void
    {
        $links->unlink($this->cabinetUser(), $this->cabinetUser());
        $this->deepLink = '';
        session()->flash('status', __('telegram.cabinet.unlinked'));
    }

    public function sendVerificationCode(TelegramMatching $matching): void
    {
        try {
            $matching->sendCode($this->cabinetUser());
            $this->codeSent = true;
            session()->flash('status', __('telegram.matching.code_sent'));
        } catch (TelegramMatchException $exception) {
            $this->addError('verificationCode', $exception->getMessage());
        }
    }

    public function confirmTelegram(TelegramMatching $matching): void
    {
        $this->validate(['verificationCode' => ['required', 'digits:6']], [], ['verificationCode' => __('telegram.matching.code')]);

        try {
            $matching->confirm($this->cabinetUser(), $this->verificationCode);
        } catch (TelegramMatchException $exception) {
            $this->addError('verificationCode', $exception->getMessage());

            return;
        }

        $this->reset('verificationCode', 'codeSent');
        session()->flash('status', __('telegram.matching.confirmed'));
    }

    protected function telegramLink(): ?TelegramLink
    {
        return TelegramLink::query()->where('user_id', $this->cabinetUser()->id)->first();
    }
}
