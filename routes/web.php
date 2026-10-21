<?php

use App\Http\Controllers\AgreementPrintController;
use App\Http\Controllers\ApiDocsController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PortalLogoutController;
use App\Http\Controllers\DevRoleSwitchController;
use App\Http\Controllers\ImportTemplateController;
use App\Http\Controllers\InvoicePrintController;
use App\Http\Controllers\ItemSerialFilesController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\OfferPrintController;
use App\Http\Controllers\OrderPrintController;
use App\Http\Controllers\PartnerCardController;
use App\Http\Controllers\PaymentReminderPrintController;
use App\Http\Controllers\PortalPrintController;
use App\Http\Controllers\PrintSampleController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\RequisitionFilesController;
use App\Http\Controllers\ServiceDocumentsController;
use App\Http\Controllers\StockPrintController;
use App\Http\Controllers\TenderPrintController;
use App\Http\Middleware\BidLinkHeaders;
use App\Http\Middleware\EnsureInternalUser;
use App\Http\Middleware\EnsurePortalUser;
use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\EnsureUserIsActive;
use App\Livewire\Accounting\Periods as AccountingPeriods;
use App\Livewire\Accounting\Receivables;
use App\Livewire\Agreements\Index as AgreementIndex;
use App\Livewire\Agreements\Show as AgreementShow;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Auth\TwoFactorChallenge;
use App\Livewire\BidLink\Page as BidLinkPage;
use App\Livewire\Cabinet;
use App\Livewire\Catalog\Categories as CategoryIndex;
use App\Livewire\Catalog\Items\Index as ItemIndex;
use App\Livewire\Catalog\Items\Show as ItemShow;
use App\Livewire\Counterparties\Index as CounterpartyIndex;
use App\Livewire\Counterparties\Show as CounterpartyShow;
use App\Livewire\Customers\Form as CustomerForm;
use App\Livewire\Customers\Index as CustomerIndex;
use App\Livewire\Customers\Show as CustomerShow;
use App\Livewire\Dashboard;
use App\Livewire\Departments\Form as DepartmentForm;
use App\Livewire\Departments\Index as DepartmentIndex;
use App\Livewire\Help\Guide;
use App\Livewire\Invoices\Index as InvoiceIndex;
use App\Livewire\Invoices\Show as InvoiceShow;
use App\Livewire\Leads\Form as LeadForm;
use App\Livewire\Leads\Index as LeadIndex;
use App\Livewire\Leads\Show as LeadShow;
use App\Livewire\Notifications\Index as NotificationIndex;
use App\Livewire\Notifications\Preferences as NotificationPreferences;
use App\Livewire\Offers\Builder as OfferBuilder;
use App\Livewire\Offers\Create as OfferCreate;
use App\Livewire\Offers\Index as OfferIndex;
use App\Livewire\Offers\Show as OfferShow;
use App\Livewire\Orders\Amendments as OrderAmendments;
use App\Livewire\Orders\Create as OrderCreate;
use App\Livewire\Orders\Index as OrderIndex;
use App\Livewire\Orders\Show as OrderShow;
use App\Livewire\Partners\Form as PartnerForm;
use App\Livewire\Partners\Index as PartnerIndex;
use App\Livewire\Payments\Create as PaymentCreate;
use App\Livewire\Payments\ForOrders as PaymentForOrders;
use App\Livewire\Payments\Index as PaymentIndex;
use App\Livewire\Payments\Show as PaymentShow;
use App\Livewire\Portal\Cabinet as PortalCabinet;
use App\Livewire\Portal\Catalog as PortalCatalog;
use App\Livewire\Portal\Dashboard as PortalDashboard;
use App\Livewire\Portal\Documents as PortalDocuments;
use App\Livewire\Portal\Equipment as PortalEquipment;
use App\Livewire\Portal\EquipmentShow as PortalEquipmentShow;
use App\Livewire\Portal\Login as PortalLogin;
use App\Livewire\Portal\Offers as PortalOffers;
use App\Livewire\Portal\OfferShow as PortalOfferShow;
use App\Livewire\Portal\Orders as PortalOrders;
use App\Livewire\Portal\OrderShow as PortalOrderShow;
use App\Livewire\Portal\Requisitions as PortalRequisitions;
use App\Livewire\Portal\RequisitionShow as PortalRequisitionShow;
use App\Livewire\Portal\Users as PortalUsers;
use App\Livewire\Portal\Warranty as PortalWarranty;
use App\Livewire\PurchaseOrders\Index as PurchaseOrderIndex;
use App\Livewire\PurchaseOrders\Show as PurchaseOrderShow;
use App\Livewire\Reports\Index as ReportIndex;
use App\Livewire\Reports\Show as ReportShow;
use App\Livewire\Requisitions\Index as RequisitionIndex;
use App\Livewire\Requisitions\Show as RequisitionShow;
use App\Livewire\Search\Index as SearchIndex;
use App\Livewire\Service\Assets as ServiceAssets;
use App\Livewire\Service\AssetShow as ServiceAssetShow;
use App\Livewire\Service\Tickets as ServiceTickets;
use App\Livewire\Service\TicketShow as ServiceTicketShow;
use App\Livewire\Settings\Accounting as AccountingSettings;
use App\Livewire\Settings\AgreementTemplateEditor;
use App\Livewire\Settings\AgreementTemplates;
use App\Livewire\Settings\Appearance;
use App\Livewire\Settings\Company;
use App\Livewire\Settings\DataImport;
use App\Livewire\Settings\Integrations;
use App\Livewire\Settings\ReferenceLists;
use App\Livewire\Settings\SetupWizard;
use App\Livewire\Settings\Telegram as TelegramSettings;
use App\Livewire\Stock\Deliveries;
use App\Livewire\Stock\DeliveryShow;
use App\Livewire\Stock\GoodsReceipts;
use App\Livewire\Stock\GoodsReceiptShow;
use App\Livewire\Stock\PackagingQueue;
use App\Livewire\Stock\ReconciliationReport;
use App\Livewire\Stock\Serials;
use App\Livewire\Stock\SerialShow;
use App\Livewire\Stock\StockAdjustments;
use App\Livewire\Stock\StockAdjustmentShow;
use App\Livewire\Stock\StockOffActs;
use App\Livewire\Stock\StockOffActShow;
use App\Livewire\Telegram\ChatsIndex as TelegramChatsIndex;
use App\Livewire\Tenders\Approvals as TenderApprovals;
use App\Livewire\Tenders\Comparison as TenderComparison;
use App\Livewire\Tenders\Index as TenderIndex;
use App\Livewire\Tenders\Show as TenderShow;
use App\Livewire\Users\Form as UserForm;
use App\Livewire\Users\Index as UserIndex;
use App\Models\AccountingPeriod;
use App\Models\Agreement;
use App\Models\AgreementTemplate;
use App\Models\Category;
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
use Illuminate\Support\Facades\Route;

// Interface language switcher: available to guests (login pages) and signed-in users.
Route::post('/locale', LocaleController::class)->name('locale.update');

// Development only: role switcher in the top bar (never registered outside APP_ENV=local).
if (app()->environment('local')) {
    Route::post('/dev/switch-user', DevRoleSwitchController::class)->middleware('auth')->name('dev.switch-user');
}

// Public bid links (Spec 08 8.2): token-scoped, outside both realms; no-referrer/no-store headers.
// 1C integration API documentation (Swagger UI + OpenAPI). Open locally; staff with `integration.docs` elsewhere (checked in the controller).
Route::get('/api/docs', [ApiDocsController::class, 'ui'])->name('api-docs')->middleware('throttle:60,1');
Route::get('/api/docs/openapi.json', [ApiDocsController::class, 'json'])->name('api-docs.json')->middleware('throttle:60,1');
Route::get('/api/docs/openapi.yaml', [ApiDocsController::class, 'yaml'])->name('api-docs.yaml')->middleware('throttle:60,1');

Route::livewire('/bid/{token}', BidLinkPage::class)->name('bid.show')->middleware(['throttle:bid-link', BidLinkHeaders::class]);

// Pictures from the private disk for staff and portal users (Spec 01 section 15); the controller authorizes.
Route::middleware('throttle:600,1')->group(function () {
    Route::get('/media/item-images/{image}/{variant}', [MediaController::class, 'itemImage'])->name('media.item-image');
    Route::get('/media/offer-lines/{line}/image', [MediaController::class, 'offerLineImage'])->name('media.offer-line-image');
    Route::get('/media/order-lines/{line}/image', [MediaController::class, 'orderLineImage'])->name('media.order-line-image');
    // Spec 09: equipment and repair photos (staff with view rights, or the owning company's portal users).
    Route::get('/media/assets/{asset}/photo', [ServiceDocumentsController::class, 'assetPhoto'])->name('service.photos.asset');
    Route::get('/media/service-photos/{photo}', [ServiceDocumentsController::class, 'ticketPhoto'])->name('service.photos.ticket');
});

// Customer portal (Spec 03 O-6, P-4): OTP login, then the portal realm only (EnsurePortalUser).
Route::livewire('/portal/login', PortalLogin::class)->name('portal.login')->middleware('throttle:60,1');
Route::prefix('portal')->name('portal.')->middleware(EnsurePortalUser::class)->group(function () {
    Route::livewire('/', PortalDashboard::class)->name('dashboard');
    Route::livewire('/offers', PortalOffers::class)->name('offers.index');
    Route::livewire('/offers/{offer:uuid}', PortalOfferShow::class)->name('offers.show');
    Route::get('/offers/{offer:uuid}/print', [PortalPrintController::class, 'offer'])->name('offers.print');
    Route::livewire('/orders', PortalOrders::class)->name('orders.index');
    Route::livewire('/orders/{order:uuid}', PortalOrderShow::class)->name('orders.show');
    Route::get('/orders/{order:uuid}/print', [PortalPrintController::class, 'order'])->name('orders.print');
    Route::get('/deliveries/{delivery:uuid}/print', [PortalPrintController::class, 'delivery'])->name('deliveries.print');
    Route::get('/deliveries/{delivery:uuid}/warranty', [PortalPrintController::class, 'warranty'])->name('deliveries.warranty');
    Route::livewire('/agreements', PortalDocuments::class)->name('agreements.index')->defaults('kind', 'agreements');
    Route::get('/agreements/{agreement:uuid}/print', [PortalPrintController::class, 'agreement'])->name('agreements.print');
    Route::livewire('/invoices', PortalDocuments::class)->name('invoices.index')->defaults('kind', 'invoices');
    Route::get('/invoices/{invoice:uuid}/print', [PortalPrintController::class, 'invoice'])->name('invoices.print');
    Route::livewire('/warranty', PortalWarranty::class)->name('warranty');
    // Spec 09: the company's equipment and repair requests (PortalScope).
    // Spec 10: price-free catalog and the company's requests.
    Route::livewire('/catalog', PortalCatalog::class)->name('catalog');
    Route::livewire('/requests', PortalRequisitions::class)->name('requisitions');
    Route::livewire('/requests/{requisition}', PortalRequisitionShow::class)->name('requisitions.show');
    Route::get('/requests/{requisition}/files/{attachment}', RequisitionFilesController::class)->name('requisitions.attachment');
    Route::livewire('/equipment', PortalEquipment::class)->name('equipment');
    Route::get('/equipment/export.csv', [ServiceDocumentsController::class, 'portalExport'])->name('equipment.export')->middleware('throttle:20,1');
    Route::livewire('/equipment/{asset}', PortalEquipmentShow::class)->name('equipment.show');
    Route::get('/service/{ticket}/print', [ServiceDocumentsController::class, 'portalReceipt'])->name('service.print');
    Route::livewire('/users', PortalUsers::class)->name('users');
    Route::livewire('/cabinet', PortalCabinet::class)->name('cabinet');
    Route::post('/logout', PortalLogoutController::class)->name('logout');
});

Route::middleware('guest')->group(function () {
    Route::livewire('/login', Login::class)->name('login');
    Route::livewire('/forgot-password', ForgotPassword::class)->name('password.request');
    Route::livewire('/reset-password/{token}', ResetPassword::class)->name('password.reset');
    Route::livewire('/two-factor-challenge', TwoFactorChallenge::class)->name('two-factor.challenge')->middleware('throttle:30,1');
});

Route::middleware(['auth', EnsureUserIsActive::class, EnsureInternalUser::class, EnsureTwoFactorEnrolled::class])->group(function () {
    Route::redirect('/', '/dashboard');
    Route::livewire('/dashboard', Dashboard::class)->name('dashboard');

    Route::livewire('/customers', CustomerIndex::class)->name('customers.index')->can('viewAny', Customer::class);
    // Spec 03 section 24: Partners = all organizations; "New customer" opens the unified form with the customer role.
    Route::livewire('/settings/reference-lists', ReferenceLists::class)->name('settings.reference-lists')->can('manageReferenceLists');
    Route::livewire('/partners', PartnerIndex::class)->name('partners.index')->can('viewAny', Partner::class);
    Route::livewire('/partners/create', PartnerForm::class)->name('partners.create')->can('create', Partner::class);
    Route::get('/partners/{partner}', PartnerCardController::class)->name('partners.show')->withTrashed();
    Route::livewire('/customers/create', PartnerForm::class)->name('customers.create')->can('create', Customer::class);
    Route::livewire('/customers/{customer}', CustomerShow::class)->name('customers.show')->can('view', 'customer')->withTrashed();
    Route::livewire('/customers/{customer}/edit', CustomerForm::class)->name('customers.edit')->can('edit', 'customer');

    // Catalog: read-only mirror of 1C (Spec 02); only CRM-local fields are editable.
    Route::livewire('/items', ItemIndex::class)->name('items.index')->can('viewAny', Item::class);
    Route::livewire('/items/{item}', ItemShow::class)->name('items.show')->can('view', 'item')->withTrashed();
    Route::get('/items/{item}/serials/template', [ItemSerialFilesController::class, 'template'])->name('items.serials.template');
    Route::get('/items/{item}/serials/export', [ItemSerialFilesController::class, 'export'])->name('items.serials.export');
    Route::livewire('/catalog/categories', CategoryIndex::class)->name('categories.index')->can('viewAny', Category::class);

    // Global search (roadmap step 5): each group checks its own permissions.
    Route::livewire('/search', SearchIndex::class)->name('search')->middleware('throttle:120,1');

    // Notification center and preferences (roadmap step 4).
    Route::livewire('/notifications', NotificationIndex::class)->name('notifications.index');
    Route::livewire('/notifications/preferences', NotificationPreferences::class)->name('notifications.preferences');

    // Reports with CSV/XLSX export (roadmap step 3); each report checks its own permission.
    Route::livewire('/reports', ReportIndex::class)->name('reports.index')->can('viewReports');
    Route::livewire('/reports/{report}', ReportShow::class)->name('reports.show')->can('viewReports');
    Route::get('/reports/{report}/export/{format}', ReportExportController::class)->name('reports.export')->can('viewReports')->middleware('throttle:20,1');

    // Leads (Spec 04): list, capture, detail, edit.
    Route::livewire('/leads', LeadIndex::class)->name('leads.index')->can('viewAny', Lead::class);
    // Spec 10: customer requests sent to us (auto-assigned to the customer's owner).
    Route::livewire('/requests', RequisitionIndex::class)->name('requisitions.index')->can('viewAny', Requisition::class);
    Route::livewire('/requests/{requisition}', RequisitionShow::class)->name('requisitions.show');
    // Spec 09: service tickets and customer equipment.
    Route::livewire('/service/tickets', ServiceTickets::class)->name('service.tickets')->can('viewAny', ServiceTicket::class);
    Route::livewire('/service/tickets/{ticket}', ServiceTicketShow::class)->name('service.tickets.show');
    Route::get('/service/tickets/{ticket}/print', [ServiceDocumentsController::class, 'staffReceipt'])->name('service.tickets.print');
    Route::livewire('/service/equipment', ServiceAssets::class)->name('service.assets')->can('viewAny', CustomerAsset::class);
    Route::livewire('/service/equipment/{asset}', ServiceAssetShow::class)->name('service.assets.show');
    Route::livewire('/leads/create', LeadForm::class)->name('leads.create')->can('create', Lead::class);
    Route::livewire('/leads/{lead}', LeadShow::class)->name('leads.show')->can('view', 'lead');
    Route::livewire('/leads/{lead}/edit', LeadForm::class)->name('leads.edit')->can('update', 'lead');

    // Offers (Spec 05): list, create, builder, detail, print.
    Route::livewire('/offers', OfferIndex::class)->name('offers.index')->can('viewAny', Offer::class);
    Route::livewire('/offers/create', OfferCreate::class)->name('offers.create')->can('create', Offer::class);
    Route::livewire('/offers/{offer}', OfferShow::class)->name('offers.show')->can('view', 'offer');
    Route::livewire('/offers/{offer}/edit', OfferBuilder::class)->name('offers.edit')->can('update', 'offer');
    Route::get('/offers/{offer}/print', OfferPrintController::class)->name('offers.print')->can('print', 'offer');

    // Orders (Spec 06 Part A, section 31): from accepted offers, or manual orders by sales.
    Route::livewire('/orders', OrderIndex::class)->name('orders.index')->can('viewAny', Order::class);
    Route::livewire('/orders/create', OrderCreate::class)->name('orders.create')->can('create', Order::class);
    Route::livewire('/orders/{order}', OrderShow::class)->name('orders.show')->can('view', 'order');
    Route::get('/orders/{order}/print', OrderPrintController::class)->name('orders.print')->can('print', 'order');
    Route::livewire('/orders/{order}/amendments', OrderAmendments::class)->name('orders.amendments')->can('view', 'order');

    // Agreements (Spec 06 Part B).
    Route::livewire('/agreements', AgreementIndex::class)->name('agreements.index')->can('viewAny', Agreement::class);
    Route::livewire('/agreements/{agreement}', AgreementShow::class)->name('agreements.show')->can('view', 'agreement');
    Route::get('/agreements/{agreement}/print', AgreementPrintController::class)->name('agreements.print')->can('print', 'agreement');

    // Order-to-cash (Spec 06): payments, invoices, receivables, periods.
    Route::livewire('/payments', PaymentIndex::class)->name('payments.index')->can('viewAny', Payment::class);
    Route::livewire('/payments/create', PaymentCreate::class)->name('payments.create')->can('create', Payment::class);
    Route::livewire('/payments/for-orders', PaymentForOrders::class)->name('payments.for-orders')->can('create', Payment::class);
    Route::livewire('/payments/{payment}', PaymentShow::class)->name('payments.show')->can('view', 'payment');
    Route::livewire('/invoices', InvoiceIndex::class)->name('invoices.index')->can('viewAny', Invoice::class);
    Route::livewire('/invoices/{invoice}', InvoiceShow::class)->name('invoices.show')->can('view', 'invoice');
    Route::get('/invoices/{invoice}/print', InvoicePrintController::class)->name('invoices.print')->can('print', 'invoice');
    Route::livewire('/receivables', Receivables::class)->name('receivables.index')->can('viewReceivables', AccountingPeriod::class);
    Route::get('/receivables/{customer}/reminder/print', PaymentReminderPrintController::class)->name('receivables.reminder.print')->can('viewReceivables', AccountingPeriod::class);
    Route::livewire('/accounting/periods', AccountingPeriods::class)->name('accounting.periods')->can('viewAny', AccountingPeriod::class);

    // Warehouse (Spec 06 section 21): goods receipts, delivery notes, serial numbers.
    Route::livewire('/goods-receipts', GoodsReceipts::class)->name('goods-receipts.index')->can('viewAny', GoodsReceipt::class);
    Route::livewire('/goods-receipts/{receipt}', GoodsReceiptShow::class)->name('goods-receipts.show')->can('view', 'receipt');
    Route::get('/goods-receipts/{receipt}/print', [StockPrintController::class, 'receipt'])->name('goods-receipts.print')->can('print', 'receipt');
    // Stock-off acts (Spec 06 section 27): write-offs and damage.
    Route::livewire('/stock-off', StockOffActs::class)->name('stock-off.index')->can('viewAny', StockOffAct::class);
    Route::livewire('/stock-off/{act}', StockOffActShow::class)->name('stock-off.show')->can('view', 'act');
    Route::get('/stock-off/{act}/print', [StockPrintController::class, 'stockOff'])->name('stock-off.print')->can('print', 'act');
    // Stock adjustments (Spec 06 section 21.4): found serials, serial and quantity corrections.
    Route::livewire('/stock-adjustments', StockAdjustments::class)->name('stock-adjustments.index')->can('viewAny', StockAdjustment::class);
    Route::livewire('/stock-adjustments/{adjustment}', StockAdjustmentShow::class)->name('stock-adjustments.show')->can('view', 'adjustment');
    // Spec 06 section 25: packaging queue (warehouse) before delivery.
    Route::livewire('/packaging', PackagingQueue::class)->name('packaging.index')->can('viewPackaging', Order::class);
    Route::livewire('/delivery-notes', Deliveries::class)->name('delivery-notes.index')->can('viewAny', Delivery::class);
    Route::livewire('/delivery-notes/{delivery}', DeliveryShow::class)->name('delivery-notes.show')->can('view', 'delivery');
    Route::get('/delivery-notes/{delivery}/print', [StockPrintController::class, 'delivery'])->name('delivery-notes.print')->can('print', 'delivery');
    Route::get('/delivery-notes/{delivery}/warranty', [StockPrintController::class, 'warranty'])->name('delivery-notes.warranty')->can('print', 'delivery');
    Route::livewire('/serials', Serials::class)->name('serials.index')->can('viewAny', ItemSerial::class);
    Route::livewire('/serials/{serial}', SerialShow::class)->name('serials.show')->can('view', 'serial');
    Route::livewire('/stock/reconciliation', ReconciliationReport::class)->name('stock.reconciliation')->can('viewReconciliation', ItemSerial::class);
    Route::get('/stock/serial-template', [StockPrintController::class, 'template'])->name('stock.serial-template')->middleware('throttle:30,1');

    // Procurement (Spec 03 T-1..T-5): counterparties, purchase and transport tenders, approvals, purchase orders.
    Route::livewire('/counterparties', CounterpartyIndex::class)->name('counterparties.index')->can('viewAny', Counterparty::class);
    Route::livewire('/counterparties/{counterparty}', CounterpartyShow::class)->name('counterparties.show')->can('view', 'counterparty')->withTrashed();
    Route::livewire('/tenders/purchase', TenderIndex::class)->name('tenders.purchase.index')->defaults('type', 'purchase')->can('viewType', [Tender::class, 'type']);
    Route::livewire('/tenders/transport', TenderIndex::class)->name('tenders.transport.index')->defaults('type', 'transport')->can('viewType', [Tender::class, 'type']);
    Route::livewire('/tenders/{tender}', TenderShow::class)->name('tenders.show')->can('view', 'tender');
    Route::get('/tenders/{tender}/notice', [TenderPrintController::class, 'notice'])->name('tenders.notice')->can('print', 'tender');
    Route::livewire('/tenders/{tender}/comparison', TenderComparison::class)->name('tenders.comparison')->can('viewBids', 'tender');
    Route::get('/tenders/{tender}/comparison/print', [TenderPrintController::class, 'comparison'])->name('tenders.comparison.print')->can('viewBids', 'tender');
    Route::get('/tenders/{tender}/comparison/csv', [TenderPrintController::class, 'comparisonCsv'])->name('tenders.comparison.csv')->can('viewBids', 'tender')->middleware('throttle:30,1');
    Route::livewire('/approvals', TenderApprovals::class)->name('approvals.index');
    Route::livewire('/purchase-orders', PurchaseOrderIndex::class)->name('purchase-orders.index')->can('viewAny', PurchaseOrder::class);
    Route::livewire('/purchase-orders/{purchaseOrder}', PurchaseOrderShow::class)->name('purchase-orders.show')->can('view', 'purchaseOrder');
    Route::get('/purchase-orders/{purchaseOrder}/print', [TenderPrintController::class, 'purchaseOrder'])->name('purchase-orders.print')->can('print', 'purchaseOrder');

    // Private files (signed scans, payment proofs); authorized by the owning record's policy, rate limited.
    Route::get('/attachments/{attachment}', AttachmentController::class)->name('attachments.download')->middleware('throttle:60,1');

    Route::livewire('/users', UserIndex::class)->name('users.index')->can('viewAny', User::class);
    Route::livewire('/users/create', UserForm::class)->name('users.create')->can('create', User::class);
    Route::livewire('/users/{user}/edit', UserForm::class)->name('users.edit')->can('update', 'user');

    Route::livewire('/departments', DepartmentIndex::class)->name('departments.index')->can('viewAny', Department::class);
    Route::livewire('/departments/create', DepartmentForm::class)->name('departments.create')->can('create', Department::class);
    Route::livewire('/departments/{department}', DepartmentForm::class)->name('departments.show')->can('view', 'department');

    Route::livewire('/settings/appearance', Appearance::class)->name('settings.appearance')->can('manage', Setting::class);
    // Go-live (roadmap step 7): setup checklist and data import, admin only.
    // Roadmap step 10: in-app user guide per role (every staff user).
    Route::livewire('/guide', Guide::class)->name('guide');
    Route::livewire('/settings/setup', SetupWizard::class)->name('settings.setup')->can('manage', Setting::class);
    Route::livewire('/settings/import', DataImport::class)->name('settings.import')->can('manage', Setting::class);
    Route::get('/settings/import/template/{kind}', ImportTemplateController::class)->name('settings.import.template')->can('manage', Setting::class);
    // Spec 04 section 17: Telegram chats (groups) the bot is in; actions are checked per chat.
    Route::livewire('/telegram/chats', TelegramChatsIndex::class)->name('telegram.chats')->can('viewAny', TelegramChat::class);
    Route::livewire('/settings/telegram', TelegramSettings::class)->name('settings.telegram')->can('manage', Setting::class);
    Route::livewire('/cabinet', Cabinet::class)->name('cabinet');
    Route::livewire('/settings/integrations', Integrations::class)->name('settings.integrations')->can('manage', Setting::class);
    Route::livewire('/settings/accounting', AccountingSettings::class)->name('settings.accounting')->can('viewSettings', AccountingPeriod::class);
    Route::livewire('/settings/company', Company::class)->name('settings.company')->can('manage', CompanyBankAccount::class);
    Route::livewire('/settings/agreement-templates', AgreementTemplates::class)->name('settings.agreement-templates')->can('manage', AgreementTemplate::class);
    Route::livewire('/settings/agreement-templates/{template}', AgreementTemplateEditor::class)->name('settings.agreement-templates.edit')->can('manage', AgreementTemplate::class);

    // Print pipeline demo; real documents (offers, orders, ...) get their own print routes.
    Route::get('/print/sample', PrintSampleController::class)->name('print.sample');

    Route::post('/logout', LogoutController::class)->name('logout');
});
