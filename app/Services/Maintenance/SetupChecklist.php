<?php

namespace App\Services\Maintenance;

use App\Enums\UserRole;
use App\Models\AgreementTemplate;
use App\Models\CompanyBankAccount;
use App\Models\CompanySignatory;
use App\Models\Department;
use App\Models\IntegrationToken;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Telegram\TelegramClient;
use App\Support\BusinessSettings;
use App\Support\CompanyProfile;
use Illuminate\Support\Facades\Cache;

/**
 * Initial setup wizard checks (roadmap step 7): what must be in place before go-live, each with a
 * live status and the screen where it is done. Required steps block "ready"; optional ones warn.
 */
class SetupChecklist
{
    public const HEARTBEAT_KEY = 'scheduler:heartbeat';

    /**
     * @return list<array{key: string, done: bool, required: bool, detail: string|null, route: string|null}>
     */
    public function steps(): array
    {
        $base = (string) config('crm.base_currency', 'UZS');
        $roles = [UserRole::Sales, UserRole::Accountant, UserRole::Finance, UserRole::StockManager];
        $missingRoles = array_values(array_filter($roles, fn (UserRole $role): bool => ! User::query()->where('role', $role)->where('is_active', true)->where('email', 'not like', '%'.DemoDataWiper::DEMO_DOMAIN)->exists()));
        $ceo = Department::query()->where('code', 'management')->first()?->members()->wherePivot('role', 'head')->exists() ?? false;
        $heartbeat = (int) Cache::get(self::HEARTBEAT_KEY, 0);

        return [
            ['key' => 'company', 'done' => filled(CompanyProfile::get('tax_id')) && filled(CompanyProfile::get('legal_name')), 'required' => true, 'detail' => null, 'route' => 'settings.company'],
            ['key' => 'signatory', 'done' => CompanySignatory::default() !== null, 'required' => true, 'detail' => CompanySignatory::default()?->full_name, 'route' => 'settings.company'],
            ['key' => 'bank', 'done' => CompanyBankAccount::defaultFor($base) !== null, 'required' => true, 'detail' => $base, 'route' => 'settings.company'],
            ['key' => 'templates', 'done' => AgreementTemplate::query()->get()->contains(fn (AgreementTemplate $template): bool => $template->publishedVersion('uz') !== null), 'required' => true, 'detail' => null, 'route' => 'settings.agreement-templates'],
            ['key' => 'onec_token', 'done' => IntegrationToken::query()->active()->exists(), 'required' => true, 'detail' => null, 'route' => 'settings.integrations'],
            ['key' => 'warehouses', 'done' => Warehouse::query()->where('is_active', true)->exists(), 'required' => true, 'detail' => (string) Warehouse::query()->where('is_active', true)->count(), 'route' => null],
            ['key' => 'roles', 'done' => $missingRoles === [] && $ceo, 'required' => true, 'detail' => implode(', ', array_map(fn (UserRole $role): string => $role->label(), $missingRoles)).($ceo ? '' : ($missingRoles === [] ? '' : ', ').__('setup.ceo')), 'route' => 'users.index'],
            ['key' => 'scheduler', 'done' => $heartbeat > now()->subMinutes(5)->timestamp, 'required' => true, 'detail' => $heartbeat > 0 ? date('Y-m-d H:i', $heartbeat) : null, 'route' => null],
            ['key' => 'two_factor', 'done' => BusinessSettings::bool('security.enforce_two_factor'), 'required' => false, 'detail' => null, 'route' => 'settings.accounting'],
            ['key' => 'telegram', 'done' => app(TelegramClient::class)->enabled(), 'required' => false, 'detail' => null, 'route' => 'settings.telegram'],
            ['key' => 'demo_data', 'done' => ! User::query()->where('email', 'like', '%'.DemoDataWiper::DEMO_DOMAIN)->exists(), 'required' => false, 'detail' => 'php artisan crm:wipe-demo', 'route' => null],
        ];
    }

    /**
     * @param  list<array{key: string, done: bool, required: bool, detail: string|null, route: string|null}>|null  $steps  already computed steps
     */
    public function ready(?array $steps = null): bool
    {
        return collect($steps ?? $this->steps())->every(fn (array $step): bool => $step['done'] || ! $step['required']);
    }
}
