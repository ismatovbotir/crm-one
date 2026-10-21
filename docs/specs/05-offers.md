# Spec 05 — Offers (Commercial Quotations) with Alternative and Optional Items

| | |
|---|---|
| Status | Draft. Design only; open questions in section 15 have defaults that will be built if unanswered |
| Owner | Product (B2B sales CRM) |
| Date | 2026-10-16 |
| Stack | Laravel 13 (PHP ^8.3), Livewire 4, PHPUnit, uz (default) / ru / en, red/silver/white theme |
| Depends on | Spec 01 (customers, contacts, `Money`, `activity_log`, `ItemPricing`), Spec 02 (1C read-only catalog, one sale + one purchase price per item with `prices_include_vat`, stock cost, `item_stocks`, GUIDs for order push), Spec 03 (portal realm, portal roles and thresholds, OTP service, Telegram/email notifications, `PermissionMap`, departments, attachments, approvals inbox), Spec 04 Leads (`number_sequences`, `status_transitions`, lead → offer handoff) |
| Amends | Spec 01 roadmap "3. Offers" row (detailed here); Spec 03 BR-P.4 (open statuses and complete selection, section 16); `.claude/skills/offer-quotation` (alternative-items rule) |
| Feeds | 06 Orders, Agreements, Accounting & Stock (`orders.offer_id` unique; only fixed + selected lines are copied via `OfferToOrderPayload`; agreement scope comes from the order, never from unselected options; Spec 07 unused), Spec 03 P-3/P-4 (portal order request → offer → accept) |
| Domain skills | `.claude/skills/offer-quotation`, `b2b-sales-workflow`, `item-catalog` |

### Repo baseline (verified 2026-10-16)
- Built: users (role, type, locale, profile), `settings`, `activity_log` + `LogsActivity` concern, departments (+ `DepartmentPolicy`, `app/Actions/Departments`), `PermissionMap` skeleton, `config/navigation.php` (`nav.offers` is `soon`), print stack `App\Documents\{DocumentRenderer, DocumentLanguage, DocumentFormatter, AmountInWords, HasDocumentLanguage, InteractsWithDocumentLanguage}`, `layouts/print`, `<x-print-language-picker>`, `resources/views/documents/sample.blade.php` (sample offer), `lang/{uz,ru,en}/documents.php` (offer/order/agreement/invoice labels), `TranslationParityTest`, `DocumentPrintingTest`.
- Not built: customers/contacts, catalog, prices, stock, leads, `number_sequences`, `status_transitions`, portal, attachments. This spec assumes their designs in Specs 01–04 and names the exact columns it reads.
- Another agent is implementing code concurrently; re-verify before building each increment.

### Pricing amendment (rev. 4, 2026-10-18)
- Price lists are gone (Spec 01/02 rev. 4). A line's price defaults to the item's **single sale price** (`price_source = item_sale_price`), converted once to the offer basis if the item's `prices_include_vat` differs (BR-2). `list_unit_price_minor` = that converted sale price; a manual unit price below it counts as discount (4.3). An item without a sale price in the offer currency cannot be quoted (no FX).
- Offer VAT basis (DEC-10) comes from `config('offers.prices_include_vat')` (default net), not from a customer price type.
- Each line snapshots `cost_price_minor` = the item's cost basis (weighted-average stock cost price, else purchase price; net of VAT) when added, refreshed at send. The builder shows line cost and margin (internal only) and the margin of fixed + chosen/recommended lines.
- **Below-cost rule** (cost basis for the discount check): a line whose net amount is below `cost_price_minor × quantity` makes the offer require approval, regardless of the discount limit, and only users with `offers.approve_below_cost` (manager, management head) may approve it. `offer_approvals.reasons[]` flags `below_cost`.
- Removed: `offers.price_list_id`, `offer_lines.price_list_id` / `price_list_external_id`, customer price lists, the item "price check" tab.

### Build status (2026-10-18, staff side: increments 05-1..05-5 + 05-7, parts of 05-6)
- Built: `number_sequences`, `status_transitions` (generic, ahead of Spec 04), `offers` (+ nullable `lead_id`; FK to `leads` added with Spec 04 on 2026-10-18), `offer_option_groups`, `offer_lines` (snapshot incl. `accountant_code`, `class_code`, `package_code`, `is_serialized` from Spec 02 BR-I.13a/b), `offer_line_selections`, `offer_selection_events`, `offer_approvals`, `offer_versions`, `item_alternatives`; `OfferStatus` map, `OptionGroupKind` (fixed/optional/alternative), `AlternativeReason`, `SelectionChannel`, `StockBand`, `OfferRejectReason`; `OfferCalculator`, `OfferValidator` (V1–V9), `DiscountRules` (config/offers.php limits, `offers.approve`), Actions for every change, `offers:expire` (hourly), `OfferToOrderPayload` (Spec 06 hand-off contract); screens `/offers`, `/offers/create`, `/offers/{offer}`, `/offers/{offer}/edit`, `/offers/{offer}/print?lang=&selection=blank|current|selected`.
- Simplified: one approval step per submission (worst-case discount vs the owner's limit; payment-terms/manual-price/amount steps of 4.3 not yet); revisions keep snapshotted prices (no re-pricing diff, BR-21); evidence is a text description (attachments are Spec 03); sections (7.2), comparison-attribute tables, drag-and-drop ordering, `ExtendOfferValidity`, the item "Alternatives" management tab and the suggestions drawer's "same category" tab are not built (linked analogues are suggested first inside an alternative group).
- Out of scope (roadmap): portal, public link/OTP, notifications/e-mail/Telegram delivery (`OfferDelivery` is a stub), orders, leads, drift listeners, analytics.
- Serial-tracked items (Spec 02 BR-I.13b): offer quantities must be whole; the builder shows "serial numbers are required at delivery". The serial registry and serial entry belong to Spec 06.

---

## 1. Problem

1. Offers are the money document of the chain **Items → Lead → Offer → Order → Agreement**. Today they are Excel/Word files: prices typed by hand, discounts unmeasured, no approval, no version history, no proof of what the customer accepted.
2. **New requirement from the business**: many offers must present **alternative items** for the same requirement (brand A vs brand B, in-stock analogue vs original with 6-week lead time, standard vs upgraded model). In Excel these are listed as ordinary lines, so:
   - customers (and our own staff) **add all alternatives into the total**, so the offer looks 2–3x more expensive than it is;
   - the customer answers "we take option 2" by phone, nobody records which line that was, and the order is keyed with the wrong item;
   - unchosen alternatives end up in the order or in 1C, or the chosen one is forgotten;
   - nobody knows which alternatives win, so purchasing cannot plan stock of analogues.
3. Customers also want **optional add-ons** (installation, extended warranty, spare parts kit) that they may include or not, with the same total confusion.

## 2. Goals / Non-goals

### Goals
- G1. Offer lifecycle with explicit state machine, approvals by discount/role, validity, numbering, versions and full audit (who/when/how).
- G2. **Alternatives as a first-class concept**: an offer contains fixed (mandatory) lines, **optional** add-on lines and **alternative groups** (2+ options for one requirement, choose exactly one by default; min/max configurable). Every customer-facing surface (portal, public link, print/PDF, email) marks them unmistakably, also in black-and-white print.
- G3. Totals that **never double count**: fixed part + selected options; a min–max range while choices are pending; recomputed server-side on every change.
- G4. Customer choice through the portal (Spec 03), a public signed link with OTP, or recorded by the sales rep (phone, paper, e-mail, e-sign) with channel and evidence.
- G5. Acceptance blocked until every required group is satisfied; on conversion only fixed + selected lines are snapshotted into the order; unselected options stay in offer history.
- G6. Suggestions of alternatives from the catalog (`item_alternatives`, CRM-local, never altering 1C-mastered items) and same-category analogues.
- G7. Revisions carry selections over when the option still exists; old versions are superseded but retained.
- G8. Analytics: which alternative wins, recommended-option acceptance, add-on attach rate, conversion of offers with vs without alternatives.

### Non-goals (this spec)
- Order fulfilment, invoicing, payments, 1C order exchange, contracts (all Spec 06).
- Customer-editable quantities, customer-added lines, counter-offers inside the portal (customer comments instead; rep revises). See Q-2.
- Partial acceptance of fixed lines (use optional groups instead). See Q-3.
- Free-text (non-catalog) lines; bundles/kits; configurable products with attribute rules.
- Header-level (whole-offer) discount; FX conversion; multi-currency offers.
- Qualified e-signature integration (E-IMZO) — roadmap; v1 stores an uploaded signed file as evidence.
- Cost/margin (1C sends no cost price; Spec 02).
- Email open-tracking pixels (viewed = opened in portal/public link only).

## 3. Key decisions

| # | Decision | Alternatives rejected | Trade-off |
|---|---|---|---|
| DEC-1 | **One selection engine**: every selection-dependent line belongs to an `offer_option_groups` row with `kind` (`alternative` \| `optional`) and `min_select`/`max_select`. Alternative = 2..6 lines, default min 1 / max 1. Optional = 1..n lines, min 0 / max n (each line independently included). Fixed lines have no group | Flags on lines only (`is_optional`, `alternative_of_line_id`) | One validator/calculator for all cases; "choose 1 of 3", "choose up to 2", "pick any add-ons" are data, not code |
| DEC-2 | **Each version is its own `offers` row** (`root_offer_id`, `version`, `previous_version_id`), lines relational. `offer_versions` = the **immutable published snapshot** (canonical JSON + sha256) written when a version is sent; acceptance stores that hash | Single offer row + JSON history | Orders reference the exact version; analytics can query lines of any version; the hash proves what the customer saw (Spec 03 BR-P.4) |
| DEC-3 | Lines and groups carry a **`stable_key` (uuid) copied across versions**; selection carry-over and analytics lineage use it | Match by item_id | Same item may appear twice; reordering/relabelling must not lose the choice |
| DEC-4 | Selections are **current state** (`offer_line_selections`, one row per selected line) + **append-only** `offer_selection_events` (who/when/how/channel/IP) | Only activity_log | Fast totals and constraints from the state table; complete audit and analytics from the event table |
| DEC-5 | Optional lines are **opt-in** (not included until chosen). "Recommended" is a badge, never a silent pre-selection | Pre-selected opt-out add-ons | No total that silently includes something the customer did not choose; avoids disputes |
| DEC-6 | **Approval is evaluated on the worst case**: every line of every group (any option can be chosen) against discount limits; approval is bound to the offer `content_hash` and invalidated by any edit | Approve only the recommended combination | The customer may pick any option; approver sees all |
| DEC-7 | Prices are fixed at **send** for the validity period (the offer is a commitment). Price/stock changes from 1C while open only **warn** internally; stock bands are live for customers | Auto-reprice open offers | Commercial certainty; drift handled by revision |
| DEC-8 | Public link = random token in URL (view only) + **6-digit OTP** to the addressed contact (email/Telegram, Spec 03 OTP service, purpose `offer_action`) to select/accept/reject | Signed URL alone; portal only | Customers without portal accounts can act; a forwarded link cannot accept |
| DEC-9 | Statuses extend the requested list with **`approved`** (approved, not yet sent) and **`cancelled`** (discarded/withdrawn by us) | Approval flag on draft | Explicit states; nothing "silently approved" |
| DEC-10 | Offer-level `prices_include_vat` (rev. 4: from `offers.prices_include_vat`, default net); VAT computed per line, rounded half-up per line, then summed | Per-line basis | One basis per document = one readable printout; Spec 02 R-I.7 |
| DEC-11 | `item_alternatives` is a **CRM-local** table (source `crm`; `onec` reserved for a future 1C "analogues" feed, read-only); items themselves stay read-only | Columns on `items` | Respects Spec 02 BR-I.2 |

## 4. Users, roles and permissions

Abilities in `config/permissions.php` (Spec 03 `PermissionMap`); Policies (`OfferPolicy`, `ItemAlternativePolicy`, `PortalOfferPolicy`) call it. Admin = system administration only (Spec 03 DEC-10).

### 4.1 Internal matrix
| Action | sales | sales dept head | manager | finance | admin |
|---|---|---|---|---|---|
| View offers | own + own customers' | department | all | all (read-only) | all (read-only) |
| Create offer (from lead, customer, portal request) | yes (becomes owner) | yes | yes | no | no |
| Edit draft / add lines, groups, alternatives | own | department | all | no | no |
| Discount within own limit (4.3) | ≤ 5% | ≤ 10% | ≤ 25% | — | no |
| Submit for approval | own | department | all | no | no |
| Approve / return discount approval | no | ≤ 10% (not own) | ≤ 25% (not own) | co-approve payment-terms deviation | no |
| Above 25% discount | — | — | management dept head approves | — | no |
| Send / resend / extend validity (open, not expired) | own | department | all | no | no |
| Record customer selection / acceptance / rejection on behalf | own | department | all | no | no |
| Revise (new version) | own | department | all | no | no |
| Cancel / withdraw | own (draft, approved) | department | all (any non-final) | no | no |
| Reassign owner | no | department | all | no | no |
| Print / download PDF | as view | as view | yes | yes | yes |
| Manage public link (create/revoke) | own | department | all | no | no |
| Manage `item_alternatives` catalog links | propose (in offer, flagged) | yes | yes | no | no |
| View offer analytics | own | department | all | all | no |
| Settings > Offers (limits, validity, numbering) | no | no | no | view | yes (config only; values approved by management) |

"Own" = `owner_id` = user. A user never approves their own offer.

### 4.2 Portal (customer realm, Spec 03 4.8; own company only, uuid route keys, 404 on foreign)
| Action | portal_admin | buyer | approver | viewer | finance |
|---|---|---|---|---|---|
| View offers sent to own company (open + history) | yes | yes | yes | yes | yes |
| Save selections (partial) | yes | yes | yes | no | no |
| Accept, selected gross ≤ company threshold | yes | yes | yes | no | no |
| Accept, above threshold (up to own `approval_limit`) | yes | no | yes | no | no |
| Reject with reason / comment to manager | yes | yes | yes | no | comment only |
| Print / download (uz/ru/en) | yes | yes | yes | yes | yes |

Public link (contact without portal account): view with token; select/accept/reject after OTP to that contact. If the customer is `portal_enabled` and selected gross is above `portal_approval_threshold_minor`, acceptance via public link is refused with a message to use the portal.

### 4.3 Discount and approval rules (setting `offers.approval_rules`, defaults)
- Line discount = `discount_bp` plus any manual unit price below the item's sale price (converted to bp of the sale price; rev. 4). A line priced below its cost basis requires manager approval (`offers.approve_below_cost`) whatever its discount. Effective discount per line is compared, **across all lines including every alternative and optional line**.
- Limits: sales 500 bp, sales department head 1000 bp, manager 2500 bp, above → `management` department head.
- Additional triggers (each adds an approval step): (rev. 4: every quotable item has a sale price, so `price_source = manual` lines do not occur); `payment_terms_days` greater than customer default or prepayment lower than customer default → finance co-approval; worst-case gross (max of range) above `offers.manager_review_above_minor` (default 1 000 000 000 UZS-equivalent, compared only in base currency offers; other currencies use per-currency thresholds) → manager.
- Approval steps are stored in `offer_approvals`; all required steps must be approved; any "return" sends the offer back to `draft` with a mandatory comment.

## 5. User stories

Builder
- US-1 As a sales rep I create an offer from a qualified lead (or customer, or portal order request) and its customer, contact, currency and payment terms are prefilled.
- US-2 As a sales rep, on a line I click "Offer alternatives", the line becomes Option A of an alternative group and I see suggested analogues (catalog links first, then same category) with price, stock band and lead time; I add B and C in one click.
- US-3 As a sales rep I mark one option "Recommended", state why each alternative is offered (out of stock, cheaper analogue, upgrade...), and fill comparison rows (brand, specification, warranty, delivery, origin).
- US-4 As a sales rep I add optional add-ons (installation, extended warranty) that the customer may tick.
- US-5 As a sales rep I see the fixed total, the price range and the recommended total, and preview the offer exactly as the customer will see it (portal and print).
- US-6 As a sales rep I get a clear "approval required" reason when any option's discount exceeds my limit.

Customer
- US-7 As a customer buyer, in the portal I see a boxed "Alternative — choose one" group with radio buttons, compare options side by side, and watch the total update.
- US-8 As a customer I save my choices for some groups and come back later; a colleague (approver) completes and accepts.
- US-9 As a customer without a portal account I open the emailed link, confirm with a code and accept.
- US-10 As a customer I print the offer in Russian, tick options by hand, sign and send the scan.

Sales follow-up
- US-11 As a sales rep I record "customer chose 3B by phone" with channel, note and optional evidence; the customer sees it in the portal.
- US-12 As a sales rep I am notified (Telegram/email/in-app) when the customer makes or completes choices or accepts.
- US-13 As a sales rep I revise a sent offer; the customer's earlier choices carry over where the option still exists, and they are told what changed.
- US-14 As a sales rep I am warned when an option in my open offer goes out of stock or its 1C price changes.

Management
- US-15 As a manager I approve or return offers in the approvals inbox, seeing all options' discounts.
- US-16 As a manager/purchase I see win rates per alternative item and per reason, to plan stock of analogues.

## 6. Workflow impact

### 6.1 `OfferStatus` (backed string enum, `canTransitionTo()`, labels `offers.status.*`)
```
draft ──submit──► pending_approval ──approve──► approved ──send──► sent ──first customer view──► viewed
  │  ▲               │ return (comment)            │ edit → draft      │                            │
  │  └───────────────┘                             │                   └──── first selection ───────┴──► partially_selected
  │ send (no approval needed)                                                                             │ (more changes stay here)
  └──────────────────────────────────────────────────► sent                                                │
open = sent | viewed | partially_selected ── accept (all required groups satisfied) ──► accepted ──► (Spec 06 order)
                                          ── reject (reason) ──► rejected
                                          ── valid_until passed (scheduler) ──► expired ── extend validity ──► sent
                                          ── new version sent ──► superseded
draft | pending_approval | approved | open ── cancel/withdraw (reason) ──► cancelled
```

| From | To | Trigger / guard | Actor |
|---|---|---|---|
| draft | pending_approval | `SubmitOfferForApproval`; validation passes (6.3); approval required (4.3) | owner |
| draft | sent | `SendOffer`; validation passes; no approval required | owner |
| pending_approval | approved | all `offer_approvals` steps approved, `content_hash` unchanged | approvers |
| pending_approval | draft | any step returned (comment required) | approver |
| approved | sent | `SendOffer`; `content_hash` equals approved hash | owner |
| approved | draft | any edit (approval invalidated, logged) | owner |
| sent | viewed | first open by a portal user or public-link visitor (internal views never count) | system |
| sent, viewed | partially_selected | first selection saved by customer or recorded by rep | customer / owner |
| sent, viewed, partially_selected | accepted | `AcceptOffer`: not expired, all groups satisfy min/max, no selected/fixed line blocked (BR-15), actor allowed (4.2), lock + hash check | customer / owner on behalf |
| sent, viewed, partially_selected | rejected | reason code + text required | customer / owner on behalf |
| sent, viewed, partially_selected | expired | `offers:expire` scheduler, `valid_until` end of day Asia/Tashkent passed; idempotent | system |
| expired | sent | `ExtendOfferValidity` (new date ≤ today + max validity) only if no line price differs from current resolver, else must revise; selections kept | owner, manager |
| sent, viewed, partially_selected, expired | superseded | the next version of the same root is **sent** (not when the draft is created) | system |
| draft, pending_approval, approved | cancelled | reason | owner, manager |
| sent, viewed, partially_selected | cancelled | withdraw, reason, customer notified | manager (or owner, setting) |

- Final statuses: accepted, rejected, superseded, cancelled. `expired` is final unless extended. A rejected version keeps `rejected` and gets `superseded_by_id` if revised (rejection reasons stay intact for analytics).
- At most **one open version per root** (enforced in `SendOffer` with a row lock on the root).
- Every transition: `status_transitions` row (from, to, user, reason, at; Spec 04) + `activity_log`. No status changes outside Actions.
- `selection_complete` (bool on offer, computed) is shown as badge "Ready to accept"; not a status.

### 6.2 Edits allowed per status
| Status | Allowed changes |
|---|---|
| draft | everything |
| pending_approval | nothing (withdraw to draft = return) |
| approved | any edit returns to draft |
| open (sent/viewed/partially_selected) | selections, accept/reject, extend validity, owner reassign, internal notes, public link management. Everything else via **Revise** |
| final | internal notes only |

### 6.3 Validation before submit/send (`OfferValidator`, returns codes for UI)
- V1 ≥ 1 fixed line or ≥ 1 alternative group (an offer of only optional lines is invalid).
- V2 Alternative group: 2..6 lines (`offers.max_options_per_group`, default 6); optional group ≥ 1 line.
- V3 `0 ≤ min_select ≤ max_select ≤ lines count`; alternative: `max_select ≥ 1`.
- V4 At most one `is_recommended` per alternative group; warning (not blocker) if none.
- V5 All lines: active item, not "Deleted in 1C", qty > 0 respecting unit precision, `min_order_qty`, `order_step`; currency = offer currency.
- V6 Each alternative line has a customer-visible difference (`comparison_attributes` non-empty or `comparison_notes`) — warning.
- V7 Duplicate item inside one group — blocker.
- V8 `valid_until` ≥ today and ≤ today + `offers.max_validity_days` (default 90).
- V9 Contact has an email or a linked portal user (needed to deliver).

### 6.4 Handoffs
- **Lead → Offer** (Spec 04): `lead_id`, customer, contact, owner, currency, needs/interests → draft lines (fixed). Accepting any version marks the lead per Spec 04 (default: `converted`). A lead may have several offers (competing roots); accepting one does not cancel others automatically — the rep is prompted to cancel them (Q-12).
- **Portal order request → Offer** (Spec 03): `offers.order_request_id` unique; request lines become fixed lines; request status → `converted`.
- **Offer → Order** (Spec 06): `CreateOrderFromOffer` (idempotent, `orders.offer_id` unique) copies **fixed lines + selected lines only**, each with `offer_line_id`, `offer_option_group_id`, group title and option label (e.g. "3B") for traceability, plus the snapshot fields of 7.4. Unselected options are **not** copied. Order totals must equal the offer's selected totals to the minor unit (test).
- **Offer → Agreement** (Spec 06 Part B): through the order only.

## 7. Data model

Conventions from Spec 01/02/03: money `*_minor` unsigned bigint + `currency` char(3) on the row or parent; VAT `*_bp`; quantities `decimal(15,3)` handled as strings with BCMath; string enums; `uuid` route keys for portal/public; `restrictOnDelete`; soft deletes only on `offers` drafts (sent versions are never deleted).

### 7.1 `offers`
| Column | Type | Notes |
|---|---|---|
| id, uuid | bigint PK, uuid unique | uuid = portal/public route key |
| number | string(20) | `OF-YYYY-NNNNN` from `number_sequences` (Spec 04), shared by all versions of a root |
| version | unsigned smallint default 1 | unique (`number`, `version`) |
| root_offer_id | FK offers, nullable (null on v1), index | |
| previous_version_id | FK offers, nullable | |
| superseded_by_id | FK offers, nullable | |
| status | string(20), index | `OfferStatus` |
| lead_id | FK leads, nullable, index | Q-10 |
| order_request_id | FK order_requests, nullable, unique | Spec 03 |
| customer_id | FK customers, index | must not be "Deleted in 1C" at send |
| contact_id | FK contacts, nullable | addressee |
| owner_id | FK users, index | |
| currency | char(3) | |
| prices_include_vat | boolean | DEC-10 |
| valid_until | date | inclusive, Asia/Tashkent |
| payment_terms_days | unsigned smallint, nullable | |
| prepayment_bp | unsigned smallint, nullable | |
| payment_terms_text, delivery_terms_text, warranty_text | text, nullable | customer-visible, entered once in any language (like Spec 03 Q-15); only labels are translated |
| intro_text, customer_notes | text, nullable | customer-visible |
| internal_notes | text, nullable | never printed or shown in portal |
| document_locale | string(2), nullable | default print language override; else customer `preferred_locale` |
| totals: `fixed_net_minor`, `fixed_vat_minor`, `fixed_gross_minor` | unsigned bigint | fixed lines only |
| totals: `selected_net_minor`, `selected_vat_minor`, `selected_gross_minor` | unsigned bigint | fixed + currently selected lines |
| totals: `range_min_gross_minor`, `range_max_gross_minor` | unsigned bigint | 8.3; equal to selected when complete |
| `recommended_gross_minor` | unsigned bigint, nullable | fixed + recommended picks, null if a required group has no recommended line |
| `pipeline_value_minor` | unsigned bigint | selected if complete, else recommended, else range min (Q-18) |
| max_discount_bp | unsigned smallint | worst case across all lines (approval) |
| selection_complete | boolean default false | |
| selection_revision | unsigned int default 0 | optimistic lock for concurrent selection saves |
| content_hash | char(64), nullable | sha256 of canonical content (header + groups + lines, excl. selections) |
| approved_hash | char(64), nullable | hash at approval |
| sent_at, sent_by, first_viewed_at | timestamps / FK | |
| accepted_at, accepted_by_user_id (portal or internal), accepted_by_contact_id (public link), accepted_via, accepted_on_behalf_by (internal recorder), acceptance_evidence_id (attachment), acceptance_note, acceptance_ip, acceptance_user_agent, accepted_hash, accepted_selection json | | 9.4 |
| rejected_at, reject_reason_code, reject_reason_text, rejected_by_* | | `OfferRejectReason`: price, lead_time, chose_competitor, no_budget, project_cancelled, specification_mismatch, other |
| expired_at, cancelled_at, cancel_reason | | |
| deleted_at | timestamp, nullable | drafts never sent only |

Indexes: (`customer_id`, `status`), (`owner_id`, `status`), (`status`, `valid_until`), (`root_offer_id`, `version`), (`lead_id`).

### 7.2 `offer_sections` (optional grouping headings)
`offer_id` FK cascade, `stable_key` uuid, `position` unsigned smallint, `title` string(255), `description` text null. Lines/groups without a section render under no heading. Index (`offer_id`, `position`).

### 7.3 `offer_option_groups`
| Column | Type | Notes |
|---|---|---|
| offer_id | FK offers cascade, index | |
| section_id | FK offer_sections, nullable, nullOnDelete | |
| stable_key | uuid, index | DEC-3 |
| position | unsigned smallint | shares the position sequence with fixed lines inside the section (one ordering) |
| kind | string(12) | `OptionGroupKind`: `alternative`, `optional` |
| title | string(255) | the requirement, customer-visible ("Air compressor 7.5 kW") |
| description | text, nullable | |
| min_select | unsigned tinyint | default alternative 1, optional 0 |
| max_select | unsigned tinyint | default alternative 1, optional = lines count |
| comparison_rows | json, nullable | ordered row keys shown in comparison (`brand`, `model`, `specification`, `warranty`, `lead_time`, `delivery`, `origin`, `custom:<label>`) |

### 7.4 `offer_lines`
| Column | Type | Notes |
|---|---|---|
| offer_id | FK offers cascade, index | |
| section_id | FK offer_sections, nullable | |
| option_group_id | FK offer_option_groups, nullable, cascadeOnDelete, index | null = fixed line; group must belong to the same offer (app-enforced, test) |
| stable_key | uuid, index | DEC-3 |
| position | unsigned smallint | fixed: position in section; grouped: order inside group |
| option_label | char(1), nullable | `A`..`F`, auto from order in group, **frozen at send** (customers refer to "3B" by phone) |
| is_recommended | boolean default false | alternative groups only |
| item_id | FK items, restrictOnDelete, index | catalog only (Q-11) |
| requested_item_id | FK items, nullable | what the lead/request asked for when this line substitutes it |
| snapshot | `item_external_id`, `sku`, `article`, `name`, `description`, `unit_id`, `unit_code`, `unit_name`, `unit_precision`, `tax_rate_bp`, `is_vat_exempt`, `price_source` (`item_sale_price` \| `manual`), `list_unit_price_minor` (nullable), `cost_price_minor` (rev. 4, nullable) | Spec 01 §5 + Spec 02 handoff |
| quantity | decimal(15,3) | may differ between alternatives (Q-4) |
| unit_price_minor | unsigned bigint | in offer basis (net or gross per `offers.prices_include_vat`) |
| discount_bp | unsigned smallint default 0 | 0..10000 |
| effective_discount_bp | unsigned smallint | vs list price (4.3) |
| line_net_minor, line_vat_minor, line_gross_minor, line_discount_minor | unsigned bigint | 8.2 |
| lead_time_days | unsigned smallint, nullable | customer-visible |
| stock_band | string(12), nullable | `in_stock` \| `limited` \| `on_request` \| `out_of_stock`, snapshot at send |
| stock_available_qty | decimal(15,3), nullable | internal only; snapshot at send |
| stock_checked_at | timestamp, nullable | |
| stock_warning_at, price_warning_at | timestamp, nullable | set by watchers (BR-14) |
| alternative_reason | string(20), nullable | `AlternativeReason`: `original_out_of_stock`, `cheaper_analogue`, `upgrade`, `faster_delivery`, `brand_choice`, `customer_request`, `other`; customer-visible label |
| alternative_reason_note | string(255), nullable | customer-visible |
| comparison_attributes | json, nullable | `{ "brand": "Atlas Copco", "warranty": "24 months", ... }` keyed by group `comparison_rows` |
| comparison_notes | text, nullable | customer-visible free text ("Higher efficiency, IE3 motor") |
| internal_note | text, nullable | never customer-visible |

Indexes: (`offer_id`, `section_id`, `position`), (`option_group_id`, `position`), (`item_id`), (`stable_key`). Unique (`option_group_id`, `item_id`) app-enforced (NULL group).

### 7.5 `offer_line_selections` (current state)
`offer_id` FK cascade, `option_group_id` FK cascade, `offer_line_id` FK cascade **unique**, `selected_by_actor` (`portal` \| `public_link` \| `internal` \| `carry_over`), `selected_by_user_id` null, `selected_by_contact_id` null, `channel` (`portal`, `public_link`, `phone`, `email`, `messenger`, `meeting`, `paper`, `e_sign`), `selected_at`. Index (`offer_id`, `option_group_id`). Count per group ≤ `max_select` enforced in the action under `lockForUpdate` on the offer row.

### 7.6 `offer_selection_events` (append-only)
`offer_id`, `option_group_id`, `offer_line_id` null, `event` (`selected`, `deselected`, `group_cleared`, `carried_over`, `carry_over_dropped`, `accepted_with`), `actor`, `user_id` null, `contact_id` null, `channel`, `note` text null, `evidence_attachment_id` null, `selection_revision`, `ip`, `user_agent`, `created_at`. Index (`offer_id`, `created_at`), (`offer_line_id`).

### 7.7 `offer_approvals`
`offer_id`, `step` (`discount_head`, `discount_manager`, `discount_management`, `manual_price`, `payment_terms_finance`, `amount_manager`), `required_reason` json (lines and values that triggered), `status` (`pending`, `approved`, `returned`), `decided_by`, `decided_at`, `comment`, `content_hash`. Unique (`offer_id`, `step`, `content_hash`).

### 7.8 `offer_versions` (immutable published snapshot)
`offer_id` FK unique, `root_offer_id`, `version`, `content_hash` char(64), `payload` json (canonical: header, sections, groups, lines, labels, terms — everything the customer sees, no internal fields), `sent_at`, `sent_by`, `channels` json (email, portal, telegram, link), `document_locale`, `pdf_attachment_id` null. Never updated.

### 7.9 `offer_share_links` (public link, DEC-8)
`offer_id`, `contact_id`, `token_hash` char(64) unique, `created_by`, `expires_at` (default `valid_until` + 30 days), `revoked_at`, `last_used_at`, `use_count`, `max_views` (default setting `offers.public_link_max_views` = 50). Token: at least 128 bits from a CSPRNG (`random_bytes(32)` base64url = 256 bits, never `Str::random` of fewer than 22 chars), shown only in the sent link; only `hash('sha256', token)` stored, looked up by hash (security audit). When `use_count` reaches `max_views` the link shows the generic "link expired, ask your manager for a new one" page (staff can reissue). Superseded/cancelled version → link shows "replaced/withdrawn" page with the new version link if the same contact.

### 7.10 `item_alternatives` (catalog analogue links, CRM-local)
| Column | Type | Notes |
|---|---|---|
| item_id | FK items cascade | |
| alternative_item_id | FK items cascade, index | ≠ item_id |
| relation | string(12) | `ItemAlternativeRelation`: `analogue` (equivalent), `upgrade`, `budget`, `substitute` |
| is_mutual | boolean default true | when true the action writes both directions (inverse relation: upgrade ↔ budget, analogue ↔ analogue) |
| default_reason | string(20), nullable | prefills `alternative_reason` |
| comparison_attributes | json, nullable | prefills lines |
| note | text, nullable | internal |
| source | string(8) default `crm` | `onec` rows read-only (future feed) |
| status | string(10) default `approved` | `proposed` when created by sales from an offer (needs head/manager approval to appear for others) |
| created_by, approved_by | FK users | |

Unique (`item_id`, `alternative_item_id`). Audit via `LogsActivity`.

### 7.11 Settings / config
`offers.default_validity_days` 14, `offers.max_validity_days` 90, `offers.max_options_per_group` 6, `offers.approval_rules` (4.3), `offers.manager_review_above_minor` per currency, `offers.stock_band_thresholds`, `offers.stock_warehouse_ids` (null = all), `offers.public_links_enabled` true, `offers.selection_notify_debounce_minutes` 10, `offers.evidence_required_channels` [`paper`, `e_sign`], `offers.withdraw_by_owner` false.

### 7.12 Migration order
(after Spec 04 `number_sequences`, `status_transitions`, `leads`; Spec 03 `attachments`, `order_requests`) → `item_alternatives` → `offers` → `offer_sections` → `offer_option_groups` → `offer_lines` → `offer_line_selections` → `offer_selection_events` → `offer_approvals` → `offer_versions` → `offer_share_links`. Greenfield; all reversible. If `order_requests` is not built yet, the column is added without FK and the FK in Spec 03 P-3.

## 8. Business rules — calculation

All in `App\Services\Offers\OfferCalculator` (pure, BCMath, no DB), called by every mutating Action; results persisted on lines/offer. Client-side totals are display only and never trusted.

- BR-1 Line amount: `base = round_half_up(qty × unit_price_minor)`; `line_discount = round_half_up(base × discount_bp / 10000)`; `amount = base − line_discount`.
  - Net basis (`prices_include_vat = false`): `net = amount`, `vat = round_half_up(net × tax_rate_bp / 10000)`, `gross = net + vat`.
  - Gross basis: `gross = amount`, `vat = round_half_up(gross × tax_rate_bp / (10000 + tax_rate_bp))`, `net = gross − vat`.
  - VAT-exempt item: `tax_rate_bp = 0`, printed "VAT exempt".
- BR-2 An item sale price whose `prices_include_vat` differs from the offer basis is converted once at line creation (rounded half-up to minor) and `price_source` kept; the conversion is shown in the line tooltip.
- BR-3 **Fixed totals** F = Σ fixed lines (net, vat, gross separately).
- BR-4 **Selected totals** = F + Σ selected lines. Never Σ all lines. Totals of an offer with unselected required groups are labelled "incomplete" everywhere.
- BR-5 **Range** ("possible totals given current choices", gross; same formula for both kinds). For group g with selected set S (count c) and unselected lines R:
  - `min_g = Σ S + Σ cheapest (min_select − c) lines of R` if c < min_select, else `Σ S`;
  - `max_g = Σ S + Σ most expensive (max_select − c) lines of R` if c < max_select, else `Σ S`.
  Range = [F + Σ min_g, F + Σ max_g]. The UI/print shows the range only while min ≠ max. `selection_complete` = every group has `min_select ≤ c ≤ max_select` (an unanswered optional group is complete).
- BR-6 Recommended total = F + recommended line of each alternative group (+ nothing for optional groups); null if a group with `min_select ≥ 1` has no recommended line.
- BR-7 VAT summary (print/portal) groups **selected** lines (or, for blank forms, fixed lines only) by `tax_rate_bp`.
- BR-8 Rounding: per line, then sum (Spec 01). Order lines copy line amounts verbatim (no recomputation) so order totals equal offer selected totals.
- BR-9 All lines share the offer currency; amounts in words only for the selected total when complete (otherwise omitted, never for a range).

## 9. Business rules — choice, acceptance, revisions, drift

### 9.1 Selection
- BR-10 Selection allowed only in open statuses and before `valid_until` end of day. Saving is per change (portal) or per batch (rep form); each save: lock offer row, check `selection_revision` equals the client's (else "This offer was updated by <name> at <time>; review and try again"), validate `count ≤ max_select` per group (min is checked only at acceptance), upsert `offer_line_selections`, append events, recompute totals and `selection_complete`, increment `selection_revision`, move status to `partially_selected` if `sent`/`viewed`.
- BR-11 For `max_select = 1` choosing another option replaces the previous one (event `deselected` + `selected`). Optional lines toggle independently.
- BR-12 Rep recording on behalf: channel required; note required for `phone`/`messenger`/`meeting`/`email`; evidence attachment required for channels in `offers.evidence_required_channels`. The customer sees in the portal "Recorded by <rep> on <date> (phone)".
- BR-13 Notifications: owner gets `offer.selection_changed` debounced (one message per offer per 10 min), `offer.selection_completed` immediately, `offer.accepted`/`offer.rejected` immediately (also owner's department head and manager on accepted above `manager_review_above_minor`). The customer actor gets a confirmation e-mail with the PDF (selected view) in their language. Channels and quiet hours per Spec 03 BR-A.6. Templates in `lang/*/notifications.php` / `telegram.php`.

### 9.2 Acceptance
- BR-14 `AcceptOffer` in one transaction with `lockForUpdate`: status open; not expired (server time Asia/Tashkent); every group satisfies `min_select ≤ count ≤ max_select`; no blocked line (BR-15); actor permitted (4.2, threshold on **selected gross**); `offer_versions.content_hash` equals the hash the client loaded (else reload). Stores accepted_* fields, `accepted_hash`, `accepted_selection` json (group stable_key → line stable_keys + labels), event `accepted_with`, transition, then dispatches `CreateOrderFromOffer` (Spec 06; idempotent). Portal acceptance above threshold or session older than 15 min requires fresh OTP (Spec 03 §11).
- BR-15 Blocked lines: a fixed or selected line whose item is "Deleted in 1C" or merged blocks acceptance (message: "Item no longer available, your manager will update the offer"; owner notified). Inactive items or out-of-stock only warn.
- BR-16 After acceptance, selections are frozen. Changes go through order amendment (Spec 06).
- BR-17 Rejection keeps selections (analytics: what they leaned to).

### 9.3 Revision and carry-over
- BR-18 `ReviseOffer` (from open, expired or rejected) creates a draft copy: new row, `version + 1`, same `number`, `root_offer_id`, copied sections/groups/lines with the same `stable_key`s, prices **re-resolved** (diff shown to the rep, rep may keep old prices — each kept price is a manual price subject to approval rules), status of previous untouched until the new version is **sent**.
- BR-19 On send of the new version, previous open/expired version → `superseded` (`superseded_by_id`), share links redirect, and **selections carry over**: for each selected line of the previous version, if a line with the same `stable_key` exists in a group with the same `stable_key` in the new version and the item is unchanged, it is selected (event `carried_over`, actor `carry_over`); otherwise dropped (`carry_over_dropped`). After carry-over, a group exceeding `max_select` is cleared. Price/qty changed on a carried line → the line shows "changed since your choice" (old vs new) in the portal and acceptance shows the diff list.
- BR-20 The customer is notified of the new version with a summary: kept choices, dropped choices, changed prices/qty, added/removed options.

### 9.4 Prices, stock and 1C changes while open
- BR-21 Draft: banner "N lines differ from current prices" with one-click reprice per line or all (logged).
- BR-22 Open: prices are binding until `valid_until`. On a 1C item price change (Spec 02 BR-I.14) a listener flags `price_warning_at` on lines of open offers whose item sale price changed by more than 0 (internal badge, owner digest once per day). No customer-visible change.
- BR-23 Stock: on stock feed (Spec 02 I-4) a listener recomputes availability (Σ `quantity − reserved` over `offers.stock_warehouse_ids`) for items on open offers; if available < line qty → `stock_warning_at`, owner notified ("Option 3B is out of stock: consider a revision with an alternative"). Portal shows the **live stock band** (never quantities) next to each option with "Lead time will be confirmed by your manager" when band worsened. Acceptance is not blocked (Spec 06 confirms stock).
- BR-24 Extend validity of an expired offer (expired → sent) only if no line's current item sale price differs; else Revise.

### 9.5 Numbering, validity, misc
- BR-25 Number on first save of v1 (`OF-YYYY-NNNNN`, yearly reset, never reused); displayed `OF-2026-00042` for v1 and `OF-2026-00042/2` for later versions.
- BR-26 `valid_until` default today + 14 days; reminder to owner 3 days and 1 day before expiry for open offers; customer reminder 2 days before (if enabled).
- BR-27 Alternative suggestions (`SuggestAlternatives` query): (1) approved `item_alternatives` of the line's item (and of `requested_item_id`), (2) active items of the same category with a sale price in the offer currency, ranked by stock band then |price difference|, max 10, labelled "same category — check compatibility". Proposed (unapproved) links visible only to their creator and approvers.
- BR-28 Single substitute (customer asked for X, we only offer Y): a fixed line with `requested_item_id = X` and `alternative_reason = original_out_of_stock`, printed "Offered instead of: X". Not an alternative group (a group needs ≥ 2 options).

## 10. UI / UX

Internal (Livewire 4, `layouts.app`), portal (`layouts.portal`), public (`layouts.guest`-style minimal shell). One shared Blade component `<x-offers.customer-view :offer :mode>` (modes: `preview`, `portal`, `public`, `readonly`) renders the customer-facing structure so the builder preview is **exactly** what the customer sees; print uses its own document view with the same section/group order.

### 10.1 Offers index (`/offers`)
Columns: number/version, customer, status badge (`offers.status.*`, icon + text), "Ready to accept" badge, owner, selected total or range ("12.4–15.8 M UZS"), valid until (red text + "Expires in 2 days"), warnings icon (stock/price). Filters: status, owner, customer, has alternatives, expiring, needs my approval. Empty state with "Create offer".

### 10.2 Offer builder (`/offers/{offer}/edit`)
- Header: customer, contact, currency, VAT basis (read-only, from config), validity, payment/delivery/warranty terms, intro, notes (customer), internal notes, document language.
- Body: sections (collapsible), each with an ordered list of **blocks**: fixed line rows, **alternative group cards** (bordered, header "ALTERNATIVE — customer chooses 1", title input, options A/B/C as rows), **optional group cards** ("OPTIONAL — customer may add").
- Toolbar per section: "Add item", "Add alternative group", "Add optional items". On a fixed line menu: "Offer alternatives" (converts to group, line becomes A, opens suggestions drawer), "Make optional". On a group: "Ungroup" (only when 1 line left → fixed line), min/max (advanced popover, defaults shown), recommended radio, reason select + note per option, comparison rows editor (row keys chosen once per group; values per option, prefilled from `item_alternatives`), drag handles (`wire:sort`) for blocks, options and sections **plus** keyboard-accessible move up/down buttons.
- Suggestions drawer: tabs "Linked alternatives" / "Same category"; each with SKU, name, price for this customer, stock band, lead time, relation badge; "Add as option"; checkbox "Save as catalog alternative" (creates `proposed` link for sales, `approved` for heads/managers).
- Right panel (sticky): Fixed total, per group "n options, choose 1", **Range** (min–max), **Recommended total**, max discount and "Approval required: reasons", validation list (V-codes, blockers red with text, warnings silver with text), stock/price warnings.
- Tabs: Edit | Customer preview (shared component, interactive radios with a "preview — not saved" banner) | Print preview (language picker, blank/selected modes) | Approvals | Selections log | Versions (diff between versions: added/removed/changed lines and groups) | Activity.
- Open offers: read-only body + actions: Record customer choice (modal mirrors portal radios + channel, note, evidence), Accept on behalf, Reject on behalf, Extend validity, Revise, Withdraw, Share link (create/copy/revoke), Resend.

### 10.3 Portal offer screen (`/portal/offers/{uuid}`) — mobile-first
- Header: number/version, valid until (countdown when ≤ 3 days), status, "Choices left: 2 of 3" progress.
- Fixed lines table (cards on mobile).
- Alternative group: bordered box with heading badge **"Alternative — choose one"** (icon + text; for max > 1 "choose up to N"), requirement title, option cards with **large letter A/B/C**, name, SKU, qty + unit (shown prominently when quantities differ), unit price, line total incl. VAT, lead time, stock band (icon + text), "Recommended" badge (star icon + word), reason text; radio buttons (checkboxes when max > 1). "Compare" toggle → side-by-side table (rows = comparison rows + price, qty, lead time, warranty, stock; columns = options); differing cells marked with "≠" and bold, not color only.
- Optional group: box "Optional — tick to add" with checkboxes.
- Sticky summary (bottom sheet on mobile): fixed part, each group's choice or "not chosen", **Total of selected options** (net, VAT, gross), **Price range** while incomplete with text "Depends on your choices", note "Alternative prices are not added together".
- Actions: Save choices (also autosave on change), Accept (disabled with list of missing choices), Reject (reason), Ask a question (comment to owner, Spec 03 notification), Download/print (language picker uz/ru/en; blank form or with my choices).
- Accept dialog: summary of choices with letters, total, terms, checkbox "I confirm I am authorised to accept on behalf of <company>", OTP if required.
- After a new version: banner "Offer updated to version 2" with change summary (BR-20).

### 10.4 Public link (`/o/{token}`)
Same component in `public` mode; actions require "Get code" → OTP to the addressed contact (email, or Telegram if the contact's portal user has it linked) → 30-minute action session bound to token + contact. Rate limits per Spec 03 4.3. `noindex`, no internal data, no navigation.
Security (audit): every `/o/*` response (page, print, Livewire updates, errors) sends `Referrer-Policy: no-referrer` (the token must not leak to third parties via Referer), `Cache-Control: no-store, private` and `X-Robots-Tag: noindex, nofollow`; no third-party assets on these pages. Per-token view counter (`use_count`, `max_views`) plus a per-IP throttle on token lookups (unknown tokens count too, generic 404). The client only sends **choices** (selected line stable keys per group); the server recomputes every price, discount, tax and total from the published `offer_versions` snapshot and ignores any amount, quantity or price in the request. Test: tampered amounts in a Livewire payload change nothing.

### 10.5 Item show → "Alternatives" tab
List of linked alternatives (relation, mutual, status, source), add/remove/approve (policy), win-rate mini stats (section 13).

### 10.6 Navigation (`config/navigation.php`)
- Sales section: `['label' => 'nav.offers', 'icon' => 'offers', 'route' => 'offers.index', 'active' => 'offers.*', 'can' => ['viewAny', Offer::class]]` (replaces `soon`).
- Settings children: `['label' => 'nav.settings_offers', 'route' => 'settings.offers']`.
- Spec 03 approvals inbox (`nav.approvals`) lists offer approvals.
- `portal_sections`: `['label' => 'nav.portal_offers', 'route' => 'portal.offers.index', 'active' => 'portal.offers.*']`.

### 10.7 Empty/error states
No price for item in currency → line add blocked with "No price in <currency> for this customer"; managers may enter a manual price (approval). Stale prices feed (Spec 02 staleness) → banner in builder. Concurrent selection conflict → message + reload. Expired while customer on page → actions disabled with "This offer expired on <date>; contact <owner>".

## 11. Printing / PDF

- Routes: internal `GET /offers/{offer}/print`, portal `GET /portal/offers/{uuid}/print`, public `GET /o/{token}/print`; each resolves `DocumentLanguage::resolve($request->query('lang'), $offer->customer)` (offer `document_locale` used as the subject preference when set) and returns `DocumentRenderer::print('documents.offer', [...], $language, __('documents.offer.title'), $backUrl)` with `<x-print-language-picker>`. Query `selection=blank|current|selected` (default: `current` if any selection else `blank`; `selected` prints fixed + selected only, used as acceptance confirmation and order basis).
- View `resources/views/documents/offer.blade.php` follows `documents/sample.blade.php`: labels only from `documents.*`, dates/numbers/money/amount in words only via `$doc`. Data passed as a DTO built from the `offer_versions.payload` (for sent versions) so the print equals what was sent; internal fields are not in the DTO (test asserts absence).
- Layout rules (must be understandable in **black-and-white**; no color-only cues):
  - Header: number + version, date, valid until, seller/buyer blocks, contact.
  - Fixed lines: numbered rows 1, 2, ...
  - **Alternative group**: one numbered position (e.g. "3") in a **double-bordered box** with heading in capitals "ALTERNATIVE — PLEASE CHOOSE ONE" (or "choose :min to :max"), requirement title, and option rows "3A", "3B", "3C" each starting with an empty **tick box** (CSS bordered square, not a font glyph) — or a ticked box with the word "Selected" when printing a current selection; "Recommended" printed as word + ★; reason and comparison notes in a sub-row; comparison table (attributes × options) below when rows exist. The amount column of options is printed in a lighter-weight "option price" column and **excluded from the subtotal** with the footnote "Option prices are not added together. Only the chosen option is included in the total."
  - **Optional group**: single-bordered box, heading "OPTIONAL — TICK TO INCLUDE", rows with tick boxes.
  - Groups use `print-avoid-break`; groups longer than a page repeat the heading with "(continued)".
  - Totals block: "Total of fixed items"; then for incomplete selection: "Price range depending on your choice: from X to Y", "Total with recommended options: Z" (if available), and a blank line "Total of selected options: ________"; for complete selection: "Total of selected options" with VAT summary by rate, "Total incl. VAT" and amount in words.
  - "Customer selection" block (blank/current modes): "We choose the options marked above" + Company, Full name, Position, Signature, Date, Seal lines.
  - Terms, validity sentence, prepared by, seller signature (as sample).
- Keys to add in `lang/{uz,ru,en}/documents.php` under `offer.` (suggested texts; final wording by translators, parity test enforces all three):

| Key | en | ru | uz |
|---|---|---|---|
| `offer.version` | Version :n | Версия :n | :n-versiya |
| `offer.alternative` | Alternative | Альтернатива | Muqobil variant |
| `offer.alternative_heading` | ALTERNATIVE — PLEASE CHOOSE ONE | АЛЬТЕРНАТИВА — ВЫБЕРИТЕ ОДИН ВАРИАНТ | MUQOBIL VARIANTLAR — BITTASINI TANLANG |
| `offer.alternative_heading_range` | ALTERNATIVE — CHOOSE :min TO :max | АЛЬТЕРНАТИВА — ВЫБЕРИТЕ ОТ :min ДО :max | MUQOBIL VARIANTLAR — :min TADAN :max TAGACHA TANLANG |
| `offer.option` | Option :label | Вариант :label | :label-variant |
| `offer.choose_one` | Choose one | Выберите один | Bittasini tanlang |
| `offer.recommended` | Recommended | Рекомендуем | Tavsiya etiladi |
| `offer.optional` | Optional | Опционально | Ixtiyoriy |
| `offer.optional_heading` | OPTIONAL — TICK TO INCLUDE | ОПЦИОНАЛЬНО — ОТМЕТЬТЕ, ЧТОБЫ ВКЛЮЧИТЬ | IXTIYORIY — QO‘SHISH UCHUN BELGILANG |
| `offer.selected` / `offer.not_selected` | Selected / Not selected | Выбрано / Не выбрано | Tanlangan / Tanlanmagan |
| `offer.option_price` | Option price | Цена варианта | Variant narxi |
| `offer.not_added_note` | Option prices are not added together. Only the chosen option is included in the total. | Цены вариантов не суммируются. В итог включается только выбранный вариант. | Variantlar narxlari qo‘shilmaydi. Jami summaga faqat tanlangan variant kiradi. |
| `offer.total_fixed` | Total of fixed items | Итого по обязательным позициям | Majburiy pozitsiyalar jami |
| `offer.subtotal_selected` | Total of selected options | Итого по выбранным вариантам | Tanlangan variantlar jami |
| `offer.price_range` | Price range depending on your choice: from :min to :max | Диапазон цены в зависимости от выбора: от :min до :max | Tanlovingizga qarab narx oralig‘i: :min dan :max gacha |
| `offer.total_recommended` | Total with recommended options | Итого с рекомендуемыми вариантами | Tavsiya etilgan variantlar bilan jami |
| `offer.customer_selection` | Customer selection | Выбор заказчика | Buyurtmachi tanlovi |
| `offer.selection_instruction` | We choose the options marked above. | Мы выбираем варианты, отмеченные выше. | Yuqorida belgilangan variantlarni tanlaymiz. |
| `offer.offered_instead_of` | Offered instead of: :name | Предлагается вместо: :name | :name o‘rniga taklif etiladi |
| `offer.lead_time_days` | Lead time: :days days | Срок поставки: :days дн. | Yetkazib berish muddati: :days kun |
| `offer.continued` | (continued) | (продолжение) | (davomi) |
| `offer.comparison` | Comparison of options | Сравнение вариантов | Variantlarni taqqoslash |
| `offer.vat_exempt` | VAT exempt | Без НДС | QQSsiz |
| `offer.stock.{in_stock,limited,on_request,out_of_stock}` | In stock / Limited stock / On request / Out of stock | В наличии / Ограниченно / Под заказ / Нет в наличии | Mavjud / Cheklangan / Buyurtma asosida / Mavjud emas |
| `offer.reasons.{original_out_of_stock,cheaper_analogue,upgrade,faster_delivery,brand_choice,customer_request,other}` | Original item out of stock / Cheaper analogue / Upgrade / Faster delivery / Choice of brand / At your request / Other | Оригинал отсутствует / Более доступный аналог / Улучшенная версия / Быстрее поставка / Выбор бренда / По вашему запросу / Другое | Asl mahsulot mavjud emas / Arzonroq analog / Yaxshilangan variant / Tezroq yetkazish / Brend tanlovi / Sizning so‘rovingiz bo‘yicha / Boshqa |
| `offer.attributes.{brand,model,specification,warranty,lead_time,delivery,origin}` | Brand / Model / Specification / Warranty / Lead time / Delivery / Country of origin | Бренд / Модель / Характеристики / Гарантия / Срок поставки / Доставка / Страна происхождения | Brend / Model / Tavsif / Kafolat / Yetkazish muddati / Yetkazib berish / Ishlab chiqarilgan mamlakat |

- UI strings in new `lang/{uz,ru,en}/offers.php` (statuses, kinds, reasons, actions, validation V-codes, builder labels, flash messages, notifications) and `nav.php` (`offers` exists; add `settings_offers`, `portal_offers`). No literal strings; every enum (`OfferStatus`, `OptionGroupKind`, `AlternativeReason`, `ItemAlternativeRelation`, `OfferRejectReason`, `SelectionChannel`, `StockBand`) has `label()` via keys (parity test).
- Customer must implement `HasDocumentLanguage` (Spec 01/03 `preferred_locale`).

## 12. Acceptance criteria

Structure and validation
- AC-1 Given a draft with an alternative group of 1 line, when sending, then blocked with V2; adding a second option clears it.
- AC-2 Given a group with `min_select = 2`, `max_select = 1`, then validation fails (V3).
- AC-3 Given two options marked recommended in one group, then the second save fails (V4).
- AC-4 Given the same item twice in one group, then blocked (V7); the same item as a fixed line and an option in different groups is allowed.
- AC-5 Given "Offer alternatives" on a fixed line, then a group is created with that line as option A (same stable_key, same price), and "Ungroup" on a group with one remaining line restores a fixed line.

Totals (fixture, net basis, VAT 12%, UZS): fixed line 1 = 1 000.00 net; group 3 alternatives A = 500.00, B = 800.00 (recommended), C = 650.00; optional group 4: X = 100.00, Y = 50.00
- AC-6 With no selection: fixed gross 1 120.00; range gross [1 680.00, 2 184.00] (min = 1 000 + 500 → ×1.12; max = 1 000 + 800 + 100 + 50 → ×1.12); recommended gross 2 016.00; selected totals labelled incomplete; the sum of all lines (3 100.00 net) appears nowhere.
- AC-7 Selecting B then C leaves only C selected (max 1); selected net 1 650.00, gross 1 848.00; `selection_complete = true` (optional group min 0); range [1 848.00, 2 016.00] (C, optionally + X + Y). Then ticking X: selected gross 1 960.00, range [1 960.00, 2 016.00].
- AC-8 Given gross basis and a line 1 120.00 gross at 12%, then VAT = 120.00, net = 1 000.00; with qty 3 and discount 3.33% rounding is half-up per line and the offer total equals the sum of line totals.
- AC-9 Totals sent from the client (tampered Livewire property) are ignored; persisted totals equal the calculator output.

Approval
- AC-10 Given a sales user with a 5% limit, a recommended option at 0% and a non-recommended option at 8%, then the offer requires sales-head approval (worst case, DEC-6).
- AC-11 Given an approved offer, when the owner changes any option's qty, then status returns to draft, approval invalidated, logged.
- AC-12 A user cannot approve their own offer; admin cannot approve.

Selection and acceptance
- AC-13 Given a sent offer with two required groups and only one selected, when a portal buyer clicks Accept (UI or direct Livewire call), then it is refused with the list of missing groups and status stays `partially_selected`.
- AC-14 Given all groups selected and selected gross above the company threshold, a buyer cannot accept; an approver with sufficient limit can; accepted fields, hash, selection json and `accepted_with` events are stored.
- AC-15 Given two portal users save selections from the same `selection_revision`, then the second save gets the conflict message and nothing is overwritten.
- AC-16 Given a rep records "B by phone" without a note, then validation fails; with a note it is saved with actor internal, channel phone, and the portal shows "Recorded by …".
- AC-17 Given `valid_until` = today, at 23:59 Asia/Tashkent selection works; after the scheduler run at 00:00 status is `expired` and selection/accept are refused.
- AC-18 Given a selected option whose item becomes "Deleted in 1C", accept is blocked with BR-15 message and the owner is notified; an out-of-stock option only shows a warning.
- AC-19 Given an accepted offer, then `CreateOrderFromOffer` creates exactly one order (twice-called → one) containing fixed + selected lines only, each with `offer_line_id` and label "3C", and order totals equal offer selected totals to the minor unit; unselected A/B are absent from order lines but remain on the offer.

Portal / public link security
- AC-20 A portal user of company B requesting company A's offer page, print, selection or accept endpoint (uuid) gets 404 and no data (data provider over all offer routes and Livewire actions).
- AC-21 A portal `viewer` cannot save selections or accept (403/404, nothing changes).
- AC-22 Public link: view works with the token; select/accept without a verified OTP is refused; OTP sent only to the addressed contact; revoked or superseded link shows the generic page; internal notes, `stock_available_qty` and `internal_note` are absent from HTML.

Versions
- AC-23 Given v1 with group stable_key G, B selected, when v2 keeps B (price changed) and removes C, then on sending v2: v1 → superseded; B carried over with "changed since your choice"; event log has `carried_over`; a dropped selection logs `carry_over_dropped`; customer notified with the summary.
- AC-24 Creating a revision draft does not change v1's status; only sending v2 does; at most one open version per root.

Printing
- AC-25 Printing the fixture in uz, ru and en renders the alternative heading key, option labels 3A/3B/3C, an empty tick box per option, "Recommended" as text, the not-added footnote, the range line and the customer selection block; money/dates via `$doc` (locale separators); no `documents.*` key is printed raw.
- AC-26 `selection=selected` after acceptance prints only fixed + selected lines with the total and amount in words in the document language.
- AC-27 The printed HTML contains no color-only marker: every group/option state has a text label (assert the label strings are present).

Catalog alternatives
- AC-28 A manager links item X ↔ Y as mutual `upgrade`; Y shows X as `budget`; the suggestions drawer for X lists Y first, then same-category items with a resolvable price; 1C-synced items are unchanged (no write to `items`).
- AC-29 A sales user "Save as catalog alternative" creates a `proposed` link visible only to them and approvers until approved.

Drift
- AC-30 Given an open offer and a 1C stock update making option B unavailable, then `stock_warning_at` is set, the owner gets one notification, the portal shows the new band; prices unchanged.
- AC-31 Given an expired offer whose prices are unchanged, extend validity returns it to `sent` with selections intact; with a changed price, extend is refused with "Revise".

## 13. Tests plan (PHPUnit; `RefreshDatabase`)

Unit
- `Offers/OfferCalculatorTest`: line math net/gross, VAT exempt, discount rounding, fixed/selected/range/recommended (AC-6..8) with data providers incl. max_select 2, optional-only groups, large values (10^15 minor).
- `Offers/OfferStatusTest`: full transition table valid + invalid.
- `Offers/OfferValidatorTest`: V1–V9 trigger and clear.
- `Offers/ApprovalRulesTest`: worst-case evaluation, manual price, payment terms finance step, thresholds per role.
- `Offers/CarryOverTest`: stable_key matching, max overflow clearing, changed price flag.
- `Offers/AlternativeSuggesterTest`: ranking, proposed visibility, no price → excluded.

Feature
- `Offers/BuilderTest` (Livewire): add/convert/ungroup, reorder (`wire:sort` payload), recommended uniqueness, validation codes, totals persisted.
- `Offers/ApprovalFlowTest`, `Offers/SendOfferTest` (`offer_versions` payload + hash, notifications faked, one open version per root).
- `Offers/SelectionTest`: portal, internal on-behalf (channel/note/evidence), optimistic lock conflict, events appended, debounce notifications (`Notification::fake`, `travelTo`).
- `Offers/AcceptOfferTest`: AC-13, 14, 17, 18; threshold per portal role; fresh OTP; hash mismatch; double-click idempotency.
- `Offers/ConversionSnapshotTest`: AC-19 (with Spec 06 action; until then a contract test on the DTO passed to it).
- `Offers/RevisionTest`: AC-23, 24.
- `Offers/ExpiryAndExtendTest`: scheduler idempotency, timezone boundary, AC-31.
- `Offers/DriftListenersTest`: AC-30, price warning digest.
- `Policies/OfferPolicyTest`: matrix 4.1 as data provider (role × department role × action); admin no approval; self-approval refused.
- `Portal/OfferTenantIsolationTest`: AC-20/21 data provider over routes + Livewire actions + print.
- `Offers/PublicLinkTest`: AC-22, OTP rate limits, revoked/superseded, `noindex` header.
- `Documents/OfferPrintTest`: AC-25..27 for uz/ru/en; internal fields absent; payload-based rendering equals sent snapshot even after catalog changes.
- `Catalog/ItemAlternativeTest`: AC-28/29, no self link, mutual inverse.
- `TranslationParityTest` (existing) covers `offers.php`, new `documents.offer.*` keys and enum label keys.

## 14. Metrics

- Offer → order conversion: offers with alternatives vs without (hypothesis: alternatives raise win rate by ≥ 10 pp on out-of-stock cases).
- **Alternative win rate** per item and per `alternative_reason` (accepted offers where the option was selected ÷ accepted offers where it was offered); recommended-option acceptance rate; position bias (A vs B vs C share); price rank chosen (cheapest/middle/most expensive).
- Optional add-on attach rate and revenue per offer from add-ons.
- Time from sent to selection complete and to acceptance; % of choices made in portal vs public link vs recorded by rep.
- Disputes: orders amended because "wrong option" (target 0); offers accepted with total mismatch vs order (target 0, tested).
- Hygiene: % alternative lines with comparison data; % groups with a recommended option; expired-without-response rate.
Reports read `offer_selection_events`, `offers.accepted_selection`, `status_transitions` (Spec 04/Phase 6 reporting); a first "Alternatives" report ships in increment 05-9.

## 15. Risks and open questions

### Risks
| # | Risk | Mitigation |
|---|---|---|
| R-1 | Customers still add up all option prices | Separate "option price" column, boxed groups, footnote, range + recommended totals, text labels in print |
| R-2 | Builder becomes complex for reps | "Offer alternatives" one-click conversion, suggestions drawer, sane defaults (min/max hidden under Advanced), preview identical to customer view |
| R-3 | Concurrent selection by several customer users / rep | Optimistic `selection_revision`, row locks, event log |
| R-4 | Forwarded public link used by an unauthorized person | View-only token, OTP to addressed contact for actions, revocation, expiry, portal threshold rule |
| R-5 | 1C price/stock drift during validity | Binding prices by design; internal warnings; live stock bands; revision |
| R-6 | Order push to 1C includes unchosen options | Order copies only fixed + selected (AC-19); Spec 06 pushes order lines only |
| R-7 | Print layout breaks across pages / translations longer in ru/uz | `print-avoid-break`, continued headings, print tests per language |
| R-8 | Position bias distorts analytics | Labels frozen at send; analytics reports position share separately |
| R-9 | Dependencies (Spec 04 sequences, Spec 03 portal/OTP, attachments) not built | Increments 05-1..05-5 need only Spec 01/02/04 rails; portal/public link last |

### Open questions for the user (default is built if unanswered)
| # | Question | Default |
|---|---|---|
| Q-1 | Can the customer pick more than one alternative in a group? | Per group: default exactly 1 (min 1/max 1); rep may set e.g. "choose up to 2" or "0–1" (group may be skipped) |
| Q-2 | Can the customer change quantities in the portal? | No. They comment; the rep revises |
| Q-3 | Partial acceptance (drop fixed lines)? | No. Anything the customer may drop must be an optional group |
| Q-4 | Can alternatives have different quantity/unit (e.g. 10 pcs vs 1 set)? | Yes; qty and unit are shown prominently on each option and in the comparison |
| Q-5 | Are alternatives allowed for services as well as products? | Yes, same mechanism |
| Q-6 | Public link without a portal account? | Yes, view by token, act after OTP to the addressed contact; can be disabled in Settings > Offers |
| Q-7 | Discount limits and approvers | Sales 5%, sales head 10%, manager 25%, above → management head; finance co-approves payment-terms deviations |
| Q-8 | Default/max validity | 14 / 90 days |
| Q-9 | Show live stock to customers on open offers? | Bands only, never quantities (Spec 03 Q-8) |
| Q-10 | Must every offer have a lead? | No; `lead_id` nullable (portal requests and repeat customers) |
| Q-11 | Non-catalog (free-text) lines? | Not in v1 (1C order push needs GUIDs); request the item in 1C |
| Q-12 | Accepting one offer: auto-cancel competing offers of the same lead? | No; prompt the rep with a one-click "cancel others" |
| Q-13 | Change of choice after acceptance? | Not in the offer; via order amendment (Spec 06) |
| Q-14 | Should "recommended" be mandatory in each alternative group? | No, warning only; recommended total shown only when every required group has one |
| Q-15 | Max options per group | 6 (A–F) |
| Q-16 | Who maintains catalog alternative links? | Managers and sales/purchase department heads; sales propose |
| Q-17 | Is the reason for an alternative shown to the customer? | Yes (reason label + note); `internal_note` never |
| Q-18 | Pipeline value of an offer with pending choices | Selected if complete, else recommended, else range minimum |
| Q-19 | Notify the customer contact automatically when the rep records a choice on their behalf? | Yes, e-mail confirmation with "selected" PDF |
| Q-20 | Header-level discount and E-IMZO e-signature | Not in v1 (roadmap) |

## 16. Changes to other specs and skills
- Spec 01 roadmap row "3. Offers": detailed and superseded by this spec (note added).
- Spec 03 BR-P.4: portal acceptance allowed in open statuses (`sent`, `viewed`, `partially_selected`), not expired, and only when every required option group is satisfied; the threshold compares the **selected** gross total (note added).
- Spec 06 (`docs/specs/06-orders-agreements-accounting.md`, written 2026-10-18): `CreateOrderFromOffer` consumes the immutable `OfferToOrderPayload` (fixed + selected lines with `offer_line_id`, `offer_option_group_id`, `option_label`, group title, item snapshot incl. `accountant_code`, `class_code`, `package_code`, `is_serialized`); totals copied verbatim. Serial numbers are never entered at offer stage (Spec 06 section 21).
- Spec 04 (to write): provides `number_sequences` (`OF-`), `status_transitions`, lead → offer handoff and lead status on offer acceptance.
- `.claude/skills/offer-quotation/SKILL.md`: alternative-items rule added.

## 17. Rollout and build order

Feature flag `offers.enabled` (setting) for the nav entry; `offers.public_links_enabled` separately. Each increment ships with tests; migrations reversible.

| Step | Increment | Contents | Depends on |
|---|---|---|---|
| 05-1 | Schema + calculator | all tables of section 7 (groups/selections included from day one), enums with labels, `OfferCalculator`, `OfferValidator`, factories | Spec 01 money/items/customers, Spec 02 I-2/I-3, Spec 04 sequences/transitions |
| 05-2 | Builder (fixed lines) | create from lead/customer, sections, lines, PriceResolver, reprice, totals panel, index, nav entry, policies | 05-1 |
| 05-3 | Alternative & optional groups in builder | group cards, convert/ungroup, recommended, reasons, comparison rows, range/recommended totals, customer preview component | 05-2 |
| 05-4 | Approval + send + print | approval rules/steps/inbox, `SendOffer`, `offer_versions` snapshot, `documents/offer` print with boxes/tick boxes in uz/ru/en, email send with PDF | 05-3, Spec 03 attachments (PDF storage) |
| 05-5 | Internal choice recording + acceptance + conversion hook | record choice (channel/evidence), accept/reject on behalf, expiry scheduler, extend, `CreateOrderFromOffer` contract | 05-4 (order creation completes with Spec 06) |
| 05-6 | Catalog alternatives | `item_alternatives` UI on item page, suggestions drawer, proposed/approved | 05-3 |
| 05-7 | Revisions | `ReviseOffer`, version diff, carry-over, superseding | 05-5 |
| 05-8 | Portal + public link + notifications | portal offer screen (Spec 03 P-4), compare, autosave, accept with thresholds/OTP, public link + OTP, notifications/debounce | 05-5, Spec 03 O-3/O-5/O-6 |
| 05-9 | Drift + analytics | stock/price listeners, warnings, digest, alternatives report | 05-5, Spec 02 I-4 |

Critical path to first value: 05-1 → 05-5 lets sales send printed offers with alternatives and record choices by phone/paper; the portal (05-8) follows when Spec 03 portal shell exists. Rollback = disable flag + rollback the increment's migrations (no production data yet).

## 16. Pictures on offers (user requirement 2026-10-18)

Defaults (recorded per user instruction):
- An offer line references the item picture (`offer_lines.item_image_id`, the main picture when the line is added). When the offer is sent (`SendOffer`), the 320 px picture of each line is copied to `offer-images/…` and the path is kept on the line (`image_path`), so later changes to the item pictures never alter a sent offer. Revisions start from the previous snapshot and take a fresh one when sent. Snapshot files are never deleted with item pictures.
- The picture is shown on lines in the builder, the offer page and the customer view (also the portal offer page); each option of an alternative or optional group shows its own picture so the customer can compare.
- Printed offer (uz/ru/en via DocumentRenderer): a narrow picture column with the picture embedded as a data URI (no authenticated URL needed, CSP-safe), a neutral "no picture" placeholder otherwise. Setting `documents.show_item_images` (default on) plus a per-print override (`?images=0|1`, link on the offer page).
- Orders copy the snapshot (`order_lines.image_path`): thumbnails on the order lines tab; order and delivery note prints show the picture column only with `documents.show_item_images_orders` (default off) or `?images=1`. Agreement texts never contain pictures (legal text; the generated lines table stays text-only).
- Pictures of offer and order lines are served by `MediaController` only to staff who may view the offer/order or portal users of that customer.

**Update 2026-10-19 (Spec 03 section 22):** offers keep `customer_id`. Customer pickers only list organizations with the customer role.

> **Spec 10 note (2026-10-19).** `offers.requisition_id` (nullable) links an offer created from a customer request; revisions keep the link. Offer status changes move the request (sent → offered, accepted → ordered, rejected → back to quoting). See `docs/specs/10-customer-requisitions.md`.

> **Spec 03 section 24 note (2026-10-19).** Customers and suppliers/carriers are one **Partner** table (`partners`) with roles. Links that mean the organization are `partner_id` (contacts, supplier profile, equipment, service tickets, requests, catalog rules); links to the customer role keep `customer_id`. The 1C contract (paths, scopes, fields, feeds) is unchanged.

> **Offers only for partners (2026-10-19, Spec 04 section 18.9).** An offer is always created for a partner in the customer role; `lead_id` is read-only provenance. There is no lead -> offer action.
