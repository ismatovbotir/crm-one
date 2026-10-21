# Spec 09 — Customer equipment and service (repair) tickets

Status: built 2026-10-19 (user requirement of the same day). Owner: PM. Related: Spec 01 (items, warranty months), Spec 03 (portal, departments, permissions), Spec 06 §21 (serial registry), Spec 02/06 §9 (1C outbox).

## 1. Problem and goal

Customers own the equipment we sold them (printers, scales, terminals) and equipment bought elsewhere. Today they can only look up the warranty of one serial number in the portal. They cannot see their fleet, report a broken unit, follow a repair, or say a unit is stored.

**Goal:** one register of every unit a customer owns, keyed by serial number:
- filled automatically from our sales;
- extended by the customer;
- with a status the customer and our service team both see;
- with repair requests (service tickets) that follow a clear workflow with SLA targets.

**Success metrics:**
- 100 % of serials on posted delivery notes appear in the customer's register with no typing.
- Median time to first response under the SLA target (default 4 h).
- No portal user ever sees another organization's equipment (tests).

**Non-goals (this version):**
- Field-service scheduling and route planning.
- Spare-part stock reservations (parts used are recorded, not reserved).
- Customer self-service invoicing (billable work goes to 1C through the outbox).
- QR label PDF (a plain serial search is provided; labels are a later increment).

## 2. Interpretation of the request (recorded defaults; the user may correct)

- **I-1 "My equipment"** means the units this organization owns, one row per serial number (`customer_assets`).
- **I-2 Asset status** (customer-facing): `in_use`, `needs_repair`, `in_repair`, `repaired_ready`, `out_of_service`.
- **I-3 "Out of stock"** is read in two ways, both modelled:
  - a) the service ticket state `waiting_for_parts`: the spare part or replacement unit is not available;
  - b) an asset flag `in_storage`: the customer keeps the unit in their own store, not deployed.

  **Open question for the user:** confirm this reading, or tell us if "out of stock" means something else, for example "unit missing / lost".
- **I-4 Ownership.** The sold serial in `item_serials` stays the source of truth for units we sold. Its status machine (available / reserved / sold / returned …) is not changed. The asset row holds only the customer-facing status and extra data.
- **I-5 Equipment bought elsewhere** can be registered by the customer or by staff. It is flagged `customer_registered` and stays unverified until staff confirm it.

## 3. Data

### 3.1 `customer_assets`

| Column | Meaning |
|---|---|
| `id`, `uuid` | `uuid` is used in portal URLs |
| `organization_id` | the owner organization |
| `item_serial_id` | nullable, unique; set for units we sold, linked to the registry row |
| `item_id` | nullable; catalogue item |
| `model_name` | free text, when there is no catalogue item |
| `serial_raw`, `serial_normalized` | the serial as entered and normalized (same normalizer as the registry) |
| `source` | `sale` / `customer_registered` / `staff_registered` |
| `verified_at`, `verified_by` | staff confirmation |
| `status` | one of the asset statuses (I-2) |
| `in_storage` | flag (I-3 b) |
| `location` | where the unit is |
| `purchased_on` | purchase date |
| `sold_at`, `order_id`, `delivery_id` | references for units we sold |
| `warranty_until` | warranty end date (see §6) |
| `warranty_reminded_at` | when the expiry reminder was sent |
| `photo_path` | optional photo |
| `notes` | free text |
| `disputed_at` | set when the serial is also claimed elsewhere |
| `created_by` | who added the row |
| timestamps | |

### 3.2 `asset_events` (append-only history)

- Columns: `asset_id`, `event`, `from_status`, `to_status`, `comment`, `service_ticket_id`, `user_id` (staff or portal user), `created_at`.
- Events: `registered`, `sold`, `verified`, `status_changed`, `storage_on`, `storage_off`, `ticket_opened`, `ticket_status`, `claim_refused`, `disputed`.

### 3.3 `service_tickets`

| Group | Columns |
|---|---|
| Identity | `uuid`, `number` (`SV-YYYY-NNNNN`), `organization_id`, `customer_asset_id` |
| Request | `status`, `urgency` (low / normal / high / critical), `problem`, `contact_name`, `contact_phone`, `location` |
| Origin | `source` (portal / staff / telegram), `telegram_chat_id` (when the customer has a linked Telegram group), `reported_by` |
| Handling | `assigned_to`, `accepted_at`, `respond_due_at`, `repair_due_at`, `ready_at`, `closed_at` |
| Warranty | `under_warranty` and `warranty_until` (snapshot when reported) |
| Work | `resolution`, `labor_minor`, `currency`, `replacement_note`, `reject_reason` |
| 1C | `onec_state`, `onec_guid`, `onec_number`, `onec_acked_at` |

### 3.4 Related tables

- `service_ticket_parts`: `item_id`, `description`, `quantity`, `unit_price_minor`.
- `service_ticket_notes`: `user_id`, `body`, `is_internal`. Internal notes are never shown to the customer.
- `service_ticket_photos`: `path`, `uploaded_by`. Photos are re-encoded to JPEG, at most 1600 px and 5 MB, 3 per report.
- Staff documents use the existing attachments (morph `service_ticket`).
- Ticket transitions use the shared `status_transitions` table.

## 4. Sources of assets

### 4.1 Sale (automatic)

- When a delivery note is posted, every serial sold to the customer gets an asset with `source=sale`, `status=in_use`, verified, and the order, delivery, `sold_at` and `warranty_until` copied from the registry.
- A data migration backfills the serials already sold.
- If the customer had already registered the same serial (unverified, same organization), that row is linked and verified instead of duplicated.

### 4.2 Registration (customer or staff)

The item is picked from the catalogue, or a free-text model is given, together with serial, location, purchase date and an optional photo.

**Claim rules (R-1):**
- **Refused:** a serial that belongs to **another** organization, either through our registry (sold to them) or through their verified or unverified asset for the same item, or for the same serial when no item is given.
  - The portal user sees only the neutral message "This serial number cannot be registered. Our service team will contact you."
  - Service staff get an alert. The event is logged on the existing asset as `claim_refused`, with no data shown to the claimant.
- **Linked:** a serial sold to the **same** organization is linked to the sale asset, which already exists.
- **Rejected as duplicate:** a duplicate inside the same organization is rejected.

## 5. Workflows

### 5.1 Asset status (customer-facing)

| From → To | Who | Note |
|---|---|---|
| in_use → needs_repair | customer (report) or staff | opens a ticket (portal) or is set by a staff ticket |
| needs_repair → in_repair | system, on ticket `accepted` | |
| needs_repair → in_use | system, on ticket `rejected`/`cancelled` | |
| in_repair → repaired_ready | system, on ticket `ready` | |
| in_repair → in_use | system, on ticket `rejected`/`cancelled` | |
| repaired_ready → in_use | system, on ticket `closed`; or the customer confirms | |
| any except in_repair → out_of_service | staff (written off) | |
| out_of_service → in_use | staff (reactivated) | |

- `in_storage` can be switched by the customer (non-viewer roles) or by staff while the asset is `in_use` or `repaired_ready`.
- Every change appends an `asset_events` row with who, when and the comment.

### 5.2 Service ticket

```
new ─▶ accepted ─▶ diagnosing ─▶ in_repair ─▶ ready ─▶ closed
 │        │            │  ▲  │        │
 │        │            ▼  │  │        ▼
 │        │   waiting_for_parts       waiting_for_parts
 ├─▶ rejected (new, accepted, diagnosing)      diagnosing ─▶ ready (no repair needed)
 └─▶ cancelled (customer or staff, while new or accepted)
```

- Only staff with `service.manage` move tickets. The customer may cancel while the ticket is `new` or `accepted`.
- Each step:
  - is logged in `status_transitions`;
  - adds an `asset_events` row;
  - notifies the customer (§8).
- `rejected` needs a reason. `closed` needs a resolution.

### 5.3 SLA

- Settings `service.sla_response_hours` (default 4) and `service.sla_repair_hours` (default 72), in calendar hours.
- `respond_due_at = reported + response hours`; it is met when the ticket is accepted or rejected.
- `repair_due_at = accepted + repair hours`; it is met at `ready`. Time in `waiting_for_parts` is not paused (simple rule; recorded).
- The queue shows the remaining or overdue time as text, not colour only.

## 6. Warranty

`warranty_until` is computed in this order:
1. the registry row's `warranty_until`;
2. otherwise the registry `sold_at` + the item's `warranty_months`;
3. otherwise none.

Equipment registered by the customer and not sold by us has no warranty from us. It shows "Not covered by our warranty".

A ticket snapshots `under_warranty` (true if `warranty_until ≥ report date`). Billable cost is only recorded when the ticket is not under warranty; staff can override with a note.

A reminder goes 30 days before expiry (daily job, once per asset) to:
- the organization's portal admins and buyers;
- the sales owner.

## 7. Permissions

### 7.1 Staff (config/permissions.php)

| Key | Who |
|---|---|
| `service.view` | admin (read), manager, the new role `service_engineer`, department `service` (all members); since 2026-10-20 also sales (`role:sales`, `dept:sales:specialist`: customers they own; `dept:sales:head`: department) — read only, lists scoped by the customer owner |
| `service.manage` | manager, `service_engineer`, `dept:service:*`. Not admin (SoD: admins have no business rights). |
| `service.register` (staff register/verify assets) | manager, `service_engineer`, `dept:service:*`, sales (own customers), `dept:sales:*` (own / department) |

- The equipment tab on the customer card is shown to users who can view the customer and have `service.view`, or who are the customer's owner (read-only).
- Sales read access (user decision 2026-10-20): the Service menu (tickets, equipment) is visible to sales; the lists are filtered to customers they own (sales head: their department) through `ServiceTicket::visibleTo()` / `CustomerAsset::visibleTo()`; other owners' tickets and units are not listed and answer 403. No manage actions (accept, assign, status, parts, notes stay `service.manage`). The service workload tiles on the dashboard stay with the service team and managers (scope "all"). New-ticket notifications still go to the service team (`service.manage`) and the sales owner only.
- New global role `service_engineer` maps to department `service` (specialist).
- The department `service` is added to the standard departments.

### 7.2 Portal roles

- All portal users see their organization's equipment and tickets.
- `portal_admin`, `buyer` and `approver` may register equipment, report problems, mark units as stored and cancel their tickets.
- `viewer` and `finance` are read-only.

### 7.3 Tenant isolation

- Every portal query goes through `PortalScope` (`organization_id` = the membership's customer).
- Foreign uuids answer 404.

## 8. Notifications

| Event | Recipients | Channels |
|---|---|---|
| New ticket | service team (`service.view` users, without admins), customer's sales owner | in-app, Telegram per preferences (new event `service_ticket`) |
| Ticket status change | portal users of the organization; the reporter | portal (database, shown on the portal dashboard and equipment page), e-mail when the address is deliverable, Telegram when linked |
| Ticket status change, ticket came from a linked Telegram group | the group | Telegram (`TelegramClient::sendMessage`) |
| Refused claim (R-1) | service team | in-app |
| Warranty expiring in 30 days | portal admins and buyers; sales owner | as above |

All texts are in the recipient's language and escaped. The Telegram group message contains the ticket number, the asset and the new status only.

## 9. UI

### 9.1 Portal

- **"My equipment"** (`/portal/equipment`):
  - Filters: status, in storage, item/model, location, warranty expiring within 30 days, serial search.
  - Status badges carry text and an icon.
  - CSV export of the organization's own equipment (with formula-injection protection).
  - "Add equipment" form.
- **Equipment page:**
  - Details, warranty and the full history: sold, warranty, status changes, tickets.
  - "Report a problem" (problem text, urgency, contact, location, up to 3 photos).
  - "Mark as in storage" / "Back in use".
  - Ticket list with the public notes and a printable acceptance receipt.

### 9.2 Staff

- **Service → Tickets:**
  - Queue with filters: open, mine, overdue, waiting for parts, all.
  - SLA timers.
  - Assignment.
- **Ticket page:**
  - Transitions, internal or public notes.
  - Parts used, cost (non-warranty), replacement unit note, documents.
  - Print the acceptance receipt.
- **Service → Equipment lookup:** serial search across customers (`service.view`).
- **Asset page:** history, verify, status change (out of service / reactivate), open a ticket.
- **Customer card → Equipment tab.**
- **Serial registry page** links to the asset.
- **Dashboard section for the service role:** open, overdue, waiting for parts.

### 9.3 Print

Service acceptance receipt (`documents.service_receipt`) through `DocumentRenderer`, in uz, ru or en. It shows:
- company and customer;
- the asset (item/model, serial);
- warranty status, problem, accepted date and ticket number;
- signatures.

## 10. 1C (pull-only)

- When a ticket that is not under warranty is closed with labour or parts costs, the outbox gets `service_ticket.closed` (feed `orders`, ability `orders:read`), available for 1C to pull.
- Payload: number, dates, customer, asset (item GUID, serial), parts (item GUID, quantity, price), labour, totals.
- The ack stores the 1C GUID and number on the ticket.
- The event is documented in the OpenAPI spec. There are no outbound calls.

## 11. Limits and audit

- **Portal rate limits:**
  - reports: 10 per hour per user and 30 per hour per organization;
  - registrations: 20 per hour per user.
- **Uploads:** JPEG, PNG or WebP, at most 5 MB and 6000 px, re-encoded, stored on the private disk, served through an authorizing controller.
- **Audit:** every action is recorded in the activity log or the asset history.

## 12. Acceptance criteria (tests)

- **AC-1** Posting a delivery note creates verified sale assets for each serial sold. The backfill covers earlier sales.
- **AC-2** A portal user sees only their organization's assets and tickets (404 for others). A viewer cannot report or change anything.
- **AC-3** The asset and ticket transition maps are enforced; invalid moves are refused.
- **AC-4** Warranty is computed from the registry, else from the item months, else none; the ticket snapshot is correct.
- **AC-5** Claiming a serial owned by another organization is refused with a neutral message and a staff alert; no data leaks.
- **AC-6** Status changes notify the customer, and the Telegram group when linked, only via api.telegram.org. A new ticket notifies the service team and the sales owner.
- **AC-7** Permissions: the service role manages tickets; admin only reads; sales sees their customers' equipment.
- **AC-8** A billable ticket closed produces `service_ticket.closed` for 1C to pull. A warranty ticket does not.
- **AC-9** The warranty reminder is sent once, 30 days before expiry.

## 13. Increments

1. Schema, models, sync from deliveries and backfill.
2. Portal register and reporting.
3. Staff queue and ticket workflow.
4. Notifications and Telegram.
5. Print and outbox.
6. Dashboards and guide.

All six were shipped together on 2026-10-19.

## 14. Telegram group updates: privacy switch (user note 2026-10-19)
Group members may include people outside the buyer team. Therefore:
- the group message carries only the ticket number and the status text (no problem description, asset, serial number or photos);
- global switch "Post service ticket updates to customers' Telegram groups" on Settings > Telegram (settings key `service.telegram_group_updates`, default on);
- per-customer switch on the customer's Telegram tab (`customers.telegram_service_updates`, default on), changed by users who may edit the customer and audited on the customer.
Portal users still get the full in-app and e-mail notification.

> **Spec 03 section 24 note (2026-10-19).** Customers and suppliers/carriers are one **Partner** table (`partners`) with roles. Links that mean the organization are `partner_id` (contacts, supplier profile, equipment, service tickets, requests, catalog rules); links to the customer role keep `customer_id`. The 1C contract (paths, scopes, fields, feeds) is unchanged.

## 15. "Out of stock" = the request waits in a queue (user correction 2026-10-19)
Corrects the earlier interpretation (a "waiting for parts" status plus an asset "in storage" flag).
- The asset "in storage" flag, its portal button, the staff button, the portal filter and the CSV column are removed. Migration `2026_10_19_105036`: flagged assets (dev: 0) get an `asset_events` row `storage_flag_removed`, then the column is dropped (down() restores the flags).
- One ticket state for the case: stored value `waiting_for_parts`, label **"Waiting in queue (out of stock)"** for customers and staff. A ticket enters it when the spare part, consumable or replacement unit is unavailable; staff record what is missing (text and/or catalogue item) and optionally an estimated date.
- Queue order (`App\Services\Equipment\ServiceQueue`): priority (critical, high, normal, low), then the manual order (`queue_rank`), then time entered (`queued_at`) = FIFO within priority. Staff move a ticket to a position within its priority with a reason (audited `queue_reordered`).
- The customer sees "Waiting in the queue, position N" and the estimated date when set; entering and leaving the queue notify the customer as every status change does (portal users in-app/e-mail; Telegram groups get number and status only).
- When a goods receipt posts an item that queued tickets wait for, the service team gets "Stock arrived for queued requests" with the next tickets (in queue order). Statuses never change automatically.
- Leaving the queue clears `queued_at`, `queue_rank` and the estimated date. Service > Tickets > "Waiting" lists the queue in order; the dashboard tile shows the queue length and the oldest wait.
- Tests: `test_out_of_stock_queue_positions_reorder_and_stock_arrival`, `test_asset_status_map_without_a_storage_flag`.
