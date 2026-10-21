<?php

/*
|--------------------------------------------------------------------------
| Central permission map (Spec 03 section 4.9, DEC-8)
|--------------------------------------------------------------------------
|
| ability => [grant => scope]. Policies ask App\Authorization\PermissionMap, which returns
| the widest scope granted to a user by their global role and their department memberships.
|
| Grant keys:
|   role:<UserRole>                 global role (admin, manager, sales, finance, accountant, staff)
|   dept:<code|*>:<DepartmentRole|*> membership in an active department, e.g. dept:sales:head
|
| Scopes (narrow to wide): own < linked < department < all.
|   own         records the user owns
|   department  records owned by anyone sharing one of the user's active departments
|   all         every record
|
| Portal users never match any grant. Abilities missing here are denied.
| The pre-existing admin-only areas (users, settings) keep their explicit policies.
|
*/

$everyRole = ['role:admin' => 'all', 'role:manager' => 'all', 'role:sales' => 'all', 'role:finance' => 'all', 'role:accountant' => 'all', 'role:stock_manager' => 'all'];

// Spec 06 DEC-2 / 4.3: accountant = operator (maker), finance = controller (checker).
$accountant = ['role:accountant' => 'all', 'dept:finance:specialist' => 'all'];
$controller = ['role:finance' => 'all', 'dept:finance:head' => 'all'];

// Spec 06 21.2/21.3 (DEC-18): stock manager moves goods and registers serials; warehouse head approves.
$stock = ['role:stock_manager' => 'all', 'dept:warehouse:specialist' => 'all'];
$warehouseHead = ['dept:warehouse:head' => 'all'];

return [

    'abilities' => [

        // Organization
        'departments.view' => [
            'role:admin' => 'all',
            'role:manager' => 'all',
            'dept:*:head' => 'department',
            'dept:*:specialist' => 'department',
        ],
        'departments.manage' => ['role:admin' => 'all'],

        // Customers & contacts (Spec 01 section 3, D-7: every commercial role sees all customers)
        'customers.view' => $everyRole + [
            'dept:sales:*' => 'all',
            'dept:management:*' => 'all',
            'dept:finance:*' => 'all',
            'dept:support:*' => 'department',
            'dept:logistics:*' => 'department',
            'dept:purchase:*' => 'department',
        ],
        // Spec 03 section 17.10: an administrator may mark a Telegram link "verified in person" (with a reason).
        'users.telegram.force_link' => ['role:admin' => 'all'],
        // Spec 03 section 24: the Partners list (all organizations, every role). Others reach the list
        // through customers.view (customer role only) or counterparties.view (suppliers/carriers only).
        // partners.create/update are not separate keys: creating or changing a role needs the right
        // of that role (customers.create, counterparties.manage).
        'partners.view' => ['role:admin' => 'all', 'role:manager' => 'all', 'dept:management:*' => 'all', 'dept:purchase:head' => 'all', 'dept:logistics:head' => 'all'],
        // Spec 03 section 23: internal Telegram notifications (Settings > Telegram); admins only by default.
        'settings.manage' => [
            'role:admin' => 'all',
        ],
        'customers.create' => [
            'role:admin' => 'all',
            'role:manager' => 'all',
            'role:sales' => 'own',
            'dept:sales:head' => 'all',
            'dept:sales:specialist' => 'own',
        ],
        'customers.update' => [
            'role:admin' => 'all',
            'role:manager' => 'all',
            'role:sales' => 'own',
            'dept:sales:head' => 'department',
            'dept:sales:specialist' => 'own',
        ],
        'customers.change_owner' => [
            'role:admin' => 'all',
            'role:manager' => 'all',
            'dept:sales:head' => 'department',
        ],
        'customers.commercial_terms' => [
            'role:admin' => 'all',
            'role:manager' => 'all',
            'role:finance' => 'all',
            'dept:finance:head' => 'all',
            'dept:finance:specialist' => 'all',
        ],
        'customers.archive' => [
            'role:admin' => 'all',
            'role:manager' => 'all',
            'dept:sales:head' => 'department',
        ],
        'customers.restore' => ['role:admin' => 'all'],
        // Spec 02 rev. 3: re-queue a customer in the 1C outbox.
        'customers.resync' => [
            'role:admin' => 'all',
            'role:manager' => 'all',
            'role:sales' => 'own',
            'dept:sales:head' => 'department',
            'dept:sales:specialist' => 'own',
        ],

        // Catalog: read-only mirror of 1C (Spec 02 BR-I.2); only CRM-local fields are editable.
        'catalog.view' => $everyRole + ['dept:*:*' => 'all'],
        'catalog.edit_local' => ['role:admin' => 'all', 'role:manager' => 'all'],
        // Spec 02 BR-I.13a.
        'catalog.edit_accounting_codes' => ['role:admin' => 'all'] + $accountant + $controller,
        // Spec 02 BR-I.13b. TODO Spec 06: grant the proposed `stock_manager` role when it exists.
        'catalog.edit_serial_tracking' => ['role:admin' => 'all', 'role:finance' => 'all', 'dept:finance:head' => 'all'] + $stock + $warehouseHead,
        // CRM-local warranty months of an item (used for warranty_until of sold serials).
        'catalog.edit_warranty' => ['role:admin' => 'all', 'role:manager' => 'all'] + $stock + $warehouseHead,
        // Spec 08 10.1: CRM-local cargo master data (HS code, weights, volume, origin) for transport RFQs.
        'catalog.edit_cargo' => ['role:admin' => 'all', 'role:manager' => 'all', 'dept:purchase:*' => 'all', 'dept:logistics:*' => 'all'],
        // Leads (Spec 04 section 3). Admin reads only (system role); finance/accounting/stock have no access.
        'leads.view' => [
            'role:admin' => 'all',
            'role:manager' => 'all',
            'role:sales' => 'own',
            'dept:sales:head' => 'department',
            'dept:sales:specialist' => 'own',
            'dept:management:*' => 'all',
        ],
        'leads.create' => [
            'role:admin' => 'all',
            'role:manager' => 'all',
            'role:sales' => 'own',
            'dept:sales:head' => 'all',
            'dept:sales:specialist' => 'own',
        ],
        // Edit, log activities, change status, create the offer.
        'leads.update' => [
            'role:manager' => 'all',
            'role:sales' => 'own',
            'dept:sales:head' => 'department',
            'dept:sales:specialist' => 'own',
        ],
        // Change the owner of a lead.
        'leads.assign' => [
            'role:manager' => 'all',
            'dept:sales:head' => 'department',
        ],
        // Offers (Spec 05 section 4.1). Linked = own offers + offers of customers the user owns.
        'offers.view' => [
            'role:admin' => 'all',
            'role:manager' => 'all',
            'role:finance' => 'all',
            'role:sales' => 'linked',
            'dept:sales:head' => 'department',
            'dept:sales:specialist' => 'linked',
            'dept:management:*' => 'all',
            'dept:finance:*' => 'all',
        ],
        'offers.create' => [
            'role:manager' => 'all',
            'role:sales' => 'own',
            'dept:sales:head' => 'all',
            'dept:sales:specialist' => 'own',
        ],
        // Edit drafts, submit, send, record customer decisions, revise, cancel before sending.
        'offers.update' => [
            'role:manager' => 'all',
            'role:sales' => 'own',
            'dept:sales:head' => 'department',
            'dept:sales:specialist' => 'own',
        ],
        // Discount approval; the amount each approver may approve is config/offers.php discount_limits_bp.
        'offers.approve' => [
            'role:manager' => 'all',
            'dept:sales:head' => 'department',
            'dept:management:head' => 'all',
        ],
        // Approve lines sold below their cost basis (stock cost price, else purchase price; rev. 4).
        'offers.approve_below_cost' => [
            'role:manager' => 'all',
            'dept:management:head' => 'all',
        ],
        // Withdraw an offer already sent to the customer.
        'offers.withdraw' => [
            'role:manager' => 'all',
            'dept:sales:head' => 'department',
        ],
        // CRM-local catalog analogues (Spec 05 7.10).
        'catalog.manage_alternatives' => [
            'role:manager' => 'all',
            'dept:sales:head' => 'all',
            'dept:purchase:head' => 'all',
        ],

        // Orders (Spec 06 4.3). Created only by the system from an accepted offer.
        'orders.view' => [
            'role:sales' => 'own',
            'dept:sales:specialist' => 'own',
            'dept:sales:head' => 'department',
            'role:manager' => 'all',
            'dept:management:*' => 'all',
            'dept:logistics:*' => 'all',
        ] + $accountant + $controller + $stock + $warehouseHead,
        'orders.confirm' => ['role:sales' => 'own', 'dept:sales:specialist' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all'],
        // Spec 06 section 31: manual order without an offer (sales for own customers, sales head,
        // manager; never admin). Enabled in OrderPolicy::create once the schema change is applied.
        'orders.create' => ['role:sales' => 'own', 'dept:sales:specialist' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all'],
        // Spec 06 section 25: packaging (start, packing list, serials, complete/reopen) is warehouse work.
        'orders.pack' => $stock + $warehouseHead,
        // Spec 06 6.4: change lines, discounts and quantities after the order started; a discount above the requester's limit needs an approver with a higher limit.
        'orders.amend' => ['role:sales' => 'own', 'dept:sales:specialist' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all'],
        'orders.cancel' => ['role:sales' => 'own', 'dept:sales:specialist' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all'],
        'orders.close' => ['role:manager' => 'all'] + $accountant + $controller,
        'orders.override_hold' => ['role:manager' => 'all'] + $controller,
        // Waive the agreement requirement for one order (reason required).
        'orders.waive_agreement' => ['role:manager' => 'all'] + $controller,
        // Power of attorney checkbox (Spec 06 section 28): tick = the order's sales owner, sales head,
        // manager or accountant; untick (with a reason) = manager, accountant or finance; changing
        // whether it is required = sales head, manager or finance; overriding the tax-invoice gate
        // with a reason = manager or finance.
        'orders.poa_tick' => ['role:sales' => 'own', 'dept:sales:specialist' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all'] + $accountant,
        'orders.poa_untick' => ['role:manager' => 'all'] + $accountant + $controller,
        'orders.poa_requirement' => ['dept:sales:head' => 'department', 'role:manager' => 'all'] + $controller,
        'orders.poa_override' => ['role:manager' => 'all'] + $controller,

        // Agreements (Spec 06 4.3). Sales see agreements of their customers and may upload scans.
        'agreements.view' => ['role:sales' => 'own', 'dept:sales:specialist' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all', 'dept:management:*' => 'all'] + $accountant + $controller,
        'agreements.prepare' => ['role:manager' => 'all'] + $accountant + $controller,
        'agreements.send' => ['role:manager' => 'all'] + $accountant + $controller,
        'agreements.upload_scan' => ['role:sales' => 'own', 'dept:sales:specialist' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all'] + $accountant + $controller,
        'agreements.confirm_signed' => ['role:manager' => 'all'] + $accountant + $controller,
        'agreements.approve' => ['role:manager' => 'all', 'dept:management:head' => 'all'] + $controller,
        'agreements.terminate' => ['role:manager' => 'all', 'dept:management:head' => 'all'] + $controller,
        'agreement_templates.manage' => ['role:manager' => 'all'] + $controller,

        // Company profile, our bank accounts and signatories (Settings > Company).
        'company.manage' => ['role:admin' => 'all', 'role:manager' => 'all'] + $controller,
        // Customer portal access (Spec 03 4.7): who may switch it on and give customer users access.
        // Spec 10: customer requests (requisitions) answered with offers; portal catalog curation.
        'requisitions.view' => ['role:admin' => 'all', 'role:manager' => 'all', 'role:sales' => 'own', 'dept:sales:specialist' => 'own', 'dept:sales:head' => 'department', 'dept:management:*' => 'all'],
        'requisitions.manage' => ['role:manager' => 'all', 'role:sales' => 'own', 'dept:sales:specialist' => 'own', 'dept:sales:head' => 'department'],
        'catalog.portal' => ['role:admin' => 'all', 'role:manager' => 'all', 'dept:sales:head' => 'all'],
        // Spec 09: customer equipment and service tickets. Admin reads only (SoD); the service team works.
        // Spec 09 §7 (user decision 2026-10-20): sales read the tickets and equipment of the
        // customers they own (head: department); managing stays with the service team / manager.
        'service.view' => ['role:admin' => 'all', 'role:manager' => 'all', 'role:service_engineer' => 'all', 'dept:service:*' => 'all', 'role:sales' => 'own', 'dept:sales:specialist' => 'own', 'dept:sales:head' => 'department'],
        'service.manage' => ['role:manager' => 'all', 'role:service_engineer' => 'all', 'dept:service:*' => 'all'],
        'service.register' => ['role:manager' => 'all', 'role:service_engineer' => 'all', 'dept:service:*' => 'all', 'role:sales' => 'own', 'dept:sales:specialist' => 'own', 'dept:sales:head' => 'department'],
        // 1C integration API documentation (/api/docs): admin and the IT department (integration managers).
        'integration.docs' => ['role:admin' => 'all', 'dept:it:*' => 'all'],
        'customers.portal_access' => ['role:admin' => 'all', 'role:manager' => 'all', 'role:sales' => 'own', 'dept:sales:specialist' => 'own', 'dept:sales:head' => 'department'],
        // Customer finance data: bank accounts, signatory, billing contact (Spec 06 4.2).
        'customers.finance_details' => ['role:sales' => 'own', 'dept:sales:specialist' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all'] + $accountant + $controller,
        // Credit limit and manual hold (Spec 06 4.2: finance only).
        'accounting.credit' => $controller,

        // Order-to-cash (Spec 06 4.3): accountant = operator, finance = controller (approves).
        'orders.amend_schedule' => ['role:manager' => 'all'] + $accountant + $controller,
        'invoices.view' => ['role:sales' => 'own', 'dept:sales:specialist' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all'] + $accountant + $controller,
        'invoices.issue' => ['role:manager' => 'all'] + $accountant + $controller,
        'invoices.register_tax_doc' => $accountant + $controller,
        'invoices.void_approve' => $controller,
        'payments.view' => ['role:sales' => 'own', 'dept:sales:specialist' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all'] + $accountant + $controller,
        // Bank account, payer account and proof of a payment (hidden from sales).
        'payments.view_sensitive' => ['role:manager' => 'all'] + $accountant + $controller,
        'payments.record' => $accountant + $controller,
        'payments.allocate' => $accountant + $controller,
        // Payments above the threshold, voids, refunds: never the recorder/requester (checked in Actions).
        'payments.approve' => $controller,
        'accounting.periods' => $controller,
        'accounting.settings' => $controller,
        'accounting.view_settings' => ['role:manager' => 'all'] + $accountant + $controller,
        'receivables.view' => ['role:sales' => 'own', 'dept:sales:specialist' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all'] + $accountant + $controller,
        'onec.outbox.view' => ['role:admin' => 'all'] + $accountant + $controller,

        // Stock module (Spec 06 21.3): goods receipts, delivery notes, serials.
        'stock.view' => ['role:manager' => 'all', 'dept:logistics:*' => 'all'] + $stock + $warehouseHead + $accountant + $controller,
        'stock.receive' => $stock + $warehouseHead,
        'stock.deliver' => $stock + $warehouseHead,
        'stock.delivery_draft' => ['role:sales' => 'own', 'dept:sales:specialist' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all', 'dept:logistics:*' => 'all'] + $stock + $warehouseHead,
        'stock.adjust_approve' => ['role:manager' => 'all'] + $warehouseHead,
        'serials.view' => ['role:admin' => 'all', 'role:sales' => 'own', 'dept:sales:specialist' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all', 'dept:logistics:*' => 'all'] + $stock + $warehouseHead + $accountant + $controller,
        'serials.view_customer' => ['role:sales' => 'own', 'dept:sales:specialist' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all'] + $warehouseHead + $accountant + $controller,
        // Catalogue "Add / import serials": stock manager, warehouse staff and head only (Spec 06 SoD-S4: admin has no stock business rights).
        'serials.register' => $stock + $warehouseHead,
        'stock.reconciliation' => ['role:manager' => 'all'] + $stock + $warehouseHead + $accountant + $controller,
        // Stock-off acts (Spec 06 section 27): write-offs and damage. Maker != checker (policy).
        'stock_off.view' => ['role:manager' => 'all', 'dept:management:*' => 'all', 'dept:purchase:*' => 'all'] + $stock + $warehouseHead + $accountant + $controller,
        'stock_off.create' => $stock + $warehouseHead,
        'stock_off.approve' => ['role:manager' => 'all'] + $warehouseHead,
        // Above the value limit (Settings > Accounting): CEO (management head) or finance.
        'stock_off.approve_high' => ['dept:management:head' => 'all'] + $controller,
        'stock_off.post' => $stock + $warehouseHead,
        // Supplier claim for receipt damage (open / settled / rejected).
        'stock_off.claim' => ['dept:purchase:*' => 'all'] + $stock + $warehouseHead,

        // Stock adjustments (Spec 06 21.4): found serials, serial and quantity corrections. Approval is `stock.adjust_approve`; maker != checker (policy).
        'stock_adjust.view' => ['role:manager' => 'all', 'dept:management:*' => 'all', 'dept:purchase:*' => 'all'] + $stock + $warehouseHead + $accountant + $controller,
        'stock_adjust.create' => $stock + $warehouseHead,

        // Counterparties: suppliers and carriers (Spec 03 4.10, T-1).
        'counterparties.view' => ['role:admin' => 'all', 'role:manager' => 'all', 'dept:purchase:*' => 'all', 'dept:logistics:*' => 'all', 'dept:management:*' => 'all'] + $accountant + $controller,
        'counterparties.manage' => ['role:admin' => 'all', 'role:manager' => 'all', 'dept:purchase:*' => 'all', 'dept:logistics:*' => 'all'],
        'counterparties.qualify' => ['role:manager' => 'all', 'dept:purchase:head' => 'all', 'dept:logistics:head' => 'all'],

        // Tenders (Spec 03 4.9/4.10, Spec 08 12.4). Admin sees metadata only: sealed content is
        // denied to the admin role by TenderBidPolicy regardless of memberships (DEC-10).
        // linked = tenders the user owns or requested (sales: transport requests from own orders).
        'tenders.purchase.view' => ['role:admin' => 'all', 'role:manager' => 'all', 'dept:management:*' => 'all', 'dept:purchase:*' => 'all'] + $controller,
        'tenders.transport.view' => ['role:admin' => 'all', 'role:manager' => 'all', 'dept:management:*' => 'all', 'dept:logistics:*' => 'all', 'dept:purchase:*' => 'linked', 'role:sales' => 'linked', 'dept:sales:*' => 'linked'] + $controller,
        'tenders.purchase.create' => ['role:manager' => 'all', 'dept:purchase:specialist' => 'own', 'dept:purchase:head' => 'department'],
        'tenders.transport.create' => ['role:manager' => 'all', 'dept:logistics:specialist' => 'own', 'dept:logistics:head' => 'department'],
        // Transport request (draft without owner) raised from a customer order or a purchase need.
        'tenders.transport.request' => ['role:manager' => 'all', 'role:sales' => 'own', 'dept:sales:*' => 'own', 'dept:purchase:*' => 'own', 'dept:logistics:*' => 'own'],
        // L1 approver: head of the tender's department (checked by ApprovalRouter) or a manager.
        'tenders.approve' => ['role:manager' => 'all', 'dept:purchase:head' => 'department', 'dept:logistics:head' => 'department'],
        // CEO level (Spec 08 DEC-5): head of the management department.
        'tenders.ceo_approve' => ['dept:management:head' => 'all'],
        'tenders.finance_approve' => $controller,
        'tenders.fx.set' => ['role:manager' => 'all'] + $controller,
        // Bid content after opening (BR-T.5); the tender department is checked by the policy.
        'tenders.view_bids' => ['role:manager' => 'all', 'dept:management:*' => 'all', 'dept:purchase:*' => 'all', 'dept:logistics:*' => 'all'] + $controller,

        // Purchase orders (purchase-tender skill 6, Spec 08 12.4).
        'purchase_orders.view' => ['role:manager' => 'all', 'dept:management:*' => 'all', 'dept:purchase:specialist' => 'own', 'dept:purchase:head' => 'department', 'dept:logistics:*' => 'all', 'role:stock_manager' => 'all'] + $accountant + $controller,
        'purchase_orders.manage' => ['role:manager' => 'all', 'dept:purchase:specialist' => 'own', 'dept:purchase:head' => 'department'],

        // Change log of a record: everyone who may see the record.
        'activity.view' => $everyRole + ['dept:*:*' => 'all'],

    ],

];
