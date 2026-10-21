# Spec 02 — 1C Integration (1C always initiates: catalog, prices, stock and customers via the CRM API)

| | |
|---|---|
| Status | Draft rev. 3 (**1C is on a private network and is never called by the CRM**: 1C pushes the catalog and its customers, and polls an outbox of CRM-created customers). Awaiting answers in section 16; each has a default we build if unanswered |
| Owner | Product (B2B sales CRM) |
| Date | 2026-10-16 |
| Stack | Laravel 13 (PHP ^8.3), Livewire 4, PHPUnit, `QUEUE_CONNECTION=database`, app timezone `UTC` |
| Depends on | Spec 01 (customers, contacts, catalog, `activity_log`, `Money`, roles). Spec 01 is amended by this spec; see its "Amendments from Spec 02" section |
| Feeds | Spec 03 Organization/portal/tenders, 04 Leads, 05 Offers, 06 Orders/Agreements/Accounting/Stock (orders, agreements, invoices, payments, stock documents published in a CRM outbox that 1C pulls; 1C pushes payments) |
| Counterparty | 1C team: scheduled jobs in 1C call the CRM API (section 9 catalog; section 10 customers: push own counterparties, poll the CRM outbox, acknowledge). Nothing has to be published on the 1C side |

### Repo baseline (verified 2026-10-16)
- Migrations: `users` (+ `role`, `is_active`, `locale`), `cache`, `jobs` (incl. `job_batches`, `failed_jobs`), `settings` (key/value text, `updated_by`).
- `App\Models\Setting` (cached key/value, `putMany`, `forget`), `SettingPolicy::manage` = admin only, `Settings\Appearance` page at `/settings/appearance`.
- `App\Enums\UserRole` (admin, manager, sales, finance), `App\Enums\Locale` (uz default, ru, en). i18n is being implemented in parallel; this spec only defines translation keys.
- `composer.json`: `laravel/framework`, `livewire/livewire`, `laravel/tinker`; dev: `laravel/boost`, `pail`, `pint`. **Sanctum is not installed. There is no `routes/api.php`** and `bootstrap/app.php` registers only `web`, `commands` and `/up`. `bootstrap/app.php` already renders JSON errors for `api/*`.
- No domain tables yet. Spec 01 catalog CRUD has not been implemented, so switching the catalog to a 1C mirror costs no rework.

### Revision history
- rev. 1: CRM pulled everything from a 1C HTTP service.
- rev. 2: **items, groups, units, prices and stock are pushed by 1C** to a CRM-hosted, versioned API (`/api/v1/integration/1c/...`). Customers: the CRM pulled 1C counterparties and pushed CRM customers to a 1C HTTP service.
- rev. 3 (this, 2026-10-18): new constraint from the business: **"our 1C is not in global access and 1C will send data"**. The CRM server cannot reach 1C (private network, no inbound access), so **there are no outbound HTTP calls from the CRM to 1C anywhere**. Customers now use the same CRM API: 1C **pushes** its counterparties (`POST /customers`, upsert by GUID), **polls an outbox** of customers created/edited in the CRM (`GET /customers/pending`), then **acknowledges** each one (`POST /customers/ack`) with the 1C GUID and code. The 1C HTTP service, `OneCClient`, outbound credentials, "Test connection" and SSRF controls are removed. Also added: optional item fields `accountant_code`, `class_code` (IKPU/MXIK), `package_code` (BR-I.13a) and `is_serialized` (BR-I.13b). Built 2026-10-18.
- rev. 4 (2026-10-18, business decision: "we have only one price ... one in and one out price; the stock table has stock cost"): **price types, price lists, dated/tiered and customer prices are removed.** Each item carries exactly one sale (out) price and one purchase (in) price, each with a currency, inside the item record of `POST /items`; stock rows carry their stock cost. `POST /item-prices`, the `prices:write` ability and the prices feed are gone; the CRM computes the stock cost price (weighted average) and uses it as cost basis for offer margins and the below-cost approval (Spec 05). Sections 5, 7–13 below are updated; any remaining mention of price types/lists in older text is superseded by BR-I.14.

---

## 1. Problem

The company runs its ERP on 1C. Items, prices and stock live there; accounting, invoicing and shipping happen there. If the CRM keeps its own catalog (as Spec 01 designed), the two drift: reps quote items that no longer exist, at prices finance already changed, without knowing stock. Customers drift the other way: a rep creates a company in the CRM, someone re-types it in 1C for invoicing, producing typos, duplicates and no link between the CRM deal and the 1C documents.

1C must be the master of the catalog, prices and stock, delivering changes to the CRM on its own schedule. The CRM must push the customers it creates into 1C without creating duplicates, keep working when 1C is down, and never leak credentials or personal data.

## 2. Goals / Non-goals

### Goals
- G1. A source-of-truth and flow-direction matrix per entity and field (section 5), enforced by read-only UI, Policies and sync rules.
- G2. **Inbound CRM API** for 1C (machine client): versioned endpoints to push items (with groups and units), prices and stock, in delta or full-snapshot mode, with batch idempotency, per-record results, out-of-order protection and rate limits.
- G3. Machine authentication without new packages: admin-managed, hashed, scoped API tokens shown once, optional IP allowlist and optional HMAC signing, HTTPS only, stateless (no session/CSRF).
- G4. Accept fast, process async: validate, persist raw batch, respond `202`/`207`, then upsert in queued jobs. Every batch stored for audit and replay.
- G5. Customers both ways **with 1C initiating every connection**: 1C pushes its counterparties into the CRM (upsert by GUID, INN linking, 1C wins on shared fields); CRM-created/edited customers wait in an **outbox** (`pending`) that 1C polls and acknowledges (`synced`/`failed`), manual Resync (re-queue), INN dedupe on the 1C side before create, idempotent ack, store 1C GUID/code/version.
- G6. **No HTTP client to 1C exists in the CRM** (enforced by a test that scans `app/` for HTTP clients). Delivery state lives in the outbox; staleness alerts replace connection tests.
- G7. Admin-only Settings > Integrations: inbound API tokens (incl. `customers:read`/`customers:write` abilities), customer-exchange switch, the polling cadence we expect from 1C and its staleness alert, "last contact from 1C" per channel (poll, ack, push), outbox counters, batch log. No 1C URL or credentials are stored.
- G8. Visible freshness: "Last received from 1C" on items/stock/prices, dashboard alerts when 1C stops pushing for N minutes.
- G9. Contracts that the 1C developer can implement without meetings (sections 9 and 10, plus BSL examples for the scheduled jobs).

### Non-goals (this spec)
- Orders, agreements, invoices, payments and stock documents exchange: designed in Spec 06 section 9 (CRM outbox pulled by 1C + `POST /payments` from 1C; the CRM never calls 1C).
- 1C-side implementation (owned by the 1C team).
- Editing catalog, prices or stock in the CRM, except CRM-local fields (section 5.2).
- Pulling the catalog from 1C (rev. 1 design) — dropped.
- FX conversion, unit conversion (pack ↔ piece), bundles. 1C sends prices per the item's base unit.
- Automatic merge of duplicate customers; bank details/signatories (Agreements spec).
- Multiple 1C infobases/organisations; individuals (B2C) as customers.
- gzip request bodies, webhooks or any other call from the CRM to 1C, OAuth.

## 3. Users & roles

Human users:

| Action | sales | manager | finance | admin |
|---|---|---|---|---|
| View items, categories, units, prices, stock (read-only mirror) and "last received" | yes | yes | yes | yes |
| Edit CRM-local item fields (`min_order_qty`, `order_step`) | no | yes | no | yes |
| Edit accounting codes (`accountant_code`, `class_code`, `package_code`; BR-I.13a) | no | no | yes | yes |
| Mark an item serial-tracked (`is_serialized`; BR-I.13b) | no | no | yes (with stock manager and warehouse head, Spec 06 21.3) | yes |
| Edit unit `precision` (CRM-local) | no | no | no | yes |
| Create customer (queued in the outbox for 1C) | yes | yes | no | yes |
| Edit shared customer fields (re-queued for 1C) | own only | all | no | all |
| Edit `tax_id` after the customer is synced | no | no | no | no (change in 1C) |
| "Resync" a customer (re-queue in the outbox) | own only | all | no | all |
| Resolve an INN multi-match reported by an inbound push (link the right CRM customer) | no | yes | no | yes |
| Configure integration, create/rotate/revoke API tokens, view batches/logs, reprocess a batch | no | no | no | yes |
| Dashboard integration alerts | own customers' failures | all | all | all |

Machine client (1C) abilities on API tokens:

| Ability | Grants |
|---|---|
| `ping` (implicit for every valid token) | `GET /ping` |
| `items:write` | `POST /items` |
| `stock:write` | `POST /item-stock` |
| `batches:read` | `GET /batches/{batch_id}` |
| `customers:read` | `GET /customers/pending` (poll the outbox) |
| `customers:write` | `POST /customers/ack`, `POST /customers` (upsert 1C counterparties) |
| `orders:read`, `payments:read`, `warehouse:read` (Spec 06) | `GET /outbox/pending` (feed `orders` / `payments` / `warehouse`), `POST /outbox/ack` for that feed |
| `payments:write` (Spec 06) | `POST /payments` (bank/cash receipts and refunds posted in 1C) |
| `POST /batch` (combined) | requires every ability for the sections present |

Policies: `SettingPolicy::manage` (admin) for the Integrations page; `CustomerPolicy::resync` (permission `customers.resync`) / `update`; `ItemPolicy::updateAccountingCodes` (permission `catalog.edit_accounting_codes`). Catalog policies deny all create/update/delete except CRM-local fields, even for admin.

## 4. User stories

Admin
- US-I.1 As an admin I want to create an API token for 1C with only the abilities it needs, see it once, and revoke or rotate it, so that a leaked token can be cut off without downtime.
- US-I.2 As an admin I want to restrict the token to the 1C server IP so that a stolen token is useless elsewhere.
- US-I.3 As an admin I want to see every batch 1C sent (when, which endpoint, mode, counts, rejected records) and reprocess one, so that I can diagnose data problems without server access.
- US-I.4 As an admin I want an alert when 1C has not pushed stock/prices/items for longer than a threshold, so that I notice a broken 1C job before sales do.
- US-I.5 As an admin I want to see when 1C last polled, acknowledged and pushed customers and be alerted when it stops polling, so that I notice a broken 1C job without being able to reach 1C myself.

Sales / manager / finance
- US-I.6 As a sales rep I want items, prices and available stock exactly as in 1C, with "last received from 1C", so that I quote what can be delivered at the right price.
- US-I.7 As a sales rep I want a customer I create to appear in 1C automatically (1C collects it on its next poll), and to see its sync status ("pending since 10:42") with a Resync button.
- US-I.8 As a sales rep, if a company with the same INN exists in 1C, I want the CRM to link to it instead of creating a duplicate.
- US-I.9 As a manager I want to resolve conflicts when a customer was changed in both systems so that no change is silently lost.
- US-I.10 As a manager I want existing 1C customers to appear in the CRM with an owner.
- US-I.11 As finance I want the CRM sale and purchase price and the stock cost to equal 1C, so that offers use the right price and margin.

## 5. Source of truth and flow direction

### 5.1 Per entity

| Entity | 1C object (typical) | Master | Flow | Transport | Default cadence | Delete handling |
|---|---|---|---|---|---|---|
| Units | Единицы измерения | 1C | **1C → CRM (push)** | CRM API `POST /items` (`units[]`) | with items | `is_deleted` or absent from full snapshot → `is_active=false` |
| Item groups (categories) | Номенклатура groups | 1C | **1C → CRM (push)** | `POST /items` (`groups[]`) | with items | same → hidden; items keep link |
| Items | Номенклатура | 1C | **1C → CRM (push)** | `POST /items` (`items[]`) | delta every 15 min; full nightly | same → `is_active=false`, `external_deleted_at` |
| Item prices (rev. 4) | one sale price + one purchase price per item | 1C | **1C → CRM (push)** | inside `POST /items` (`sale_price`, `purchase_price`, currencies, `prices_include_vat`) | with items (delta every 15 min) | `null` clears a price; absent = unchanged |
| Warehouses | Склады | 1C | **1C → CRM (push)** | `POST /item-stock` (`warehouses[]`) | with stock | same → `is_active=false` |
| Stock balances | Остатки | 1C | **1C → CRM (push)** | `POST /item-stock` (`stock[]`) | full snapshot every 10 min (or delta) | absent from full snapshot → row deleted (= 0) |
| Customers (counterparties) | Контрагенты (+ Партнеры) | **Shared** (1C for 1C-originated records and accounting fields; CRM creates new ones) | **Both, always initiated by 1C**: 1C → CRM by 1C **push** (`POST /customers`); CRM → 1C by 1C **polling** the outbox (`GET /customers/pending`) and **acknowledging** (`POST /customers/ack`) | CRM API (section 10) | 1C pushes changes and polls every 5 min (D-I.2) | 1C deletion mark → "Deleted in 1C" (read-only, excluded from pickers). CRM archive is local, never sent |
| Contacts | Контактные лица | Shared, inside the customer aggregate | **Both** (inside the customer payload, same transport) | CRM API | with customer | 1C deletion mark → archived; CRM archive → sent as `is_deleted: true` |
| Orders, agreements, proforma invoices, CRM-recorded payments, delivery notes, goods receipts | Заказ клиента, Договор, Счет на оплату, ПКО/Поступление, Реализация, Поступление товаров | CRM | CRM → 1C by **1C pull** | CRM outbox `GET /outbox/pending` + `POST /outbox/ack` (Spec 06 section 9) | 1C polls (default every 5 min) | cancel/void events; never deleted |
| Bank/cash payments; tax documents (ESF, acts, waybills) | Поступление на расчетный счет, ПКО; Счет-фактура | 1C | **1C → CRM (push)** | `POST /payments` (Spec 06 9.3); tax documents: manual registration v1, `documents:write` roadmap | 1C schedule (default every 15 min) | 1C deletion mark → payment voided in CRM |
| Serial numbers | — (not kept in 1C) | CRM | none | — | — | — |

### 5.2 Field ownership

Catalog (1C-owned except CRM-local):

| Table | 1C-owned (overwritten by pushes) | CRM-local (never overwritten) |
|---|---|---|
| units | code, name, is_active | `precision` (seeded from OKEI map, BR-I.12) |
| categories | name, parent_id, is_active | `sort_order` |
| items | sku (1C code), article, name, description, type, category_id, unit_id, tax_rate_bp, is_vat_exempt, is_active | `min_order_qty`, `order_step` |
| items (shared, BR-I.13a) | `accountant_code`, `class_code`, `package_code`: a non-empty value from 1C overwrites | the same fields may be entered in the CRM (finance/admin); an empty/absent value from 1C never wipes them |
| items (prices, rev. 4) | sale_price_minor, sale_currency, purchase_price_minor, purchase_currency, prices_include_vat | none |
| warehouses, item_stocks | all | none |

Customers:

| Group | Fields | Rule |
|---|---|---|
| Shared (both directions) | name, legal_name, legal_type, tax_id, country, city, address, postal_code, phone, email; contacts (first_name, last_name, position, phone, email, is_deleted) | **1C wins on conflict**: an inbound push overwrites shared fields even while a CRM edit is still pending; the overwritten CRM values are kept in the activity log (`synced_from_1c` old/new + `sync_conflict_1c_won`) and the open outbox entry is superseded, so nothing is lost silently |
| Immutable after first sync | tax_id | Editable in CRM only while `external_id` is null |
| 1C-only (pushed by 1C, read-only) | external_code, deleted/merged state | 1C wins always |
| CRM-only (never pushed) | owner_id, currency, payment_terms_days, website, industry, notes | CRM wins always; `owner_id` set from the 1C manager only at first import |

## 6. Workflow impact

- Sales-stage statuses are unchanged. Leads/offers (Specs 04–05) pick only `is_active` items and customers not "Deleted in 1C".
- New enums: `SyncStatus` (customers, outbound), `InboundBatchStatus` (batches), `SyncMode` (`delta`, `full`).

### 6.1 Customer `SyncStatus` (CRM → 1C via the outbox)

```
            create (exchange on, pushable)                1C acks "synced"
 [new] ───────────────────────────────────► pending ─────────────────────────► synced
   │ create (exchange off / not pushable)      │  ▲  ▲                            │
   ▼                                           │  │  └── shared field edited ─────┘
 not_synced ── becomes pushable / Resync ──────┘  │      or Resync (re-queued)
                         1C acks "failed" ▼       │
                                       failed ────┘  Resync / edit
 any ── inbound push from 1C (link by GUID, crm_id or single INN match) ──► synced (1C wins)
```

`pending` = an open outbox entry exists (`customers.sync_queued_at` = since when). 1C polls it, creates or updates the counterparty, and acknowledges.

| From | To | Trigger | Actor |
|---|---|---|---|
| (new) | pending | created, customer exchange enabled, pushable (BR-I.6) | user |
| (new) | not_synced | exchange disabled or not pushable (reason stored) | user |
| not_synced | pending | becomes pushable, exchange enabled (`onec:queue-customers`), Resync | user, system |
| pending | synced | `POST /customers/ack` with `status: synced` for the current outbox entry | 1C |
| pending | failed | `POST /customers/ack` with `status: failed` (+ error, fields) | 1C |
| synced | pending | shared field or contact edited, Resync | user |
| failed | pending | Resync or edit | user |
| any | synced | inbound push for the customer (BR-I.11, 1C wins) | 1C |

`conflict` stays in the enum for INN multi-matches reported by an inbound push (reported in the push result; a manager links manually); no version negotiation is needed because 1C wins.

### 6.2 `InboundBatchStatus`

`received` → `queued` → `processing` → `processed` | `partial` | `failed`; `rejected` (422 at intake, terminal). `processed/partial/failed` → `queued` only by admin "Reprocess" (logged). For full snapshots split into parts, the deactivation step runs only when the last part is processed (section 7.4).

Activity events (new): `sync_queued`, `sync_succeeded`, `sync_failed`, `sync_linked_existing`, `sync_conflict_1c_won`, `synced_from_1c` (causer null; old/new of changed fields), `deleted_in_1c`, `price_changed` (causer null), `api_token_created`, `api_token_revoked`, `batch_reprocessed`, `integration_settings_changed` (secrets as `"changed"`).

Handoffs: offer line snapshot (Spec 01 section 5) additionally stores `item_external_id`, `price_source` (`item_sale_price` | `manual`), `cost_price_minor` and the offer's `prices_include_vat`, so the later order push can reference 1C GUIDs. Orders may be handed to 1C (roadmap: 1C polls an order outbox) only for customers in `synced`.

## 7. Architecture

### 7.1 Components (namespaces are proposals; follow repo conventions at build time)

Inbound API (1C → CRM):

| Component | Responsibility |
|---|---|
| `routes/api.php` + `bootstrap/app.php` `withRouting(api: …)` | Registered manually; **do not run `php artisan install:api`** (it installs Sanctum). `api` middleware group is stateless (no session, cookies, CSRF) |
| Route group `api/v1/integration/1c` | Middleware in order: `RequireHttps` (production), `AuthenticateIntegrationToken`, `EnsureIntegrationIpAllowed`, `VerifyIntegrationSignature` (only if the token has a signing secret), `throttle:onec-api`, `EnsureTokenAbility:<ability>`, `EnsureIntegrationEnabled` |
| `App\Models\IntegrationToken` | Hashed tokens (section 11.4). Token = `1c_` + 48 random chars (`Str::random`), shown once; stored as `hash('sha256', token)`; lookup by hash; prefix kept for identification |
| `App\Http\Middleware\AuthenticateIntegrationToken` | Reads `Authorization: Bearer`; rejects missing/unknown/revoked/expired with 401 (generic message); sets token on request attributes; updates `last_used_at`/`last_used_ip` at most once per minute |
| `App\Http\Requests\Integration\OneC\*Request` | Envelope + per-record validation (split: envelope errors → 422; record errors → collected, not thrown) |
| `App\Http\Controllers\Api\V1\Integration\OneC\*Controller` | `ping`, `items`, `itemStock`, `batch`, `showBatch`. Thin: idempotency check → persist `inbound_batches` → dispatch → respond |
| `App\Actions\OneC\ReceiveInboundBatch` | Idempotency (batch_id + payload hash), size checks, snapshot part bookkeeping, persistence, dispatch |
| `App\Jobs\OneC\ProcessInboundBatch` | Queue `onec-inbound`, `WithoutOverlapping('onec-inbound')` (FIFO, one at a time, preserves send order), `timeout=900`, `tries=3`, backoff 60/300 s for DB/lock errors only |
| `App\Services\OneC\Inbound\{Unit,Group,Item,Warehouse,Stock}Upserter` | Map, normalize, out-of-order check, hash skip, upsert by `external_id`, audit |
| `App\Services\OneC\Inbound\SnapshotFinalizer` | Deactivation after the last part of a full snapshot, with the safety guard |

Customer exchange (1C pushes, 1C polls the CRM outbox). **No component calls 1C**:

| Component | Responsibility |
|---|---|
| `config/onec.php` | `ONEC_ENABLED` (customer exchange default, overridden by setting `onec.customers_enabled`), `ONEC_TIMEZONE=Asia/Tashkent`, `ONEC_CUSTOMERS_POLL_STALE_MINUTES=30`, `ONEC_CUSTOMERS_MAX_PER_POLL=500`, `ONEC_CUSTOMERS_MAX_PER_PUSH=200`, `ONEC_DEFAULT_OWNER_EMAIL`; inbound catalog limits as before |
| `App\Services\OneC\OneCSettings` | Effective config: `settings` rows (`onec.*`) override `.env`. No secrets of 1C are stored (there is nothing to connect to) |
| `customer_outbox` + `App\Models\CustomerOutboxEntry` | One row per "please (re)send this customer"; the auto-increment id is the poll cursor; open = not acknowledged and not superseded |
| `App\Services\OneC\Customers\CustomerOutbox` | `queue()` (pushability check BR-I.6; reuse the open entry while undelivered, supersede it once delivered), `poll()`, `acknowledge()` (idempotent) |
| `App\Services\OneC\Customers\CustomerPayload` | Serialises a customer + contacts at poll time (edits before the poll collapse into one delivery) |
| `App\Services\OneC\Customers\CustomerInboundUpserter` | Applies `POST /customers`: match by GUID → `crm_id` → INN, out-of-order and hash checks, 1C wins, contacts, deletion/merge marks, owner mapping |
| `App\Http\Controllers\Api\V1\Integration\OneC\CustomerExchangeController` | `pending`, `ack`, `upsert`; same middleware stack + `EnsureTokenAbility:customers:*` + `EnsureIntegrationEnabled:customers` |
| Model hooks | `Customer`/`Contact` saved/deleted outside `OneCSyncContext` with a shared-field change → `CustomerOutbox::queue()`. Changes applied from 1C never re-queue |
| Console | `onec:queue-customers` (queue every pushable `not_synced` CRM customer; run after enabling the exchange), `onec:expire-snapshots` |
| Schedule (`routes/console.php`) | `onec:expire-snapshots` every 5 min, `model:prune`; customer poll staleness is computed on read |

Ops prerequisites: queue worker `php artisan queue:work --queue=onec-inbound,default --max-time=3600` and the scheduler (Windows Task Scheduler on OSPanel, cron on Linux). PHP `post_max_size`/`upload_max_filesize` and the web server body limit must be ≥ `ONEC_API_MAX_BODY_KB`. The dashboard shows "Queue not processing" if any batch stays `queued` > 10 min.

### 7.2 Intake (synchronous, target p95 < 1 s for 1 000 records)

1. Middleware chain (auth, IP, signature, throttle, ability, enabled). Body > max → 413 before parsing.
2. Parse JSON (strip BOM). Envelope validation: `batch_id` UUID, `mode`, `sent_at`, `snapshot` block if `mode=full`, arrays present, total records ≤ `ONEC_API_MAX_RECORDS`. Failure → 422, batch stored as `rejected` (payload kept for diagnosis, 7 days).
3. Idempotency: `Idempotency-Key` header, if present, must equal `batch_id` (else 422). Look up `inbound_batches.batch_id`:
   - exists with same `payload_hash` → return the **stored** response (same status code and body) with header `Idempotent-Replayed: true`; nothing re-queued.
   - exists with different hash → 409 `idempotency_key_reused`.
4. Per-record validation (types, required fields, decimal strings, GUIDs, enum values, duplicates of the same `id` within the batch). Invalid records are listed in `rejected[]` with `index`, `id`, `errors`; valid ones continue.
5. Persist `inbound_batches` (raw payload, hash, token, ip, counts, response) in one transaction; dispatch `ProcessInboundBatch` after commit.
6. Respond `202` (all valid) or `207` (some rejected) with `rejected[]`; `422` if every record is invalid (nothing queued, status `rejected`).

### 7.3 Processing (queued)

- Batches are processed **one at a time in arrival order** (FIFO queue + `WithoutOverlapping`). 1C must send items before prices/stock that reference new items; the combined `/batch` endpoint processes its sections in the fixed order units → groups → items (with prices) → warehouses → stock (with cost).
- Chunks of 500 records per DB transaction. Within `POST /items`: units, then groups (two-pass: upsert all, then link `parent_id`), then items.
- Per record:
  1. Resolve references by GUID (unit, group, item, warehouse). Missing required reference (item's unit, stock's item/warehouse) → record result `failed` with `unknown_reference`; others continue. The next full snapshot repairs it.
  2. **Out-of-order protection**: compare incoming `updated_at` (and `version` when sent) with stored `external_updated_at`. Older → `stale`, skipped (not an error). Equal timestamp with different content → applied (last arrival wins within the same second). Stock uses the batch `as_of` per row instead.
  3. Compute `external_hash` (sha256 of canonical JSON of mapped 1C-owned fields). Equal → `unchanged`.
  4. Upsert by `external_id`; write `activity_log` for updates (`synced_from_1c`, `price_changed`, `deleted_in_1c`); creations are audited by the batch row.
- Results: per-record outcome counts (`created`, `updated`, `unchanged`, `stale`, `deactivated`, `failed`) and up to 1 000 error entries stored on the batch; final status `processed` / `partial` / `failed`. `last_received_at` per feed (`items`, `stock`) updated when a batch for that feed is processed (not merely received).

### 7.4 Delta vs full snapshot

- `mode: "delta"`: only the records sent are upserted; deletions must be explicit (`is_deleted: true`; `sale_price: null` clears a price).
- `mode: "full"`: the sender states "this is everything for this feed (and scope)". Because full catalogs exceed one request, a snapshot is split into parts: `snapshot: { "id": "<uuid>", "part": 3, "total_parts": 12 }`. Each part is a normal batch (own `batch_id`). After the part that completes the set (all `1..total_parts` processed) the `SnapshotFinalizer` deactivates rows of that feed **not seen in any part** of that snapshot (`last_seen_snapshot_id != id`).
  - Scope: items feed = units, groups, items (prices travel with items); stock feed = per warehouse listed in `scope.warehouse_ids` (default: all warehouses in the snapshot).
  - Guard: if seen count < `ONEC_FULL_SNAPSHOT_GUARD_PERCENT`% (default 80) of currently active rows in scope, deactivation is skipped, the snapshot is marked `partial`, and an admin alert is raised (protects against a buggy empty export).
  - A snapshot whose parts do not all arrive within `ONEC_SNAPSHOT_TTL_MINUTES` (default 120) expires: upserts already done stay, no deactivation, alert.
  - Conflicting `total_parts` for the same snapshot id → 409 `snapshot_mismatch`.

### 7.5 Customer exchange (1C initiates every call)

1. A customer is saved in the CRM first (fully usable immediately). If it is pushable (BR-I.6) and the exchange is enabled, an outbox entry is opened and the customer becomes `pending` (`sync_queued_at` = now).
2. A 1C scheduled job (every 5 min by default) calls `GET /customers/pending` without a cursor, pages with `next_cursor` within the run, and for each record creates or updates the counterparty in 1C (1C deduplicates by INN on its side and may link an existing counterparty).
3. For each record 1C calls `POST /customers/ack` with `crm_id`, the `outbox_id` it processed, the 1C GUID/code and `synced` or `failed` (+ error). Acks are idempotent: repeating the same ack returns `already_applied`.
4. Anything not acknowledged stays pending and is returned again on the next run (runs always start without a cursor). If the customer is edited **after** 1C fetched it, a new entry supersedes the delivered one; an ack for the old entry still stores the GUID but answers `superseded`, and the newer change is delivered on the next poll.
5. 1C pushes its own new/changed counterparties with `POST /customers` (synchronous, ≤ 200 per request, idempotent by `batch_id`), applied per BR-I.11.
6. Freshness: every customer call records "last contact" per channel (`customers_poll`, `customers_ack`, `customers_push` in `integration_feeds`). If the exchange is enabled and 1C has not polled for `onec.customers_poll_stale_minutes` (default 30), the customer screens and Settings show "1C has not collected changes since …" (BR-I.23).

## 8. Business rules

- BR-I.1 Identity: every 1C-mastered row carries `external_id` = 1C GUID (lowercase, 36 chars), unique per table. Matching is only by `external_id`, never by name or code.
- BR-I.2 Read-only catalog: no create/edit/delete routes or Livewire actions for units, categories, items, item prices, warehouses, stock, except CRM-local fields (5.2).
- BR-I.3 Money from 1C: decimal strings parsed with BCMath into minor units of the price's currency (`sale_currency` / `purchase_currency` / `cost_currency`, default the CRM base currency). More decimals than the currency allows → rounded half-up and a warning in the batch result (D-I.14). Negative → record rejected. JSON numbers for money/quantities → record rejected (`must_be_decimal_string`).
- BR-I.4 Stock quantities: decimal strings, up to 3 decimals, stored `decimal(15,3)`. Negative balances accepted (1C allows them) and shown as 0 available with a warning icon. `available = quantity − reserved`, computed, floored at 0 for display.
- BR-I.5 Dates: datetimes must carry an offset; if missing, interpreted in `ONEC_TIMEZONE` (Asia/Tashkent). Stored in UTC. `valid_from` is a date.
- BR-I.6 Pushable customer: customer exchange enabled, `tax_id` present and valid (UZ 9 digits), `legal_type` in (`legal_entity`, `sole_proprietor`), `name` present, not archived, not "Deleted in 1C"; else `not_synced` with a translated reason (`integration_disabled`, `tax_id_missing`, `tax_id_invalid`, `legal_type_not_supported`, `archived`).
- BR-I.7 Outbox: at most one **open** entry per customer. While the open entry has not been delivered (never returned by a poll) further edits reuse it (the payload is read at poll time). Once delivered, the next edit/Resync supersedes it (`superseded_at`, reason `edited_after_delivery`) and opens a new entry, so 1C sees the change again. Entries are never deleted (audit); `delivery_count`, `first_delivered_at`, `last_delivered_at` track polls.
- BR-I.8 Ack (`POST /customers/ack`, idempotent):
  - `crm_id` unknown → `rejected` (`unknown_crm_id`).
  - Target = the entry named by `outbox_id`, else the customer's open entry; none → `rejected` (`nothing_pending`) unless the last acknowledged entry carries the same status and GUID (`already_applied`).
  - Already acknowledged with the same status and GUID → `already_applied` (no change). Acknowledged differently → `rejected` (`already_acknowledged`).
  - `synced`: `external_id` (GUID) required; must not belong to another customer (`external_id_taken`) and must equal an existing link (`external_id_mismatch`). Stores GUID, code, version and contact GUIDs by `crm_id`; status `synced`, error cleared, `sync_succeeded` logged. If the target entry was superseded meanwhile, the GUID is stored but the status stays `pending` and the answer is `superseded`.
  - `failed`: status `failed`, `sync_error` and `sync_error_fields` stored, `sync_failed` logged; the entry is closed (no automatic retry; Resync or edit re-queues).
- BR-I.9 1C-side validation errors arrive as `failed` acks; field errors are shown on the customer page.
- BR-I.10 Only shared fields are sent. Edits touching only CRM-only fields (owner, notes, payment terms, website, industry, currency, document language) do not re-queue.
- BR-I.11 Inbound customer (`POST /customers`), per record, inside `OneCSyncContext` (no re-queue, `synced_from_1c` audit):
  - Match order: `id` (GUID) → `crm_id` (our uuid, for counterparties 1C created from the outbox) → INN among CRM customers **not linked** to 1C.
  - INN: exactly 1 unlinked match → link (`linked`, `sync_linked_existing`); more than 1 → result `conflict` with the candidate `crm_id`s, nothing changed; none unlinked but linked customers carry the INN → create a new customer and flag all of them `has_duplicate_tax_id` (BR-I.17); no match → create (`origin=onec`).
  - Out of order: incoming `updated_at` older than the stored `external_updated_at` → `stale`; identical mapped content (hash) → `unchanged`.
  - **1C wins**: shared and 1C-only fields are applied even if a CRM edit is pending; the open outbox entry is superseded (`overwritten_by_1c`) and `sync_conflict_1c_won` is logged with the replaced CRM values; status `synced`.
  - `is_deleted: true` → `external_deleted_at` ("Deleted in 1C", read-only, excluded from pickers); `merged_into_id` stored; `legal_type: individual` → `skipped` (B2C not imported).
- BR-I.12 Units: `precision` is CRM-local; new units get it from an OKEI map in config (`796` шт → 0, `166` кг → 3, `112` л → 3, `006` м → 2, `055` м² → 2, `356` ч → 2); unknown → 0 + admin alert "set precision for new unit".
- BR-I.13a Accounting codes on items (shared fields, all nullable, indexed, not unique): `accountant_code` (string ≤ 64, the accountant's internal code), `class_code` (IKPU/MXIK product/service classifier code of the Uzbek national catalog used on e-invoices, digits only, ≤ 17; a length other than 17 is a warning, not an error) and `package_code` (package code from the same catalog, digits only, ≤ 32). Leading zeros are kept (strings). Optional in the `POST /items` payload (absent = unchanged; backward compatible). Rule: **a non-empty value from 1C overwrites; an empty or absent value from 1C never wipes a value** (whether it came from 1C or was entered in the CRM). Finance, the accountant and admin may edit them in the CRM item form (permission `catalog.edit_accounting_codes`, Spec 06 4.3). Snapshotted onto offer lines; only `accountant_code` may be printed, behind `documents.show_accountant_code` (default off). Exact official formats to be confirmed by the accountants; validation lives in one rule class.
- BR-I.13b Serial tracking: `items.is_serialized` boolean, default false. **1C does not keep serial numbers**; the serial registry lives only in the CRM (Spec 06: stock managers enter serials on receipt, sales/delivery pick them). The flag is optional in `POST /items` (`is_serialized`: `true`/`false`; absent or `null` = unchanged). It is editable in the CRM item form (permission `catalog.edit_serial_tracking`: admin, finance, stock manager and warehouse head). A value set in the CRM is overwritten only when 1C sends an explicit non-null `is_serialized`. Offer lines snapshot it; quantities of serialized items must be whole numbers. Changing the flag keeps existing serials (read-only when switched off); switching it on with stock but no registered serials shows the gap in the serials-vs-1C report until opening balances are registered (Spec 06 BR-SN.11).
- BR-I.13 Items: `sku` = 1C code (non-unique index in CRM; uniqueness owned by 1C), `article` = Артикул. VAT `vat_rate`: `"12"` → 1200 bp, `"0"` → 0, `"exempt"` → 0 + `is_vat_exempt=true`. Spec 01 category depth limit does not apply to 1C groups.
- BR-I.14 Prices (rev. 4): **one sale (out) price and one purchase (in) price per item**, each with its currency, stored in minor units on `items` (`sale_price_minor`/`sale_currency`, `purchase_price_minor`/`purchase_currency`) plus `prices_include_vat` for the sale price. No price types, price lists, customer prices, quantity tiers or dated/future prices. Absent key = unchanged; explicit `null` = cleared (1C is the master). Item without a sale price in the offer currency cannot be quoted (no FX conversion). Stock rows carry `cost_minor` (total cost of the quantity on hand) and `cost_currency`; 1C sends `cost` (total) or `unit_cost` (multiplied by `quantity`, rounded half-up). The item's **stock cost price** = Σ cost / Σ quantity over warehouses with a positive quantity and a known cost in the first costed currency (weighted average, rounded half-up). Cost basis for margins and the below-cost check = stock cost price, else purchase price, in the offer currency. Migration: the sale price was backfilled from the former default price list (base currency first), then price tables were dropped.
- BR-I.15 Deletions/merges: catalog `is_deleted` → `is_active=false`, never hard delete. Customers deletion-marked in 1C → `external_deleted_at`, "Deleted in 1C", read-only, excluded from pickers; `merged_into_id` stored + admin alert; re-pointing documents is manual (future).
- BR-I.16 Customer import scope (inbound push): buyers only, not individuals. Owner at first import: CRM user with email = `manager.email`, else `onec.default_owner_id`. `currency` = `config('crm.base_currency')`.
- BR-I.17 Duplicate INNs inside 1C must not fail import: `customers.tax_id` index is non-unique; the unique-INN rule (Spec 01 BR-5) applies only to customers created in the CRM (app validation incl. archived). Imported duplicates get `has_duplicate_tax_id=true`.
- BR-I.18 API security: HTTPS only (non-HTTPS → 403 `https_required` outside `local`; behind a proxy configure TrustProxies); tokens hashed (sha256), shown once, never logged; generic 401 message; optional per-token CIDR allowlist (403 `ip_not_allowed`); optional HMAC; no cookies/session/CSRF on the API; JSON-only (`415` for other content types). The CRM stores no 1C credentials at all.
- BR-I.18a Security hardening (security audit, mandatory):
  - **No outbound calls**: the CRM never opens a connection to 1C, so there is no 1C URL, no 1C credential and no SSRF surface (the rev. 2 host allowlist, redirect and TLS-verification rules are obsolete). A test fails if an HTTP client (`Http::`, Guzzle, cURL, `file_get_contents('http…')`) appears in `app/`.
  - Client IP for the inbound allowlist: `X-Forwarded-For` is honoured only when the direct peer is a configured trusted proxy (`TRUSTED_PROXIES`, explicit IPs/CIDRs, never `*`); otherwise `REMOTE_ADDR` is used. The allowlist test covers a spoofed `X-Forwarded-For` from an untrusted peer.
  - Token lifetime: every integration token has `expires_at`, default 180 days, maximum 365 days (`ONEC_TOKEN_MAX_DAYS`); tokens without expiry cannot be created; the Integrations page and dashboard warn 14 days before expiry. Rotation procedure as in 11.4.
  - HMAC: optional only when the caller is on a private network (RFC 1918, loopback, or CIDRs in `ONEC_PRIVATE_NETWORKS`, e.g. the VPN). A request from any other source IP is refused with 401 `signature_required` unless the token has a signing secret and the request is signed (9.1). The admin UI requires a signing secret when the token's IP allowlist is empty or contains public addresses.
- BR-I.19 Logs: `integration_logs` hold method, path (no query string), status, duration, error code, request id, token prefix, subject/external_id; **no bodies, names, phones, emails, INNs or tokens**. Raw inbound catalog payloads (no personal data) are stored in `inbound_batches` for 30 days for replay. Customer pushes contain personal data, so only their hash and response (GUIDs, crm_ids, result codes) are kept for idempotent replay; the body is not stored.
- BR-I.20 Rate limits: one `onec-api` limiter per token for every endpoint incl. customers, default 60 requests/min; 429 with `Retry-After`. Acks accept up to 500 items per request, so a poll run fits comfortably.
- BR-I.21 Staleness: thresholds per feed in settings (defaults: stock 30 min, items 1 440 min; prices travel with items). A feed is stale when `now − last_received_at` > threshold. Stale → red badge on Items/Item show and dashboard alert for admin/manager/finance. Thresholds only apply while the integration is enabled.
- BR-I.22 Integration disabled: catalog endpoints return 503 `integration_disabled` while `onec.inbound_enabled` is off; customer endpoints return 503 while `onec.customers_enabled` is off (1C retries later; nothing stored); new customers `not_synced` (`integration_disabled`); catalog read-only with last data and its age. Re-enabling the customer exchange queues every pushable `not_synced` customer (`onec:queue-customers`).
- BR-I.23 Customer poll staleness: while the customer exchange is enabled, the poll is stale when `now − last poll` > `onec.customers_poll_stale_minutes` (default 30). Stale → warning on pending customers ("pending since 10:42; 1C has not collected changes since 09:58") and on Settings > Integrations.

## 9. CRM inbound API contract (1C → CRM)

All fields are **proposed** for the 1C team to confirm. snake_case JSON.

### 9.1 General
- Base URL: `https://<crm-host>/api/v1/integration/1c`. Breaking changes → `/v2`; additive fields allowed in `v1` (CRM ignores unknown fields).
- Headers from 1C: `Authorization: Bearer <token>` (required), `Content-Type: application/json; charset=utf-8` (required), `Accept: application/json`, `Idempotency-Key: <batch_id>` (recommended), `X-Request-Id` (optional; echoed back), `X-Timestamp` + `X-Signature` (only if HMAC enabled for the token).
- Encoding: UTF-8 **without BOM** (CRM strips it defensively). GUIDs lowercase, 36 chars. **Money and quantities as decimal strings** (`"125000.00"`, `"12.500"`), never JSON numbers. Datetimes ISO 8601 with offset (`2026-10-16T14:05:00+05:00`). Dates `YYYY-MM-DD`. Currency ISO 4217 alpha-3 (`UZS`, `USD`), not 1C numeric codes.
- Limits: ≤ 1 000 records per request (sum of all arrays), ≤ 10 MB body, 60 requests/min per token. Larger sets → split into a multi-part snapshot or several delta batches.
- Every 1C object carries `id` (GUID), `updated_at`, `is_deleted`; `version` (1C ВерсияДанных) optional but recommended.
- Optional HMAC: `X-Signature: sha256=<hex(HMAC_SHA256(secret, X-Timestamp + "." + raw_body))>`, `X-Timestamp` = Unix seconds; rejected if outside ±300 s (401 `invalid_signature`).

### 9.2 Envelope (all POST endpoints)
```json
{
  "batch_id": "0f8b6c2e-6a3d-4c3b-9b1e-2d6f1a7c9e10",
  "mode": "delta",
  "sent_at": "2026-10-16T14:05:00+05:00",
  "snapshot": null,
  "source": { "infobase": "UT_UZ", "exchange_node": "CRM", "message_no": 18234 }
}
```
- `batch_id`: UUID generated by 1C per request; **reuse the same id when retrying the same request**.
- `mode`: `delta` | `full`. For `full`, `snapshot` is required: `{ "id": "<uuid>", "part": 1, "total_parts": 12, "scope": { "warehouse_ids": [...] } }` (`scope` only for stock).
- `source`: informational, stored for audit.

### 9.3 Endpoints

| Method & path | Ability | Purpose |
|---|---|---|
| `GET /ping` | any valid token | Health/auth check for 1C. 200 `{ "status": "ok", "api_version": "1.0", "server_time": "…", "token": { "name": "1C production", "abilities": ["items:write","stock:write"] }, "integration_enabled": true }` |
| `POST /items` | `items:write` | Units, item groups and items |
| `POST /item-stock` | `stock:write` | Warehouses and stock balances |
| `POST /batch` | all abilities for the sections present | Optional combined batch: `{ envelope…, "items": {units, groups, items}, "stock": {as_of, warehouses, stock} }`, processed in fixed order. Useful for initial load. `mode` applies to every section |
| `GET /batches/{batch_id}` | `batches:read` | Processing status and per-record results (only batches sent by the same token) |
| `GET /customers/pending` | `customers:read` | Poll the outbox of CRM customers (section 10) |
| `POST /customers/ack` | `customers:write` | Acknowledge polled customers (section 10) |
| `POST /customers` | `customers:write` | Upsert 1C counterparties into the CRM (section 10) |

### 9.4 `POST /items`
```json
{
  "batch_id": "0f8b6c2e-6a3d-4c3b-9b1e-2d6f1a7c9e10",
  "mode": "delta",
  "sent_at": "2026-10-16T14:05:00+05:00",
  "units": [
    { "id": "5f0c1a2b-0000-4000-8000-000000000796", "code": "796", "name": "шт", "full_name": "Штука",
      "is_deleted": false, "updated_at": "2026-10-01T10:00:00+05:00" }
  ],
  "groups": [
    { "id": "8a1e0000-0000-4000-8000-000000000012", "code": "00-000012", "name": "Brake systems",
      "parent_id": null, "is_deleted": false, "updated_at": "2026-10-01T10:00:00+05:00" }
  ],
  "items": [
    {
      "id": "a1b2c3d4-0000-4000-8000-000000000001",
      "code": "00-00001234",
      "article": "BRK-100",
      "name": "Brake pad BRK-100",
      "full_name": "Brake pad BRK-100, front, set of 4",
      "description": null,
      "type": "product",
      "group_id": "8a1e0000-0000-4000-8000-000000000012",
      "unit_id": "5f0c1a2b-0000-4000-8000-000000000796",
      "vat_rate": "12",
      "accountant_code": "41.01-1234",
      "class_code": "08708001001000000",
      "package_code": "1503778",
      "is_serialized": false,
      "sale_price": "125000.00",
      "sale_currency": "UZS",
      "prices_include_vat": true,
      "purchase_price": "88000.00",
      "purchase_currency": "UZS",
      "is_deleted": false,
      "version": "AAAAAwAAAAA=",
      "updated_at": "2026-10-16T09:12:44+05:00"
    }
  ]
}
```
Required per item: `id`, `code`, `name`, `type` (`product`|`service`), `unit_id`, `vat_rate` (`"12"`, `"0"`, `"exempt"` or another integer percent string), `is_deleted`, `updated_at`. Units/groups may be omitted when unchanged, but a referenced unit must exist in the CRM or in the same batch. Optional: `accountant_code` (string ≤ 64), `class_code` (digit string ≤ 17), `package_code` (digit string ≤ 32), each `null`/`""`/absent = keep the stored value (BR-I.13a); an invalid value rejects the record (`invalid_format`). Optional `is_serialized` (boolean; absent/`null` = keep; BR-I.13b). Prices (rev. 4, BR-I.14): `sale_price`, `purchase_price` (decimal strings per the item's base unit; absent = keep, `null` = clear), `sale_currency`, `purchase_currency` (ISO 4217, default the CRM base currency), `prices_include_vat` (boolean, sale price basis).

### 9.5 (removed in rev. 4) `POST /item-prices`
The endpoint, price types and dated prices are gone: each item's single sale and purchase price travel in the item record (9.4, BR-I.14). Calls to `/item-prices` answer 404.

### 9.6 `POST /item-stock`
```json
{
  "batch_id": "9e7f…",
  "mode": "full",
  "sent_at": "2026-10-16T14:10:02+05:00",
  "snapshot": { "id": "41aa…", "part": 1, "total_parts": 1, "scope": { "warehouse_ids": ["b1000000-0000-4000-8000-000000000001"] } },
  "as_of": "2026-10-16T14:10:00+05:00",
  "warehouses": [
    { "id": "b1000000-0000-4000-8000-000000000001", "code": "000000001", "name": "Main warehouse Tashkent",
      "is_deleted": false, "updated_at": "2026-01-10T09:00:00+05:00" }
  ],
  "stock": [
    { "item_id": "a1b2c3d4-0000-4000-8000-000000000001", "warehouse_id": "b1000000-0000-4000-8000-000000000001",
      "quantity": "120.000", "reserved": "20.000", "cost": "10560000.00", "cost_currency": "UZS" }
  ]
}
```
- `as_of` (required) is the moment the balances were calculated in 1C; a row is updated only if `as_of` is newer than the stored one (out-of-order protection).
- Recommended: full snapshot every 10 min (stock deltas are error-prone); delta allowed (`quantity` of the rows sent).
- Stock cost (rev. 4, optional): `cost` = total cost of `quantity` (decimal string, may be negative with a negative balance) or `unit_cost` (multiplied by `quantity`); `cost_currency` default the CRM base currency. Neither sent = cost unknown. The CRM shows the weighted-average stock cost price on the item page and uses it as cost basis (BR-I.14).

### 9.7 Responses

202 Accepted (all records valid, queued)
```json
{
  "batch_id": "0f8b6c2e-6a3d-4c3b-9b1e-2d6f1a7c9e10",
  "status": "queued",
  "received": { "units": 1, "groups": 1, "items": 1 },
  "accepted": 3,
  "rejected": [],
  "status_url": "/api/v1/integration/1c/batches/0f8b6c2e-6a3d-4c3b-9b1e-2d6f1a7c9e10",
  "request_id": "c1d2…"
}
```

207 Multi-Status (some records rejected at validation; the valid ones are queued). 1C should log the rejected records and fix them at the source; **do not resend the whole batch** with the same `batch_id` (it would be replayed); send corrections in a new batch.
```json
{
  "batch_id": "0f8b…",
  "status": "queued",
  "accepted": 998,
  "rejected": [
    { "section": "items", "index": 17, "id": "a1b2…0042", "errors": [ { "field": "unit_id", "code": "required", "message": "unit_id is required" } ] },
    { "section": "items", "index": 3, "id": "a1b2…0050", "errors": [ { "field": "sale_price", "code": "must_be_decimal_string", "message": "sale_price must be a decimal string" } ] }
  ],
  "request_id": "…"
}
```

`GET /batches/{batch_id}` (after processing)
```json
{
  "batch_id": "0f8b…", "status": "partial", "mode": "delta",
  "received_at": "2026-10-16T09:05:01Z", "processed_at": "2026-10-16T09:05:09Z",
  "counts": { "created": 10, "updated": 950, "unchanged": 30, "stale": 5, "deactivated": 0, "failed": 3, "rejected": 2 },
  "errors": [ { "section": "items", "id": "a1b2…0077", "code": "unknown_reference", "message": "unit 5f0c… not found" } ],
  "snapshot": null
}
```

Error envelope (all non-2xx)
```json
{ "error": { "code": "validation_failed", "message": "The batch_id field is required.", "fields": { "batch_id": ["required"] }, "request_id": "…" } }
```

| HTTP | `code` | 1C should |
|---|---|---|
| 200 | (replay) | same body as the original; treat as success |
| 202 / 207 | – | mark the exchange message as delivered; log `rejected[]` |
| 401 | `unauthenticated`, `invalid_signature` | stop; alert 1C admin (token wrong/revoked/clock skew) |
| 403 | `forbidden_ability`, `ip_not_allowed`, `https_required` | stop; fix configuration |
| 409 | `idempotency_key_reused`, `snapshot_mismatch` | bug on 1C side; generate a new `batch_id` only for genuinely new content |
| 413 | `payload_too_large` | split into smaller batches |
| 415 | `unsupported_media_type` | send JSON |
| 422 | `validation_failed` (envelope invalid or all records invalid) | fix and send a new batch |
| 429 | `rate_limited` + `Retry-After` | wait and retry the **same** `batch_id` |
| 500 / 502 / 503 / 504, timeout | `internal_error`, `integration_disabled`, … | retry the **same** `batch_id` with backoff (1, 5, 15, 60 min) |

### 9.8 1C-side example (BSL pseudo-code, for the 1C developer)

```bsl
// Common server module CRMExchange. Scheduled jobs call SendItems (with prices)/SendStock (with cost).
Function SendBatch(Resource, Data) Export
    // Data: Structure with batch_id, mode, sent_at, ... (prices/quantities already strings)
    SSL = New OpenSSLSecureConnection();
    Connection = New HTTPConnection(CRMHost(), 443, , , , 60, SSL);
    Request = New HTTPRequest("/api/v1/integration/1c/" + Resource);
    Request.Headers.Insert("Authorization", "Bearer " + CRMToken()); // read from SafeDataStorage, not a constant
    Request.Headers.Insert("Content-Type", "application/json; charset=utf-8");
    Request.Headers.Insert("Accept", "application/json");
    Request.Headers.Insert("Idempotency-Key", Data.batch_id);

    Writer = New JSONWriter;
    Writer.SetString();
    WriteJSON(Writer, Data);
    Request.SetBodyFromString(Writer.Close(), TextEncoding.UTF8, ByteOrderMarkUse.DontUse);

    For Attempt = 1 To 4 Do
        Try
            Response = Connection.Post(Request);
        Except
            Pause(RetryDelay(Attempt)); Continue;           // network error: retry same batch_id
        EndTry;
        If Response.StatusCode = 200 Or Response.StatusCode = 202 Or Response.StatusCode = 207 Then
            LogRejected(Response.GetBodyAsString());          // 207: log rejected[] records
            Return True;                                      // e.g. mark exchange-plan message delivered
        ElsIf Response.StatusCode = 429 Or Response.StatusCode >= 500 Then
            Pause(RetryDelay(Attempt)); Continue;
        Else
            WriteLogEvent("CRMExchange", EventLogLevel.Error, , , Response.GetBodyAsString());
            Return False;                                     // 4xx: do not retry, fix data/config
        EndIf;
    EndDo;
    Return False;
EndFunction

// Helpers: money as string without grouping, e.g. Format(Price, "ND=15; NFD=2; NDS=.; NG=0; NZ=0.00")
// dates: Format(Date, "DF='yyyy-MM-ddTHH:mm:ss'") + "+05:00"; GUID: Lower(String(Ref.UUID()))
// batch_id: Lower(String(New UUID)) generated once per message and stored with it for retries.
// Delta source: exchange plan node "CRM" (registered changes of Nomenclature, Prices register, Units, Groups).
```

Customers (scheduled job "CRMCustomers", every 5 min; same connection and token helpers):

```bsl
Procedure ExchangeCustomers() Export
    PushChangedCounterparties();   // 1C -> CRM: our new/changed buyers
    CollectCRMCustomers();         // CRM -> 1C: poll the outbox, create/update, acknowledge
EndProcedure

Procedure PushChangedCounterparties()
    Changes = SelectRegisteredChanges("CRM", "Catalog.Контрагенты", 200);   // exchange plan node, <= 200
    While Changes.Count() > 0 Do
        Data = New Structure("batch_id, sent_at, customers", Lower(String(New UUID)), NowWithOffset(), New Array);
        For Each Ref In Changes Do Data.customers.Add(CounterpartyToStructure(Ref)); EndDo;   // id, crm_id, tax_id, contacts, version, updated_at
        Response = CallCRM("POST", "customers", Data);          // retries 429/5xx with the SAME batch_id
        If Response = Undefined Then Return; EndIf;            // try again next run
        LogResults(Response.results);                           // conflict/failed records -> 1C admin review
        DeleteChangeRegistration("CRM", Changes);
        Changes = SelectRegisteredChanges("CRM", "Catalog.Контрагенты", 200);
    EndDo;
EndProcedure

Procedure CollectCRMCustomers()
    Cursor = "";                                                // every run starts from the oldest pending entry
    Repeat
        Page = CallCRM("GET", "customers/pending?limit=200" + ?(Cursor = "", "", "&cursor=" + Cursor), Undefined);
        If Page = Undefined Then Return; EndIf;                 // network/5xx: next run
        Acks = New Array;
        For Each Customer In Page.data Do
            Try
                Ref = FindByGUIDOrCrmId(Customer.external_id, Customer.crm_id);
                If Ref = Undefined Then Ref = FindByINN(Customer.tax_id); EndIf;      // dedupe: link instead of creating
                Ref = CreateOrUpdateCounterparty(Ref, Customer);                       // stores crm_id in an additional attribute
                Acks.Add(New Structure("crm_id, outbox_id, status, external_id, external_code, external_version, contacts",
                    Customer.crm_id, Customer.outbox_id, "synced", Lower(String(Ref.UUID())), Ref.Code, Ref.DataVersion, ContactGUIDs(Ref)));
            Except
                Acks.Add(New Structure("crm_id, outbox_id, status, error", Customer.crm_id, Customer.outbox_id, "failed",
                    New Structure("code, message", "validation_failed", BriefErrorDescription(ErrorInfo()))));
            EndTry;
        EndDo;
        If Acks.Count() > 0 Then CallCRM("POST", "customers/ack", New Structure("acks", Acks)); EndIf;   // safe to repeat
        Cursor = Page.next_cursor;
    Until Not Page.has_more;
EndProcedure
```

## 10. Customer exchange contract (1C pushes and polls; the CRM never calls 1C)

Replaces the rev. 2 "1C HTTP service". Same base URL, headers, typing rules, error envelope and rate limit as section 9. Customer endpoints are switched by `onec.customers_enabled` (503 `integration_disabled` while off).

### 10.1 `GET /customers/pending?limit=100&cursor=<opaque>` (`customers:read`)
- Open outbox entries, oldest first. `limit` 1..500 (default 100). `cursor` = `next_cursor` of the previous page **within the same run**; start every run without a cursor so unacknowledged entries come back.
- Each call records "last poll"; returned entries get `delivery_count + 1` and `first/last_delivered_at`.

```json
{
  "data": [
    {
      "outbox_id": 1842, "queued_at": "2026-10-18T10:42:00+05:00", "reason": "created", "delivery_count": 1,
      "crm_id": "0b9a5c1e-7d2f-4e1a-9c3b-5a6d7e8f9a0b", "external_id": null, "external_version": null,
      "legal_type": "legal_entity", "name": "Alpha Trade", "full_name": "\"ALPHA TRADE\" MChJ", "tax_id": "123456789",
      "country": "UZ", "city": "Tashkent", "address": "Amir Temur st. 1", "postal_code": "100000",
      "phone": "+998901234567", "email": "info@alpha.uz",
      "contacts": [
        { "crm_id": "3c2d…", "external_id": null, "first_name": "Aziz", "last_name": "Karimov", "position": "Purchasing manager",
          "phone": "+998901112233", "email": "aziz@alpha.uz", "is_primary": true, "is_deleted": false }
      ]
    }
  ],
  "next_cursor": "1842", "has_more": false, "server_time": "2026-10-18T10:45:03+05:00", "request_id": "…"
}
```
`reason`: `created` | `updated` | `contacts` | `resync`. `external_id` is set when the customer is already linked (1C must update, not create). Archived CRM contacts are sent with `is_deleted: true`.

### 10.2 `POST /customers/ack` (`customers:write`, idempotent)
Body: one ack object or `{ "acks": [ … ] }` (≤ 500).
```json
{ "acks": [
  { "crm_id": "0b9a…", "outbox_id": 1842, "status": "synced",
    "external_id": "e3f40000-0000-4000-8000-000000012345", "external_code": "000012345", "external_version": "AAAAAQAAAAA=",
    "contacts": [ { "crm_id": "3c2d…", "external_id": "…" } ] },
  { "crm_id": "9f00…", "outbox_id": 1843, "status": "failed",
    "error": { "code": "validation_failed", "message": "INN checksum invalid", "fields": { "tax_id": ["invalid"] } } }
] }
```
Response 200 (all applied/already applied/superseded) or 207 (some rejected); 422 only when the body itself is invalid:
```json
{ "results": [
  { "index": 0, "crm_id": "0b9a…", "outbox_id": 1842, "result": "applied" },
  { "index": 1, "crm_id": "9f00…", "outbox_id": 1843, "result": "already_applied" }
], "request_id": "…" }
```
`result`: `applied` | `already_applied` | `superseded` (GUID stored; a newer change will be delivered on the next poll) | `rejected` (+ `errors[]`: `unknown_crm_id`, `unknown_outbox_id`, `nothing_pending`, `already_acknowledged`, `external_id_taken`, `external_id_mismatch`, `validation_failed`).

### 10.3 `POST /customers` (`customers:write`, synchronous, idempotent by `batch_id`)
```json
{
  "batch_id": "7d0c…", "sent_at": "2026-10-18T10:40:00+05:00",
  "customers": [
    { "id": "e3f40000-0000-4000-8000-000000012345", "code": "000012345", "crm_id": null,
      "legal_type": "legal_entity", "name": "Alpha Trade", "full_name": "\"ALPHA TRADE\" MChJ", "tax_id": "123456789",
      "country": "UZ", "city": "Tashkent", "address": "Amir Temur st. 1", "postal_code": "100000",
      "phone": "+998901234567", "email": "info@alpha.uz",
      "manager": { "name": "Ivanov I.", "email": "ivanov@company.uz" },
      "contacts": [ { "id": "…", "crm_id": null, "first_name": "Aziz", "last_name": "Karimov", "position": "Purchasing manager",
                      "phone": "+998901112233", "email": "aziz@alpha.uz", "is_deleted": false } ],
      "is_deleted": false, "merged_into_id": null, "version": "AAAABQAAAAA=", "updated_at": "2026-10-18T10:30:00+05:00" }
  ]
}
```
- ≤ 200 records; required per record: `id`, `name`, `legal_type`, `is_deleted`, `updated_at`.
- Response 200 (no record `failed`/`conflict`) / 207 (some) / 422 (envelope invalid):
```json
{ "batch_id": "7d0c…", "results": [ { "index": 0, "id": "e3f4…", "crm_id": "0b9a…", "result": "linked" } ], "request_id": "…" }
```
`result`: `created` | `updated` | `linked` | `unchanged` | `stale` | `skipped` | `conflict` (+ `candidates`: crm_ids) | `failed` (+ `errors[]`). Same `batch_id` + same body → stored response replayed with `Idempotent-Replayed: true`; different body → 409 `idempotency_key_reused`.

### 10.4 What 1C should do
| HTTP / result | 1C should |
|---|---|
| 200/207 | apply results; log `rejected`/`conflict`/`failed` records for the 1C admin |
| 401/403 | stop; token wrong, revoked, missing `customers:*` ability or IP not allowed |
| 429, 5xx, timeout | retry later (same `batch_id` for pushes; acks are idempotent) |
| 503 `integration_disabled` | customer exchange switched off in the CRM; retry next run |

## 11. Data model

Spec 01 conventions apply (money minor units, decimals as strings, string enums, portable migrations).

### 11.1 Sync columns (trait `SyncsWithOneC`)
On `units`, `categories`, `items`, `warehouses`, `customers`, `contacts`:

| Column | Type | Notes |
|---|---|---|
| external_id | char(36), unique; NOT NULL on catalog tables, nullable on customers/contacts | 1C GUID |
| external_code | string(50), nullable, index | 1C code |
| external_version | string(64), nullable | 1C `version` if sent |
| external_updated_at | timestamp, nullable | 1C `updated_at` (UTC); out-of-order guard |
| external_hash | char(64), nullable | sha256 of mapped 1C-owned fields |
| synced_at | timestamp, nullable | last applied change |
| external_deleted_at | timestamp, nullable | deletion seen |
| last_seen_snapshot_id | char(36), nullable, index | catalog tables only; full-snapshot deletion detection |

### 11.2 Catalog changes vs Spec 01
- `items`: `sku` non-unique index; add `article` string(64) nullable index; add `is_vat_exempt` boolean; **drop `base_price_minor` and `currency`**; rev. 4 adds `sale_price_minor`, `sale_currency`, `purchase_price_minor`, `purchase_currency`, `prices_include_vat`.
- `price_lists`, `price_list_items`, `customers.price_list_id`, `offers.price_list_id`, `offer_lines.price_list_id/price_list_external_id`: **dropped in rev. 4** (migration `drop_price_lists`, after backfilling the sale price).
- `item_stocks` (rev. 4): add `cost_minor` (signed bigint, total cost) and `cost_currency`.
- `units.code` = 1C code (string(16) unique).

### 11.3 New catalog tables
`warehouses`: name string(255), is_active boolean, + sync columns.

`item_stocks` (current balance, one row per item × warehouse):
| Column | Type | Notes |
|---|---|---|
| item_id | FK items, cascadeOnDelete | |
| warehouse_id | FK warehouses, cascadeOnDelete | |
| quantity | decimal(15,3) | on hand |
| reserved | decimal(15,3), default 0 | |
| as_of | timestamp | 1C calculation time (UTC); out-of-order guard |
| received_at | timestamp | when the CRM applied it |
| last_seen_snapshot_id | char(36), nullable | |
Unique (`item_id`, `warehouse_id`); index (`warehouse_id`).

### 11.4 Integration tables
`integration_tokens`:
| Column | Type | Notes |
|---|---|---|
| name | string(100) | e.g. "1C production" |
| token_prefix | string(12), index | first chars, shown in UI and logs |
| token_hash | char(64), unique | sha256 of the plain token; plain token never stored |
| abilities | json | subset of `items:write`, `stock:write`, `batches:read`, `customers:read`, `customers:write` (`prices:write` removed in rev. 4) |
| allowed_ips | json, nullable | list of IPs/CIDRs; null = any |
| signing_secret | text, nullable | encrypted (`Crypt`); null = HMAC off |
| expires_at | timestamp, nullable | |
| last_used_at, last_used_ip | timestamp / string(45), nullable | |
| revoked_at | timestamp, nullable | |
| created_by, revoked_by | FK users, nullable, nullOnDelete | |
| timestamps | | |

Rotation: create token B → configure 1C → verify `last_used_at` of B → revoke A. Several active tokens allowed.

`inbound_batches`:
| Column | Type | Notes |
|---|---|---|
| batch_id | char(36), unique | from 1C |
| integration_token_id | FK, nullOnDelete | |
| endpoint | string(20), index | `items`, `prices`, `stock`, `batch` |
| mode | string(10) | `delta` \| `full` |
| snapshot_id | char(36), nullable, index | |
| snapshot_part, snapshot_total_parts | unsigned smallint, nullable | |
| status | string(15), index | `InboundBatchStatus` |
| payload_hash | char(64) | sha256 of raw body |
| payload | longText, nullable | raw JSON; pruned after 30 days (rejected: 7 days) |
| record_count, accepted_count, rejected_count | unsigned int | |
| counts | json, nullable | processing counters |
| errors | json, nullable | rejected + processing errors (max 1 000) |
| response_status | unsigned smallint | for idempotent replay |
| response_body | json | for idempotent replay |
| source | json, nullable | 1C `source` block |
| ip | string(45) | |
| request_id | char(36) | |
| received_at, processing_started_at, processed_at | timestamps | |
| reprocessed_by | FK users nullable | |

`inbound_snapshots`: id char(36) PK (1C snapshot id), endpoint, scope json, total_parts, parts_processed json, status (`collecting`, `finalized`, `partial`, `expired`), started_at, finalized_at.

`integration_feeds`: feed string(20) PK (`items`, `prices`, `stock`, `customers_poll`, `customers_ack`, `customers_push`), last_received_at (= last contact for the customer channels), last_batch_id, last_full_snapshot_at, updated_at. Drives "last received / last contact from 1C" and staleness.

`customer_outbox` (append-only except delivery/ack bookkeeping): id (cursor), customer_id FK cascade, reason (`created`, `updated`, `contacts`, `resync`), queued_by FK users nullable, queued_at, first_delivered_at, last_delivered_at, delivery_count, acked_at, ack_status (`synced`/`failed`), ack_external_id, ack_error json, superseded_at, superseded_reason (`edited_after_delivery`, `overwritten_by_1c`), timestamps. Indexes (`acked_at`, `superseded_at`, `id`) and (`customer_id`, `id`).

`integration_logs` (append-only, pruned after 90 days): inbound_batch_id nullable, direction (always `inbound`: 1C calls the CRM), level, method, path, http_status, duration_ms, request_id, token_prefix, error_code, message (sanitized), subject morph nullable, external_id nullable, created_at.

### 11.5 Customer and item columns
`customers`: `uuid` (sent as `crm_id`), `origin` (`crm`|`onec`), `legal_type`, `sync_status`, `sync_error`, `sync_error_fields` json, `sync_attempts`, `sync_idempotency_key`, `sync_conflict_payload` json, `last_pushed_at` (unused since rev. 3), `sync_queued_at` (open outbox entry since, rev. 3), `merged_into_external_id`, `has_duplicate_tax_id`; `tax_id` non-unique index. `sync_idempotency_key`/`sync_conflict_payload` are unused since rev. 3. `contacts`: `uuid`. `items` (rev. 3): `accountant_code` string(64), `class_code` string(32), `package_code` string(32), all nullable + indexed (BR-I.13a); `is_serialized` boolean default false, indexed (BR-I.13b).

Settings keys (`settings` table): `onec.inbound_enabled`, `onec.customers_enabled`, `onec.customers_poll_stale_minutes`, `onec.stale_minutes.items|stock`. No 1C URL or credentials.

### 11.6 Migration order
users alters → activity_log → integration_tokens → inbound_batches → inbound_snapshots → integration_feeds → integration_logs → units → categories → items → warehouses → item_stocks → customers → contacts. Rev. 3 adds `customer_outbox`, `customers.sync_queued_at` and the item accounting codes in new additive migrations.

## 12. UI/UX

All strings via keys in `lang/{uz,ru,en}/integration.php` (uz default); colours from theme tokens (red primary/danger, silver neutral, white surfaces).

| Screen | Changes |
|---|---|
| Items index / show | No create/edit/delete. Header badge "Synced from 1C" (`integration.badge.synced_from_1c`) and "Last received from 1C: 5 min ago" (`integration.last_received`) per feed (items, stock); red "Data may be outdated" (`integration.stale`) when BR-I.21 triggers. Columns: 1C code, article, accountant code, name, group, unit, VAT, sale price, available stock. Show: tab Prices (sale price with VAT basis, purchase price, stock cost price, margin), Stock (per warehouse with cost and `as_of`), Activity. CRM-local fields form for manager/admin. "Deleted in 1C" badge |
| Categories / Units | Read-only; admin edits unit `precision` (price-list screens removed in rev. 4) |
| Customer show / form | Sync badge (icon + text): synced = silver + check, pending = silver + sync icon **"Pending since 10:42"**, failed = red + error text, conflict = red outline, not_synced = outline + reason. **Stale warning** when the exchange is on and 1C has not polled within the threshold: "1C has not collected changes since 09:58". 1C code, GUID, last synced, last delivery to 1C. `tax_id` locked once linked. Field errors from a `failed` ack. **Resync** (re-queue in the outbox; policy `resync`). "Deleted in 1C" banner = read-only |
| Customers index | Filters: sync status, origin, duplicates in 1C, deleted in 1C |
| Settings > Integrations (admin) | **Inbound API**: API base URL to give 1C; tokens (abilities incl. `customers:read/write`), shown once; staleness thresholds per feed; feed status cards. **Customer exchange (1C polls the CRM)**: switch "Exchange customers with 1C", "Alert when 1C has not polled for N minutes" (the schedule we expect from the 1C job), **"Last contact from 1C"** for poll / ack / push (replaces "Test connection": the CRM cannot reach 1C), outbox counters (pending, oldest pending since, failed). No 1C URL or credentials |
| Settings > Integrations > Batches | Paginated `inbound_batches` (time, endpoint, mode, snapshot part, status, counts); detail with rejected/failed records (section, index, GUID, error) and "Reprocess" (admin, confirm, logged). Outbound runs list similarly from `sync_runs` |
| Dashboard | Alerts (admin/manager/finance): "No stock from 1C for 47 min", "Prices/items stale", "Snapshot incomplete/expired", "Full snapshot deactivation skipped", "Batches with rejected records today", "1C has not polled customers for 47 min", "N customers failed/conflict", "New units need precision", "Queue not processing". Sales: "My customers not synced" |

Empty state: catalog empty → "Items arrive from 1C. Ask an admin to create an API token and share it with the 1C team." (`integration.empty.catalog`).

## 13. Acceptance criteria

Inbound API — auth and transport
- AC-I.1 Given no/unknown/revoked/expired token, when calling any inbound endpoint, then 401 `unauthenticated` with a generic message and no batch stored.
- AC-I.2 Given a token with only `stock:write`, when calling `POST /items`, then 403 `forbidden_ability`; `GET /ping` returns 200 with its abilities.
- AC-I.3 Given a token with allowlist `10.0.0.5/32`, when called from `10.0.0.6`, then 403 `ip_not_allowed`.
- AC-I.4 Given HMAC enabled, when the signature is wrong or `X-Timestamp` is 10 min old, then 401 `invalid_signature`.
- AC-I.5 Given `APP_ENV=production` and a plain-HTTP request, then 403 `https_required`.
- AC-I.6 Given a request with a session cookie and no CSRF token, then it is neither required nor used (stateless); a web-session user without token gets 401.
- AC-I.7 Given 61 requests in a minute with one token, then the 61st gets 429 with `Retry-After`.
- AC-I.8 Given integration disabled, then POST endpoints return 503 `integration_disabled` and nothing is stored.
- AC-I.9 Given an admin creates a token, then the plain token is displayed once, only its sha256 is stored, and reloading the page never shows it again; revoking it makes the next call 401.

Inbound API — validation, idempotency, processing
- AC-I.10 Given a valid 1 000-record items batch, then the response is 202 within 1 s (p95 in test env), a `queued` batch row exists, and processing creates the records with `external_id`, `sku` = code, unit and group resolved.
- AC-I.11 Given 1 001 records or a body > 10 MB, then 413/422 and nothing queued.
- AC-I.12 Given 3 of 100 items invalid (missing `unit_id`, price as JSON number, unknown `type`), then 207 lists exactly those 3 with section/index/id/errors and the other 97 are processed.
- AC-I.13 Given all records invalid or a missing `batch_id`, then 422 and batch status `rejected`.
- AC-I.14 Given the same `batch_id` and identical body is sent twice, then the second response equals the first (status and body) with `Idempotent-Replayed: true`, and no second job is dispatched.
- AC-I.15 Given the same `batch_id` with a different body, then 409 `idempotency_key_reused`.
- AC-I.16 Given an item stored with `updated_at` 10:05, when a batch carries that item with `updated_at` 10:01, then it is counted `stale` and not changed.
- AC-I.17 Given an identical item re-sent, then `unchanged++`, no update and no activity row.
- AC-I.18 (rev. 4) Given an item's `sale_price` changes `125000.00` → `130000.00`, then `13000000` minor is stored with a `synced_from_1c` log; an absent `purchase_price` leaves it unchanged; `null` clears it.
- AC-I.19 Given a price `"100.505"` UZS, then `10051` stored and a warning in the batch result.
- AC-I.20 Given an item references a unit unknown to the CRM and not in the batch, then that item `failed` (`unknown_reference`), others succeed, batch `partial`, error visible in `GET /batches/{id}` and the admin batch page.
- AC-I.21 Given `is_deleted: true` in a delta, then the item becomes inactive with `external_deleted_at`, is excluded from pickers, references intact.
- AC-I.22 Given a full items snapshot in 3 parts where part 2 lacks item X (active), then X is deactivated only after all 3 parts are processed, not earlier.
- AC-I.23 Given a full snapshot covering 50% of active items (guard 80%), then nothing is deactivated, snapshot `partial`, admin alert.
- AC-I.24 Given only parts 1 and 2 of 3 arrive within the TTL, then the snapshot expires with no deactivation and an alert.
- AC-I.25 (rev. 4) Given stock rows with `cost` 1 000.00 for 10 and `unit_cost` 120.00 for 30, then the item's stock cost price is 115.00.
- AC-I.26 Given stock stored with `as_of` 14:10 and a batch with `as_of` 14:00, then rows are not changed; a full stock snapshot without (item, warehouse) removes that row.
- AC-I.27 Given a `POST /batch` with items (with prices) and stock for a new item, then the item exists before its stock is applied, and all succeed.
- AC-I.28 Given no stock batch processed for longer than the threshold (30 min), then the Items screen shows the stale badge and the dashboard shows the alert; a new processed batch clears it.
- AC-I.29 Given any user, when calling a Livewire action that creates or updates item name/price, then 403.
- AC-I.30 Given an admin presses "Reprocess" on a processed batch, then it is re-queued, results equal (idempotent upserts) and a `batch_reprocessed` activity row exists.
- AC-I.31 Given a new unit with code `166`, then precision 3; unknown code → 0 + alert.

Customer exchange (rev. 3: 1C initiates every call)
- AC-I.32 Given the exchange enabled and a valid INN, when a sales user creates a customer, then it saves immediately with `pending`, one open outbox entry and `sync_queued_at`; no HTTP request leaves the CRM.
- AC-I.33 Given a pending customer, when 1C polls `GET /customers/pending` with `customers:read`, then the customer and its contacts are returned with `outbox_id`, `delivery_count` is 1 and "last poll" is updated; a token without `customers:read` gets 403 `forbidden_ability`.
- AC-I.34 Given a polled entry, when 1C acks `synced` with a GUID and code, then GUID/code and contact GUIDs are stored, status `synced`, entry closed; repeating the same ack returns `already_applied` and changes nothing.
- AC-I.35 Given an ack `failed` with `fields.tax_id`, then status `failed` with the message; Resync re-queues (new open entry).
- AC-I.36 Given the customer is edited after 1C polled it, then a new entry supersedes the delivered one; an ack for the old entry answers `superseded`, stores the GUID and leaves the customer `pending`; the next poll returns the new entry.
- AC-I.37 Given several edits before the first poll, then exactly one open entry exists and the poll returns the latest values.
- AC-I.38 Given an ack with a GUID already linked to another customer, then that ack is `rejected` (`external_id_taken`) and nothing changes.
- AC-I.39 Given no INN, then `not_synced` with reason `tax_id_missing`; adding a valid INN → `pending`.
- AC-I.40 Given a synced customer, a change to `notes`/`owner_id` queues nothing; a change to `phone` or a contact's email queues an entry.
- AC-I.41 Given 1C pushes a counterparty with an unknown GUID whose INN matches exactly one unlinked CRM customer, then it is linked (`linked`), 1C values applied; with two unlinked matches the record is `conflict` with both `crm_id`s and nothing changes.
- AC-I.42 Given a CRM customer pending an edit and 1C pushes it with a newer `updated_at`, then 1C values win, the open entry is superseded (`overwritten_by_1c`), `sync_conflict_1c_won` lists the replaced CRM values, status `synced`.
- AC-I.43 Given the same push `batch_id` and body twice, then the second response equals the first with `Idempotent-Replayed: true`; a different body → 409.
- AC-I.44 Given a pushed customer with `manager.email` matching a CRM user, then that user is owner; else the default owner.
- AC-I.45 Given a pushed record older than the stored `external_updated_at`, then `stale` and unchanged.
- AC-I.46 Given a pushed counterparty whose INN equals an already linked customer's, then a second customer is created and both carry `has_duplicate_tax_id`.
- AC-I.47 Given a push with `is_deleted: true`, then "Deleted in 1C", read-only, out of pickers.
- AC-I.47a Given the exchange enabled and no poll for longer than the threshold, then a pending customer's page shows the stale warning; a poll clears it.
- AC-I.47b Given more customer calls per minute than the token limit, then 429 with `Retry-After`.
- AC-I.47c `app/` contains no HTTP client usage (architecture test).
- AC-I.47d Given an item with `class_code` entered in the CRM, when 1C sends the item without `class_code` (or empty), then the CRM value stays; when 1C sends `"08708001001000000"`, it replaces it. A `class_code` with letters rejects the record.

Security & logs
- AC-I.48 After any test sync, `integration_logs` contain no token, password, customer name, phone, email or INN (scan for fixture values); tokens never appear in `inbound_batches`.
- AC-I.49 Settings changes are attributed (`settings.updated_by`); there are no 1C secrets to log.

## 14. Tests plan

Global: no real 1C and no outbound HTTP (architecture test). Fixtures in `tests/Fixtures/onec/` follow sections 9–10 exactly and are shared with the 1C developer as examples.

Inbound API (feature tests with `postJson`/`getJson` and `withToken`)
- `Api/OneC/AuthTest`: missing/unknown/revoked/expired token, ability matrix per endpoint (data provider), IP allowlist incl. CIDR, HMAC valid/invalid/skewed, HTTPS enforcement, stateless (no session/CSRF), disabled integration → 503, `last_used_at` update (AC-I.1–I.9).
- `Api/OneC/IntakeValidationTest`: envelope rules, size limits (413), 415, per-record errors → 207 shape, all-invalid → 422, decimal-string enforcement, GUID format, duplicate ids in one batch (AC-I.10–I.13).
- `Api/OneC/IdempotencyTest`: identical replay returns stored response and no new job (`Queue::fake()` + `assertPushed` count), different body → 409, header ≠ body → 422 (AC-I.14–I.15).
- `Api/OneC/ProcessItemsBatchTest`: create/update/unchanged/stale, two-pass groups, unknown references → partial, deletion, OKEI precision (AC-I.16–I.17, I.20–I.21, I.31). Runs the job synchronously.
- `Api/OneC/ProcessPricesAndStockTest` (rev. 4): item prices in minor units, rounding warning, absent/`null`, stock cost and weighted-average stock cost price (AC-I.18–I.19, I.25).
- `Api/OneC/ProcessStockBatchTest`: `as_of` ordering, full snapshot removal, negative stock, available calc (AC-I.26).
- `Api/OneC/FullSnapshotTest`: multi-part finalization only after last part, out-of-order part arrival, guard, TTL expiry, `snapshot_mismatch` (AC-I.22–I.24).
- `Api/OneC/CombinedBatchTest` (AC-I.27), `Api/OneC/BatchStatusTest` (`GET /batches/{id}`, token scoping).
- `OneC/StalenessTest`: travel time with `$this->travel()`, badge and alert on/off (AC-I.28).
- `Settings/IntegrationTokensTest` (Livewire): create shows token once, hash stored, revoke, policy (admin only) (AC-I.9).
- `Settings/BatchReprocessTest` (AC-I.30).

Customer exchange (rev. 3)
- `Api/OneC/CustomerOutboxTest`: queue on create/edit/contact change, reuse of an undelivered entry, supersede after delivery, poll paging + cursor + abilities, idempotent ack, superseded ack, failed ack, GUID taken, Resync (AC-I.32–I.40).
- `Api/OneC/CustomerInboundTest`: create, link by INN, INN multi-match conflict, duplicate INN flag, 1C wins over a pending edit, stale, deletion, owner mapping, idempotent replay, abilities, rate limit (AC-I.41–I.47b).
- `Architecture/NoOutboundHttpTest` (AC-I.47c); `Customers/CustomerSyncUiTest` (badge, pending since, stale warning, Resync policy); `Catalog/ItemAccountingCodesTest` (AC-I.47d, permissions, search).

Cross-cutting: catalog policy matrix (all mutations denied except CRM-local), `OneC/LogPrivacyTest` (AC-I.48), `Console/ScheduleTest`, `onec:import-file` happy path.

Definition of done: all green, Pint clean, no HTTP client in `app/`, every Livewire action authorizes, every API route has auth + ability middleware (route-list test).

## 15. Rollout, build order, roadmap

Flags: `onec.inbound_enabled` (catalog) and `onec.customers_enabled` (customers), default false. Additive, reversible migrations (no production data yet). Parallel tracks: the 1C team builds the scheduled jobs (section 9 catalog sender; section 10 customer push + outbox poll + ack); the CRM side builds against fixtures. Nothing needs to be exposed on the 1C side.

| Step | Increment | Contents | Depends on |
|---|---|---|---|
| I-0 | Contract sign-off | 1C team confirms sections 9–10 and answers section 16 | – |
| I-1 | Inbound API core | `routes/api.php` registration, `integration_tokens` + middleware stack, `/ping`, Settings > Integrations token UI, `inbound_batches`, `integration_feeds`, `integration_logs`, intake (validation, idempotency, 202/207/422) | Spec 01 step 0.3 |
| I-2 | Items feed | units, categories, items migrations (replace Spec 01 1a/1b CRUD), `ProcessInboundBatch` for items, snapshots + guard, read-only screens, staleness | I-1 |
| I-3 | Prices (rev. 4) | single sale/purchase price on items, stock cost, `ItemPricing`, price lists dropped with backfill | I-2 |
| I-4 | Stock feed | warehouses, item_stocks, stock tab/column | I-2 |
| I-5 | Combined batch + batch UI | `/batch`, `GET /batches/{id}`, admin batches page, reprocess | I-2..I-4 |
| I-6 | (dropped in rev. 3) | no outbound client: the CRM cannot reach 1C | – |
| I-7 | Customer outbox + ack (built 2026-10-18) | `customer_outbox`, model hooks, `GET /customers/pending`, `POST /customers/ack`, abilities `customers:read/write`, "pending since", stale warning, Resync, Settings customer exchange | I-1 |
| I-8 | Inbound customers (built 2026-10-18) | `POST /customers` upsert by GUID / crm_id / INN, 1C wins, duplicate/deleted flags, owner mapping | I-7 |
| I-9 | Ops & alerts | dashboard alerts, pruning, queue heartbeat, end-to-end test with the 1C test infobase | all |
| Go-live | | create production token with IP allowlist; 1C sends a full snapshot of items (with prices) → stock (with cost) out of hours; reconcile counts with 1C; 1C pushes all buyers once, then enable the customer exchange and the 1C poll job | I-9 |

Rollback: revoke the token / disable the flags (CRM keeps the last mirror; customers stay `not_synced` until re-enabled).

Roadmap
- Orders, delivery notes (with serial arrays), agreements, proforma invoices, CRM-recorded payments and stock documents → 1C: **designed in Spec 06 section 9** (1C polls `GET /outbox/pending`, acks with `POST /outbox/ack`; same pattern as customers; only `synced` customers).
- Customer bank details pushed by 1C (Spec 06 Q-18).
- Payments **pushed by 1C** (`POST /payments`, Spec 06 9.3). Later: 1C-issued tax documents and realizations (`documents:write`). Serial numbers are never exchanged (CRM-only).

## 16. Risks and open questions

### Risks
| # | Risk | Mitigation |
|---|---|---|
| R-I.1 | 1C sender or customer service late (critical path) | Contract-first; fixtures; `onec:import-file` for demo/UAT from a 1C JSON export |
| R-I.2 | 1C stops pushing or polling silently (scheduled job disabled, token expired) | Per-feed staleness alerts incl. the customer poll; "last contact from 1C"; token `last_used_at`; `/ping` |
| R-I.3 | Truncated full snapshot deactivates the catalog | Multi-part completion + percentage guard + TTL |
| R-I.4 | Out-of-order or duplicate deliveries (1C retries) | `batch_id` idempotency, FIFO processing, `updated_at`/`as_of` guards, hash skip |
| R-I.5 | Token leak | Hashed storage, shown once, abilities, IP allowlist, optional HMAC, rotation without downtime |
| R-I.6 | Queue worker/scheduler not running on OSPanel/Windows | "Queue not processing" alert; deployment checklist |
| R-I.7 | Gross/net price mismatch → wrong offer totals | `prices_include_vat` per item; converted once per offer line (Spec 05 BR-2) |
| R-I.8 | Duplicate INNs / Партнер+Контрагент split in 1C | Non-unique INN on import; multi-match → manual choice |
| R-I.9 | Large bodies rejected by web server/PHP limits before Laravel | Document limits; 1 000-record cap keeps bodies ~1–2 MB |
| R-I.10 | Every prospect reaches 1C and pollutes it | INN required; D-I.8 may move queueing to "first order" |
| R-I.11 | Customers stay pending for long while 1C is down | Customers stay fully usable; "pending since" + stale warning; one open entry per customer (no pile-up) |
| R-I.12 | 1C overwrites a CRM edit made just before its push (1C wins) | Replaced values kept in the activity log (`sync_conflict_1c_won`); agreed rule D-I.10 |

### Decisions taken (no answer needed unless you disagree)
- DEC-1 **API auth without Sanctum**: Sanctum is not installed; we use a small native `integration_tokens` table + middleware (sha256-hashed tokens, abilities, IP allowlist, optional HMAC). No new Composer dependency. Revisit only if a public API for other clients appears.
- DEC-2 Accept-fast/process-async with FIFO processing; 1C never waits for upserts.
- DEC-3 Stock is a current-balance table (no history) with its cost; prices are a single current value per item (rev. 4, no history or future prices; changes are in the activity log).
- DEC-4 (rev. 3) Customers: 1C pushes its counterparties and polls an outbox of CRM customers; the CRM makes no outbound calls (1C is on a private network).
- DEC-5 (built 2026-10-17) The inbound catalog API has its own switch `onec.inbound_enabled` (setting, default `ONEC_INBOUND_ENABLED=false`) separate from `onec.enabled` (customer push/pull), so the catalog feed can go live before the outbound customer sync exists. BR-I.21/I.22 apply to the inbound switch for the catalog feeds.

### Open questions for the user / 1C team (default assumed if unanswered)

| # | Question | Default we build |
|---|---|---|
| D-I.1 | 1C configuration and platform version (UT 11 / ERP 2 / KA / BP for Uzbekistan; 8.3.x)? | 8.3.2x trade/ERP configuration with Партнеры + Контрагенты, hidden behind the customer service |
| D-I.2 | How often will 1C push each feed and poll customers? | Stock: full snapshot every 10 min. Prices and items: delta every 15 min. Nightly full snapshot at 02:00. Customers: push changes + poll the outbox every 5 min (stale alert after 30 min) |
| D-I.3 | Can 1C send `Authorization: Bearer`? | Yes (`HTTPConnection` headers on any 8.3); no 1C-side auth is needed because the CRM never calls 1C |
| D-I.4 | Network: which address does 1C use to reach the CRM (public HTTPS or VPN), and from which static egress IP? | **Answered in part**: 1C is not reachable from outside and initiates every call. Default: token allowlisted to 1C's egress IP; HMAC mandatory if that IP is public (BR-I.18a); valid certificate on the CRM |
| D-I.5 | Expected item count, price rows and stock rows (payload size)? | ≤ 50 000 items, ≤ 10 warehouses; batches of ≤ 1 000 records (~1–2 MB) |
| D-I.6 | Which warehouses should sales see, and which figure (on hand or available)? | All warehouses flagged for CRM in 1C; sales see available = quantity − reserved |
| D-I.7 | (answered, rev. 4) Price types? | One sale and one purchase price per item; stock cost on stock rows. Default assumed: sale price may include VAT (`prices_include_vat`), costs are net of VAT |
| D-I.8 | Queue customers for 1C on creation or only at the first order? | On creation (your requirement), only with a valid INN |
| D-I.9 | Which fields identify a customer (INN only, PINFL for sole proprietors, foreign companies)? | INN (9 digits) for UZ; customers without INN not pushed |
| D-I.10 | Who wins on conflicting edits of shared customer fields? | 1C wins; the replaced CRM values are kept in the activity log |
| D-I.11 | Which counterparties to import (buyers only? individuals?) | Buyers only; legal entities and sole proprietors |
| D-I.12 | How to map 1C responsible managers to CRM users? | By email; fallback default owner in settings |
| D-I.13 | Are contacts kept in 1C, in which object? | Contact persons of the counterparty/partner, synced inside the customer aggregate both ways |
| D-I.14 | Do 1C prices use more than 2 decimals? | 2; extra decimals rounded half-up with a warning |
| D-I.15 | (obsolete in rev. 4) Customer price types | No customer prices; a `price_type_id` in a customer push is ignored |
| D-I.16 | Does 1C merge duplicates and can it return `merged_into_id`? | Yes if available; else treated as a deletion |
| D-I.17 | 1C maintenance window? | Full snapshots at 02:00 Asia/Tashkent; while 1C is down nothing is polled and customers stay pending |
| D-I.18 | Can CRM users edit shared fields of customers that came from 1C? | Yes (owner/manager/admin) with version check; `tax_id` never after sync |
| D-I.19 | Does 1C use an exchange plan for change tracking, and can it keep `batch_id` per message for retries? | Exchange plan node "CRM"; message marked delivered on 200/202/207; same `batch_id` on retry |
| D-I.20 | Is HMAC signing wanted in addition to Bearer + IP allowlist + HTTPS? | Optional per token on the private network/VPN; **mandatory** for calls from public IPs (BR-I.18a) |
| D-I.21 | Should 1C ack `failed` for business rejections (e.g. INN checksum) and simply not ack technical errors? | Yes: `failed` closes the entry until the data is fixed or Resync; unacked entries come back next run |
| D-I.22 | Can 1C store our `crm_id` on the counterparty and send it back in pushes? | Yes; it makes linking exact before falling back to INN |
| D-I.23 | Exact formats of `accountant_code`, `class_code` (IKPU/MXIK, 17 digits?) and `package_code`, and which 1C attributes hold them? | Lenient: digits only, IKPU ≤ 17 (warning if not 17), package ≤ 32; 1C attributes named by the 1C team |

**Update 2026-10-19 (Spec 03 section 22):** the 1C customer exchange keeps its contract.

- Only organizations with the customer role are queued, offered, acked or matched (`Customer` scope). Supplier/carrier-only organizations never enter the customer outbox.
- When an organization gets the customer role, it is queued as a new customer (CRM origin).
- A 1C customer whose tax ID matches a supplier-only organization stays a separate row flagged `has_duplicate_tax_id` and is merged by hand (D22-7).
- 1C supplier columns are prepared on the supplier profile (`counterparties.external_id`, `synced_at`). It stays pull-only.

> **Spec 03 section 24 note (2026-10-19).** Customers and suppliers/carriers are one **Partner** table (`partners`) with roles. Links that mean the organization are `partner_id` (contacts, supplier profile, equipment, service tickets, requests, catalog rules); links to the customer role keep `customer_id`. The 1C contract (paths, scopes, fields, feeds) is unchanged.

## Rev. 4 (user decision 2026-10-19): sync is optional per partner; matching by tax ID only
- `partners.sync_with_1c` (default **false** for every new partner: the normal form, "New partner", lead conversion, import, suppliers/carriers). A partner is offered in `GET /customers/pending` only when the switch is on **and** it has a tax ID; the forms refuse switching it on without a tax ID; "Resync" is shown only for sync-enabled partners; switching it off withdraws open outbox entries (`superseded_reason = sync_off`).
- Upgrade: partners with a 1C GUID or any outbox row got `true` (dev database: 1 on, 17 off); the rest stay off, so nothing that was syncing stops.
- `POST /customers` matches by **tax ID only**. Match → update (1C wins on shared fields, replaced values logged), GUID stored as information, switch turned on, roles unchanged. No match → create a customer only while `integrations.allow_create_from_1c` is on (default on; Settings > Integrations), else `skipped/create_disabled`. No tax ID → `skipped/tax_id_missing`. `crm_id` and GUID stay accepted in payloads (backward compatible) but are ignored for matching; a GUID stored on another partner → `conflict`. Duplicates are impossible because the tax ID is unique per partner.
- Payments (`POST /payments`) still find the customer by its 1C GUID (unchanged; the GUID is stored when the customer is matched).
- The "held" state of section 18 (Spec 04) is replaced by this switch: the conversion option "Sync with 1C" sets it.
- The CRM never calls 1C; outbox and pull model unchanged.
- Rev. 4 note (2026-10-19): the CRM keeps only current item prices and stock (no history); the 1C contract is unchanged (no field was removed).

## Rev. 5 (user decisions 2026-10-19): goods receipts pushed by 1C, document linking

### 10.5 `POST /goods-receipts`, `PUT /goods-receipts/{crm_id}` (`warehouse:write`)
- New ability `warehouse:write`. Supplier goods receipts are created in 1C only and pushed here; the stock manager completes them in the CRM; the posted result is available for 1C to pull (`goods_receipt.posted`, feed `warehouse`). Full contract: Spec 06 section 26.2 and the OpenAPI file.
- Self-contained: a supplier unknown by tax ID is created (supplier role, "Sync with 1C" on, needs review); an item unknown by GUID is created from `code`/`name`/`unit_id` (needs review). Tax ID is mandatory (`tax_id_required`); any error rejects that receipt completely.
- Synchronous, idempotent by `batch_id`, logged in `inbound_batches` (endpoint `goods_receipts`), rate-limited like every 1C call. No outbound call.

### 10.6 Document linking (applies to every document 1C pushes; goods receipts first)
- Answers carry `crm_id`, CRM `number`, `version` and echo the 1C `id`.
- Lookup: `crm_id`, then the 1C id; no match = create. Stale `version` / `updated_at` → refused (`stale_version`, `stale_update`). Same content → `unchanged`. Result: `created | updated | unchanged`.
- A document already posted in the CRM is never rewritten by 1C: refused with a clear code and flagged for the responsible CRM user (Spec 06 26.3).
- Rev. 4 (matching customers by tax ID only) is unchanged; payments keep their own matching (GUID, then `crm_id`).
