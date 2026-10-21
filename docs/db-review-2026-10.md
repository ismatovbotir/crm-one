# Database review - 2026-10-18

Scope: all 38 migration files in `database/migrations`, models, factories, seeders, Livewire list screens, the 1C inbound path (`app/Services/OneC/Inbound`, `ProcessInboundBatch`), `PriceResolver`, activity/audit logging, and the planned tables of specs 03, 06 (orders, ledger, payments, serials, tenders).
Mode: review only. No migration, model, factory, seeder or app file was changed; the dev database was not migrated. Experiments used a copy of `database/database.sqlite` (scratchpad) and an in-memory SQLite.

Conventions used below: "MySQL" = production (8.0, InnoDB, utf8mb4_unicode_ci per `config/database.php`); "SQLite" = tests/local. Every snippet is marked `both` (works on both) or `mysql-only` (guard with `DB::getDriverName() === 'mysql'`).

## 0. State of the schema

`php artisan migrate:status` on the dev DB: batches 1-9 applied (26 migrations, up to `2026_10_18_073423_create_customer_outbox_table`). **Ten migrations are Pending and belong to the offers phase being written by pm (in flux, reviewed separately in section 3):**

```
2026_10_18_075028_create_number_sequences_table
2026_10_18_075029_create_status_transitions_table
2026_10_18_075031_create_item_alternatives_table
2026_10_18_075032_create_offers_table
2026_10_18_075034_create_offer_option_groups_table
2026_10_18_075035_create_offer_lines_table
2026_10_18_075037_create_offer_line_selections_table
2026_10_18_075038_create_offer_selection_events_table
2026_10_18_075040_create_offer_approvals_table
2026_10_18_075042_create_offer_versions_table
```

The directory is not a git repository, so "uncommitted" cannot be read from git; "Pending" is used as the in-flux marker. Because they are pending (never applied here), pm may amend them in place, provided they were not applied on a shared/staging DB - confirm before doing so. Applied migrations (1-9) must only be changed by new files.

Dev data is tiny (items 18, price_list_items 61, customers 11, activity_log 128), so no query plan below says anything about scale; plans are used only to check which index is chosen. All volume statements are extrapolations from the specs: <= 50 000 items, <= 5 price types / 250 000 price rows, <= 10 warehouses (Spec 02 D-I.5); serials 10^5-10^6+ (Spec 06 21.5).

## 1. Findings table

Severity: H = fix before volume / before the next phase; M = fix soon; L = hygiene.
"Where" points to the migration file or app file; line numbers are from the current files.

| # | Sev | Where | Problem | Impact at scale | Fix |
|---|---|---|---|---|---|
| F-01 | H | `app/Services/OneC/Inbound/SnapshotFinalizer.php` `finalize()` (ll. 62-75) | Removal of unseen rows does `->get()->each(remove)` for the whole scope inside ONE transaction; for prices/stock `remove` is `$row->delete()` (model events, one `activity_log` insert per row). | A price-type swap or a first full snapshot after a gap can delete up to 250k rows: whole result set hydrated in memory (PHP memory_limit), one huge transaction (undo log, binlog, lock time), 250k audit rows. | Set-based delete in chunks outside one transaction, one summary audit row. Code change (section 5.1) + index F-02. |
| F-02 | H | `price_list_items`, `item_stocks` (`2026_10_16_174655`:22, `..174658`:22) | `last_seen_snapshot_id` has no index; finalizer runs `count()` twice and a `!=`/IS NULL scan per target. | Full scan of the price list per finalization (250k rows x 2-3 passes). Not a disaster alone, but it multiplies F-01. | `(price_list_id, last_seen_snapshot_id)` and `(warehouse_id, last_seen_snapshot_id)`. Migration N1. |
| F-03 | H | `inbound_batches.payload` longText (`2026_10_17_031832`:26) | 1-2 MB raw JSON per batch kept in the hot row, together with `response_body`, `counts`, `rejected`, `errors` JSON. `Settings/Integrations.php:250` does `InboundBatch::latest('id')->limit(15)->get()` -> loads 15 payloads (up to ~30 MB) on every render (`#[Hidden]` only hides serialization, not the SELECT). | A full prices snapshot = ~250 batches = ~375 MB per run; at 30 days retention with daily snapshots ~10 GB of LOB pages in one table; `DELETE` of such rows is slow and bloats undo/binlog. | (a) immediately: `->select([...all but payload])` in the Integrations screen and any batch list; (b) structural: move payload to `inbound_batch_payloads` (PK = batch id), prune = delete from that table, keep the metadata row (also matches Spec 02 "payload pruned", see F-04). Migration S1. |
| F-04 | M | `app/Models/InboundBatch.php` `prunable()`; migration `..031832`:44 | (1) `prunable()` deletes the whole batch row after 30 days, Spec 02 11.4 says only the payload is pruned. Deleting the row also deletes the idempotency record (`batch_id` replay returns, counts, audit). (2) The retention predicate `received_at < X` has no usable index: `(status, received_at)` needs `status` first, so each 1000-row `MassPrunable` chunk is a scan. (3) `endpoint` and single `status` indexes are low-cardinality / redundant with `(status, received_at)`. | Slow nightly prune; loss of audit counts after 30 days; replay of an old batch id would be re-processed instead of answered. | Add `index(received_at)`; keep rows, null the payload (after S1 delete the payload row); drop `inbound_batches_endpoint_index` and `inbound_batches_status_index`. Migration N1. |
| F-05 | M | `PriceUpserter`, `StockUpserter`, `CatalogUpserter` | Row-by-row: one SELECT for the existing row + one INSERT/UPDATE (+ one `activity_log` INSERT per real change, `LogsActivity` is active inside `OneCSyncContext` for updates) per record. Chunks of 500 per transaction are fine. | 250k price rows = 250k SELECTs + changed rows x 2 writes; minutes per full snapshot on MySQL, acceptable but the dominant cost. Only one worker (WithoutOverlapping), so deadlocks are unlikely. | Prefetch the existing rows of a chunk with one `whereIn` and key them in PHP; write new rows with `insert`/`upsert` on the existing unique keys (`price_list_items` unique, `item_stocks` unique, `external_id` unique). Keep the stale/hash decisions in PHP. Section 5.2. No schema change. |
| F-06 | M | `activity_log` (`2026_10_16_173416`) | No retention, no time index. `PriceListItem` is `LogsActivity` with old/new JSON and 1C `price_changed` events; `deleted` events from F-01. Spec 01 R-3 defers retention to Phase 6. | Price churn alone can add 10^4-10^5 rows per day -> 10^7 rows/yr; the only index starts with `subject_type`, so any "latest N" / report / prune by date scans. | Index `(created_at)`; add `(causer_id)` (SQLite has no implicit FK index); exclude or batch-summarise sync price updates (log one `price_sync` row per batch with counts); decide retention (e.g. 2 years hot, archive table after). Migration N2 + section 5.3. |
| F-07 | M | `customers.tax_id` (`..174700`:23), `contacts.is_primary` (`..174701`) | Invariants enforced only in the app: no duplicate CRM-created customer by tax ID, at most one primary contact per customer. Two parallel requests can both pass the check. | Duplicate customers / two primaries under concurrency (silent data defect). | Unique generated "key" columns (both engines, tested on SQLite 3.40). Migration N3. |
| F-08 | M | all `char(36)` GUID columns: `external_id`, `last_seen_snapshot_id`, `batch_id`, `request_id`, `uuid` | `char(36)` in utf8mb4 = 144 bytes per index entry; every secondary index repeats the PK. 1C GUIDs are lowercase ASCII. | Larger, slower indexes (items.external_id unique, items.last_seen_snapshot_id, inbound_batches.batch_id...). Matters mostly for items (50k) and prices (250k, `last_seen_snapshot_id` after F-02). | `->charset('ascii')->collation('ascii_bin')` (mysql-only, no-op on SQLite). For new tables use it from the start (section 4). For existing tables: cheap now (small tables); do it in migration N4 or leave as L priority. |
| F-09 | M | `Livewire/Customers/Index.php:77-92`, `Catalog/Items/Index.php:75-79`, `Offers/Index.php:96-100`, `PriceLists/Show.php:57-60` | Search is `LIKE '%term%'` across 4-7 columns with OR and `orWhereHas(contacts ...)` (correlated EXISTS per row). `customers.name` and `legal_name` have no index (only `name_normalized`). Offers: `number like %x%` + customer name subquery. | Full scan of customers/items on every keystroke (Livewire `wire:model.live`?). 10^4 rows is fine, 10^5 starts to hurt (100-300 ms), plus contacts subqueries. | Prefix search on normalised columns first (`number like 'OF-2026-%'`, `name_normalized like 'abc%'`), FULLTEXT (ngram parser) on `items(name, article)` and `customers(name, legal_name)` for substring search (mysql-only; keep LIKE fallback for SQLite), `contacts(phone)`/`contacts(email)` indexes. Migration N5, section 5.4. |
| F-10 | M | `app/Services/Pricing/PriceResolver.php` `findRow()` (ll. 106-125) | Loads ALL dated rows (all tiers, all history up to the date) for (list, item) and picks tier/date in PHP; `whereDate` wraps the DATE column in a function (non-sargable on MySQL); `$customer->priceList` is a lazy load per call; `defaultList()` queried per call. | Per offer line: 2-3 queries returning every historical price of the item. Offer with 50 lines = ~150 queries. Items with years of price history (1C keeps dated rows) make rows per call grow. | Single query with `valid_from = (select max(valid_from) ... <= date)` and `min_qty <= qty order by min_qty desc limit 1`; compare `valid_from <= 'Y-m-d'` as a plain date; cache `defaultList()` per request; eager-load customer price list. Reorder the unique to `(price_list_id, item_id, valid_from, min_qty)` so one index answers both the max and the tier lookup. Section 5.5 + migration S2. |
| F-11 | M | no CHECK constraints anywhere | Nothing enforces: `quantity`/`reserved >= 0`, `min_qty >= 0`, `*_bp <= 10000`, `valid_from <= valid_to`, `min_select <= max_select`, `price_minor` sane, status domains. Money columns are `unsigned`, but SQLite ignores `unsigned`. | Silent bad data from any code path that bypasses the Action layer (seeders, tinker, jobs, 1C). | CHECKs on MySQL 8.0.16+ (guarded); SQLite cannot `ADD CONSTRAINT` without a table rebuild, so tests will not see them: keep Action/validation guards and add one MySQL CI job. Migration N6 (existing) and baked into create-table for new tables (section 4). |
| F-12 | M | `NumberSequence::next()` (`app/Models/NumberSequence.php`) with `..075028` | `lockForUpdate()` on a row that does not exist yet takes a gap lock; two concurrent first numbers of a year (or key) both lock the gap and both try to INSERT -> deadlock or unique violation on `(key, year)`. Also the lock is held until the outer transaction commits, serialising all creators of that entity. | Failed offer/order creation on Jan 1 / first use; contention grows with order/payment numbers (Spec 06 uses it for OR-, AG-, PF-, PM-, RF-, DN-, GR-). | `insertOrIgnore` the row first, then `select ... for update`, `increment`; allocate the number as late as possible in the transaction. No schema change. Section 5.6. |
| F-13 | M | `..075042` offer_versions:17, `..075038` offer_selection_events:16, `..075040` offer_approvals:17 (all `cascadeOnDelete` to offers) | The immutable evidence tables cascade-delete with the offer. Offers are soft-deleted (`SoftDeletes`), but a `forceDelete()` or a manual `DELETE` silently removes the proof of what was sent/accepted. Dba rules: restrict for financial/audit data. | Loss of legal evidence (what the customer received / who selected what). | `restrictOnDelete()` on `offer_versions.offer_id`, `offer_selection_events.offer_id`, `offer_approvals.offer_id`; lines/groups may keep cascade only if offers can never be hard-deleted (add a model `forceDeleting` guard). **In flux: edit in place if unapplied elsewhere, else migration N7.** |
| F-14 | M | `..075035` offer_lines, `..075037` offer_line_selections, `..075034` option_groups | (1) `stable_key` is indexed but not unique per offer; (2) `offer_line_selections.offer_id` and `option_group_id` are denormalised from the line; nothing guarantees `selection.offer_id = line.offer_id = group.offer_id`; (3) no CHECK `min_select <= max_select`, `quantity > 0`, `discount_bp <= 10000`, `effective_discount_bp <= 10000`. | A selection pointing to a line of another offer; duplicated keys after copy-on-revise. | Unique `(offer_id, stable_key)` on groups and lines; composite FK `(offer_id, id)` via unique `(offer_id, id)` on groups/lines; CHECKs (section 3). |
| F-15 | M | `..075032` offers:24,84-88 | `offers.status` single index is a prefix of `(status, valid_until)` (redundant). The list sorts by `updated_at desc, id desc` with `owner_id` filter -> filesort on the owner's rows; `whereDate('created_at')` is non-sargable. `expired offers` job needs `(status, valid_until)` - exists. Many FK columns (15+ FKs) each add an implicit index on MySQL; write cost is negligible at 10^4-10^5 offers. | Slow list for managers with many offers; redundant index maintained. | Drop `offers_status_index`; add `(owner_id, updated_at)` (own scope) and `(updated_at)`; use range `created_at >= ? and < ?` in the component. Migration N7/amend. |
| F-16 | M | `..075031` item_alternatives:29-30 | `unique(item_id, alternative_item_id)` does not stop the reverse pair (B,A) when `is_mutual`; no CHECK `item_id <> alternative_item_id`. | Duplicate or self links. | Canonical ordering rule in the Action (`least/greatest`) plus CHECK `item_id <> alternative_item_id`; or a generated `pair_key`. |
| F-17 | L | `..075029` status_transitions:25; `activity_log` | Only a subject-first index. Spec 01 Phase 6 reports (cycle time, conversion) scan by `to_status` + date. | Report queries scan the whole table. | `(subject_type, to_status, created_at)` when reports are built; not needed yet. |
| F-18 | L | `price_lists` page `withCount('entries')` (`PriceLists/Index.php:30`) | `count(*)` on 250k rows per list per page load. | ~tens of ms per list; fine for 5 lists, flag only. | Cache for 5 min or keep a `rows_count` refreshed after each prices batch. |
| F-19 | L | `users` (`..173415`, `..123109`) | `role` index is low cardinality; `phone` not indexed/unique (OTP login by phone planned, Spec 03). `email` unique is case-insensitive on MySQL, case-sensitive on SQLite -> normalise to lowercase in the app (Customer does, User should). Portal users without e-mail are impossible (`email` NOT NULL). | Duplicate accounts differing by case in tests-vs-prod; OTP lookup by phone is a scan. | Index `users(phone)` now; decide e-mail nullability with the portal spec (nullable unique is fine on both). |
| F-20 | L | `customers` dead columns | `sync_idempotency_key`, `sync_conflict_payload`, `last_pushed_at` are "unused since rev. 3" (Spec 02 11.5). | Wider rows, confusing schema. | Drop in a pre-release cleanup migration (down() re-adds). |
| F-21 | L | `customer_outbox` `customer_id` FK `cascadeOnDelete` (`..073423`:17) | Docstring says "Rows are never deleted (audit)", but a hard customer delete cascades them. Customers are soft-deleted, so only theoretical. | Audit loss on force delete. | `restrictOnDelete()` via new migration. |
| F-22 | L | `jobs`, `failed_jobs`, `job_batches`, `sessions`, `cache` (`0001_01_01_*`) | Queue/cache/session all on the DB. No `queue:prune-failed` / `queue:prune-batches` scheduled; `jobs` has only the single-column `queue` index (default skeleton; newer default is `(queue, reserved_at, available_at)`). The 1C job holds a DB-queue reservation up to 300 s. | Slow growth of `failed_jobs`; polling contention when throughput rises. | Schedule `queue:prune-failed --hours=720` and `queue:prune-batches`; move queue + cache to Redis when the portal/OTP traffic starts; until then add the composite jobs index (mysql). |
| F-23 | L | `departments` migration `..173417` | Schema migration calls `App\Actions\Departments\BackfillDepartmentMemberships` (app code in a migration): breaks if that class changes/moves. | A fresh install can fail months later. | Inline the SQL or make the data step a seeder; do it in the pre-release squash. |
| F-24 | L | `settings.key`, `number_sequences.key`, `cache.key` | `KEY` is a reserved word in MySQL. Eloquent quotes it, raw SQL will not. Spec 03 also plans `tender_bid_lots.rank` (RANK is reserved since MySQL 8.0.2). | Raw-SQL/report syntax errors. | Avoid in new tables (`rank_position`, `setting_key`); always use the query builder. |
| F-25 | L | spec 06 vs migrations | Spec 06 7.1 says `accountant_code` string(32), `class_code` string(17), `package_code` string(20); applied migration (Spec 02 rev. 3) created 64/32/32 and Spec 02 11.5 says the same. | Snapshot columns in `order_lines`/`invoice_lines` must be at least as long as `items`, or inserts fail in strict mode. | Use 64/32/32 for the snapshot columns; fix Spec 06 wording. |

### Not a finding (checked and OK)

- PK/FK types are consistent (`id()` / `foreignId()` = bigint unsigned) across all 38 migrations; every FK has an index on MySQL (implicit or explicit); index names stay within 64 chars (longest: `price_list_items_price_list_id_item_id_min_qty_valid_from_unique` = exactly 64, at the limit; do not add a column to it).
- Money is `unsigned bigint` minor units + `char(3)` currency, VAT in basis points, quantities `decimal(15,3)` via BCMath: matches the rules. No floats.
- Soft deletes with uniques: `items.external_id`, `customers.external_id`, `offers(number, version)` are unique over soft-deleted rows too, which is the intended behaviour (1C identity, numbers never reused). `items.sku` is correctly non-unique (1C owns it).
- `customers`, `items`, `offers` list queries use `with()`/`withCount()`: no N+1 found. Users list loads `departments` once (and `primaryDepartment()`/`activeDepartments()` reuse the loaded relation); Departments list eager-loads members. Item show uses `with(['unit','category.parent','stocks.warehouse'])`.
- `price_list_items`: the (price_list_id, item_id, min_qty, valid_from) unique is exactly the upsert key and gives the 1C path an index lookup; `(item_id, price_list_id, valid_from)` serves the item page. Plans on the copy (SQLite) choose these indexes.
- Idempotency of 1C batches: `inbound_batches.batch_id` unique + stored response; `integration_tokens.token_hash` unique (sha256); `signing_secret` is `encrypted` cast.
- Timezone policy: `config/app.php` is UTC; Tashkent only for offers/1C configs. Good.
- Customer name/tax/phone/e-mail are normalised in the `saving` hook; indexes are on the normalised values.
- Query plans (SQLite copy, rough guide only): price lookup uses `price_list_items_item_id_price_list_id_valid_from_index`; price list page uses the unique + a temp b-tree for the join order; items/customers lists use `is_active` / `owner_id` indexes + temp b-tree sort; activity item tab uses the subject index.

## 2. Existing tables: ready-to-use new migrations

Names are proposals; all are NEW files (do not edit applied ones). Timestamps must sort after `2026_10_18_075042`. Apply order is in section 6. All are reversible.

### N1 - inbound/snapshot indexes (both)

`database/migrations/2026_10_19_100000_add_sync_housekeeping_indexes.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_list_items', function (Blueprint $table) {
            $table->index(['price_list_id', 'last_seen_snapshot_id'], 'pli_list_seen_index');
        });
        Schema::table('item_stocks', function (Blueprint $table) {
            $table->index(['warehouse_id', 'last_seen_snapshot_id'], 'item_stocks_wh_seen_index');
            // existing single index on warehouse_id is now a prefix of the new one
            $table->dropIndex(['warehouse_id']);
        });
        Schema::table('inbound_batches', function (Blueprint $table) {
            $table->index('received_at');            // retention prune
            $table->dropIndex(['endpoint']);         // 4 values, never selective
            $table->dropIndex(['status']);           // prefix of (status, received_at)
        });
        Schema::table('activity_log', function (Blueprint $table) {
            $table->index('created_at');             // retention / "latest" / reports
            $table->index('causer_id');              // implicit on MySQL, missing on SQLite
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['causer_id']);
            $table->dropIndex(['created_at']);
        });
        Schema::table('inbound_batches', function (Blueprint $table) {
            $table->index('status');
            $table->index('endpoint');
            $table->dropIndex(['received_at']);
        });
        Schema::table('item_stocks', function (Blueprint $table) {
            $table->index('warehouse_id');
            $table->dropIndex('item_stocks_wh_seen_index');
        });
        Schema::table('price_list_items', function (Blueprint $table) {
            $table->dropIndex('pli_list_seen_index');
        });
    }
};
```

Caveats: on MySQL, `causer_id` already has an implicit FK index (`activity_log_causer_id_foreign`); creating a second explicit one makes a duplicate - on MySQL skip that line or check `SHOW INDEX` first (the FK then adopts either). Dropping `item_stocks.warehouse_id` index is safe only because the FK is satisfied by the new composite (warehouse_id is its leftmost column). Tables are small today, so the ALTERs are instant; later they would be online (`ALGORITHM=INPLACE`) on InnoDB for index add/drop.

### N3 - DB-enforced "one primary contact" and "unique CRM tax ID" (both; verified on SQLite 3.40 in-memory, MySQL unverified)

`database/migrations/2026_10_19_100200_add_unique_keys_for_customers_and_contacts.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pre-flight (run manually, must return 0 rows):
        //   select customer_id from contacts where is_primary=1 and deleted_at is null group by 1 having count(*)>1;
        //   select tax_id from customers where origin='crm' and deleted_at is null and tax_id is not null group by 1 having count(*)>1;
        Schema::table('contacts', function (Blueprint $table) {
            $table->unsignedBigInteger('primary_key')->nullable()
                ->virtualAs('case when is_primary = 1 and deleted_at is null then customer_id end');
            $table->unique('primary_key', 'contacts_one_primary_unique');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->string('crm_tax_key', 32)->nullable()
                ->virtualAs("case when origin = 'crm' and deleted_at is null and tax_id is not null then tax_id end");
            $table->unique('crm_tax_key', 'customers_crm_tax_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_crm_tax_unique');
            $table->dropColumn('crm_tax_key');
        });
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique('contacts_one_primary_unique');
            $table->dropColumn('primary_key');
        });
    }
};
```

Notes: the Action that swaps the primary contact must unset the old one before setting the new one inside one transaction (or the unique fires mid-swap). The tax-ID rule was an explicit product choice ("uniqueness for CRM-created customers is enforced by the application"; 1C duplicates allowed), so this keeps that semantics and only moves it into the DB. A CRM customer later linked to 1C (`origin` flip) leaves the key; confirm that is intended.

### N6 - CHECK constraints on existing tables (mysql-only)

`database/migrations/2026_10_19_100500_add_check_constraints_catalog_sync.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, array<string, string>> */
    private array $checks = [
        'item_stocks' => ['item_stocks_reserved_chk' => 'reserved >= 0'],
        'price_list_items' => ['pli_min_qty_chk' => 'min_qty >= 0'],
        'price_lists' => ['price_lists_period_chk' => 'valid_to is null or valid_from is null or valid_from <= valid_to'],
        'items' => [
            'items_tax_bp_chk' => 'tax_rate_bp <= 10000',
            'items_moq_chk' => 'min_order_qty is null or min_order_qty > 0',
            'items_step_chk' => 'order_step is null or order_step > 0',
        ],
        'units' => ['units_precision_chk' => 'precision <= 3'],   // `precision` is non-reserved in MySQL 8; quote if in doubt
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return; // SQLite cannot ADD CONSTRAINT; Action/validation guards remain the test-time defence.
        }
        foreach ($this->checks as $table => $constraints) {
            foreach ($constraints as $name => $expr) {
                DB::statement("alter table `{$table}` add constraint `{$name}` check ({$expr})");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        foreach ($this->checks as $table => $constraints) {
            foreach (array_keys($constraints) as $name) {
                DB::statement("alter table `{$table}` drop check `{$name}`");
            }
        }
    }
};
```

Pre-flight each expression with a `select count(*)` first; a violating row aborts the ALTER. 1C negative stock stays legal (`quantity` has no check by design; `reserved` does). Warning: with `StockUpserter` the CRM trusts 1C's `reserved`; if 1C can send negative `reserved`, the whole batch row would fail with `conflict` - confirm with the 1C side before applying.

### N5 - search support (mysql-only for FULLTEXT; indexes both)

`database/migrations/2026_10_19_100400_add_search_indexes.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->index('phone');                       // email already indexed
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->index(['owner_id', 'name'], 'customers_owner_name_index'); // own-scope list sorted by name, no filesort
            // owner_id alone is now a prefix; drop it (the FK adopts the composite on MySQL)
            $table->dropIndex(['owner_id']);
            $table->index('legal_name');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->index('phone');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('alter table items add fulltext items_search_ft (name, article) with parser ngram');
            DB::statement('alter table customers add fulltext customers_search_ft (name, legal_name) with parser ngram');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('alter table customers drop index customers_search_ft');
            DB::statement('alter table items drop index items_search_ft');
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropIndex(['phone']));
        Schema::table('customers', function (Blueprint $table) {
            $table->index('owner_id');
            $table->dropIndex('customers_owner_name_index');
            $table->dropIndex(['legal_name']);
        });
        Schema::table('contacts', fn (Blueprint $t) => $t->dropIndex(['phone']));
    }
};
```

The components must then use `MATCH(...) AGAINST (? IN BOOLEAN MODE)` on MySQL (keep `LIKE` on SQLite). ngram tokenisation (min 2 chars) is the only built-in way to get substring semantics on cyrillic/latin mixed names; verify relevance with real names before switching. `legal_name` plain index only helps prefix search; skip it if FULLTEXT is adopted.

### N7 - offers FK policy (only if the pending offers migrations were already applied somewhere)

If not applied anywhere, put the same changes into the pending files (section 3). Otherwise:

```php
// 2026_10_19_100600_restrict_offer_evidence_foreign_keys.php  (both; SQLite rebuilds the table on dropForeign)
foreach (['offer_versions', 'offer_selection_events', 'offer_approvals'] as $name) {
    Schema::table($name, function (Blueprint $table) {
        $table->dropForeign(['offer_id']);
        $table->foreign('offer_id')->references('id')->on('offers')->restrictOnDelete();
    });
}
Schema::table('offers', function (Blueprint $table) {
    $table->dropIndex(['status']);                          // prefix of (status, valid_until)
    $table->index(['owner_id', 'updated_at'], 'offers_owner_updated_index');
    $table->index('updated_at');
});
```
down(): reverse (restore `cascadeOnDelete`, re-add `status` index, drop the two new ones).

### Structural migrations

**S1 - split the inbound payload (both).** Only after the Integrations screen select fix.

```php
// 2026_10_20_100000_split_inbound_batch_payloads.php
Schema::create('inbound_batch_payloads', function (Blueprint $table) {
    $table->unsignedBigInteger('inbound_batch_id')->primary();
    $table->foreign('inbound_batch_id')->references('id')->on('inbound_batches')->cascadeOnDelete();
    $table->longText('payload');
    $table->timestamp('created_at')->index();      // prune key
});
// backfill in chunks of 200 (never one INSERT..SELECT of GBs):
//   DB::table('inbound_batches')->whereNotNull('payload')->orderBy('id')->chunkById(200, fn ($rows) => ... insert into payloads ...);
// release step (a later migration, after the code reads the new table):
//   Schema::table('inbound_batches', fn ($t) => $t->dropColumn('payload'));
```
`InboundBatch::decodedPayload()` then reads from the relation; `prunable()` becomes `InboundBatchPayload::where('created_at', '<', ...)` (rejected: 7 days) and the batch row stays (counts, hash, response for idempotent replay). The `ProcessInboundBatch` job already carries only the id, so it reads the payload table once.

**S2 - price lookup index (both).** After the resolver is rewritten:

```php
// 2026_10_20_100100_reorder_price_list_items_unique.php
Schema::table('price_list_items', function (Blueprint $table) {
    $table->unique(['price_list_id', 'item_id', 'valid_from', 'min_qty'], 'pli_lookup_unique');
    $table->dropUnique(['price_list_id', 'item_id', 'min_qty', 'valid_from']);
});
```
Create the new unique before dropping the old (the FK on `price_list_id` needs an index at every moment). Upsert code must keep matching on all four columns (any order works for equality). Name length OK (< 64). 250k rows: seconds.

**S3 - GUID columns to ASCII (mysql-only).** `ALTER TABLE items MODIFY external_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL` etc. for `external_id` (units, categories, items, price_lists, warehouses, customers, contacts), `last_seen_snapshot_id`, `snapshot_id`, `batch_id`, `request_id`, `uuid`. Do it only for the highest-volume tables (`items`, `price_list_items`, `item_stocks`, `inbound_batches`); on the others it is cosmetic. The comparisons in code already lowercase GUIDs, so `ascii_bin` behaves identically. Generate the statements per table; rebuild is quick at current sizes and unacceptable later (price_list_items 250k+ rows will still be seconds - fine).

## 3. Pending offers-phase migrations (in flux, reviewed separately)

Reviewed: number_sequences, status_transitions, item_alternatives, offers, offer_option_groups, offer_lines, offer_line_selections, offer_selection_events, offer_approvals, offer_versions. Good: money/bp/quantity types, snapshot columns on lines (item, unit, price list, tax), `unique(number, version)`, `offer_versions.offer_id` unique (one frozen snapshot per version), `offer_line_selections.offer_line_id` unique (one selection per line), restrict on customer/owner/item FKs, nullOnDelete on user FKs, explicit `item_id` index on lines. Suggested amendments (apply in the unapplied files, or as N7):

1. `offers`: drop `->index()` on `status` (line 24). Add `index(['owner_id','updated_at'])` and `index('updated_at')`.
2. `offer_option_groups`: replace `uuid('stable_key')->index()` (l. 18) by `uuid('stable_key')` + `unique(['offer_id','stable_key'])`; add `unique(['offer_id','id'])` (target for composite FKs); add CHECK (mysql) `min_select <= max_select` and `max_select >= 1`.
3. `offer_lines`: replace the `stable_key` index (l. 20) by `unique(['offer_id','stable_key'])`; add `unique(['offer_id','id'])`; CHECKs (mysql): `quantity > 0`, `discount_bp <= 10000`, `effective_discount_bp <= 10000`, `tax_rate_bp <= 10000`. If lines of a group must belong to the same offer: composite FK `(offer_id, option_group_id)` -> `offer_option_groups (offer_id, id)` (nullable `option_group_id` stays valid: MATCH SIMPLE ignores NULL).
4. `offer_line_selections`: make `(offer_id, offer_line_id)` a composite FK to `offer_lines (offer_id, id)` and `(offer_id, option_group_id)` to groups, so denormalised ids cannot disagree; add an explicit `index('option_group_id')` (MySQL adds one implicitly; SQLite does not, and the cascade from groups scans).
5. `offer_selection_events` (l. 16): `offer_id` `restrictOnDelete`; `option_group_id`/`offer_line_id`/`contact_id` are deliberately plain columns (history must survive line removal) - keep, document it. Add `index(['offer_id','offer_line_id'])` if the event timeline per line is shown.
6. `offer_approvals` (l. 17) and `offer_versions` (l. 17): `restrictOnDelete`. Add `unique(['offer_id','content_hash'])` on approvals only if one decision per hash is a business rule (not stated; leave).
7. `offer_versions.root_offer_id` (l. 18) has no FK (index only): add `foreign('root_offer_id')->references('id')->on('offers')->restrictOnDelete()`, or document why not. `payload` JSON is written once; add a MySQL BEFORE UPDATE trigger (optional ops toggle, same pattern as the planned ledger) to make it truly immutable.
8. `offers`: CHECKs (mysql): `fixed_*`, `selected_*` ok as unsigned; `range_min_gross_minor <= range_max_gross_minor`, `max_discount_bp <= 10000`, `version >= 1`. Status domain stays an enum in app code (not a CHECK: adding states would need a migration for every status).
9. `offers.lead_id` plain index until Spec 04: fine; when leads ship, add the FK with `nullOnDelete` after verifying orphans.
10. `item_alternatives`: CHECK `item_id <> alternative_item_id` (mysql); the (A,B)/(B,A) duplicate rule belongs in the Action (store `min(id)` first when `is_mutual`, otherwise it is a different link).
11. `number_sequences`: schema is fine (`unique(key, year)`); the issue is in `NumberSequence::next()` (section 5.6). `key` is a MySQL reserved word; keep using the query builder, never raw SQL.
12. `status_transitions` / `activity_log`: both polymorphic with no FK (by design). Add `(created_at)` on `status_transitions` only when reports exist.

Also verified for MySQL: offers row has ~60 columns including 9 TEXT; TEXT is stored off-page so the row-size limit (65 535 bytes) is not at risk. `offers(number, version)` key = 20x4+2+2 = 84 bytes.

## 4. Schema recommendations for the planned tables (apply BEFORE writing those migrations)

### 4.0 Rules for every new table

- Character set: all machine-readable strings (GUIDs, numbers, status codes, serials, period, currency, hashes, `*_type`) as `->charset('ascii')->collation('ascii_bin')` on MySQL (Laravel ignores it on SQLite). Human text stays utf8mb4. GUID = `char(36)` ascii (or `binary(16)` if a table will have 10^7 rows).
- `uuid` public id on every aggregate that is exposed (portal, outbox, URLs), unique.
- Money `*_minor` unsigned bigint where the sign is known; signed `bigint` only on ledger/allocation reversals. Always add `CHECK (amount_minor > 0)` etc. in the same migration, guarded by `mysql`.
- FK policy: `restrictOnDelete` on everything financial, document and serial related; `nullOnDelete` only for "who did it" user columns; `cascadeOnDelete` only for pure children that are never audited (e.g. `serial_import_rows` -> batch, draft-only lines). No soft deletes on ledger/payments/serials.
- Status domains: string columns + PHP enum; DB CHECK `status in (...)` is optional and costs a migration per new state - use it only for terminal/safety columns (`direction`, `kind`).
- Every table that gets polled by 1C or paged by id uses a monotonic bigint PK as cursor and an index that matches the poll predicate.
- Create CHECK constraints inside a helper that no-ops on SQLite, e.g. `App\Support\Schema\Check::add($table, $name, $expr)` (one place to review).
- `period char(7)` (YYYY-MM) is redundant with a date: make it a generated column (`virtualAs("date_format(paid_on, '%Y-%m')")` on MySQL; on SQLite `strftime('%Y-%m', paid_on)`) or compute in the app and index it; do not let it drift from the date.

### 4.1 Serial registry (Spec 06 section 21.5)

`item_serials` (10^5-10^6+ rows, hottest correctness table):
- `serial_normalized varchar(64)` and `serial_raw varchar(100)`: `ascii_bin` for normalized (case-sensitive, no PAD SPACE: utf8mb4_unicode_ci would treat `'ab'` = `'ab '` and `'a'` = `'á'`); `serial_raw` utf8mb4 (audit, may contain look-alike letters).
- Keep `unique(item_id, serial_normalized)` as the real guard. Keep `index(serial_normalized)` (cross-item lookup) - non-unique. `global_key` unique nullable: acceptable (MySQL allows many NULLs); add a CHECK `global_key is null or global_key = serial_normalized`. Flipping `serials.global_uniqueness` backfills up to 10^6 rows: do it with `update ... where global_key is null and id between ? and ?` in 10k-id chunks, after the duplicate preflight.
- Do NOT index `status` alone (6-8 values). Indexes: `(item_id, warehouse_id, status)` (claim/availability), `(warehouse_id, status)`, `(customer_id)`, `(sold_delivery_line_id)`, `(received_receipt_line_id)`, `(reserved_by_line_type, reserved_by_line_id)` (spec).
- `reserved_by_line_type/id` polymorphic = no FK and no integrity. Prefer three nullable FK columns (`reserved_delivery_line_id`, `reserved_transfer_line_id`, `reserved_adjustment_line_id`) + CHECK that at most one is set; or accept the polymorphic pair and add a reconciliation job. Either way CHECK `(reserved_at is null) = (reserved_by_line_id is null)`.
- State CHECKs (mysql): `status <> 'available' or warehouse_id is not null`; `status <> 'sold' or (sold_at is not null and customer_id is not null and sold_delivery_line_id is not null)`; `status not in ('sold','written_off') or warehouse_id is null` only if that is the rule (spec says warehouse null when sold/written off).
- FKs: `item_id`, `warehouse_id`, `customer_id`, `received_receipt_line_id`, `sold_*_line_id` all `restrictOnDelete`. No `cascadeOnDelete` anywhere.
- Claim concurrency (BR-SN.7): `SELECT ... FOR UPDATE` ordered by id on `(item_id, serial_normalized)` hits the unique index and locks only those rows; the conditional `UPDATE ... WHERE id IN (...) AND status = 'available'` is already the optimistic fallback. `lock_version` is then redundant for claims; keep for edits. Lock time: keep the claim transaction free of PDF/notification work. Gap locks: all candidate rows exist (registry), so no gap-lock deadlock like F-12; for receipts (inserts of new serials) use `insert` and catch the unique violation instead of `select for update` on missing rows.
- `lock_version`, `warranty_until date`, `received_at timestamp`: fine. Add `created_at` index only if prune/report needs it (no).

`document_line_serials` (2-4 M rows):
- `active_key` unique string: store as `char(64)` sha256 hex or `ascii_bin varchar(128)` (the raw concatenation is up to ~122 chars: 488 bytes in utf8mb4, 122 in ascii; both under 3072 bytes, ascii preferred).
- `document_type` + `document_id` + `line_type` + `line_id` is a four-way polymorphism: use `line_type` only plus `line_id` (the document is reachable via the line); keep `document_id` denormalised for `active_key`. Index `(line_type, line_id)`, `(item_serial_id)`, `(import_batch_id)`.
- Posted rows immutable: enforce in the model AND with a MySQL BEFORE UPDATE trigger on `status = 'posted'` (optional toggle).

`serial_events` (append-only, ~5-10 rows per serial => 10^6-10^7):
- No FKs needed beyond `item_serial_id` restrict; no updates; BIGINT PK; index `(item_serial_id, created_at)`; add `(document_type, document_id)` and `(created_at)`.
- Partitioning: do NOT partition now. MySQL cannot combine partitioning with foreign keys, and the PK must include the partition column. If it ever passes ~50 M rows, move to monthly range partitions on `created_at` with `(id, created_at)` PK and drop the FK on that table only; before then, archive rows older than N years into `serial_events_archive` with an `INSERT ... SELECT` + `DELETE` in chunks.

`serial_import_batches` / `serial_import_rows`:
- Rows are 10 000 per batch and short-lived: `serial_import_rows.batch_id` FK `cascadeOnDelete` is the right use of cascade; index `(batch_id, row_no)` unique and `(batch_id, result)`. Prune by `expires_at` in chunks; do not keep rows after commit (the `serial_events` already audit).
- `committed_key` nullable unique (spec) is the right MySQL pattern for "unique among committed" (no partial indexes). `file_hash char(64)` ascii; `disk_path` never user-controlled.

### 4.2 Orders, invoices, payments, allocations, ledger (Spec 06 section 7)

- `orders.offer_id` unique FK restrict (DEC-1) good. `orders.payment_status` single index is low-selectivity; replace by `(status, payment_status)` or `(customer_id, status)` per the real screens. `paid_minor`/`invoiced_gross_minor` caches: update them inside the same transaction that inserts the allocation, under `SELECT ... FOR UPDATE` on the order row; add a nightly reconcile query (`orders.paid_minor` vs `sum(allocations)`) and CHECKs `paid_minor <= gross_minor + tolerance` only if overpayment is forbidden (it is not - prepayments; so no check, a report instead).
- `order_lines`: CHECK `delivered_qty + cancelled_qty <= quantity` and `invoiced_qty <= delivered_qty` as specified (mysql) - the only place in the system where it protects inventory/billing concurrency (two delivery notes posting simultaneously): do the update as `UPDATE order_lines SET delivered_qty = delivered_qty + ? WHERE id = ? AND delivered_qty + cancelled_qty + ? <= quantity` and check affected rows; the CHECK is the backstop.
- `invoices`: spec says unique per (`type`, `number`, `issued_on` year) - an expression is not a column. Add `issued_year smallint unsigned` (generated from `issued_on`) and `unique(type, number, issued_year)`. `esf_id` unique nullable. `period` as in 4.0. Index `(customer_id, status)`, `(order_id)`, `(type, issued_on)` as spec; `(status, due_on)` for the dunning/overdue job (`due_on` nullable).
- `payments`: `unique(source, onec_guid)` works with NULLs on MySQL and SQLite (many NULLs allowed). CHECK `amount_minor > 0`; `allocated_minor <= amount_minor`; `direction in ('incoming','outgoing')`. `reference_number` index: payment-order numbers repeat across banks - not unique, but add `(payer_tax_id, reference_number, paid_on, amount_minor)` as the duplicate-detection index (`match_state`). `payer_account`: encrypt (Laravel `encrypted` cast) or store only masked + last 4 + a keyed HMAC for matching; hashed OTP-style secrets do not apply. Model guard "updating throws when money columns dirty" is app-only: add a MySQL BEFORE UPDATE trigger rejecting changes of `amount_minor`, `currency`, `customer_id`, `paid_on` (optional ops toggle, same helper as the ledger).
- `payment_allocations` (append-only): index `(payment_id)`, `(order_id)`, `(invoice_id)`, `(reverses_id)` unique nullable (a reversal reverses one allocation once). CHECK `amount_minor <> 0`. `fx_rate decimal(18,8)`: fine (max 10^10 with 8 decimals fits in 18,8: 10 integer digits). Store both amounts (spec does) and never recompute.
- `accounting_entries` (append-only ledger): signed `bigint`, `uuid` unique, `entry_type` ascii. Indexes `(customer_id, occurred_on, id)`, `(order_id, id)`, `(payment_id)`, `(period, entry_type)`, `(reverses_entry_id)` unique nullable. FKs `restrictOnDelete`. **No partitioning** (it needs FKs). Volume (a few entries per document) is 10^6/yr at most - fine without. Immutability: MySQL triggers `BEFORE UPDATE` / `BEFORE DELETE` with `SIGNAL SQLSTATE '45000'`, SQLite equivalents use `RAISE(ABORT, ...)`; write them in a migration guarded by driver, toggle by config. Also run the app with a DB user that has no `TRUNCATE`/`DROP`; `DELETE` must stay for dev reseed, so the trigger is the actual control. Period lock: `accounting_periods.period char(7) ascii unique`; a posting must `SELECT ... FOR SHARE` the period row, and the entry's `period` must equal the month of `occurred_on` (CHECK on MySQL via generated column compare is overkill - assert in the Action).
- `dunning_events`: unique `(schedule_item_id, stage, channel)` is the idempotency key - good. Prune after 2 years.
- `fx_rates`: unique `(currency, rate_date)` good; `rate decimal(18,8)`; CHECK `rate > 0`.
- `payment_schedule_items`: CHECK `amount_minor > 0`; index `(order_id, due_on)` and `(status, due_on)` for the overdue scan (run daily, chunked).
- `stock_reservations`: index `(item_id, warehouse_id, status)` as spec; add `(order_line_id)`; available-for-sale aggregate `sum(quantity) where status='active'` per item/warehouse is cheap with that index; keep it a query, not a cached column, until measured. CHECK `quantity > 0`.

### 4.3 1C outbox (`onec_outbox`, Spec 06 7.15) - mirrors `customer_outbox`

- Cursor = `id` bigint. The poll predicate is `feed = ? and status in (pending,delivered) and id > ? order by id limit N` -> index `(feed, status, id)`, not the three single-column indexes in the spec (`event_type`, `feed`, `status` single indexes are low-selectivity). Add `(aggregate_type, aggregate_id, status)` for the "supersede older pending" update.
- `payload json` + `ack_payload json` are the big columns: store the payload in the same row only while `status` is not `acked`; consider a separate `onec_outbox_payloads` (same pattern as S1) so acked rows are cheap to keep and the 180-day prune deletes only the payload table. `event_id` char(36) ascii unique.
- Written in the business transaction (spec): keep it to one `INSERT` per event; do not `lockForUpdate` the outbox in the writer.
- Prune in chunks of 1000 by `(status, acked_at)`; add that index (or `(acked_at)`).

### 4.4 Tenders and bids (Spec 03 section 7.3)

- `tender_awards`: "unique (lot) where status in (approved, accepted)" is a partial unique - not available in MySQL. Use a generated column: `active_lot_key bigint unsigned null virtualAs("case when status in ('approved','accepted') then lot_id end")` + `unique(active_lot_key)` (works on SQLite 3.31+, tested pattern as N3).
- `tender_bids`: `unique(tender_id, counterparty_id, version)` as spec; add `(tender_id, status)`; `payload_hash char(64) ascii`. Bids are legal evidence: FKs `restrictOnDelete`, no soft delete; `submitted_at` immutable after submit (trigger or model guard). "Sealed until due date": a DB view/scope is not enough - enforce in the repository layer and test it.
- `tender_bid_lines`: purchase XOR transport: CHECK `(tender_item_id is null) <> (charge_code is null)` (mysql). Amount CHECK `amount_minor >= 0`; `quantity decimal(15,3)`.
- `tender_bid_lots.rank`: rename to `rank_position` (reserved word). Computed columns (`landed_cost_minor`, `score`, `rank_position`) are derived: they belong in the evaluation snapshot, recomputed on demand; if stored, never index them alone.
- `tender_scores`: `score decimal(4,2)` CHECK between 0 and 10; unique `(bid_lot_id, criterion_id, user_id)`.
- `tender_criteria.weight_bp` CHECK 0..10000; sum = 10000 per tender is a cross-row rule: validate in the Action at publish and lock after publish (spec).
- `tender_questions`, `tender_invitations`: unique `(tender_id, counterparty_id)` on invitations as spec; index `(tender_id, published_at)` on questions.
- Attachments (shared, morph): index `(attachable_type, attachable_id)`, `(sha256)` for dedupe; files on a private disk, `path` random; never serve through a predictable URL.
- Retention (Q-16 keeps tenders/bids forever): plan an archive flag, not deletion; index `(status, closed_at)` for "recent" lists.

### 4.5 Auth/portal tables (Spec 03 7.1) - brief

- `login_codes.code_hash`: a plain `sha256` of a 6-digit OTP is brute-forceable offline from a DB leak (10^6 candidates). Use `hash_hmac('sha256', code, app_key . row_salt)` or bcrypt-cost-4 style; attempts counter in the row; index `(user_id, purpose, consumed_at)`; prune expired rows daily.
- `telegram_updates.update_id` PK is an idempotency table: good; prune by `received_at` (7 days) with an index on `received_at`.
- `auth_events`: index `(created_at)`, `(user_id, created_at)`; 1-year retention by chunked delete.

### 4.6 Tables that grow without bound today (retention matrix)

| Table | Growth driver | Retention now | Recommendation |
|---|---|---|---|
| `inbound_batches` | every 1C call; payload 1-2 MB | rows deleted at 30 d (code), payload only per spec | split payload (S1), keep metadata 1 year |
| `integration_logs` | every API call (polling every minute) | 90 d prune, scheduled | OK; index on `created_at` exists |
| `activity_log` | every model change incl. 1C price changes | none | index + retention + summarise sync rows |
| `status_transitions`, `offer_selection_events` | per workflow step | none | keep (business audit); archive after 3-5 years |
| `inbound_snapshots` | per full snapshot | none | prune after 90 d (finalized/expired) |
| `customer_outbox` | per customer change | never (by design) | acceptable (10^4-10^5 customers); prune acked rows > 1 y if it grows |
| `failed_jobs`, `job_batches` | failures | none | schedule `queue:prune-failed`, `queue:prune-batches` |
| `sessions`, `cache` | users / OTP bots | session GC lottery 2/100; cache lazy | fine; move to Redis with the portal |
| `item_serials`, `serial_events`, `ledger` | business | never | no partitioning; archive table pattern |

## 5. Code-level recommendations (no schema change; for the owner of each area)

### 5.1 SnapshotFinalizer removal (F-01)

Replace the `get()->each(remove)` with a loop of bounded chunks, each its own short transaction, and a set-based delete for prices/stock:

```php
// prices: no per-row model events needed, one audit row for the whole removal
do {
    $ids = PriceListItem::query()->whereIn('price_list_id', $listIds)
        ->where(fn ($q) => $q->whereNull('last_seen_snapshot_id')->orWhere('last_seen_snapshot_id', '!=', $snapshot->id))
        ->limit(1000)->pluck('id');
    $deleted = PriceListItem::query()->whereKey($ids)->delete();   // Eloquent builder delete = no model events
    $total += $deleted;
} while ($ids->isNotEmpty());
// then: ActivityLog row 'snapshot_pruned' with counts per target.
```
Catalog targets (items/units/groups) set `is_active=false` via `->update()` in `whereKey` chunks the same way (the per-row audit of "deactivated by snapshot" can be one summary row plus the list of GUIDs in the snapshot result, capped).
The `(clone)->count()` guard queries are fine once F-02 exists.

### 5.2 Upserters (F-05)

Per chunk of 500 records: collect `itemGuid -> id` with one `whereIn('external_id', ...)` (already cached per upserter instance, but warm it in bulk), fetch existing price rows with one `whereIn('item_id', ids)->where('price_list_id', ...)`, key by `item_id|valid_from`, then decide stale/unchanged/updated in memory. Write `created` rows with `PriceListItem::insert([...])` (they bypass model events - audit via batch counts, consistent with "creations from 1C are audited by the inbound batch row") and updates with the existing `save()` only for changed rows (they keep `price_changed` audit). Do not switch to `upsert()` for rows that must keep `external_updated_at` out-of-order protection unless the stale check stays in PHP.

### 5.3 Activity log volume (F-06)

For `PriceListItem` updates and creates inside `OneCSyncContext`, log one `price_sync` row per batch (`properties: {created, updated, deleted, batch_id}`) instead of one row per price row; keep per-row `price_changed` for CRM-side edits. Add `ActivityLog` as `MassPrunable` once retention is decided (the scheduled `model:prune` list in `routes/console.php:31` is explicit, so it must be added there).

### 5.4 Search (F-09)

- Offers: `where('number', 'like', $search.'%')` when the term looks like a number (`OF-`, digits) and an `exists` on customers by `name_normalized like prefix%` otherwise; avoid `orWhereHas` over a possibly soft-deleted join with `withTrashed()` on a per-row basis (use a pre-selected id list `whereIn('customer_id', Customer::withTrashed()->where(...)->select('id'))`, which MySQL turns into a semi-join).
- Customers: same: prefix on `name_normalized`; `tax_id` exact when digits only (the stored value is normalised) instead of `%digits%`; phone: store normalised digits and search by suffix via a reversed-digits generated column if substring phone search matters (otherwise `like 'prefix%'`).
- `whereDate('created_at', '>=', $d)` -> `where('created_at', '>=', $d.' 00:00:00')` (use the Tashkent day boundary converted to UTC to avoid the 5-hour shift: `created_at` is stored UTC, the filter is entered in local days).
- Debounce the Livewire search (`wire:model.live.debounce.400ms`) if not already; each keystroke is a full list query + `owners` subquery.

### 5.5 PriceResolver (F-10)

```php
// one query: latest valid_from <= date, highest tier <= qty
PriceListItem::query()
    ->where('price_list_id', $list->id)->where('item_id', $item->id)
    ->where('valid_from', '=', fn ($q) => $q->from('price_list_items as p2')
        ->selectRaw('max(p2.valid_from)')->whereColumn('p2.price_list_id', 'price_list_items.price_list_id')
        ->whereColumn('p2.item_id', 'price_list_items.item_id')->where('p2.valid_from', '<=', $date->toDateString()))
    ->where('min_qty', '<=', $quantity)
    ->orderByDesc('min_qty')->limit(1)->first();
```
`valid_from` is a DATE column: compare as `<= 'YYYY-MM-DD'`, never `whereDate`. `$quantity` is a normalised decimal string, bound as a string: MySQL compares DECIMAL with a string by converting - bind with `CAST(? AS DECIMAL(15,3))` to be exact. For repeated lookups of many items (offer creation from a list) add `resolveMany(items[], ...)` doing one query per price list. Keep `defaultPricesFor()` (already `whereIn`) but drop `whereDate`.

### 5.6 NumberSequence (F-12)

```php
return DB::transaction(function () use ($key, $year): int {
    DB::table('number_sequences')->insertOrIgnore(['key' => $key, 'year' => $year, 'last_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $row = self::query()->where('key', $key)->where('year', $year)->lockForUpdate()->first();
    $row->increment('last_value');
    return (int) $row->last_value;
});
```
`insertOrIgnore` is portable (INSERT IGNORE / INSERT OR IGNORE). Call it as the last statement before commit in the creating Action.

### 5.7 Integrations screen (F-03a)

`app/Livewire/Settings/Integrations.php:250`: `InboundBatch::query()->select([...no payload...])->latest('id')->limit(15)->get()`. Same for any other listing of batches (the `response_body` JSON is also wide: select only what the table shows).

## 6. Recommended order of new migrations (new files only, for pm to apply later)

Phase A - quick wins, no code coordination (safe now, run on MySQL pre-flight first):
1. N1 sync/housekeeping indexes (+ activity_log indexes).
2. N5 search indexes (index part; FULLTEXT after the components are changed).
3. N3 unique keys for contacts primary / CRM tax ID (after the pre-flight selects return nothing and the Actions are made order-safe).
4. N7 offers FK/index amendments - or edit the pending offers migrations (section 3) while they are unapplied.

Phase B - with the offers phase (before applying `..075028`-`..075042` anywhere shared):
5. Amend the pending migrations per section 3 (restrict on evidence tables, unique `(offer_id, stable_key)`, composite FKs, CHECKs).
6. NumberSequence code fix (F-12).

Phase C - structural, with code changes:
7. Code: Integrations select (F-03a), SnapshotFinalizer chunking (F-01), PriceResolver query (F-10), upserter prefetch (F-05).
8. S2 price unique reorder (after the resolver is deployed).
9. S1 payload split (backfill in chunks; second migration drops the column after the code is live), S3 ASCII GUID columns for the big tables.
10. N6 CHECK constraints (MySQL only) after the pre-flight counts.
11. Cleanup migration: drop dead `customers` sync columns (F-20), `customer_outbox` restrict (F-21), scheduler additions (F-22), `inbound_snapshots` prune.

Phase D - before Spec 06 tables are written: adopt section 4.0-4.4 as the "schema checklist" in the migration PRs (ASCII codes, CHECK helper with driver guard, unique generated keys instead of partial uniques, restrict FKs, immutable triggers helper, no partitioning with FKs).

Phase E - pre-release squash: once the first production deploy is decided, squash migrations 1-N into a schema dump (`php artisan schema:dump --prune`) and remove the app-code dependency in the departments migration (F-23). Do not squash earlier: other agents are still adding files.

## 7. What could not be verified

- MySQL 8 behaviour: every plan, index-size and locking statement is reasoned from InnoDB semantics, not measured. The sandbox has only SQLite; `migrate --pretend` against MySQL was not possible (no MySQL server reachable; the pretend run needs the migrations table). The generated-column uniques (N3) were tested only on SQLite 3.40; MySQL 8.0 supports virtual generated columns with unique secondary indexes (and `change()`/`dropColumn` on them), but the Laravel `virtualAs` + `unique` inside one `Schema::table` call should be run once on a MySQL scratch schema. Same for ngram FULLTEXT relevance and the CHECK expressions.
- Performance at volume: dev DB holds <= 61 price rows; no seeded 250k-row test was run (a throwaway seeded copy was out of scope for review-only). The 250k / 50k figures come from Spec 02 D-I.5.
- The pending offers migrations were reviewed as files; they were not run (not even on a copy), so FK-name generation on MySQL (all < 64 chars by inspection), `unsignedBigInteger` defaults and the composite-FK suggestions are unexecuted.
- `PermissionMap`/policy scope queries were read, not profiled; `colleagueIds()` runs one extra query per list render (two queries per `Offer::visibleTo` call: department ids, then colleague ids) - cheap, but cache per request if several lists render on one page (the dashboard).
- Blade views were grepped for relation access, not rendered; N+1 was ruled out by reading `with()` calls and view property access, not by a query-count test. A cheap guard: `Model::preventLazyLoading(! app()->isProduction())` in `AppServiceProvider`, then run the suite.
- Production DB configuration (sql_mode, isolation level, buffer pool, `max_allowed_packet` for 2 MB payloads - the MySQL 8 default 64 MB is enough, binlog format, backups/PITR, DB user privileges) was not available; recommendations assume REPEATABLE READ and strict mode (`config/database.php` has `strict => true`). Backup/restore and a restore test are not documented in the repo.
- Spec 04 (leads/rails) and attachments/notifications tables do not exist yet, so FKs to them (e.g. `offers.lead_id`) could not be reviewed.

## 8. Applied 2026-10-18 (pm, job 2 of the approved plan)

The user allowed editing the pending offers migrations in place and re-running them on the dev DB (pre-release, local). Applied:

| Item | How | Notes |
|---|---|---|
| Section 3 items 1-3, 5-7, 10 | edited in place: `075032` offers (no `status` index; `(owner_id, updated_at)`, `(updated_at)`; CHECKs), `075034` groups (`unique(offer_id, stable_key)`, CHECKs), `075035` lines (`unique(offer_id, stable_key)`, CHECKs, `cost_price_minor`, no price-list columns), `075037` selections (`index(option_group_id)`), `075038`/`075040`/`075042` evidence tables `restrictOnDelete`, `offer_versions.root_offer_id` FK restrict, `075031` CHECK `item_id <> alternative_item_id` | Composite FKs `(offer_id, id)` (items 2-4) **deferred**: the "same offer" rule stays app-enforced in the Actions and is covered by tests; revisit with the pre-release squash. |
| CHECK helper | `App\Support\SchemaChecks` (no-op on SQLite) | used by the offers migrations and N6 |
| N1 | `2026_10_18_131222_add_sync_housekeeping_indexes` | adapted: `price_list_items` no longer exists (rev. 4); adds `inbound_snapshots(finalized_at)` |
| N5 (index part) | `2026_10_18_131224_add_search_indexes` | FULLTEXT ngram deferred until components use `MATCH ... AGAINST` on MySQL |
| N3 | `2026_10_18_131225_add_unique_keys_for_customers_and_contacts` | `SaveContact` already unsets the old primary first; a 1C-linked customer leaves the CRM tax key (intended) |
| S1 + F-04 | `2026_10_18_131227_split_inbound_batch_payloads` + `InboundBatchPayload` (pruned 30/7 days), batch rows kept 365 days | customer pushes still store no body |
| N6 | `2026_10_18_131228_add_check_constraints_catalog` (MySQL only) | adapted to rev. 4 (no price-list checks; sale/purchase currency required when a price is set) |
| F-20 / F-21 | `2026_10_18_131230_cleanup_customer_sync_columns_and_outbox_fk` | |
| F-01 | `SnapshotFinalizer` removes in chunks of 500, each in its own transaction; stock rows deleted set-based | catalog rows keep per-row `deleted_in_1c` audit |
| F-03a | Integrations screen selects only listed columns | |
| F-12 | `NumberSequence::next()` insert-or-ignore then lock | |
| F-15 / 5.4 | Offers list date filter uses Tashkent-day UTC ranges (sargable) | |
| F-22 | scheduled `queue:prune-failed --hours=720`, `queue:prune-batches --hours=168`, `model:prune` adds payloads and snapshots (90 days) | |
| F-10 / S2 / F-05 (prices) / F-18 | obsolete: price lists were removed (Spec 01/02 rev. 4) | |

Not applied (still recommended): S3 ASCII GUID columns (MySQL), FULLTEXT search, F-06 activity retention/summarising, F-16 mutual-pair canonical ordering, F-17 report indexes, F-23 departments migration app-code dependency and the pre-release squash (Phase E), `Model::preventLazyLoading()` guard. Section 4 rules are the checklist for the Spec 06/03/08 tables built next.
