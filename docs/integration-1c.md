# CRM ⇄ 1C integration: guide for the 1C developer

Version 1.0 (2026-10-19). The machine-readable description is the OpenAPI 3.1 file. The CRM serves it at `/api/docs` (Swagger UI) and `/api/docs/openapi.json` / `.yaml`, and it is also committed as `docs/openapi/1c-integration.json` / `.yaml`. If this guide and the OpenAPI file ever disagree, the OpenAPI file is correct: an automated test keeps it in line with the code.

Contents:
1. Architecture
2. Access: token, IP allowlist, signing
3. Exchange schedule
4. First load, step by step
5. Data mapping
6. Ongoing exchange
7. Conflict rules and data ownership
8. Errors, retries, idempotency
9. curl / Postman cookbook
10. 1C:Enterprise (BSL) code samples
11. Go-live checklist

---

## 1. Architecture: pull-only

```
          push: catalog, stock, customers, payments
  1C  ───────────────────────────────────────────▶  CRM API  /api/v1/integration/1c
      ◀───────────────────────────────────────────
          pull: customers to create, documents (orders, invoices,
          delivery notes with serials, payments, goods receipts) + ack
```

- **The CRM never opens a connection to 1C.** Every exchange is started by 1C, using an HTTP client (`HTTPСоединение`) and a scheduled job (`РегламентноеЗадание`).
- **1C pushes:**
  - units, item groups, items (with prices and codes), warehouses, stock with cost;
  - its customers (counterparties);
  - payments booked in 1C.
- **1C pulls:**
  - customers created or changed in the CRM (to create or update them in 1C);
  - documents from the CRM outbox.

  1C then **acknowledges** each item: the CRM stores the 1C GUID and number and stops offering it.
- **Catalog and stock pushes are queued.** The CRM answers `202` right away and processes the data in the background; check the result with `GET /batches/{batch_id}`. Customers and payments are processed synchronously.

Base URL: `https://<crm-host>/api/v1/integration/1c`. HTTPS is required in production.

## 2. Access

### 2.1 Token

An administrator creates the token in **CRM → Settings → Integrations → Tokens**:
1. Enter a name, for example "1C production".
2. Choose the abilities (only the ones you need).
3. Optionally add the IP addresses or CIDR ranges of the 1C server.
4. Set the lifetime (default 180 days, maximum 365).
5. Tick "signing secret" if 1C connects over the internet.

The token (`1c_` + 48 characters) and the signing secret are shown **once**. Store them in 1C in a protected place, such as a constant visible to administrators only or the safe data storage (`БезопасноеХранилищеДанных`). There is no console command for tokens; use the UI. Rotate before expiry: create a new token, switch 1C to it, then revoke the old one.

| Ability | Allows |
|---|---|
| `items:write` | `POST /items`, the `items` section of `POST /batch` |
| `stock:write` | `POST /item-stock`, the `stock` section of `POST /batch` |
| `batches:read` | `GET /batches/{id}` |
| `customers:read` | `GET /customers/pending` |
| `customers:write` | `POST /customers/ack`, `POST /customers` |
| `orders:read` | outbox feed `orders` (orders, invoices, agreements, delivery notes) |
| `payments:read` | outbox feed `payments` |
| `warehouse:read` | outbox feed `warehouse` (goods receipts) |
| `payments:write` | `POST /payments` |
| `warehouse:write` | `POST /goods-receipts`, `PUT /goods-receipts/{crm_id}` (goods receipts created in 1C) |

Every request sends `Authorization: Bearer 1c_...`. Optionally send `X-Request-Id: <uuid>`; it comes back as `request_id` and appears in the CRM integration log.

### 2.2 IP allowlist

If the token has an allowlist, any other address gets `403 ip_not_allowed`.

### 2.3 Request signing (HMAC-SHA256)

Signing is required:
- when the request comes from outside the private network (anything other than 10/8, 172.16/12, 192.168/16, loopback or the ranges configured in the CRM);
- always, for tokens issued with a signing secret.

```
X-Timestamp: 1760865000                       ← Unix time in seconds (UTC), ±300 s
X-Signature: sha256=<hex>                      ← lowercase hex
<hex> = HMAC_SHA256(key = signing_secret, message = X-Timestamp + "." + body)
```

- `body` is the exact bytes sent (UTF-8 JSON). For GET requests it is the empty string, so the message is `"1760865000."`.
- Serialize the JSON once and send the very same string you signed.
- Keep the server clock in sync (NTP). A wrong clock gives `401 invalid_signature`.

## 3. Recommended exchange schedule

| Job | Frequency | Endpoint |
|---|---|---|
| Stock (delta) | every 5–10 min | `POST /item-stock` (`mode=delta`) |
| Catalog changes | every 15 min, and on change | `POST /items` (`mode=delta`) |
| Full stock snapshot | nightly (03:00) | `POST /item-stock` (`mode=full`, in parts) |
| Full catalog snapshot | weekly | `POST /items` (`mode=full`) |
| Customers from the CRM | every 5 min | `GET /customers/pending` → create/update in 1C → `POST /customers/ack` |
| Customers changed in 1C | every 15 min | `POST /customers` |
| Documents from the CRM | every 2–5 min per feed | `GET /outbox/pending?feed=orders|payments|warehouse` → post in 1C → `POST /outbox/ack` |
| Bank statements / cash | every 15 min | `POST /payments` |
| Health | every hour | `GET /ping` |

Stay below **60 requests per minute per token**. The CRM shows the time of the last contact per feed in Settings > Integrations and warns when a feed is stale (items 24 h, stock 30 min).

## 4. First load, step by step

Before you start, the administrator switches on "Inbound from 1C" (and "Customer exchange") in Settings > Integrations. Until then the API answers `503 integration_disabled`.

1. **Check the connection:** `GET /ping`. You get the abilities and `integration_enabled: true`.
2. **Units:** `POST /items` with `units` only. Use the OKEI code (796 pcs, 166 kg, 006 m, 112 l). The CRM sets the quantity precision from the code. Unknown codes produce a warning, and the precision is then set in the CRM.
3. **Item groups:** `POST /items` with `groups`, parents before children (or all in one batch).
4. **Items:** `POST /items` with `items`, in batches of at most 1000 records and 10 MB. Include the prices, the codes (`accountant_code`, `class_code` IKPU, `package_code`) and `is_serialized`.
5. **Warehouses:** `POST /item-stock` with `warehouses` and `as_of`, and `stock: []`.
6. **Stock:** a full snapshot: `mode: full`, the same `snapshot.id` for all parts, `part` 1..N, `total_parts` N.
7. **Customers:**
   1. Push your counterparties with `POST /customers` (200 per request). They are matched by **tax ID only** (rev. 4): a CRM partner with the same tax ID is updated and linked; `crm_id` and the GUID are not used for matching.
   2. Then pull `GET /customers/pending` and acknowledge.
8. **Check each batch:** `GET /batches/{batch_id}` → `status: processed` (or `partial` with the rejected records listed).
9. **Turn on the scheduled jobs** from section 3.

For an initial load from a file, the CRM administrator can also import items with their 1C GUIDs (Settings > Data import). The API stays the normal path.

## 5. Data mapping

### 5.1 General rules

| Topic | Rule |
|---|---|
| Identifiers | 1C reference GUID (`Ссылка.УникальныйИдентификатор()`), sent as `id` / `*_id`. Lower or upper case is fine. The CRM's own ids are UUIDs (`crm_id`). |
| Dates | `YYYY-MM-DD`. Date-times are ISO 8601; without an offset they are read as Asia/Tashkent. |
| Money | Decimal **string** in major units, dot as separator: `"1500000.00"`. Rounded half-up to 2 decimals. The CRM stores minor units (tiyin/cents). |
| Currency | ISO 4217: `UZS`, `USD`, `EUR`, `RUB`. |
| VAT | Percent string `"12"`, `"0"`, or `"exempt"`. The CRM stores basis points (12 % = 1200). |
| Quantities | Decimal string, up to 3 decimals, `"12.500"`. Negative stock is allowed. |
| Deletion | `is_deleted: true` (deletion mark). The CRM deactivates the record; nothing is physically deleted. |
| Ordering | `updated_at` of the record. An older `updated_at` never overwrites a newer one (`stale`). |

### 5.2 Item (`items[]`)

| 1C (УТ/КА: Номенклатура) | CRM field | Notes |
|---|---|---|
| Ссылка (GUID) | `id` | required |
| Код | `code` | required, ≤64; shown as SKU |
| Артикул | `article` | ≤64 |
| Наименование | `name` | required, ≤255 |
| Описание | `description` | |
| Вид номенклатуры (Товар/Услуга) | `type` | `product` / `service` |
| Родитель (группа) | `group_id` | GUID of a group in `groups` |
| Единица измерения | `unit_id` | GUID of a unit in `units`; required |
| Ставка НДС | `vat_rate` | `"12"`, `"0"`, `"exempt"` |
| Бухгалтерский код / счёт | `accountant_code` | ≤64 |
| Код ИКПУ (MXIK) | `class_code` | digits, normally 17 |
| Код упаковки | `package_code` | digits ≤32 |
| Ведётся учёт по серийным номерам | `is_serialized` | boolean; the serials themselves are kept in the CRM |
| Цена продажи + валюта | `sale_price`, `sale_currency` | absent = keep, `null` = clear |
| Цена закупки + валюта | `purchase_price`, `purchase_currency` | absent = keep, `null` = clear |
| Цены включают НДС | `prices_include_vat` | boolean |
| Пометка удаления | `is_deleted` | |
| Дата изменения | `updated_at` | |
| Версия данных | `version` | optional |

### 5.3 Stock (`stock[]`)

| 1C | CRM | Notes |
|---|---|---|
| Номенклатура | `item_id` | GUID |
| Склад | `warehouse_id` | GUID of a warehouse in `warehouses` |
| Количество (остаток) | `quantity` | string, may be negative |
| Резерв | `reserved` | not negative |
| Себестоимость (сумма) | `cost` | total cost of `quantity` (preferred) |
| Себестоимость за единицу | `unit_cost` | used when `cost` is absent |
| Валюта себестоимости | `cost_currency` | default UZS |
| — | `as_of` (batch) | the moment of the balance |

### 5.4 Customer (`POST /customers`, `customers[]`)

| 1C (Контрагенты) | CRM | Notes |
|---|---|---|
| Ссылка | `id` | GUID |
| Код | `code` | |
| Юр./физ. лицо | `legal_type` | `legal_entity`, `sole_proprietor`, `individual` (individuals are skipped) |
| Наименование | `name` | |
| Полное наименование | `full_name` | |
| ИНН | `tax_id` | |
| Страна (код) | `country` | ISO alpha-2 |
| Город, адрес, индекс | `city`, `address`, `postal_code` | |
| Телефон, e-mail | `phone`, `email` | |
| Основной менеджер (e-mail) | `manager.email` | owner of a new customer in the CRM |
| Контактные лица | `contacts[]` | `id`, `first_name`, `last_name`, `position`, `phone`, `email`, `is_deleted` |
| Пометка удаления | `is_deleted` | |
| Объединён с | `merged_into_id` | GUID of the counterparty this one was merged into |

The CRM keeps one **organization** per tax ID, with roles customer / supplier / carrier. This exchange covers **customers only**. Supplier/carrier exchange columns exist in the CRM but are not active, and there is no endpoint for them yet.

### 5.5 Documents pulled from the CRM

All amounts are in the document currency, as decimal strings. Items are referenced by the 1C GUID (`item_id`) plus the codes. Customers are referenced by `customer.external_id` (your GUID) and `customer.crm_id`.

| Event | Feed | Create in 1C (suggestion) |
|---|---|---|
| `order.confirmed` / `order.cancelled` / `order.closed` | orders | Заказ клиента (create / cancel / close) |
| `invoice.issued` (proforma) | orders | Счёт на оплату покупателю |
| `invoice.registered` (tax invoice, ESF) / `invoice.voided` | orders | Счёт-фактура выданный / отмена |
| `agreement.signed` / `agreement.terminated` | orders | Договор с контрагентом |
| `delivery.posted` | orders | Реализация товаров и услуг; `lines[].serials` carry the shipped serial numbers |
| `payment.recorded` / `payment.refund_recorded` / `payment.voided` | payments | Поступление / возврат денежных средств |
| `goods_receipt.posted` | warehouse | Supplier receipts: complete your own document (`onec_document.id`) with received / damaged quantities; returns and opening balances: Оприходование / возврат от клиента (`source_type`) |
| `goods_receipt.cancelled` | warehouse | A 1C receipt cancelled in the CRM (by the warehouse head) |
| `stock_off.posted` | warehouse | Списание товаров (write-off / damage act; `reason`, quantities, estimated value; linked goods receipt for receipt damage) |
| `service_ticket.closed` | orders | Реализация услуг (ремонт вне гарантии: работы и запчасти) |

Order-feed events of a customer that 1C does not know yet (no GUID) are held back until that customer has been acknowledged. Events of one document come strictly in order: the next one appears only after the previous one is acknowledged.

> **Partners (2026-10-19).** The CRM now stores every organization as a *partner* with roles (customer, supplier, carrier). The `/customers` endpoints, scopes, payload fields and feeds work exactly as before and cover partners with the customer role only. Suppliers and carriers are not exchanged. Nothing changes for the 1C side.

> **Power of attorney (2026-10-19).** Order, invoice and delivery payloads carry `power_of_attorney {required, received, received_at, note, overridden}`; the CRM registers a tax invoice only after the power of attorney is received (or overridden). Payments recorded in the CRM for orders keep the `payment.recorded` event; they are an information signal, the books stay in 1C.

## 6. Ongoing exchange

### 6.1 Push (catalog, stock)

```
POST /items | /item-stock | /batch
{ "batch_id": "<new uuid>", "mode": "delta" | "full", "sent_at": "...", ... }
→ 202 { "status": "queued", "accepted": n, "rejected": [], "status_url": "..." }
→ 207 the same, with "rejected": [{ section, index, id, errors[] }]
→ 422 envelope invalid, or every record invalid (nothing stored)
```

- **Delta** updates only what you send.
- **Full** sends everything, in parts that share one `snapshot.id`. When the last part arrives (within 120 minutes), active records that were not sent are deactivated (catalog) or removed (stock).
- **Safety:** if fewer than 80 % of the active rows were seen, nothing is removed and the snapshot ends as `partial`. A stock snapshot can be limited with `snapshot.scope.warehouse_ids`.

### 6.2 Customers both ways

1. `GET /customers/pending?limit=100&cursor=0` returns `data[]`, `next_cursor` and `has_more`. Repeat with `cursor=next_cursor` while `has_more` is true.
2. For each entry, find the customer by `external_id` (if set) or create it. Keep `crm_id` in 1C, for example in an additional attribute.
3. Send one acknowledgement per entry:
   - `{crm_id, outbox_id, status: "synced", external_id, external_code, contacts: [{crm_id, external_id}]}`, or
   - `{crm_id, outbox_id, status: "failed", error: {code, message, fields}}`.

   The failure message is shown to the CRM user.
4. Entries stay pending until acknowledged. If your process crashes, you simply receive them again (`delivery_count` grows).

### 6.3 Documents

1. `GET /outbox/pending?feed=orders&limit=200&cursor=0`.
2. Process the events in `sequence` order.
3. Acknowledge with `{event_id, result: "applied", onec_guid, onec_number, posting_status: "posted", updated_at}`, or `result: "rejected"` with `errors`.
4. If a document is later unposted or deleted in 1C, send another ack for the same `event_id` with the new `posting_status` and a newer `updated_at`.

### 6.4 Payments

`POST /payments` with your payment documents: `id` = 1C GUID, `customer_id` = counterparty GUID, `amount`, `currency`, `paid_on`, `reference_number`, `purpose`, and optionally `allocations: [{order_crm_id, amount}]`.

| Result | Meaning |
|---|---|
| `created` | new payment |
| `unmatched` | no customer found; an accountant matches it |
| `possible_duplicate` | same amount, date and reference as an existing payment |
| `updated` / `corrected` | changes applied |
| `voided` | `is_deleted: true` was sent |
| `unchanged` | nothing to change |

### 6.5 Goods receipts (push from 1C)

Supplier goods receipts are created **in 1C only** (Spec 06 section 21.11). 1C pushes each receipt; the CRM stock manager enters what actually arrived (received and damaged quantities with a note, serial numbers), marks it checked and posts it. The posted result is then available for 1C to pull from the `warehouse` feed (`goods_receipt.posted`, no serial numbers by default). Customer returns and opening balances are still created in the CRM.

```
POST /goods-receipts                        (ability warehouse:write)
{ "batch_id": "<uuid>", "sent_at": "...", "receipts": [ {
    "id": "<1C document GUID>", "number": "ПТ-000318", "date": "2026-10-19",
    "updated_at": "2026-10-19T09:58:00+05:00",       // optional, for the stale check
    "crm_id": null, "version": null,                 // send them back once you have them
    "warehouse_id": "<1C warehouse GUID>",
    "supplier": { "tax_id": "305112233", "name": "...", "full_name": "...", "country": "UZ" },
    "lines": [ { "line_id": "<1C row GUID>", "item": { "id": "<1C item GUID>" }, "expected_qty": "10" },
               { "line_id": "...", "item": { "id": "...", "code": "00-00004410", "name": "...", "unit_id": "<unit GUID>" }, "expected_qty": "200" } ],
    "is_deleted": false } ] }
→ 200 { "results": [ { "index": 0, "id": "<your GUID>", "crm_id": "<CRM uuid>", "number": "GR-2026-00057", "version": 1, "result": "created", "created": { "partner": false, "items": ["00-00004410"] } } ] }
→ 207 when at least one receipt failed

PUT /goods-receipts/{crm_id}                (one receipt, same fields)
→ 200 created | updated | unchanged | cancelled | cancel_requested
→ 409 stale_version | stale_update | receipt_posted | receipt_cancelled | line_has_serials | id_mismatch
→ 422 invalid receipt
```

Rules:
- **Supplier by tax ID only.** `supplier.tax_id` is mandatory (`tax_id_required`, nothing is created). A supplier the CRM does not know is created with the supplier role, "Sync with 1C" on and a "needs review" flag. An existing partner with that tax ID is used as it is.
- **Items by 1C GUID.** An unknown item needs `code`, `name` and a known `unit_id`; it is created with a "needs review" flag and completed later by your normal `/items` push. Non-serialized items are posted by quantity only.
- **Document linking.** Store `crm_id`, `number` and `version` from the answer. Matching: `crm_id` first, then your `id`; no match creates the receipt. Send the `version` you hold: an older one is `stale_version`; an older `updated_at` is `stale_update`. Same content → `unchanged`.
- **Before posting only.** Updates are applied while the receipt is not posted. A change to a posted receipt is refused (`receipt_posted`, `review_flagged: true`) and shown to the stock manager, who proposes a correction; serial-linked data is never rewritten.
- **Lines with serial numbers** keep their item and cannot be removed (`line_has_serials`; nothing of that update is kept). The expected quantity may change; if fewer units are expected than serials entered, the CRM shows an overage that the stock manager must explain.
- **Serialized lines in the result:** `received` = number of serial numbers entered, `damaged` = number of them flagged damaged; non-serialized lines carry the quantities typed by the stock manager.
- **Delete/cancel in 1C** (`is_deleted: true`): `cancelled` if no serial numbers were entered, otherwise `cancel_requested` (the warehouse head decides). A receipt cancelled in the CRM is available for 1C to pull as `goods_receipt.cancelled`.
- One transaction per receipt: a failed receipt leaves nothing behind (no half-created supplier or item). Idempotent by `batch_id`; 50 receipts per push, 500 lines per receipt.

## 7. Conflict rules and data ownership

| Data | Owner | Rule |
|---|---|---|
| Items, units, groups, prices, accounting codes, warehouses, stock and cost | **1C** | The CRM never edits them (it adds pictures and cargo data only). |
| Customers already in 1C | **1C wins** | Fields pushed by 1C overwrite the CRM values. While a CRM change is still waiting for your ack, the CRM keeps the replaced values in its log for the user. |
| New customers created in the CRM | CRM until acknowledged | Then 1C. |
| Serial numbers, warranty, item pictures, CRM-local cargo data | **CRM** | 1C must not send them. They are ignored if present. 1C receives serials inside `delivery.posted` (and in customer returns). |
| Orders, invoices, agreements, delivery notes | **CRM** | 1C receives them and acknowledges with its GUID and number. |
| Payments | both | The CRM records payments and 1C confirms them via the outbox ack. 1C sends its own payments via `POST /payments`. |

Matching by tax ID (rev. 4, 2026-10-19):
- The tax ID (INN) is the only matching key. It is unique per partner in the CRM, so a push can never create a duplicate: the partner with that tax ID is updated (1C wins), your GUID is stored as information and "Sync with 1C" is switched on. Its roles (customer / supplier / carrier) are not changed.
- No partner with that tax ID: a new customer is created while the CRM setting "1C may create new partners" is on (default); otherwise the record is `skipped` with `create_disabled`.
- No tax ID (branches, individuals, foreign partners without INN): `skipped` with `tax_id_missing`; such partners are not synced. Keep them in 1C only, or add the INN first.
- `crm_id` and `id` are still accepted in payloads for backward compatibility but are ignored for matching. A GUID that is already stored on another partner gives `conflict`.
- The CRM offers a customer in `GET /customers/pending` only when its "Sync with 1C" switch is on and it has a tax ID. The switch is off by default for every new partner; existing synced or queued customers were switched on during the upgrade.

## 8. Errors, retries, idempotency

Every error has the same shape:

```json
{ "error": { "code": "validation_failed", "message": "...", "fields": { "batch_id": ["required"] }, "request_id": "..." } }
```

| HTTP | code | What to do |
|---|---|---|
| 401 | `unauthenticated`, `signature_required`, `invalid_signature` | Check the token, clock and signature. Do not retry blindly. |
| 403 | `forbidden_ability`, `ip_not_allowed`, `https_required` | Configuration problem; ask the CRM administrator. |
| 404 | `not_found` | Unknown batch (or one sent with another token). |
| 409 | `idempotency_key_reused`, `snapshot_mismatch` | A `batch_id` was reused with a different body. Generate a new `batch_id` for new data. |
| 413 | `payload_too_large` | Split the batch. |
| 415 | `unsupported_media_type` | Send `Content-Type: application/json; charset=utf-8`. |
| 422 | `validation_failed` | Fix the data. Read `fields`, and `rejected` for record errors. |
| 429 | `rate_limited` | Wait `Retry-After` seconds, then retry the **same** request. |
| 503 | `integration_disabled` | The integration is switched off in the CRM. Retry after `Retry-After`. |
| 5xx / network | — | Retry with exponential backoff (5 s, 30 s, 2 min, 10 min) and the **same** `batch_id`. |

**Idempotency.**
- Each push has its own `batch_id` (UUID). Store it with the outgoing package in 1C until a 2xx answer arrives.
- Re-sending the same `batch_id` with the same body returns the original answer with the header `Idempotent-Replayed: true`. Nothing is applied twice.
- The `Idempotency-Key` header is optional; if you send it, it must equal `batch_id`.
- Acks are idempotent too: a repeated ack returns `already_applied`.

## 9. curl / Postman cookbook

Set the variables once:

```bash
BASE=https://crm.example.uz/api/v1/integration/1c
TOKEN=1c_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx   # from Settings > Integrations
SECRET=...                                                   # only for signed tokens
```

Ping (unsigned, from the private network):

```bash
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/ping"
```

Signed request (bash + openssl):

```bash
BODY='{"batch_id":"0b6f6a4e-2c1d-4f3a-9e8b-7d6c5b4a3f21","mode":"delta","sent_at":"2026-10-19T09:30:00+05:00","items":[]}'
TS=$(date +%s)
SIG=$(printf '%s.%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -hex | sed 's/^.* //')
curl -s -X POST "$BASE/items" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json; charset=utf-8" \
  -H "X-Timestamp: $TS" -H "X-Signature: sha256=$SIG" --data-binary "$BODY"
```

Stock delta:

```bash
curl -s -X POST "$BASE/item-stock" -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" --data-binary @- <<'JSON'
{"batch_id":"5d4c3b2a-1f0e-4d9c-8b7a-6f5e4d3c2b1a","mode":"delta","sent_at":"2026-10-19T10:00:00+05:00","as_of":"2026-10-19T10:00:00+05:00",
 "warehouses":[],"stock":[{"item_id":"6b1c2d3e-4f50-4a6b-8c7d-9e0f1a2b3c4d","warehouse_id":"3c4d5e6f-0000-4000-8000-000000000001","quantity":"14","reserved":"2","cost":"29400000.00","cost_currency":"UZS"}]}
JSON
```

Batch status:

```bash
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/batches/5d4c3b2a-1f0e-4d9c-8b7a-6f5e4d3c2b1a"
```

Customers: pull, then acknowledge:

```bash
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/customers/pending?limit=100&cursor=0"
curl -s -X POST "$BASE/customers/ack" -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  --data-binary '{"acks":[{"crm_id":"c7a1e2d3-4b5c-4d6e-8f70-81a2b3c4d5e6","outbox_id":41,"status":"synced","external_id":"e9c3a4b5-6d7e-4f80-a192-a3c4d5e6f708","external_code":"00-000123"}]}'
```

Documents: pull, then acknowledge:

```bash
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/outbox/pending?feed=orders&limit=200&cursor=0"
curl -s -X POST "$BASE/outbox/ack" -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  --data-binary '{"acks":[{"event_id":"b1c2d3e4-f5a6-4b7c-8d9e-0f1a2b3c4d5e","result":"applied","onec_guid":"f5a6b7c8-d9e0-4f10-a213-4d5e6f708192","onec_number":"ЦБ-00000881","posting_status":"posted","updated_at":"2026-10-19T11:06:00+05:00"}]}'
```

**Postman.** Import `docs/openapi/1c-integration.json` (Import → File) to get a collection with every request and example. Set the `bearerAuth` token in the collection's Authorization tab. For signed requests, add a pre-request script that computes `X-Signature` with `CryptoJS.HmacSHA256(ts + "." + pm.request.body.raw, secret)`.

## 10. 1C:Enterprise (BSL) samples

These samples are for platform 8.3.14+ (they use `ХешированиеДанных` with SHA256 and `БуферДвоичныхДанных`). Adapt the names to your configuration.

### 10.1 HMAC-SHA256 and the signature

```bsl
Функция HMAC_SHA256(Ключ, Сообщение) Экспорт
	// Ключ, Сообщение - ДвоичныеДанные. Возвращает ДвоичныеДанные (32 байта).
	РазмерБлока = 64;
	БуферКлюча = ПолучитьБуферДвоичныхДанныхИзДвоичныхДанных(Ключ);
	Если БуферКлюча.Размер > РазмерБлока Тогда
		Хеш = Новый ХешированиеДанных(ХешФункция.SHA256);
		Хеш.Добавить(Ключ);
		БуферКлюча = ПолучитьБуферДвоичныхДанныхИзДвоичныхДанных(Хеш.ХешСумма);
	КонецЕсли;
	Ipad = Новый БуферДвоичныхДанных(РазмерБлока);
	Opad = Новый БуферДвоичныхДанных(РазмерБлока);
	Для Индекс = 0 По РазмерБлока - 1 Цикл
		Байт = ?(Индекс < БуферКлюча.Размер, БуферКлюча[Индекс], 0);
		Ipad.Установить(Индекс, ПобитовоеИсключительноеИли(Байт, 54));   // 0x36
		Opad.Установить(Индекс, ПобитовоеИсключительноеИли(Байт, 92));   // 0x5C
	КонецЦикла;
	Внутренний = Новый ХешированиеДанных(ХешФункция.SHA256);
	Внутренний.Добавить(ПолучитьДвоичныеДанныеИзБуфераДвоичныхДанных(Ipad));
	Внутренний.Добавить(Сообщение);
	Внешний = Новый ХешированиеДанных(ХешФункция.SHA256);
	Внешний.Добавить(ПолучитьДвоичныеДанныеИзБуфераДвоичныхДанных(Opad));
	Внешний.Добавить(Внутренний.ХешСумма);
	Возврат Внешний.ХешСумма;
КонецФункции

Функция ПодписьЗапроса(СекретПодписи, Метка, ТелоСтрокой) Экспорт
	Сообщение = ПолучитьДвоичныеДанныеИзСтроки(Метка + "." + ТелоСтрокой, КодировкаТекста.UTF8, Ложь); // без BOM
	Ключ = ПолучитьДвоичныеДанныеИзСтроки(СекретПодписи, КодировкаТекста.UTF8, Ложь);
	Возврат "sha256=" + НРег(ПолучитьHexСтрокуИзДвоичныхДанных(HMAC_SHA256(Ключ, Сообщение)));
КонецФункции
```

### 10.2 Sending a request (with retry)

```bsl
Функция ВызватьCRM(Метод, Адрес, ТелоСтрокой = "") Экспорт
	// Адрес относительно /api/v1/integration/1c, например "/items" или "/outbox/pending?feed=orders"
	Хост = "crm.example.uz";
	Токен = ПолучитьТокенCRM();          // из безопасного хранилища
	Секрет = ПолучитьСекретПодписиCRM();  // пустая строка, если подпись не нужна
	Соединение = Новый HTTPСоединение(Хост, 443, , , , 30, Новый ЗащищенноеСоединениеOpenSSL());

	Паузы = Новый Массив; Паузы.Добавить(5); Паузы.Добавить(30); Паузы.Добавить(120); Паузы.Добавить(600);
	Для Попытка = 0 По Паузы.Количество() Цикл
		Запрос = Новый HTTPЗапрос("/api/v1/integration/1c" + Адрес);
		Запрос.Заголовки.Вставить("Authorization", "Bearer " + Токен);
		Запрос.Заголовки.Вставить("Accept", "application/json");
		Запрос.Заголовки.Вставить("X-Request-Id", НРег(Строка(Новый УникальныйИдентификатор)));
		Если ЗначениеЗаполнено(Секрет) Тогда
			Метка = Формат(УниверсальнаяДатаВМиллисекундах() / 1000, "ЧГ=0; ЧДЦ=0");
			Запрос.Заголовки.Вставить("X-Timestamp", Метка);
			Запрос.Заголовки.Вставить("X-Signature", ПодписьЗапроса(Секрет, Метка, ТелоСтрокой));
		КонецЕсли;
		Если Метод = "POST" Тогда
			Запрос.Заголовки.Вставить("Content-Type", "application/json; charset=utf-8");
			Запрос.УстановитьТелоИзСтроки(ТелоСтрокой, КодировкаТекста.UTF8, ИспользованиеByteOrderMark.НеИспользовать);
		КонецЕсли;
		Попытка
			Ответ = ?(Метод = "POST", Соединение.ОтправитьДляОбработки(Запрос), Соединение.Получить(Запрос));
			Код = Ответ.КодСостояния;
		Исключение
			Код = 0; // сеть
		КонецПопытки;
		Если Код >= 200 И Код < 300 Тогда
			Возврат ПрочитатьJSONСтроку(Ответ.ПолучитьТелоКакСтроку(КодировкаТекста.UTF8));
		КонецЕсли;
		Если Код <> 0 И Код <> 429 И Код <> 503 И Код < 500 Тогда
			ВызватьИсключение СтрШаблон("CRM %1: %2", Код, Ответ.ПолучитьТелоКакСтроку(КодировкаТекста.UTF8));
		КонецЕсли;
		Если Попытка < Паузы.Количество() Тогда
			Пауза = Паузы[Попытка];
			Если Код = 429 Или Код = 503 Тогда
				Пауза = Макс(Пауза, Число("0" + Ответ.Заголовки.Получить("Retry-After")));
			КонецЕсли;
			ПриостановитьВыполнение(Пауза); // свой хелпер: в фоне - ожидание, на сервере - ФоновыеЗадания
		КонецЕсли;
	КонецЦикла;
	ВызватьИсключение "CRM недоступна, пакет будет отправлен повторно с тем же batch_id";
КонецФункции
```

The same `ТелоСтрокой`, and therefore the same `batch_id`, is reused on every attempt. A retry never creates duplicates.

### 10.3 Building and sending items in batches

```bsl
Процедура ВыгрузитьНоменклатуруВCRM(ИзмененнаяНоменклатура) Экспорт
	РазмерПакета = 500;
	Записи = Новый Массив;
	Для Каждого Ссылка Из ИзмененнаяНоменклатура Цикл
		Записи.Добавить(ЗаписьНоменклатуры(Ссылка));
		Если Записи.Количество() = РазмерПакета Тогда
			ОтправитьПакетНоменклатуры(Записи);
			Записи = Новый Массив;
		КонецЕсли;
	КонецЦикла;
	Если Записи.Количество() > 0 Тогда
		ОтправитьПакетНоменклатуры(Записи);
	КонецЕсли;
КонецПроцедуры

Функция ЗаписьНоменклатуры(Ссылка)
	З = Новый Структура;
	З.Вставить("id", НРег(Строка(Ссылка.УникальныйИдентификатор())));
	З.Вставить("code", СокрЛП(Ссылка.Код));
	З.Вставить("article", СокрЛП(Ссылка.Артикул));
	З.Вставить("name", СокрЛП(Ссылка.Наименование));
	З.Вставить("type", ?(Ссылка.ТипНоменклатуры = Перечисления.ТипыНоменклатуры.Услуга, "service", "product"));
	З.Вставить("unit_id", НРег(Строка(Ссылка.ЕдиницаИзмерения.УникальныйИдентификатор())));
	З.Вставить("vat_rate", "12");                         // из ставки НДС номенклатуры
	З.Вставить("class_code", СокрЛП(Ссылка.КодИКПУ));      // реквизиты вашей конфигурации
	З.Вставить("is_serialized", Ссылка.ИспользоватьСерийныеНомера);
	З.Вставить("sale_price", Формат(ЦенаПродажи(Ссылка), "ЧРД=.; ЧГ=0; ЧДЦ=2; ЧН=0.00"));
	З.Вставить("sale_currency", "UZS");
	З.Вставить("is_deleted", Ссылка.ПометкаУдаления);
	З.Вставить("updated_at", ЗаписатьДатуJSON(ТекущаяДатаСеанса(), ФорматДатыJSON.ISO, ВариантЗаписиДатыJSON.ЛокальнаяДатаСоСмещением));
	Возврат З;
КонецФункции

Процедура ОтправитьПакетНоменклатуры(Записи)
	Пакет = Новый Структура;
	Пакет.Вставить("batch_id", НРег(Строка(Новый УникальныйИдентификатор)));
	Пакет.Вставить("mode", "delta");
	Пакет.Вставить("sent_at", ЗаписатьДатуJSON(ТекущаяДатаСеанса(), ФорматДатыJSON.ISO, ВариантЗаписиДатыJSON.ЛокальнаяДатаСоСмещением));
	Пакет.Вставить("items", Записи);
	Тело = ЗаписатьJSONСтроку(Пакет);
	СохранитьИсходящийПакет(Пакет.batch_id, Тело); // очередь: повтор после сбоя с тем же телом
	Ответ = ВызватьCRM("POST", "/items", Тело);
	ОтметитьПакетОтправленным(Пакет.batch_id, Ответ);
КонецПроцедуры
```

(`ЗаписатьJSONСтроку` / `ПрочитатьJSONСтроку` are your wrappers around `ЗаписьJSON` / `ЧтениеJSON` with `ЗаписатьJSON`/`ПрочитатьJSON`.) Money must be sent as **strings**: use `Формат(..., "ЧРД=.; ЧГ=0; ЧДЦ=2")`.

### 10.4 Pulling the outbox

```bsl
Процедура ЗагрузитьДокументыCRM(Лента) Экспорт // "orders", "payments", "warehouse"
	Курсор = "0";
	Пока Истина Цикл
		Страница = ВызватьCRM("GET", СтрШаблон("/outbox/pending?feed=%1&limit=200&cursor=%2", Лента, Курсор));
		Подтверждения = Новый Массив;
		Для Каждого Событие Из Страница.data Цикл
			Подтверждения.Добавить(ОбработатьСобытиеCRM(Событие)); // создать/провести документ, вернуть ack
		КонецЦикла;
		Если Подтверждения.Количество() > 0 Тогда
			ВызватьCRM("POST", "/outbox/ack", ЗаписатьJSONСтроку(Новый Структура("acks", Подтверждения)));
		КонецЕсли;
		Если Не Страница.has_more Или Страница.next_cursor = Неопределено Тогда
			Прервать;
		КонецЕсли;
		Курсор = Страница.next_cursor;
	КонецЦикла;
КонецПроцедуры
```

## 11. Go-live checklist

- [ ] Production token(s) created with the minimum abilities, an IP allowlist and (over the internet) a signing secret. Stored securely in 1C. Expiry date noted.
- [ ] HTTPS certificate of the CRM trusted by the 1C server. Clock synchronised (NTP).
- [ ] `GET /ping` OK from the production 1C server.
- [ ] First load done (units → groups → items → warehouses → stock full snapshot → customers); every batch `processed`.
- [ ] Customer exchange tested both ways: CRM-created customer pulled and acknowledged with its GUID; 1C change pushed.
- [ ] Each outbox feed pulled and acknowledged once: order, delivery with serials, payment, goods receipt. A rejected ack tested (visible to CRM users).
- [ ] Payments push tested, including `unmatched` and `is_deleted`.
- [ ] Retry tested: the same `batch_id` re-sent returns `Idempotent-Replayed: true`.
- [ ] Scheduled jobs on and within 60 requests per minute.
- [ ] CRM Settings > Integrations shows fresh contact times for all feeds, with no stale warnings.
- [ ] Contacts agreed: who watches failed acks on each side; token rotation date in the calendar.
