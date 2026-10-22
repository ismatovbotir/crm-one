<?php

use App\Models\AccountingPeriod;
use App\Models\Agreement;
use App\Models\AgreementTemplate;
use App\Models\CompanyBankAccount;
use App\Models\Counterparty;
use App\Models\Customer;
use App\Models\CustomerAsset;
use App\Models\Delivery;
use App\Models\Department;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\Lead;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\ServiceTicket;
use App\Models\Setting;
use App\Models\StockAdjustment;
use App\Models\StockOffAct;
use App\Models\TelegramChat;
use App\Models\Tender;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Sidebar navigation
|--------------------------------------------------------------------------
|
| Single source for the sidebar (rendered by resources/views/partials/sidebar-nav).
| Adding a module or a settings page is one entry here.
|
| Item keys:
|   label    translation key (lang/{uz,ru,en}/nav.php)
|   icon     <x-icon> name (top-level items and groups)
|   route    route name; omit or set 'soon' => true for modules not built yet
|   active   route pattern(s) that mark the item active (defaults to route)
|   can      [ability, model] checked with the Policy; hides the item when denied
|            (routes enforce the same ability server-side)
|   soon     true = shown disabled with a "Soon" badge
|   children nested items: the entry becomes a collapsible group, auto-expanded
|            when a child is active. Children inherit the group's `can`.
|
*/

return [

    'sections' => [
        [
            'label' => 'nav.sales',
            'items' => [
                ['label' => 'nav.dashboard', 'icon' => 'dashboard', 'route' => 'dashboard'],
                ['label' => 'nav.leads', 'icon' => 'leads', 'route' => 'leads.index', 'active' => 'leads.*', 'can' => ['viewAny', Lead::class]],
                // Spec 04 section 17: in Sales, not Settings (the Settings group stays admin-only).
                ['label' => 'nav.telegram_chats', 'icon' => 'contact', 'route' => 'telegram.chats', 'active' => 'telegram.*', 'can' => ['viewAny', TelegramChat::class]],
                // Spec 03 section 24: Partners (all organizations; each user sees the roles they may see), then the filtered Customers view.
                ['label' => 'nav.partners', 'icon' => 'customers', 'route' => 'partners.index', 'active' => 'partners.*', 'can' => ['viewAny', Partner::class]],
                ['label' => 'nav.customers', 'icon' => 'customers', 'route' => 'customers.index', 'active' => 'customers.*', 'can' => ['viewAny', Customer::class]],
                // Offers go only to partners, so they sit right after Customers; customer requests (Spec 10) follow.
                ['label' => 'nav.offers', 'icon' => 'offers', 'route' => 'offers.index', 'active' => 'offers.*', 'can' => ['viewAny', Offer::class]],
                ['label' => 'nav.customer_requests', 'icon' => 'orders', 'route' => 'requisitions.index', 'active' => 'requisitions.*', 'can' => ['viewAny', Requisition::class]],
                [
                    'key' => 'catalog',
                    'label' => 'nav.catalog',
                    'icon' => 'items',
                    'can' => ['viewAny', Item::class],
                    'children' => [
                        ['label' => 'nav.items', 'route' => 'items.index', 'active' => 'items.*'],
                        ['label' => 'nav.categories', 'route' => 'categories.index'],
                        ['label' => 'nav.label_print', 'route' => 'labels.print', 'active' => 'labels.*'],
                        ['label' => 'nav.serials', 'route' => 'serials.index', 'active' => 'serials.*', 'can' => ['viewAny', ItemSerial::class]],
                    ],
                ],
                ['label' => 'nav.orders', 'icon' => 'orders', 'route' => 'orders.index', 'active' => 'orders.*', 'can' => ['viewAny', Order::class]],
                ['label' => 'nav.agreements', 'icon' => 'agreements', 'route' => 'agreements.index', 'active' => 'agreements.*', 'can' => ['viewAny', Agreement::class]],
            ],
        ],
        [
            // Spec 09: customer equipment and repair tickets (service team; managers and admins read).
            'label' => 'nav.service',
            'items' => [
                ['label' => 'nav.service_tickets', 'icon' => 'settings', 'route' => 'service.tickets', 'active' => 'service.tickets*', 'can' => ['viewAny', ServiceTicket::class]],
                ['label' => 'nav.service_equipment', 'icon' => 'items', 'route' => 'service.assets', 'active' => 'service.assets*', 'can' => ['viewAny', CustomerAsset::class]],
            ],
        ],
        [
            'label' => 'nav.accounting_section',
            'items' => [
                ['label' => 'nav.payments', 'icon' => 'accounting', 'route' => 'payments.index', 'active' => 'payments.*', 'can' => ['viewAny', Payment::class]],
                ['label' => 'nav.invoices', 'icon' => 'invoices', 'route' => 'invoices.index', 'active' => 'invoices.*', 'can' => ['viewAny', Invoice::class]],
                ['label' => 'nav.receivables', 'icon' => 'receivables', 'route' => 'receivables.index', 'can' => ['viewReceivables', AccountingPeriod::class]],
                ['label' => 'nav.accounting_periods', 'icon' => 'lock', 'route' => 'accounting.periods', 'can' => ['viewAny', AccountingPeriod::class]],
            ],
        ],
        [
            'label' => 'nav.warehouse_section',
            'items' => [
                // Flow order (Spec 06 section 25): packaging, then delivery; receipts and reconciliation after.
                ['label' => 'nav.packaging', 'icon' => 'archive', 'route' => 'packaging.index', 'active' => 'packaging.*', 'can' => ['viewPackaging', Order::class]],
                ['label' => 'nav.delivery_notes', 'icon' => 'orders', 'route' => 'delivery-notes.index', 'active' => 'delivery-notes.*', 'can' => ['viewAny', Delivery::class]],
                ['label' => 'nav.goods_receipts', 'icon' => 'items', 'route' => 'goods-receipts.index', 'active' => 'goods-receipts.*', 'can' => ['viewAny', GoodsReceipt::class]],
                // Spec 06 section 27: write-offs follow receipts (damage found at receipt opens an act).
                ['label' => 'nav.stock_off', 'icon' => 'archive', 'route' => 'stock-off.index', 'active' => 'stock-off.*', 'can' => ['viewAny', StockOffAct::class]],
                ['label' => 'nav.stock_adjustments', 'icon' => 'archive', 'route' => 'stock-adjustments.index', 'active' => 'stock-adjustments.*', 'can' => ['viewAny', StockAdjustment::class]],
                ['label' => 'nav.stock_reconciliation', 'icon' => 'receivables', 'route' => 'stock.reconciliation', 'can' => ['viewReconciliation', ItemSerial::class]],
            ],
        ],
        [
            'label' => 'nav.procurement',
            'items' => [
                [
                    'key' => 'tenders',
                    'label' => 'nav.tenders',
                    'icon' => 'offers',
                    // No group `can`: each child follows its own type (Spec 03 section 9 renderer note).
                    'children' => [
                        ['label' => 'nav.tenders_purchase', 'route' => 'tenders.purchase.index', 'active' => 'tenders.purchase.*', 'can' => ['viewType', [Tender::class, 'purchase']]],
                        ['label' => 'nav.tenders_transport', 'route' => 'tenders.transport.index', 'active' => 'tenders.transport.*', 'can' => ['viewType', [Tender::class, 'transport']]],
                    ],
                ],
                ['label' => 'nav.counterparties', 'icon' => 'customers', 'route' => 'counterparties.index', 'active' => 'counterparties.*', 'can' => ['viewAny', Counterparty::class]],
                ['label' => 'nav.purchase_orders', 'icon' => 'orders', 'route' => 'purchase-orders.index', 'active' => 'purchase-orders.*', 'can' => ['viewAny', PurchaseOrder::class]],
                ['label' => 'nav.approvals', 'icon' => 'check', 'route' => 'approvals.index', 'can' => ['viewAny', Tender::class]],
                ['label' => 'nav.shipments', 'icon' => 'clock', 'soon' => true, 'can' => ['viewType', [Tender::class, 'transport']]],
            ],
        ],
        [
            'label' => 'nav.analytics',
            'items' => [
                ['label' => 'nav.reports', 'icon' => 'receivables', 'route' => 'reports.index', 'active' => 'reports.*', 'can' => ['viewReports']],
            ],
        ],
        [
            'label' => 'nav.administration',
            'items' => [
                ['label' => 'nav.users', 'icon' => 'users', 'route' => 'users.index', 'active' => 'users.*', 'can' => ['viewAny', User::class]],
                ['label' => 'nav.departments', 'icon' => 'departments', 'route' => 'departments.index', 'active' => 'departments.*', 'can' => ['viewAny', Department::class]],
                [
                    'key' => 'settings',
                    'label' => 'nav.settings',
                    'icon' => 'settings',
                    // No group `can`: each child carries its own (finance and managers reach Company
                    // and Agreement templates; the rest stays admin-only).
                    'children' => [
                        ['label' => 'nav.settings_setup', 'route' => 'settings.setup', 'can' => ['manage', Setting::class]],
                        ['label' => 'nav.settings_appearance', 'route' => 'settings.appearance', 'can' => ['manage', Setting::class]],
                        ['label' => 'nav.settings_accounting', 'route' => 'settings.accounting', 'can' => ['viewSettings', AccountingPeriod::class]],
                        // Spec 03 section 24.7: managed lists (industry, currency, positions, supplier categories); admins and catalog managers.
                        ['label' => 'nav.reference_lists', 'route' => 'settings.reference-lists', 'can' => ['manageReferenceLists']],
                        ['label' => 'nav.settings_company', 'route' => 'settings.company', 'can' => ['manage', CompanyBankAccount::class]],
                        ['label' => 'nav.settings_agreement_templates', 'route' => 'settings.agreement-templates', 'active' => 'settings.agreement-templates*', 'can' => ['manage', AgreementTemplate::class]],
                        ['label' => 'nav.settings_label_templates', 'route' => 'settings.label-templates', 'active' => 'settings.label-templates*', 'can' => ['manage', Setting::class]],
                        ['label' => 'nav.settings_integrations', 'route' => 'settings.integrations', 'can' => ['manage', Setting::class]],
                        ['label' => 'nav.settings_api_docs', 'route' => 'api-docs', 'new_window' => true, 'can' => ['viewApiDocs']],
                        ['label' => 'nav.settings_telegram', 'route' => 'settings.telegram', 'can' => ['manage', Setting::class]],
                        ['label' => 'nav.settings_import', 'route' => 'settings.import', 'can' => ['manage', Setting::class]],
                    ],
                ],
            ],
        ],
        [
            'label' => 'nav.help',
            'items' => [
                ['label' => 'nav.guide', 'icon' => 'catalog', 'route' => 'guide'],
            ],
        ],
    ],

    /*
    | Customer portal top navigation (Spec 03 section 9). 'finance' => true: only portal_admin,
    | approver and finance roles; 'admin' => true: only portal_admin.
    */
    'portal_sections' => [
        ['label' => 'portal.nav.dashboard', 'route' => 'portal.dashboard'],
        ['label' => 'portal.nav.offers', 'route' => 'portal.offers.index', 'active' => 'portal.offers.*'],
        ['label' => 'portal.nav.orders', 'route' => 'portal.orders.index', 'active' => 'portal.orders.*'],
        ['label' => 'portal.nav.agreements', 'route' => 'portal.agreements.index', 'finance' => true],
        ['label' => 'portal.nav.invoices', 'route' => 'portal.invoices.index', 'finance' => true],
        ['label' => 'portal.nav.catalog', 'route' => 'portal.catalog'],
        ['label' => 'portal.nav.requisitions', 'route' => 'portal.requisitions', 'active' => 'portal.requisitions*'],
        ['label' => 'portal.nav.equipment', 'route' => 'portal.equipment', 'active' => 'portal.equipment*'],
        ['label' => 'portal.nav.warranty', 'route' => 'portal.warranty'],
        ['label' => 'portal.nav.users', 'route' => 'portal.users', 'admin' => true],
    ],

];
