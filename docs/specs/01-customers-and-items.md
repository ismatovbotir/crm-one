# Spec 01 — Foundation, Customers/Contacts and Item Catalog (Phase 0 + Phase 1)

| | |
|---|---|
| Status | Draft, awaiting confirmation of decisions in section 12 |
| Owner | Product (B2B sales CRM) |
| Date | 2026-10-16 |
| Stack | Laravel 13 (PHP ^8.3), Livewire 4, PHPUnit 12, SQLite (dev/test); production DB TBD (see D-10) |
| Depends on | Nothing (first spec) |
| Amended by | **Spec 02 — 1C integration** (`docs/specs/02-1c-integration.md`), see "Amendments from Spec 02" below. Where this document and Spec 02 disagree, Spec 02 wins. Also amended by **Spec 03** (`docs/specs/03-organization-users-portal-tenders.md`): user types (internal/portal), `staff` role, departments, permission map (G1 and section 3) |
| Feeds | Spec 02 1C integration, 03 Organization/portal/tenders, 04 Leads, 05 Offers, 06 Orders, Agreements, Accounting & Stock (07 unused) |

### Repo baseline (verified 2026-10-16)
- Migrations: `users` (+ `password_reset_tokens`, `sessions`), `cache`, `jobs`. No domain tables.
- Models: `App\Models\User` only. No enums, policies, Livewire components, routes beyond `welcome`.
- No authentication UI/scaffolding. No role concept on users.
- Tests: PHPUnit (`tests/Feature`, `tests/Unit` examples only). `.env` uses `DB_CONNECTION=sqlite`.
- `CLAUDE.md` asks for Laravel Boost (`composer require laravel/boost --dev && php artisan boost:install`) before application changes; not installed yet. It is task 0.1 below.

### Amendment rev. 4: one sale price and one purchase price (2026-10-18)
Business decision: "we have only one price, no need for several sell prices; one in and one out price; the stock table has stock cost". This **supersedes every price-list rule below** (G4, US-1.5 price-list part, US-3.1..3.4, BR-8, BR-9, BR-10, sections 6.3 `price_list_id`, 6.8, 6.9, 7 price-list screens, matrix rows on price lists, Spec 02 A4/A7):
- `items` carry `sale_price_minor` + `sale_currency`, `purchase_price_minor` + `purchase_currency` (integer minor units, nullable) and `prices_include_vat` (sale price basis); all mastered by 1C (Spec 02 BR-I.14).
- `item_stocks` carry `cost_minor` (total stock cost of the quantity) + `cost_currency`; the **stock cost price** = weighted average Σcost/Σquantity over warehouses with positive quantity (Item::stockCostPrice()). Cost basis = stock cost price, else purchase price.
- `App\Services\Pricing\ItemPricing` replaces `PriceResolver`: sale price in the requested currency or `PriceNotFound`; inactive/archived/deleted-in-1C → `ItemNotSellable`; no customer, quantity or dated prices, no FX.
- Removed: `price_lists`, `price_list_items`, `customers.price_list_id`, the price-list screens, navigation entry, `price_lists.set_default` permission, customer price-list field and translations. Backfill: sale price taken from the former default price list (base currency first); then the tables were dropped (new migrations, reversible structure).
- Item page shows sale price (with VAT basis), purchase price, stock cost price and margin; stock tab shows cost per warehouse; the items list shows the sale price.

### Amendments from Spec 02 (1C integration, 2026-10-16)
1C is the ERP. **Units, item groups (categories), items, price types/prices and stock are mastered in 1C and pushed by 1C into a CRM API**; the CRM catalog is a read-only mirror. Customers are shared: existing 1C counterparties are pulled into the CRM, customers created in the CRM are pushed to 1C. Concretely:
- A1. Catalog CRUD (units, categories, items, price lists, price entries) is **removed**. Only CRM-local fields stay editable: `units.precision` (admin), `categories.sort_order`, `items.min_order_qty` / `items.order_step` (manager/admin), `price_lists.is_default` (finance/admin). Affects G3, G4, the catalog rows of the permission matrix, US-2.1..2.5, US-3.1..3.3, section 7 catalog screens, BR-4, BR-9, BR-14 and AC-2.x / AC-3.6..3.9 (see inline notes).
- A2. Every 1C-mastered table gets sync columns (`external_id` GUID unique, `external_code`, `external_version`, `external_updated_at`, `external_hash`, `synced_at`, `external_deleted_at`, `last_seen_snapshot_id`), Spec 02 section 11.1.
- A3. `items`: `sku` = 1C code with a **non-unique** index (1C owns uniqueness); new `article`, `is_vat_exempt`; **`base_price_minor` and `currency` are dropped** (1C items have no base price).
- A4. `price_lists` = 1C price types (+ `prices_include_vat`); `price_list_items` become dated rows keyed by (`price_list_id`, `item_id`, `min_qty`, `valid_from`); `min_qty` is always 0 from 1C (no tiers unless 1C adds them).
- A5. New tables `warehouses`, `item_stocks` (read-only stock). The "Inventory/stock" non-goal is narrowed to "no stock management in the CRM".
- A6. `customers`: `tax_id` index becomes **non-unique** (1C may contain duplicate INNs); the unique-INN rule applies only to customers created in the CRM (application validation, incl. archived). New columns `uuid`, `origin`, `legal_type`, `sync_status`, `sync_error`, `sync_error_fields`, `sync_attempts`, `sync_idempotency_key`, `sync_conflict_payload`, `last_pushed_at`, `merged_into_external_id`, `has_duplicate_tax_id` + sync columns. `tax_id` is immutable once the customer is linked to 1C. `price_list_id` is set from 1C (finance can no longer assign it while the integration is enabled). `contacts` get `uuid` + sync columns.
- A7. `PriceResolver` (BR-10): step 4 (item base price) is removed; per list it takes the row with the greatest `valid_from <= date`; result also returns `prices_include_vat`. D-3 (net vs gross) is now decided per 1C price type.
- A8. Build order: steps 1a–1c become Spec 02 increments I-2..I-4; step 1d becomes I-7/I-8. Later specs renumber: Leads = 04, Offers = 05, Orders = 06, Agreements = 07 (03 = Organization, users, portal & tenders). **Update 2026-10-18**: Orders, Agreements, Accounting and Stock/serial numbers are combined in Spec 06 (`docs/specs/06-orders-agreements-accounting.md`); number 07 is unused.

---

## 1. Problem

The CRM must run the chain **Items → Lead → Offer → Order → Agreement**, but nothing exists yet. Every later stage needs two things to be right from day one:

1. **Who we sell to**: a deduplicated customer (company) record with contacts and an owner. Without it, leads and offers are created against free-text company names, which produces duplicates, broken pipeline reports and contracts with wrong legal details.
2. **What we sell and at what price**: a catalog with units, tax rates and a deterministic price lookup. Without it, sales reps type prices into offers, discounts can't be measured, and price changes silently alter history.

Phase 1 also has to lay the cross-cutting rails (roles, money handling, audit log) that every later phase reuses. Getting these wrong now means data migrations later on live commercial records.

## 2. Goals / Non-goals

### Goals
- G1. Authenticated users with exactly one role: `admin`, `manager`, `sales`, `finance`, enforced by Policies.
- G2. Customers (companies) with contacts, owner, default currency, payment terms and optional assigned price list; duplicate prevention on tax ID and warning on similar name/email.
- G3. Catalog: categories (tree), units (with quantity precision), items (product/service) with SKU, VAT rate, active flag. **(Spec 02: read-only mirror of 1C; no base price.)**
- G4. ~~Price lists per currency; `PriceResolver`~~ (rev. 4: one sale + one purchase price per item, `ItemPricing`). **(Spec 02: price lists = 1C price types with dated prices; not editable in CRM.)**
- G5. Money stored as integer minor units + ISO currency; VAT as integer basis points; quantities as fixed decimals. No floats anywhere.
- G6. Audit trail (who/when/what changed) for customers, contacts, items, price lists and price entries.
- G7. Reusable building blocks for later phases: `Money` value object, `UserRole` enum, `LogsActivity` concern, `activity_log` table.

### Non-goals (explicitly out of Phase 1)
- Leads, offers, orders, agreements (roadmap, section 14).
- Stock management, reservations. (Spec 02: read-only stock balances per warehouse are received from 1C.)
- Bundles/kits, item variants/attributes, images and datasheets.
- Unit conversion (e.g. box ↔ pcs).
- Currency exchange rates / FX conversion. A price is only resolved in a currency that actually has a price.
- Customer-specific per-item prices outside price lists (achieved by assigning a dedicated price list to a customer).
- CSV/Excel import (Phase 1d, optional, see roadmap).
- Customer bank details and signatories (needed for agreements; added in Phase 5).
- Individuals (B2C) as customers. Customers are companies only.
- Self-registration. Users are created by admin.

## 3. Users & roles

`users.role` holds one `App\Enums\UserRole` value. Inactive users (`is_active = false`) cannot log in.

### Permission matrix (Policies)

| Action | sales | manager | finance | admin |
|---|---|---|---|---|
| View customers & contacts (all) | yes | yes | yes | yes |
| Create customer | yes (becomes owner) | yes | no | yes |
| Update customer / manage its contacts | own only | all | no | all |
| Change customer owner | no | yes | no | yes |
| Assign payment terms to customer | no | yes | yes | yes |
| Assign price list to customer (Spec 02: comes from 1C; editable only while integration disabled) | no | yes | yes | yes |
| Archive (soft delete) customer | no | yes | no | yes |
| Restore archived customer | no | no | no | yes |
| View catalog (categories, units, items, stock) | yes | yes | yes | yes |
| Create/update/activate/delete categories, items, units, price lists, price entries | no | no | no | no (Spec 02: mastered in 1C) |
| Edit item `min_order_qty` / `order_step` (CRM-local) | no | yes | no | yes |
| Edit unit `precision` (CRM-local) | no | no | no | yes |
| View price lists | yes | yes | yes | yes |
| Mark price list as CRM default per currency | no | no | yes | yes |
| View activity log of a record | yes | yes | yes | yes |
| Manage users & roles | no | no | no | yes |

Rationale: sales can see all customers (to avoid creating duplicates) but cannot change prices or assign cheaper price lists to their own accounts. Finance owns commercial terms; manager owns the catalog content.

## 4. User stories

Foundation
- US-0.1 As an admin I want to create users and assign a role so that access is controlled from day one.
- US-0.2 As any user I want to log in/out and reset my password so that I can work securely.
- US-0.3 As an admin I want to deactivate a user so that leavers lose access without losing their records.

Customers & contacts
- US-1.1 As a sales rep I want to create a customer with name, tax ID, address and default currency so that leads and offers reference one clean record.
- US-1.2 As a sales rep I want to be warned about possible duplicates (same tax ID, similar name, same contact email) before saving so that I don't create a second record for the same company.
- US-1.3 As a sales rep I want to add several contacts to a customer and mark one as primary so that offers can be addressed correctly.
- US-1.4 As a manager I want to reassign a customer's owner so that I can rebalance accounts or handle leavers.
- US-1.5 As finance I want to set a customer's payment terms and price list so that offers start from the right commercial conditions.
- US-1.6 As a manager I want to archive a customer instead of deleting it so that history is preserved.
- US-1.7 As any user I want to search customers by name, tax ID, email or phone and filter by owner so that I find accounts quickly.
- US-1.8 As any user I want to see who changed what on a customer so that disputes can be resolved.

Catalog (Spec 02: US-2.2..2.5 and US-3.1..3.2 are fulfilled in 1C; the CRM only displays the result. US-2.1 survives as "set precision", US-3.3 as "choose CRM default", US-3.4 unchanged.)
- US-2.1 As an admin I want to manage units with a quantity precision so that "pcs" accepts only whole numbers and "kg" accepts decimals.
- US-2.2 As a manager I want to organise items in nested categories so that reps can browse the catalog.
- US-2.3 As a manager I want to create a product or service item with SKU, unit, base price, currency and VAT rate so that it can be quoted.
- US-2.4 As a manager I want to deactivate an item so that it can no longer be quoted but old documents stay intact.
- US-2.5 As finance I want to change an item's base price with the change logged so that price history is auditable.

Pricing
- US-3.1 As finance I want to create a price list for a currency with validity dates so that seasonal or regional pricing is possible.
- US-3.2 As finance I want to define quantity tiers on a price list entry so that volume discounts are applied consistently.
- US-3.3 As finance I want to mark one price list as the default per currency so that customers without an assigned list still get list pricing.
- US-3.4 As a sales rep I want to check "what price would customer X pay for item Y at quantity Q today" so that I can answer customers before an offer exists (and so the resolver is visible and testable before Phase 3).

## 5. Workflow impact

- Phase 1 introduces **no status state machines**. Customer, item and price list lifecycles are flags (`is_active`) plus soft delete (archive). Status machines with `status_transitions` start in Phase 2 (leads).
- Handoff contracts established now and consumed later:
  - Lead → `customer_id`, `contact_id` (Phase 2).
  - Offer header defaults from customer: `currency`, `payment_terms_days`, primary contact, owner (Phase 3).
  - Offer line snapshot from item + resolver: `item_id`, `sku`, `name`, `unit_code`, `unit_precision`, `unit_price_minor`, `currency`, `tax_rate_bp`, `price_source`, `price_list_id` (Phase 3). Snapshots mean later catalog edits never change existing offers/orders.
- Archiving/deactivation is the only "removal" for anything that later phases will reference. Hard delete is admin-only and blocked when referenced.

## 6. Data model

Conventions for all tables:
- `id` bigint PK, `timestamps()`. Soft deletes where stated.
- Money: `*_minor` unsigned bigint in currency minor units (e.g. 1 250 000.00 UZS → `125000000`). Every money column sits next to a `currency` char(3) (ISO 4217, uppercase), either on the row or on its parent (price list).
- VAT: `tax_rate_bp` unsigned smallint, basis points (12% = `1200`), range 0..10000.
- Quantities: `decimal(15,3)`, cast to string/BCMath in PHP, never float.
- Enums: string columns backed by PHP backed enums; validated in Form Requests/Livewire rules (SQLite has no native enum; no DB enum types for portability).
- CHECK constraints are not portable through the Schema builder; range rules are enforced in validation + model guards and covered by tests.
- Foreign keys: `restrictOnDelete` unless stated; since we soft delete, FKs mainly protect against accidental hard deletes.

### 6.1 `users` (alter)
| Column | Type | Notes |
|---|---|---|
| role | string(20), default `sales`, index | `UserRole` enum |
| is_active | boolean, default true | inactive users cannot authenticate |

### 6.2 `activity_log` (new, shared by all later phases)
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| subject_type | string | morph class (use `Relation::enforceMorphMap`, short aliases like `customer`, `item`) |
| subject_id | unsignedBigInteger | |
| causer_id | FK users, nullable, nullOnDelete | null = system/console |
| event | string(40) | `created`, `updated`, `archived`, `restored`, `deleted`, `activated`, `deactivated`, `owner_changed`, `price_changed` |
| properties | json, nullable | `{ "old": {...}, "new": {...} }` only for changed attributes; secrets excluded |
| created_at | timestamp | no `updated_at` (append-only) |

Indexes: (`subject_type`, `subject_id`, `created_at`), (`causer_id`). Rows are never updated or deleted by the app.

### 6.3 `customers` (new, soft deletes)
| Column | Type | Notes |
|---|---|---|
| name | string(255) | display/trade name, required |
| name_normalized | string(255), index | lowercase, trimmed, quotes and legal-form tokens removed (LLC, LTD, OOO, MChJ, AJ, ...), single spaces; used for duplicate warnings |
| legal_name | string(255), nullable | full registered name for documents |
| tax_id | string(32), nullable, index (**non-unique**, Spec 02 A6) | digits only after normalization; uniqueness enforced in the app for CRM-created customers, including archived rows (see D-4); immutable once linked to 1C |
| country | char(2), default `UZ` | ISO 3166-1 alpha-2 |
| city | string(120), nullable | |
| address | text, nullable | |
| postal_code | string(20), nullable | |
| phone | string(32), nullable, index | stored normalized E.164 where parseable |
| email | string(255), nullable, index | lowercase |
| website | string(255), nullable | |
| industry | string(120), nullable | free text in Phase 1 |
| currency | char(3) | default from `config('crm.base_currency')` |
| payment_terms_days | unsigned smallint, nullable | 0..365 |
| price_list_id | FK price_lists, nullable, nullOnDelete | must match `currency` (validated) |
| owner_id | FK users, restrictOnDelete, index | |
| notes | text, nullable | |
| deleted_at | timestamp, nullable | archive |

Note: `price_list_id` FK requires `price_lists` to be created first; migration order in section 13.
Spec 02: plus `uuid`, `origin`, `legal_type`, sync status columns and sync columns (Spec 02 sections 11.1 and 11.5).

### 6.4 `contacts` (new, soft deletes)
| Column | Type | Notes |
|---|---|---|
| customer_id | FK customers, cascadeOnDelete, index | cascade only matters on admin hard delete |
| first_name | string(120) | required |
| last_name | string(120), nullable | |
| position | string(120), nullable | job title |
| email | string(255), nullable, index | lowercase |
| phone | string(32), nullable | |
| is_primary | boolean, default false | at most one primary per customer (app-enforced in a transaction) |
| notes | text, nullable | |

Rule: a contact must have at least one of email or phone.

### 6.5 `units` (new)
| Column | Type | Notes |
|---|---|---|
| code | string(16), unique | e.g. `pcs`, `kg`, `m`, `h`, `l`, `set`, `month` |
| name | string(64) | |
| precision | unsigned tinyint, default 0 | allowed decimal places for quantities, 0..3 |
| is_active | boolean, default true | |

Seeded: pcs(0), set(0), kg(3), l(3), m(2), m2(2), h(2), day(0), month(0).
Spec 02: units come from 1C (`code` = 1C/OKEI code, e.g. `796`); seeding is dev-only; `precision` is CRM-local, defaulted from an OKEI map. All 1C-mastered tables below also get the Spec 02 sync columns.

### 6.6 `categories` (new)
| Column | Type | Notes |
|---|---|---|
| parent_id | FK categories, nullable, restrictOnDelete, index | |
| name | string(120) | unique among siblings (app-enforced; NULL parent breaks DB uniqueness) |
| sort_order | unsigned int, default 0 | |

Rules (Spec 02: categories are 1C item groups; depth limit and sibling-name uniqueness are not enforced for synced rows; no manual moves/deletes): max depth 3; no cycles (a category cannot be moved under itself or a descendant); delete only when no children and no items.

### 6.7 `items` (new, soft deletes)
| Column | Type | Notes |
|---|---|---|
| sku | string(64), index (**non-unique**, Spec 02 A3) | = 1C item code as received (no normalization); uniqueness owned by 1C |
| article | string(64), nullable, index | Spec 02: 1C Артикул |
| is_vat_exempt | boolean, default false | Spec 02: 1C "без НДС" |
| name | string(255), index | |
| description | text, nullable | |
| type | string(20) | `ItemType`: `product`, `service` |
| category_id | FK categories, nullable, restrictOnDelete, index | |
| unit_id | FK units, restrictOnDelete, index | required |
| ~~base_price_minor~~ | removed by Spec 02 | 1C items have no base price; displayed price = default price list of the base currency |
| ~~currency~~ | removed by Spec 02 | |
| tax_rate_bp | unsigned smallint | from 1C `vat_rate` |
| min_order_qty | decimal(15,3), nullable | must respect unit precision |
| order_step | decimal(15,3), nullable | qty must be a multiple of step (Phase 3 enforces on lines) |
| is_active | boolean, default true, index | inactive items cannot be added to new offers |
| deleted_at | timestamp, nullable | |

Composite index: (`is_active`, `category_id`).

### 6.8 `price_lists` (new, soft deletes)
| Column | Type | Notes |
|---|---|---|
| code | string(32), unique | e.g. `PL-UZS-2027`, `DEALERS-USD` |
| name | string(120) | |
| currency | char(3), index | all entries are in this currency |
| valid_from | date, nullable | inclusive; null = open start |
| valid_to | date, nullable | inclusive; null = open end; must be >= valid_from |
| is_default | boolean, default false | at most one active default per currency (app-enforced in a transaction); CRM-local |
| prices_include_vat | boolean | Spec 02: from the 1C price type |
| is_active | boolean, default true | |
| notes | text, nullable | |

### 6.9 `price_list_items` (new)
| Column | Type | Notes |
|---|---|---|
| price_list_id | FK price_lists, cascadeOnDelete | |
| item_id | FK items, restrictOnDelete, index | |
| min_qty | decimal(15,3), default 0 | tier threshold; tier applies when qty >= min_qty |
| price_minor | unsigned bigint | in the list's currency |
| valid_from | date | Spec 02: 1C price period; future-dated rows allowed |

Unique: (`price_list_id`, `item_id`, `min_qty`, `valid_from`) (Spec 02; was without `valid_from`).

### 6.10 Configuration (`config/crm.php`)
- `base_currency` (default `UZS`, D-1), `currencies` allowed list with minor-unit exponent (`UZS => 2`, `USD => 2`, `EUR => 2`, `RUB => 2`).
- `default_tax_rate_bp` (default `1200`, D-2).
- `legal_form_tokens` for name normalization.

### 6.11 Migration/backfill plan
Greenfield, so no backfill. All migrations reversible (`down()` drops). Seeders: units, a demo category tree, demo items, one default price list per configured currency, and one user per role for local/dev only (never run in production; guarded by `app()->isProduction()`).

## 7. UI/UX (Livewire 4, full-page components behind `auth` middleware)

Layout: left nav (Customers, Catalog → Items / Categories / Units, Price lists, Admin → Users). Lists paginate at 25, search debounced 300 ms, filters kept in query string. Action buttons hidden when the Policy denies; server-side authorization repeated in every action.

| Screen | Content | Notes |
|---|---|---|
| Customers index | name, tax ID, city, owner, primary contact, phone; search (name, tax ID, email, phone); filters: owner, "mine", archived | empty state with "Create customer" for allowed roles |
| Customer form | fields from 6.3; duplicate panel shown live as name/tax ID/email are typed | exact tax ID match blocks save with link to existing (or "restore" for admin if archived) |
| Customer show | header (owner, currency, terms, price list), tabs: Contacts, Activity; later phases add Leads/Offers/Orders/Agreements tabs | |
| Contact modal | fields from 6.4; "primary" toggle | |
| Items index | SKU, name, category, type, unit, base price (formatted with currency), VAT %, active; filters: category (incl. descendants), type, active | |
| Item form/show | fields from 6.7; show tab "Prices" (all price list entries) and "Price check" (US-3.4), Activity tab | |
| Categories | tree view with add/rename/move/delete | |
| Units | table, admin only for edits | |
| Price lists index/show | header fields; entries grid: item search, min qty, price; inline add/edit/remove | |
| Users (admin) | name, email, role, active; create/edit | |

Money input: typed as a decimal string in major units (`1250000.50`), parsed with string/BCMath to minor units; rejects more decimals than the currency allows. Display uses `Number::currency` / locale formatting.
Validation errors inline; unauthorized actions return 403; archived records shown read-only with an "Archived" badge.

## 8. Business rules

BR-1 Money: never float. `App\Support\Money` (immutable: `amount` int minor, `currency` string) with `add`, `subtract`, `multiplyByQty(string $qty)` using BCMath and **half-up rounding to minor units**; operations on different currencies throw.
BR-2 VAT: prices in the catalog and price lists are **net (excluding VAT)** (D-3). VAT rate is stored per item; Phase 3 computes VAT per line, rounded half-up to the minor unit, then sums lines.
BR-3 Quantities: a quantity's decimal places must be <= the unit's `precision`; negative quantities are invalid; `min_qty` tiers obey the same rule.
BR-4 SKU: (superseded by Spec 02 BR-I.13) SKU is the 1C code as received; uniqueness is owned by 1C.
BR-5 Tax ID: normalized to digits/letters only; unique across active and archived customers **created in the CRM** (Spec 02 BR-I.17: duplicates imported from 1C are allowed and flagged). For `country = UZ` it must be 9 digits (company INN) (D-5). Creating a customer with an existing archived tax ID offers restore instead.
BR-6 Duplicate warnings (non-blocking): same `name_normalized`, or a contact/customer email already used on another customer, or same phone. Blocking only on tax ID.
BR-7 Primary contact: setting a contact primary unsets the previous one in the same transaction. Archiving the primary contact leaves the customer with none (allowed; UI prompts).
BR-8 Customer price list must have the same currency as the customer's currency and be active.
BR-9 Default price list: setting `is_default` unsets the other default for the same currency in one transaction. A default list may have validity dates.
BR-10 Price resolution (`App\Services\Pricing\PriceResolver::resolve(Item $item, string $currency, string $qty, ?Customer $customer = null, ?CarbonImmutable $date = null): ResolvedPrice`), date defaults to today in app timezone:
  1. Item must be active and not archived, else `ItemNotSellable` exception.
  2. Customer's assigned price list, if active, not archived, currency matches, `date` within validity: take the entry with the highest `min_qty <= qty`.
  3. Else the active default price list for `currency` valid on `date`: same tier rule.
  4. ~~Else the item base price~~ (removed by Spec 02: no base price).
  5. Else `PriceNotFound` exception (Phase 3 decides whether manual entry is allowed).
  Spec 02: within a list, among rows with `valid_from <= date` take the latest `valid_from`, then the tier rule.
  Returns `unit_price` (Money), `source` (`customer_price_list` | `default_price_list`), `price_list_id`, `prices_include_vat`, `tax_rate_bp`. If a list exists but has no tier for the item at that qty, fall through to the next step.
BR-11 Archiving: customers, contacts, items and price lists are soft-deleted ("archived"). Archived records are excluded from pickers and search by default, viewable via filter, and restorable by admin. Hard delete (admin) is blocked if any FK references the row (in later phases: leads/offers/orders).
BR-12 Deactivating an item or price list keeps all data; only affects new selections and price resolution.
BR-13 Ownership: a sales user creating a customer becomes owner; manager/admin choose an owner (active users with role `sales` or `manager`). Owner changes logged as `owner_changed`.
BR-14 Audit: every create/update/archive/restore/delete on customers, contacts, items, categories, units, price lists and price list entries writes `activity_log` with the causer and old/new values of changed fields. Item `base_price_minor`/`tax_rate_bp` and entry `price_minor` changes use event `price_changed`.

## 9. Acceptance criteria

Foundation
- AC-0.1 Given an inactive user, when they try to log in with correct credentials, then login fails with a generic message.
- AC-0.2 Given a guest, when they open any CRM route, then they are redirected to login.
- AC-0.3 Given a non-admin, when they open the Users admin page or call its actions, then they get 403.

Customers
- AC-1.1 Given a sales user, when they create a customer with valid data, then it is saved with `owner_id` = that user and a `created` activity row exists with causer = that user.
- AC-1.2 Given a customer with tax ID `123456789` exists, when anyone creates another with ` 123-456-789 `, then save is blocked with a link to the existing record.
- AC-1.3 Given the existing customer with that tax ID is archived, when an admin attempts creation, then they are offered "Restore"; a non-admin is told to contact an admin; no second record is created.
- AC-1.4 Given a customer "Alpha Trade LLC" exists, when a user types "ALPHA TRADE" as name, then a non-blocking duplicate warning lists the existing customer.
- AC-1.5 Given country `UZ` and tax ID `12345`, when saving, then validation fails ("must be 9 digits").
- AC-1.6 Given a sales user who does not own customer C, when they try to update C or add a contact to it (UI or direct Livewire call), then they get 403 and nothing changes.
- AC-1.7 Given a sales user, when they try to change owner, price list or payment terms of their own customer, then they get 403.
- AC-1.8 Given a finance user, when they assign a USD price list to a UZS customer, then validation fails; assigning an active UZS list succeeds and is logged.
- AC-1.9 Given contact A is primary, when contact B of the same customer is set primary, then A is no longer primary and exactly one primary exists.
- AC-1.10 Given a contact with neither email nor phone, when saving, then validation fails.
- AC-1.11 Given a manager archives a customer, then it disappears from the default list and search, appears under "Archived", is read-only, and an `archived` activity row exists.
- AC-1.12 Given a customer update changes only `city`, then the activity row's `properties` contain only `city` old/new.

Catalog (Spec 02: AC-2.2..2.6 and 2.8 no longer apply because catalog mutations are not possible in the CRM; replaced by Spec 02 AC-I.10..I.31. AC-2.1 and 2.7 still apply. In Pricing, AC-3.6..3.9 apply only to the CRM-local default flag and to the "no mutation" rule; AC-3.4/3.5 lose the base-price fallback.)
- AC-2.1 Given unit `pcs` (precision 0), when an item's `min_order_qty` is `1.5`, then validation fails; for unit `kg` (precision 3) `1.5` passes and `1.2345` fails.
- AC-2.2 Given SKU `ab-100` exists (stored `AB-100`), when creating an item with SKU ` AB-100`, then validation fails, also if the existing item is archived.
- AC-2.3 Given base price input `1250000.505` in UZS (2 decimals), then validation fails; `1250000.50` stores `125000050`.
- AC-2.4 Given a category with items or children, when an admin deletes it, then deletion is blocked with a reason.
- AC-2.5 Given category A > B, when moving A under B, then it fails (cycle); a 4th nesting level fails (depth).
- AC-2.6 Given a finance user changes an item's base price from 100.00 to 120.00, then a `price_changed` row with old `10000` and new `12000` exists; a finance user changing the item name gets 403.
- AC-2.7 Given an item is deactivated, then it is excluded from the item picker and `PriceResolver` throws `ItemNotSellable`, while the item's show page and prices remain viewable.
- AC-2.8 Given a unit used by any item, when an admin deletes it, then deletion is blocked.

Pricing
- AC-3.1 Given UZS default list D has item X tiers {0: 100.00, 10: 90.00, 100: 80.00}, when resolving qty `9.999`→ 100.00, `10` → 90.00, `250` → 80.00, each with source `default_price_list` and `price_list_id = D`.
- AC-3.2 Given customer C is assigned UZS list P with X at 85.00 (min_qty 0), when resolving X qty 50 for C in UZS, then 85.00 with source `customer_price_list`.
- AC-3.3 Given P's `valid_to` is yesterday, when resolving for C, then it falls back to D.
- AC-3.4 Given P exists but has no entry for item Y, when resolving Y for C, then it falls back to D, then base price.
- AC-3.5 Given item base currency UZS and no USD list contains it, when resolving in USD, then `PriceNotFound` is thrown (no FX conversion).
- AC-3.6 Given a UZS default list exists, when another UZS list is set default, then the first is no longer default; a USD default is unaffected.
- AC-3.7 Given `valid_to` earlier than `valid_from`, when saving a list, then validation fails.
- AC-3.8 Given an entry (P, X, min_qty 10) exists, when adding another (P, X, 10.000), then it fails as a duplicate tier.
- AC-3.9 Given a sales user, when calling any price list mutation, then 403.

Money
- AC-4.1 `Money(1001, 'UZS')->multiplyByQty('0.5')` = 501 (half-up from 500.5); `multiplyByQty('0.333')` of 100 = 33 (33.3 → 33).
- AC-4.2 Adding UZS to USD throws `CurrencyMismatch`.

## 10. Tests (PHPUnit; `RefreshDatabase`, SQLite in memory)

Unit
- `tests/Unit/Support/MoneyTest.php`: parsing from major-unit strings, rounding half-up, currency mismatch, zero, large values (up to 10^15 minor).
- `tests/Unit/Support/QuantityTest.php`: precision validation per unit, negative rejection.
- `tests/Unit/Support/CustomerNameNormalizerTest.php`: legal-form tokens, quotes, whitespace, Cyrillic/Latin case.

Feature
- `Auth/LoginTest.php`: active vs inactive user, guest redirect (AC-0.x).
- `Policies/CustomerPolicyTest.php`, `Policies/CatalogPolicyTest.php`, `Policies/PriceListPolicyTest.php`: the full matrix in section 3 as data-provider rows (role × action → allowed).
- `Customers/CreateCustomerTest.php`: AC-1.1 to 1.5 (Livewire component tests).
- `Customers/UpdateCustomerTest.php`: AC-1.6 to 1.8, 1.11, 1.12.
- `Customers/ContactTest.php`: AC-1.9, 1.10, ownership via parent.
- `Catalog/ItemTest.php`: AC-2.1 to 2.3, 2.6, 2.7.
- `Catalog/CategoryTreeTest.php`: AC-2.4, 2.5.
- `Catalog/UnitTest.php`: AC-2.8.
- `Pricing/PriceListTest.php`: AC-3.6 to 3.9.
- `Pricing/PriceResolverTest.php`: AC-3.1 to 3.5, inactive/archived list ignored, archived item, date boundaries inclusive.
- `Audit/ActivityLogTest.php`: one row per mutating action, causer set, only changed fields recorded, append-only.

Factories for every model with states (`archived`, `inactive`, `forOwner`, `withTiers`). Definition of done: all tests pass, Pint clean, every Livewire action calls `$this->authorize()`.

## 11. Metrics

Phase 1 is foundational, so metrics are data-quality metrics reviewed 30 days after go-live:
- Duplicate customers (same normalized name or tax ID found in review): target < 1%.
- Customers with tax ID filled: target > 90%.
- Active items with a price in the default list of the base currency: target > 95%.
- Price changes with an audit row: 100% (tested).
- Median time to create a customer with one contact: < 2 minutes.

## 12. Risks, open questions and chosen defaults

Items marked **confirm** need a user decision before implementation starts. The default is what we build if there is no answer.

| # | Question / risk | Default chosen | |
|---|---|---|---|
| D-1 | Base currency and allowed currencies | `UZS` base; allowed `UZS, USD, EUR, RUB` | **confirm** |
| D-2 | Default VAT rate | 12% (`1200` bp), per-item override, 0% allowed | **confirm** |
| D-3 | Are catalog prices net or gross of VAT? | Superseded by Spec 02: per 1C price type (`prices_include_vat`) | |
| D-4 | SKU / tax ID reuse after archiving | SKU: owned by 1C (Spec 02). Tax ID: never reused for CRM-created customers; restore instead | |
| D-5 | Tax ID format | UZ: 9-digit INN required format; other countries free text up to 32 | **confirm** |
| D-6 | Roles model | Single `role` column + Policies; no permissions package. Revisit if per-user overrides are needed | **confirm** |
| D-7 | Sales visibility of customers | Sales can **view all** customers, **edit only own**. Alternative: see only own | **confirm** |
| D-8 | Auth scaffolding | Livewire starter-kit-style auth (login, logout, password reset), no registration | **confirm** |
| D-9 | Unit price precision: minor units (2 decimals) may be too coarse for very cheap items sold in bulk | Accept 2 decimals in Phase 1; if needed, add per-currency `price_scale` before Phase 3 | |
| D-10 | Production database | Write portable migrations; target MySQL 8 (OSPanel) in prod, SQLite for tests | **confirm** |
| D-11 | Customer-specific prices beyond price lists | Use a dedicated price list per customer; no separate table | |
| D-12 | FX conversion when no price in requested currency | Not supported; `PriceNotFound` | |
| R-1 | Uniqueness checks with soft deletes and NULL parents are app-level for categories/defaults/primary contact; race conditions possible | Wrap in DB transactions with `lockForUpdate`; covered by tests | |
| R-2 | Laravel Boost not yet installed (required by `CLAUDE.md`) | Task 0.1 installs it before any code | |
| R-3 | Audit log growth | Append-only, indexed; retention policy deferred to Phase 6 | |
| R-4 | Name normalization for duplicates is language-specific (Uzbek/Russian/English legal forms) | Configurable token list; warnings only, never blocking | |

## 13. Rollout and build order

Greenfield: no feature flags; each increment is a shippable vertical slice merged behind `auth`. Rollback = `migrate:rollback` of that increment's migrations (no production data yet).

| Step | Increment | Contents | Depends on |
|---|---|---|---|
| 0.1 | Tooling | Install Laravel Boost per `CLAUDE.md`; `config/crm.php` | – |
| 0.2 | Auth & roles | `UserRole` enum, users migration (`role`, `is_active`), auth screens, admin Users screen; no `Gate::before` bypass (admin rights are explicit in each policy) | 0.1 |
| 0.3 | Shared rails | `Money`, quantity helpers, `activity_log` migration + `LogsActivity` concern, morph map, base layout/nav | 0.2 |
| 1a–1c | Catalog, prices, stock | **Replaced by Spec 02 increments I-1..I-5** (inbound API, read-only screens, `PriceResolver` amended) | 0.3 |
| 1d | Customers & contacts | `customers` (needs `price_lists` for FK), `contacts`, normalizer, duplicate detection, screens; built together with Spec 02 I-7/I-8 sync columns | 0.3, Spec 02 I-3 |
| 1e | Hardening | full policy matrix tests, factories, seed demo data, Pint | 1a–1d |

Migration order: superseded by Spec 02 section 11.6.

Prioritization (RICE-lite): customers/contacts and items are both blocking for leads/offers (impact high, effort medium). 1d could be built before 1a–1c if leads are urgent, by deferring the `customers.price_list_id` column to a follow-up migration.

## 14. Roadmap for remaining stages

Each phase gets its own spec (`docs/specs/04-…` to `07-…`; `02` is the 1C integration, `03` organization/portal/tenders) before code. All sales entities share: `owner_id`, sequential human-readable numbers, status enum with `canTransitionTo()`, transitions via Actions only, `status_transitions` logging, policies by role, activity timeline.

| Phase | Scope | Key tables / components | Depends on | Exit criteria |
|---|---|---|---|---|
| **2. Leads** | Capture, qualification (BANT), pipeline, lost/disqualified reasons, dedupe against customers | `number_sequences` (LD-, OF-, OR-, AG- with yearly reset, row-locked increment), `status_transitions` (polymorphic: from, to, user_id, reason, created_at), `leads` (number, customer_id nullable until qualified, contact_id, source, status, owner_id, est_value_minor + currency, expected_close_date, BANT fields, lost_reason), `lead_items` (interests), `LeadStatus` enum, `QualifyLead` / `DisqualifyLead` actions | Phase 1 | Qualified requires customer + contact + BANT; every transition logged; dedupe warning on create |
| **3. Offers** (detailed and superseded by Spec 05 `docs/specs/05-offers.md`: option groups for alternative/optional items, extra statuses) | Quotes with lines, discounts, VAT, approvals, validity, versions, PDF, send/accept | `offers` (number, lead_id, customer_id, contact_id, status, currency, valid_until, version, parent_offer_id, payment_terms_days, totals *_minor), `offer_lines` (snapshot: item_id, sku, name, unit_code, qty, unit_price_minor, discount_bp/amount, tax_rate_bp, line totals, price_source), `discount_limits` per role (config), `offer_approvals`; `CreateOfferFromLead`, `SubmitForApproval`, `ApproveOffer`, `SendOffer`, `AcceptOffer`, `ReviseOffer` actions; scheduled `ExpireOffers` job | Phase 2, `PriceResolver`, `Money` | Lines snapshot prices; totals computed server-side (round per line, then sum); discount above role limit requires approval; sent offers immutable; one lead may have several offers but accept marks lead converted |
| **4. Orders** | Order from accepted offer, confirmation, delivery/invoice tracking, amendments, cancellation | `orders` (number, offer_id **unique**, customer_id, status, totals, delivery address/date, payment_status), `order_lines` (copied snapshot + delivered_qty, invoiced_qty), `order_amendments`; `CreateOrderFromOffer` (idempotent, transaction + unique constraint), `ConfirmOrder`, `RecordDelivery`, `MarkInvoiced`, `CancelOrder` | Phase 3 | One accepted offer → exactly one order; delivered/invoiced qty never exceed ordered; cancellation needs reason; orders with agreements cannot be deleted |
| **5. Agreements** | Contract from order, review, signing, active term, renewal/expiry/termination | `agreements` (number, order_id, customer_id, status, starts_at, ends_at, signed_at, auto_renew, notice_days, file path, version), `agreement_signatories`, customer bank details & signatory fields on `customers`; templates; `GenerateAgreement`, `SendForSignature`, `MarkSigned`, `Activate`, `Terminate`, `Renew`; scheduled renewal reminders (90/60/30 days) | Phase 4 | Only signed agreements become active; signed file immutable (addenda only); termination needs reason/date |
| **6. Reporting & timeline** | KPIs, dashboards, notes/tasks/reminders, attachments, retention | report queries over `status_transitions`, `activities` (notes/calls/tasks), `attachments` | Phases 2–5 | Lead→offer, offer→order conversion, cycle time, win/loss reasons, average discount, pipeline by stage, renewal rate |
| **1d+. Import (optional)** | CSV/Excel import for items, price list entries, customers with dry-run and error report | queued job, validation reuse from Form Requests | Phase 1 | Dry-run shows row errors; no partial import on failure |

Critical path: Phase 0 → 1 (catalog + customers) → 2 → 3 → 4 → 5; Phase 6 can start once Phase 3 produces data. Phase 2 can begin as soon as 1d (customers/contacts) is done, in parallel with 1c if needed.

## 15. Item pictures (user requirement 2026-10-18)

Defaults (recorded per user instruction):
- CRM-owned main picture (position 0) plus up to 5 more per item (`item_images`). 1C does not send pictures; the item feed never touches this table, so an empty 1C payload cannot remove them.
- Upload, replace (upload as main), make main and remove on the item page (`catalog.edit_local`: manager, admin; policy `manageImages`); every change is logged on the item.
- Security as for the logo: JPEG, PNG or WebP only, decided from the content (finfo and getimagesize must agree), never SVG; at most 5 MB, 6000 × 6000 px and 36 megapixels (checked before GD decodes); decoded and re-encoded with GD as JPEG (EXIF, ICC and any trailing payload removed, transparency flattened on white): full ≤ 1600 px, medium 320 px, thumb 96 px, random 40-character names on the private disk. Pictures are served only through `MediaController` (`/media/item-images/{uuid}/{thumb|medium|full}`) to signed-in staff with catalog access or portal users, as `image/jpeg` with `nosniff`, under the CSP (`img-src 'self' data:`). Alt text = item name.
- Item list: 40 px thumbnail column; item page: gallery.

Build status: migration `create_item_images_table`, `ItemImage`, `ManageItemImages`, `ItemImageException`, `ItemGallery` (Livewire), `x-item-thumb`, `MediaController`, tests `tests/Feature/Catalog/ItemImagesTest.php` (5).

**Update 2026-10-19 (Spec 03 section 22, organizations with roles):**

- The `customers` table is now the organizations table, with the flags `is_customer`, `is_supplier` and `is_carrier`. Customer screens and rules apply to organizations with the customer role (`Customer` model scope).
- A tax ID is unique per organization, whatever its roles. When a new customer's tax ID belongs to a supplier or carrier, the form offers "Add the customer role" instead of creating a duplicate.
- Supplier contacts are now customer-table contacts (one `contacts` table). Their `telegram_username` and `preferred_locale` are kept.
- The customer card shows "Also a supplier / carrier" badges, plus buttons to add those roles (needs `counterparties.create`).

**Update 2026-10-19 (Spec 09, customer equipment):** an item's `warranty_months` also sets the warranty of the units in the customer's equipment register. That register is a new tab on the customer card (Equipment). Customers see their units, report problems and follow repairs in the portal.

> **Spec 10 note (2026-10-19).** Categories gained `portal_visible`, `portal_names` (uz/ru/en) and `portal_sort`; items gained `portal_visible`. Per-customer allow/deny rules live in `catalog_visibility_rules`. The portal catalog never exposes price, cost or stock quantity (see `docs/specs/10-customer-requisitions.md`).

> **Spec 03 section 24 note (2026-10-19).** Customers and suppliers/carriers are one **Partner** table (`partners`) with roles. Links that mean the organization are `partner_id` (contacts, supplier profile, equipment, service tickets, requests, catalog rules); links to the customer role keep `customer_id`. The 1C contract (paths, scopes, fields, feeds) is unchanged.

> **No price or stock history (user decision 2026-10-19).** Items keep ONE current purchase price and ONE sale price (with currency) and the current stock per warehouse with stock cost, upserted in place by the 1C push (delta and full snapshot behaviour and `batch_id` idempotency unchanged; a full snapshot still zeroes/removes rows missing from it). Found and removed: the item change log recorded price changes (`price_changed` / `synced_from_1c` with price columns), which acted as a price history; price columns are now excluded from logging and migration `2026_10_19_111354` removed them from existing rows (dev: 54 → 24 item log rows). Stock rows were never logged; there are no history tables, charts, API fields or trend reports. Kept on purpose: price/amount snapshots on offers, orders, invoices, delivery notes and payments (documents keep what was sold at the time), the stock cost snapshot on posted document lines, and the 1C batch/ack log (payload retention 30 days, batch metadata 365 days: `config/onec.php`).

## 17. Item page tabs and analogues (2026-10-19)
- Header (name, SKU, badges, portal visibility, 1C status) above tabs built with the shared `<x-tabs>` component (role=tablist/tab/tabpanel, aria-selected, aria-controls to real panel ids, roving tabindex with Left/Right/Home/End via the CSP-safe Alpine `tabList`, visible focus, horizontal scroll): Overview (local fields, description, 1C data), Prices & stock (current prices only, stock per warehouse with cost), Codes & tax (accounting codes, serial flag, warranty, cargo data), Serial numbers (Spec 06 21.10), Analogues, Pictures, Usage (offers, orders, leads containing the item, each limited to what the user may see, paged), Activity. The tab is kept in the URL (`?tab=`); unknown values fall back to Overview. Tabs a user cannot use are hidden (serials without any serial right, usage without offers/orders/leads view). Labels carry cheap counts (serials, analogues, pictures). Heavy tabs (serials list, usage, activity) query only when opened.
- Analogues ("matching"): CRM-local `item_alternatives` (1C never overwrites): add by search with relation analogue or upgrade, note, order (`sort_order`), two-way option (`is_mutual`), remove; stock band and sale price per analogue (staff only; the portal catalog never shows prices); "Used as analogue of" lists one-way links pointing here. Gate `manageAnalogues` = catalog.manage_alternatives or catalog.edit_local. The offer builder suggests approved analogues of the group's items including two-way links from the other side, in their order.
- Tests: `tests/Feature/Catalog/ItemPageTabsTest.php`.
