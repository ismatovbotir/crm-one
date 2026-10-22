<?php

namespace App\Livewire\Settings;

use App\Enums\IntegrationAbility;
use App\Enums\OneCFeed;
use App\Enums\SyncStatus;
use App\Http\Middleware\Integration\VerifyIntegrationSignature;
use App\Models\Customer;
use App\Models\CustomerOutboxEntry;
use App\Models\InboundBatch;
use App\Models\IntegrationFeed;
use App\Models\IntegrationToken;
use App\Models\Setting;
use App\Services\OneC\Customers\CustomerOutbox;
use App\Services\OneC\OneCSettings;
use App\Services\OneC\Outbox\OneCOutbox;
use App\Services\OneC\SimpleToken;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Settings > Integrations, tab "Inbound API (1C -> CRM)" (Spec 02 section 12, US-I.1..I.4):
 * on/off switch, API base URL, tokens (create shown once, rotate, revoke), staleness thresholds,
 * feed status and recent batches. Admin only (SettingPolicy::manage).
 */
#[Layout('layouts.app')]
class Integrations extends Component
{
    public bool $inboundEnabled = false;

    /** @var array<string, int|string> feed => minutes */
    public array $staleMinutes = [];

    /** Customer exchange (Spec 02 rev. 3): 1C polls the CRM outbox; nothing to connect to. */
    public bool $customersEnabled = false;

    /** Spec 02 rev. 4: 1C may create partners when no tax ID matches. */
    public bool $allowCreateFromOneC = true;

    public int|string $customerPollStaleMinutes = 30;

    public string $tokenName = '1C production';

    /** @var list<string> */
    public array $tokenAbilities = [];

    /** IPs/CIDRs, one per line or comma separated; empty = any address. */
    public string $tokenAllowedIps = '';

    public int|string $tokenDays = 180;

    public bool $tokenWithSecret = false;

    /** Plain token / signing secret of the token just issued, shown once until dismissed. */
    #[Locked]
    public ?string $issuedToken = null;

    #[Locked]
    public ?string $issuedSecret = null;

    public function mount(OneCSettings $settings): void
    {
        $this->authorize('manage', Setting::class);

        $this->inboundEnabled = $settings->inboundEnabled();
        $this->customersEnabled = $settings->customersEnabled();
        $this->allowCreateFromOneC = $settings->allowCreateFromOneC();
        $this->customerPollStaleMinutes = $settings->customerPollStaleMinutes();
        $this->tokenAbilities = IntegrationAbility::values();
        $this->tokenDays = (int) config('onec.inbound.token_default_days');

        foreach (OneCFeed::cases() as $feed) {
            $this->staleMinutes[$feed->value] = $settings->staleMinutes($feed);
        }
    }

    public function saveSettings(OneCSettings $settings): void
    {
        $this->authorize('manage', Setting::class);

        $rules = ['inboundEnabled' => ['boolean']];
        $attributes = [];

        foreach (OneCFeed::cases() as $feed) {
            $rules['staleMinutes.'.$feed->value] = ['required', 'integer', 'min:1', 'max:10080'];
            $attributes['staleMinutes.'.$feed->value] = $feed->label();
        }

        $this->validate($rules, [], $attributes);

        $settings->save($this->inboundEnabled, array_map('intval', $this->staleMinutes), auth()->user());

        session()->flash('status', __('integration.settings.saved'));
        $this->redirectRoute('settings.integrations');
    }

    /**
     * Customer exchange switch and the poll staleness threshold. Switching it on queues every
     * pushable not-synced customer (BR-I.22).
     */
    public function saveCustomerExchange(OneCSettings $settings, CustomerOutbox $outbox): void
    {
        $this->authorize('manage', Setting::class);

        $this->validate([
            'customersEnabled' => ['boolean'],
            'customerPollStaleMinutes' => ['required', 'integer', 'min:1', 'max:10080'],
        ], [], ['customerPollStaleMinutes' => __('integration.customer_exchange.poll_stale')]);

        $wasEnabled = $settings->customersEnabled();
        $settings->saveCustomerExchange($this->customersEnabled, (int) $this->customerPollStaleMinutes, auth()->user(), $this->allowCreateFromOneC);

        $queued = ! $wasEnabled && $this->customersEnabled ? $outbox->queueNotSynced(auth()->user()) : 0;

        session()->flash('status', __('integration.customer_exchange.saved', ['count' => $queued]));
        $this->redirectRoute('settings.integrations');
    }

    public function createToken(): void
    {
        $this->authorize('manage', Setting::class);

        $maxDays = (int) config('onec.inbound.token_max_days');
        $this->validate([
            'tokenName' => ['required', 'string', 'max:100'],
            'tokenAbilities' => ['required', 'array', 'min:1'],
            'tokenAbilities.*' => ['string', 'in:'.implode(',', IntegrationAbility::values())],
            'tokenDays' => ['required', 'integer', 'min:1', 'max:'.$maxDays],
            'tokenWithSecret' => ['boolean'],
        ], [], [
            'tokenName' => __('integration.tokens.name'),
            'tokenAbilities' => __('integration.tokens.abilities'),
            'tokenDays' => __('integration.tokens.days'),
        ]);

        $ips = $this->parsedIps();

        if ($ips === null) {
            $this->addError('tokenAllowedIps', __('integration.tokens.ips_invalid'));

            return;
        }

        // BR-I.18a: HMAC is optional only when the token is confined to private networks.
        if (! $this->tokenWithSecret && ! $this->onlyPrivateNetworks($ips)) {
            $this->addError('tokenWithSecret', __('integration.tokens.secret_required'));

            return;
        }

        $this->issue($this->tokenName, $this->tokenAbilities, $ips, (int) $this->tokenDays, $this->tokenWithSecret);
    }

    /**
     * Rotation (Spec 02 11.4): a new token with the same settings; the old one stays active
     * until the admin revokes it after 1C has switched.
     */
    public function rotateToken(int $tokenId): void
    {
        $this->authorize('manage', Setting::class);

        $old = IntegrationToken::query()->active()->findOrFail($tokenId);
        $days = max(1, min((int) config('onec.inbound.token_max_days'), (int) config('onec.inbound.token_default_days')));

        $this->issue($old->name, $old->abilities, $old->allowed_ips, $days, $old->requiresSignature());
    }

    public function revokeToken(int $tokenId): void
    {
        $this->authorize('manage', Setting::class);

        IntegrationToken::query()->findOrFail($tokenId)->revoke(auth()->user());

        session()->flash('status', __('integration.tokens.revoked'));
    }

    /**
     * Generate the 1C API token, or replace it: the previous one stops working immediately.
     */
    public function refreshSimpleToken(SimpleToken $simple): void
    {
        $this->authorize('manage', Setting::class);
        $simple->refresh(auth()->user());
        unset($this->tokens);
        session()->flash('status', __('integration.simple.refreshed'));
    }

    public function dismissIssued(): void
    {
        $this->issuedToken = null;
        $this->issuedSecret = null;
    }

    /**
     * @param  list<string>  $abilities
     * @param  list<string>|null  $ips
     */
    protected function issue(string $name, array $abilities, ?array $ips, int $days, bool $withSecret): void
    {
        $issued = IntegrationToken::issue($name, $abilities, $ips, $days, $withSecret, auth()->user());

        $this->issuedToken = $issued['plain'];
        $this->issuedSecret = $issued['signing_secret'];
        $this->reset('tokenAllowedIps', 'tokenWithSecret');
        unset($this->tokens);
    }

    /**
     * @return list<string>|null null when an entry is invalid
     */
    protected function parsedIps(): ?array
    {
        $entries = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $this->tokenAllowedIps) ?: [])));

        foreach ($entries as $entry) {
            if (! IntegrationToken::isValidIpOrCidr($entry)) {
                return null;
            }
        }

        return $entries;
    }

    /**
     * @param  list<string>  $ips
     */
    protected function onlyPrivateNetworks(array $ips): bool
    {
        if ($ips === []) {
            return false;
        }

        $private = [...VerifyIntegrationSignature::PRIVATE_RANGES, ...config('onec.inbound.private_networks', [])];

        foreach ($ips as $entry) {
            [$ip] = explode('/', $entry);

            if (! IpUtils::checkIp($ip, $private)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return Collection<int, IntegrationToken>
     */
    #[Computed]
    public function tokens(): Collection
    {
        return IntegrationToken::query()->with('creator')->latest('id')->get();
    }

    public function render(OneCSettings $settings, SimpleToken $simple): View
    {
        return view('livewire.settings.integrations', [
            'simpleToken' => $simple->plain(),
            'simpleTokenExpires' => $simple->model()?->expires_at,
            'apiBaseUrl' => $settings->apiBaseUrl(),
            'abilities' => IntegrationAbility::cases(),
            'feeds' => IntegrationFeed::statuses(),
            // Only the columns the table shows (DB review F-03a).
            'batches' => InboundBatch::query()->select(['id', 'batch_id', 'endpoint', 'mode', 'snapshot_part', 'snapshot_total_parts', 'status', 'accepted_count', 'rejected_count', 'counts', 'received_at'])->latest('id')->limit(15)->get(),
            'maxDays' => (int) config('onec.inbound.token_max_days'),
            'customerContacts' => [
                'poll' => IntegrationFeed::lastContact(IntegrationFeed::CUSTOMERS_POLL),
                'ack' => IntegrationFeed::lastContact(IntegrationFeed::CUSTOMERS_ACK),
                'push' => IntegrationFeed::lastContact(IntegrationFeed::CUSTOMERS_PUSH),
            ],
            'pollStale' => $settings->customerPollIsStale(),
            'outbox' => [
                'pending' => CustomerOutboxEntry::query()->open()->count(),
                'oldest' => CustomerOutboxEntry::query()->open()->min('queued_at'),
                'failed' => Customer::query()->where('sync_status', SyncStatus::Failed)->count(),
            ],
            // Spec 06 9.4: document outbox (orders, payments) monitor.
            'documentOutbox' => app(OneCOutbox::class)->stats(),
            'documentContacts' => [
                'poll' => IntegrationFeed::lastContact(IntegrationFeed::OUTBOX_POLL),
                'ack' => IntegrationFeed::lastContact(IntegrationFeed::OUTBOX_ACK),
                'payments' => IntegrationFeed::lastContact(IntegrationFeed::PAYMENTS_PUSH),
                'receipts' => IntegrationFeed::lastContact(IntegrationFeed::GOODS_RECEIPTS_PUSH),
            ],
        ])->title(__('integration.settings.title'));
    }
}
