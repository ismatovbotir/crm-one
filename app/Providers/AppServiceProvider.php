<?php

namespace App\Providers;

use App\Authorization\PermissionMap;
use App\Enums\InternalEvent;
use App\Enums\Locale;
use App\Http\Middleware\EnsureInternalUser;
use App\Http\Middleware\EnsurePortalUser;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\AccountingPeriod;
use App\Models\Agreement;
use App\Models\AgreementTemplate;
use App\Models\Category;
use App\Models\CompanyBankAccount;
use App\Models\CompanySignatory;
use App\Models\Contact;
use App\Models\Counterparty;
use App\Models\CounterpartyDocument;
use App\Models\Customer;
use App\Models\CustomerAsset;
use App\Models\CustomerBankAccount;
use App\Models\Delivery;
use App\Models\Department;
use App\Models\GoodsReceipt;
use App\Models\IntegrationToken;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemAlternative;
use App\Models\Lead;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\PortalMembership;
use App\Models\PurchaseOrder;
use App\Models\ReferenceItem;
use App\Models\Requisition;
use App\Models\ServiceTicket;
use App\Models\StatusTransition;
use App\Models\StockAdjustment;
use App\Models\StockOffAct;
use App\Models\TelegramChat;
use App\Models\TelegramDestination;
use App\Models\Tender;
use App\Models\TenderBid;
use App\Models\Unit;
use App\Models\User;
use App\Policies\CustomerPolicy;
use App\Policies\PartnerPolicy;
use App\Services\Offers\Delivery\LogOfferDelivery;
use App\Services\Offers\Delivery\OfferDelivery;
use App\Services\Reports\ReportRegistry;
use App\Services\Telegram\Internal\InternalNotifications;
use App\Support\DevLoginBypass;
use App\Support\IntegrationApi;
use App\Support\PortalContext;
use Carbon\Laravel\ServiceProvider as CarbonServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Offer delivery (email/Telegram) is out of scope: the stub only records the request.
        $this->app->bind(OfferDelivery::class, LogOfferDelivery::class);

        // Spec 03 4.1: the portal company of the current request (set by EnsurePortalUser).
        $this->app->scoped(PortalContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->mapCarbonLocales();

        DevLoginBypass::assertSafeConfiguration();

        // Roadmap step 3: the reports area is open when at least one report is allowed.
        // Spec 10: curate the price-free portal catalog (category/item visibility, per-customer rules).
        Gate::define('managePortalCatalog', fn (User $user): bool => $user->isInternal() && PermissionMap::allows($user, 'catalog.portal'));
        Gate::define('viewApiDocs', fn (User $user): bool => $user->isInternal() && PermissionMap::allows($user, 'integration.docs'));
        Gate::define('viewReports', fn (User $user): bool => app(ReportRegistry::class)->allowedFor($user) !== []);
        // Spec 01 section 17: item analogues (CRM-local, never overwritten by 1C).
        Gate::define('manageAnalogues', fn (User $user): bool => $user->isInternal() && (PermissionMap::allows($user, 'catalog.manage_alternatives') || PermissionMap::allows($user, 'catalog.edit_local')));
        // Spec 03 section 24.7: Settings > Reference lists (admins and catalog managers).
        Gate::define('manageReferenceLists', fn (User $user): bool => $user->isInternal() && PermissionMap::allows($user, 'catalog.edit_local'));
        Gate::define('manageTelegramNotifications', fn (User $user): bool => $user->isInternal() && PermissionMap::allows($user, 'settings.manage'));

        // Spec 03 section 23: status changes that the company groups may hear about.
        StatusTransition::created(function (StatusTransition $row): void {
            $context = $row->subject_type === 'lead' && $row->from_status === null ? ['source' => Lead::query()->whereKey($row->subject_id)->toBase()->value('source')] : [];
            $event = InternalEvent::fromTransition((string) $row->subject_type, $row->from_status, (string) $row->to_status, $context);

            if ($event !== null && ($subject = $row->subject) !== null) {
                app(InternalNotifications::class)->publish($event, $subject, [], $event->value.':'.$row->subject_type.':'.$row->subject_id);
            }
        });

        // Spec 02 BR-I.20: inbound 1C API, 429 + Retry-After. Laravel's middleware priority runs
        // throttling before token authentication, so the per-token bucket is keyed by the hash of
        // the presented bearer token; a 5x per-IP bucket caps floods of invalid tokens.
        // Spec 08 8.2: public bid-link pages, per IP.
        RateLimiter::for('bid-link', fn (Request $request) => Limit::perMinute(60)->by('bid-link|'.$request->ip()));

        RateLimiter::for('onec-api', function (Request $request): array {
            $perMinute = max(1, (int) config('onec.inbound.rate_per_minute'));
            $tooMany = fn (Request $request, array $headers) => IntegrationApi::error($request, 429, 'rate_limited', 'Too many requests; retry the same batch_id after Retry-After seconds.', headers: $headers);

            return [
                Limit::perMinute($perMinute)->by('onec-api|token:'.hash('sha256', (string) $request->bearerToken()))->response($tooMany),
                Limit::perMinute($perMinute * 5)->by('onec-api|ip:'.$request->ip())->response($tooMany),
            ];
        });

        Relation::enforceMorphMap(self::morphMap());
        // Spec 03 section 24: Partner = all organizations (PartnerPolicy); Customer = its customer-role view (CustomerPolicy).
        Gate::policy(Partner::class, PartnerPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);

        // Re-apply realm and activity checks on every /livewire/update request (Spec 03 section 4.1).
        Livewire::addPersistentMiddleware([EnsureUserIsActive::class, EnsureInternalUser::class, EnsurePortalUser::class]);
    }

    /**
     * Short, stable aliases stored in polymorphic columns (activity_log.subject_type, ...).
     *
     * @return array<string, class-string<Model>>
     */
    public static function morphMap(): array
    {
        return [
            'user' => User::class,
            'department' => Department::class,
            'partner' => Partner::class,
            // Legacy alias (rows written before Spec 03 section 24); new rows use 'partner'.
            'customer' => Customer::class,
            'contact' => Contact::class,
            'item' => Item::class,
            'category' => Category::class,
            'unit' => Unit::class,
            'integration_token' => IntegrationToken::class,
            'offer' => Offer::class,
            'item_alternative' => ItemAlternative::class,
            'order' => Order::class,
            'order_amendment' => OrderAmendment::class,
            'agreement' => Agreement::class,
            'agreement_template' => AgreementTemplate::class,
            'company_bank_account' => CompanyBankAccount::class,
            'company_signatory' => CompanySignatory::class,
            'customer_bank_account' => CustomerBankAccount::class,
            'payment' => Payment::class,
            'invoice' => Invoice::class,
            'accounting_period' => AccountingPeriod::class,
            'delivery' => Delivery::class,
            'goods_receipt' => GoodsReceipt::class,
            'stock_off_act' => StockOffAct::class,
            'stock_adjustment' => StockAdjustment::class,
            'counterparty' => Counterparty::class,
            'counterparty_document' => CounterpartyDocument::class,
            'tender' => Tender::class,
            'tender_bid' => TenderBid::class,
            'purchase_order' => PurchaseOrder::class,
            'portal_membership' => PortalMembership::class,
            'lead' => Lead::class,
            'telegram_chat' => TelegramChat::class,
            'customer_asset' => CustomerAsset::class,
            'service_ticket' => ServiceTicket::class,
            'requisition' => Requisition::class,
            'telegram_destination' => TelegramDestination::class,
            'reference_item' => ReferenceItem::class,
        ];
    }

    /**
     * Carbon follows the app locale on every App::setLocale(), but its plain "uz" is
     * Cyrillic. Route the app locale through Locale::carbonLocale() so Uzbek dates are Latin.
     */
    protected function mapCarbonLocales(): void
    {
        $carbon = $this->app->getProvider(CarbonServiceProvider::class);

        if (! $carbon instanceof CarbonServiceProvider) {
            return;
        }

        $map = fn (string $locale): string => Locale::tryFrom($locale)?->carbonLocale() ?? $locale;

        $carbon->setLocaleGetter(fn (): string => $map($this->app->getLocale()));
        $carbon->setFallbackLocaleGetter(fn (): string => $map($this->app->getFallbackLocale()));
        $carbon->updateLocale();
        $carbon->updateFallbackLocale();
    }
}
