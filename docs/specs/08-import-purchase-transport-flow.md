# Spec 08 — Import Purchase → Transport Tender → CEO Comparison Report → Shipment

| | |
|---|---|
| Status | Draft (design only; no code). Bid intake channels **decided by the user** (DEC-1). Other questions have defaults (section 17) |
| Owner | Product (B2B sales CRM) |
| Date | 2026-10-18 |
| Stack | Laravel 13 (PHP ^8.3), Livewire 4, PHPUnit, `QUEUE_CONNECTION=database`, app timezone UTC (shown Asia/Tashkent UTC+5), uz/ru/en |
| Depends on | Spec 01 (items, warehouses, `Money`, `activity_log`), Spec 02 (1C pull-only: CRM never calls 1C; 1C pulls the outbox), Spec 03 (counterparties, departments, `PermissionMap`, portal realm + OTP service, Telegram, notifications, attachments, **Tender engine** T-1..T-5, approvals inbox, `shipments`), Spec 06 (orders/order lines, `onec_outbox`, stock manager goods receipts, openspout decision DEC-20) |
| Amends | Spec 03: `purchase_orders` (manual POs, not only from awards), `tender_invitations` (bid links), `tender_bids` (channel, evidence, rounds, variants), `tender_bid_lines` charge codes, `login_codes` (link verification), `ShipmentStatus` (+`arrived`, `reconciling`), BR-T.14 (bid on behalf incl. justified late entry), approval routing for transport awards (CEO level), route-guard test whitelist (`/bid/*`). Spec 06: outbox feed `purchasing` (roadmap). Skill `transport-tender` (N25–N27, section 7) |
| Feeds | Spec 06 goods receipts (expected shipments, ETA), order promise dates (BR-OR.9), 1C landed cost (roadmap) |
| Numbering | 04 Leads, 05 Offers, 06 Orders/Agreements/Accounting/Stock, **07 unused** (Spec 03 already defines the tender engine implementation as increments T-1..T-6; Spec 06 header), 08 this spec |
| Domain skills | `.claude/skills/transport-tender` (lifecycle, N1–N24 checklist, scorecard — source of truth for field rules), `purchase-tender` (section 6 PO hand-off), `accounting-workflow`, `spec-writing`; agent `.claude/agents/logistics.md` |

### Repo baseline (verified 2026-10-18)
- Built: users (type, roles, locale), departments + members, settings, activity_log, units/categories/items (incl. `accountant_code`, `class_code`, `package_code`, `is_serialized`), price lists, warehouses, item_stocks, customers/contacts, 1C inbound/outbox (customers), `number_sequences`, `status_transitions`, offers (in progress by another agent). `App\Documents\DocumentRenderer::print(view, data, Locale, title, ?backUrl)`, `DocumentLanguage::resolve()`, `<x-print-language-picker>`.
- Not built: counterparties, attachments, notifications, portal, OTP, Telegram, tenders, purchase orders, shipments, orders. Everything below is new or extends Spec 03 designs that are not yet implemented, so the amendments cost no migrations of live data.

---

## 1. Problem

The company imports goods from foreign suppliers (EU, TR, CN, RU/KZ...). When the **Purchase** team registers an order to a supplier and the Incoterm leaves the main carriage to us (EXW/FCA/FOB...), somebody must find a carrier. Today: the transport employee phones/Telegrams 3–6 carriers, quotes arrive in different currencies, units and scopes (one includes customs brokerage, another excludes border fees, a third quotes rail), and the CEO receives a WhatsApp screenshot or an Excel he cannot verify. Consequences: decisions on freight price only, post-award surprises (demurrage, СВХ/terminal charges, uninsured damage), no audit trail, no history of lane rates, and no link between the transport cost and the goods' landed cost.

## 2. Goals / Non-goals

### Goals
- G1. Register an import **purchase order (PO)** to a supplier with Incoterm, pickup point, readiness date and cargo data (HS code, weight, volume, packages), optionally linked to a customer order.
- G2. One action **"Create transport tender"** on the PO that prefills the Spec 03 transport tender (`type = transport`) and runs the transport-tender completeness check (N1–N27).
- G3. **Bid intake through three channels**, all producing the same structured, sealed, comparable bid: (1) **employee-entered** bid with evidence, (2) **unique bid link** sent to the carrier (no account), (3) optional partner-portal login (Spec 03 OTP).
- G4. Normalise every bid to **total landed transport cost** in the evaluation currency (incoterm responsibility matrix, estimates for missing components, FX frozen at close), with transit, validity, risk flags and a weighted score.
- G5. A reproducible **Comparison Report** (one-page executive summary + detail) on screen, printable A4 uz/ru/en (black-and-white safe), CSV export (XLSX after DEC-20).
- G6. A **CEO decision** screen (approve / reject all / best-and-final round / approve non-lowest with justification) with step-up confirmation, immutable decision record, delegation and cost thresholds (small shipments decided by the purchase head).
- G7. Award → carrier confirmation → transport order → **shipment milestones** → POD → **cost reconciliation** vs award → carrier scorecard → landed cost per PO line.

### Non-goals (this spec)
- Supplier payments, carrier invoice payment and bank operations (1C; CRM only records the carrier invoice reference and clears it for payment).
- Customs duty/VAT calculation (duties are equal for all bidders under one Incoterm and route; excluded from comparison, per logistics agent).
- Purchase tenders for the goods themselves (Spec 03 `type = purchase`; a PO may still come from such an award).
- Live GPS integration with carriers' telematics (link field only), reverse auctions, server-side PDF generation library (browser "Print / Save PDF" via `layouts.print`), automatic CBU FX feed (manual entry by finance, Spec 03 Q-13).
- Pushing anything to 1C (pull-only; the `purchasing` outbox feed is roadmap, section 15).

## 3. Key decisions

| # | Decision | Alternatives rejected | Trade-off |
|---|---|---|---|
| DEC-1 | **Primary bid intake = employee-entered bid + unique bid link**; portal login (Spec 03) is optional third. All three write the same `tender_bids` structure, versioning and sealing (**user decision**) | Portal-only (Spec 03 default) | Carriers in the region rarely adopt portals; the link needs no account. Employee-entered bids weaken sealing for that bid (the enterer knows it) → mitigations in BR-B.4 |
| DEC-2 | Reuse the Spec 03 tender engine (`tenders`, `tender_lots`, `tender_transport_specs`, `tender_invitations`, `tender_bids`, `tender_bid_lots`, `tender_bid_lines`, `tender_criteria`, `tender_scores`, `tender_awards`, `shipments`); Spec 08 only adds columns and a few tables | Separate "transport quotes" module | One sealed-bid, audit and approval implementation |
| DEC-3 | Bid link = **token-scoped access** outside both guards: `/bid/{token}`, 256-bit random token, only `sha256` stored, bound to exactly one invitation; optional (default ON) 6-digit OTP verification to the invited contact before the first submit | Magic-link login into the portal realm | No user rows for one-off carriers; one invitation = blast radius of a leaked link |
| DEC-4 | **Owner of a transport tender = the transport employee** (department `logistics`, Spec 03 matrix unchanged). The **Purchase** team owns the PO, requests the tender from it, sees the comparison (linked scope) and the **purchase head is the L1 approver** for small shipments | Purchase specialist owns the tender | Matches Spec 03 and the user's "our employee for transportation". If that employee sits in the purchase department, grant `dept:purchase:specialist` on `tenders.transport.create` with scope `linked` (Q-2) |
| DEC-5 | **CEO = head of department `management`** (`dept:management:head`; exactly one head per department, Spec 03 BR-O.1). No new global role; CEO gets read-focused rights + decision ability | New `UserRole::Ceo` | No enum change; management dept already approves above-limit discounts (Spec 05) |
| DEC-6 | The comparison is a **frozen snapshot** (`tender_comparisons`: normalised numbers, FX, weights, flags, sha256 hash); the CEO decides on a specific snapshot; any later change to bids/estimates/FX/weights marks it `superseded` and voids pending decisions | Live-computed report | Reproducible decisions and prints years later |
| DEC-7 | Best-and-final offer (BAFO) = **new round in the same tender** (`tenders.round_no`, status `bafo`) restricted to shortlisted invitees; re-tender (new draft, `parent_tender_id`) stays for "reject all" | Re-tender for every rebid | Keeps the bid history and comparison in one record |
| DEC-8 | HS code ≠ `class_code`: `class_code` is the IKPU/MXIK for e-invoices (Spec 06 DEC-15); the 10-digit TN VED/HS code is a **separate** field (`items.hs_code`, PO line snapshot). Never substitute one for the other | Reuse class_code | Different classifiers; wrong code = wrong broker quote/permits |
| DEC-9 | Landed transport cost is computed in the CRM and stored per PO line (allocation by gross weight by default); 1C receives it later through the outbox (1C pulls) | CRM updates item cost | Items/costs are mastered in 1C (Spec 02) |

## 4. Users, roles and responsibilities

| Actor | Grant (Spec 03 model) | Does |
|---|---|---|
| Purchase specialist | `dept:purchase:specialist` | Creates/edits POs, requests the transport tender from the PO, answers cargo questions, sees bids/comparison of tenders sourced from own POs after opening |
| Purchase head | `dept:purchase:head` | Confirms POs, **L1 award approver** (≤ CEO threshold, rank-1, BR-D.2), approves justified late employee-entered bids, approves extra charges ≤ threshold |
| Transport employee (logistics specialist) | `dept:logistics:specialist` | Owns the transport tender: completes RFQ, invitees, sends bid links, enters bids received by email/phone/Telegram/WhatsApp, Q&A, evaluation, award proposal, transport order, shipment tracking, reconciliation |
| Logistics head | `dept:logistics:head` | Approves publishing (Spec 03 threshold rules), qualifies carriers, co-L1 approver when the purchase head is the PO owner (maker ≠ checker) |
| CEO | `dept:management:head` (+ delegate) | Decides awards above threshold or with exceptions; "Decisions waiting" dashboard; read access to POs, tenders after opening, shipments, reports |
| Finance (controller) | `role:finance`, `dept:finance:head` | Enters FX rates (CBU, closing date), co-approves awards > T2 (Spec 03), approves cost variances above tolerance with the purchase head |
| Accountant (operator) | `role:accountant`, `dept:finance:specialist` | Read POs/shipments/comparison after decision; registers carrier invoice reference; marks "cleared for payment" after 3-way match |
| Stock manager | `role:stock_manager` | Sees expected shipments ("Goods to receive", Spec 06 21.10); creates goods receipt from the shipment |
| Sales | `role:sales` | Sees status/ETA of POs/shipments linked to own customers' orders (no prices) |
| Carrier (external) | bid link (token) or partner portal user (`PartnerPortalRole::bidder`) | Reads notice, asks questions, submits/declines bid, (portal or link) confirms award and updates milestones |
| Admin | `role:admin` | System only; never reads sealed bids, never approves (Spec 03 DEC-10) |

## 5. User stories
- US-1 As a purchase specialist I register a PO to a Turkish supplier, FCA Istanbul, readiness 2026-11-05, with HS codes and weights per line, linked to customer order SO-2026-0123.
- US-2 As a purchase specialist I press "Create transport tender" on the PO and get a prefilled draft; the completeness panel tells the transport employee what is missing.
- US-3 As the transport employee I invite 5 carriers; for 3 I press "Send link" (email/Telegram/copy for WhatsApp), for 2 who only answer by phone I enter their bids myself with a call note or chat screenshot as evidence.
- US-4 As a carrier I open the link, choose Russian, read the RFQ, ask a question, verify myself with a code sent to my email, save a draft, submit a bid with a road and a rail variant, and receive a receipt.
- US-5 As the transport employee I see an invitation board (invited / link sent / opened / draft / submitted / declined / no response) and send reminders.
- US-6 As the CEO I open "Decisions waiting", read a one-page summary with a recommendation, approve the second-cheapest carrier with a justification and confirm with a code from Telegram.
- US-7 As the purchase head I approve small shipments myself without involving the CEO.
- US-8 As the transport employee I record milestones until POD and reconcile the carrier invoice against the award; extra demurrage needs approval.
- US-9 As an accountant I see the reconciled transport cost and clear the carrier invoice for payment; as purchase I see landed cost per PO line.

## 6. End-to-end flow

```
[Customer order (optional, Spec 06)]
        │ requires_import lines
        ▼
 1 PO draft (Purchase) ─► 2 PO confirmed (supplier confirmed; Incoterm, pickup point, readiness date)
        │ Incoterm leaves a leg to us (BR-PO.4)
        ▼
 3 "Create transport tender" (prefill from PO) ─► draft tender TT-YYYY-NNNN (owner = transport employee)
 4 Completeness check N1–N27 (blockers block, warnings need comment)
 5 Logistics review + approval to publish (Spec 03 thresholds)
 6 Publish: invitations (shortlist of qualified carriers / all qualified carriers of matching lanes)
      ├─ channel A: employee enters bids received off-platform (evidence mandatory)
      ├─ channel B: unique bid link per invitation (/bid/{token}, OTP verify default ON)
      └─ channel C: partner portal login (optional)
 7 Q&A window (answers + addenda go to all invitees through their channel)
 8 bids_due_at → system closes, opens bids (sealed until then)
 9 Finance freezes FX (CBU rate of closing date) → normalisation → knock-outs → scoring
10 Transport employee proposes award + recommendation → Comparison snapshot frozen
11 Decision routing: purchase head (L1) or CEO (L2) [+ finance > T2]
      approve │ approve non-lowest (justification) │ request BAFO → round 2 → back to 8 │ reject all → cancelled (+ re-tender)
12 Award → carrier confirms within 24 h (link/portal/employee records phone confirmation with evidence)
      declined/no answer → next rank (new decision if routing requires)
13 Transport order (print) = shipment SH-YYYY-NNNN with award snapshot
14 Milestones: booked → vehicle assigned → loaded → departed → border in/out → customs arrived/cleared → arrived → delivered → POD
15 Cost reconciliation vs award (extras, demurrage, approvals) → carrier invoice cleared for payment
16 Close: carrier scorecard, lane rate history, landed cost allocated to PO lines, tender completed
```

### 6.1 Purchase order (`PurchaseOrderStatus`, labels `purchase_orders.status.*`)
| From | To | Guard | Actor |
|---|---|---|---|
| draft | confirmed | supplier is `is_supplier`, ≥ 1 line, Incoterm + named place, readiness date, pickup location, destination warehouse | purchase specialist (own) / head |
| confirmed | in_transit | first shipment of the PO reaches `in_transit` | system |
| in_transit | partially_received / received | Spec 06 goods receipts posted (qty) | system |
| received | closed | all shipments closed (costs reconciled) | purchase head / system |
| draft / confirmed | cancelled | reason; confirmed → only if no awarded tender (else cancel tender first) | purchase head |

### 6.2 Transport tender (Spec 03 `TenderStatus` + `bafo`)
```
draft ─► pending_approval ─► approved ─► published ─► closed ─► evaluation ─► award_pending_approval ─► awarded ─► contracted ─► in_execution ─► completed
  ▲          │reject                       │addendum      ▲                     │  │ request BAFO              │decline/24h
  └──────────┘                             │              │                     │  ▼                           ▼
                                           │              └──── closed ◄── bafo (round + 1, shortlisted)     evaluation (next rank)
              cancelled ◄──────────────────┴────── (reject all / no valid bids / cancel with reason) ◄─────────┘
```
New/changed transitions (others as Spec 03 6.1):
| From | To | Guard | Actor |
|---|---|---|---|
| evaluation | award_pending_approval | award proposal per lot + **frozen comparison snapshot** (DEC-6); justification if not rank 1 | owner |
| award_pending_approval | awarded | decision `approve` / `approve_non_lowest` by the routed approver(s) with step-up confirmation | purchase head / CEO / delegate (+ finance > T2) |
| award_pending_approval | bafo | decision `request_bafo`: ≥ 1 shortlisted invitee, `bafo_due_at` ≥ now + 24 h (default 48 h) | CEO / purchase head |
| bafo | closed | `bafo_due_at` reached (scheduler); round-2 bids opened; shortlisted invitees who did not rebid keep their round-1 bid | system |
| award_pending_approval | cancelled | decision `reject_all` (reason); offers "Re-tender" (Spec 03) | CEO / purchase head |
| award_pending_approval | evaluation | decision `return` (comment: e.g. "check carrier X's insurance") | approver |

### 6.3 Invitation and bid link (`InvitationStatus`, labels `tenders.invitation_status.*`)
```
invited ─► link_sent ─► opened ─► draft ─► submitted ─► (revised: stays submitted, version + 1)
   │           │           │        │          └─► withdrawn (before deadline) ─► draft/submitted again
   └───────────┴───────────┴────────┴─► declined (reason)        no_response (at close, if never submitted/declined)
employee-entered bid: invited/link_sent/opened ─► submitted (channel = employee)
```
Status is derived from events (link sent, first open, draft save, submit, decline) and stored on `tender_invitations.status` for filtering. Link revocation does not change the bid; it only blocks further access.

### 6.4 Shipment (`ShipmentStatus`, amends Spec 03 6.2)
```
planned ─► vehicle_assigned ─► loading ─► in_transit ─► at_border ─► customs ─► arrived ─► delivered ─► reconciling ─► closed
   └──────────────► cancelled (reason; before loading)          exception flag (any active state, with reason) ┘
```
| Milestone event (`shipment_events.code`) | Sets status | Required data |
|---|---|---|
| `booked` | planned | award accepted, pickup date confirmed |
| `vehicle_assigned` | vehicle_assigned | truck/trailer plates, driver name + phone, container/wagon no.; due ≥ 48 h before pickup (setting) |
| `loaded` | loading → in_transit on `departed` | loading time, seal no., photos (optional), CMR/SMGS/BL no. |
| `departed` | in_transit | time, location |
| `border_in` / `border_out` | at_border | crossing point, time |
| `customs_arrived` / `customs_cleared` | customs | post/terminal, declaration no. (optional) |
| `arrived` | arrived | time at destination |
| `delivered` | delivered | unloading time, remarks (none / damage / shortage) |
| `pod_received` | (required before reconciling) | POD/CMR scan with stamps |
| `downtime_started` / `downtime_ended` | — | free-time consumption for demurrage |
Events by transport employee or carrier (link/portal, own shipment only); carrier events are marked `reported_by = carrier` and can be corrected only by internal users (audited). Delivery remarks `damage`/`shortage` open a claim draft with CMR time bars (skill section 6).

## 7. Bid structure (comparability)

### 7.1 Bid form (identical for employee, link and portal channels)
Per bid (header): carrier, currency (from `tenders.accepted_currencies`), `valid_until`, payment terms offered (days after delivery/documents, prepayment %), subcontracting (yes/no + who), insurance cover offered (insurer, amount, currency, cover type), liability terms (CMR 8.33 SDR/kg default / declared value / other), claims terms, documents offered (CMR, TIR carnet, SMGS, insurance certificate, GPS link...), acceptance of tender conditions (communications/confidentiality, checkbox), exclusions and assumptions (structured list + free text), attachments (quote PDF, licences, insurance certificates).

Per lot **variant** (≥ 1; e.g. "road FTL via KZ", "rail 40HC via Altynkol"): mode, load type, vehicle/equipment type and capacity (t, m3, pallet places), units count, route and border crossings, transshipment (yes/no + where), earliest pickup date, transit days (clock per tender spec), free time accepted (loading/customs/unloading hours), downtime/demurrage rate per truck per day (mandatory, N4), container free days/detention (container), primary-variant flag.

Per variant **charge lines** (`tender_bid_lines.charge_code`):
| Code | Label key | Typical basis |
|---|---|---|
| `freight` | base freight | per truck / per container / per kg / lump sum |
| `fuel` | fuel surcharge | % or amount |
| `border_permits` | border, permits, transit fees | lump sum |
| `terminal_storage` | terminal / СВХ / port / THC | lump sum, per day |
| `customs_brokerage` | customs brokerage (export/import declaration) | per declaration |
| `insurance` | cargo insurance | % of value |
| `loading` / `unloading` | loading/unloading at origin/destination | lump sum |
| `documents` | documentation (CMR, TIR, CO handling) | lump sum |
| `escort_permits_special` | oversize/DG/escort | lump sum |
| `other` | other (description required) | — |
Each line: `inclusion` = `included` (amount required) / `not_included` / `na`, `basis`, `quantity`, `unit_price_minor`, `amount_minor` (server-computed), currency = bid currency. Demurrage is not a charge line but the mandatory rate field (used in expected cost).

### 7.2 Incoterm responsibility matrix and normalisation
The tender's `charges_payer` (Spec 03) states, per charge code, the party paying under the PO Incoterm: `buyer` (us; comparable scope), `seller` (supplier), `n/a`. For each bid variant:
| Tender payer | Bid inclusion | In landed cost | Flag |
|---|---|---|---|
| buyer | included | bid amount | — |
| buyer | not_included | owner's estimate (`estimate_minor`, mandatory before evaluation completes) | F2 "estimate" |
| buyer | na | 0 only if the owner confirms n/a for that route, else treated as not_included | F2 |
| seller | included | **excluded** from total | F13 "seller-scope charge quoted (double-payment risk)" |
| seller | not_included / na | 0 | — |
Landed cost per variant (skill section 5, BR-T.8, evaluation currency, BCMath, half-up once per component):
```
landed = Σ buyer-scope components (bid or estimate)
       + expected_downtime_days × downtime_rate          (expected days per tender, default 1)
       + prepayment × cost_of_capital × prepay_days / 365 (setting cost_of_capital_bp, default 2400 = 24%/yr; verify)
       + cargo_value × carrying_rate × transit_days / 365 (setting carrying_rate_bp, default 2400)
```
Displayed per carrier as a grid charge × carrier with letters (I included / E estimate / – n/a / S seller-scope) so it prints in black and white.

### 7.3 FX policy
- Accepted currencies default USD, EUR, UZS (RUB/CNY optional per tender); evaluation currency UZS (Spec 03 Q-4).
- At close, finance enters the **CBU official rate of the closing date** (Asia/Tashkent date of `opened_at`) per currency (`tenders.fx_rates`: rate as decimal string scale 6, date, source `CBU`, entered_by). Evaluation cannot complete without it.
- Stored for every bid variant: original currency amounts (bid) **and** converted amounts (comparison snapshot). Never overwritten.
- If the decision happens > 7 days after close or the live rate entered by finance moves > 3%, the report shows warning W-FX; the approver may continue or ask for a re-snapshot.

### 7.4 Knock-outs (pass/fail before scoring; skill section 5)
On time (or approved late entry, BR-B.5), mandatory fields complete (all buyer-scope charges answered, transit days, downtime rate, validity), `valid_until` ≥ `bids_due_at` + `bid_validity_days`, carrier qualified **by award time** (quick-created carriers may bid; flag F10 until qualified), equipment/route compliant (transshipment/partial rules, avoided countries), insurance certificate valid through planned delivery when required, conditions accepted. Failed knock-out keeps the bid visible in the report (reason shown), never ranked.

### 7.5 Sealing, clarifications
Spec 03 BR-T.4/T.5 apply to all channels. Clarification answers and addenda are delivered to every invitee through the invitation's channel (link page shows them; email/Telegram notification with the link; employee-channel invitees: the owner must mark "communicated" with evidence, warning if not). A material addendum after a bid was submitted marks that bid "needs confirmation" (carrier confirms or revises; employee-entered: owner records confirmation with evidence).

## 8. Bid intake channels

### 8.1 Channel A — employee-entered bid
- Who: tender owner or any `dept:logistics:*` / `dept:purchase:*` user with `tenders.transport.enter_bid` (scope linked to tenders they may view).
- Steps: Invitation board → "Add bid manually" → pick an invited carrier, or **quick-create carrier** (name, country, contact name, email or phone/Telegram; tax ID optional) → status `prospect`, auto-invited, flagged F10 → same bid form as 7.1 → **source block (mandatory)**: received via (`email`, `phone`, `telegram`, `whatsapp`, `meeting`, `other`), received at (date-time, ≤ now), sender (name + email/phone), evidence file(s) (email PDF/.eml, quote PDF, chat screenshot) **or** call/meeting note text (≥ 20 chars; for `phone`/`meeting` a note is enough) → Save.
- Corrections until close create a new bid version with a reason; previous version `superseded`; diff in the activity log (amounts excluded from log until opening, Spec 03 security).
- After close: **late entry** only with a justified override (BR-B.5).
- Visibility: the enterer sees only the bids **they entered** (and receipts) before opening; never other bids (BR-B.4).

### 8.2 Channel B — unique bid link (no account)
Generation: Invitation board → "Send link" → choose delivery: email (invitation contact email), Telegram (contact's linked Telegram if the carrier contact is a known portal user with a link, else the employee copies), or **Copy link** (for WhatsApp/other; the copy action is logged). One **active** link per invitation; "Regenerate" revokes the old one; "Revoke" (reason) blocks access immediately.

| Property | Rule |
|---|---|
| URL | `https://<app>/bid/{token}` (route name `bid.show`) |
| Token | 32 bytes from `random_bytes`, base64url (43 chars, 256-bit); only `sha256(token)` stored (`tender_invitation_links.token_hash` unique); lookup by hash then `hash_equals`; plain token shown once to the sender (and in the email/Telegram) |
| Binding | exactly one `tender_invitation_id` (→ tender + carrier); the page never accepts tender/bid ids from the client |
| Expiry | submission until `bids_due_at` (server time, row lock); read-only access (notice, own receipt, result "awarded / not awarded") until `bids_due_at + grace` (setting `transport.link_grace_hours`, default 72 h); BAFO re-activates the link of shortlisted invitees until `bafo_due_at`; award confirmation for the winner uses the same link until `award_accept_due_at`, then milestone updates until the shipment is closed (Q-14) |
| Verification | per tender `link_verification_required` (default **ON**): before the first draft save/submit in a browser session, a 6-digit OTP is sent to the invited contact's email or linked Telegram (Spec 03 4.3 rules: 10 min, 5 attempts, 60 s cooldown, purpose `bid_link_verify`); verified state stored in the session bound to `link_id` for 12 h. OFF = submit without OTP (flag F16 "unverified link submission") |
| Headers | `Referrer-Policy: no-referrer`, `Cache-Control: no-store, private`, `X-Robots-Tag: noindex, nofollow`, `X-Frame-Options: DENY`; no third-party assets; token never in logs (route parameter masked in request logging and exception reports) |
| Rate limits | `throttle:bid-link`: 30 requests/min per link, 60/min per IP; invalid-token lookups 10/min per IP (→ generic 404); OTP limits per Spec 03; uploads 30/hour per link |
| Session | Livewire on `/bid/*` uses persistent middleware `ResolveBidLink` that re-validates the link on every request (incl. `/livewire/update`); CSRF on; not an auth guard; route-guard coverage test whitelists `bid.*` explicitly |
| Page content | company name/logo, tender number/title, deadline countdown (UTC+5 label), notice (RFQ details, route, cargo, dates, required documents, "Important conditions" block) translated labels uz/ru/en with a language switcher (default: invitation `notice_locale`, carrier choice stored in session), Q&A (ask + published anonymised answers), addenda, bid form 7.1, uploads, save draft, submit, withdraw, decline (reason code + text), receipts/version list (own only) |
| Not shown | other carriers, their count (default, Q-7), budget/target/incumbent (BR-T.12), internal notes/attachments, other tenders, site contacts (after award only) |
| Uploads | Spec 03 rules: private disk, 20 MB, allowlist pdf/jpg/png/xlsx/xls/docx/doc/csv/zip, content sniffing, random names, `scan_status`; max 10 files per bid version; sealed until opening |
| Submit | idempotent (client `submission_uuid`, unique per link; double-click = one version), server-side validation and recomputation of all totals; receipt = version, time, `payload_hash` (no amounts in email/Telegram, Spec 03 security); printable receipt page (DocumentRenderer, own amounts visible on screen only) for "Save as PDF" |
| Tracking | `first_opened_at`, `last_opened_at`, `open_count`, last IP hash (`sha256(ip . app key)`), user agent per open (`tender_link_events`) visible to the employee |
| Forwarded links | > 3 distinct IP hashes or OTP failures on a link → board badge "possibly forwarded" + notification to owner; owner may revoke/regenerate; verification ON prevents submission by anyone without access to the invited contact |
| Reminders | auto: 48 h and 6 h before deadline to invitations without submitted bid and not declined (setting); manual "Remind" (max 3/day per invitation); both via the original channel; "copy" channel → reminder task for the employee |

### 8.3 Channel C — partner portal (optional)
Spec 03 T-3 unchanged: carrier user with OTP login sees invitations and bids in `/portal`. If a carrier has both a portal user and a link, both write to the same invitation and bid versions (one bid per invitation).

## 9. Comparison report and CEO decision

### 9.1 Report content (from the frozen snapshot)
**Page 1 — executive summary** (A4, B/W safe): tender number, PO(s), supplier, route (origin → destination, mode), cargo (weight/volume/packages/value), Incoterm, readiness and required delivery date; **recommendation** box: system rank 1 (carrier, variant, landed cost) and the team's recommended carrier with reasons (if different: justification text, difference in UZS and %); top 3 table (landed cost, transit days, weighted score, key flags); savings vs budget / last shipment on lane / 12-month median; decision required (level, deadline, approver); open warnings count.

**Detail pages:**
1. Side-by-side normalised table (one row per bid variant): carrier, variant, knock-out, total landed (eval currency) + original currency total, cost score, transit days, time score, price per kg / per m3 / per pallet (or per chargeable kg for air), reliability score, compliance, payment terms (days, prepayment %), validity (date, days left), weighted total, rank, flags.
2. Incoterm responsibility/charge matrix (7.2) with estimates marked.
3. Weighted scorecard: criteria, weights (frozen at publish), per-carrier criterion scores, evaluator averages for manual criteria.
4. Savings: vs budget (`tenders.budget_minor`), vs last shipment on the same lane key (origin country+city → destination warehouse, mode, equipment), vs 12-month median of actual costs (`transport_lane_rates`); "no baseline" shown when missing.
5. Sensitivity: FX ±5% for each foreign currency (recomputed landed cost and rank; "rank stable" or "rank changes: X overtakes Y at +3.2%"); delay cost: cost per day of delay (carrying cost + linked customer order penalty if set) × (transit − fastest), and break-even days between rank 1 and the recommended carrier.
6. Exceptions and assumptions per carrier (structured exclusions + free text), estimates list (component, amount, who estimated).
7. Missing-information warnings (completeness warnings acknowledged at publish, missing evidence, unqualified carriers, unconfirmed addenda, validity expiring before decision + 3 days).
8. Evidence index: per bid version channel (employee/link/portal), received via, received at, entered by, evidence files (names + sha256), link verification status.
9. Audit: snapshot number/version/hash, generated by/at, FX rates (rate, date, source, entered by), tender timeline (published, Q&A, addenda, close, opened), decision record(s) when decided.

**Risk flags (codes, label keys `tenders.flags.*`):** F1 exclusions present; F2 estimate used; F3 validity < decision deadline + 3 days; F4 compliance document expires before planned delivery; F5 prepayment requested; F6 transit exceeds `max_transit_days` or misses required delivery date; F7 price < 70% of the median of valid bids (abnormally low, subcontracting risk); F8 subcontracting declared; F9 employee-entered bid; F10 carrier not qualified / new (no history); F11 liability cover < cargo value and our insurance not arranged; F12 late entry (override); F13 seller-scope charge quoted; F14 FX ±5% changes rank; F15 fewer than 2 valid bids; F16 unverified link submission; F17 bid needs addendum confirmation.

### 9.2 Formats
- On screen: Livewire page `tenders.transport.comparison` (tabs Summary / Table / Matrix / Scorecard / Sensitivity / Evidence / Audit).
- Print: `DocumentRenderer::print('documents.transport-comparison', ['snapshot' => ...], DocumentLanguage::resolve($request->query('lang')), __('documents.transport_comparison.title'))` with `<x-print-language-picker>`; labels only from `lang/*/documents.php` (`transport_comparison.*`), numbers/dates/money from `$doc`; flags and matrix as text/letters (no colour-only meaning). Internal document: no customer subject; language = explicit choice → UI locale.
- CSV: comparison table (one row per bid variant, columns of 9.1 item 1 + charge components) generated from the snapshot only; UTF-8 with BOM, `;` delimiter option (setting) for Excel ru/uz locales; **formula-injection safe**: any cell starting with `=`, `+`, `-`, `@`, tab or CR is prefixed with `'`. XLSX same columns once openspout is approved (Spec 06 DEC-20).
- Export/print allowed only when `opened_at` is set; actions logged (`comparison_exported`/`printed`, snapshot id, format).

### 9.3 Decision routing (setting `transport.decision_thresholds`, evaluation currency)
| Condition (any → CEO) | Default |
|---|---|
| Awarded landed cost > CEO threshold | **60 000 000 UZS** (≈ USD 5 000; Q-3) |
| Recommended carrier is not system rank 1 | always CEO |
| Fewer than 2 valid bids (F15), or single-source | always CEO |
| Landed cost > budget + 10% | always CEO |
| Late-entered bid is recommended (F12) | always CEO |
Otherwise **L1 = purchase head** (if the purchase head is the PO owner or the tender owner → logistics head; maker ≠ checker by user id). `role:manager` may act as L1. Above T2 (1 000 000 000 UZS, Spec 03) finance co-approves after the CEO.

### 9.4 Decision screen and record
- Opens only for the routed approver (or active delegate) with a `submitted` snapshot. Shows page 1 + links to detail; requires "I have reviewed the report" tick.
- Actions: **Approve recommended** · **Approve another carrier/variant** (non-lowest or different from recommendation → justification ≥ 30 chars mandatory) · **Request best-and-final** (choose shortlisted carriers, deadline, message to carriers) · **Return to evaluation** (comment) · **Reject all** (reason; optional "Re-tender").
- Step-up confirmation: 6-digit OTP via Telegram/email (OneTimeCodeService purpose `decision_confirm`, bound to user + snapshot hash) **or** password re-entry (within the request); default OTP, password allowed as fallback (Q-4).
- Record (`tender_decisions`, append-only, no update/delete; policy + model guard + test): tender, snapshot id + hash, level, decision, selected bid variant(s), justification/comment, decided_by, on_behalf_of (delegation), confirmed_via (`otp`/`password`), ip, user_agent, decided_at. Shown in the report audit section and printed.
- Delegation (`approval_delegations`): the CEO sets a delegate with date range and scope (`transport_award`) in My cabinet, or a standing deputy; the delegate acts "on behalf of"; delegate ≠ tender owner/PO owner. No automatic approval ever; SLA breach only escalates notifications.
- CEO dashboard "Decisions waiting": count + list (tender, route, landed cost, deadline/SLA, flags count), plus "Shipments with exceptions" and "Monthly transport spend vs budget" tiles (read-only).

## 10. Data model

Conventions: Spec 01/03 (money `*_minor` bigint + `currency` char(3); decimals as strings; weights kg decimal(12,3); volumes m3; string enums with label keys; `uuid` route keys; soft deletes for business records; `restrictOnDelete`; `status_transitions` + `activity_log` for every transition).

### 10.1 Items and PO lines (cargo master data)
| Table | Columns | Notes |
|---|---|---|
| `items` (alter) | `hs_code` string(10) null (TN VED, digits), `gross_weight_kg` decimal(12,4) null (per base unit), `net_weight_kg` decimal(12,4) null, `volume_m3` decimal(12,6) null (per base unit), `country_of_origin` char(2) null, `package_qty` decimal(12,3) null (units per package), `package_type` string(20) null | CRM-local, editable by purchase head / catalog.edit_local; 1C values win if 1C later sends them (Spec 02 field ownership amendment, Q-9). Index `hs_code` |
| `counterparty_locations` (new) | `counterparty_id`, `type` (`pickup`/`office`/`warehouse`), `name`, `country`, `city`, `address`, `postcode`, `lat`/`lng` decimal(9,6) null, `working_hours`, `contact_name`, `contact_phone`, `is_default` | supplier pickup points (prefill origin) |
| `purchase_orders` (replaces Spec 03 sketch) | `uuid`, `number` PO-YYYY-NNNN, `source` (`manual`/`tender_award`), `tender_award_id` null **unique**, `supplier_id` FK counterparties (`is_supplier`), `supplier_contract_ref`, `supplier_order_ref`, `order_id` null FK orders (customer order, Spec 06), `owner_id`, `department_id`, `status`, `currency`, `subtotal_minor`, `incoterm` (enum 2020 terms), `incoterm_place`, `pickup_location_id` FK counterparty_locations, `destination_warehouse_id` FK warehouses, `readiness_date`, `ship_by_date` null, `required_delivery_date`, `payment_terms`, `documents_available` json (invoice, packing list, CO type, certificates), `special_handling` text, `onec_number` null (manual), `confirmed_at`, `cancelled_at`, `cancel_reason` | index (`status`), (`supplier_id`), (`order_id`), (`readiness_date`) |
| `purchase_order_lines` | `purchase_order_id`, `position`, `item_id` null, `order_line_id` null (customer order line), snapshot `sku`, `name`, `unit_code`, `unit_precision`, `quantity` decimal(15,3), `unit_price_minor`, `line_total_minor`, `hs_code`, `country_of_origin`, `gross_weight_kg`, `net_weight_kg`, `volume_m3` decimal(12,3) (line totals, prefilled qty × item master, editable from the supplier packing list), `packages_count`, `package_type`, `dimensions` json null, `is_dangerous`, `un_number`, `is_temperature_controlled`, `weight_source` (`master`/`packing_list`/`manual`), `landed_transport_minor` null (UZS, after close) | totals server-side |

### 10.2 Tender extensions (Spec 03 tables)
| Table | Added columns / changes |
|---|---|
| `tenders` | `round_no` tinyint default 1, `bafo_due_at` null, `link_verification_required` bool default true, `expected_downtime_days` decimal(5,2) default 1, `delay_cost_per_day_minor` null, `decision_level` (`l1`/`ceo`) computed at proposal, `decision_due_at` |
| `tender_sources` | `source_type = purchase_order` used; FK to `purchase_orders` (+ `order` via PO) |
| `tender_transport_specs` | `purchase_order_id` null (lot ↔ PO; consolidation = several lots or several sources), `prefilled_from` json (field → source, for "changed after prefill" warnings) |
| `tender_criteria` | method `auto_liability` added (optional insurance/liability criterion, default weight 0) |
| `tender_invitations` | `status` (6.3), `channel_preference` (`link`/`portal`/`employee`), `contact_email`, `contact_telegram_link_id` null, `notice_locale`, `reminder_count`, `last_reminded_at`, `decline_reason_code`, `needs_addendum_confirmation` bool, `shortlisted_round` null |
| `tender_invitation_links` (new) | `tender_invitation_id`, `token_hash` char(64) **unique**, `created_by`, `sent_via` (`email`/`telegram`/`copy`), `sent_to` (masked string), `sent_at`, `expires_at`, `revoked_at`, `revoked_by`, `revoke_reason`, `first_opened_at`, `last_opened_at`, `open_count`, `distinct_ip_count`, `verified_at` (last) | one active per invitation (app-enforced with row lock); index (`tender_invitation_id`, `revoked_at`) |
| `tender_link_events` (new) | `link_id`, `event` (`opened`, `otp_sent`, `verified`, `verify_failed`, `draft_saved`, `submitted`, `withdrawn`, `declined`, `rate_limited`, `revoked`), `ip_hash` char(64), `user_agent` string(255), `created_at` | append-only, 1-year retention; no amounts |
| `login_codes` (alter, Spec 03) | `user_id` → nullable; `tender_invitation_link_id` null FK; check: exactly one of them; purposes `bid_link_verify`, `decision_confirm` | same OTP service |
| `tender_bids` | `round_no`, `channel` (`employee`/`link`/`portal`), `link_id` null, `entered_by` null (replaces `entered_by_internal_id`), `submission_uuid` unique null, `valid_until`, `payment_days`, `prepayment_bp`, `subcontracting`, `subcontractor_name`, `insurance_cover_minor` + currency, `insurer`, `liability_basis`, `documents_offered` json, `exclusions` json (list of {code, text}), `assumptions` text, `conditions_accepted_at`, `needs_confirmation` bool, `late_override_id` null, `correction_reason` null | unique (`tender_id`, `counterparty_id`, `round_no`, `version`) |
| `tender_bid_sources` (new) | `bid_id` (version), `received_via` (`email`/`phone`/`telegram`/`whatsapp`/`meeting`/`other`), `received_at`, `sender_name`, `sender_contact`, `note` text, `entered_by` | attachments morph = evidence files (required unless note for phone/meeting) |
| `tender_bid_lots` (= bid variant) | `variant_no` tinyint default 1, `variant_label`, `is_primary`, `mode`, `load_type`, `vehicle_capacity_t`, `vehicle_capacity_m3`, `pallet_places`, `transshipment` bool + `transshipment_point`, `earliest_pickup_date`, `container_free_days`; computed per snapshot only (`landed_cost_minor`, `score`, `rank` stay as convenience, overwritten each evaluation) | unique (`bid_id`, `lot_id`, `variant_no`) |
| `tender_bid_lines` | charge codes per 7.1; `payer_scope` copy at normalisation (`buyer`/`seller`/`na`) | |
| `tender_late_overrides` (new) | `tender_id`, `bid_id`, `requested_by`, `evidence_received_at` (must be ≤ `bids_due_at`), `justification`, `approved_by` (≠ requester), `approved_at`, `status` | BR-B.5 |
| `tender_comparisons` (new) | `uuid`, `tender_id`, `round_no`, `version`, `status` (`draft`/`submitted`/`decided`/`superseded`), `fx_rates` json, `weights` json, `rows` json (per variant: original + converted components, totals, per-kg/m3/pallet, scores, knock-out, flags, rank), `matrix` json, `recommendation` json (system rank 1, recommended variant, reasons), `savings` json (budget, last, median, values used), `sensitivity` json, `warnings` json, `evidence` json, `payload_hash` char(64), `generated_by`, `generated_at`, `submitted_at` | immutable after `submitted` (guard + test); unique (`tender_id`, `round_no`, `version`) |
| `tender_decisions` (new, append-only) | 9.4 columns + `comparison_id`, `comparison_hash`, `selected` json (bid lot ids) | no updated_at; deny update/delete |
| `approval_delegations` (new, generic) | `delegator_id`, `delegate_id`, `scope` (`transport_award`, later others), `starts_at`, `ends_at` null (standing), `reason`, `created_by`, `revoked_at` | overlap check per scope |
| `tender_awards` | `comparison_id`, `decision_id`, `accept_due_at`, `accepted_via` (`link`/`portal`/`employee` + evidence source id) | |

### 10.3 Execution and closing
| Table | Columns |
|---|---|
| `shipments` (Spec 03, extended) | + `uuid`, `purchase_order_id`, `tender_award_id` unique null, `bid_lot_id` (awarded variant), `award_snapshot` json (charges, rates, free time, payment terms, currency), `planned_pickup_at`, `planned_delivery_at`, `eta_at`, `actual_*` per milestone, `tracking_url`, `exception_reason`, `reconciliation_status` (`pending`/`in_review`/`approved`), `closed_at` |
| `shipment_events` (Spec 03) | codes of 6.4, `occurred_at`, `location`, `note`, `reported_by_user_id` null, `reported_via` (`internal`/`link`/`portal`), `corrected_event_id` null |
| `shipment_charges` (new) | `shipment_id`, `charge_code` (7.1 + `demurrage`, `detention`, `storage_extra`), `quoted_minor` (from award snapshot, 0 if new), `invoiced_minor`, `currency`, `variance_minor`, `reason`, `carrier_invoice_ref`, `evidence` (attachments), `status` (`pending`/`approved`/`rejected`), `entered_by`, `approved_by` (≠ entered_by), `approved_at` |
| `purchase_order_line_costs` (new) | `purchase_order_line_id`, `shipment_id`, `cost_type` (`transport`), `amount_minor` (UZS) + `amount_original_minor` + `currency` + `fx_rate`, `allocation_basis` (`gross_weight` default / `volume` / `value`), `allocated_at` | unique (line, shipment, cost_type) |
| `transport_lane_rates` (new, derived) | `lane_key` (origin country:city → warehouse code:mode:equipment), `tender_id`, `shipment_id`, `kind` (`quoted`/`actual`), `amount_minor` UZS, `per_kg_minor`, `gross_kg`, `occurred_on` | index (`lane_key`, `occurred_on`); written at award and at close |
| `counterparty_ratings` (Spec 03) | subject = shipment; on_time (pickup and delivery), damage, communication, documents, invoice accuracy (variance) |
| `counterparty_documents` (Spec 03) | types + `transport_license`, `tir_carnet_holder`, `asmap_membership` (ATI/АСМАП), `cmr_insurance`, `cargo_insurance`, `adr`, `permit` (country); expiry drives qualification |

Migration order (after Spec 03 T-1..T-3 tables): items alter → counterparty_locations → purchase_orders/lines → tender column alters → tender_invitation_links, tender_link_events, login_codes alter → tender_bid_sources, tender_late_overrides → tender_comparisons, tender_decisions, approval_delegations → shipment alters, shipment_charges → purchase_order_line_costs, transport_lane_rates. All reversible; no production data.

## 11. Business rules

Purchase orders
- BR-PO.1 Numbering PO-YYYY-NNNN via `number_sequences`; never reused.
- BR-PO.2 PO line cargo data prefilled from item master × quantity; editing marks `weight_source = packing_list|manual`; totals recomputed server-side (BCMath, 3 decimals).
- BR-PO.3 Incoterm stored with version 2020 and named place; FOB/CFR/CIF/FAS with road/rail pickup inland → warning (skill N2).
- BR-PO.4 "Create transport tender" enabled when the buyer has a leg: EXW/FCA/FAS/FOB (main carriage); CPT/CIP/CFR/CIF (on-carriage from named place, if destination ≠ our warehouse); DAP/DPU to border (delivery leg); DDP/DAP-to-warehouse → hidden (seller delivers). EXW → warning "buyer must export-clear abroad; prefer FCA".
- BR-PO.5 Prefill (one lot per pickup location): origin = pickup location; destination = warehouse; cargo description/HS codes/weights/volume/packages/DG/temperature from lines; cargo value = PO subtotal (currency); incoterm + place; readiness = `readiness_date`; pickup window = readiness .. readiness + 3 days; delivery window to = `required_delivery_date` (or linked customer order promise date − 2 days buffer if earlier); required documents from `documents_available`; `tender_sources` = PO (+ customer order). Prefilled values stay editable; changes logged.
- BR-PO.6 One PO may feed several tenders over time (re-tender) but only one **active** (not cancelled/completed) transport tender per PO lot (unique app check).

Completeness (adds to N1–N24, skill updated)
- N25 (W) PO lines with weights/volumes from master data or manual entry, not from the supplier packing list.
- N26 (W) Required delivery later than the linked customer order promise date (like P9).
- N27 (W) No internal budget/target rate (savings vs budget and routing use last-lane rate instead).

Bids
- BR-B.1 One bid per invitation per round; versions per BR-T.4; all channels share it.
- BR-B.2 Employee-entered bid requires a `tender_bid_sources` row with evidence (file or note for phone/meeting) and `received_at` ≤ now; correction = new version + reason.
- BR-B.3 Link submit/draft only while link active and `now < bids_due_at` (or `bafo_due_at` for shortlisted); verification enforced if ON.
- BR-B.4 Sealing for enterers: before `opened_at`, an internal user sees bid content only for versions **they entered** (needed to type/correct it); never others'. Admin never. The comparison marks employee-entered bids (F9). A user who entered a bid cannot be the L1/CEO approver of that tender (SoD).
- BR-B.5 Late employee entry: allowed only if `evidence_received_at ≤ bids_due_at`, within 24 h after close (setting), with `tender_late_overrides` approved by the purchase head (or tender department head, ≠ requester); flagged F12; routes the decision to the CEO if recommended.
- BR-B.6 Totals, conversions, landed cost and scores are always recomputed server-side from lines; client values ignored.
- BR-B.7 Bid amounts never appear in logs, notifications, job payloads, exports or `activity_log` before `opened_at` (Spec 03 security), including link events and employee entries.

Evaluation and decision
- BR-E.1 Evaluation requires FX for every bid currency, estimates for every buyer-scope not_included/na-unconfirmed component, and knock-outs evaluated.
- BR-E.2 Snapshot generation is deterministic: same inputs → same `payload_hash` (canonical JSON, sorted keys, string decimals).
- BR-E.3 Submitting an award proposal freezes the snapshot; any change to bids (late entry, correction after opening is forbidden except knock-out reasons), estimates, FX or scores supersedes it and cancels pending decisions (notifying approvers).
- BR-D.1 Routing per 9.3; approver ≠ PO owner, tender owner, bid enterer, delegate's own conflicts.
- BR-D.2 Non-lowest / non-recommended approval requires justification; BAFO limited to one extra round by default (setting `transport.max_bafo_rounds` = 1).
- BR-D.3 Decisions are append-only; a wrong decision is corrected only by a new tender action (e.g. winner declines → next decision).
- BR-D.4 Winner acceptance within 24 h (Spec 03); else owner proposes next rank with a new snapshot version (routing re-evaluated).

Execution and closing
- BR-X.1 Award → exactly one shipment per lot (unique `tender_award_id`), award snapshot copied.
- BR-X.2 `vehicle_assigned` missing 48 h before planned pickup → alert; no update for 24 h while in_transit/at_border/customs → alert.
- BR-X.3 `reconciling` requires `delivered` + `pod_received`. Charges: invoiced − quoted per code; variance ≤ tolerance (setting 2% of awarded total, min 0) auto-approved; above tolerance or new charge codes → approval by purchase head (≤ CEO threshold for the variance amount) or CEO (above), finance informed; approver ≠ enterer.
- BR-X.4 Close: all charges decided → accountant may mark carrier invoice "cleared for payment"; final cost allocated to PO lines by basis (rounding remainder to the largest line); lane rate `actual` written; rating requested from the owner (mandatory to close).
- BR-X.5 Demurrage claims by the carrier must reference `downtime_*` events; without events → warning "no recorded downtime".

## 12. UI / screens and navigation

### 12.1 Internal screens (Livewire, `layouts.app`)
| Screen | Contents |
|---|---|
| Purchase orders index/show/form | filters status/supplier/readiness/linked order; show tabs: Lines (cargo data, weight source badges), Transport (tenders, shipments, ETA), Costs (landed per line), Activity; actions Confirm, Create transport tender, Cancel |
| Tender wizard (Spec 03) | prefilled from PO; completeness panel with N-codes |
| **Invitation board** (tender show → Invitees tab) | row per carrier: qualification badge, channel, status (6.3), link sent via/at, first/last opened, open count, verification, "possibly forwarded" badge, bid submitted y/n + time (no amounts before opening), declined reason; actions: Add bid manually, Send link, Resend, Copy link, Regenerate, Revoke, Remind, Mark addendum communicated; bulk "Send links to all", "Remind all without bid" |
| Bid entry form | same component as the link form (shared Livewire form object), plus source/evidence block |
| Evaluation | FX status (finance), estimates entry, knock-outs, manual scores, recompute |
| Comparison report | 9.1 tabs, Print (language picker), Export CSV/XLSX, Submit for decision |
| Decision screen | 9.4 |
| Decisions waiting (approvals inbox filter + CEO dashboard tile) | |
| Shipments index/show | status board, milestones timeline, documents, exceptions, charges reconciliation, rating |
| Carriers | counterparties filtered `is_carrier`: qualification, documents with expiry, lanes, ratings, lane history |
| Settings > Transport | thresholds, grace hours, verification default, reminders, tolerance, cost of capital, carrying rate, allocation basis |

### 12.2 External
- `/bid/{token}` pages (layout `layouts.bid`: logo, language switcher, countdown; no sidebar): Notice, Q&A, Bid form, My bid (versions/receipts), Decline, Award confirmation (winner), Milestone update (winner, until shipment closed, if `transport.carrier_link_milestones` = true, default true).
- Partner portal (Spec 03): Tenders, Shipments.

### 12.3 Navigation (`config/navigation.php`, to be added by the implementing increment)
```php
// Procurement section (Spec 03) gains:
['label' => 'nav.purchase_orders', 'icon' => 'purchase-orders', 'route' => 'purchase-orders.index', 'active' => 'purchase-orders.*', 'can' => ['viewAny', PurchaseOrder::class]],
['label' => 'nav.shipments', 'icon' => 'truck', 'route' => 'shipments.index', 'active' => 'shipments.*', 'can' => ['viewAny', Shipment::class]], // replaces 'soon'
['label' => 'nav.carriers', 'icon' => 'counterparties', 'route' => 'counterparties.index', 'params' => ['type' => 'carrier'], 'can' => ['viewAny', Counterparty::class]],
['label' => 'nav.decisions', 'icon' => 'approvals', 'route' => 'approvals.index', 'params' => ['kind' => 'transport_award']], // CEO/heads
// Settings children: ['label' => 'nav.settings_transport', 'route' => 'settings.transport']
```
(`params` support needs a small renderer change; otherwise use separate routes.)

### 12.4 Permissions (proposed abilities for `config/permissions.php`; implemented by the build increments)
```php
$purchase  = ['dept:purchase:specialist' => 'own', 'dept:purchase:head' => 'department'];
$logistics = ['dept:logistics:specialist' => 'all', 'dept:logistics:head' => 'all'];
$ceo       = ['dept:management:head' => 'all'];
$finRead   = ['role:finance' => 'all', 'dept:finance:head' => 'all', 'role:accountant' => 'all', 'dept:finance:specialist' => 'all'];

'purchase_orders.view'        => $purchase + $logistics + $ceo + $finRead + ['role:manager' => 'all', 'dept:management:*' => 'all',
                                  'role:stock_manager' => 'all', 'role:sales' => 'linked_own_orders'],
'purchase_orders.manage'      => $purchase + ['role:manager' => 'all'],
'purchase_orders.confirm'     => $purchase + ['role:manager' => 'all'],
'tenders.transport.request'   => $purchase + $logistics,                       // Spec 03, unchanged
'tenders.transport.enter_bid' => $logistics + ['dept:purchase:*' => 'linked'],
'tenders.transport.send_link' => $logistics,
'tenders.transport.compare'   => $logistics + ['dept:purchase:*' => 'linked'] + $ceo + $finRead + ['role:manager' => 'all'], // after opened_at only
'tenders.transport.decide'    => ['dept:purchase:head' => 'all', 'dept:logistics:head' => 'all', 'role:manager' => 'all'] + $ceo, // level checked by DecisionRouter
'tenders.transport.late_override' => ['dept:purchase:head' => 'all', 'dept:logistics:head' => 'all'],
'tenders.fx.set'              => ['role:finance' => 'all', 'dept:finance:head' => 'all', 'role:manager' => 'all'],
'shipments.view'              => $logistics + $purchase + $ceo + $finRead + ['role:manager' => 'all', 'role:stock_manager' => 'all', 'role:sales' => 'linked_own_orders'],
'shipments.update'            => $logistics,
'shipments.approve_charges'   => ['dept:purchase:head' => 'all', 'role:finance' => 'all'] + $ceo,
'shipments.clear_invoice'     => $finRead,
'approval_delegations.manage' => $ceo + ['dept:*:head' => 'own'],
```
Matrix (summary):
| Action | Purchase spec./head | Transport employee / logistics head | CEO | Finance / Accountant | Sales | Stock mgr | Admin | Carrier |
|---|---|---|---|---|---|---|---|---|
| PO create/edit/confirm | own / dept | view | view | view | linked status | view | no | no |
| Create tender from PO | request | own / dept | no | no | no | no | no | no |
| Send/revoke link, remind | no | yes | no | no | no | no | no | no |
| Enter bid on behalf | linked | yes | no | no | no | no | no | no |
| See bid content before opening | only own entries | only own entries | no | no | no | no | **never** | own bid |
| Comparison view/print/export (after opening) | linked | yes | yes | read | no | no | no | never |
| Decide L1 / CEO | head (L1) | head (L1 if purchase conflicted) | CEO | finance co-approve > T2 | no | no | no | no |
| FX rates | no | no | no | finance | no | no | no | no |
| Shipment milestones | view | yes | view | view | ETA only | view | no | own shipment (link/portal) |
| Approve extra charges | head ≤ threshold | no (maker) | > threshold | finance ≤ threshold alt. / clear invoice (accountant) | no | no | no | no |

## 13. Notifications (in-app + email + Telegram per Spec 03 preferences/quiet hours; keys `lang/*/notifications.php` → `transport.*`; external messages in the invitation `notice_locale`, link pages switchable)
| Event | Recipients | Timing |
|---|---|---|
| PO confirmed with buyer-scope leg and no tender after 1 business day | PO owner, logistics head | daily |
| Transport tender requested from PO | logistics specialists (dept) | immediate |
| Tender pending approval / approved / rejected | approver / owner | immediate |
| Bid link / invitation (with link), reminders 48 h and 6 h, addendum, answer published | carrier contact (email/Telegram) | immediate / scheduled |
| Verification code | carrier contact | immediate (ignores quiet hours) |
| Bid received (no amounts) / receipt with hash | owner / carrier | immediate |
| Link possibly forwarded, OTP lock on a link | owner | immediate |
| Tender closed — FX needed | finance | immediate; reminder 4 business h |
| Evaluation due (closed > 1 business day) | owner, logistics head | daily |
| Decision required (L1/CEO/delegate) | routed approver(s) | immediate; reminder at 8 business h; escalation to delegate/manager at 24 business h (notification only) |
| Decision made | owner, PO owner, purchase head, finance (if > T2) | immediate |
| Award / regret | winner (accept link) / others (no prices, no winner name by default) | immediate |
| Winner not accepted in 24 h | owner | immediate |
| Vehicle data missing 48 h before pickup; no milestone 24 h; exception; ETA moved later | owner; PO owner; linked order owner (ETA) | immediate |
| Arrived/delivered | stock manager (destination warehouse), PO owner | immediate |
| POD missing 3 days after delivery; reconciliation not done 5 business days after POD | owner | daily |
| Extra charge pending approval / decided | approver / enterer, accountant | immediate |
| Compliance document of an invited/awarded carrier expires within 30 days | logistics head | daily |

## 14. Security
| Risk | Control |
|---|---|
| Bid link guessing/leak | 256-bit token, sha256 at rest, `hash_equals`, invalid-token throttling with generic 404, no-referrer/no-store headers, token masked in logs/exception reports, one active link per invitation, revoke/regenerate, expiry, verification ON by default, forwarded-link detection |
| Cross-carrier access (IDOR) | link resolves exactly one invitation; Livewire actions never take tender/bid/shipment ids from the client; uploads/receipts served via controller checking link ↔ owner; portal per Spec 03 (uuid, 404) |
| Sealed bids | BR-B.4/B.7; comparison/export/print require `opened_at`; admin always denied; CEO and approvers see nothing before opening |
| Employee manipulation of keyed bids | evidence mandatory with hash, versioned corrections with reason, F9 flag, enterer cannot approve, late entry only with evidence time ≤ deadline + head override (F12, CEO routing) |
| Decision repudiation / tampering | step-up OTP/password, append-only `tender_decisions` with snapshot hash, immutable snapshots |
| Formula injection in exports | prefix rule 9.2; tests with `=HYPERLINK(...)` |
| Malicious uploads | Spec 03 file rules, private disk, sniffing, `scan_status`, sealed until opening |
| Internal data leak to carriers | DTO whitelist for link/portal/notice (budget, target, incumbent, other carriers, internal notes, site contacts before award) — test asserts absence |
| CSRF / replay | CSRF tokens on link forms, idempotent `submission_uuid` |
| Abuse / spam | rate limits (8.2), reminders capped |

## 15. 1C (pull-only, roadmap)
The CRM never calls 1C. Later increment: outbox feed `purchasing` (Spec 06 `onec_outbox` pattern) with events `purchase_order.confirmed` and `purchase_order.landed_cost` (per line UZS amounts, shipment ref, carrier counterparty); 1C polls `GET /outbox/pending?feed=purchasing` and acks. Until then `purchase_orders.onec_number` is entered manually (purchase-tender skill section 6).

## 16. Acceptance criteria
PO and prefill
- AC-1 Given a confirmed PO FCA Istanbul with 3 lines with item master weights, when "Create transport tender" is pressed, then a draft TT tender exists with origin = default pickup location, destination = PO warehouse, HS codes/gross/net/volume/packages = line totals, cargo value = PO subtotal, incoterm FCA Istanbul 2020, readiness = PO readiness date, `tender_sources` = PO and its customer order; N25 is listed as a warning.
- AC-2 Given a DDP PO, the action is hidden; given an EXW PO, a warning about export clearance is shown.
- AC-3 Pressing the action twice yields one active tender per PO lot.
Employee-entered bids
- AC-4 Given an employee-entered bid without evidence and without a note, save fails; with a phone note ≥ 20 chars it saves with channel `employee`, received via `phone`, `entered_by` = user.
- AC-5 Given employee E entered carrier A's bid, before opening E sees A's bid but gets no amounts for carrier B (UI and direct Livewire call); admin sees no amounts for any.
- AC-6 Given the deadline passed, E cannot enter a bid without an approved late override; an override with `evidence_received_at` after the deadline is refused; with an earlier time and purchase head approval it saves and the comparison shows F12.
- AC-7 A correction creates version + 1 with a reason; the activity log contains no amounts before opening.
Bid link
- AC-8 Sending a link stores only `token_hash`; the plain token appears in the email/Telegram payload sent once and nowhere in DB, logs or `jobs` (scan test).
- AC-9 A random/modified token returns 404 with the same body as a revoked token; the 11th invalid lookup within a minute from one IP is throttled.
- AC-10 Regenerating revokes the previous link (old URL → 404); only one active link per invitation exists after concurrent regenerate calls.
- AC-11 With verification ON, submit without a verified session is refused; a code sent to the invited email verifies; 5 wrong codes invalidate it.
- AC-12 Submit at `bids_due_at + 1 s` is refused; read-only access works until grace end, then 404.
- AC-13 Link of carrier A cannot load or modify carrier B's bid, attachment, receipt or shipment (data-provider over all `/bid/*` actions with foreign ids injected).
- AC-14 `/bid/*` responses carry `Referrer-Policy: no-referrer` and `Cache-Control: no-store`; the page shows no budget, other carriers or participant count.
- AC-15 Double submit with the same `submission_uuid` creates one version; totals sent by the client are ignored and recomputed.
- AC-16 Decline with reason sets invitation `declined`; the board shows status changes (opened, draft, submitted) with timestamps.
Normalisation and report
- AC-17 Given bids in USD, EUR, UZS, a seller-scope charge included by one carrier, a not_included brokerage with estimate and a downtime rate, then landed costs, per-kg prices, scores and ranks equal a hand-computed fixture; the seller-scope charge is excluded and flagged F13.
- AC-18 Generating the snapshot twice with the same inputs gives the same `payload_hash`; changing an estimate after submission marks it superseded and cancels the pending decision.
- AC-19 FX +5% on USD changes the rank in the fixture → F14 shown with the crossover percentage.
- AC-20 Print in ru shows Russian labels and money via `$doc`; contains no colour-only information (flags as codes); CSV escapes a carrier name `=cmd()` as `'=cmd()`.
- AC-21 Before `opened_at`, comparison, print and export return 403.
Decision
- AC-22 Landed cost 40 000 000 UZS, rank 1 recommended, 3 valid bids, within budget → routed to purchase head; 80 000 000 UZS or non-rank-1 → CEO.
- AC-23 Non-lowest approval without justification fails; with justification and a valid OTP it creates one immutable decision (update/delete attempts fail) and moves the tender to `awarded`.
- AC-24 An active delegation lets the delegate decide "on behalf of" the CEO; outside the date range it does not; a delegate who is the tender owner is refused.
- AC-25 "Request best-and-final" re-activates links of shortlisted carriers only until `bafo_due_at`; non-shortlisted links stay read-only; round-2 comparison includes round-1 bids of non-responders.
Execution
- AC-26 Award acceptance creates exactly one shipment with the award snapshot; milestones move statuses per 6.4; `reconciling` is refused without POD.
- AC-27 An extra demurrage charge above tolerance needs approval by a user other than the enterer; after closing, landed transport cost is allocated to PO lines by gross weight and sums to the reconciled total exactly.

## 17. Tests plan
- Unit: `LandedCostNormalizerTest` (matrix rules, estimates, FX, rounding), `ComparisonSnapshotBuilderTest` (determinism, hash), `SensitivityCalculatorTest`, `DecisionRouterTest` (thresholds, conflicts, delegation), `BidLinkTokenTest` (entropy length, hashing, lookup), `PurchaseOrderPrefillTest`, `CompletenessCheckerTest` (N25–N27), `CostAllocatorTest` (remainder), `CsvExporterTest` (formula injection, BOM).
- Feature: `PurchaseOrders/LifecycleTest`, `Transport/CreateTenderFromPoTest`, `Transport/EmployeeBidEntryTest` (evidence, versions, sealing for enterer, late override), `BidLink/AccessTest` (valid/invalid/expired/revoked/grace, headers, throttling), `BidLink/VerificationTest`, `BidLink/SubmissionTest` (idempotency, deadline race with row lock, server recompute, uploads), `BidLink/IsolationTest` (IDOR data provider), `Transport/InvitationBoardTest` (status derivation, reminders, forwarded detection), `Transport/ComparisonAccessTest` (before/after opening, roles), `Transport/ComparisonPrintTest` (uz/ru/en, `DocumentRenderer`), `Transport/DecisionTest` (actions, OTP/password, immutability, BAFO), `Shipments/MilestonesTest`, `Shipments/ReconciliationTest`, `Security/SealedSinksTest` (scan logs, notifications, jobs, activity_log for fixture amounts), `Routes/RouteGuardCoverageTest` (whitelist `bid.*` only).
- Translation parity covers new keys; enum label test for every new enum.

## 18. Translations
New files `lang/{uz,ru,en}/purchase_orders.php`, `shipments.php`, `bid_link.php` (external page, OTP, receipts, decline reasons, errors); additions to `tenders.php` (invitation statuses, channels, received via, flags F1–F17, charge codes, decision actions, BAFO), `documents.php` (`transport_comparison.*`, `transport_order.*`, `bid_receipt.*`), `notifications.php` (`transport.*`), `telegram.php` (carrier messages), `nav.php` (`purchase_orders`, `shipments`, `carriers`, `decisions`, `settings_transport`), `settings.php` (transport). Enum labels (PO status, shipment status/milestones, Incoterms with short descriptions, modes, equipment) via keys. No literals; `TranslationParityTest` must pass.

## 19. Metrics
Bids per tender (valid ≥ 3), share of bids via link vs employee entry, link open → submit conversion, cycle time PO confirmed → award (median ≤ 5 business days), decision time (median ≤ 1 business day), savings vs budget/last lane rate, % awards non-lowest (with justification), actual vs quoted variance (target ≤ 3%), demurrage cost per shipment, on-time pickup/delivery, reports printed/exported per decision.

## 20. Build order (each increment shippable, tested, reversible)
| Step | Increment | Contents | Depends on |
|---|---|---|---|
| 08-1 | Cargo master + POs | items cargo columns, counterparty_locations, purchase_orders/lines, PO UI, permissions, nav | Spec 03 T-1 (counterparties), Spec 01 warehouses; customer order link after Spec 06 06-2 (nullable FK) |
| 08-2 | Tender from PO | prefill action, N25–N27, sources | Spec 03 T-2 |
| 08-3 | **Employee-entered bids** + invitation board | bid sources/evidence, quick-create carrier, variants, charge codes, versioning, sealing for enterers, late overrides | 08-2, Spec 03 bids tables (T-3 schema part; portal not needed) |
| 08-4 | **Bid links** | links, events, OTP verification (login_codes alter), `/bid/*` layout and Livewire, uploads, receipts, reminders, decline | 08-3, Spec 03 O-3 (OTP service), attachments; Telegram delivery after O-4 (email + copy work first) |
| 08-5 | Normalisation + comparison report | FX freeze, estimates, matrix, scores, snapshot, screen, print, CSV | 08-3 (can ship before 08-4), Spec 03 T-4 core |
| 08-6 | Decision | routing, decision screen, OTP/password step-up, decisions, delegations, BAFO, CEO dashboard | 08-5 |
| 08-7 | Execution | award acceptance, shipments, milestones (internal + link), alerts | 08-6, Spec 03 T-5 |
| 08-8 | Closing | charges reconciliation, approvals, allocation to PO lines, lane rates, ratings | 08-7 |
| Later | XLSX export (DEC-20), 1C `purchasing` outbox, partner-portal channel (Spec 03 T-3), claims module | | user approvals |
**MVP (fastest value):** 08-1 → 08-2 → 08-3 → 08-5 → 08-6 (employee-entered bids + CEO report and decision); links (08-4) next.

## 21. Risks
| # | Risk | Mitigation |
|---|---|---|
| R-1 | Transport employee knows the amounts they keyed and could leak them to a favoured carrier | Evidence + timestamps, F9 flag, enterer ≠ approver, encourage links (carriers enter themselves), metric: share of employee-entered bids |
| R-2 | Bid link forwarded or intercepted | Verification ON, forwarded detection, revoke, expiry |
| R-3 | Item master lacks weights/HS codes | Packing-list entry on PO lines, N1/N25, data-quality report |
| R-4 | FX movement between close and decision | W-FX warning, re-snapshot option, sensitivity table |
| R-5 | CEO bottleneck | L1 routing for small/clean cases, delegation, reminders |
| R-6 | Estimates disputed | Estimates visible with author, flagged F2 |
| R-7 | Scope size | MVP slice; links and execution later |
| R-8 | Carriers reluctant to OTP | Per-tender toggle (F16 when off) |

## 22. Open questions for the user (default built if unanswered)
| # | Question | Default |
|---|---|---|
| Q-1 | Bid intake channels | **Answered**: employee-entered + unique link (primary), portal optional |
| Q-2 | Is the transport employee in the Logistics or the Purchase department? | Logistics (DEC-4); if Purchase, grant `tenders.transport.create` to purchase specialists (linked) |
| Q-3 | Who is "CEO" and the CEO threshold | Head of department `management`; CEO decides above 60 000 000 UZS landed cost or on exceptions (9.3); purchase head below |
| Q-4 | Decision confirmation | OTP (Telegram/email); password re-entry as fallback |
| Q-5 | Who acts when the CEO is away | Delegate set by the CEO with dates (or standing deputy); no auto-approval |
| Q-6 | Link grace period / OTP verification default | Read-only 72 h after deadline; verification ON per tender |
| Q-7 | May carriers see the number of participants? | No |
| Q-8 | FX source | CBU official rate of the closing date, entered by finance; both original and converted amounts stored |
| Q-9 | Are weight/volume/HS code kept in 1C items? | Unknown; CRM-local fields now, 1C wins if it starts sending them |
| Q-10 | Minimum number of carriers | 3 invited (warning), ≥ 2 valid bids else CEO decides |
| Q-11 | Budget baseline | Optional tender budget; otherwise last lane rate and 12-month median |
| Q-12 | Customs brokerage quoted by carriers or our broker? | Charge line in every bid; if our own broker is used, the owner enters one estimate for all bids (comparable) |
| Q-13 | Late employee entry | Only if evidence time ≤ deadline, within 24 h, purchase head override |
| Q-14 | Do carriers update milestones through the link? | Yes (winner's link stays valid until shipment closed) |
| Q-15 | Regret notice reveals winner/price? | No |

## 23. Build status (2026-10-18, job 7: 08-1, 08-2, 08-3, 08-4, 08-5, 08-6 core)

Built and tested (`tests/Feature/Tenders/TransportFlowTest.php` + the Spec 03 tender suites; full suite 762 green):
- **08-1 Cargo master + POs**: items `hs_code` (≠ class_code), gross/net weight and volume per unit, country of origin (CRM-local, Items screen "Cargo data", ability `catalog.edit_cargo`); manual purchase orders (create, header, cargo lines prefilled from item master × quantity with `weight_source` master / packing_list / manual, confirm guards of 6.1, cancel, manual 1C number, print).
- **08-2 Tender from PO**: "Create transport tender" on a confirmed PO when the Incoterm leaves a leg to us (EXW warning, hidden for D-terms); prefill per BR-PO.5 incl. `prefilled_from`; one active tender per PO; sources = PO + its customer order; N25–N27 warnings; purchase users see tenders sourced from their POs (linked scope).
- **08-3 Employee-entered bids**: built in job 6 (evidence, versions, sealing for enterers, F9).
- **08-4 Bid links**: `/bid/{token}` (256-bit base64url token, only sha256 stored, one active link per invitation, regenerate revokes, expiry = deadline + 72 h grace, generic 404 + per-IP throttling of invalid tokens, no-referrer / no-store / noindex / DENY headers), e-mail delivery or copy-once on the invitation board (status, opens, verified, "possibly forwarded" after > 3 distinct IP hashes), OTP verification to the invited e-mail (Spec 03 `login_codes` generalised, purpose `bid_link_verify`, 10 min, 5 attempts, 60 s cooldown), page in uz/ru/en with the whitelisted notice, own bids/receipts only, questions, submit/revise (idempotent `submission_uuid`, server-side totals), withdraw, decline; link events append-only with keyed IP hashes.
- **08-5 Normalisation + comparison**: seller-scope charges excluded and flagged (brokerage/terminal via the payer matrix), flags F1–F3, F5–F10, F13–F17, per kg/m³/pallet, FX ±5 % sensitivity, savings vs budget, evidence index; screen with tabs Summary/Table/Matrix/Scorecard/Sensitivity/Evidence/Audit, print (uz/ru/en via DocumentRenderer), CSV (BOM, `;`, formula-safe); only after opening, never admins.
- **08-6 Decision core**: proposing an award freezes an immutable snapshot (`tender_comparisons`, deterministic sha256); routing per 9.3 (L1 purchase/logistics head ≤ 60M UZS and clean; CEO above or on exceptions; finance after the CEO above T2); decision screen with "reviewed" tick and step-up (password re-entry) for transport tenders; append-only `tender_decisions` with snapshot id/hash, selection and confirmation channel; return to evaluation supersedes the snapshot.

Decisions taken (defaults, per user instruction):
- **D7-1** Step-up is password re-entry (Q-4 fallback) until the Spec 03 OTP channels (job 8); purchase awards keep the plain approval (setting `tenders.decision_step_up`).
- **D7-2** Pickup points are stored on the PO (`pickup_country/city/address`); `counterparty_locations` deferred.
- **D7-3** "Approve another carrier", best-and-final rounds (BAFO), delegations and the CEO dashboard tiles are not built; approvers return the proposal with a comment and the owner re-proposes (non-rank-1 needs justification and routes to the CEO).
- **D7-4** Flags F4 (compliance document expiry before delivery), F11 (liability vs cargo value) and F12 (late entry with override) are not computed yet; late employee entries are refused (no override flow).
- **D7-5** Lane history and 12-month medians are not available: savings show "vs budget" or "no baseline".
- **D7-6** Link e-mails are sent synchronously with `Mail::send` views (no Mailable classes, no new folder); reminders (48 h / 6 h), Telegram delivery and receipts by e-mail come with job 8 notifications.
- **D7-7** Execution (08-7 shipments, milestones) and closing (08-8 reconciliation, cost allocation, lane rates) are not built.

**Update 2026-10-19 (Spec 03 section 22):**

- Suppliers and carriers are organizations with those roles. Their `counterparties` row is the supplier profile: qualification status, documents, ratings, fleet and lanes. Counterparty ids are unchanged.
- Tenders, bids, invitations, awards and purchase orders are unchanged.
- When an organization is also a customer, its name, tax ID, address and contacts are edited on the customer card.

> **Spec 03 section 24 note (2026-10-19).** Customers and suppliers/carriers are one **Partner** table (`partners`) with roles. Links that mean the organization are `partner_id` (contacts, supplier profile, equipment, service tickets, requests, catalog rules); links to the customer role keep `customer_id`. The 1C contract (paths, scopes, fields, feeds) is unchanged.
