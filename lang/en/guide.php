<?php

/*
| In-app user guide per role (roadmap step 10).
*/

return [

    'title' => 'User guide',
    'intro' => 'Short guide to the daily work in the CRM. It opens on your role; the other tabs show how colleagues continue the work.',
    'sections_label' => 'Guide sections',
    'yours' => 'your role',
    'open' => 'Open the page',
    'sections' => [
        'service_engineer' => [
            'title' => 'Service engineer',
            'intro' => 'Customer equipment and repair requests (Spec 09).',
            'steps' => [
                'queue' => [
                    'title' => 'Watch the ticket queue',
                    'text' => 'Service > Service tickets lists new and open requests with SLA timers. "Overdue" means the response or repair target has passed.',
                ],
                'accept' => [
                    'title' => 'Accept or reject',
                    'text' => 'Accept a request to start the repair (the unit shows "In repair" to the customer), or reject it with a reason. Assign the engineer.',
                ],
                'repair' => [
                    'title' => 'Diagnose and repair',
                    'text' => 'Move to Diagnosing, "Waiting in queue (out of stock)" when a part, consumable or replacement unit is missing (say what and, if known, the expected date), or In repair. The queue is first in, first out within priority; reorder only with a reason. A goods receipt with the needed item alerts you. Record parts and, outside warranty, the labour cost.',
                ],
                'close' => [
                    'title' => 'Ready and close',
                    'text' => 'Mark Ready when the unit can be picked up, then Close with the work done. Print the acceptance receipt. Billable work outside warranty becomes available for 1C to pull.',
                ],
                'equipment' => [
                    'title' => 'Equipment lookup',
                    'text' => 'Find any unit by serial number, verify units registered by customers and review disputed serials.',
                ],
            ],
        ],
        'general' => [
            'title' => 'Everyone',
            'intro' => 'How the CRM fits together and the tools every user has.',
            'steps' => [
                'flow' => [
                    'title' => 'The sales flow',
                    'text' => 'Lead → customer → offer → order and agreement → payment → packaging with serial numbers → delivery. Each record links to the one before it, so nothing is typed twice.',
                ],
                'sign_in' => [
                    'title' => 'Signing in and 2FA',
                    'text' => 'Sign in with your e-mail and password. Admin, accountant and finance users set up two-step verification in My cabinet and keep the recovery codes safe.',
                ],
                'search' => [
                    'title' => 'Search',
                    'text' => 'The search field in the top bar finds leads, customers, offers, orders, items and serial numbers you are allowed to see.',
                ],
                'notifications' => [
                    'title' => 'Notifications',
                    'text' => 'The bell shows approvals, payments, follow-ups and deadlines. Choose in-app or Telegram per event; link Telegram in My cabinet first.',
                ],
                'language' => [
                    'title' => 'Language',
                    'text' => 'Switch the interface language in the top bar. Printed documents use the customer\'s language unless you pick another one on the print page.',
                ],
            ],
        ],
        'sales' => [
            'title' => 'Sales',
            'intro' => 'From the first request to the paid and delivered order.',
            'steps' => [
                'power_of_attorney' => [
                    'title' => 'Power of attorney',
                    'text' => 'For B2B orders of companies the order page has the checkbox "Power of attorney received". Tick it when the customer\'s power of attorney arrives (add its number and the representative in the note). The tax invoice can be registered only after that; a manager or finance can allow it without one, with a reason.',
                ],
                'requests' => [
                    'title' => 'Answer customer requests',
                    'text' => 'Sales > Customer requests lists what customers sent from the portal (no prices on their side). Ask questions in the request, map "by category" lines to items, then "Create offer from request".',
                ],
                'leads' => [
                    'title' => 'Capture every request as a lead',
                    'text' => 'Leads > New lead walks you through four steps: company and contact, need and items, qualification, then review and save (or tick "Show everything on one page"; the choice is remembered). Search the customer first; duplicates are shown on every step. Alt+S saves from any step.',
                ],
                'qualify' => [
                    'title' => 'Qualify',
                    'text' => 'Confirm budget, authority, need and timeline, then convert the lead to a customer; the first offer is created in the same dialog. Close lost leads with a reason.',
                ],
                'convert' => [
                    'title' => 'Convert the lead to a customer',
                    'text' => 'On the lead page press "Convert to customer". Link the matching organization or create a new one, choose per field what to keep, and tick a first offer if needed. The lead becomes read only; its history shows on the customer timeline.',
                ],
                'offers' => [
                    'title' => 'Build and send the offer',
                    'text' => 'Add items, options and discounts. Discounts above your limit go to a manager for approval. Send the offer; the customer can choose options in the portal.',
                ],
                'orders' => [
                    'title' => 'Turn the accepted offer into an order',
                    'text' => 'Accepting an offer creates the order, or use Orders > New order to enter one without an offer (customer, delivery or self pick-up, warehouse, items; prices and discount limits as in offers). Orders start at once (no separate confirmation); one stays "new" only while a hold blocks it. B2B orders need an agreement (a framework agreement is used automatically), retail orders get a sales receipt. Print the order form from the order page; the page shows the next step.',
                ],
                'follow' => [
                    'title' => 'Follow payment and delivery',
                    'text' => 'The order stepper shows agreement, payment, packaging and delivery. Receivables lists overdue customers.',
                ],
                'partners' => [
                    'title' => 'Partners and roles',
                    'text' => 'Sales > Partners lists every organization you may see. "New partner" creates one organization with one or more roles (customer, supplier, carrier); an existing tax ID is never duplicated: add the role instead. Roles are changed with "Edit roles" on the card.',
                ],
                'customers' => [
                    'title' => 'Keep customer data complete',
                    'text' => 'Tax ID, contacts, bank details and document language. Switch on "Sync with 1C" (needs the tax ID) when 1C should have the customer; it is off by default and 1C matches by tax ID only.',
                ],
                'service' => [
                    'title' => 'Follow your customers\' service tickets',
                    'text' => 'Service > Tickets and Equipment show the repair tickets and units of your customers (sales head: your department): status, queue, history. You can register a ticket for your customer; the service team accepts, assigns and repairs.',
                ],
                'create_from_card' => [
                    'title' => 'Start documents from the customer card',
                    'text' => 'The "Create" button on a customer card opens a new lead, offer, order or payment with this customer already selected, or jumps to the framework agreement and equipment tabs. You only see what you are allowed to do.',
                ],
            ],
        ],
        'manager' => [
            'title' => 'Sales manager',
            'intro' => 'Approvals, team pipeline and results.',
            'steps' => [
                'reference_lists' => [
                    'title' => 'Keep the reference lists tidy',
                    'text' => 'Catalog > Reference lists holds industries, currencies, contact positions and supplier categories (names in uz, ru, en). Review positions added by users and old industry values; deactivate entries instead of deleting them.',
                ],
                'portal_catalog' => [
                    'title' => 'Curate the portal catalog',
                    'text' => 'On Catalog > Categories set "Portal" names, order and visibility; hide single items on the item page; per-customer allow/deny rules are on the customer\'s Portal tab.',
                ],
                'approvals' => [
                    'title' => 'Approve offers',
                    'text' => 'Offers with discounts above the sales limit wait in Approvals. Approve or return them with a comment.',
                ],
                'assign' => [
                    'title' => 'Assign leads',
                    'text' => 'Unassigned leads (for example from Telegram) wait for an owner. Reassign leads when someone is absent.',
                ],
                'dashboard' => [
                    'title' => 'Watch the pipeline',
                    'text' => 'The dashboard shows open leads, offers waiting for approval, orders in progress and overdue follow-ups.',
                ],
                'reports' => [
                    'title' => 'Reports',
                    'text' => 'Sales funnel, offer win rate and orders backlog, exportable to CSV and Excel.',
                ],
            ],
        ],
        'accountant' => [
            'title' => 'Accountant',
            'intro' => 'Agreements, invoices and payments (operator role; finance approves).',
            'steps' => [
                'order_payments' => [
                    'title' => 'Record payments for orders',
                    'text' => 'Payments > New payment: choose the partner, tick the paid orders and check the amounts (filled with what is still open), date and method. This tells the warehouse what may be shipped; the books are kept in 1C. A wrong entry is voided with a reason, never deleted.',
                ],
                'agreements' => [
                    'title' => 'Prepare and send agreements',
                    'text' => 'For B2B orders: generate the agreement from the template, send it and record the signed copy.',
                ],
                'invoices' => [
                    'title' => 'Issue invoices',
                    'text' => 'Issue the proforma invoice, or a sales receipt for retail orders, from the order.',
                ],
                'payments' => [
                    'title' => 'Record and allocate payments',
                    'text' => 'Record each incoming payment with its proof and allocate it to invoices. Finance approves it; you cannot approve your own entries.',
                ],
                'receivables' => [
                    'title' => 'Follow receivables',
                    'text' => 'Receivables and the aging report show who is overdue. Orders that exceed the customer\'s credit limit are put on hold.',
                ],
                'onec' => [
                    'title' => 'Exchange with 1C',
                    'text' => 'Invoices, deliveries and receipts become available for 1C to pull. Check the exchange status on the document instead of entering it in 1C by hand.',
                ],
            ],
        ],
        'finance' => [
            'title' => 'Finance',
            'intro' => 'Control and approval (checker role).',
            'steps' => [
                'payments' => [
                    'title' => 'Approve payments',
                    'text' => 'Approve or reject payments recorded by accountants. Maker and checker are always different people.',
                ],
                'voids' => [
                    'title' => 'Voids and refunds',
                    'text' => 'Requests to void or refund a payment or invoice wait for your decision with the reason.',
                ],
                'periods' => [
                    'title' => 'Lock periods',
                    'text' => 'At month end, lock the accounting period so nobody changes posted figures.',
                ],
                'credit' => [
                    'title' => 'Credit limits and holds',
                    'text' => 'Set credit limits and manual holds on customers; orders of customers on hold cannot proceed.',
                ],
                'reports' => [
                    'title' => 'Reports',
                    'text' => 'Receivables aging and payments reports, exportable to CSV and Excel.',
                ],
            ],
        ],
        'stock_manager' => [
            'title' => 'Stock manager',
            'intro' => 'Goods receipts, serial numbers, packaging and delivery.',
            'steps' => [
                'receipts' => [
                    'title' => 'Complete goods receipts from 1C',
                    'text' => 'Supplier receipts arrive from 1C. Start receiving. For serial-tracked items scan the serial numbers: the received quantity is counted from them; tick "Damaged" on each damaged unit and describe the damage. For other items type the received and damaged quantities (describe damage in the note). Mark the receipt checked and post it; only damaged serials go to quarantine and the result becomes available for 1C to pull. Put a receipt on hold when something must be clarified; the warehouse head releases it. You create only customer returns and opening balances yourself.',
                ],
                'stock_off' => [
                    'title' => 'Write off damaged or lost stock',
                    'text' => 'Damage found at a goods receipt opens a draft stock-off act; for anything else (damage in storage or transit, loss, theft, expiry, quality rejection, internal use, samples, rejected returns) create one under Warehouse > Stock-off acts. Pick the serial numbers, add photos, submit; the warehouse head approves (the CEO or finance above the value limit), then you post it. A supplier claim can be tracked on receipt damage.',
                ],
                'serials' => [
                    'title' => 'Register serial numbers',
                    'text' => 'Add serial numbers one by one or import them from a file; duplicates are refused.',
                ],
                'packaging' => [
                    'title' => 'Pack orders',
                    'text' => 'The packaging queue lists paid or released orders. Scan the serial numbers, then mark the order packed.',
                ],
                'delivery' => [
                    'title' => 'Post delivery notes',
                    'text' => 'Post the delivery note when goods leave; the serial numbers move to the customer.',
                ],
                'reconciliation' => [
                    'title' => 'Serials vs 1C',
                    'text' => 'Compare serial numbers on hand with 1C quantities. Shortages are fixed with an opening balance receipt.',
                ],
            ],
        ],
        'staff' => [
            'title' => 'Procurement and logistics',
            'intro' => 'Tenders, suppliers, carriers and purchase orders.',
            'steps' => [
                'purchase' => [
                    'title' => 'Purchase tenders',
                    'text' => 'Describe the goods or service, invite suppliers by link, compare bids and propose the award for approval.',
                ],
                'transport' => [
                    'title' => 'Transport tenders',
                    'text' => 'Announce the route, cargo and dates, invite carriers, compare offers and award.',
                ],
                'counterparties' => [
                    'title' => 'Suppliers and carriers',
                    'text' => 'Keep counterparties qualified: documents, contacts and ratings.',
                ],
                'purchase_orders' => [
                    'title' => 'Purchase orders',
                    'text' => 'Create the purchase order from the award and follow it until the goods are received.',
                ],
            ],
        ],
        'admin' => [
            'title' => 'Administrator',
            'intro' => 'System setup only; administrators have no business rights (maker ≠ checker).',
            'steps' => [
                'setup' => [
                    'title' => 'Go-live checklist',
                    'text' => 'Settings > Setup shows what is missing: company, CEO signatory, bank account, templates, 1C token, warehouses, roles, scheduler.',
                ],
                'users' => [
                    'title' => 'Users and roles',
                    'text' => 'Create users with the right role, department and default language. Deactivate leavers. Reset two-step verification when a phone is lost. In Users > edit > Telegram you can link a Telegram user the bot has seen; the link stays pending until the person confirms it with the bot code (or you record "Verified in person" with a reason).',
                ],
                'import' => [
                    'title' => 'Initial data import',
                    'text' => 'Import customers and items from CSV or Excel; the file is checked first and only valid rows are saved.',
                ],
                'integrations' => [
                    'title' => '1C integration',
                    'text' => 'Issue the 1C token. 1C sends catalog and stock data and pulls documents from the outbox.',
                ],
                'telegram_groups' => [
                    'title' => 'Company Telegram groups',
                    'text' => 'Settings > Telegram > Company groups: add your team group (and topic), choose what is posted and at which level, keep amounts off unless everyone may see them, and send a test message. Staff can use /status, /orders today, /requests open and /report today there.',
                ],
                'operations' => [
                    'title' => 'Backups and monitoring',
                    'text' => 'Daily backups, /api/health and the production checklist are described in the operations guide (docs/operations.md).',
                ],
            ],
        ],
    ],

];
