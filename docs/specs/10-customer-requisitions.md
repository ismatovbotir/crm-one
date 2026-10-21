# Spec 10 — Portal catalog and customer requisitions

Status: built 2026-10-19 (user requirement of the same day). Owner: PM. Related:
- Spec 01: items and categories.
- Spec 03: portal, roles, organizations (section 22).
- Spec 05: offers.
- Spec 06: orders.
- Spec 09: equipment.

## 1. Problem and goal

**User's words:** "The client's team should make a request according to a category, or select from items in our catalog but WITHOUT seeing prices; then the client's purchase manager makes the request to us, like an order created by the customer. We manage the categories."

**Goal:**
- Customer teams browse a price-free portal catalog that we curate.
- They build internal requests (requisitions), with lines that are either catalog items or category-level needs.
- Their purchase manager reviews and consolidates the requests and sends them to us.
- We answer with an offer through the existing offer → order flow.

**Success metrics:**
- Every request sent by a customer reaches its sales owner within one minute, with a notification.
- Median time from "sent to supplier" to "offered" is tracked.
- Zero price fields in any portal catalog or requisition response, enforced by a test.

**Non-goals:**
- Customer-visible prices outside offers.
- Punch-out / OCI.
- Budget approvals by amount (there are no prices).
- 1C exchange of requests.

## 2. Defaults (recorded, not asked)

- **D10-1 No prices anywhere** in the portal catalog, requisition screens, requisition notifications, exports or API. Only an offer addressed to the customer shows prices.
- **D10-2 Availability is shown as words only:** "In stock" or "On request".
  - The setting `requisitions.show_stock_band` (default off) adds a band: "1–10", "11–50", "50+".
  - Exact quantities are never shown.
- **D10-3 Catalog visibility.**
  - By default all active catalog items are visible.
  - **Global hiding:** a category or an item can be hidden for everyone. The new "Visible in portal" flag applies to the category and its sub-categories.
  - **Per-customer rules** (on the customer's Portal tab):
    - *deny* hides a category or item for that customer;
    - *allow* switches the customer to allow-list mode, where only allowed categories and items are visible;
    - an explicit *allow* also overrides a global hide for that customer.
  - **Who manages it:** the new permission `catalog.portal`, held by managers, sales heads and admins (catalogue setup is system configuration). Sales representatives see the rules but do not change them.
- **D10-4 Portal names of categories.** Categories still come from 1C. The CRM adds local fields: portal name per language (uz/ru/en; falls back to the 1C name), portal sort order and the visible flag.
- **D10-5 New portal roles** `requester` and `purchase_manager`, alongside `portal_admin`.
  - A `portal_admin` can do everything a purchase manager can.
  - `buyer` and `approver` act as requesters.
  - `viewer` and `finance` can read requests but not create them.
- **D10-6 Quantity limit for purchase managers (optional).** The setting `requisitions.pm_max_line_quantity` (default empty = no limit) applies when a purchase manager sends to us. Over the limit, only a portal admin may send. There is no money limit.
- **D10-7 Queue on our side:** "Customer requests" in the **Sales** sidebar section, right after Leads. A request from an existing customer does not need a lead. Each request is auto-assigned to the customer's sales owner.
- **D10-8 A Telegram group member who is a portal user creates requests in the portal only** (no requests from Telegram messages).
- **D10-9 1C does not pull requests.** The confirmed order goes to 1C as before. This decision is documented in the OpenAPI description.

## 3. Data

| Table | Columns |
|---|---|
| `categories` (added) | `portal_visible` (bool, default true), `portal_names` (json `{uz,ru,en}`), `portal_sort` (int) |
| `items` (added) | `portal_visible` (bool, default true) |
| `catalog_visibility_rules` | `organization_id`, `category_id` or `item_id`, `mode` (allow/deny), `created_by` |
| `requisitions` | `uuid`, `number` (`RQ-YYYY-NNNNN`), `organization_id`, `status`, `requester_id`, `purchase_manager_id`, `title`, `delivery_location`, `needed_by`, `notes`, `return_comment`, `reject_reason`, `decline_reason`, `owner_id` (our sales owner), `merged_into_id`, `split_from_id`, timestamps `submitted_at` / `approved_at` / `sent_at` / `decided_at` |
| `requisition_lines` | `kind` (item/category), `item_id`, `category_id`, `description`, `quantity` (decimal 15,3), `unit` (text), `needed_by`, `mapped_item_id` (our mapping of a category line), `source_line_id` (merge provenance), `position` |
| `requisition_comments` | `user_id`, `body`, `audience`: `client` (requester ↔ purchase manager, never shown to us) or `shared` (the thread with us) |
| attachments | the existing table, morph `requisition`. Up to 5 files of 10 MB each, re-validated by content (PDF, images, office documents). |
| `offers` (added) | `requisition_id`, linking the offer to its request (single source of truth) |

## 4. Status machine

```
draft ─▶ submitted ─▶ approved ─▶ received ─▶ quoting ─▶ offered ─▶ ordered
  │          │  ▲         │            │          │          │
  │          ▼  │         ▼            ▼          ▼          ▼
  │       returned     merged       declined   declined   quoting (offer rejected / revised)
  │          │
  └──────────┴──▶ cancelled        submitted ─▶ rejected (by the purchase manager) | merged
```

| From | To | Who |
|---|---|---|
| draft | submitted | requester. If the requester is a purchase manager or portal admin, the request may go straight to approved/received. |
| submitted | returned | purchase manager, with a comment |
| submitted | approved | purchase manager |
| submitted | rejected | purchase manager, with a reason |
| submitted, approved | merged | purchase manager (consolidation) |
| returned | submitted | requester (after edits) |
| draft, returned, submitted | cancelled | requester (own request) or purchase manager |
| approved | received | purchase manager, "Send to supplier". The request becomes visible to us. |
| received | quoting | our sales (or automatically when an offer is created) |
| received, quoting | declined | our sales, with a reason |
| quoting | offered | system, when a linked offer is **sent** |
| offered | ordered | system, when the linked offer is accepted |
| offered | quoting | system, when the linked offer is rejected or expires (a new offer can follow) |

- The customer sees "Sent to supplier" for `received`.
- Every change is logged in `status_transitions` and the activity log.

## 5. Purchase manager tools

- **Edit lines:** change quantities (and needed-by dates) on submitted or approved requests.
- **Merge:** choose two or more submitted or approved requests of the company.
  - A new consolidated request is created, owned by the purchase manager, with status approved.
  - Each line is copied with `source_line_id`; identical items are summed.
  - The originals become `merged`, with `merged_into_id` set.
- **Split:** choose lines of a submitted or approved request. They move to a new request with the same status and `split_from_id` set.
- **Return, reject, cancel, send to supplier.**

## 6. Our side

The "Customer requests" queue shows new, quoting, offered and all requests, with the owner, the age and the line count.

On a request:
- **Clarification:** a shared comment thread, visible to the customer.
- **Map category lines:** a category-level line can be mapped to a catalog item.
- **Decline**, with a reason.
- **Create offer from request:**
  - It creates a draft offer with prices from each item's sale price. Lines come from item lines plus mapped category lines.
  - All category lines must be mapped first.
  - The existing offer rules, alternatives and approvals apply.
  - The request moves to `quoting` and `offers.requisition_id` is set.
- **After the offer:** when the offer is sent, the request becomes `offered`; when it is accepted, the request becomes `ordered` and the normal offer → order flow continues.
- **Customer card:** the customer's Requests appear on the customer timeline.

## 7. Notifications

These use the new event `requisition` and go in-app, by e-mail and on Telegram, following preferences.

| When | To |
|---|---|
| Submitted | the company's purchase managers and portal admins |
| Returned, approved, rejected | the requester |
| Sent to supplier (received) | our sales owner (if there is none: the sales heads/managers who can assign) |
| Offered | the requester and the purchase manager |
| Clarification from us | the requester and the purchase manager |
| Comment from the customer on the shared thread | our owner |

## 8. Security

- **Tenant isolation:** all portal queries go through `PortalScope` (organization). Foreign uuids return 404.
- **Requesters** see their own requests plus requests they are a line author of. **Purchase managers and portal admins** see all of the company's requests.
- Requests cannot be created through the API.
- **Rate limits:** 30 request submissions per user per hour; at most 100 lines per request.
- **Uploads:** content-sniffed, at most 5 files of 10 MB, served only to the company's portal users and to staff who can view the request.
- **Price-free test:** responses of the catalog, requests, request detail and notifications are scanned for the sale price, cost and price field names.

## 9. Acceptance criteria (tests)

- **AC-1** The portal catalog shows visible items with availability words and no price data anywhere (page, Livewire payload, notifications).
- **AC-2** Per-customer allow and deny rules and global hiding are applied.
- **AC-3** Company A never sees company B's requests or catalog rules (404).
- **AC-4** Roles: a requester cannot approve or send; a purchase manager can; a viewer can only read.
- **AC-5** Transitions are enforced: return/resubmit, reject, cancel, send.
- **AC-6** Merge sums identical items and marks the originals merged; split moves the chosen lines.
- **AC-7** Create offer from request: every category line must be mapped; the offer has prices from the sale price and `requisition_id`; sending the offer makes the request `offered`; accepting it makes the request `ordered`.
- **AC-8** Notifications go to the right people at each step.

> **Spec 03 section 24 note (2026-10-19).** Customers and suppliers/carriers are one **Partner** table (`partners`) with roles. Links that mean the organization are `partner_id` (contacts, supplier profile, equipment, service tickets, requests, catalog rules); links to the customer role keep `customer_id`. The 1C contract (paths, scopes, fields, feeds) is unchanged.
