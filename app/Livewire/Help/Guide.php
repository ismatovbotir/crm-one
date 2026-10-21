<?php

namespace App\Livewire\Help;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Short in-app user guide per role (roadmap step 10), uz/ru/en from lang/<locale>/guide.php.
 * Opens on the reader's own role; every staff user may read the other roles too (how the
 * colleagues' steps fit together). Links are shown only where the reader may open the page.
 */
#[Layout('layouts.app')]
class Guide extends Component
{
    /**
     * Steps per section, each with the page it links to (null = no link).
     *
     * @var array<string, array<string, string|null>>
     */
    public const SECTIONS = [
        'general' => ['flow' => null, 'sign_in' => 'cabinet', 'search' => 'search', 'notifications' => 'notifications.preferences', 'language' => null],
        'sales' => ['leads' => 'leads.create', 'qualify' => 'leads.index', 'convert' => 'leads.index', 'offers' => 'offers.index', 'orders' => 'orders.index', 'power_of_attorney' => 'orders.index', 'follow' => 'receivables.index', 'customers' => 'customers.index', 'partners' => 'partners.index', 'requests' => 'requisitions.index', 'service' => 'service.tickets', 'create_from_card' => 'customers.index'],
        'manager' => ['approvals' => 'approvals.index', 'assign' => 'leads.index', 'dashboard' => 'dashboard', 'reports' => 'reports.index', 'reference_lists' => 'settings.reference-lists', 'portal_catalog' => 'categories.index'],
        'accountant' => ['agreements' => 'agreements.index', 'invoices' => 'invoices.index', 'order_payments' => 'payments.for-orders', 'payments' => 'payments.index', 'receivables' => 'receivables.index', 'onec' => null],
        'finance' => ['payments' => 'payments.index', 'voids' => 'payments.index', 'periods' => 'accounting.periods', 'credit' => 'customers.index', 'reports' => 'reports.index'],
        'stock_manager' => ['receipts' => 'goods-receipts.index', 'stock_off' => 'stock-off.index', 'serials' => 'serials.index', 'packaging' => 'packaging.index', 'delivery' => 'delivery-notes.index', 'reconciliation' => 'stock.reconciliation'],
        'service_engineer' => ['queue' => 'service.tickets', 'accept' => 'service.tickets', 'repair' => 'service.tickets', 'close' => 'service.tickets', 'equipment' => 'service.assets'],
        'staff' => ['purchase' => 'tenders.purchase.index', 'transport' => 'tenders.transport.index', 'counterparties' => 'counterparties.index', 'purchase_orders' => 'purchase-orders.index'],
        'admin' => ['setup' => 'settings.setup', 'users' => 'users.index', 'import' => 'settings.import', 'integrations' => 'settings.integrations', 'telegram_groups' => 'settings.telegram', 'operations' => null],
    ];

    #[Url(as: 'role', except: '')]
    public string $section = '';

    public function mount(): void
    {
        if (! array_key_exists($this->section, self::SECTIONS)) {
            $role = (string) auth()->user()->role->value;
            $this->section = array_key_exists($role, self::SECTIONS) ? $role : 'general';
        }
    }

    public function render(): View
    {
        $section = array_key_exists($this->section, self::SECTIONS) ? $this->section : 'general';
        $user = auth()->user();
        $steps = [];

        foreach (self::SECTIONS[$section] as $key => $route) {
            $href = null;

            if ($route !== null && Route::has($route)) {
                $href = $this->mayOpen($route) ? route($route) : null;
            }

            $steps[] = ['key' => $key, 'href' => $href];
        }

        return view('livewire.help.guide', [
            'current' => $section,
            'steps' => $steps,
            'own' => (string) $user->role->value,
        ])->title(__('guide.title'));
    }

    /**
     * Same rule as the sidebar: the `can` of the navigation entry for that route
     * (config/navigation.php); routes outside the sidebar fall back to their `can:` middleware.
     */
    protected function mayOpen(string $name): bool
    {
        $user = auth()->user();
        $navigation = $this->navigationRules((array) config('navigation.sections'));

        if (array_key_exists($name, $navigation)) {
            return $navigation[$name] === null || $user->can(...$navigation[$name]);
        }

        foreach (Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [] as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                $arguments = explode(',', substr($middleware, 4));

                if (! $user->can(array_shift($arguments), $arguments)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array<int, mixed>|null  $inherited  `can` of the enclosing group
     * @return array<string, array<int, mixed>|null> route name => `can` arguments
     */
    protected function navigationRules(array $entries, ?array $inherited = null): array
    {
        $rules = [];

        foreach ($entries as $entry) {
            $can = $entry['can'] ?? $inherited;

            if (isset($entry['route'])) {
                $rules[$entry['route']] = $can;
            }

            foreach (['items', 'children'] as $nested) {
                if (isset($entry[$nested])) {
                    $rules += $this->navigationRules($entry[$nested], $can);
                }
            }
        }

        return $rules;
    }
}
