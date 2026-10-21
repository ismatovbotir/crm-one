# Spec 06 — Orders, Agreements, Accounting (order-to-cash) and Stock/Serial Numbers, with the ACCOUNTANT and STOCK MANAGER roles

| | |
|---|---|
| Status | Draft. Design only; open questions in section 19 have defaults that will be built if unanswered |
| Owner | Product (B2B sales CRM) |
| Date | 2026-10-18 |
| Stack | Laravel 13 (PHP ^8.3), Livewire 4, PHPUnit, uz (default) / ru / en, red/silver/white theme, `QUEUE_CONNECTION=database`, app timezone UTC, business timezone Asia/Tashkent |
| Depends on | Spec 01 (customers, contacts, `Money`, `Quantity`, `activity_log`), Spec 02 (1C inbound API, tokens, `inbound_batches`, catalog GUIDs, `item_stocks`; **pull-only rule**, see DEC-14), Spec 03 (departments, `PermissionMap`, portal realm/roles, attachments, notifications, Telegram, approvals inbox, shipments/transport tenders), Spec 04 Leads (`number_sequences`, `status_transitions`), Spec 05 Offers (accepted version, `OfferToOrderPayload`, `CreateOrderFromOffer` hook) |
| Replaces | the planned separate "06 Orders" and "07 Agreements" specs (Spec 01 A8 numbering). **Spec 07 is not used**; Agreements live in Part B of this spec |
| Amends | Spec 02 section 5.1 rows and roadmap (orders/agreements/invoices/payments via outbox + inbound, never CRM → 1C calls); `UserRole` (new `accountant`); department `finance` display name; `config/permissions.php` (new abilities, section 4.3); `config/navigation.php` (section 10.1) |
| Domain skills | `.claude/skills/b2b-sales-workflow`, `order-management`, `agreement-contracts`, `accounting-workflow` (new), `spec-writing` |

### Repo baseline (verified 2026-10-18)
- Built: users (role, type, locale, profile), `settings`, `activity_log`, departments + `DepartmentRole` (head/specialist/viewer) + `DataScope` (own < linked < department < all), `App\Authorization\PermissionMap` + `config/permissions.php` (customers, catalog, activity abilities; `$everyRole` = admin, manager, sales, finance), `config/navigation.php` (`nav.orders`, `nav.agreements` are `soon`), catalog mirror (units, categories, items, price lists, warehouses, `item_stocks`), customers/contacts (with `preferred_locale`, `payment_terms_days`, `currency`, sync columns), 1C inbound API core (`integration_tokens`, `inbound_batches`, `inbound_snapshots`, `integration_feeds`, `integration_logs`; `IntegrationAbility` = items:write, prices:write, stock:write, batches:read), `App\Support\{Money, Quantity}`, print stack `App\Documents\{DocumentRenderer, DocumentLanguage, DocumentFormatter, AmountInWords, HasDocumentLanguage, InteractsWithDocumentLanguage}`, `lang/{uz,ru,en}/documents.php` with `order.*`, `agreement.*`, `invoice.*` base labels.
- `App\Enums\UserRole` = admin, manager, sales, finance, staff. No `accountant`.
- Being built concurrently by another agent (do not assume final names; re-verify before each increment): offers phase incl. items `accountant_code`, `class_code`, `package_code` (nullable, received from 1C in the items payload; `accountant_code` also editable in CRM by accountant/admin) and `is_serialized` (flag from 1C) snapshotted on offer lines; `OfferToOrderPayload`.
- User answers recorded in this spec: 1C does **not** keep serial numbers; the serial registry is CRM-master and serials are entered by the **stock manager** at goods receipt (section 21). Serials can be entered one by one, pasted, or imported from CSV/XLSX.
- Not built: leads, `number_sequences`, `status_transitions`, portal, attachments, notifications, shipments/tenders, orders, agreements, invoices, payments.

---

## 1. Problem

1. After an offer is accepted, everything happens outside the CRM: the order is re-keyed into 1C, the contract is written in Word from an old file, sent by e-mail, chased by phone, and the signed scan lives in someone's mailbox. Nobody can answer "is the contract for order X signed?" or "has the customer paid the prepayment, can we ship?" without calling accounting.
2. **New business requirement**: an **ACCOUNTANT** role that follows orders, prepares and **sends agreements to the customer**, follows the accounting workflow (invoices, payment terms, receivables) and **records payments**. Today accountants have no CRM role; the existing `finance` role is a read/approve role defined before departments existed.
3. Payments are the riskiest data in the system: a wrong, duplicated or silently edited payment releases goods without money, or chases a customer who already paid. 1C is the accounting book of record, but **1C is not reachable from the internet: 1C always initiates, the CRM never calls 1C**. The CRM must therefore expose what it records for 1C to pull, and accept what 1C pushes, without double counting.
4. Uzbekistan specifics: VAT invoices (счёт-фактура) and waybills are electronic (ESF / e-TTN through an e-invoicing operator); ESF lines require the national product classifier code (IKPU/MXIK) and a package code. Accountants also work by their own item codes (`accountant_code`). If these codes are missing at invoicing time, documents are rejected later and re-done.

## 2. Goals / Non-goals

### Goals
- G1. **Orders** created only from an accepted offer through `OfferToOrderPayload` (fixed + selected lines, amounts copied verbatim), with an explicit state machine, holds (credit, overdue, prepayment), soft stock reservation against 1C snapshots, deliveries, amendments, transport link for imports.
- G2. **Agreements** generated from orders with versioned templates in uz/ru/en, review, sending by the accountant (portal, e-mail secure link, printable document in the chosen language via `DocumentRenderer`), tracking sent/viewed/signed, signed-scan upload, addenda, renewals, expiry reminders, termination.
- G3. **Accounting workflow**: payment terms → payment schedule per order; proforma invoices issued in the CRM; tax documents (VAT invoice/act/waybill) registered with their 1C/ESF references; **payments recorded by the accountant** (all methods, partial/over/under, FX, allocation to many orders/invoices, refunds and voids as separate entries, never deleted); order payment status; aging/receivables; reminders; credit limit/hold; month-end period lock; immutable ledger-style audit.
- G4. **ACCOUNTANT role** with a clear relation to `finance`, a permission matrix, segregation of duties (maker-checker), notifications, dashboard work queues.
- G5. **1C reconciliation under the pull-only rule**: a CRM **outbox API** that 1C pulls (orders, agreements, invoices, payments) and acknowledges with its GUIDs; inbound pushes from 1C for bank payments and posting status. 1C is the book of record; CRM entries are "recorded, pending 1C confirmation" until acknowledged.
- G6. Tax/accounting codes (`accountant_code`, `class_code`, `package_code`) carried from items → offer lines → order lines → invoice lines → prints, exports and outbox payloads, with configurable issue-time validation.
- G7. **Serial numbers** (section 21): a CRM-master serial registry filled by the **STOCK MANAGER** at goods receipt (one by one, paste, CSV/XLSX import with preview); serialized items are **registered by serial number in the sales document** (delivery note), one serial per unit, checked unique / belonging to the item / available, claimed atomically; immutable after posting; returns, transfers, write-offs, corrections with full history; reconciliation of serial counts against 1C stock quantities.

### Non-goals (this spec)
- Calling 1C from the CRM in any form (DEC-14). Direct e-invoicing operator integration (Didox, Faktura.uz, soliq.uz ESF), e-TTN, E-IMZO e-signature: **roadmap/open questions** (Q-2, Q-15); v1 registers references and scans.
- General ledger, VAT returns, FX gain/loss, cost of goods, penalties (пеня) calculation: done in 1C.
- Fiscal receipts (online cash registers / OFD) for cash and card: produced by the POS/1C; the CRM stores the receipt number (Q-5).
- Credit notes / corrective VAT invoices, netting (взаимозачёт), barter, factoring: roadmap.
- Framework agreements UI beyond the minimal link (`orders.framework_agreement_id`) — schema-ready, UI in increment 06-12.
- Orders created without an offer (manual orders): create and accept an offer on behalf instead (single source of truth, DEC-1).
- Purchase side (supplier invoices/payments) — Spec 03 purchase tenders.
- Server-side PDF engine choice: reused from Spec 05 increment 05-4 (Q-23).
- Serial numbers in 1C (1C never sends or stores serials); serial tracking of kit components; lot/batch and expiry tracking; bin/location management; picking/packing workflows; purchase prices on goods receipts.

## 3. Key decisions

| # | Decision | Alternatives rejected | Trade-off |
|---|---|---|---|
| DEC-1 | **Revised 2026-10-20 (section 31):** orders come from an accepted offer (`CreateOrderFromOffer`) or, once the schema change of 31.3 is applied, from a manual order by sales; there is no confirmation step. Original text: orders are created **only** by `CreateOrderFromOffer` consuming the immutable `OfferToOrderPayload` (Spec 05 side builds it from the accepted version: fixed + selected lines, accepted hash). `orders.offer_id` unique → idempotent | Manual order entry | One path, one snapshot, no re-keying; manual cases go through an offer accepted on behalf |
| DEC-2 | **Keep `finance` and add `accountant`**: `accountant` = **operator (maker)**: prepares/sends agreements, issues invoices, records and allocates payments. `finance` = **controller (checker)**, read-all: approves payments above threshold, voids, refunds, period lock/reopen, credit limits, hold overrides, payment-terms deviations. Department `finance` keeps its code, display name becomes "Accounting & Finance": `head` = chief accountant (checker grants), `specialist` = accountant (operator grants), `viewer` = read-only | (a) Rename `finance` → `accountant`; (b) accountant as a department-only role with `staff` | Maker-checker needs two distinct grant sets; `finance` already carries approver meaning in Specs 03/05 (payment-terms co-approval, FX rates, award co-approval, 2FA) and tests (Spec 03 AC-16); renaming would silently give operators approval rights. A global `accountant` role keeps a one-click setup for small teams; departments give the same rights for larger ones |
| DEC-3 | **Payments are immutable**. No update/delete of amount, currency, date, customer. Corrections = **void** (status + reason + reversal entry, second user approves) and a new payment; refunds = separate outgoing payments. Allocations append-only (deallocation = reversal row) | Editable payments with history | Ledger integrity; matches 1C document logic |
| DEC-4 | **`accounting_entries` is the append-only ledger** (signed minor units + currency, refs to customer/order/invoice/payment/allocation, period, causer, source). Cached balances on rows (`orders.paid_minor`, `payments.allocated_minor`, `invoices.paid_minor`) are recomputed from it in the same transaction; nightly integrity check | Balances only on rows | Auditable "who/when/what"; caches fast for lists |
| DEC-5 | **Receivables are driven by the payment schedule** (`payment_schedule_items` per order: prepayment, on-delivery, milestone), not by proformas. Allocations to an order cover its schedule FIFO by due date (computed, not stored) | Receivables only from tax invoices | Works for prepayment and deferred terms; overdue and aging are deterministic |
| DEC-6 | **Invoice documents**: the CRM **issues** proformas (счёт на оплату, `PF-`); **legal tax documents** (счёт-фактура/ESF, акт, накладная) are issued in 1C/e-invoicing and **registered** in the CRM (manually in v1, by 1C push later), each with lines, qty, codes and ESF/1C references. Only tax documents move `invoiced_qty` | CRM issues ESF directly | No assumption about the e-invoicing provider (Q-2); 1C stays book of record |
| DEC-7 | Order **holds** are a flag set (`hold_reasons` json), not statuses: `credit_limit`, `overdue`, `prepayment_missing`, `agreement_unsigned`, `manual`. Holds block confirmation (credit/overdue) or start of fulfilment (prepayment/agreement) | Extra statuses | Orthogonal to fulfilment progress; several reasons at once |
| DEC-8 | **Soft stock reservation** in the CRM (`stock_reservations`) against 1C snapshots; once 1C acks the order (its own reservation now inside `item_stocks.reserved`) the CRM row flips to `mirrored_in_1c` and stops being subtracted | Write reservations to 1C | Respects pull-only; avoids double subtraction |
| DEC-9 | Agreement statuses as requested **plus `cancelled`** (pre-signature only, e.g. order cancelled). Addenda and renewals are rows of the same `agreements` table (`kind` = `order` \| `framework` \| `addendum`; `parent_agreement_id`, `renewal_of_id`) | Separate addenda table | One lifecycle, one print view, one dispatch tracker |
| DEC-10 | Agreement text = **template version (per locale) + frozen variables snapshot**. Templates use a whitelist placeholder syntax `{{ order.number }}` rendered by a dedicated renderer (escaped values, no Blade/PHP in templates). On send, rendered HTML per locale is stored with sha256 (`agreement_versions`); prints of sent versions come from that snapshot | Blade templates editable in UI; free Word upload | No code injection; the print equals what was sent; uz/ru/en and bilingual layouts |
| DEC-11 | The **signed scan is verified** by the accountant: a customer upload (portal) only sets `customer_signed_uploaded_at`; `sent_for_signature → signed` requires an internal user (accountant) to confirm signatures of both sides | Auto-sign on upload | No bogus "signed" contracts |
| DEC-12 | Payment above the threshold, any void, any refund → **second approval** by a user with `payments.approve` who is not the recorder. Admin has no business rights (Spec 03 DEC-10) | Single actor | Segregation of duties |
| DEC-13 | Payments `recorded` (pending 1C) **count** toward order payment status by default (setting `accounting.count_unconfirmed_payments` = true), always shown with a "pending 1C" badge; period lock requires none pending in that period | Count only 1C-confirmed | Operations (shipping on prepayment) are not blocked by 1C exchange delay; risk visible and bounded |
| DEC-14 | **Pull-only 1C rule**: the CRM never opens connections to 1C. CRM → 1C data is published in `onec_outbox`; 1C polls `GET /outbox/pending` and acks with `POST /outbox/ack` — the same pattern and vocabulary as the Spec 02 rev. 3 customer outbox (`GET /customers/pending`, `POST /customers/ack`). 1C → CRM via the Spec 02 inbound API (`POST /payments`). New abilities: `orders:read`, `payments:read`, `payments:write`, `warehouse:read` | CRM pushes to a 1C HTTP service | 1C is on a private network and not reachable; one outbox pattern for 1C developers |
| DEC-15 | Codes are **snapshots**: `accountant_code`, `class_code` (IKPU/MXIK), `package_code` are copied item → offer line → order line → invoice line at each conversion; later item edits never change issued documents. Missing codes → configurable warning/blocking at invoice issue (BR-AC.12) | Read live from items | Documents stay reproducible; ESF data frozen at issue |
| DEC-16 | The **sales document** in the CRM is the **delivery note** (`deliveries`, `DN-`): it posts the sale in the CRM (serials → sold, `delivered_qty`, on-delivery schedule items) and is published to 1C, which posts its realization and the legal e-waybill/ESF. Serialized items are **registered by serial number in the delivery note**; tax documents and prints reuse those serials, never re-entered | Serials at invoice; serials at order confirmation | The delivery note is the moment goods physically leave a warehouse, so the serial is known and verifiable; invoices always follow deliveries (`invoiced_qty ≤ delivered_qty`), so registration before invoicing is guaranteed |
| DEC-17 | **CRM-master serial registry** (`item_serials`), filled by the stock manager at **goods receipt**; **registry mode is the only mode**: a sale accepts only a serial registered for that item, `available` at the delivery warehouse. The "open mode" survives only as a disabled-by-default, per-line **override with reason** by the warehouse head | 1C serial feed (1C keeps none); open mode by default | Real "belongs to this item" check; override covers items that became serialized with stock not yet registered |
| DEC-18 | New role **`stock_manager`** + department **`warehouse`** (head = warehouse head/approver, specialist = stock manager, viewer). Stock manager moves goods and registers serials; never records money; accountant never moves goods | Give logistics or sales the warehouse rights | Segregation of duties between goods and money; matches the real job (omborchi / кладовщик) |
| DEC-19 | Goods receipts, transfers and adjustments are CRM documents published to 1C through the outbox (feed `warehouse`) **without serials** (setting); since 2026-10-19 supplier receipts originate in 1C and the CRM result is available for 1C to pull (section 26); 1C stock push remains the **quantity truth**; CRM serial counts are reconciled against it, never pushed into it | CRM as stock-quantity master | Respects 1C as book of record for quantities; serial identity lives in the CRM |
| DEC-20 | `.xlsx` import via **openspout/openspout** (MIT, streaming, no formula engine) — **requires user approval of the dependency**; phase 1 ships CSV-only with native PHP | PhpSpreadsheet, Laravel Excel | Low memory for 10 000-row files, small attack surface; see 21.8 |

## 4. Users, roles and permissions

### 4.1 ACCOUNTANT role definition
- `App\Enums\UserRole::Accountant = 'accountant'`, label key `users.roles.accountant` (uz "Hisobchi", ru "Бухгалтер", en "Accountant").
- Department membership: department code `finance` (seeded) with display name changed to "Accounting & Finance" (`departments` row name only; code unchanged so existing grants `dept:finance:*` keep working). Accountants are `finance/specialist`; chief accountant `finance/head`; auditors `finance/viewer`.
- Grant equivalence (section 4.3): `role:accountant` ≡ `dept:finance:specialist` (operator); `role:finance` ≡ `dept:finance:head` (controller/checker). A user may have both through role + department; maker-checker is still enforced **per record** (approver ≠ recorder), not per role.
- `security.2fa_required_roles` default becomes `["admin","finance","accountant"]` (Spec 03 4.4).
- Visibility scope: accountant sees **all** orders, agreements, invoices, payments, receivables and customers (company-wide function); offers **read-only, `linked`** (only offers referenced by an order, opened from the order page; no offers index). Note: `PermissionMap::applyScope` currently treats `linked` as `own`; the order page authorizes via `OfferPolicy::viewLinked` (offer has an order).
- Accountant **cannot**: create/edit offers, change prices, discounts or quantities, approve discounts, change order owner, approve own payments/voids/refunds, lock periods, set credit limits, sign agreements on behalf of the company (they prepare and send; signatory is a director/POA holder, Q-7).

### 4.2 Permission matrix (internal)
"own" = `owner_id` is the user; "dept" = owner shares a department; sales head = `dept:sales:head`; logistics = `dept:logistics:*`.

| Action | sales | sales head | manager | logistics | accountant | finance | admin |
|---|---|---|---|---|---|---|---|
| **Orders** view | own | dept | all | all (read) | all | all | no |
| Create order (from accepted offer, automatic) | system on accept (owner = offer owner) | ← | ← | – | – | – | – |
| Edit delivery address/date/contact while `new` | own | dept | all | date only | no | no | no |
| Confirm order (new → confirmed) | own | dept | all | no | no | no | no |
| Override hold (credit/overdue/prepayment) with reason | no | no | yes (not own order's credit) | no | no | yes | no |
| Start fulfilment / link shipment / draft delivery note | own (services) | dept | all | yes | no | no | no |
| Register serials and **post** delivery note (goods; stock manager / warehouse head only, section 21.3) | no | no | no | no (unless warehouse member) | no | no | no |
| Request transport (Spec 03) | own | dept | all | yes | no | no | no |
| Create amendment (qty, price, line, date, close-short) | own | dept | all | date only | payment schedule dates only | payment terms | no |
| Approve amendment | per Spec 05 discount limits | ≤ 10% | ≤ 25% | no | no | payment-terms changes | no |
| Cancel order (reason) | own, `new` only | dept, ≤ confirmed | all, no deliveries | no | no | no | no |
| Close order (invoiced → closed) | no | no | yes | no | yes | yes | no |
| View payment status of order | own | dept | all | status only | all | all | no |
| **Agreements** view | own orders' | dept | all | no | all | all | no |
| Generate draft from order / addendum / renewal | request only | request | yes | no | yes | yes | no |
| Edit draft (variables, clauses from template options) | no | no | yes | no | yes | yes | no |
| Submit for review / approve non-standard terms | no | no | approve | no | submit | approve (payment terms, liability) | no |
| Send to customer (portal, e-mail link, print) | no | no | yes | no | **yes** | yes | no |
| Upload signed scan / confirm signed | upload only (own) | upload | yes | no | **yes** | yes | no |
| Terminate (reason, date) | no | no | yes | no | prepare | approve | no |
| Manage agreement templates | no | no | yes | no | propose | yes (publish) | no |
| **Invoices** view | own orders' | dept | all | no | all | all | no |
| Issue/send proforma | request only | request | yes | no | **yes** | yes | no |
| Register tax document (VAT invoice/act/waybill) | no | no | no | no | **yes** | yes | no |
| Void invoice (reason) | no | no | no | no | request | approve | no |
| **Payments** view | own orders' (no bank details) | dept | all | no | all | all | no |
| Record payment | **no** | no | no | no | **yes** | yes | no |
| Approve payment above threshold | no | no | no | no | no (never own) | yes (not own) | no |
| Allocate / deallocate (open period) | no | no | no | no | yes | yes | no |
| Void payment / record refund | no | no | no | no | request | approve (not own) | no |
| Resolve 1C duplicate / unmatched payment | no | no | no | no | yes | yes | no |
| **Customers** view | all (Spec 01 D-7) | all | all | dept | all (read) | all | no |
| Edit billing contact flag, bank details, signatory | own | dept | all | no | yes | yes | no |
| Set credit limit / manual credit hold | no | no | propose | no | no | yes | no |
| **Offers** view | own | dept | all | no | linked (read) | all (read) | no |
| **Reports** receivables/aging, payments, agreements | own customers | dept | all | no | all | all | no |
| Export (CSV/XLSX) | own | dept | all | no | all | all | no |
| **Accounting periods** lock / reopen | no | no | no | no | no | lock; reopen = finance head + reason | no |
| Settings > Accounting (thresholds, reminders, validation modes, bank accounts) | no | no | view | no | view | edit | config only (values approved by finance) |
| 1C outbox monitor (events, acks, errors) | no | no | no | no | view | view | yes (incl. tokens) |

Portal (Spec 03 4.8, own company only, uuid keys, 404 on foreign): portal_admin/approver/finance — view/download orders, agreements, invoices, payment status and schedule; portal_admin/approver — upload signed agreement scan; buyer/viewer — orders only; all portal roles — warranty lookup of serials sold to their own company (21.10).

The **stock manager / warehouse** column set (goods receipts, delivery-note posting, serials, transfers, adjustments, reconciliation) is in section 21.3; the accountant has read-only access there.

### 4.3 Abilities for `config/permissions.php` (proposed; implemented in 06-0)
```php
$accountant = ['role:accountant' => 'all', 'dept:finance:specialist' => 'all'];
$controller = ['role:finance' => 'all', 'dept:finance:head' => 'all'];
// $everyRole gains 'role:accountant' => 'all' (customers.view, catalog.view, activity.view)

'orders.view'               => ['role:sales' => 'own', 'dept:sales:specialist' => 'own', 'dept:sales:head' => 'department',
                                'role:manager' => 'all', 'dept:logistics:*' => 'all'] + $accountant + $controller,
'orders.confirm'            => ['role:sales' => 'own', 'dept:sales:specialist' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all'],
'orders.fulfil'             => ['role:sales' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all', 'dept:logistics:*' => 'all'],
'orders.amend'              => ['role:sales' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all'],
'orders.amend_schedule'     => $accountant + $controller + ['role:manager' => 'all'],
'orders.cancel'             => ['role:sales' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all'],
'orders.close'              => $accountant + $controller + ['role:manager' => 'all'],
'orders.override_hold'      => $controller + ['role:manager' => 'all'],
'agreements.view'           => ['role:sales' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all'] + $accountant + $controller,
'agreements.prepare'        => ['role:manager' => 'all'] + $accountant + $controller,
'agreements.send'           => ['role:manager' => 'all'] + $accountant + $controller,
'agreements.confirm_signed' => ['role:manager' => 'all'] + $accountant + $controller,
'agreements.approve'        => ['role:manager' => 'all'] + $controller,
'agreement_templates.manage'=> ['role:manager' => 'all'] + $controller,
'invoices.view'             => ['role:sales' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all'] + $accountant + $controller,
'invoices.issue'            => ['role:manager' => 'all'] + $accountant + $controller,
'invoices.register_tax_doc' => $accountant + $controller,
'invoices.void_approve'     => $controller,
'payments.view'             => ['role:sales' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all'] + $accountant + $controller,
'payments.record'           => $accountant + $controller,
'payments.allocate'         => $accountant + $controller,
'payments.approve'          => $controller,          // threshold, voids, refunds; never own records
'accounting.periods'        => $controller,
'accounting.credit'         => $controller,
'accounting.settings'       => $controller,
'receivables.view'          => ['role:sales' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all'] + $accountant + $controller,
'onec.outbox.view'          => ['role:admin' => 'all'] + $accountant + $controller,
// Stock module abilities (goods receipts, delivery-note posting, serials): section 21.3
```
Admin keeps system rights only (users, settings structure, tokens). `payments.view` for sales hides bank account, statement attachment and payer details (policy `viewSensitive`).

### 4.4 Segregation of duties (enforced in Actions, tested)
- SoD-1 Sales cannot record, allocate, void or approve payments (no grant), and cannot change an order's payment schedule.
- SoD-2 Accountant cannot change prices, discounts, quantities or lines (no `orders.amend`, no offer edit); invoice lines derive from order lines (qty ≤ deliverable/invoiceable; price never editable).
- SoD-3 Approver ≠ recorder for payments above threshold, voids, refunds, invoice voids, period reopen (checked by user id, even if the same person holds both grants).
- SoD-4 Payment threshold (setting `accounting.payment_approval_threshold_minor`, per currency; defaults UZS 100 000 000.00, USD 10 000.00, EUR 10 000.00, RUB 1 000 000.00 — Q-8): a CRM-recorded payment ≥ threshold starts `pending_approval` and does not count until approved.
- SoD-5 Admin cannot hold business grants on these abilities (DEC-10 of Spec 03); an admin cannot add themselves to `finance` (Spec 03 security).
- SoD-6 Sales may **upload** a signed scan received from their customer, but only an accountant/manager/finance confirms `signed`.
- SoD-7 1C-sourced payments cannot be voided or edited in the CRM (book of record); only allocation changes.

### 4.5 Accountant notifications (in-app + e-mail; Telegram per Spec 03 preferences; keys `notifications.accounting.*`)
| Event | Recipients | Timing |
|---|---|---|
| Order confirmed, agreement required and none exists | accountants (finance dept specialists), owner FYI | immediate |
| Agreement in `sent_for_signature` with no signature for N days (`agreements.unsigned_reminder_days` = 5, repeating) | accountant who sent, owner | daily digest |
| Customer uploaded signed scan (needs verification) | accountant who sent | immediate |
| Agreement expiring in 90/60/30 days (`agreements.expiry_reminder_days`) | accountant, owner, manager (30 days) | daily |
| Proforma to issue: order confirmed with prepayment > 0 and no proforma | accountants | immediate |
| Delivery recorded, tax document not registered after N days (`accounting.tax_doc_due_days` = 3) | accountants | daily |
| Schedule item overdue | accountant digest, owner (immediate), customer (if enabled, BR-AC.17) | daily 09:00 Tashkent |
| Payment received from 1C (allocated / unallocated) | accountants; owner when allocated to their order | immediate (debounced 10 min) |
| Unallocated payment older than 2 days; possible duplicate | accountants | daily |
| Payment pending approval / void or refund requested | finance (approvers) | immediate |
| 1C rejected an outbox event (ack `rejected`) or event unacked > 60 min | accountants (payments/invoices), admin (all) | immediate / hourly |
| Credit hold set/released on an order | owner, accountants, finance | immediate |
Notifications never contain bank account numbers or statement attachments; amounts and document numbers are allowed.

### 4.6 Accountant dashboard widgets / work queues
"Agreements to send" (confirmed orders requiring agreement with status none/draft/approved), "Awaiting signature" (sent_for_signature, days waiting, last viewed), "Signed scans to verify", "Invoices to issue" (proforma due, deliveries without registered tax document), "Unallocated payments" (with age and suggested matches), "Pending 1C confirmation" (recorded payments/invoices not acked), "Overdue" (schedule items past due by bucket, amount per currency), "Holds" (orders on hold and reason), "Expiring agreements (90 days)". Each tile = count + link to a pre-filtered list. Finance additionally: "Approvals waiting", "Period close checklist".

## 5. User stories

Orders
- US-1 As a sales rep, when my customer accepts an offer, an order appears with exactly the chosen lines, amounts and terms, and I confirm it after the stock and credit checks.
- US-2 As a sales rep I see why an order is on hold (credit limit, overdue, prepayment missing, agreement unsigned) and who can release it.
- US-3 As logistics I record partial deliveries and link a transport tender/shipment for imported lines; the order shows ETA.
- US-4 As a sales rep I request an amendment (qty, date, close short) with approval when it changes price or terms; a signed agreement gets an addendum.
Agreements
- US-5 As an accountant I generate an agreement from a confirmed order from the right template, review the variables, and send it to the customer through the portal and e-mail in Uzbek, Russian or English.
- US-6 As an accountant I see whether the customer opened/downloaded it, get reminded when it stays unsigned, upload or verify the signed scan, and the agreement becomes active.
- US-7 As an accountant I prepare addenda and renewals and am reminded 90/60/30 days before expiry.
Accounting
- US-8 As an accountant I issue a proforma for the prepayment and send it; I register the VAT invoice/act/waybill numbers and ESF IDs from 1C so the order shows `invoiced`.
- US-9 As an accountant I record a payment (amount, currency, date, method, our bank account, payer, reference, proof) and allocate it to one or several orders/invoices; the remainder stays as customer advance.
- US-10 As an accountant I void a wrong entry with a reason (approved by finance) instead of deleting it, and record refunds as separate entries.
- US-11 As an accountant I see payments pushed by 1C, match unallocated ones to orders, and resolve possible duplicates of payments I recorded.
- US-12 As finance I approve large payments, voids and refunds, set credit limits, and lock the month after reconciliation with 1C.
- US-13 As a sales rep I see payment status and overdue amounts of my orders and get notified when my customer pays or is overdue.
- US-14 As an accountant I filter and group order lines, invoices and receivables by `accountant_code`, and see which lines lack `class_code`/`package_code` before issuing.
- US-15 As a portal finance user I download our agreement, invoices and payment schedule and upload the signed agreement.

## 6. Workflow impact

### 6.1 `OrderStatus` (backed string enum, `canTransitionTo()`, labels `orders.status.*`)
```
new ──confirm──► confirmed ──start──► in_progress ──delivery (part)──► partially_delivered ──delivery (rest)──► delivered ──all delivered qty invoiced──► invoiced ──paid + docs ok──► closed
 │                  │                     │ └────────────── delivery (all) ─────────────────────────────────────────►┘
 └──cancel──► cancelled ◄──cancel (no deliveries, no allocations)──┘
```
| From | To | Trigger / guard | Actor |
|---|---|---|---|
| (accepted offer) | new | `CreateOrderFromOffer(OfferToOrderPayload)`; idempotent by `offer_id` unique; totals equal payload | system |
| new | confirmed | `ConfirmOrder`: customer not "Deleted in 1C"; stock check run (shortages acknowledged, BR-OR.6); credit check passes or override (BR-AC.19); payment schedule generated; reservations created | owner, sales head, manager |
| new | cancelled | reason | owner, sales head, manager |
| confirmed | in_progress | `StartFulfilment`: no blocking hold (`prepayment_missing`, `agreement_unsigned`, `credit_limit`, `overdue`, `manual`) or override with reason | owner, logistics, manager |
| confirmed, in_progress | cancelled | reason; no delivery recorded; Σ active allocations = 0 (reallocate/refund first); releases reservations; draft/in_review/sent agreements → cancelled; outbox `order.cancelled` | manager (sales head for confirmed) |
| in_progress | partially_delivered | delivery note **posted** (every serialized line fully registered, section 21), some open qty remains | system |
| in_progress, partially_delivered | delivered | every line `delivered_qty + cancelled_qty = quantity` | system |
| delivered | invoiced | every line `invoiced_qty = delivered_qty` (registered tax documents, not voided) | system |
| invoiced | closed | `payment_status = paid`; agreement `signed`/`active` (or not required / framework); no `addendum_pending`; manual by accountant/finance/manager or nightly when `accounting.auto_close_orders` = true | accountant, finance, manager, system |

- Final: closed, cancelled. Reopen is not supported (corrections in 1C; credit notes roadmap).
- Every transition: `status_transitions` + `activity_log`; only Actions change status; outbox events on `confirmed`, amendment applied, `cancelled`, `closed`.
- Services: "delivery" = completion record (act); same rules.

### 6.2 Holds (DEC-7)
| Reason | Set when | Blocks | Cleared by |
|---|---|---|---|
| credit_limit | exposure + order gross > customer limit (BR-AC.19) at confirm | confirm | finance/manager override (reason) or exposure drops |
| overdue | customer has a schedule item overdue > `accounting.hold_overdue_days` (30) | confirm, start fulfilment | payment allocated / override |
| prepayment_missing | schedule prepayment item not fully covered | start fulfilment | allocation / override |
| agreement_unsigned | `orders.agreement_required` and no `signed`/`active` agreement (order or framework) | start fulfilment (setting `orders.block_fulfilment_without_agreement`, default true) | agreement signed / manager override |
| manual | finance sets (reason) | confirm + fulfilment | finance |
Overrides are per order and reason, logged, and shown as a badge "Released by <user>: <reason>".

### 6.3 `OrderPaymentStatus` (computed, stored on `orders.payment_status`)
`unpaid` (Σ allocations = 0) · `partially_paid` (0 < Σ < gross) · `paid` (Σ ≥ gross; overpayment stays unallocated on the payment, never on the order) · `overdue` (precedence over unpaid/partially_paid when any schedule item has outstanding amount and `due_on + accounting.overdue_grace_days` < today Tashkent). Recomputed on allocation/void/approval/1C correction and nightly at 00:15.

### 6.4 Amendments (`OrderAmendmentStatus`: draft → pending_approval → approved → applied | rejected | cancelled)
- Types (one amendment may hold several changes, stored as json diff): `qty_change`, `price_change`, `add_line` (from catalog, priced by `PriceResolver` at today), `remove_line`, `close_short` (sets `cancelled_qty`), `date_change`, `address_change`, `payment_terms_change`, `schedule_change`.
- Allowed in confirmed, in_progress, partially_delivered (close_short also in partially_delivered). In `new`, only address/date/contact edits without amendment.
- Guards: new qty ≥ delivered_qty and ≥ invoiced_qty; removing a line requires delivered_qty = 0; total may not drop below Σ registered tax documents (credit notes roadmap) nor below Σ allocations without a reallocation/refund plan.
- Approval steps reuse Spec 05 rules: price/discount → discount limits; payment terms → finance; qty increase → stock check warning.
- On apply: lines/totals recomputed with `OfferCalculator` line math (BR-1 of Spec 05), reservations adjusted, schedule regenerated for unpaid items, outbox `order.amended` (version + 1). If a signed agreement exists and price/total/terms changed, an addendum draft is created and `orders.addendum_pending = true` (blocks closing, not delivery).

### 6.5 `AgreementStatus` (labels `agreements.status.*`)
```
draft ──submit──► in_review ──approve──► (approved_at set) ──send──► sent_for_signature ──signed confirmed──► signed ──starts_on reached──► active ──ends_on passed──► expired
  │  ▲               │ return (comment)                              │                                                      │ ──terminate──► terminated
  │  └───────────────┘                                               │                                                      └─ renewal signed ─► renewed
  └── send (standard terms, no review needed) ───────────────────────┘
draft | in_review | sent_for_signature ── cancel (reason) ──► cancelled
```
| From | To | Guard | Actor |
|---|---|---|---|
| draft | in_review | `AgreementValidator` passes; review required when non-standard (template clauses changed, payment terms deviate from customer default, liability clause edited, value ≥ `agreements.review_above_minor`) | accountant, manager |
| in_review | draft | returned with comment | finance, manager |
| in_review | sent_for_signature | approved (approver ≠ preparer) + `SendAgreement` | accountant, manager, finance |
| draft | sent_for_signature | review not required + `SendAgreement` | accountant, manager, finance |
| sent_for_signature | signed | `ConfirmAgreementSigned`: signed scan attached (pdf/jpg/png), customer signatory name/position/date, our signatory, signed_on ≤ today | accountant, manager, finance |
| signed | active | `starts_on` ≤ today (immediately or scheduler 00:05) | system |
| active | expired | `ends_on` < today and no renewal signed and `auto_renew = false` | system |
| active | renewed | renewal agreement (`renewal_of_id`) becomes signed; or `auto_renew = true` at `ends_on` (new term row created automatically, status signed→active, logged) | system |
| active, signed | terminated | `TerminateAgreement`: reason code, termination date ≥ today + notice days unless mutual; open orders listed and must be resolved (cancel or move to new agreement) | finance/manager (accountant prepares) |
| draft, in_review, sent_for_signature | cancelled | reason; on order cancellation automatic | accountant, manager, system |

Sent sub-tracking (not statuses): `agreement_dispatches` rows (channel, recipient, sent_at, sent_by, viewed_at, downloaded_at, download_count), `customer_signed_uploaded_at`. Resend creates a new dispatch with the same version; any content change after send requires `cancel` + new version (version + 1) — sent text is immutable.

Addendum (`kind = addendum`): draft → (in_review) → sent_for_signature → signed | cancelled; signing an addendum applies to the parent; parent stays `active`.

### 6.6 Invoices (`InvoiceType`: `proforma`, `tax_invoice`, `act`, `waybill`; `InvoiceStatus`: draft → issued → voided)
- Proforma: draft → issued (number `PF-YYYY-NNNNN`, outbox `invoice.issued`) → voided (reason, finance approval). Payment state derived: `invoices.paid_minor`, badge unpaid/partially paid/paid.
- Tax documents (registered): draft → issued (= registered with external number/date, ESF ID optional; outbox `invoice.registered` so 1C can link) → voided. `source` = `crm_registered` (v1) \| `onec` (1C push, roadmap `documents:write`). Lines carry qty → `order_lines.invoiced_qty`.
- `esf_status` (informational, nullable): `not_applicable`, `pending`, `sent`, `accepted`, `rejected`, `cancelled` — manually set in v1 (Q-2).

### 6.7 `PaymentStatus`
```
(CRM, < threshold) recorded ──1C ack applied──► confirmed
(CRM, ≥ threshold) pending_approval ──approve──► recorded ──► confirmed
                                    ──reject──► rejected
recorded ──1C ack rejected──► onec_rejected (does not count; accountant voids and re-records)
recorded | confirmed | onec_rejected ──void (reason, approved)──► voided
(1C push) ──► confirmed directly; 1C deletion mark ──► voided (reason deleted_in_1c)
```
Counts toward balances: `recorded` (if DEC-13 setting), `confirmed`. Never: `pending_approval`, `rejected`, `onec_rejected`, `voided`. Void of a `recorded`/`confirmed` CRM payment → outbox `payment.voided`. A 1C-sourced payment cannot be voided in the CRM (SoD-7).

### 6.8 Handoffs
- **Offer → Order**: `OfferToOrderPayload` (Spec 05; immutable DTO built from the accepted `offer_versions` payload + selection): offer id/version/accepted hash, customer, contact, owner, currency, `prices_include_vat`, terms (payment_terms_days, prepayment_bp, payment/delivery/warranty text), document locale, totals, and lines = fixed + selected only, each with `offer_line_id`, `offer_option_group_id`, group title, option label, item id + snapshot (`item_external_id`, sku, article, name, unit, precision, tax, `price_list_external_id`, **`accountant_code`, `class_code`, `package_code`**), qty, unit price, discount, line net/vat/gross. `CreateOrderFromOffer` copies amounts verbatim (no recomputation); test asserts Σ order = offer selected totals to the minor unit and codes equal offer-line snapshots.
- **Order → Agreement**: parties (company profile + customer, bank accounts, signatories), scope (order lines snapshot), totals, payment terms and schedule, delivery and warranty terms, `order_id`.
- **Order → Invoice lines**: from order lines (qty ≤ remaining invoiceable), price and codes copied from the order line (not from items).
- **Order → Transport request** (Spec 03 6.3): `tender_sources(order)`, destination, promised date, items/HS; `deliveries.shipment_id` links execution.
- **CRM → 1C**: outbox events (section 9). **1C → CRM**: acks (GUIDs, numbers, posting status) and payments.

## 7. Data model

Conventions: money `*_minor` unsigned bigint + `currency` char(3) on the row (ledger amounts signed bigint); quantities decimal(15,3) as strings via `Quantity`/BCMath; VAT `*_bp`; string enums; `uuid` route keys (portal/public/outbox); `restrictOnDelete` everywhere on financial FKs; **no soft deletes and no hard deletes** on orders, agreements (except never-sent drafts), invoices (except drafts), payments, allocations, entries. Numbers from `number_sequences` (Spec 04), yearly reset: `OR-`, `AG-`, `PF-`, `PM-` (payments), `RF-` (refunds).

### 7.1 Item code columns (owned by the offers increment; listed for traceability)
`items.accountant_code` string(32) nullable index (1C-sent; CRM-editable by accountant/admin — Spec 02 5.2 field-ownership row to be amended: "1C value overwrites unless locally edited" is an open question, Q-20), `items.class_code` string(17) nullable (IKPU/MXIK), `items.package_code` string(20) nullable. Exact formats and lengths are **open** (Q-19): validate only "digits only" softly until confirmed.

### 7.2 `orders`
| Column | Type | Notes |
|---|---|---|
| id, uuid | PK, unique | |
| number | string(20) unique | `OR-YYYY-NNNNN` |
| status | string(24) index | `OrderStatus` |
| payment_status | string(16) index | `OrderPaymentStatus` |
| offer_id | FK offers **unique** | DEC-1 |
| offer_version | unsigned smallint | |
| offer_accepted_hash | char(64) | proof link |
| customer_id | FK customers index | |
| contact_id, billing_contact_id | FK contacts nullable | billing = reminders/invoices recipient |
| owner_id | FK users index | |
| framework_agreement_id | FK agreements nullable | 06-12 |
| currency | char(3) | |
| prices_include_vat | boolean | |
| net_minor, vat_minor, gross_minor | unsigned bigint | verbatim from payload; changed only by amendments |
| paid_minor | unsigned bigint default 0 | cache of Σ counted allocations |
| invoiced_gross_minor | unsigned bigint default 0 | cache of Σ issued tax docs |
| payment_terms_days | unsigned smallint nullable | deferred days after delivery |
| prepayment_bp | unsigned smallint nullable | |
| payment_terms_text, delivery_terms_text, warranty_text | text nullable | snapshot |
| delivery_address | text nullable | |
| requested_delivery_on, promised_delivery_on | date nullable | |
| document_locale | string(2) nullable | |
| agreement_required | boolean default true | from setting at creation; manager may waive with reason |
| addendum_pending | boolean default false | |
| hold_reasons | json nullable | DEC-7: `[{reason, set_at, overridden_by, overridden_at, override_reason}]` |
| on_hold | boolean index | derived from hold_reasons (non-overridden present) |
| confirmed_at/by, started_at, delivered_at, invoiced_at, closed_at/by, cancelled_at/by, cancel_reason | | |
| revision | unsigned int default 1 | +1 per applied amendment; optimistic lock + outbox version |
| onec_guid | char(36) nullable unique | from ack |
| onec_number | string(30) nullable | |
| onec_state | string(16) default `not_sent` | `not_sent`, `queued`, `acked`, `rejected`, `posted`, `unposted`, `deleted` |
| onec_acked_at, onec_error | | |
| timestamps | | |
Indexes: (`customer_id`, `status`), (`owner_id`, `status`), (`payment_status`), (`on_hold`), (`status`, `promised_delivery_on`).

### 7.3 `order_lines`
`order_id` FK restrict (orders are never deleted), `position`, `offer_line_id` FK nullable (null for amendment-added lines), `offer_option_group_id` nullable, `option_label` char(1) nullable, `group_title` string(255) nullable, `item_id` FK restrict, snapshot (`item_external_id` char(36), `sku`, `article`, `name`, `unit_id`, `unit_code`, `unit_name`, `unit_precision`, `tax_rate_bp`, `is_vat_exempt`, `price_list_external_id`, **`accountant_code` string(32) null, `class_code` string(17) null, `package_code` string(20) null**), `quantity` decimal(15,3), `unit_price_minor`, `discount_bp`, `line_net_minor`, `line_vat_minor`, `line_gross_minor`, `line_discount_minor`, `delivered_qty`, `invoiced_qty`, `cancelled_qty` decimal(15,3) default 0, `warehouse_id` FK nullable (preferred), `lead_time_days` nullable, `requires_import` boolean, `added_by_amendment_id` nullable. Checks (DB CHECK where supported + Action guard): `delivered_qty + cancelled_qty ≤ quantity`, `invoiced_qty ≤ delivered_qty`. Indexes (`order_id`, `position`), (`item_id`), (`accountant_code`).

### 7.4 `order_amendments`
`order_id`, `number` (per order 1..n), `status`, `changes` json (per change: type, order_line_id, old, new), `reason` text, `requested_by`, `approval_steps` json (step, status, decided_by, decided_at, comment), `applied_at`, `applied_by`, `revision_from`, `revision_to`, `totals_before` / `totals_after` json, `addendum_agreement_id` nullable.

### 7.5 `stock_reservations`
`order_line_id`, `item_id`, `warehouse_id`, `quantity` decimal(15,3), `status` (`active`, `mirrored_in_1c`, `consumed`, `released`), `created_at`, `released_at`, `release_reason`. Index (`item_id`, `warehouse_id`, `status`). Available-for-sale = Σ(`item_stocks.quantity − reserved`) − Σ `active` reservations (Spec 05 BR-23 uses the same helper `StockAvailability`).

### 7.6 `deliveries`, `delivery_lines` — the delivery note = **sales document** (DEC-16)
deliveries: `uuid`, `number` (`DN-YYYY-NNNNN`), `order_id`, `status` (`DeliveryNoteStatus`: `draft` → `posted`; `draft` → `cancelled`), `warehouse_id` (required when any goods line), `planned_on`, `shipped_on`, `delivered_on`, `shipment_id` FK nullable (Spec 03), `tender_id` nullable (via `tender_sources`), `created_by`, `posted_by`, `posted_at`, `onec_guid`, `onec_number` (1C realization), `onec_state`, `note`, timestamps. delivery_lines: `delivery_id`, `order_line_id`, `item_id`, `is_serialized` (snapshot at line creation), `quantity` decimal(15,3) (serialized: whole number and **equal to the count of active serial rows**, derived), `returned_qty` default 0. Only **posted** notes add to `delivered_qty`; a posted note is immutable — corrections through stock adjustments or customer returns (section 21). Serial rows: `document_line_serials` (21.5).

`order_lines` add `is_serialized` (snapshot from the offer line) and `returned_qty` decimal(15,3) default 0. `invoice_lines` add `delivery_line_id` FK nullable (required for serialized lines, so prints list the serials of that delivery line).

### 7.7 `payment_schedule_items`
`order_id`, `agreement_id` nullable, `kind` (`prepayment`, `on_delivery`, `milestone`), `label` string(255) nullable, `delivery_id` nullable (on_delivery), `percent_bp` nullable, `amount_minor`, `currency` (= order), `due_on` date, `created_by` (null = system), `superseded_at` nullable (schedule edits supersede, never delete), `superseded_by_id`. Invariant: Σ active items = order gross (validated on every regeneration/edit; rounding remainder on the last item). Index (`order_id`, `due_on`), (`due_on`) for overdue scans. Covered amount per item computed FIFO from order allocations (DEC-5).

### 7.8 Agreement tables
`agreement_templates`: `code` unique, `name`, `kind` (`order`, `framework`, `addendum`, `termination`), `is_active`, `created_by`.
`agreement_template_versions`: `template_id`, `version`, `locale` (uz/ru/en), `body` longText (HTML subset with `{{ var }}` placeholders and `{{#clause:code}}…{{/clause}}` optional blocks), `variables` json (declared list), `status` (`draft`, `published`, `retired`), `published_by/at`, `checksum`. Unique (`template_id`, `version`, `locale`). Published versions immutable.

`agreements`:
| Column | Type | Notes |
|---|---|---|
| id, uuid | | portal/public key |
| number | string(30) unique | `AG-YYYY-NNNNN`; addendum `AG-2026-00012/A1` |
| kind | string(12) | DEC-9 |
| status | string(20) index | `AgreementStatus` |
| order_id | FK orders nullable, unique where kind=order (app-enforced) | originating order |
| customer_id | FK index | |
| parent_agreement_id | FK nullable | addendum → parent |
| renewal_of_id, renewed_by_id | FK nullable | |
| template_id, template_version | | |
| document_locale | string(2) | primary language |
| secondary_locale | string(2) nullable | bilingual print (e.g. uz + ru) |
| variables | json | frozen snapshot of resolved variables (parties, bank details, signatories, lines summary, totals, terms) |
| clause_options | json nullable | optional clauses on/off; deviations flagged |
| is_non_standard | boolean | drives review |
| version | unsigned smallint default 1 | +1 on re-issue after cancel |
| starts_on, ends_on | date nullable | |
| auto_renew | boolean default false | |
| notice_days | unsigned smallint default 30 | |
| value_minor, currency | nullable | |
| prepared_by, approved_by, approved_at, sent_at, sent_by | | |
| customer_signed_uploaded_at | timestamp nullable | DEC-11 |
| signed_on | date nullable | |
| signed_confirmed_by | FK users nullable | |
| our_signatory_id | FK company_signatories nullable | |
| customer_signatory_name, customer_signatory_position, customer_signatory_basis | string nullable | |
| signed_attachment_id | FK attachments nullable | |
| terminated_on, termination_reason_code, termination_reason, cancelled_at, cancel_reason | | |
| onec_guid, onec_number, onec_state, onec_acked_at | | outbox ack |
| timestamps | | |

`agreement_versions` (immutable rendered snapshot at send): `agreement_id`, `version`, `locale`, `html` longText, `sha256` char(64), `rendered_at`, `rendered_by`. Unique (`agreement_id`, `version`, `locale`).
`agreement_dispatches`: `agreement_id`, `version`, `channel` (`portal`, `email_link`, `print`, `courier`), `recipient_contact_id` nullable, `recipient_user_id` nullable, `recipient_email` (masked in logs), `locale`, `share_token_hash` char(64) nullable unique, `token_expires_at`, `sent_at`, `sent_by`, `viewed_at`, `downloaded_at`, `download_count`, `revoked_at`.

### 7.9 Company and customer finance data
- `company_bank_accounts`: `bank_name`, `mfo` string(5), `account_number` string(20), `currency`, `is_default` per currency, `is_active`, `onec_guid` nullable.
- `company_signatories`: `full_name`, `position`, `basis` (charter / power of attorney no. and date), `valid_until` nullable, `is_default`, `is_active`.
- Settings `company.*` (Settings > Company, currently `soon`): legal name per locale, INN, VAT registration code, OKED, address per locale, director, chief accountant, phone, logo.
- `customer_bank_accounts` (CRM-local v1; 1C-owned later, Q-18): `customer_id`, `bank_name`, `mfo`, `account_number`, `currency`, `is_default`.
- `customers` add: `vat_registration_code` string(20) null, `oked` string(10) null, `signatory_name`, `signatory_position`, `signatory_basis`, `credit_limit_minor` unsigned bigint null + `credit_limit_currency` char(3) null (null = no limit), `manual_hold` boolean, `manual_hold_reason`. CRM-only (never pushed, Spec 02 5.2 CRM-only group).
- `contacts` add: `is_billing_contact` boolean, `is_signatory` boolean (CRM-only).

### 7.10 `invoices`, `invoice_lines`
invoices: `uuid`, `type`, `status`, `source` (`crm`, `crm_registered`, `onec`), `number` string(40) (PF- for proforma; external number for tax docs, unique per (`type`, `number`, `issued_on` year)), `issued_on` date, `due_on` date nullable (proforma), `order_id` FK, `customer_id`, `agreement_id` nullable, `currency`, `prices_include_vat`, `net_minor`, `vat_minor`, `gross_minor`, `requested_amount_minor` nullable (proforma "to pay now", e.g. prepayment share), `paid_minor` default 0 (cache), `bank_account_id` (our account printed), `document_locale`, `esf_id` string(64) nullable, `esf_status` nullable, `external_ref` (1C number) nullable, `attachment_id` nullable (scan/PDF of the legal document), `period` char(7) (YYYY-MM of `issued_on`), `issued_by`, `voided_at/by`, `void_reason`, `void_approved_by`, `onec_guid`, `onec_state`, `code_warnings` json nullable (BR-AC.12 result at issue), timestamps. Index (`order_id`), (`customer_id`, `status`), (`type`, `issued_on`), (`period`).
invoice_lines: `invoice_id`, `order_line_id` FK, `position`, snapshot copied from the order line (`item_external_id`, `sku`, `name`, `unit_code`, `unit_name`, `tax_rate_bp`, `is_vat_exempt`, **`accountant_code`, `class_code`, `package_code`**), `quantity`, `unit_price_minor`, `line_net_minor`, `line_vat_minor`, `line_gross_minor`. Index (`order_line_id`), (`accountant_code`), (`class_code`).

### 7.11 `payments`
| Column | Type | Notes |
|---|---|---|
| id, uuid | | `uuid` = `crm_id` sent to 1C |
| number | string(20) unique | `PM-YYYY-NNNNN` / `RF-` |
| direction | string(8) | `incoming`, `outgoing` |
| kind | string(10) | `receipt`, `refund` |
| status | string(16) index | `PaymentStatus` |
| source | string(8) | `crm`, `onec` |
| customer_id | FK nullable index | null only for 1C payments with unknown payer ("unmatched") |
| amount_minor | unsigned bigint | > 0 |
| currency | char(3) | |
| paid_on | date index | value date (bank) / cash date |
| method | string(16) | `bank_transfer`, `cash`, `card` (Q-21 netting later) |
| company_bank_account_id | FK nullable | required for bank_transfer/card |
| payer_name | string(255) | may differ from customer (third-party payer; note required) |
| payer_tax_id | string(32) nullable | |
| payer_account | string(34) nullable | masked in UI except for accountant/finance |
| reference_number | string(64) nullable | payment order no. / receipt no. / card slip |
| purpose | text nullable | bank "назначение платежа" |
| refund_of_payment_id | FK nullable | refunds |
| proof_attachment_id | FK attachments nullable | bank statement/receipt; required for bank_transfer ≥ threshold (setting) |
| allocated_minor | unsigned bigint default 0 | cache |
| period | char(7) | from `paid_on` |
| recorded_by | FK users nullable | null for 1C |
| approved_by, approved_at, rejected_by, rejected_at, reject_reason | | SoD-3/4 |
| voided_by, voided_at, void_reason, void_requested_by, void_requested_at | | |
| duplicate_of_id | FK payments nullable | possible duplicate link |
| match_state | string(16) nullable | `possible_duplicate`, `linked`, `distinct` |
| onec_guid | char(36) nullable unique | |
| onec_number, onec_posted (bool), onec_updated_at, onec_version, onec_acked_at, onec_error | | |
| timestamps | | |
Indexes: (`customer_id`, `paid_on`), (`status`), (`reference_number`), (`period`), (`source`, `status`). Unique (`source`, `onec_guid`) where not null. Model: `updating` throws when any money/identity column is dirty; `deleting` always throws.

### 7.12 `payment_allocations` (append-only)
`payment_id`, `order_id` (required), `invoice_id` nullable (same order), `amount_minor` (payment currency, signed: negative = deallocation reversing `reverses_id`), `order_amount_minor` (order currency, signed), `fx_rate` decimal(18,8) nullable (order currency per 1 payment currency unit), `fx_rate_date` nullable, `fx_source` (`cbu_manual`, `agreement`, `onec`), `reverses_id` nullable, `reason` nullable (required for reversals), `period`, `created_by`, `created_at`. Guards: payment.customer_id = order.customer_id; Σ allocations per payment ≤ amount; invoice belongs to the order.

### 7.13 `accounting_entries` (append-only ledger, DEC-4)
`uuid`, `entry_type` (`payment_recorded`, `payment_approved`, `payment_confirmed`, `payment_voided`, `refund_recorded`, `allocation`, `deallocation`, `invoice_issued`, `invoice_voided`, `onec_correction`, `fx_note`), `customer_id`, `order_id` null, `invoice_id` null, `payment_id` null, `allocation_id` null, `amount_minor` **signed bigint**, `currency`, `occurred_on` date, `period` char(7), `source` (`crm`, `onec`, `system`), `causer_id` nullable, `reverses_entry_id` nullable, `meta` json (no bank numbers), `created_at`. No `updated_at`. Index (`customer_id`, `occurred_on`), (`order_id`), (`payment_id`), (`period`). Optional MySQL triggers rejecting UPDATE/DELETE (ops toggle).

### 7.14 Other accounting tables
- `accounting_periods`: `period` char(7) unique, `status` (`open`, `locked`), `locked_by/at`, `reopened_by/at`, `reopen_reason`, `checklist` json (pending 1C confirmations, unallocated count at lock time).
- `fx_rates`: `currency`, `rate_date`, `rate` decimal(18,8) UZS per 1 unit, `source` (`cbu_manual` v1, `cbu_api` later), `entered_by`. Unique (`currency`, `rate_date`).
- `dunning_events`: `schedule_item_id`, `order_id`, `customer_id`, `stage` (`pre_due`, `due_1`, `due_7`, `due_14`, `due_30`), `channel` (`email`, `telegram`, `in_app`), `recipient`, `sent_at`, `locale`. Unique (`schedule_item_id`, `stage`, `channel`) → idempotent reminders.

### 7.15 `onec_outbox`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | = monotonic `sequence` exposed to 1C |
| event_id | uuid unique | idempotency key for 1C |
| event_type | string(32) index | section 9.2 |
| aggregate_type | string(16) | `order`, `agreement`, `invoice`, `payment` |
| aggregate_id, aggregate_uuid | | |
| aggregate_version | unsigned int | order revision, agreement version, etc. |
| feed | string(10) index | `orders` (orders, agreements, invoices) \| `payments` → ability |
| payload | json | full snapshot at event time (no PII beyond document needs) |
| payload_hash | char(64) | |
| status | string(12) index | `pending`, `delivered`, `acked`, `rejected`, `superseded` |
| delivered_count, first_delivered_at, last_delivered_at | | |
| acked_at, ack_result, ack_payload json, onec_guid, onec_number, error | | |
| created_at | | |
Written in the **same DB transaction** as the business change (transactional outbox). Pruned 180 days after ack.

### 7.16 Settings (`settings` table; Settings > Accounting and > Agreements)
`accounting.payment_approval_threshold_minor` {UZS, USD, EUR, RUB}, `accounting.count_unconfirmed_payments` true, `accounting.prepayment_due_days` 5, `accounting.overdue_grace_days` 0, `accounting.hold_overdue_days` 30, `accounting.tax_doc_due_days` 3, `accounting.reminder_days_before_due` 3, `accounting.reminder_stages_after_due` [1, 7, 14, 30], `accounting.customer_reminders_enabled` **false**, `accounting.auto_close_orders` true, `accounting.proof_required_above_minor` = threshold, **`accounting.invoice_code_validation`** = `{ accountant_code: "warn", class_code: "warn", package_code: "warn" }` (each `off` \| `warn` \| `block`), `accounting.esf_goods_scope` = `goods_only` (which lines need class/package code; Q-19), `accounting.invoice_print_columns` = `{ accountant_code: false, class_code: true, package_code: true }`, `orders.agreement_required` true, `orders.block_fulfilment_without_agreement` true, `orders.block_confirm_on_shortage` false, `agreements.unsigned_reminder_days` 5, `agreements.expiry_reminder_days` [90, 60, 30], `agreements.review_above_minor` per currency, `agreements.share_link_days` 30.

### 7.17 Migration order (after Spec 04 rails, Spec 05 offers, Spec 03 attachments/notifications)
users role enum value (no schema change; string column) → departments name update (data migration, reversible) → customers/contacts alters → company_bank_accounts, company_signatories, customer_bank_accounts → accounting_periods, fx_rates → orders → order_lines → order_amendments → stock_reservations → deliveries → delivery_lines → payment_schedule_items → agreement_templates → agreement_template_versions → agreements → agreement_versions → agreement_dispatches → invoices → invoice_lines → payments → payment_allocations → accounting_entries → dunning_events → onec_outbox → (section 21) item_serials → serial_events → goods_receipts → goods_receipt_lines → stock_transfers → stock_transfer_lines → stock_adjustments → stock_adjustment_lines → document_line_serials → serial_import_batches → serial_import_rows. Then `tender_sources` FK to orders (Spec 03 T-6). All additive and reversible; no production data.

## 8. Business rules

### 8.1 Orders (BR-OR)
- BR-OR.1 `CreateOrderFromOffer` runs in one transaction, `lockForUpdate` on the offer; existing order for `offer_id` → returned (idempotent); unique index is the final guard (catch duplicate → return existing).
- BR-OR.2 Amounts and codes are copied, never recomputed or re-read from items (DEC-15).
- BR-OR.3 Customer "Deleted in 1C" or merged → confirmation blocked with message; owner notified.
- BR-OR.4 Numbering `OR-YYYY-NNNNN` at creation.
- BR-OR.5 Confirmation computes availability per line (`StockAvailability`, warehouses `orders.stock_warehouse_ids` or line warehouse) using the latest 1C snapshot; stock feed stale (Spec 02 BR-I.21) → warning "stock data is N min old".
- BR-OR.6 Shortage (available < qty) → line flagged backorder; confirmation requires ticking "I acknowledge shortages" (blocking only if `orders.block_confirm_on_shortage`). Reservations are created for available quantity only.
- BR-OR.7 Reservations: created on confirm; adjusted on amendment; consumed on delivery; released on cancel/close-short; flipped to `mirrored_in_1c` when the `order.confirmed` event is acked `applied` (1C reserves); if 1C later reports `deleted` the order shows an error and the CRM rows go back to `active`.
- BR-OR.8 Delivery note: qty per line ≤ open qty (`quantity − delivered − cancelled`), unit precision respected; serialized lines whole numbers with one registered serial per unit (BR-SN rules, section 21); posting recomputes order status and consumes `stock_reservations`; services completion same (no serials).
- BR-OR.9 Transport: lines with `requires_import` (stock band `on_request` at offer or flagged by logistics) show "Request transport"; delivery promise date updated from shipment ETA (owner notified when it moves later).
- BR-OR.10 Cancellation per 6.1 table; allocated payments must be zero (deallocate → customer advance or refund first).
- BR-OR.11 Close: per 6.1; auto-close job nightly 01:00 idempotent.

### 8.2 Agreements (BR-AG)
- BR-AG.1 Generation: `GenerateAgreement(order, template, locale, secondary_locale?)` resolves variables from a **whitelist** (`company.*`, `customer.*`, `customer_bank.*`, `signatory.*`, `order.number/date/lines/totals/amount_in_words`, `terms.*`, `schedule.*`, `agreement.number/starts_on/ends_on`), formats money/dates/amount in words with `DocumentFormatter` in each locale, stores `variables` snapshot. Unknown placeholders → validation error listing them (template publish also lints).
- BR-AG.2 `AgreementValidator`: required variables present (customer INN, legal name, address, bank account, signatory; our signatory valid on `starts_on`), `starts_on ≤ ends_on`, value equals order gross (order kind), template version published.
- BR-AG.3 One non-cancelled `order` agreement per order. Framework: order may reference an `active` framework agreement of the same customer valid on order date (then `agreement_required` satisfied).
- BR-AG.4 Send: renders `agreement_versions` for `document_locale` (+ secondary), creates dispatch rows per channel. Portal: notification to customer portal_admin/approver/finance users. E-mail: message in recipient contact's `preferred_locale` (else customer) with a secure link `/a/{token}` (token 256-bit, hashed, expires `agreements.share_link_days`) that opens view/print **after OTP to that contact** (Spec 03 OTP, purpose `agreement_view`); PDF attachment when the Spec 05 PDF engine exists. Print: `GET /agreements/{agreement}/print?lang=` via `DocumentRenderer::print`.
- BR-AG.5 Viewed/downloaded tracked only on portal/secure-link opens (no e-mail pixels).
- BR-AG.6 Reminder: unsigned after N days → accountant + owner; customer reminder e-mail only when `accounting.customer_reminders_enabled`.
- BR-AG.7 Signed confirmation per 6.5; scan stored as private attachment with sha256; signed agreements and their versions are immutable; changes only via addendum.
- BR-AG.8 Activation/expiry/renewal schedulers idempotent (00:05 Tashkent). Expiry reminders 90/60/30 days, one per stage (dedupe key).
- BR-AG.9 Termination lists open orders; requires each to be cancelled, completed, or re-linked to another active agreement before `terminated_on`.

### 8.3 Accounting (BR-AC)
- BR-AC.1 Money: integer minor units + currency, BCMath, half-up; never float; currencies from `config('crm.currencies')`.
- BR-AC.2 Schedule generation at confirm: prepayment item = round_half_up(gross × prepayment_bp / 10000), due confirmed_on + `prepayment_due_days`; remainder generated per delivery as `on_delivery` items = delivered lines gross × (1 − prepayment share), due delivered_on + `payment_terms_days`; last delivery takes the rounding remainder so Σ = gross. Milestones replace this when the agreement/order defines them (accountant edits schedule; Σ must equal gross; changes to dates beyond customer terms need finance approval).
- BR-AC.3 Proforma: lines from order lines (all or selected), `requested_amount_minor` default = next uncovered schedule item; due_on = that item's due date; prints our default bank account in the order currency; "This is not a VAT invoice".
- BR-AC.4 Tax document registration: type, external number, date, ESF ID/status optional, lines with qty ≤ `delivered_qty − invoiced_qty`; amounts computed from the order line unit price (same line math, half-up per line); scan optional; issuing date must be in an open period. Serialized lines must reference posted delivery lines (`delivery_line_id`) and qty ≤ that line's not-yet-invoiced qty; the serials printed are those of the delivery line (no re-entry).
- BR-AC.5 Recording a payment: required customer (or unmatched only for 1C), amount > 0, currency, paid_on ≤ today and in an open period, method, company bank account for bank_transfer/card, payer name, reference number for bank_transfer/card, proof attachment when ≥ `proof_required_above_minor`. Duplicate guard: same customer + amount + currency + reference + paid_on exists (non-voided) → blocking confirm dialog "possible duplicate of PM-…".
- BR-AC.6 Status after record: < threshold → `recorded` (counts per DEC-13); ≥ threshold → `pending_approval`. Outbox `payment.recorded` only when it reaches `recorded`.
- BR-AC.7 Allocation: to one or many orders (and optionally their invoices) of the **same customer**; Σ ≤ payment amount; unallocated remainder = customer advance (shown on customer, offered for allocation on the next order). Default suggestion: oldest overdue/due schedule items first.
- BR-AC.8 FX: payment currency ≠ order currency → `fx_rate` required (default proposal: `fx_rates` CBU rate of `paid_on`, editable, source stored); `order_amount_minor = round_half_up(amount_minor × rate)` with currency exponents; FX differences are not booked in the CRM (1C). Q-6 covers legal currency rules.
- BR-AC.9 Over/under payment: under → order `partially_paid`; over → order `paid`, remainder unallocated; never an order allocation above its gross minus other allocations.
- BR-AC.10 Deallocation: reversal row with reason, open period only; does not touch the payment.
- BR-AC.11 Void: request (reason) by accountant → approval by a different `payments.approve` user → `voided`, reversal entries for the payment and all its active allocations dated today (if the original period is locked) or original date (open period). Refund: outgoing payment `kind=refund` against an incoming payment's unallocated balance, always needs approval; outbox `payment.refund_recorded`.
- BR-AC.12 **Issue-time code validation** (`InvoiceCodeValidator`, run on proforma issue and tax-document registration and shown live in the builder): for each line, empty `accountant_code` → per setting `invoice_code_validation.accountant_code` (default `warn`); for lines in scope `accounting.esf_goods_scope` (default: items of type `product`; exemptions and scope Q-19), empty `class_code` or `package_code` → per setting (default `warn`); format check (IKPU 17 digits) is a **warning only** until Q-19 confirms formats. `block` mode refuses "Issue/Register/Send" with the list of lines; `warn` mode requires ticking "Issue with missing codes" and stores `code_warnings` on the invoice. Fixing a code means fixing the item (accountant/admin for `accountant_code`; 1C for class/package) and refreshing the **draft** invoice line snapshot (button "Refresh codes from catalog", logged); issued invoices never change.
- BR-AC.13 Period lock: no record/approve/allocate/deallocate/void/issue/register dated in a locked period; corrections go to the open period. Lock preconditions (checklist, warnings unless finance confirms): no `pending_approval`; no `recorded` CRM payments in the period still unacked; no unacked outbox events for the period; unallocated list reviewed. Reopen = finance head (or `role:finance` other than the locker) + reason; logged.
- BR-AC.14 1C pushes for a locked period are **accepted** (book of record) and flagged `posted_in_locked_period` with an alert to finance.
- BR-AC.15 Ledger integrity: every money mutation writes `accounting_entries` in the same transaction as the cache update; nightly `accounting:verify` recomputes caches from entries and alerts on any difference (no auto-fix).
- BR-AC.16 Aging: outstanding per schedule item (after FIFO coverage) bucketed current / 1–30 / 31–60 / 61–90 / 90+ days past due (Tashkent), per currency (never summed across currencies; optional base-currency column at latest `fx_rates`, labelled "indicative"). Unallocated advances shown as negative per customer.
- BR-AC.17 Reminders: pre-due (3 days) and overdue stages via `dunning_events` (idempotent); internal (owner, accountant digest) always; customer (billing contact, portal finance users) only when `customer_reminders_enabled` = true, in the contact/customer document language with the reminder print; Telegram for customers later.
- BR-AC.18 Payment status recompute triggers: allocation, deallocation, void, approval, 1C correction, schedule change, nightly.
- BR-AC.19 Credit check: exposure = Σ (gross − paid) of confirmed+ non-closed non-cancelled orders of the customer, converted to the limit currency at the latest `fx_rates` (indicative), + this order. Exposure > limit → hold `credit_limit`. No limit configured → skipped. Overdue rule independent (BR 6.2).

## 9. 1C exchange contract (pull-only, extends Spec 02 section 9)

Same base URL, auth, middleware, HMAC, rate limits, JSON typing (decimal strings, ISO dates with offset, lowercase GUIDs) as Spec 02 9.1. New token abilities (`IntegrationAbility`): `orders:read`, `payments:read`, `payments:write`.

### 9.1 Endpoints
| Method & path | Ability | Purpose |
|---|---|---|
| `GET /outbox/pending?feed=orders&limit=200&cursor=` | `orders:read` | Open (unacknowledged, not superseded) events of feed `orders` (order, delivery-note, agreement, invoice events), oldest first |
| `GET /outbox/pending?feed=payments…` | `payments:read` | Same for payment events |
| `GET /outbox/pending?feed=warehouse…` | `warehouse:read` | Same for goods receipts, transfers, adjustments (section 21.9) |
| `POST /outbox/ack` | read ability of each event's feed | Ack results (≤ 500 per call) |
| `POST /payments` | `payments:write` | Bank/cash receipts and refunds posted in 1C, delta, batch envelope (Spec 02 9.2); async via `inbound_batches` (endpoint `payments`) |
| `GET /batches/{batch_id}` | `batches:read` | as Spec 02 |

### 9.2 Outbox semantics
- Event types: `order.confirmed`, `order.amended`, `order.cancelled`, `order.closed`, `delivery.posted` (sales document; lines carry `serials[]`), `delivery.serials_corrected`, `agreement.signed`, `agreement.terminated`, `invoice.issued` (proforma), `invoice.registered` (tax doc), `invoice.voided`, `payment.recorded`, `payment.voided`, `payment.refund_recorded`; feed `warehouse` (new ability `warehouse:read`): `goods_receipt.posted`, `stock_transfer.posted`, `stock_adjustment.posted` (section 21.9); since 2026-10-19 also `stock_off.posted` (section 27).
- Delivery is **at-least-once**, polling as in Spec 02 10.1: every 1C run starts **without a cursor** (so unacknowledged events come back) and pages with `next_cursor` within the run; 1C must be idempotent by `event_id` and by (`aggregate.crm_id`, `aggregate_version`). Head-of-line per aggregate: an event is not returned while an earlier event of the same aggregate is unacked; other aggregates continue. `limit` 1..500 (default 200). Returned events move `pending → delivered` (`delivery_count`) and are returned again until acked.
- **Customer precondition** (Spec 02 section 6 handoff): order-feed events of a customer that is not yet `synced` (no 1C GUID) are held (`blocked_customer`) and become pending automatically when the customer outbox ack stores the GUID; the order page shows "Waiting for customer to reach 1C". Customers themselves travel only through the Spec 02 customer outbox.
- Payload = full current snapshot of the document at that version (not a diff). Order/invoice/delivery lines include `item_id` (1C GUID = `item_external_id`), `price_type_id`, **`accountant_code`, `class_code`, `package_code`**, quantity, unit price, VAT rate, amounts (delivery lines also `serials[]`). The customer block carries `crm_id` and `external_id` (GUID) only.
- `superseded`: when a newer version of the same aggregate is created before 1C fetched the older one, the older `pending` event is marked superseded and not delivered (only for `order.amended` chains).

Example `GET /outbox/pending?feed=orders&limit=1` → 200
```json
{
  "data": [{
    "sequence": 10452, "event_id": "6f1c…", "event_type": "order.confirmed", "occurred_at": "2026-10-18T10:15:00+05:00",
    "aggregate": { "type": "order", "crm_id": "a3e1…", "version": 1 },
    "payload": {
      "number": "OR-2026-00042", "date": "2026-10-18", "currency": "UZS", "prices_include_vat": false,
      "customer": { "crm_id": "0b9a…", "external_id": "e3f4…" },
      "payment_terms": { "prepayment_percent": "50.00", "deferred_days": 10 },
      "delivery": { "address": "Tashkent, …", "promised_on": "2026-11-01" },
      "lines": [{ "crm_line_id": "77aa…", "item_id": "a1b2…0001", "price_type_id": "c7d8…0002",
                  "accountant_code": "4101-07", "class_code": "08708300001000000", "package_code": "1503371",
                  "quantity": "10.000", "unit_price": "125000.00", "discount_percent": "0.00",
                  "vat_rate": "12", "net": "1250000.00", "vat": "150000.00", "total": "1400000.00" }],
      "totals": { "net": "1250000.00", "vat": "150000.00", "total": "1400000.00" }
    }
  }],
  "next_cursor": null, "has_more": false, "server_time": "2026-10-18T10:16:02+05:00"
}
```
`POST /outbox/ack`
```json
{ "acks": [
  { "event_id": "6f1c…", "result": "applied", "onec_guid": "9d0e…", "onec_number": "ЗК-000123",
    "posting_status": "posted", "updated_at": "2026-10-18T10:17:00+05:00" },
  { "event_id": "71ab…", "result": "rejected", "errors": [ { "code": "unknown_item", "message": "item a1b2… not found" } ] }
] }
```
→ 200 `{ "results": [ { "index": 0, "event_id": "6f1c…", "result": "applied" }, { "index": 1, "event_id": "71ab…", "result": "applied" } ] }` (the CRM applied the rejection). Result vocabulary as Spec 02 10.2: `applied` \| `already_applied` \| `superseded` (references stored; a newer version will be delivered) \| `rejected` (+ `errors[]`: `unknown_event_id`, `feed_not_allowed`, `validation_failed`). Re-ack with the same result is idempotent; `applied` events accept later **posting updates** (`posting_status`: `posted` \| `unposted` \| `deleted`, newer `updated_at` wins) — this is how 1C reports posting status changes of CRM-originated documents without a new scope. `rejected` → CRM document `onec_state = rejected` + alert; after a fix the CRM emits a new event (new `event_id`, same aggregate version + 1).

### 9.3 `POST /payments` (1C → CRM)
```json
{ "batch_id": "…", "mode": "delta", "sent_at": "…",
  "payments": [{
    "id": "4c5d…", "crm_id": null, "number": "ПП-001234", "direction": "incoming", "kind": "receipt",
    "customer_id": "e3f4…", "customer_crm_id": "0b9a…", "payer_name": "ALPHA TRADE MCHJ", "payer_tax_id": "123456789",
    "amount": "700000.00", "currency": "UZS", "paid_on": "2026-10-19", "method": "bank_transfer",
    "bank_account_id": "b7c8…", "reference_number": "1234", "purpose": "Oplata po zakazu OR-2026-00042",
    "allocations": [ { "order_crm_id": "a3e1…", "amount": "700000.00" } ],
    "posted": true, "is_deleted": false, "version": "AAAB…", "updated_at": "2026-10-19T11:00:00+05:00"
  }] }
```
Processing (queued, FIFO, out-of-order guard on `updated_at`/`version` as Spec 02 7.3):
1. `crm_id` present → it is a CRM-recorded payment that 1C created from the outbox: link `onec_guid`, `confirmed`; if amount/currency/date/customer differ → **1C wins** through `onec_correction` entries (reversal + new values; CRM payment row updated only by this system path, audited), allocations exceeding the new amount flagged `needs_reallocation`, accountant alerted.
2. Else `id` known (`onec_guid`) → update the same way.
3. Else exact match to a CRM `recorded`, unconfirmed payment (same customer, amount, currency, paid_on, reference_number) → auto-link as in 1. Fuzzy match (same customer and amount, paid_on ± 3 days or reference equal) → create the 1C payment with `match_state = possible_duplicate`, `duplicate_of_id`; **not counted** until the accountant resolves ("Same payment" → link and keep one; "Different" → `distinct`).
4. Else new `source=onec`, `confirmed` payment; allocations from `allocations[]` when the orders belong to the customer and fit; else auto-suggest by order number regex in `purpose` (`OR-\d{4}-\d{5}`) without auto-allocating; remains in "Unallocated payments".
5. Unknown customer → `customer_id` null, "unmatched" queue; accountant links a customer (allocation then allowed).
6. `is_deleted: true` / unposted → payment `voided` (`deleted_in_1c`) or flagged unposted (still counted? no — unposted 1C payments do not count; status shown), allocations reversed, accountant alerted.
7. Locked period → BR-AC.14.

### 9.4 Errors and limits
As Spec 02 9.7; `onec-api` limiter per token 60/min shared; outbox reads ≤ 500 events per call; ack ≤ 500; body ≤ 10 MB; `payments` batch ≤ 1 000 records. Staleness: alert when no `GET /outbox/pending` call for 30 min while events are pending, or oldest unacked event > 60 min.

## 10. UI / UX

Internal Livewire pages (`layouts.app`), portal pages (`layouts.portal`), all strings from `lang/{uz,ru,en}/{orders,agreements,accounting,notifications}.php`; theme tokens (red primary/danger, silver neutral, white surfaces); statuses always icon + text.

### 10.1 Navigation (`config/navigation.php`)
- Sales section: replace `soon` entries:
  `['label' => 'nav.orders', 'icon' => 'orders', 'route' => 'orders.index', 'active' => 'orders.*', 'can' => ['viewAny', Order::class]]`,
  `['label' => 'nav.agreements', 'icon' => 'agreements', 'route' => 'agreements.index', 'active' => 'agreements.*', 'can' => ['viewAny', Agreement::class]]`.
- New section `nav.accounting_section`:
  `['key' => 'accounting', 'label' => 'nav.accounting', 'icon' => 'accounting', 'can' => ['viewAny', Payment::class], 'children' => [ ['label' => 'nav.invoices', 'route' => 'invoices.index', 'active' => 'invoices.*'], ['label' => 'nav.payments', 'route' => 'payments.index', 'active' => 'payments.*'], ['label' => 'nav.receivables', 'route' => 'receivables.index'], ['label' => 'nav.accounting_periods', 'route' => 'accounting.periods'] ]]` (children inherit `can`; sales reach invoices/payments of their orders from the order page, not this menu).
- Settings children: `nav.settings_company` → route `settings.company`; add `['label' => 'nav.settings_accounting', 'route' => 'settings.accounting']`, `['label' => 'nav.settings_agreement_templates', 'route' => 'settings.agreement-templates', 'active' => 'settings.agreement-templates.*']`; Integrations page gains an "Outbox (CRM → 1C)" tab.
- `portal_sections`: `nav.portal_orders`, `nav.portal_agreements`, `nav.portal_invoices`, `nav.portal_warranty` (serial lookup) (Spec 03 list).
- New section `nav.warehouse_section` (section 21.10): Goods receipts, Delivery notes, Transfers, Adjustments, Serial numbers, Stock reconciliation.

### 10.2 Screens
| Screen | Content |
|---|---|
| Orders index | number, customer, status, payment status badge, hold icon (+reason tooltip), agreement status, owner, gross, paid, promised date, 1C state; filters status/payment status/hold/owner/customer/agreement missing/overdue |
| Order show | header (status, payment status, holds with override, 1C number/state, actions per policy); tabs **Lines** (qty ordered/delivered/invoiced/cancelled, reservation, backorder, option label "3B", columns `accountant_code`, `class_code`, `package_code` toggleable, missing-code markers), **Payments** (schedule with coverage FIFO, allocations, overdue), **Invoices**, **Deliveries & logistics** (deliveries, transport tender/shipment status, ETA), **Agreement** (status, dispatch timeline, viewed/signed), **Amendments**, **Activity** (status_transitions + activity_log) |
| Confirm dialog | stock per line (available, stale warning), shortages acknowledge, credit/overdue result, schedule preview |
| Agreements index | number, kind, customer, order, status, sent/viewed/days waiting, ends_on; queue filters (to send, awaiting signature, scans to verify, expiring) |
| Agreement editor | template + version + locale(s), variables panel (read-only resolved values with source, editable only free-text terms permitted by template), optional clauses, non-standard flags, validator list, preview per language (rendered through `DocumentRenderer::render`), actions Submit/Approve/Send/Cancel |
| Send dialog | recipients (billing/signatory contacts, portal users), channels (portal, e-mail link, print), language, message preview |
| Signed confirmation | upload/verify scan (preview), signatories, signed_on |
| Invoice builder | from order: choose type; lines picker with **search/filter by `accountant_code`, SKU, name, `class_code`**; qty ≤ remaining; live code-validation panel (BR-AC.12); our bank account; requested amount; issue/register |
| Payments index | number, date, customer/payer, amount + currency, method, status, source, allocated/unallocated, 1C state, match state; filters incl. pending approval, unallocated, possible duplicate, pending 1C |
| Record payment form | fields of BR-AC.5; proof upload; duplicate warning; allocation step (orders of the customer with outstanding schedule, suggested amounts; FX rate field when currencies differ) |
| Payment show | allocations (with reversals), ledger entries, 1C info, void/refund request, approval panel for finance |
| Receivables | aging per customer/currency, drill-down to schedule items; group by owner, customer, **accountant_code** (via order lines value share); exports CSV/XLSX with columns incl. `accountant_code`, `class_code` |
| Accounting periods | months with status, lock checklist, lock/reopen |
| Customer show | new tabs Orders, Agreements, Finance (credit limit, exposure, advance balance, overdue, bank accounts, billing contacts) |
| Dashboard | widgets of 4.6 per role |

Empty/error states: no template for kind/locale → "Ask finance to publish a template in <language>"; customer without bank account/signatory → validator lists missing data with link to customer; outbox stale → banner on payments/invoices "1C has not fetched updates for 47 min".

## 11. Notifications (keys `lang/*/notifications.php` → `accounting.*`, `orders.*`, `agreements.*`)
Events of 4.5 plus to sales owner: `order.created`, `order.hold_set`, `order.payment_received`, `order.overdue`, `agreement.signed`; to customer (opt-in by setting): `agreement.sent`, `agreement.reminder`, `invoice.issued`, `payment.reminder_pre_due`, `payment.overdue`. Channels/quiet hours per Spec 03 BR-A.6; Telegram customer reminders later.

## 12. Printing (all via `DocumentRenderer::print()`, language `DocumentLanguage::resolve($request->query('lang'), $customer)` with document_locale as preference, `<x-print-language-picker>`, labels only from `documents.*`, values via `$doc`)

| Document | Route (internal / portal) | View |
|---|---|---|
| Order confirmation | `/orders/{order}/print`, `/portal/orders/{uuid}/print` | `documents.order` |
| Agreement / addendum | `/agreements/{agreement}/print`, `/portal/agreements/{uuid}/print`, `/a/{token}/print` (after OTP) | `documents.agreement` (sent versions from `agreement_versions` snapshot; bilingual = two columns) |
| Proforma invoice | `/invoices/{invoice}/print`, portal | `documents.invoice` |
| Tax document copy (registered act/waybill/VAT invoice data sheet, marked "Copy for reference; the legal document is the electronic ESF") | `/invoices/{invoice}/print` | `documents.invoice` (type switch) |
| Payment schedule | `/orders/{order}/schedule/print` | `documents.payment_schedule` |
| Payment reminder letter | `/receivables/{customer}/reminder/print` | `documents.payment_reminder` |
| Statement of account (informational, Q-22) | `/receivables/{customer}/statement/print?from&to` | `documents.statement` |
Invoice/act/waybill prints: line columns `class_code` and `package_code` shown by default, `accountant_code` hidden by default, each configurable in `accounting.invoice_print_columns`.

Keys to add in `lang/{uz,ru,en}/documents.php` (suggested; translators finalise; parity test enforces all three):

| Key | en | ru | uz |
|---|---|---|---|
| `order.confirmation_title` | Order confirmation | Подтверждение заказа | Buyurtma tasdig‘i |
| `order.chosen_option` | Option :label | Вариант :label | :label-variant |
| `order.payment_schedule` | Payment schedule | График платежей | To‘lov jadvali |
| `order.schedule.prepayment` | Prepayment :percent | Предоплата :percent | Oldindan to‘lov :percent |
| `order.schedule.on_delivery` | Payment after delivery | Оплата после поставки | Yetkazib berilgandan keyin to‘lov |
| `order.schedule.milestone` | Milestone | Этап | Bosqich |
| `order.due_on` | Due date | Срок оплаты | To‘lov muddati |
| `order.paid` / `order.outstanding` | Paid / Outstanding | Оплачено / Остаток к оплате | To‘langan / To‘lanishi kerak |
| `agreement.addendum_title` | Addendum No. :number to agreement No. :agreement | Дополнительное соглашение № :number к договору № :agreement | :agreement-sonli shartnomaga :number-sonli qo‘shimcha kelishuv |
| `agreement.renewal_of` | Renewal of agreement No. :number | Продление договора № :number | :number-sonli shartnomani uzaytirish |
| `agreement.details_of_parties` | Details of the parties | Реквизиты сторон | Tomonlarning rekvizitlari |
| `agreement.signatures` | Signatures of the parties | Подписи сторон | Tomonlarning imzolari |
| `agreement.acting_on_basis` | acting on the basis of :basis | действующий на основании :basis | :basis asosida faoliyat yurituvchi |
| `agreement.vat_code` | VAT registration code | Регистрационный код плательщика НДС | QQS to‘lovchisining ro‘yxatdan o‘tish kodi |
| `agreement.oked` | OKED | ОКЭД | IFUT |
| `agreement.fingerprint` | Document fingerprint: :hash | Отпечаток документа: :hash | Hujjat izi: :hash |
| `invoice.types.proforma` | Invoice for payment | Счёт на оплату | To‘lov uchun hisob-varaq |
| `invoice.types.tax_invoice` | VAT invoice | Счёт-фактура | Hisob-faktura |
| `invoice.types.act` | Act of completed works | Акт выполненных работ | Bajarilgan ishlar dalolatnomasi |
| `invoice.types.waybill` | Waybill | Товарная накладная | Yuk xati |
| `invoice.not_tax_invoice` | This is not a VAT invoice. | Не является счётом-фактурой. | Hisob-faktura hisoblanmaydi. |
| `invoice.reference_copy` | Copy for reference. The legal document is the electronic invoice No. :esf. | Справочная копия. Юридически значимый документ — электронный документ № :esf. | Ma’lumot uchun nusxa. Yuridik ahamiyatga ega hujjat — :esf-sonli elektron hujjat. |
| `invoice.payment_purpose` | Payment purpose | Назначение платежа | To‘lov maqsadi |
| `invoice.payment_purpose_text` | Payment under order No. :order of :date | Оплата по заказу № :order от :date | :date dagi :order-sonli buyurtma bo‘yicha to‘lov |
| `invoice.amount_due` | Amount due | Сумма к оплате | To‘lanadigan summa |
| `invoice.pay_by` | Please pay by :date | Просим оплатить до :date | :date gacha to‘lashingizni so‘raymiz |
| `invoice.director` / `invoice.chief_accountant` | Director / Chief accountant | Директор / Главный бухгалтер | Direktor / Bosh hisobchi |
| `invoice.class_code` | Product classifier code (IKPU) | Код ИКПУ | MXIK kodi |
| `invoice.package_code` | Package code | Код упаковки | Qadoq kodi |
| `invoice.accountant_code` | Accounting code | Бухгалтерский код | Buxgalteriya kodi |
| `invoice.esf_id` | Electronic invoice ID | Идентификатор ЭСФ | EHF identifikatori |
| `payment.methods.{bank_transfer,cash,card}` | Bank transfer / Cash / Card | Банковский перевод / Наличные / Карта | Bank o‘tkazmasi / Naqd pul / Karta |
| `reminder.title` | Payment reminder | Напоминание об оплате | To‘lov haqida eslatma |
| `reminder.intro` | According to our records, the following amounts are due or overdue: | По нашим данным, следующие суммы подлежат оплате или просрочены: | Bizning ma’lumotlarimizga ko‘ra, quyidagi summalar to‘lanishi lozim yoki muddati o‘tgan: |
| `reminder.days_overdue` | :days days overdue | просрочено :days дн. | :days kun kechiktirilgan |
| `reminder.ignore_if_paid` | If you have already paid, please disregard this reminder. | Если вы уже оплатили, пожалуйста, не учитывайте это напоминание. | Agar to‘lov amalga oshirilgan bo‘lsa, ushbu eslatmani e’tiborsiz qoldiring. |
| `statement.title` | Statement of account | Выписка по взаиморасчётам | O‘zaro hisob-kitoblar ko‘chirmasi |
| `statement.period` | Period: :from – :to | Период: :from – :to | Davr: :from – :to |
| `statement.opening_balance` / `statement.closing_balance` | Opening balance / Closing balance | Сальдо на начало / Сальдо на конец | Boshlang‘ich qoldiq / Yakuniy qoldiq |
| `statement.disclaimer` | Informational statement based on CRM data. The official reconciliation statement is issued from the accounting system. | Информационная выписка по данным CRM. Официальный акт сверки формируется в учётной системе. | CRM ma’lumotlari asosidagi axborot ko‘chirmasi. Rasmiy solishtirma dalolatnoma buxgalteriya tizimida tuziladi. |

UI keys: new `lang/{uz,ru,en}/orders.php`, `agreements.php`, `accounting.php` (statuses, payment statuses, hold reasons, amendment types, invoice types/statuses, payment statuses/methods/sources, entry types, validation messages incl. code warnings, flash messages), `nav.php` (`accounting_section`, `accounting`, `invoices`, `payments`, `receivables`, `accounting_periods`, `settings_accounting`, `settings_agreement_templates`, `portal_*`), `users.php` (`roles.accountant`), `integration.php` (abilities `orders_read`, `payments_read`, `payments_write`, outbox labels). Every enum has `label()` via keys.

## 13. Security
- **Payments immutability**: no update/delete routes or Livewire actions for money/identity fields; model guards throw; allocations/entries append-only; voids/refunds require a second user; optional DB triggers; nightly ledger verification; all money mutations in DB transactions with `lockForUpdate` on payment and order rows.
- **IDOR / tenant isolation**: internal records authorized by Policies + `PermissionMap` scopes (sales see own orders only — direct ids of others → 403); portal routes use `uuid` + company membership, foreign → 404 (Spec 03 DEC-13) across orders, agreements, invoices, schedules, prints, attachments, Livewire actions (data-provider test). Allocation Action re-checks `payment.customer_id == order.customer_id` and `invoice.order_id == order.id` server-side; Livewire never trusts posted ids/amounts (recomputed).
- **Sensitive fields**: bank accounts and payer accounts visible to accountant/finance/manager only; masked elsewhere; excluded from `activity_log` properties, notifications, `integration_logs`, queued job payloads (ids only).
- **File uploads** (signed scans, proofs, tax-doc scans): Spec 03 attachments: private disk, random names, ≤ 10 MB, allowlist **pdf, jpg, png** only (no office/zip/svg/html), content sniffing (mime from bytes must match), sha256 stored, optional ClamAV, served by a policy-checked controller with `Content-Disposition: attachment` (inline only for pdf/images with `X-Content-Type-Options: nosniff`, CSP sandbox); upload rate limit 20/min per user; portal uploads limited to agreements of own company in `sent_for_signature`.
- **Secure agreement links**: token 256-bit, hashed, expiring, revocable, OTP required to view, `Referrer-Policy: no-referrer`, `Cache-Control: no-store`, `noindex`; per-IP throttle on token lookups (Spec 05 10.4 pattern).
- **1C endpoints**: Spec 02 BR-I.18/I.18a (HTTPS, hashed expiring tokens, IP allowlist, HMAC from public IPs), per-token rate limit 60/min, separate abilities per feed (a stock token cannot read payments), outbox payloads contain no internal notes, owner names or bank accounts of customers beyond what the document needs; `GET /outbox` limited to 500; ack payload validated (GUID format, known event, feed ability).
- **Templates**: placeholder renderer escapes values; template HTML sanitized on publish (allowlist tags; no script/style/iframe/on* attributes); only `agreement_templates.manage` can publish; versions immutable.
- **2FA** required for accountant and finance; sensitive actions (approve payment, void, lock period) require a session confirmed within 15 min (re-OTP).
- **Exports**: CSV/XLSX escape cells starting with `= + - @` (formula injection); exports logged.
- **Audit**: `status_transitions`, `activity_log`, `accounting_entries`, dispatch and download logs; attachment downloads of signed agreements and proofs logged.

## 14. Acceptance criteria

Orders
- AC-1 Given an accepted offer with fixed line 1 and selected option 3C, when `CreateOrderFromOffer` runs twice, then exactly one order exists with lines 1 and 3C only, totals equal the offer selected totals to the minor unit, and each line's `accountant_code`, `class_code`, `package_code` equal the offer line snapshot even after the item's codes are edited later.
- AC-2 Given a `new` order and a customer "Deleted in 1C", confirm is refused with the translated message.
- AC-3 Given available 4 and line qty 10, confirm shows the shortage; without acknowledgement it is refused; with it, a reservation of 4 is created and the line flagged backorder.
- AC-4 Given credit limit 10 000 000.00 UZS and exposure 9 000 000.00, confirming a 2 000 000.00 order sets hold `credit_limit` and refuses; a finance user overrides with a reason → confirmed, override shown and logged; a sales user cannot override.
- AC-5 Given prepayment 50% not covered, `StartFulfilment` is refused (hold `prepayment_missing`); after allocating 50% it succeeds.
- AC-6 Given `orders.block_fulfilment_without_agreement` and no signed agreement, start is refused; after `signed` it succeeds.
- AC-7 Delivering 6 of 10 → `partially_delivered`; delivering 4 more → `delivered`; delivering 1 more is refused.
- AC-8 Registering a waybill for 10 of 10 delivered → `invoiced`; with payment status `paid` and agreement active → auto-close at night → `closed`.
- AC-9 Cancelling a confirmed order with an allocated payment is refused; after deallocation it succeeds, reservations released, unsigned agreement cancelled, outbox `order.cancelled` written.
- AC-10 An amendment reducing qty below delivered qty is refused; a price decrease beyond the requester's limit needs approval; applying it increments `revision`, recomputes totals, regenerates unpaid schedule items, creates an addendum draft when a signed agreement exists and sets `addendum_pending`.
- AC-11 Once the `order.confirmed` event is acked `applied`, its reservations become `mirrored_in_1c` and are no longer subtracted from availability.

Agreements
- AC-12 Generating from a template whose ru version lacks a variable lists the unknown/missing variables and refuses; with all present, `variables` snapshot is stored and preview renders in uz, ru and en with locale formatting.
- AC-13 Changing the payment terms clause marks the agreement non-standard; sending from draft is refused until a finance/manager user (not the preparer) approves in review.
- AC-14 Sending creates `agreement_versions` rows with sha256 per locale and dispatch rows per channel; the e-mail link opens only after OTP to that contact; viewing sets `viewed_at`; a forwarded link without OTP shows nothing.
- AC-15 A portal approver uploads a scan → `customer_signed_uploaded_at` set, status unchanged, accountant notified; accountant confirms → `signed`; a sales user cannot confirm.
- AC-16 Editing a sent agreement is impossible; cancel + re-issue creates version 2; prints of version 1 still render its stored snapshot.
- AC-17 Given `ends_on` in 30 days, the 30-day reminder is sent once; at expiry without renewal → `expired`; with a signed renewal → old `renewed`, new `active`.
- AC-18 Termination with open orders is refused until each is resolved.

Invoices and codes
- AC-19 Issuing a proforma with defaults prints PF-number, our bank account in the order currency, requested amount = prepayment item, "This is not a VAT invoice", in the chosen language.
- AC-20 Given `invoice_code_validation.class_code = warn` and a goods line with empty `class_code`, issue requires ticking "Issue with missing codes" and stores `code_warnings`; given `block`, issue is refused listing the line; given a service line (outside `esf_goods_scope`), no class/package warning; empty `accountant_code` follows its own setting.
- AC-21 Registering a tax document for more than `delivered − invoiced` qty is refused; registered lines copy codes from the order line; print shows `class_code`/`package_code` columns and hides `accountant_code` by default; toggling the setting changes the columns.
- AC-22 The invoice builder filters lines by `accountant_code`; receivables export contains `accountant_code` and `class_code` columns.

Payments
- AC-23 A sales user calling the record-payment action directly gets 403 and nothing is written.
- AC-24 Recording 50 000 000.00 UZS (threshold 100 000 000.00) → `recorded`, counted, badge "pending 1C", ledger entry written, outbox `payment.recorded`; recording 150 000 000.00 → `pending_approval`, not counted; the recorder cannot approve; another finance user approves → `recorded`.
- AC-25 A payment of 1 500 000.00 allocated 1 000 000.00 to order A and 300 000.00 to order B of the same customer leaves 200 000.00 unallocated; allocating to an order of another customer is refused (also via tampered Livewire payload).
- AC-26 A USD payment of 100.00 allocated to a UZS order with rate 12 850.50 stores `order_amount_minor` 128 505 000 (half-up) and the rate source.
- AC-27 Updating or deleting a payment amount through the model throws; void requested by the accountant and approved by finance → `voided`, reversal entries for payment and allocations, order payment status recomputed, outbox `payment.voided`.
- AC-28 A refund of 200 000.00 against the unallocated remainder requires approval and creates an outgoing payment linked by `refund_of_payment_id`; refund above the unallocated balance is refused.
- AC-29 Given period 2026-10 locked, recording/allocating/voiding with a date in October is refused; a correction dated in November is allowed; a 1C payment for October is accepted and flagged.
- AC-30 Given a schedule item due yesterday with outstanding amount, nightly recompute sets `overdue`, the owner is notified once, and a customer reminder is sent only when `customer_reminders_enabled` (dedupe per stage).
- AC-31 Aging buckets per currency match a fixture (current, 1–30, 31–60, 61–90, 90+); currencies are never summed.

1C exchange
- AC-32 A token with `orders:read` reading `feed=payments` gets 403; with `payments:read` it gets payment events only.
- AC-33 Two events of the same order: the second is not returned until the first is acked; events of other orders are returned meanwhile; repeated GET returns unacked events again.
- AC-34 Ack `applied` stores `onec_guid`/`onec_number` on the order; repeating the ack is idempotent; a later posting update with newer `updated_at` changes `onec_state`, older is ignored; ack `rejected` alerts accountants and the order shows the error.
- AC-35 A 1C payment carrying `crm_id` of a recorded CRM payment confirms it (no second payment); with a different amount, correction entries are written and over-allocation is flagged.
- AC-36 A 1C payment without `crm_id` exactly matching a recorded CRM payment links automatically; a fuzzy match becomes `possible_duplicate`, not counted, until resolved.
- AC-37 A 1C payment for an unknown customer appears in the unmatched queue; allocation is refused until a customer is linked.
- AC-38 Outbox order payloads contain `item_id`, `accountant_code`, `class_code`, `package_code` per line and no internal notes.

Roles and security
- AC-39 Matrix 4.2 as a data provider (role × department role × action) passes for orders, agreements, invoices, payments, receivables, settings; admin gets no business action.
- AC-40 A user with `role:accountant` sees an offer linked to an order from the order page and gets 403 on an unlinked offer and on the offers index.
- AC-41 Portal user of company B requesting company A's order/agreement/invoice/print/attachment by uuid gets 404 (data provider incl. Livewire actions).
- AC-42 Uploading an `.html`, `.svg` or a renamed executable as signed scan is rejected; a valid PDF is stored privately and downloadable only by authorized users.
- AC-43 `TranslationParityTest` passes with all new keys in uz/ru/en; every document prints in uz/ru/en without raw keys; money/dates via `$doc`.

## 15. Tests plan (PHPUnit, `RefreshDatabase`, `Http::preventStrayRequests()` — and an architecture test that no code under `app/` opens HTTP connections to 1C hosts)
Unit: `OrderStatusTest`, `AgreementStatusTest`, `PaymentStatusTest` (transition tables valid/invalid); `PaymentScheduleGeneratorTest` (prepayment/on-delivery/milestones, rounding remainder, Σ = gross); `ScheduleCoverageTest` (FIFO, overdue); `AllocationFxTest`; `AgingCalculatorTest`; `CreditExposureTest`; `InvoiceCodeValidatorTest` (off/warn/block × goods/services × missing codes); `TemplateRendererTest` (escaping, unknown placeholders, clause blocks, sanitizer); `LedgerVerifierTest`.
Feature: `Orders/CreateOrderFromOfferTest` (AC-1, idempotency, concurrent double call), `Orders/ConfirmOrderTest` (AC-2..4, 11), `Orders/FulfilmentTest` (AC-5..8), `Orders/CancelOrderTest` (AC-9), `Orders/AmendmentTest` (AC-10); `Agreements/GenerateTest`, `ReviewSendTest`, `SignedConfirmationTest`, `LifecycleSchedulerTest` (`travelTo`), `TerminationTest`, `SecureLinkTest`; `Accounting/ProformaTest`, `TaxDocumentRegistrationTest`, `RecordPaymentTest`, `ApprovalTest`, `AllocationTest`, `VoidRefundTest`, `PeriodLockTest`, `RemindersTest` (`Notification::fake`, dedupe), `ReceivablesReportTest`, `ExportTest` (formula escaping, code columns); `Api/OneC/OutboxReadTest`, `OutboxAckTest`, `InboundPaymentsTest`, `PaymentMatchingTest`; `Policies/*PolicyTest` (matrix data providers, SoD self-approval); `Portal/FinanceIsolationTest`; `Documents/{Order,Agreement,Invoice,Reminder,Statement}PrintTest` (uz/ru/en, columns setting); `Attachments/UploadRulesTest`; `TranslationParityTest`.
Definition of done: all green, Pint clean, every Livewire action authorizes, every API route has auth + ability middleware, no float in money code (static check), no update/delete path for payments (test enumerates routes/actions).

## 16. Metrics
- Order cycle time (confirmed → delivered → invoiced → closed); % orders blocked by holds and average hold time.
- Agreements: time confirmed → sent (target < 1 business day), sent → signed (median), % orders shipped without signed agreement (target 0 unless overridden), renewal rate, expiries missed (target 0).
- Cash: DSO, % receivables overdue (by bucket), average days late, prepayment collection time, unallocated payments count/age (target < 2 days), duplicates detected/resolved.
- Data quality: % invoice lines issued with missing `class_code`/`package_code`/`accountant_code` (target → 0 before switching to block mode), ESF rejections (once integrated).
- 1C reconciliation: % CRM payments confirmed by 1C within 1 day, outbox lag p95, rejected events per week, ledger verification differences (target 0).

## 17. Build order (each increment shippable, tested, reversible)
| Step | Increment | Contents | Depends on |
|---|---|---|---|
| 06-0 | Roles & rails | `UserRole::Accountant` + label, department display name, permission abilities (4.3), 2FA default, settings skeleton (Accounting, Agreements), company profile + bank accounts + signatories, customer finance columns, `fx_rates`, `accounting_periods` | Spec 03 departments, Spec 04 `number_sequences`/`status_transitions` |
| 06-1 | Orders core | orders/lines, `CreateOrderFromOffer` consuming `OfferToOrderPayload`, statuses, policies, index/show (lines tab with codes), order print, nav entry | Spec 05 05-5 |
| 06-2 | Confirmation | stock availability + reservations, credit/overdue holds, schedule generation, confirm dialog | 06-1, Spec 02 I-4 |
| 06-3 | Fulfilment | deliveries, start/hold rules, transport request link (`tender_sources`), amendments | 06-2, Spec 03 T-6 (link optional) |
| 06-4 | Agreements core | templates + versions (admin/finance UI), renderer + sanitizer, generation, validator, review, print uz/ru/en, nav entry | 06-1, Spec 03 attachments |
| 06-5 | Agreement sending & signing | portal + e-mail secure link (OTP), dispatch tracking, scan upload/verify, activation, reminders, accountant queues | 06-4, Spec 03 portal/OTP/notifications |
| 06-6 | Invoices | proforma issue/print/send, tax-document registration, invoiced qty, code validation settings, invoice builder filters | 06-3 |
| 06-7 | Payments | record, approval, allocation, FX, void, refund, ledger, payment status, unallocated queue, sales visibility | 06-2 (06-6 for invoice allocation) |
| 06-8 | Receivables | aging, overdue scheduler, internal reminders, customer reminders (flag off), exports, dashboards | 06-7 |
| 06-9 | Period lock | lock/reopen, checklist, locked-period guards, ledger verification job | 06-7 |
| 06-10 | 1C outbox | `onec_outbox` writers (transactional), `GET /outbox/pending`, `POST /outbox/ack`, customer-precondition hold, abilities, monitor tab, staleness | 06-1, 06-7, Spec 02 I-1 |
| 06-11 | 1C payments inbound | `POST /payments`, matching, corrections, unmatched queue | 06-10 |
| 06-12 | Addenda, renewals, termination, framework link | | 06-5 |
| 06-S1 | Stock rails | `UserRole::StockManager`, `warehouse` department, stock abilities, `item_serials`, `serial_events`, `document_line_serials`, `SerialNormalizer`, serial settings | 06-0, Spec 02 I-4 |
| 06-S2 | Goods receipts | receipts (all sources except returns), serial entry one-by-one + paste + CSV import with preview, posting, discrepancies, receipt print | 06-S1, Spec 03 T-5 (PO/shipment links optional) |
| 06-S3 | Serialized sales | delivery-note serial registration (06-3 extended), atomic claim, posting block, prints with serials, warranty card, serial search | 06-S2, 06-3 |
| 06-S4 | Movements | transfers, adjustments (write-off, found, serial correction), customer returns, opening-balance registration | 06-S3 |
| 06-S5 | Reconciliation & outbox | serial vs 1C quantity report/alerts, `warehouse` outbox feed, `delivery.posted` serial arrays, portal warranty lookup | 06-S4, 06-10 |
| 06-S6 | XLSX import | openspout (after dependency approval, DEC-20) | 06-S2 |
| Later | e-invoicing/ESF provider, E-IMZO, 1C documents push (`documents:write`), shipments from 1C, CBU rate API, Telegram customer reminders, credit notes, statement from 1C | | answers to Q-2, Q-15, Q-12 |

Critical path to first value: 06-0 → 06-1 → 06-2 → 06-4 → 06-5 (orders + agreements sent by the accountant), then 06-7 (payments) → 06-8.

## 18. Risks
| # | Risk | Mitigation |
|---|---|---|
| R-1 | Duplicate payments (CRM-recorded + 1C bank import) | `crm_id` round-trip via outbox, exact auto-link, fuzzy → possible_duplicate not counted, Q-11 one-direction recommendation |
| R-2 | Shipping on an unconfirmed (later rejected) CRM payment | Threshold approval, proof attachments, `onec_rejected` alerts, setting to count only confirmed (DEC-13) |
| R-3 | 1C team does not implement outbox pulling soon | CRM works standalone (states `not_sent`); outbox accumulates; manual 1C numbers can be entered; monitor shows backlog |
| R-4 | Legal/tax rules (ESF, codes, currency) assumed wrongly | Registration-only design, settings for validation modes, open questions to accountants before block mode |
| R-5 | Agreement template errors produce invalid contracts | Versioned templates, lint on publish, review step, preview per language, finance publishes |
| R-6 | Ledger and cache drift | Single Action layer, same-transaction writes, nightly verification |
| R-7 | Scope size | Thin increments; framework/e-invoicing/e-sign deferred |
| R-8 | Orders of new customers wait until 1C has collected the customer (customer outbox) | Hold is visible on the order ("waiting for customer to reach 1C"); customer poll staleness alert (Spec 02 BR-I.23) |
| R-9 | Period locked before 1C confirmations | Lock checklist; 1C pushes into locked periods flagged, not lost |
| R-10 | Serialized items with existing stock but no registered serials block sales on go-live | Opening-balance registration (import) before switching items to serialized in production; "Serials missing" queue; warehouse-head override with reason (disabled by default) |
| R-11 | Two users claim the same serial | Atomic claim (row lock + conditional update + unique active key), tested concurrently |
| R-12 | Excel corrupts serials (leading zeros, scientific notation) | Serial column as text in templates; numeric-cell detection and errors in preview |
| R-13 | CRM serial counts drift from 1C quantities | Reconciliation report with lag-aware expected difference, alerts, adjustments with approval |

## 19. Open questions for the user (default built if unanswered)
| # | Question | Default |
|---|---|---|
| Q-1 | Accountant vs finance: keep both (accountant = operator, finance = controller/approver; finance department renamed "Accounting & Finance")? | Yes, as DEC-2 |
| Q-2 | E-invoicing provider and ESF: Didox, Faktura.uz, soliq.uz, or the 1C built-in exchange? Will the CRM ever send ESF itself? | Not assumed. v1: legal documents issued in 1C/operator; CRM registers number, date, ESF ID/status and scan |
| Q-3 | Which system issues VAT invoice, act, waybill (and e-TTN)? | 1C |
| Q-4 | VAT invoice numbering | Owned by 1C/ESF; CRM proforma numbering `PF-YYYY-NNNNN` separate |
| Q-5 | Fiscal receipts for cash/card (online cash registers) | Not issued by the CRM; receipt number stored as reference |
| Q-6 | Currencies of contracts and payments (residents in UZS only? USD contracts paid in UZS at CBU rate?) | Any of UZS/USD/EUR/RUB; FX rate = CBU rate of payment date entered manually |
| Q-7 | Who signs agreements on our side; power-of-attorney handling? | Director (default signatory); other signatories with POA number/date and validity |
| Q-8 | Payment approval threshold | UZS 100 000 000.00 / USD 10 000.00 / EUR 10 000.00 / RUB 1 000 000.00 per payment |
| Q-9 | Advance payments without an order | Accepted as customer advance (unallocated), allocated later or refunded |
| Q-10 | Count payments before 1C confirmation? | Yes, flagged "pending 1C"; setting can switch to confirmed-only |
| Q-11 | Who enters bank payments: 1C (bank-client import) or the CRM accountant? | Bank transfers come from 1C; CRM accountant records cash/card and urgent bank payments; matching handles overlap |
| Q-12 | Deliveries/realizations from 1C? | v1 recorded in CRM (logistics/sales); 1C push (`shipments:write`/`documents:write`) later |
| Q-13 | Agreement required for every order? Framework agreements needed now? | Required for every order (manager may waive with reason); framework link in 06-12 |
| Q-14 | Who owns agreement templates and legal review? | Finance publishes, manager approves content; no legal department in the org chart |
| Q-15 | E-signature (E-IMZO / Didox contracts) | Roadmap; v1 signed scan upload verified by accountant |
| Q-16 | Credit limits: who sets, default? | Finance; no limit = no credit check; overdue > 30 days holds |
| Q-17 | Month-end lock timing | Finance locks by the 5th of the next month; reopen by finance head with reason |
| Q-18 | Customer bank details and signatories: CRM or 1C master? | CRM-local now; 1C push later |
| Q-19 | **`class_code` (IKPU/MXIK) and `package_code`**: exact formats/lengths, which items/services are exempt or out of ESF scope, whether services need them | Not assumed: format checks are warnings; scope = goods (`type = product`); validation mode `warn`; confirm with your accountants before switching to `block` |
| Q-20 | **`accountant_code`**: should an invoice be blocked while a line has an empty code? And when 1C sends a different value for a code edited in the CRM, who wins? | Warning, not blocking (setting `invoice_code_validation.accountant_code = warn`); 1C value wins unless the field was edited locally after the last 1C change (conflict shown) |
| Q-21 | Netting / barter / offsets as payment method | Not in v1 |
| Q-22 | Reconciliation statement (акт сверки) source | Official one from 1C; CRM prints an informational statement of account |
| Q-23 | Server-side PDF engine for e-mail attachments | Same decision as Spec 05 05-4; until then e-mail carries the secure link |
| Q-24 | Polling cadence for the order/payment/warehouse outboxes and the payments push | Outbox poll every 5 min (same job as the customer outbox, Spec 02 D-I.2); payments push every 15 min |
| Q-25 | Customer-facing reminders (overdue e-mails) enabled from day one? | Off until you approve the texts; internal reminders on |
| Q-26 | Late-payment penalties (пеня) shown in CRM? | No; 1C/legal |

Serial-number and stock questions are Q-S1..Q-S14 in section 21.15.

## 20. Changes to other specs and skills
- Spec 01: A8 numbering note (Orders, Agreements and Accounting = Spec 06; 07 unused).
- Spec 02 (rev. 3 already removed every CRM → 1C call): Feeds line, section 5.1 rows (orders/agreements/invoices/payments/stock documents; serial numbers CRM-only), non-goals pointer, abilities table and roadmap rows now point to Spec 06 section 9; new abilities `orders:read`, `payments:read`, `payments:write`, `warehouse:read`.
- Spec 05: Feeds/non-goals/handoff references "Spec 07" → Spec 06 Part B; `OfferToOrderPayload` named as the DTO consumed by `CreateOrderFromOffer`; offer line snapshot includes `accountant_code`, `class_code`, `package_code`.
- Spec 03: `security.2fa_required_roles` default gains `accountant`; finance department display name; new seeded department `warehouse`; `UserRole::StockManager`; portal sections orders/agreements/invoices/warranty; goods receipts reference POs (T-5) and shipments.
- Spec 02: `items.is_serialized` is 1C-owned (flag only); 1C never sends serials; new abilities `warehouse:read`.
- Skills: new `.claude/skills/accounting-workflow/SKILL.md`; `order-management` and `agreement-contracts` point to it; `.claude/agents/pm.md` lists it and the accountant role.

---

## 21. Stock module: STOCK MANAGER, goods receipts and serial numbers

User requirements (2026-10-18): "While selling the user should enter serial numbers according to qty, and the system should check the serial is unique and that the serial belongs to this item." "In the sales document, items should be registered via serial numbers." "1C does not keep serials; it is for the CRM. When accepting stock, the stock manager could and should enter serial numbers into the system." "Serial numbers can be entered one by one or imported from Excel/CSV files."

### 21.1 Goals and decisions (see DEC-16..DEC-20)
- The registry `item_serials` is **CRM-master**. 1C never sends or stores serials; it keeps sending stock **quantities** (Spec 02 `item_stocks`), which the CRM reconciles against serial counts (21.11).
- Serials are **created only by stock documents** posted by the stock manager: goods receipt (incl. opening balance and customer return) and stock adjustment `found`. They are **consumed only by the sales document** (delivery note) and moved by transfers / write-offs.
- **Registry mode is the only mode**: a sale accepts a serial only if it exists for that item, is `available` and sits in the delivery note's warehouse. Exception: per-serial **unregistered-serial override** (setting `serials.allow_unregistered_override` = false by default; warehouse head only, reason required, serial created with `source = manual_override` and immediately sold, flagged in reports).
- Serials are **not** entered at offer or order stage (alternatives included): they are unknown until goods are picked.

### 21.2 STOCK MANAGER role
- `UserRole::StockManager = 'stock_manager'`, label `users.roles.stock_manager` (uz "Omborchi", ru "Кладовщик", en "Stock manager"). Department **`warehouse`** (seeded; name "Ombor" / "Склад" / "Warehouse"): `head` = warehouse head (approver), `specialist` = stock manager, `viewer` = read-only (e.g. auditors). Grant equivalence: `role:stock_manager` ≡ `dept:warehouse:specialist`. Optional `department_warehouses` pivot (Q-S9) limits a stock manager to their warehouses; default: all warehouses.
- Does: receives goods, registers serials (one by one / paste / import), posts receipts, registers serials in and posts delivery notes, transfers, requests adjustments/write-offs, handles customer-return receipts, inspects returned units, registers opening balances, works the discrepancy queue.
- Does not: see payments, invoices, agreements, receivables; change prices, ordered quantities or order lines; approve own write-offs, corrections or overrides.

### 21.3 Permission matrix (stock module)
| Action | sales | sales head | manager | logistics | stock manager | warehouse head | accountant | finance | admin |
|---|---|---|---|---|---|---|---|---|---|
| View goods receipts, transfers, adjustments | no | no | all | all (read) | all | all | all (read) | all (read) | no |
| Create/edit draft goods receipt | no | no | no | draft from shipment | yes | yes | no | no | no |
| Enter/import serials on a receipt; post receipt | no | no | no | no | yes | yes | no | no | no |
| Draft delivery note (no serials) | own orders | dept | all | yes | yes | yes | no | no | no |
| Register serials in delivery note; post it | no | no | no | no | yes | yes | no | no | no |
| Unregistered-serial override (if enabled) | no | no | no | no | no | yes (reason) | no | no | no |
| Transfer between warehouses | no | no | no | no | yes | yes | no | no | no |
| Request write-off / found / serial correction / qty correction | no | no | no | no | yes | yes | no | no | no |
| Approve adjustment (never own) | no | no | yes | no | no | yes | notified | notified (write-offs) | no |
| Correct serial on a **posted** delivery note | no | no | no | no | request | approve (not own) | notified | no | no |
| Customer return receipt (references delivery note) | request | request | yes | no | yes | yes | notified | no | no |
| Opening-balance registration | no | no | no | no | yes | yes | no | no | no |
| Serial search: item, status, warehouse, history | own orders' serials | dept | all | all | all | all | all | all | no |
| Serial search: customer/order of a sold serial | own orders | dept | all | no | document number only | yes | yes | yes | no |
| Reconciliation report (serials vs 1C) | no | no | all | no | all | all | all | all | no |
| Serial settings (normalization, global uniqueness, override) | no | no | view | no | view | view | view | no | config (values approved by warehouse head) |

Abilities (proposed for `config/permissions.php`):
```php
$stock = ['role:stock_manager' => 'all', 'dept:warehouse:specialist' => 'all'];
$warehouseHead = ['dept:warehouse:head' => 'all'];
'stock.view'            => $stock + $warehouseHead + ['role:manager' => 'all', 'dept:logistics:*' => 'all'] + $accountant + $controller,
'stock.receive'         => $stock + $warehouseHead,
'stock.deliver'         => $stock + $warehouseHead,   // register serials + post delivery note
'stock.delivery_draft'  => ['role:sales' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all', 'dept:logistics:*' => 'all'] + $stock + $warehouseHead,
'stock.transfer'        => $stock + $warehouseHead,
'stock.adjust_request'  => $stock + $warehouseHead,
'stock.adjust_approve'  => $warehouseHead + ['role:manager' => 'all'],   // never own
'stock.serial_override' => $warehouseHead,
'serials.view'          => ['role:sales' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all', 'dept:logistics:*' => 'all'] + $stock + $warehouseHead + $accountant + $controller,
'serials.view_customer' => ['role:sales' => 'own', 'dept:sales:head' => 'department', 'role:manager' => 'all'] + $warehouseHead + $accountant + $controller,
'stock.reconciliation'  => ['role:manager' => 'all'] + $stock + $warehouseHead + $accountant + $controller,
```
Segregation of duties: SoD-S1 the stock manager never records/approves money and the accountant never posts stock documents; SoD-S2 requester ≠ approver for adjustments, write-offs, posted-serial corrections and overrides (checked by user id); SoD-S3 sales cannot register serials or post delivery notes (they draft); SoD-S4 admin has no stock business rights.

### 21.4 Workflows and statuses
**Goods receipt** (`GoodsReceiptStatus`, superseded by section 26.4: `received_from_1c` → `in_progress` → `checked` → `posted`, `on_hold`, `cancelled`; number `GR-YYYY-NNNNN`; supplier receipts originate in 1C, section 26). Sources (`source_type`): `purchase_order` (Spec 03 T-5), `shipment` (import via transport tender), `supplier_delivery` (no PO), `customer_return` (references a posted delivery note), `opening_balance`. Lines: item, expected qty (from source, nullable), received qty, damaged qty (subset of received), condition, discrepancy reason. Partial receipts: several receipts per source; remaining expected qty shown.
- Post guards: every serialized line has exactly `received_qty` serial rows (whole numbers), no duplicates within the receipt, none already registered for that item (or for any item, if global uniqueness is on); discrepancy (`received ≠ expected`) requires a reason code (`short`, `over`, `damaged`, `wrong_item`, `other`) + note; over-receipt above `stock.over_receipt_tolerance_bp` (default 0) needs warehouse-head approval.
- Post effects: serials created `available` at the receipt warehouse (damaged units → `quarantined`), events `registered`; customer-return units → `returned` (inspection), then the inspection action sets `available` or raises a write-off request; order line `returned_qty` += qty; outbox `goods_receipt.posted`. Posted receipts are immutable; corrections via adjustment.

**Delivery note = sales document** (`draft` → `posted`; `draft` → `cancelled`; `DN-YYYY-NNNNN`): drafted from an order (lines = open qty, editable down); each serialized line **expands into one serial row per unit** (`document_line_serials`); the line qty is derived from the number of serial rows; non-serialized lines keep plain qty. Post guard: every serialized line fully registered, every serial still reserved by this note. Post effects: serial `reserved → sold` (customer, order line, delivery line, `sold_at`, `warranty_until` = `sold_at` + item `warranty_months` if set), serial rows `posted` (immutable snapshot), order `delivered_qty`, on-delivery schedule items, outbox `delivery.posted` with serial arrays. Cancel draft → serials released.

**Stock transfer** (`TR-`; draft → posted): from/to warehouse; serialized lines list serials that must be `available` at the source; posting moves `warehouse_id` (event `transferred`); v1 instant (no in-transit state, Q-S10).

**Stock adjustment** (`ADJ-`; draft → pending_approval → posted | rejected): types `write_off` (serials → `written_off`, reason), `found` (registers new serials `available`, reason), `serial_correction` (posted delivery-note line: replace serial X by Y; Y must be available, same item, same warehouse; X → `available` or `quarantined` by decision; old snapshot row → `replaced`, new row `posted`; outbox `delivery.serials_corrected`), `qty_correction` (non-serialized, informational to 1C), `status_change` (quarantined/returned → available after inspection). Approval by warehouse head/manager (not the requester); accountant notified.

**Serial status machine** (`SerialStatus`):
```
(receipt / found / opening) ──► available ──claim on delivery-note draft──► reserved ──post──► sold ──customer return receipt──► returned ──inspection──► available | written_off
available ──transfer──► available (other warehouse)            available | quarantined | returned ──write-off──► written_off
(receipt, damaged) ──► quarantined ──inspection──► available   reserved ──draft cancelled / line reduced / serial removed──► available
```
`returned` (customer return under inspection) and `quarantined` (damaged on receipt) are not sellable. No transition deletes a serial; `written_off` is final except through an approved `found` adjustment on the same row (new events).

### 21.5 Data model (stock)
`item_serials`
| Column | Type | Notes |
|---|---|---|
| id, uuid | | |
| item_id | FK items restrict | |
| serial_raw | string(100) | as entered (after invisible-character strip) |
| serial_normalized | string(64) | BR-SN.1 |
| global_key | string(64) nullable **unique** | = `serial_normalized` while `serials.global_uniqueness` is on, else null (DB-enforced global uniqueness without a second table) |
| status | string(16) index | `SerialStatus` |
| warehouse_id | FK nullable | null when sold / written off |
| source | string(16) | `receipt`, `opening_balance`, `found`, `return`, `manual_override` |
| received_receipt_line_id | FK nullable | origin document line |
| received_at | timestamp | |
| reserved_by_line_type, reserved_by_line_id | nullable, index | delivery/transfer/adjustment line holding it |
| reserved_at | timestamp nullable | |
| sold_delivery_line_id, sold_order_line_id | FK nullable | |
| customer_id | FK nullable index | set at sale |
| sold_at | timestamp nullable | |
| warranty_until | date nullable | |
| lock_version | unsigned int | |
| timestamps | | no soft or hard deletes |
Unique (`item_id`, `serial_normalized`); index (`serial_normalized`) for cross-item lookups and messages; index (`item_id`, `warehouse_id`, `status`).

`document_line_serials` (serial rows of any stock document line; the registration grid): `document_type` (`goods_receipt`, `delivery_note`, `stock_transfer`, `stock_adjustment`), `document_id`, `line_type`, `line_id`, `item_id`, `item_serial_id` FK nullable (null only on receipt drafts; resolved when posting creates the serial), `serial_number` (normalized snapshot), `serial_raw`, `status` (`draft`, `posted`, `replaced`, `voided`), `replaced_by_id`, `active_key` string nullable **unique** (= `document_type:document_id:item_id:serial_normalized` while draft/posted, null when replaced/voided → no duplicate within one document, across lines too), `position`, `import_batch_id` nullable, `created_by`, `created_at`. Index (`line_type`, `line_id`), (`item_serial_id`). Posted rows immutable (model guard).

`serial_events` (append-only): `item_serial_id`, `event` (`registered`, `reserved`, `released`, `sold`, `returned`, `quarantined`, `inspected`, `transferred`, `written_off`, `found`, `corrected`, `override_created`), `from_status`, `to_status`, `warehouse_from_id`, `warehouse_to_id`, `document_type`, `document_id`, `line_id`, `customer_id`, `reason`, `causer_id`, `import_batch_id`, `created_at`. Index (`item_serial_id`, `created_at`).

`goods_receipts`: `uuid`, `number`, `status`, `source_type`, `source_id` nullable, `counterparty_id` nullable (supplier/carrier), `customer_id` nullable (return), `return_of_delivery_id` nullable, `warehouse_id`, `received_on`, `supplier_doc_ref` nullable, `created_by`, `posted_by/at`, `approved_by` nullable (over-receipt), `onec_guid`, `onec_number`, `onec_state`, `note`. `goods_receipt_lines`: `receipt_id`, `item_id`, `is_serialized` (snapshot), `return_of_delivery_line_id` nullable, `expected_qty` nullable, `received_qty`, `damaged_qty`, `condition` (`good`, `damaged`, `mixed`), `discrepancy_reason` nullable, `note`. No prices.
`stock_transfers` / `stock_transfer_lines`: number, status, from/to warehouse, lines (item, qty). `stock_adjustments` / `stock_adjustment_lines`: number, `type`, status, reason, `target_delivery_line_id` nullable (serial correction), requested_by, approved_by/at, lines (item, qty, old/new serial ids for corrections).
`serial_import_batches`: `uuid`, `context_type` (`goods_receipt`, `goods_receipt_line`, `delivery_note`, `delivery_line`, `opening_balance`), `context_id`, `file_name`, `file_hash` (sha256), `disk_path` (private), `size_bytes`, `format` (`csv`, `xlsx`), `encoding_detected`, `delimiter_detected`, `rows_total`, `rows_ok`, `rows_warning`, `rows_error`, `mode` (`all_or_nothing` default, `valid_only`), `status` (`uploaded`, `validating`, `validated`, `committing`, `committed`, `failed`, `discarded`), `error_report_path`, `created_by`, `committed_by/at`, `expires_at` (file deleted after `serials.import_file_retention_days` = 14; metadata kept). Unique (`context_type`, `context_id`, `file_hash`) among committed batches (app-enforced + `committed_key` nullable unique column).
`serial_import_rows` (pruned with the file): `batch_id`, `row_no`, `serial_raw`, `serial_normalized`, `item_id` nullable, `warehouse_id` nullable, `warranty_until` nullable, `note`, `result` (`ok`, `already_in_document`, `duplicate_in_file`, `already_registered`, `belongs_to_other_item`, `wrong_item`, `not_found`, `not_available`, `wrong_warehouse`, `invalid_chars`, `too_long`, `too_short`, `numeric_cell`, `scientific_notation`, `formula`), `message`.
Items: `is_serialized` (1C flag, offers agent); proposed CRM-local `warranty_months` unsigned smallint nullable (`catalog.edit_local`). Delivery lines, order lines and invoice lines: see 7.6.
Settings: `serials.global_uniqueness` false, `serials.remove_internal_whitespace` true, `serials.uppercase` true, `serials.allowed_pattern`, `serials.min_length` 3, `serials.max_length` 64, `serials.reject_cyrillic` true, `serials.allow_unregistered_override` false, `serials.draft_hold_alert_hours` 72, `serials.discrepancy_grace_hours` 24, `serials.print_inline_max` 20, `serials.import_max_mb` 5, `serials.import_max_rows` 10000, `serials.import_file_retention_days` 14, `serials.outbox_receipt_serials` false, `stock.over_receipt_tolerance_bp` 0.

### 21.6 Serial rules (BR-SN)
- BR-SN.1 Normalization (`SerialNormalizer`, one implementation for UI, paste, import): strip invisible characters (zero-width, BOM, control characters; NBSP → space); trim; remove internal whitespace (if `remove_internal_whitespace` is false: collapse to one space); uppercase; must match `allowed_pattern` (default `^[A-Z0-9][A-Z0-9\-/._]*$`); length `min_length`..`max_length`. Cyrillic letters are **rejected** with the hint "Switch the keyboard/scanner layout to English" (scanners on a Cyrillic layout type look-alike letters). No automatic homoglyph mapping (Q-S4). `serial_raw` keeps the input for audit.
- BR-SN.2 Uniqueness: always unique per item (DB index). Global uniqueness across items is a setting (default off, Q-S2); switching it on runs a preflight report of cross-item duplicates, is refused while any exist, then backfills `global_key`.
- BR-SN.3 Count rule: serialized lines accept whole-number quantities only; on the delivery note the line qty **equals** the number of active serial rows; on receipts `received_qty` must equal the rows. Posting is **hard-blocked** until every serialized line matches.
- BR-SN.4 Duplicates inside one entry, paste, file or document (across lines too) are rejected per row ("Duplicate of row 12 / line 3").
- BR-SN.5 Receipt checks per serial: valid format; not already in this document; not registered for this item ("Already registered: GR-… on <date>, status <status>"); with global uniqueness on, not registered for another item ("Registered for item <SKU> <name>").
- BR-SN.6 Sale checks per serial: exists for **this item** — else, if it exists for another item, "This serial belongs to <SKU> <name>"; else "Serial not found in the registry for <item>"; status `available` — else `sold` ("Already sold on DN-… to <customer>"; order/customer only for users with `serials.view_customer` on that order, otherwise "Already sold"), `reserved` ("Reserved by delivery note DN-…", user name if authorized), `returned`/`quarantined` ("Under inspection — not sellable"), `written_off` ("Written off"); warehouse equals the note's warehouse — else "Located in <warehouse>; transfer it first". The serial's item must equal the order line's item (substitution = order amendment).
- BR-SN.7 Atomic claim (`ClaimSerials` action, used by one-by-one entry, paste and import commit): one transaction; normalize and dedupe the batch; `SELECT … FOR UPDATE` the candidate `item_serials` rows ordered by id (deadlock-safe); verify each; conditional `UPDATE … SET status = 'reserved', reserved_by_* = … WHERE id IN (…) AND status = 'available'`; affected rows must equal the batch size, else roll back and return per-serial errors; insert `document_line_serials` (unique `active_key` is the last guard); write `serial_events`. Posting re-locks and moves `reserved → sold` only for rows reserved by that note.
- BR-SN.8 Edit before posting: remove/replace a serial in a draft (old → `available`, new claimed), reduce a draft line, cancel the draft, or cancel the order (only drafts can exist then) → serials `available`, event `released`. Drafts holding serials longer than `draft_hold_alert_hours` appear in the stock manager queue (no automatic release).
- BR-SN.9 After posting, serials are locked: changes only via an approved `serial_correction` adjustment or a customer return, both audited; prints show the correcting document number.
- BR-SN.10 Returns (reverse flow): the return receipt may contain only serials sold on the referenced delivery note (status `sold`, same customer); qty per line ≤ delivered − already returned. Credit notes are issued in 1C (roadmap); outbox `goods_receipt.posted` for returns includes the serials and the original delivery-note reference.
- BR-SN.11 Item switched to serialized while stock exists: the item enters "Serials missing" with required count = 1C quantity per warehouse − registered available serials; delivery notes for it are blocked until opening balances are registered (unless the override is enabled). Item switched off: existing serials stay read-only; new documents no longer require serials; history kept.
- BR-SN.12 Kits/bundles are not modeled (Spec 05 non-goal); a serialized kit item carries one serial; component serials out of scope (Q-S7).
- BR-SN.13 Unregistered-serial override (only when enabled): warehouse head, per serial, reason required; creates the serial (`manual_override`; events `override_created` + `sold`); listed in the reconciliation report and a weekly digest to warehouse head + accountant.
- BR-SN.14 Invoices and tax-document registrations never take serial input; serialized invoice lines reference posted delivery lines and print their serials.

### 21.7 Entry modes (one Livewire component `<x-serials.entry>` for receipts, opening balances and delivery notes)
1. **One by one**: focused input; typing or a barcode scanner (keyboard wedge, Enter-terminated) adds the serial; live check per serial (debounced server call returning status + message); a rejected value stays in the input for correction.
2. **Bulk paste**: textarea, one serial per line (tab/semicolon/comma also split); preview with per-row results before "Add valid".
3. **File import** (.csv now, .xlsx after DEC-20): 21.8.
- Grid per line: position, serial, status icon + text, remove/replace (draft only), filter "errors only"; counter "3 / 5 entered" per line and per document; duplicate detection across lines of the same document; post button disabled with the list of incomplete lines.
- Delivery notes accept the same three modes but only serials **available for that item in the note's warehouse** (BR-SN.6); receipts accept only **new** serials (BR-SN.5).

### 21.8 Import (CSV / XLSX)
- **Template download** per receipt line, per receipt (multi-item) and for opening balances: columns `serial_number` (required), `sku`, `external_id`, `accountant_code` (optional item identifiers, prefilled on per-line templates), `warehouse` (code; opening balances), `warranty_until` (YYYY-MM-DD), `note`. Header labels in the user's UI language (keys `stock.import.columns.*`); the canonical English keys are accepted in any language. The XLSX template formats the serial column as **Text**.
- **Upload limits**: ≤ 5 MB, ≤ 10 000 data rows; extension plus sniffed content (`text/csv`/`text/plain`, or a zip with a spreadsheet `[Content_Types].xml`); XLSX zip-bomb guard (uncompressed ≤ 50 MB, ≤ 50 entries, ratio check) before parsing; XML parsed without external entities.
- **CSV parsing** (native `fgetcsv`): encoding — UTF-8 BOM → UTF-8; valid UTF-8 → UTF-8; else Windows-1251 → converted, shown in the preview; delimiter detected from the header among `,` `;` tab; RFC 4180 quotes; header row required.
- **Excel pitfalls**: the serial is always read as **text**. XLSX numeric cell in the serial column → if integral and ≤ 15 digits converted without decimals and flagged `numeric_cell` (warning: "stored as a number in Excel; leading zeros may be lost — format the column as Text"); > 15 digits → error (precision lost). Values in scientific notation (`^\d+(\.\d+)?E[+-]?\d+$`, typical of CSVs saved by Excel) → error `scientific_notation`. Formula cells → error `formula` (never evaluated, cached values not trusted).
- **Preview/validation** (nothing written to the registry): per-row result code and message (duplicate in file / already registered for this item / belongs to another item, named / wrong item for this receipt line / not available (sales) / invalid characters / too long / numeric cell / scientific notation / formula); summary counts; comparison with the line's expected qty ("48 valid serials; expected 50 — posting stays blocked until counts match"); downloadable error report CSV (formula-escaped). Commit choice: **all-or-nothing** (default) or "import valid rows only".
- **Commit**: one DB transaction per batch, re-validating under row locks; unique indexes are the last guard (violation → whole batch rolled back with a "re-validate" message). Files > 1 000 rows are validated and committed in a queued job (`serial-import` queue) with a progress bar (Livewire poll). On delivery notes the commit is `ClaimSerials` (BR-SN.7).
- **Idempotency**: batch keyed by (context, `file_hash`); re-uploading the same file for the same document shows "Already imported on <date> by <user>" and commits nothing; rows already in the document are `already_in_document` (ok, not duplicated).
- **Audit and retention**: `serial_import_batches` (who, file name, hash, counts, mode, encoding), `serial_events.import_batch_id`; files on the private disk, deleted after 14 days.
- **Library options** (DEC-20; the dependency needs user approval, Q-S12):

| Option | License | Memory / speed | Security surface | Notes |
|---|---|---|---|---|
| **openspout/openspout** (recommended) | MIT | streaming reader/writer, constant memory | no formula engine; XMLReader-based | reads/writes xlsx, csv, ods; reusable for XLSX exports |
| phpoffice/phpspreadsheet | MIT | loads the whole workbook; high memory at 10k+ rows (read filters help) | large surface incl. a formula calculation engine; past XXE advisories | rich formatting not needed here |
| maatwebsite/excel (Laravel Excel) | MIT | wraps PhpSpreadsheet; chunk/queue helpers | inherits PhpSpreadsheet | convenient Laravel API; heaviest |

Phase 1 fallback: CSV only (native PHP); an XLSX upload is refused with the hint "Save as CSV UTF-8".

### 21.9 1C exchange (pull-only)
- Outbox feed `warehouse` (new ability `warehouse:read`): `goods_receipt.posted` (lines: item GUID, `accountant_code`, received/damaged qty, warehouse GUID, source references; **no serials** unless `serials.outbox_receipt_serials`; returns include serials), `stock_transfer.posted`, `stock_adjustment.posted`. Feed `orders`: `delivery.posted` (sales document: order reference, warehouse, lines with item GUID, `accountant_code`, `class_code`, `package_code`, qty, amounts and `serials: ["SN0001", …]` per serialized line), `delivery.serials_corrected`.
- 1C acks with its document GUID/number and optionally `posted_lines` quantities. **Conflict rule**: the 1C stock push stays the quantity truth for availability (Spec 02); CRM posted documents are never changed by 1C. If 1C posts a receipt with a different quantity (ack `posted_lines` ≠ CRM) or later stock disagrees, reconciliation flags a discrepancy for the stock manager and accountant; resolution is an approved CRM adjustment or a correction in 1C, never automatic.
- There is no inbound serial endpoint (1C keeps no serials).
- Since 2026-10-19 supplier receipts are pushed by 1C (`POST /goods-receipts`, `warehouse:write`), see section 26.

### 21.10 Screens, navigation, prints, notifications
- Navigation section `nav.warehouse_section`: `['key' => 'warehouse', 'label' => 'nav.warehouse', 'icon' => 'warehouse', 'can' => ['viewAny', GoodsReceipt::class], 'children' => [ ['label' => 'nav.goods_receipts', 'route' => 'goods-receipts.index', 'active' => 'goods-receipts.*'], ['label' => 'nav.delivery_notes', 'route' => 'delivery-notes.index', 'active' => 'delivery-notes.*'], ['label' => 'nav.stock_transfers', 'route' => 'stock-transfers.index', 'active' => 'stock-transfers.*'], ['label' => 'nav.stock_adjustments', 'route' => 'stock-adjustments.index', 'active' => 'stock-adjustments.*'], ['label' => 'nav.serials', 'route' => 'serials.index', 'active' => 'serials.*'], ['label' => 'nav.stock_reconciliation', 'route' => 'stock.reconciliation'] ]]`; Settings child `nav.settings_serials`; portal `nav.portal_warranty`.
- Screens: goods receipts index/editor (source picker, lines, expected vs received, discrepancy, per-line serial component, post); delivery-note editor (from the order page or the warehouse queue; per serialized line a serial grid with counter; post disabled with the list of incomplete lines); transfers; adjustments (approval panel); **serial search** (exact/prefix on the normalized value; results: item, status, warehouse, document chain, warranty; customer/order per permission); serial show (history timeline); item show → "Serials" tab (counts by status/warehouse, list); opening-balance wizard (item/warehouse → template → import → compare count with 1C qty → post); reconciliation report.
- Stock manager queues: **Goods to receive** (POs/shipments with ETA ≤ 3 days, announced returns), **Delivery notes to post** (drafts, oldest first, serial completeness), **Serials missing** (BR-SN.11), **Discrepancies** (21.11), **Drafts holding serials > 72 h**, **Returned / quarantined to inspect**; warehouse head: **Adjustments to approve**, override digest.
- Notifications (`notifications.stock.*`): goods expected (shipment ETA, PO due), delivery note drafted for my warehouse, item became serialized with stock, reconciliation discrepancy (stock manager + accountant), adjustment pending approval (head) / decided (requester), 1C ack quantity mismatch on a receipt, return announced.
- **Portal warranty lookup** (`/portal/warranty`): exact serial match among serials sold to the user's own company → item, sale date, delivery note number, warranty until; anything else → the same generic "not found" (never reveals that the serial exists elsewhere); throttled 10/min per user and 100/day per company.
- Prints (all via `DocumentRenderer::print`, uz/ru/en): delivery note (`documents.delivery_note`, serials under each line), invoice/act/waybill copy (serials of the referenced delivery lines), **warranty card** (`documents.warranty_card`), goods receipt (`documents.goods_receipt`), transfer and adjustment. Compact format: up to `print_inline_max` (20) serials inline, comma-separated; above it, consecutive runs (same prefix, numeric suffix of equal width) print as ranges "SN000101–SN000150 (50)" with the full list in an annex at the end.
- Document keys (add to `lang/{uz,ru,en}/documents.php`):

| Key | en | ru | uz |
|---|---|---|---|
| `delivery_note.title` | Delivery note | Накладная на отпуск товаров | Tovarlarni berish yuk xati |
| `serials.label` | Serial numbers | Серийные номера | Seriya raqamlari |
| `serials.range` | :from – :to (:count pcs) | :from – :to (:count шт.) | :from – :to (:count dona) |
| `serials.annex_title` | Annex: serial numbers | Приложение: серийные номера | Ilova: seriya raqamlari |
| `serials.see_annex` | See annex (:count serial numbers) | См. приложение (:count серийных номеров) | Ilovaga qarang (:count ta seriya raqami) |
| `serials.corrected_by` | Serial numbers corrected by :number | Серийные номера исправлены документом :number | Seriya raqamlari :number hujjati bilan tuzatilgan |
| `warranty_card.title` | Warranty card | Гарантийный талон | Kafolat taloni |
| `warranty_card.warranty_until` | Warranty until | Гарантия до | Kafolat muddati |
| `goods_receipt.title` | Goods receipt | Приходная накладная | Kirim yuk xati |
| `goods_receipt.expected` / `.received` / `.damaged` | Expected / Received / Damaged | Ожидается / Принято / Повреждено | Kutilgan / Qabul qilingan / Shikastlangan |

UI keys: new `lang/{uz,ru,en}/stock.php` (statuses, sources, reasons, entry/import messages, every BR-SN.5/6 error text with `:item`, `:sku`, `:document`, `:customer`, `:warehouse` placeholders, import column headers, result codes), `nav.php` (warehouse entries), `users.php` (`roles.stock_manager`), `departments.php`, `integration.php` (`warehouse_read`).

### 21.11 Reconciliation (serials vs 1C quantities)
Per serialized item × warehouse: CRM count of serials in `available` + `reserved` + `returned` + `quarantined` vs 1C `item_stocks.quantity` (latest `as_of`). **Expected lag**: + CRM delivery notes posted whose outbox event is not yet posted in 1C; − CRM receipts/found posted but not yet posted in 1C; ± transfers likewise. Discrepancy = CRM − 1C − expected lag. Non-zero for longer than `discrepancy_grace_hours` (24) → alert to the stock managers of that warehouse and to accountants. Report columns: item (SKU, name, `accountant_code`), warehouse, CRM by status, 1C qty, lag, discrepancy, since, document links; nightly snapshots kept 90 days. Resolution: register missing serials (opening balance / found, approved), write off, or ask 1C to correct; never automatic.

### 21.12 Security (stock)
- Atomic claims and unique indexes (BR-SN.7); posted serial rows and `serial_events` append-only (model guards; optional DB triggers).
- IDOR: documents authorized by policy and warehouse scope (Q-S9); sold-serial customer/order details only with `serials.view_customer` on that order; portal lookup exact-match, own company, generic not-found, throttled.
- Uploads: private disk, random names, size/row limits, content sniffing, zip-bomb and XXE protection, no formula evaluation, files deleted after 14 days; error reports and exports escape leading `= + - @`.
- Rate limits: live serial checks 120/min per user; import uploads 10/min per user; outbox reads per token as section 9.
- Audit: every serial mutation has a `serial_events` row with causer and document; overrides and corrections need a reason and a second person.

### 21.13 Acceptance criteria (stock)
- AC-S1 Given a receipt line of a serialized item with received qty 5 and 4 serials, posting is refused listing the line; with 5 valid unique serials it posts and creates 5 `available` serials at the receipt warehouse with `registered` events.
- AC-S2 Entering " sn-0001 " and "sn-0001" in one receipt: both normalize to `SN-0001`, the second is rejected as a duplicate of row 1; "SN 0002" normalizes to `SN0002`; a Cyrillic "СН0003" is rejected with the layout hint.
- AC-S3 A serial already registered for the item is rejected at receipt with the original receipt number; with global uniqueness on, a serial registered for item B is rejected for item A naming item B.
- AC-S4 Delivery note for item A: serial of item B → "belongs to <B>"; unknown → "not found for <A>"; sold → "already sold" (customer only for authorized users); reserved by another note → names that note; in another warehouse → names it; available → reserved and the counter shows "1 / 2".
- AC-S5 Two users claim the same available serial concurrently on two drafts: exactly one succeeds; the other gets "Reserved by DN-…" and nothing partial is written (two DB connections/processes).
- AC-S6 A delivery note with a serialized line of qty 3 and 2 serials cannot be posted (UI or direct action call); after the third it posts: serials `sold`, customer/order/warranty set, serial rows `posted`, order `delivered_qty` updated, outbox `delivery.posted` lists the 3 serials.
- AC-S7 Cancelling a draft note releases its serials to `available` with `released` events; replacing a serial in a draft releases the old one.
- AC-S8 A posted note's serial cannot be edited; a `serial_correction` requested by the stock manager and approved by the warehouse head replaces X by Y, X → available, old row `replaced`, the print shows "corrected by ADJ-…", outbox `delivery.serials_corrected`; the requester cannot approve.
- AC-S9 A return receipt referencing DN-1 accepts only DN-1's sold serials; a serial sold on DN-2 is rejected; posting → `returned`; inspection → `available`.
- AC-S10 Transfer moves an available serial's warehouse; a reserved serial cannot be transferred.
- AC-S11 CSV import (Windows-1251, `;`, 10 rows incl. a duplicate, a scientific-notation value and a serial of another item) previews per-row results; all-or-nothing commit is refused while errors exist; "valid rows only" registers the valid rows; re-uploading the same file commits nothing and shows the earlier batch.
- AC-S12 XLSX with the serial column stored as numbers shows `numeric_cell` warnings; a 17-digit numeric cell and a formula cell are errors; a 6 MB file or 10 001 rows are refused.
- AC-S13 An item flipped to serialized with 1C qty 10 at W1 and no serials appears in "Serials missing" (10); delivery notes for it are blocked; after opening-balance registration of 10 serials it clears.
- AC-S14 Reconciliation: 1C qty 10, CRM available 9, one posted delivery not yet in 1C → discrepancy 0; without that delivery → −1 and an alert after 24 h.
- AC-S15 A portal user of company A looking up a serial sold to company B gets the generic not-found; their own serial shows item, date and warranty.
- AC-S16 Matrix 21.3 as a data provider passes; an accountant cannot post a receipt; a stock manager cannot record a payment; a sales user cannot post a delivery note.
- AC-S17 Delivery note, warranty card and invoice copy print serials in uz/ru/en; 60 consecutive serials print as one range plus annex.

### 21.14 Tests plan (stock)
Unit: `SerialNormalizerTest` (whitespace, case, invisible characters, Cyrillic, length, pattern, setting variants), `SerialStatusTest` (transition table), `SerialRangeFormatterTest` (ranges, mixed prefixes, widths), `CsvSerialReaderTest` (BOM, Windows-1251, delimiters, quotes, scientific notation), `XlsxSerialReaderTest` (06-S6: numeric cells, formulas, zip-bomb guard), `ReconciliationCalculatorTest` (lag).
Feature: `Stock/GoodsReceiptPostingTest` (AC-S1..S3, discrepancies, over-receipt approval), `Stock/DeliveryNoteSerialsTest` (AC-S4, S6, S7), `Stock/SerialClaimConcurrencyTest` (AC-S5; parallel connections against MySQL in CI), `Stock/SerialCorrectionTest` (AC-S8), `Stock/CustomerReturnTest` (AC-S9), `Stock/TransferTest` (AC-S10), `Stock/SerialImportTest` (AC-S11, S12, idempotency, queued path), `Stock/OpeningBalanceTest` (AC-S13), `Stock/ReconciliationTest` (AC-S14), `Portal/WarrantyLookupTest` (AC-S15, throttle), `Policies/StockPolicyTest` (AC-S16), `Documents/SerialPrintTest` (AC-S17), `Api/OneC/WarehouseOutboxTest` (receipt without serials by default, delivery with serials, ack mismatch → discrepancy), `TranslationParityTest`.

### 21.15 Open questions (stock; default built if unanswered)
| # | Question | Default |
|---|---|---|
| Q-S1 | Is the delivery note the right "sales document" for serial registration (rather than the invoice)? | Yes (DEC-16); invoices reuse the delivery serials |
| Q-S2 | Must serials be unique across all items, or only per item? | Per item; global uniqueness is a setting (off) |
| Q-S3 | Serial format: allowed characters, min/max length, keep internal spaces? | `A-Z 0-9 - / . _`, 3–64, spaces removed, uppercase |
| Q-S4 | Auto-convert Cyrillic look-alike letters typed by scanners? | No; reject with a layout hint |
| Q-S5 | Allow selling a serialized item whose serial is not registered (override)? | Disabled; when enabled, warehouse head only, with reason |
| Q-S6 | Warranty period source | Item CRM-local `warranty_months`, or the import column; else none |
| Q-S7 | Kits/bundles with component serials | Out of scope; a kit has one serial |
| Q-S8 | Should goods-receipt outbox events include serials for 1C? | No (1C keeps none); returns and delivery notes include them |
| Q-S9 | Restrict stock managers to specific warehouses? | No, all warehouses; `department_warehouses` pivot ready |
| Q-S10 | In-transit state for transfers between distant warehouses? | No, instant transfer in v1 |
| Q-S11 | Discrepancy grace period and who resolves | 24 h; stock manager resolves, accountant informed, warehouse head approves adjustments |
| Q-S12 | **Approve the `openspout/openspout` dependency for XLSX import?** | Phase 1 is CSV only until approved |
| Q-S13 | Import commit default | All-or-nothing; "valid rows only" selectable |
| Q-S14 | Does a stock manager need 2FA? | No by default (shared terminals); configurable |

## 22. Build status (2026-10-18, job 3: orders and agreements)

Built (increments 06-0 partial, 06-1, 06-2 partial, 06-4, 06-5 partial, 06-12):
- **Roles and rails**: `UserRole::Accountant` (uz "Hisobchi", ru "Бухгалтер", en "Accountant"); department `finance` displayed as "Accounting & Finance"; backfill maps `finance` → finance/**head** (controller) and `accountant` → finance/specialist (operator); abilities of 4.3 for orders and agreements plus `orders.waive_agreement`, `agreements.upload_scan`, `agreements.terminate`, `company.manage`, `customers.finance_details`, `accounting.credit`; `catalog.edit_accounting_codes` now includes the accountant. Settings > Company (legal profile per document language, our bank accounts with one default per currency, signatories with the CEO as default); customer Finance tab (VAT code, OKED, signatory, bank accounts, billing/signatory contact flags, credit limit and manual hold for finance only, exposure, orders and agreements).
- **Orders**: `CreateOrderFromOffer` (called after `AcceptOffer` commits; "Create order" fallback on the offer page; idempotent by unique `offer_id`), `ConfirmOrder` (customer blocked check, manual and credit holds, `StockAvailability` with shortage acknowledgement or `orders.block_confirm_on_shortage`, reservations for the available quantity, `agreement_unsigned` hold), `StartFulfilment`, `CancelOrder` (no deliveries, `paid_minor` = 0, releases reservations, cancels unsigned agreements), `OverrideHold`, `WaiveAgreement`, `LinkFrameworkAgreement`, `UpdateOrderDetails` (only while `new`). Screens: Orders index (queues on hold / agreement missing), Order show (lines with codes toggle, holds with release, confirm dialog with availability, stale stock and credit, agreement tab, timeline); print `documents.order` in uz/ru/en.
- **Agreements**: templates and versions (Settings > Agreement templates: draft per language, optional clauses, live lint, publish with sanitizer and checksum, published versions immutable); `TemplateRenderer` (whitelisted `{{ group.key }}` placeholders escaped and formatted per language, `{{#clause:code}}` blocks, generated lines table), `AgreementVariables` (frozen snapshot), `AgreementValidator` (BR-AG.2) and non-standard detection (clauses changed, payment terms differ from the customer's, value ≥ review threshold); actions Generate, Update draft (refreshes data), Submit / Approve (approver ≠ preparer) / Return, Send (stores `agreement_versions` per print language with sha256; dispatch per channel), Upload scan (private attachment, pdf/jpg/png sniffed, 10 MB), Confirm signed (internal accountant/manager/finance; sales never), Re-issue, Cancel, Terminate (open orders block, notice period unless mutual), Renew (framework), Addendum; scheduler `agreements:lifecycle` daily 00:05 Tashkent (activate, expire, renew, expiry reminders 90/60/30 once per stage, unsigned reminders). Screens: Agreements index with the accountant queues (to send, in review, awaiting signature, scans to verify, expiring), Agreement show (draft editor, validator list, preview per language, stored version selector, dispatches, timeline); print `documents.agreement` (stored text with fingerprint, side-by-side second language, details of the parties and signatures from the snapshot, draft mark for previews).
- Seeders: `AgreementTemplateSeeder` (order, framework, addendum in uz/ru/en; texts must be reviewed by the company lawyer before go-live), `CompanyDemoSeeder` (dev only), demo accountant `accountant@crm.test`.

Defaults and decisions taken (user instruction: take the spec defaults and record them):
- Review threshold `agreements.review_above_minor`: UZS 60 000 000.00 (user: amounts above 60M UZS go to the CEO), USD/EUR 5 000.00, RUB 500 000.00; approvers = manager, management head, finance.
- Re-issue is implemented on the same agreement row: `sent_for_signature → draft` with version + 1 (transition added to `AgreementStatus`); stored versions of earlier numbers stay printable with `?version=n` (AC-16). A cancelled order agreement frees the order for a new one (generated unique key).
- Order agreements default to a 12-month term starting on the generation date (editable in the draft); signing fills missing dates from `signed_on`.
- Rule switches live in `config/orders.php` / `config/agreements.php` and can be overridden by `settings` rows of the same key (`App\Support\BusinessSettings`); the Settings > Accounting screen that edits them comes with job 4.
- Credit exposure = Σ (gross − paid) of confirmed, unfinished orders in the limit currency; other currencies are not converted (no FX until job 4). The overdue hold and `prepayment_missing` arrive with the payment schedule (job 4).
- Agreement visibility for sales follows the order owner (order agreements) or the customer owner (framework/addendum).
- Sales may upload a customer's signed scan for their orders; only internal accountant/manager/finance confirm signatures (SoD-6).
- Company profile falls back to `config('documents.seller')` until filled; printed offers now use the company profile too.
- Settings navigation: the group has no group-level permission; Appearance/Integrations/Telegram/Notifications stay admin-only, Company is admin/manager/finance, Agreement templates manager/finance.

Deferred (with the job that delivers it):
- Payment schedule, proformas, payments, allocations, period lock, receivables, `onec_outbox` and `order.*` events, close order, overdue/prepayment holds, `fx_rates`, `accounting_periods`, Settings > Accounting: **job 4**.
- Deliveries/delivery notes (06-3, with serial registration), amendments, `requires_import`/transport link: **job 5** (deliveries) and **job 7** (transport).
- Portal and e-mail secure-link channels with OTP, notification delivery of reminders (currently activity log + queues): **job 8**.
- 2FA requirement for accountant/finance (no 2FA in the app yet) and sensitive-action re-confirmation: **job 10** hardening.

## 23. Build status (2026-10-18, job 4: accountant, payments, proformas, 1C outbox)

Built (increments 06-6 partial, 06-7, 06-8 partial, 06-9, 06-10, 06-11):
- **Permissions** of 4.3 for invoices, payments, receivables, periods, settings, `onec.outbox.view`, `orders.amend_schedule`, plus `payments.view_sensitive` (bank account, payer account, proof hidden from sales) and `accounting.view_settings` (manager/accountant read-only).
- **Payment schedule** (BR-AC.2): generated at confirmation (prepayment item due confirmation + `prepayment_due_days`; one on-delivery balance item due `promised_delivery_on + payment_terms_days`, undated until a delivery date is known); payment terms (prepayment %, deferred days) editable by accountant/manager/finance, schedule regenerated (superseded, never deleted). FIFO coverage, overdue with grace days, `prepayment_missing` hold at start of fulfilment (AC-5), `overdue` hold at confirmation when another order is overdue more than `hold_overdue_days`.
- **Payments**: record (validations of BR-AC.5, duplicate guard with explicit confirmation, proof above the threshold, open period), threshold approval by a second person (SoD-3/4), allocation to orders of the same customer only (re-checked server-side), FX with CBU rates (manual entry) and half-up conversion, deallocation as reversal rows, void request/approval (requester and recorder excluded), refunds as outgoing `RF-` payments always approved, immutability guards on payments/allocations/ledger, `accounting_entries` ledger written in the same transaction, nightly `accounting:nightly` (statuses + verification) and `orders:auto-close`.
- **Invoices**: proforma `PF-` (requested amount = next uncovered schedule item, our default bank account, print "not a VAT invoice"), tax-document registration (VAT invoice/act/waybill; qty ≤ delivered − invoiced; amounts by line math; `invoiced_qty`; order → invoiced), issue-time code validation (off/warn/block; accountant code never blocks per user decision; services outside the goods scope), void with finance approval (tax documents give quantities back), ESF id/status, print with configurable code columns.
- **Receivables** aging per customer and currency (never summed), unallocated payments as advances; **periods** lock (checklist; open items need confirmation) / reopen by another finance user with reason; **Settings > Accounting** (thresholds per currency, days, rule switches, code modes, print columns).
- **1C (pull-only)**: `onec_outbox` written in the business transaction for `order.confirmed`, `order.cancelled`, `order.closed`, `agreement.signed`, `agreement.terminated`, `invoice.issued`, `invoice.registered`, `invoice.voided`, `payment.recorded`, `payment.voided`, `payment.refund_recorded`; `GET /outbox/pending?feed=orders|payments` (feed ability, head-of-line per aggregate, at-least-once), `POST /outbox/ack` (applied / already_applied / superseded / rejected, idempotent, newer posting updates win; `order.confirmed` applied flips reservations to `mirrored_in_1c`; `payment.recorded` applied confirms the payment, rejected → `onec_rejected`); order-feed events of customers without a 1C GUID wait as `blocked_customer` and are released when the customer outbox ack links the customer; `POST /payments` (synchronous, idempotent by `batch_id`; crm_id link with 1C winning through correction entries, GUID update with out-of-order guard, exact auto-link, fuzzy possible duplicate not counted until resolved, unmatched payer queue, deletion mark → voided with reversals, unposted → not counted, locked period flagged). New token abilities `orders:read`, `payments:read`, `payments:write`; outbox monitor on Settings > Integrations.

Defaults and decisions:
- Thresholds (Q-8 defaults): approval UZS 100 000 000.00 / USD 10 000.00 / EUR 10 000.00 / RUB 1 000 000.00; proof required from the same amounts for bank transfers.
- Payments are processed synchronously on `POST /payments` (1 000 per call) instead of a queued batch; responses are stored for idempotent replay like the customer push.
- A "same payment" resolution keeps the CRM-recorded payment (it takes the 1C GUID and is confirmed) and voids the never-counted 1C duplicate as `merged_duplicate`.
- Order payment status counts `recorded` payments by default (DEC-13), shown as "Recorded, pending 1C".
- Proforma lines are all order lines (selection of lines deferred); tax documents can be registered only up to delivered quantities, which arrive with delivery notes in job 5.

Deferred: dunning events and customer reminders (internal queues exist; e-mail/Telegram with job 8), statement/reminder/payment-schedule prints, receivables CSV/XLSX export (with job 5's openspout), credit-limit conversion across currencies, milestone schedules edited by line, `warehouse` outbox feed (job 5), 2FA re-confirmation for approvals (job 10).

## 24. Build status (2026-10-18, job 5: stock manager, serial registry, goods receipts, delivery notes)

Built (increments 06-3 deliveries part, 06-S1, 06-S2, 06-S3, 06-S5 partial, 06-S6):
- **Role and department**: `UserRole::StockManager` (uz "Omborchi", ru "Кладовщик", en "Stock manager"), department `warehouse` (seeded for new installs, inserted by migration for existing ones), backfill `stock_manager` → warehouse/specialist; abilities `stock.view`, `stock.receive`, `stock.deliver`, `stock.delivery_draft`, `stock.adjust_approve` (warehouse head, manager), `serials.view`, `serials.view_customer`, `stock.reconciliation`, `catalog.edit_warranty`; `catalog.edit_serial_tracking` now includes the stock roles; demo user `stock@crm.test`.
- **Registry**: `item_serials` (unique per item, optional global uniqueness via `global_key`), append-only `serial_events`, `document_line_serials` (unique `active_key`, posted rows immutable), `SerialNormalizer` (BR-SN.1: invisible characters, whitespace, uppercase, Cyrillic rejected with a layout hint, scientific notation and formulas refused, length and pattern), `SerialChecker` (BR-SN.4/5/6/10 messages; buyer shown only with `serials.view_customer`).
- **Goods receipts** (`GR-`): supplier delivery, opening balance, customer return (lines from the posted delivery note; only serials sold on it); expected / received / damaged, condition, discrepancy reason, over-receipt above tolerance only by the warehouse head; posting creates serials (`available`, damaged lines `quarantined`), returns move serials to `returned`, inspection makes them available; outbox `goods_receipt.posted` on the new `warehouse` feed (ability `warehouse:read`; serials only for returns or when `serials.outbox_receipt_serials`).
- **Delivery notes** (`DN-`, the sales document): drafted from an order by sales/logistics/stock (open quantity minus other drafts), serials registered only by stock roles through the atomic `ClaimSerials` (row locks, conditional update, unique key), plain lines editable down, cancel releases serials (also when the order is cancelled); posting (order in fulfilment) sells the serials (customer, order line, warranty from item `warranty_months`), adds `delivered_qty`, consumes reservations, moves the order to partially delivered / delivered, splits the on-delivery schedule item (due delivery date + payment days; the completing delivery takes the remainder), outbox `delivery.posted` with serial arrays on the orders feed.
- **Entry modes**: one shared Livewire component per line — scan/type with Enter, bulk paste with per-row results, CSV/XLSX import (openspout) with preview, all-or-nothing or valid-rows-only commit, same-file idempotency per line, template download (serial column as text), Windows-1251/BOM/delimiter detection, numeric-cell warnings, >15-digit and formula errors, zip-bomb guard, size/row limits.
- **Screens and prints**: Warehouse section (goods receipts, delivery notes, serial search + history + inspection, serials vs 1C reconciliation with expected lag and "serials missing"), order Deliveries tab, item warranty months; prints delivery note (serials inline up to 20, ranges + annex above), warranty card, goods receipt — all uz/ru/en through DocumentRenderer.

Decisions:
- The delivery-note line quantity of serialized items is **derived from the registered serials** (7.6/21.4), so a note with fewer serials than the open quantity posts as a partial delivery; the screen lists lines still short of the open quantity. AC-S6's "qty 3 with 2 serials is refused" therefore reads as "a note without registered serials for its lines cannot be posted".
- Damaged units: the whole line goes to `quarantined` when its condition is "damaged"; per-serial damage marking is not modelled.
- Imports up to 10 000 rows are validated synchronously (no queue); files are kept on the private disk with an expiry date (the pruning job joins the go-live checklist).

Deferred: stock transfers and adjustments (write-off, found, serial correction of posted notes with approval, qty correction) and the `stock_transfer` / `stock_adjustment` outbox events; unregistered-serial override (setting stays off); reconciliation alerts after 24 h and nightly snapshots; portal warranty lookup (job 8); department–warehouse scoping (Q-S9 default: all warehouses).

### 24.1 Catalogue serial registration and units page removal (user requirement, 2026-10-18)
- **Units page removed**: `/catalog/units` route, Catalogue menu entry, `units.edit_precision` permission, the Livewire page and its translations are gone. The `units` table and model stay (items use them); units are seed/1C-managed. The unit precision is still shown on the item page (`catalog.items.unit_precision`).
- **Catalogue > Serial numbers** (moved from the Warehouse section): list of active serials (written-off excluded unless filtered), columns serial, item, status, warehouse, received date, document (goods receipt link); search by serial, filter by item and status, paginated; admins may open it read-only (`serials.view` includes `role:admin`; no add/import button).
- **"Add / import serials"** dialog (`App\Livewire\Stock\SerialRegistration`, reusable): serialized items only; warehouse; one-by-one entry (Enter adds, remove per row, running count) or CSV/XLSX import (openspout reader, file size and row caps from `serials.import_max_mb` / `serials.import_max_rows`, type sniffing) with a preview of valid / duplicate / invalid rows and a CSV error report; the template comes from `stock.serial-template`. The normalized value is shown next to the input ("will be saved as"), so nothing is normalized silently. Saving needs a fully valid batch and runs in one transaction (`App\Actions\Stock\RegisterSerials`): a goods receipt with the new source `manual_registration` is created, the serials attached and posted (serials keep the document link; the 1C warehouse feed sees the receipt). Duplicates are rejected in the batch and in the registry per the uniqueness setting. Ability `serials.register`: stock manager, warehouse staff (`dept:warehouse:specialist`) and warehouse head only; admin is excluded (SoD-S4, user decision 2026-10-18), the button is hidden for everyone else and `RegisterSerials` re-checks the ability, so no other role can create the goods receipt behind the dialog. Activity log event `serials_registered` (count, receipt, warehouse, origin) on the item.
- **Item page**: for serialized items, counts by status, the first active serials and the same dialog with the item preselected and locked (a forged item change is refused with 403).

## 25. Target sales flow (user decision 2026-10-18): lead → customer → offer → order and agreement → payment → packaging (serial numbers) → delivery

### 25.1 Gap analysis (before this change)
| Area | Built before | Gap against the target flow | Resolution |
|---|---|---|---|
| Sale type | none; every order treated as B2B | retail vs B2B decides documents, agreement and payment rule | `SaleType` (b2b default / retail) on customers and orders |
| Payment document | proforma (PF-) before payment, tax documents registered after delivery | retail sells against a sales receipt | `InvoiceType::SalesReceipt` (`SR-`), print via DocumentRenderer (uz/ru/en) |
| Agreement | `orders.agreement_required` setting for every order | retail needs none unless added | retail orders start with `agreement_required = false`; generating an agreement for a retail order sets it to true |
| Packaging | none; "start fulfilment" then serials registered on the delivery note | packaging is its own step where serials are assigned to the order lines | order statuses `packing` (renamed from `in_progress`) and `packed`; the packing list is the draft delivery note (lines = order lines, serials per line) with `packed_at/by` |
| Gates | payment and agreement holds at "start fulfilment"; posting needed `in_progress` | packaging only when the payment rule is met; delivery only after packaging is complete with all serials | G2 at start packaging, G3 at complete packaging, G4 at posting (below) |
| Roles | sales/logistics started fulfilment and drafted delivery notes | stock manager does packaging, accountant issues payment documents | `orders.pack` (stock manager, warehouse staff and head) replaces `orders.fulfil`; delivery note drafting moves to the warehouse; receipt needs `invoices.issue` (accountant, finance, manager) |
| Closing | only from `invoiced` | retail has no tax document | retail closes from `delivered` when paid and the receipt is issued |
| Navigation / UI | delivery notes under Warehouse; no flow view | packaging needs a queue; the order should show the flow | Warehouse section in flow order: Packaging, Delivery notes, Goods receipts, Reconciliation; order page stepper with the next action |

### 25.2 Order statuses and transitions (`OrderStatus`)
new → confirmed | cancelled; confirmed → packing | cancelled; packing → packed | cancelled; packed → packing (reopen) | partially_delivered | delivered | cancelled; partially_delivered → packing (pack the rest) | delivered; delivered → invoiced (B2B tax document) | closed (retail only, enforced by CloseOrder); invoiced → closed. Existing `in_progress` rows were migrated to `packing` (orders and status history).

### 25.3 Gates
- **G1 confirm** (unchanged): customer active, holds (credit, manual), stock check with acknowledgement.
- **G2 start packaging** (`StartPackaging`, from confirmed or partially delivered; stock roles): agreement signed/active when required (B2B by default) and `orders.block_fulfilment_without_agreement`; payment rule: retail = order fully paid; B2B = prepayment schedule item covered (no prepayment = credit terms, nothing to wait for). Otherwise the hold (`agreement_unsigned` / `prepayment_missing`) is stored and shown; finance/manager may release holds as before.
- **G3 complete packaging** (`Packaging::complete`): order `packing`, one open packing list per order, at least one quantity, every serialized line has one registered serial per unit still reserved by that line for the whole open quantity (backorder lines may be packed partly). The list is then locked (no quantity or serial changes) and the order becomes `packed`. `Packaging::reopen` (reason ≥ 5 characters) unlocks it and returns the order to `packing`.
- **G4 post delivery** (`PostDeliveryNote`): order `packed` and the list packed; effects unchanged (serials sold, quantities, schedule, outbox `delivery.posted`, available for 1C to pull).

### 25.4 Decisions (defaults recorded per user instruction)
- **DEC-25.1** Sale type is a CRM-local customer field (default B2B), editable by users who may edit the customer; an empty or missing value from 1C never changes it. Orders snapshot it at creation.
- **DEC-25.2** The sale type of an order can be changed only while the order is `new` (sales owner / sales head / manager, `orders.confirm`). Retail sets prepayment 100 % and 0 credit days and clears the agreement requirement; back to B2B restores the customer's payment days and the agreement setting.
- **DEC-25.3** Retail needs no agreement; generating an order agreement for a retail order makes it required for that order (the agreement step and gate then apply).
- **DEC-25.4** Sales receipt (`SR-YYYY-NNNNN`): retail only, issued by the accountant (`invoices.issue`) once the order is fully paid; one issued receipt per order (void and re-issue to correct); all order lines with amounts and the payments received; not a tax document (no ledger entry, `invoiced_qty` unchanged); added to the 1C outbox as `invoice.issued` with type `receipt`, available for 1C to pull. B2B keeps proforma (before payment) and registered tax documents (after delivery); retail orders get neither.
- **DEC-25.5** The packing list is the draft delivery note: created by the warehouse only when the order is `packing`; serials are registered per order line on it (DocumentLineSerial). Sales and logistics no longer draft delivery notes (they follow the order and the packaging queue read-only); `stock.delivery_draft` remains only for read access.
- **DEC-25.6** Stock roles can open orders (`orders.view` adds stock manager, warehouse staff and head) to pack them; they still never record or approve money (SoD-S1).
- **DEC-25.7** The packaging queue (`/packaging`, `orders.pack`) lists confirmed and partly delivered orders (with the gate status), orders being packed and packed orders, by promised delivery date.
- **DEC-25.8** The order page shows the stepper (order, agreement when it applies, payment and receipt / payment (invoice), packaging and serials, delivery, tax invoice for B2B, closed) with text states (done, in progress, waiting, not started) and the next action with the responsible role.

### 25.5 Build status (2026-10-18)
Built and tested (`tests/Feature/Orders/SalesFlowTest.php`, 7 tests; delivery, payment, invoice, agreement and fulfilment tests updated; full suite green): migration `add_sale_type_and_packaging_to_orders`, `SaleType`, `OrderStatus` packing/packed, `InvoiceType::SalesReceipt`, `ChangeSaleType`, `StartPackaging` (replaces StartFulfilment), `Packaging` (complete/reopen), packing-list locks in `ManageDeliveryNote`/`ClaimSerials`/`ManageSerials`, `IssueInvoice::salesReceipt`, retail closing in `CloseOrder`, `OrderFlow` stepper, `PackagingQueue`, `documents/sales_receipt` print, policies (`startPackaging`, `changeSaleType`, `issueReceipt`, `completePackaging`, `reopenPackaging`), customer form sale type, translations uz/ru/en. No outbound call to 1C was added.

Proposed skill updates (not applied; skills are agent configuration and need the user's approval): `order-management` — replace the order status line with "new → confirmed → packing → packed → partially_delivered/delivered → invoiced (B2B) → closed | cancelled; gates G2 payment rule, G3 all serials, G4 packed"; `agreement-contracts` — add "retail orders need no agreement unless one is added (Spec 06 DEC-25.3)".

**Update 2026-10-19 (Spec 03 section 22):**

- Orders, agreements, invoices, payments, ledger entries and sold serials keep `customer_id`. It is now the organization id; all these records point at organizations with the customer role.
- Purchase orders keep `supplier_id`, which points at the supplier profile (`counterparties`). That profile belongs to an organization.

**Update 2026-10-19 (Spec 09):**

- Posting a delivery note now also creates the customer's equipment rows (`customer_assets`) for each serial sold. The serial status machine of section 21 is unchanged.
- A billable repair that is closed outside warranty adds the outbox event `service_ticket.closed` (feed `orders`). It is available for 1C to pull and is documented in the OpenAPI spec.

> **Spec 03 section 24 note (2026-10-19).** Customers and suppliers/carriers are one **Partner** table (`partners`) with roles. Links that mean the organization are `partner_id` (contacts, supplier profile, equipment, service tickets, requests, catalog rules); links to the customer role keep `customer_id`. The 1C contract (paths, scopes, fields, feeds) is unchanged.

### 21.10 Item page serial tools (2026-10-19)
- The item page always shows a "Serial numbers" card: tracking off → explanation, and the switch for roles with `updateSerialTracking` (finance, admin, stock manager / warehouse head), otherwise who can switch it on; tracking on → counts per status, searchable paged list, "Add / import serials" (existing dialog, item locked), "Download template" (prefilled for the item), "Export serials" (scope "all" only), "Import history" (registration receipts of the item: who, when, rows).
- Entry stays with stock roles (`serials.register`); admins keep no register right (SoD) and see "Serial entry is done by the stock manager". Dev login for testing: `stock@crm.test` (Stock manager, seeded).
- Edit (available/quarantined only): number typo, warehouse, warranty date, note; a reason is required; normalized and uniqueness-checked (BR-SN); `serial_events` `corrected` + item audit. Sold/reserved serials are not editable; the stock adjustment for corrections and write-offs (with approval) is specified but not built yet.
- Bulk move of available serials to another warehouse with a reason (`transferred` events); only the CRM registry moves, 1C stock is moved in 1C.

## 26. Goods receipts originate in 1C (user decisions 2026-10-19)

### 26.1 Decision and scope
- Supplier goods receipts are **always created in 1C**. 1C pushes them to the CRM (26.2); the CRM never creates a supplier receipt. The stock manager only completes the receipt: received quantity per line (shortage / overage against the 1C expected quantity is flagged on the line), damaged quantity with a note (damaged units are quarantined), serial numbers; then marks it checked and posts it.
- The CRM still creates **customer returns** (of a posted delivery note), **opening balances** and the catalogue **serial registration** (section 24.1). These are the only kinds offered by "New return or opening balance"; `ManageGoodsReceipt::create` refuses `supplier_delivery` (`receipt_from_1c_only`), and lines of a 1C receipt cannot be added or removed in the CRM (`receipt_lines_from_1c`).
- The posted result is available for 1C to pull from the `warehouse` feed (`goods_receipt.posted`; no serials, DEC-19). A 1C receipt cancelled in the CRM is available for 1C to pull as `goods_receipt.cancelled`.
- Permissions: `stock.receive` (stock manager, warehouse head) completes, checks and posts; it no longer creates supplier receipts. `stock.adjust_approve` (warehouse head, manager) releases holds, cancels 1C receipts, approves over-receipt above the tolerance and resolves 1C review flags.

### 26.2 Inbound endpoint (Spec 02 rev. 5)
- `POST /goods-receipts` (scope `warehouse:write`, inbound switch, rate limit `onec-api`): envelope `{batch_id, sent_at, receipts[1..50]}`, idempotent by `batch_id` (stored response replayed), each receipt in its own transaction; `PUT /goods-receipts/{crm_id}` updates one receipt.
- Receipt: `id` (1C GUID), `number`, `date`, `updated_at?`, `crm_id?`, `version?`, `warehouse_id` (1C GUID), `supplier {tax_id, name?, full_name?, country?}`, `lines[1..500] {line_id (1C row GUID), item {id, code?, name?, unit_id?}, expected_qty}`, `is_deleted?`.
- **Self-contained push.** Supplier by tax ID only; `tax_id` missing → `tax_id_required`, nothing created. Unknown supplier → partner created with the supplier role (customer role off), "Sync with 1C" on, `needs_review` with `review_reason = created_from_1c_receipt`, a supplier profile in status `prospect`, owner = configured default / first manager / first admin (`no_owner` if none). An existing partner with the tax ID is used as it is (roles untouched). Unknown item (1C GUID) → item created from `code`, `name`, `unit_id` (unit must exist: `unknown_unit`; code used by another item: `item_code_taken`), type product, default VAT, not serialized, not on the portal, `needs_review`. Any error rolls the whole receipt back (no half-created supplier or item). Every creation is in the activity log; the batch is in `inbound_batches` (endpoint `goods_receipts`).
- Non-serialized lines post quantity only; received quantity starts at the expected one.

### 26.3 Document linking (all documents 1C pushes; receipts first)
- Every answer returns `crm_id`, CRM `number` and `version` and echoes the 1C `id`.
- Matching: `crm_id` first (must belong to a 1C receipt with the same 1C id: else `id_mismatch` / `not_from_1c`), then the 1C id; no match = create.
- Optimistic checks: a `version` lower than the CRM `sync_version` → `stale_version`; an `updated_at` older than the stored one → `stale_update`. Same content (hash) → `unchanged`. Result flag: `created | updated | unchanged` (+ `cancelled`, `cancel_requested`, `failed`).
- Updates are accepted only before posting. Posted receipt → refused `receipt_posted`, nothing rewritten, `needs_review` with `onec_update_after_posting` and the 1C content in the activity log, so the stock manager proposes a correction (adjustment, section 21.4) and the warehouse head resolves the flag with a note. Cancelled receipt → `receipt_cancelled`.
- Serial-linked data is never rewritten: a line with entered serials keeps its item and cannot be removed (`line_has_serials`). A lower expected quantity is accepted and shows as an overage at checking (the former `qty_below_serials` rule was dropped on 2026-10-19 when serialized quantities started to follow the serials).
- 1C delete/cancel (`is_deleted`): no serials entered → cancelled (system transition `cancelled_in_1c`, nothing published back); serials entered → `cancel_requested`, `needs_review` with `cancel_requested_by_1c`; the warehouse head cancels (entered serial rows voided) or keeps the receipt and resolves the flag.
- An update to a `checked` receipt sends it back to `in_progress` (transition reason `updated_from_1c`).

### 26.4 Goods receipt statuses (`GoodsReceiptStatus`; delivery notes keep `StockDocumentStatus`)
| From → to | Who | Notes |
|---|---|---|
| (push) → `received_from_1c` | 1C (system) | Lines read-only until started |
| `received_from_1c` → `in_progress` | stock manager | "Start receiving" |
| `in_progress` → `checked` | stock manager | Same completeness checks as posting (serials = received, reasons for discrepancies, damaged ≤ received, damage note) |
| `checked` → `in_progress` | stock manager | "Reopen" |
| `checked` → `posted` | stock manager; warehouse head when over-receipt exceeds the tolerance | Creates serials, outbox `goods_receipt.posted` |
| `received_from_1c` / `in_progress` / `checked` → `on_hold` | stock manager, reason required | Discrepancy to clarify |
| `on_hold` → `in_progress` | warehouse head | "Release hold" |
| any open → `cancelled` | warehouse head for 1C receipts (reason required); stock manager for CRM kinds | Entered serial rows voided; 1C receipts: outbox `goods_receipt.cancelled` |
| `posted`, `cancelled` | — | Final |

- Short path for CRM-created kinds (return, opening balance, serial registration): created `in_progress`, posted directly (no checked step).
- Lines, quantities and serials change only in `in_progress`. Every transition is written to `status_transitions` (who, from, to, when, reason) and the activity log.
- UI: status filter, origin filter (1C / CRM) and "Needs review" filter on the list; status badges; stepper on the receipt page (1C path: received from 1C → in progress → checked → posted; CRM path: in progress → posted; on hold / cancelled shown as a badge); move buttons only for allowed moves; review panel for 1C flags; history.
- Migration: drafts → `received_from_1c` (1C origin, no serial rows) or `in_progress`; `posted` and `cancelled` unchanged; status column widened to 20.

### 26.5 Outbox payload additions (`goods_receipt.posted` / `.cancelled`)
`status`, `onec_document {id, number, version}` (null for CRM kinds), `supplier_tax_id`; per line `line_id` (1C row GUID), `expected`, `received`, `damaged`, `discrepancy` (received − expected), `discrepancy_reason`, `note`. Serials only for returns or when `serials.outbox_receipt_serials` is on (DEC-19 unchanged).

### 26.6 Mismatches with earlier text (resolved by this section)
- 21.4 listed `supplier_delivery`, `purchase_order` and `shipment` as CRM-created sources with `draft → posted`. Supplier receipts are now 1C-originated; `purchase_order` / `shipment` sources (Spec 03/08) are not built and, when they are, must come through the 1C push too (open question).
- DEC-19 said receipts are "CRM documents published to 1C". A 1C-originated receipt is a 1C document completed in the CRM; the CRM result is still only available for 1C to pull (no serials), 1C stays the quantity truth.
- 21.9 "1C acks `posted_lines`" still applies to the CRM result event.
- Spec 02 rev. 4 says only customers are exchanged. Rev. 5 adds an inbound supplier creation inside the receipt push; the auto-created supplier has "Sync with 1C" on as decided, but it does not enter the customer outbox (customer role only), so the switch has no outbound effect today.
- Items are 1C-owned (`/items`). A receipt may create a minimal item flagged for review; the next `/items` push for the same GUID completes it. The unit must already exist in the CRM.

### 26.7 Build status (2026-10-19)
Built: migrations (origin and 1C fields, review flags on partners/items/receipts, sync version, status widening and value migration), `GoodsReceiptStatus`, `TransitionGoodsReceipt`, `GoodsReceiptImporter`, `GoodsReceiptExchangeController` (POST/PUT), OpenAPI and the 1C guide (EN/RU), screens, lang uz/ru/en, tests. Not built: correction (adjustment) flow for posted receipts (the review flag records the request), a partner/item "needs review" queue screen, linking for other pushed documents (payments keep their own matching).

### 26.8 Serialized lines: quantities follow serials, damage per serial (2026-10-19)
- For serialized lines the received quantity is read-only and equals the number of serial numbers entered (live count, recounted on every add/remove and again when checking/posting); missing = expected − received is shown, with a shortage or overage badge. Non-serialized lines keep the editable received quantity.
- Damage is recorded per serial: each serial row has a "damaged" flag and an optional damage note (`document_line_serials.is_damaged`, `damage_note`). The damaged quantity of a serialized line is the count of flagged serials (read-only); only flagged serials become `quarantined` on posting, the others `available`. Non-serialized lines keep the damaged quantity field. The line condition (good / mixed / damaged) is derived.
- Checking/posting: no quantity typed by hand on serialized lines; received ≠ expected needs a discrepancy reason; more than expected beyond the over-receipt tolerance needs the warehouse head; damage needs a note on the line or on every damaged serial.
- Paste: a line "serial; damaged; note" (the word damaged in uz/ru/en, or yes/да/ha) flags the unit. CSV/XLSX: optional column `damaged` (1 / yes / x / damaged) next to `note`; the template has the column.
- The outbox payload derives `received`, `damaged` and `discrepancy` of serialized lines from the serial rows; with serials enabled it also lists `damaged_serials`.
- Dropped: `qty_below_serials` (the stock manager cannot type a serialized quantity any more; a lower 1C expected quantity is an overage).

## 27. Stock-off act: write-offs and damage (user decision 2026-10-19)

### 27.1 Decision and how it fits the stock adjustment of 21.4
- Every stock-off event is its own document, the **stock-off act** (`SO-YYYY-NNNNN`), instead of living inside a receipt or a generic adjustment.
- **One design, two documents:**
  - **Stock-off acts** cover everything that leaves stock other than by sale.
  - **Stock adjustments** (21.4, still not built) are narrowed to the remaining cases:
    - `found` (registering new serials);
    - `serial_correction` (replacing a serial on a posted delivery note);
    - `qty_correction` (non-serialized, informational);
    - `status_change` (inspection release).
  - The adjustment type `write_off` is replaced by the stock-off act.
  - Sold or reserved serials cannot be written off on an act. Correcting them stays with the adjustment `serial_correction`.
- Mismatch with 21.4: the adjustment had a single flow (draft → pending_approval → posted/rejected) for all five types. The act has its own flow (27.3), a reason list, a value limit and a supplier claim.

### 27.2 Content and creation
- **Reasons** (`StockOffReason`):
  - damaged at receipt; damaged in storage; damaged in transit / delivery;
  - lost; theft; expired / obsolete; quality rejection;
  - internal use / demo; sample; customer return rejected;
  - other.
  - The reason is required. "Other" needs a note when the act is submitted.
- **Header:** warehouse, reason, note, link to the goods receipt and the supplier (receipt damage), supplier claim, estimated totals per currency, approval level, who/when for every step.
- **Lines:** item and quantity.
  - Serial-tracked items: specific serials are picked and the quantity is their count.
  - A serial can be picked if it is available, quarantined or returned, belongs to the item and is at the act warehouse. Sold or reserved serials are refused.
  - A serial can be on only one open act (unique `active_key`).
  - Optional link to the goods receipt line.
- **Estimated value:** per line from the warehouse stock cost, i.e. the 1C push's total cost divided by the quantity, rounded half-up, in the cost currency.
- **Evidence:** photos and files (PDF/JPG/PNG, private attachments, downloads logged), addable while the act is open.
- **Automatic creation:** posting a goods receipt with damaged units opens a DRAFT act.
  - Reason: damaged at receipt.
  - It links the receipt, its lines and the flagged serials (now quarantined), plus the supplier.
  - The creator is the user who posted the receipt.
  - At most one act per receipt.
  - If inspection releases the units, the act is cancelled.
- **Manual creation:** the stock manager creates an act for any other event (Warehouse > Stock-off acts).

### 27.3 Statuses (`StockOffStatus`) and roles
| From → to | Who |
|---|---|
| draft → submitted | stock staff (`stock_off.create`); needs at least one line with a quantity, and a note for "other" |
| submitted → approved / rejected | warehouse head or manager (`stock_off.approve`); from the value limit the CEO (management head) or finance (`stock_off.approve_high`); never the creator or the submitter (SoD); rejecting needs a reason |
| rejected → draft | stock staff (rework) |
| approved → posted | stock staff (`stock_off.post`) |
| draft / rejected → cancelled | stock staff, reason required |
| submitted / approved → cancelled | an approver, reason required |
| posted, cancelled | final; posted acts are immutable |

- **Value limit:** `stock_off.high_approval_from_minor`, per currency, in Settings > Accounting. Defaults: UZS 10 000 000, USD/EUR 1 000, RUB 100 000. It is checked against the estimated totals when lines change and again on submit.
- **Status history:** every move is written to `status_transitions` and the activity log.

### 27.4 Posting effects
- Picked serials become `written_off` (from available / quarantined / returned), with a `written_off` serial event referencing the act. This releases the quarantine of damaged receipt units.
- The CRM stock mirror (`item_stocks.quantity` of the act warehouse) is lowered by the line quantity.
- 1C stays the quantity truth: its next stock push overwrites the mirror. The write-off itself must be posted in 1C from the outbox event.
- Outbox `stock_off.posted` (feed `warehouse`, ability `warehouse:read`) is available for 1C to pull. The CRM never sends it.
  - Payload: number, date, reason, note, warehouse GUID, linked receipt (CRM id, number, 1C document id), supplier tax ID, claim, totals.
  - Lines carry item GUID, accountant code, quantity and value. Serials are included only with `serials.outbox_receipt_serials` (DEC-19).

### 27.5 Supplier claim (receipt damage)
- Applies only to acts linked to a receipt.
- Claim status: none / open / settled / rejected, with a reference and a note.
- Opening a claim needs the reference. Settled or rejected records the date.
- Editable in every status except cancelled, including posted: the claim follows the write-off.
- Who: stock staff, the warehouse head and the purchase department (`stock_off.claim`).

### 27.6 UI, print, permissions
- Warehouse menu: Packaging, Delivery notes, Goods receipts, **Stock-off acts**, Stock reconciliation.
- **List:** filters for status, reason and claim; search by number or claim reference.
- **Act page:**
  - stepper and buttons only for allowed moves;
  - lines with serial picking and estimated value;
  - evidence files, claim panel and history.
- **Print:** through DocumentRenderer in uz/ru/en (`documents.stock_off`), with lines, serials, value and signature blocks.
- **Permissions** (config/permissions.php):
  - `stock_off.view`: manager, management, purchase, stock, warehouse head, accountant, finance;
  - `stock_off.create` and `stock_off.post`: stock staff and warehouse head;
  - `stock_off.approve`: warehouse head and manager;
  - `stock_off.approve_high`: management head and finance;
  - `stock_off.claim`: purchase, stock staff and warehouse head.
  - Admin and sales have no rights.

### 27.7 Mismatches and open points
- 21.4 listed `write_off` as an adjustment type. It is replaced by the act (27.1).
- "Stock quantity reduced": the CRM stock mirror is lowered until 1C's next stock push, which overwrites it. Real stock is reduced only when 1C posts the write-off from `stock_off.posted`.
- The approval is a single step by the right level: warehouse head, or CEO/finance above the limit. It is not head first and then CEO.
- The value is estimated from the 1C stock cost. Items without a cost have no value and are approved by the warehouse head unless another line exceeds the limit.
- Not built:
  - claim amounts / credit notes;
  - write-off of sold serials (adjustment `serial_correction`);
  - ~~the adjustment document itself (`found`, corrections)~~ built 2026-10-21, see section 32.

## 28. Power of attorney checkbox (user decision 2026-10-19, simplified)
- Order columns: `poa_required`, `poa_received_at` / `poa_received_by` / `poa_note` (the checkbox "Power of attorney received" with who, when and an optional note), `poa_waived_at` / `poa_waived_by` / `poa_waiver_reason` (override).
- Default: required for B2B orders of legal entities, government bodies and sole proprietors; never for retail orders or private persons (also recomputed when the sale type changes before confirmation). Orders created before this change are not required (no in-flight order is blocked).
- Rights (config/permissions.php):
  - `orders.poa_tick`: the order's sales owner, the sales head, a manager, an accountant;
  - `orders.poa_untick`: manager, accountant, finance, with a reason;
  - `orders.poa_requirement`: sales head, manager, finance, with a reason;
  - `orders.poa_override`: manager or finance, with a reason, to allow the tax invoice without the power of attorney.
  - Every change is in the order's activity log.
- Gate: while required and neither ticked nor overridden, registering a tax invoice (счёт-фактура) or waybill (`IssueInvoice::registerTaxDocument`) is refused with `poa_missing`. Proformas (счёт на оплату), sales receipts and acts are not gated.
- Flow (section 25): `… → delivery → power of attorney → tax invoice → closed` for B2B orders that require it. Orders list queue "Power of attorney missing".
- 1C: `power_of_attorney {required, received, received_at, note, overridden}` is in the order, invoice and delivery payloads, available for 1C to pull (the CRM sends nothing).
- Dropped from the first proposal (user: "a checkbox is enough"): the power-of-attorney record with number/dates/representative/scan, statuses and expiry, portal upload, printable template, receiver check at delivery. The partly started record (model, enum, migration) was removed before anything shipped; the dev database was rolled back and re-migrated.
- How it relates to existing documents: the CRM already has the tax invoice as a registered document (`InvoiceType::TaxInvoice`, number, date, ESF id, lines; issued in 1C / e-invoicing and registered here, section 6.6). It is kept and gated; no separate "tax invoice issued" checkbox was added because registration already records number and date and moves the order to `invoiced`.

## 29. Payment for orders: information signal for the warehouse (user decision 2026-10-19)
- The CRM payment is an information signal (what may be shipped); the books stay in 1C.
- Form Payments > New payment (`payments.for-orders`): partner → that partner's unpaid / partly paid / overdue orders (number, date, total, paid, outstanding) with checkboxes → amount per order (filled with the outstanding amount, editable) → date (today), method, reference (bank methods), note, optional proof.
- One payment per ticked order in the order currency, allocated to it at once; never more than outstanding (no advances, no unallocated balance from this form).
- Kept unchanged (not weakened): open period, duplicate guard, proof above the threshold, second-person approval above the approval threshold (the payment keeps `planned_order_id` and is allocated when approved), immutable records corrected by a void with a reason, `payments.record` = accountant / finance only, `payment.recorded` available for 1C to pull.
- Warehouse signal (packaging queue, delivery note): Paid — ready to ship / Partly paid (paid of total) / Not paid — released by <manager> / Not paid — credit terms / Not paid. Posting a delivery note of an order that is not fully paid notifies accountants and the order owner (`shipped_unpaid`, in-app; Telegram on by default). Orders list queue "Shipped, not paid".
- The detailed payment form (`payments.create`), allocations on the payment page, refunds, FX allocation, matching of 1C payments and the receivables screens stay as they are (see the report: candidates to hide if the user confirms the CRM never needs them).

## 30. Preparation and shipment before payment (user decision 2026-10-19)
- Interpretation (to confirm): "заготовка" = packaging (order status `packing`: picking, serial numbers, packing list), "отгрузка" = posting the delivery note. Not procurement.
- Payment status stays its own dimension (`payment_status` unpaid / partially paid / paid / overdue) next to the fulfilment status; nothing new was added to the status machine.
- Already built and now documented as the rule: B2B orders without prepayment (credit terms) start packaging without payment; when prepayment is required (or a retail order is not fully paid) the hold `prepayment_missing` is set and a manager or finance (`orders.override_hold`) releases it with a reason — that is the "release before payment" mark (who and why is stored on the order and shown to the warehouse). Posting the delivery note is not blocked by payment; it warns and notifies (section 29).
- Not built (defaults): no extra value-limit approval for releasing before payment; no separate status "shipment".

> **Confirmed by the user (2026-10-19):** (1) write-offs and damage are stock-off acts (section 27); stock adjustments, when built, cover only found units and corrections; (2) the CRM only lowers its local copy of 1C stock, real stock falls when 1C posts the write-off it pulls (`stock_off.posted`); (3) stock-off approval is a single step (warehouse head, or CEO / finance above the value limit); (4) "заготовка" = packaging (picking, serial numbers, packing list) and "отгрузка" = posting the delivery note (section 30). Still open: who may record payments (kept: accountant / finance) and which payment features to hide (section 29).

## 31. Orders without confirmation; manual orders (user decision 2026-10-20)

### 31.1 Built now (no schema change)
- **No confirmation step.** Right after creation the order is started with the former confirmation rules (`StartOrder` → `ConfirmOrder`): stock check, shortages flagged as backorder automatically (acknowledged by the system, recorded as `shortage_acknowledged`), reservations, payment schedule, `order.confirmed` available for 1C to pull. The status value `confirmed` is kept as the internal "working" state (renaming it needs a status migration, 31.3).
- An order stays `new` only when it cannot start: customer archived / deleted in 1C, a credit-limit, overdue or manual hold. The hold is stored and shown; after a manager or finance releases it, "Start order" (former "Confirm order") starts it.
- Because nothing waits for a confirmation any more, delivery details and the sale type stay editable until packaging starts (`Order::isBeforeFulfilment()`); the sale type also only before any payment counts. Changing the sale type of a started order regenerates the payment schedule and the agreement hold of the new type.
- Gates hanging off the old confirmation are unchanged: prepayment / full payment and the agreement are checked when packaging starts (hold `prepayment_missing`, `agreement_unsigned`, released by a manager or finance with a reason); the power of attorney gates the tax invoice (section 28).
- Printable order form: the existing order print (`orders.print`, DocumentRenderer, language from the partner) is available as soon as the order exists.

### 31.2 Designed, waiting for the schema change (31.3)
- **Manual order** (`orders.create`: sales for own customers, sales head, manager; never admin): partner with the customer role (never a lead), sale type B2B/B2C defaulted from the partner (retail for private persons), lines from the catalogue priced exactly like offer lines (one sale price, VAT, discounts within the user's discount limit; a discount above the limit is refused on this path because there is no offer approval step — the user must make an offer for that), delivery method (delivery / self pick-up), delivery address, warehouse, note. Snapshot immutable after creation as for offer orders; starts immediately (31.1).
- **B2C / retail flow:** payment (cash / card / transfer, sales receipt) → packaging (serials) → delivery note, or for self pick-up a handover note with who collected and their ID; no agreement, no power of attorney.
- **B2B flow:** agreement → power of attorney (when required) → payment signal → packaging → delivery → tax invoice. Framework agreement (long-open question) — proposed default: an order of a customer with an active framework agreement is linked to it automatically and needs no per-order agreement; without one, a per-order agreement is generated as today. The manager can still choose "new agreement for this order".
- Orders list "New order" button (with permission), source badge (offer / manual), offer link when it exists; customer-portal orders and requisitions (Spec 10) unchanged; 1C payloads get `source` and `delivery_method` (available for 1C to pull).

### 31.3 Schema changes needed (blocked until the migration squash is finished)
1. `orders.offer_id` nullable; uniqueness kept for non-null values; `orders.offer_version` and `offer_accepted_hash` nullable.
2. `orders.source` string(10) not null default `offer` (`offer` | `manual`), indexed.
3. `orders.delivery_method` string(10) not null default `delivery` (`delivery` | `pickup`).
4. `orders.warehouse_id` nullable FK (warehouse chosen on a manual order; lines keep their own).
5. `orders.note` text nullable (internal note of a manual order), if not already present.
6. `deliveries.pickup_person` string(160), `deliveries.pickup_document` string(60) nullable (self pick-up handover).
7. Optional: rename status `confirmed` → `in_progress` (data migration of existing rows, `status_transitions`, outbox event name kept `order.confirmed` for 1C) — not needed for the behaviour above; proposed to skip.
8. Data: existing orders in `new` without blocking holds may be started once by a one-off command (no schema), at the user's choice.

### 31.4 Built (2026-10-20, user decisions)
- Schema (edited in the squashed baseline, nothing deployed yet): `orders.offer_id` and `orders.offer_version` nullable (unique `offer_id` kept, NULLs allowed); `orders.source` (default `offer`, index `orders_source_index`), `orders.delivery_method` (default `delivery`), `orders.warehouse_id` (nullable FK warehouses, null on delete, index `orders_warehouse_id_index`), `orders.note`; `deliveries.pickup_person`, `deliveries.pickup_document`.
- Manual order screen `orders.create` (`CreateManualOrder`): customer-role partner the user owns (`orders.create`; admin excluded), sale type defaulted (retail for private persons), delivery / self pick-up, address (required for delivery), one warehouse (all lines), note, lines from the catalogue priced like offer lines; discount above the user's limit and below-cost lines are refused; framework agreement auto-linked unless "new agreement for this order". The order starts at once; the order print is available immediately and shows the delivery method (no "based on offer" line).
- Self pick-up: the delivery note screen asks who collected and their document; posting is refused without them (`pickup_details_required`); the print becomes the handover act. Flow step "Pick-up" and next action "hand the goods over".
- Orders list: "New order" button (permission), source shown per order; order page: source badge, offer link only when there is one, delivery method, warehouse, note.
- 1C: order payload `source`, `delivery_method`; delivery payload `delivery_method`, `pickup {person, document}`, `order.source` — available for 1C to pull.
- One-off command `php artisan orders:start-new` (dry run, lists orders) and `--apply`: starts existing `new` orders without a blocking hold.
- Not added: notifications for manual orders (activity log `created_manually` only); item image snapshot on manual order lines.

## 32. Build status (2026-10-21): stock adjustments, order amendments, payment reminders

### 32.1 Stock adjustments (21.4, narrowed by 27.1)
- Document `ADJ-YYYY-NNNNN`, types `found`, `serial_correction`, `qty_correction` (`StockAdjustmentType`). `write_off` is the stock-off act; `status_change` and typo fixes stay on the serial tools.
- Flow `draft → pending_approval → posted`, `rejected → draft`, cancel from draft / pending / rejected. Stock staff request (`stock_adjust.create`); the warehouse head or a manager approves (`stock.adjust_approve`) and approving posts it in the same step; never the requester or the submitter.
- `found`: serial-tracked item + serial number; a written-off serial of the same item comes back `available` on the same row; the stock mirror rises by one.
- `serial_correction`: against a posted delivery note; the replacement must be `available` in the note's warehouse; the old unit returns to `available` or `quarantined` (chosen per line); the delivery serial row becomes `replaced`, a new `posted` row takes its place, sale data and warranty move to the new serial, the customer's equipment entry follows it.
- `qty_correction`: non-serialized item, signed quantity; posting is refused when the mirror would go below zero.
- A serial sits on one open adjustment at a time. Outbox `stock_adjustment.posted` (feed `warehouse`), one event for all types (mismatch with 21.4, which named `delivery.serials_corrected` for corrections; resolved here, the payload carries `type`, `delivery`, `serial` and `old_serial`).
- Screens: Warehouse > Stock adjustments (list, new, document page). No print yet.

### 32.2 Order amendments (6.4, AC-10)
- `order_amendments` (+ `order_lines.added_by_amendment_id`), one open amendment per order, allowed in confirmed, packing, packed and partially delivered orders. Changes: `qty_change`, `price_change` (the line **discount**; unit prices come from the price list), `add_line`, `remove_line`, `close_short`. Date, address and payment-terms changes stay on their own screens.
- Guards: a quantity never goes below delivered + cancelled + what sits on an open packing list; a line with delivered, invoiced or packing-list quantity is closed short, not removed; serial-tracked quantities are whole; a discount never takes a line below cost; the order must keep something to deliver; the new total is not below paid or invoiced.
- Approval: a discount above the requester's limit (`offers.discount_limits_bp`) waits for an approver with `orders.amend` on the order and a limit that covers it; anyone who may amend can reject; never the requester. Within the limit the amendment applies at once.
- Applying: amounts recomputed on `quantity − cancelled_qty` with the offer line math, reservations follow the open quantity, the payment schedule is regenerated and coverage recomputed, the credit hold re-evaluated, `revision` + 1, `order.amended` for 1C (the whole order is resent; lines carry `cancelled_quantity`). A removed line keeps its row with the whole quantity cancelled. Closing short the last open quantity of a partly delivered order moves it to `delivered`.
- A binding agreement whose price or total changed gets an addendum draft and `addendum_pending` (blocks closing, not delivery); signing or cancelling the addendum clears it. If no addendum can be generated (no template), the amendment page tells the rep to add one by hand.
- Screen: `/orders/{order}/amendments` (button "Amend order" on the order page).

### 32.3 Payment reminders (BR-AC.17, AC-30)
- `dunning_events` with unique (schedule item, stage, channel). Stages `pre_due` (3 days before), `due_1`, `due_7`, `due_14`, `due_30`; an item sends only the highest stage it has reached.
- `php artisan accounting:dunning`, daily 09:00 Tashkent. Channel `in_app`: the order owner always, plus one digest to the accountants when items newly reached an overdue stage. Channel `email`: only when `accounting.customer_reminders_enabled` is on — e-mail to the billing contact in the order's document language and an in-app message to the customer's portal finance / admin users.
- Printed reminder letter `/receivables/{customer}/reminder/print` (uz/ru/en, scoped to the orders the user may see), linked from the receivables page. Statement print, payment-schedule print and Telegram customer reminders stay open.

