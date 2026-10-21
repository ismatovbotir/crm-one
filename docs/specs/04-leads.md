# Spec 04: Leads (capture, qualification, conversion to offer)

| | |
|---|---|
| Status | Approved for build (job 9, 2026-10-18; defaults taken per user instruction "take the defaults in the specs and record them") |
| Depends on | Spec 01 (customers, contacts, items, `DuplicateCustomerFinder`, `Money`, activity log), Spec 03 (`PermissionMap`, departments), Spec 05 (`CreateOffer`, `AddOfferLine`, `offers.lead_id`) |
| Feeds | Spec 05 (offer prefilled from the lead, `offers.lead_id` FK), reporting (lead → offer conversion, lost reasons, response time) |

## 1. Problem

Enquiries arrive by phone, e-mail, website and exhibitions and are tracked in notebooks and chats. There is no owner, no proof of follow-up, duplicates are created against free-text company names, and offers cannot be traced back to the enquiry, so lead → offer conversion and lost reasons are unknown.

## 2. Goals / non-goals

Goals
- G1 Every enquiry is a numbered lead (LD-YYYY-NNNNN) with source, owner and status history.
- G2 Duplicates are visible at capture: matching customers (tax ID, name, e-mail, phone) and open leads.
- G3 Qualification is an explicit gate (customer + contact + need + BANT) before an offer can be created.
- G4 "Create offer" hands company, contact, currency and interested items to a draft offer exactly once and links `offers.lead_id`.
- G5 Disqualified / lost leads always carry a reason; stale open leads are easy to find.

Non-goals (this increment): web-form/API capture, CSV import, round-robin auto-assignment, scoring, reminders/notifications, merge of two leads, lead reports/dashboards (the data model supports them), portal requests.

## 3. Roles and permissions (config/permissions.php)

| Ability | Grants |
|---|---|
| `leads.view` | admin all (read only), manager all, management all, sales head department, sales role / sales specialist own |
| `leads.create` | manager, sales role, sales head, sales specialist |
| `leads.update` (edit, notes, status changes, create offer) | manager all, sales head department, sales role / specialist own |
| `leads.assign` (change owner) | manager all, sales head department |

Finance, accountant, stock and logistics have no lead access. Admin never edits leads (system role). Creating an offer additionally needs `offers.create`.

## 4. User stories

- US-1 As a sales rep I register an enquiry (company or existing customer, contact, source, need, interested items, estimated value) in under a minute and see possible duplicates before saving.
- US-2 As a sales rep I log calls, e-mails, meetings and notes so the history shows the follow-up.
- US-3 As a sales rep I qualify a lead once budget, authority, need and timeline are confirmed and the lead is linked to a customer and contact; if the company is new I create the customer from the lead with the data prefilled.
- US-4 As a sales rep I create an offer from a qualified lead; the offer opens prefilled and the lead becomes converted.
- US-5 As a sales head I reassign leads in my department and filter stale leads (no activity for 14 days).
- US-6 As a sales rep I close a lead as disqualified or lost with a reason, and I can reopen it with a comment.

## 5. Workflow

Statuses (`LeadStatus`): `new`, `contacted`, `qualified`, `converted`, `disqualified`, `lost`.

| From | Allowed to |
|---|---|
| new | contacted, qualified, disqualified, lost |
| contacted | qualified, disqualified, lost |
| qualified | converted, contacted (gate lost, e.g. contact changed), disqualified, lost |
| disqualified | contacted (reopen, comment required) |
| lost | contacted (reopen, comment required) |
| converted | none (final) |

Every change goes through `TransitionLead` (map check, `status_transitions` row with user/time/reason, timestamps `qualified_at`, `converted_at`, `closed_at`). No automatic status changes except `converted`, which is written by the conversion action in the same transaction as the offer.

## 6. Data model

`leads`: `uuid`, `number` (unique), `status`, `source` (`LeadSource`: website, phone, email, referral, exhibition, campaign, social, other), `campaign`, `owner_id` (FK users), `created_by`, `customer_id` (FK customers, nullable until qualification), `contact_id` (FK contacts, nullable), free-text company (`company_name`, `tax_id`, `industry`) and contact (`contact_name`, `contact_position`, `contact_email`, `contact_phone`) used until a customer is linked, `need` (text), `est_value_minor` + `currency`, `expected_close_date`, BANT flags (`budget_confirmed`, `authority_confirmed`, `need_confirmed`, `timeline_confirmed`), `close_reason` (`LeadCloseReason`), `close_note`, `qualified_at`, `converted_at`, `closed_at`, `last_activity_at`. Indexes: (`owner_id`, `status`), (`status`, `last_activity_at`), `tax_id`, `contact_email`, `contact_phone`.

`lead_items`: `lead_id` (cascade), `item_id` (FK items), `quantity` decimal(18,3), `note`; unique (`lead_id`, `item_id`).

`offers.lead_id` gets the foreign key to `leads` (null on delete; leads are never deleted in the UI).

Activities (calls, e-mails, meetings, notes) are `activity_log` rows on the lead (`lead_activity`, properties `{type, text}`) and update `last_activity_at`.

## 7. Business rules

- BR-1 Number LD-YYYY-NNNNN from `number_sequences` (yearly, row-locked).
- BR-2 Capture needs a company (customer or company name), a source and a way to reach the contact (customer contact, e-mail or phone). Owner defaults to the creator; only `leads.assign` may pick another active sales user.
- BR-3 Duplicates are warnings, not blocks: customers by exact tax ID, normalised name, e-mail, phone (`DuplicateCustomerFinder`); open leads by tax ID, e-mail, phone or name. The rep may link the existing customer in one click.
- BR-4 Qualification gate: customer linked (not archived, not deleted in 1C), contact of that customer linked, need filled, all four BANT flags confirmed. Missing items are listed by name.
- BR-5 Closing (disqualified/lost) needs a reason; reason `other` needs a note of at least 10 characters. Reopen needs a comment of at least 10 characters.
- BR-6 (replaced by section 18 and 18.9: conversion is to a customer, `ConvertLeadToCustomer`; offers are created from the customer) Conversion (`ConvertLeadToOffer`) locks the lead, requires `qualified`, creates a draft offer for the lead's customer and contact in the customer currency with `lead_id`, adds each interested item as a fixed line (quantity from the lead), and marks the lead `converted` with reason `offer:<number>`, all in one transaction. Items that can no longer be sold are skipped and reported; they stay on the lead. A second conversion is refused (`already_converted`).
- BR-7 Estimated value is stored in minor units with currency (never floats); it is informational and never copied as a price.
- BR-8 Stale = open lead (new, contacted, qualified) whose `last_activity_at` (or creation) is older than `config('leads.stale_days')` (14).
- BR-9 Converted, disqualified and lost leads are read only except notes and reopen (closed ones).

## 8. UI

- Leads list (`/leads`): number, company, contact, source, status (badge with text), estimated value, owner, last activity with "stale" badge; search (number, company, tax ID, contact e-mail/phone), filters status (default "open"), source, owner / mine, stale.
- Lead form (`/leads/create`, `/leads/{lead}/edit`): customer picker or new company fields, contact picker or free text, source/campaign, need, interested items (catalogue search, quantity), estimated value, expected close, BANT checkboxes, owner (assign permission only); duplicate panel.
- Lead page (`/leads/{lead}`): header with actions (Edit, Mark contacted, Qualify, Create offer, Close, Reopen, Reassign), qualification checklist with what is missing, "Create customer from lead" (customer form prefilled, the new customer and its primary contact are linked back), interests, linked offers, activity form and timeline (status changes and activities).
- Offer page shows "From lead LD-…" when linked. Sidebar entry Sales > Leads.

## 9. Acceptance criteria

- AC-1 Given a rep, when they save a lead with company name and phone, then LD-YYYY-00001 is created with status new, owner = rep, history row "created".
- AC-2 Given a customer with tax ID 123456789, when a lead is entered with that tax ID, then the duplicate panel shows the customer and "Use this customer" links it.
- AC-3 Given a lead without customer or BANT, when the rep qualifies, then it is refused listing each missing item; with all data it becomes qualified and `qualified_at` is set.
- AC-4 (replaced by section 18.9, 2026-10-19) Given an open lead, when "Convert to customer" runs with the option "Create a first offer", then the customer exists, the lead is converted, and a draft offer from the lead's interests exists for the customer; running the conversion again is refused and no second customer or offer is created. (The old direct "Create offer" from a lead and BR-6 `ConvertLeadToOffer` no longer exist.)
- AC-5 Given an open lead, when closing as lost without a reason (or `other` without note), then it is refused; with a reason it is lost and `closed_at` is set; reopen without a comment is refused.
- AC-6 Given a sales rep, when they open another rep's lead, then 403; a sales head sees department leads; finance gets 403 on the list.
- AC-7 Given an open lead with no activity for 15 days, then the stale filter lists it; logging a call removes it.
- AC-8 Invalid transitions (converted → contacted, new → converted) are refused by the map.

## 10. Tests

`tests/Feature/Leads/*`: capture + numbering, duplicates, qualification gate, conversion to customer (section 18.8; the offer is created from the customer, section 18.9), close/reopen rules, transition map, policies/IDOR, stale filter, create customer from lead, translation parity.

## 11. Metrics

Lead → qualified and lead → offer rates by source and owner, median time new → contacted (response time), lost/disqualified reasons, open pipeline value by status, stale lead count per owner.

## 12. Risks and open questions (defaults chosen)

- Q-1 Should accepting the offer (not creating it) mark the lead converted? Default: creating the offer converts (lead → offer KPI); the offer outcome is reported from the linked offer.
- Q-2 One lead → several offers: further offers for a converted lead are created from the customer and are not linked automatically (Spec 05 Q-12 stays open).
- Q-3 Duplicate leads are warned at capture; merging is section 20 (2026-10-20).
- Q-4 Personal data of closed leads: retention and anonymisation, section 19 (2026-10-20).

## 13. Rollout

Migrations `leads`, `lead_items`, `offers.lead_id` FK (no production data; `offers.lead_id` is null everywhere, so the FK is safe). Demo leads in the demo seeder. Rollback: drop the FK, then the tables.

## 14. Build status (2026-10-18, job 9)

Built and tested (`tests/Feature/Leads/LeadWorkflowTest.php`, 14 tests; demo seed test extended; full suite green):
- Migrations `leads`, `lead_items`; foreign keys `offers.lead_id` and `orders.lead_id` → `leads` (null on delete).
- `LeadStatus` (map + badge), `LeadSource`, `LeadCloseReason`, `LeadActivityType`; `Lead` (visibility and stale scopes, qualification gaps, never deleted), `LeadItem`; `LeadPolicy`; `leads.view/create/update/assign` in config/permissions.php; config/leads.php.
- Actions: `SaveLead` (numbering LD-YYYY-NNNNN, BR-2), `TransitionLead` (only status writer, `status_transitions`), `LeadWorkflow` (contacted, qualify gate, close with reason, reopen with comment, assign, activity log, link customer), `ConvertLeadToOffer` (BR-6, locked, idempotent, skipped items reported). `LeadDuplicateFinder`, `LeadOwners`.
- Screens: `/leads`, `/leads/create`, `/leads/{lead}`, `/leads/{lead}/edit`; customer form prefilled from `?lead=` and linked back; offer page shows "From lead"; dashboard tile "Open leads" with the stale count; sidebar Sales > Leads.
- Demo: five leads (new from an exhibition, contacted, qualified, lost, stale).

Decisions (defaults): Q-1 creating the offer converts the lead; Q-2 further offers are not linked automatically; quantities that do not fit the item unit (e.g. 1.5 pieces) are skipped with the reason and stay on the lead; the estimated value is never copied as a price.

## 15. Lead sources rev. 2 and Telegram leads (user decisions 2026-10-18)

### 15.1 Sources
`LeadSource`: website, phone, email, referral, exhibition, marketing (campaigns, social media, ads; replaces `campaign` and `social`, existing rows migrated, the campaign name stays a field), existing_customer (repeat or upsell), portal_request, telegram, tender, partner, cold_outreach, marketplace, other. Labels uz/ru/en; the list filter and the form follow the enum.

### 15.2 Telegram bot messages become leads
Defaults (recorded per user instruction):
- **Unknown sender** (chat not linked to a CRM account): a lead with source `telegram`, status new, company and contact name = the Telegram name (or @username), `telegram_chat_id`, `telegram_user_id`, `telegram_username`, the message text as the need, `locale` from the Telegram language (uz/ru/en, else uz). Owner by round robin among active sales users (`leads.round_robin`, default on; the pointer is the setting `leads.round_robin_last_user_id`), otherwise unassigned (owner null). Unassigned leads are visible to and assignable by managers and sales heads; sales see only their own.
- **Follow-ups**: further messages from the same chat are appended to the open lead of that chat as activities (type note); once the lead is converted, disqualified or lost, the next message opens a new lead.
- **Linked portal user**: a lead with source `portal_request` attached to their customer and contact; owner = the customer's account owner (notified).
- **Linked staff account**: never a lead; staff get the help text and their commands work as before.
- **Commands** (`/start`, `/start <token>`, `/stop`, `/help`, any text starting with `/`) and contact sharing never create leads; login codes are sent by the bot and not affected.
- **Attachments**: never downloaded (safe default: no untrusted files enter the system); the lead gets a note with the kind, file name and size and asks staff to request the file by e-mail if needed.
- **Spam protection**: messages from bots are ignored; at most `leads.telegram_rate_per_hour` (20) messages per chat and hour are processed, the rest are dropped silently; texts are capped at 2 000 characters.
- **Acknowledgement**: a new lead gets "We received your message, a manager will contact you" in the sender's language; appended messages get a short confirmation at most once per 10 minutes.
- **Notification**: the owner (or, when unassigned, everyone who may assign the lead) gets an in-app notification (`notifications` table, shown by the notification center, roadmap step 4) and a Telegram message when their account is linked.
- The webhook stays secret-verified and idempotent per `update_id`; outbound HTTP stays limited to api.telegram.org (`NoOutboundHttpTest`); nothing goes to 1C.

Build status: migration `add_telegram_and_sources_to_leads` (owner nullable, Telegram columns, source migration), `notifications` table, `TelegramLeadIntake`, `LeadRoundRobin`, `NewTelegramLead`, owner filter "Unassigned", tests `tests/Feature/Leads/TelegramLeadTest.php` (6).

## 16. Lead capture redesign (`/leads/create`, user requirement 2026-10-18)

Layout: two columns. Main column with numbered section cards (1 Company and contact, 2 Opportunity, 3 Products of interest, 4 Qualification, 5 Assignment); a sticky summary (lead score with a text band, completeness meter, suggested next step with the gaps that block qualification, duplicate warnings with the override reason, save actions, keyboard shortcuts). On small screens the summary follows the form. Follows `.claude/templates/page-form.blade.php` (error summary, labels, hints, lock hints). No inline scripts; the Alpine component `leadForm` (resources/js/app.js) adds the unsaved-changes warning and the shortcuts.

Defaults (recorded per user instruction):
- **Unified search** over customers (CRM and 1C-synced, badge "1C") and open leads by name, tax ID, phone or e-mail. Picking a customer prefills the primary contact, the owner (only for users with `leads.assign`, when the customer owner is assignable), preferred language, sale type, currency and source `existing_customer`; it shows the customer's open offers (3), last orders (3) and outstanding amount with the number of overdue orders. Picking a lead opens that lead.
- **New company checks**: live duplicate detection (customers by tax ID / name / e-mail / phone, open leads by tax ID / e-mail / phone / name); tax ID format for Uzbekistan (9 digits, not starting with 0). The official STIR check-digit algorithm is not published, so no checksum is guessed (format only). Phones are normalised to +998XXXXXXXXX and the normalised value is shown before saving ("will be saved as"); e-mails are stored in lower case.
- **Exact duplicates** (customer with the same tax ID, or an open lead with the same tax ID, e-mail or phone) need an override reason of at least 10 characters; it is stored on the lead and in the activity log (`duplicate_override`). Name similarity is a warning only.
- **Opportunity**: source (rev. 2 list), campaign, estimated value with currency (minor units), expected decision date, priority (low / normal / high), products of interest with quantity (search shows price, stock and the serialized flag), need text.
- **Score** (`LeadScore`, 0–100): BANT 15 each, customer linked 10, reachable 5, need 5, value 5, decision within 90 days 5, products 5, high priority +5. Bands: low < 40, medium < 70, high. Stored on save (`leads.score`). Suggestions: qualify / link customer / confirm BANT / contact.
- **Assignment**: owner defaults to the current user; another owner only with `leads.assign` within the assigner's scope (checked again on save). Round robin applies to leads that arrive without a person (section 15.2). A follow-up task is proposed for tomorrow 10:00 with a reminder at the due time (`lead_follow_ups`; `leads:remind` every 5 minutes sends an in-app notification once). Follow-ups are listed, added and completed on the lead page.
- **Draft**: in create mode the form is saved server-side per user every 20 seconds when it changed (`lead_drafts`); on the next visit the user can restore or discard it; saving removes it.
- **Actions**: Save, Save and add another, Save and create offer (when a customer is linked: qualifies through the BR-4 gate and converts; on a gap the lead page opens with the reason), Cancel. Shortcuts: Alt+S save, Alt+N save and add another.
- **Audit**: lead changes are logged (LogsActivity); duplicate override, follow-ups and status changes have their own history rows.

Build status: migration `add_capture_fields_and_follow_ups_to_leads`, `LeadPriority`, `LeadScore`, `UzbekIdentifiers`, `LeadFollowUp` + `ManageFollowUps` + `leads:remind` + `LeadFollowUpDue`, `LeadDraft`, redesigned `Leads\Form` and view, follow-ups on the lead page, tests `tests/Feature/Leads/LeadCaptureTest.php` (9). Design done without the ui-ux agent (not available in this session); a design review is recommended.

### 16.4 Step-by-step lead form (user decision 2026-10-20)
- `/leads/create` (and `/leads/{lead}/edit`) are a wizard by default; the switch "Show everything on one page" brings back the single page of 16 and is remembered per user (`users.ui_preferences.lead_form_single_page`, new JSON column in the baseline).
- Steps: **1 Company and contact** (unified search / new company, contact, language, sale type, **source and campaign** — moved here from the opportunity card in both modes), **2 Need and items** (need, estimated value and currency, expected close, priority, products of interest), **3 Qualification and assignment** (BANT, owner, follow-up), **4 Review** (what will be saved per step with "Edit" links, and the save actions).
- Next validates only the current step (same rules as saving, plus the tax ID format on step 1 and value/quantities on step 2); errors are shown in the error summary, which receives focus; otherwise focus moves to the heading of the shown step. Steps not reached yet cannot be opened; editing an existing lead reaches all steps.
- The sticky summary with score, gaps and the duplicates panel (and the override reason) stays visible on every step. The save buttons sit on the review step; Alt+S and Alt+N save from any step in both modes; a save with errors jumps to the first step that has one. The draft autosave and restore work unchanged (state lives in the component).
- Indicator: an ordered list of buttons with `aria-current="step"` on the current one and a hidden state text; keyboard reachable; wraps on small screens.
- Same component (`Leads\Form`), actions and permissions (admin may create, as before).
- Mismatch with 16: the five numbered cards become three steps plus review; source and campaign moved to the first card; the save actions are not in the sidebar in wizard mode until the review step (shortcuts still work).

## 17. Telegram users vs groups (user requirement 2026-10-19)

**Problem.** Leads arrive from single Telegram users and from Telegram groups (a buyer's team chat with the bot added). The CRM must tell them apart, keep the relation between a group and its members, and let a manager turn a group into a company and its members into contacts or portal users. It must not trust display names or give anyone access automatically.

**Defaults (recorded, not asked):**

- **D17-1 Data.**
  - `telegram_chats`:
    - one row per chat id; type private / group / supergroup / channel; title, username;
    - bot status member / admin / removed; member count (on demand);
    - first seen, last message;
    - `customer_id`, `lead_id` (the group's current open lead), `merged_into_id`.
  - `telegram_contacts`:
    - one row per Telegram user id; first and last name, username, language;
    - `user_id` (a staff or portal user, matched by the Telegram id of their own link), `contact_id` (at most one customer contact, so one company at a time);
    - phone only when the person shares their own contact with the bot.
  - `telegram_chat_members`:
    - unique (chat, person); role creator / admin / member / left;
    - source `message` (a message, or a join/leave service message), `chat_member` (member update) or `administrators` (getChatAdministrators);
    - first and last seen.
  - `telegram_messages`:
    - append-only, ids only: chat, person, message id, kind message/edited, edit date, lead;
    - unique per chat + message + kind + edit;
    - no text; the text lives only in the lead activity.
  - `contacts.telegram_username`.
- **D17-2 Webhook.** Handles:
  - `message` and `edited_message`;
  - `my_chat_member` (bot added, promoted, removed);
  - `chat_member`;
  - `new_chat_members` / `left_chat_member` service messages;
  - group-to-supergroup migration (`migrate_to_chat_id` / `migrate_from_chat_id`): the same chat row and open lead continue under the new id.

  Group ids are negative. A private chat id is the person's user id; this is used to infer the type when Telegram omits it. Messages from bots are ignored, including anonymous admins posting as the group. The webhook is still secret-verified and idempotent per `update_id`, and the message log deduplicates as well. `setWebhook` now asks for `message, edited_message, my_chat_member, chat_member`.
- **D17-3 Privacy mode.** To see every group message, make the bot an administrator or turn privacy mode off in BotFather (`/setprivacy`). Otherwise only commands and messages that mention the bot arrive. `chat_member` updates also need admin rights. This note is shown on the Telegram chats page. Members the bot never sees are not guessed.
- **D17-4 Leads.**
  - Private messages keep the rule from section 15.2.
  - A group opens ONE open lead per group chat:
    - source `telegram`;
    - company name = the group title, or the linked customer;
    - contact name = the first requester;
    - owner = the linked customer's owner, else round robin.
  - Later group messages append to that lead's activity with the sender's name and Telegram id. The timeline shows the sender's name, escaped.
  - Edits append as "(edited) ..." and never open a lead. Commands and service messages never open a lead.
  - Rate limits: per group 120/h (`LEADS_TELEGRAM_GROUP_RATE_PER_HOUR`) and per sender 20/h.
  - The bot acknowledges only a new group lead.
  - The lead page shows a "Telegram group" badge, the member count (seen vs total in the group) and the members seen.
- **D17-5 Manager actions.** All need `leads.update` and visibility of the chat; admins only read.
  1. **Create company from group.** This is the existing "create customer from lead" form, prefilled with the group title and a note with the chat link. Saving links the lead and the chat: `LeadWorkflow::linkCustomer` links the group when the lead is a group lead. Later group leads start on that customer.
  2. **Create contacts from members.** For a linked group; needs `update` on the customer.
     - Each checked member becomes a contact with their name and Telegram username.
     - Skipped, with a reason: staff, people already belonging to another company, existing contacts.
     - Optional "prepare portal access" (needs `managePortal` and the portal switched on) creates a portal user:
       - viewer role;
       - no e-mail: a reserved placeholder `tg-<id>@telegram.invalid` that is never mailed (`User::hasDeliverableEmail`, `routeNotificationForMail`);
       - a Telegram link to their user id;
       - membership `activated_at = null`.

       The account is activated by the person's first sign-in with the 6-digit Telegram code, which they request on the portal login page with their @username. Nothing is sent automatically.
  3. **Link to an existing customer**, searching visible customers the manager may edit. **Unlink.**
  4. **Merge duplicate chats** (on the Telegram chats page):
     - members, customer and open lead move to the kept chat;
     - the duplicate keeps its row, its message log and `merged_into_id`, and its later messages go to the kept chat;
     - chats of different companies cannot be merged.

  Every action is audited on the chat (activity subject `telegram_chat`); linking and unlinking are also audited on the customer. A person who is in groups of different companies is flagged with a warning; a person can be a contact of one company at a time.
- **D17-6 Refresh members.** Only on demand, with the "Refresh members" button. It calls `getChatAdministrators` and `getChatMemberCount` through `TelegramClient` (api.telegram.org). There is no scheduled sweep.
- **D17-7 Security and privacy.**
  - Joining a group never grants portal access.
  - People are identified by Telegram user id only.
  - All names and titles are escaped (Blade); bot replies are escaped HTML.
  - Staff visibility follows the customer (Spec 01 D-7: commercial roles see all customers), else the lead.
  - Unlinked chats without a lead are visible to lead assigners and admins.
  - Portal users have no Telegram chat screens, so a portal user of company A never sees group data of company B.
- **D17-8 Notifications.**
  - New group lead: event `lead_created`, sent to the owner, or to the assigners when unassigned.
  - Bot removed from group: new event `telegram_bot_removed`, sent to the lead owner, else the customer owner, else the assigners. Telegram is on by default.
- **D17-9 UI.**
  - A Telegram tab on the customer card lists the linked groups with members and actions.
  - The lead page has a group panel.
  - The Telegram chats page (`/telegram/chats`) lists groups, supergroups and channels with type, bot status, company, open lead, members and last message, plus filters and merge.
  - All texts are in uz/ru/en.
  - Deviation from the request: the chats page sits in the Sales section of the sidebar, after Leads, not under Settings. The Settings group is admin-only by rule (NavigationTest). Admins still see the page.

**Tests.** `tests/Feature/Leads/TelegramGroupTest.php`:
- fixtures: private, group, supergroup, new member, left member, bot added/removed, bot message, chat_member, edits, migration;
- idempotency and the per-sender limit;
- create company from group;
- contacts from members, with pending portal access, conflicts and the staff skip;
- refresh, merge, link and unlink;
- escaping;
- tenant isolation.

**Update 2026-10-19 (Spec 03 section 22):** Telegram groups link to organizations with the customer role (`telegram_chats.customer_id`). Contacts created from group members go into the single `contacts` table.

**Update 2026-10-19 (Spec 03 section 23):** a group marked as a company group (`telegram_chats.purpose = internal_notifications`) is not a customer group: it never opens leads, members are not offered as contacts, and only staff bot commands are answered.

## 18. Convert to customer (user requirement 2026-10-19)

**Problem.** Until now "Create customer" on a lead only prefilled the customer form and linked the lead back. Contacts, interests, history and the Telegram chat stayed behind, and nothing stopped a duplicate organization. Conversion was only counted for lead → offer.

**Goal.** One action on the lead, "Convert to customer", moves or links everything to the organization in one transaction, never creates a duplicate, and never overwrites organization data silently. Non-goals: merging two existing organizations; reopening converted leads.

### 18.1 Who and when
- Open lead (new, contacted or qualified). No BANT gate: converting is how the customer gets created.
- Permission: `leads.update` for the lead **and** `customers.create` (policy `LeadPolicy::convertToCustomer`). Admins have no `leads.update` and stay read only.
- The old "Create customer" button on the lead is replaced. "Create offer" on a qualified lead with a customer stays (BR-6).

### 18.2 Status
- New transitions: `new → converted` and `contacted → converted` (`qualified → converted` already existed).
- The lead gets `converted_at`, `converted_by`, `customer_id`, `contact_id` and `conversion_kind` (`created` | `linked` | `role_added`; null when converted through the offer hand-off, which now also fills `converted_by`). The transition reason is `customer:<name>`.
- A converted lead is read only except notes, calls and follow-ups (`logActivity`). It cannot be reopened; a new enquiry is a new lead.
- Idempotency: the lead row is locked; a second attempt (double click, two managers) fails with "This lead has already been converted" and the dialog reloads the lead.

### 18.3 Matching and duplicates
`LeadConversionMatcher` looks for organizations (any role, the supplier/carrier-only ones from Spec 03 section 22 included):
- same tax ID: creating a new customer is **blocked**; the manager links it (customer) or adds the customer role (supplier/carrier only). Archived matches cannot be used;
- same e-mail or phone (organization or any of its contacts), same normalized name: a warning. The manager links, adds the role, or ticks "This is a different company" to create anyway;
- the customer already linked to the lead is pre-selected.

For an existing organization the dialog shows a per-field diff (name, tax ID, industry, e-mail, phone, document language, owner): "Organization now" vs "From the lead". Empty organization fields default to the lead value; filled ones default to "keep". The tax ID is offered only when the organization has none and is not linked to 1C. A user without `update` on the customer sees the diff read only (everything kept); `add_role` counts as editing the new role.

### 18.4 What moves
| Lead data | Where it goes |
|---|---|
| Company, tax ID, industry, e-mail, phone | New customer, or the existing one per the diff |
| Contact person | Organization contact; skipped when a contact with the same e-mail or phone exists (that one is used). Primary when the organization has none |
| Product interests | Stay on the lead and show on the customer card ("Product interests from leads", one row per item); "Create a first offer" and "Create offer from interests" turn them into offer lines at current catalogue prices |
| Activities, notes, status history | Stay on the lead (readable); the customer timeline shows the lead's status changes and its calls/notes/messages |
| Attachments | Leads have no attachments today; nothing to move |
| Telegram private chat or group | `telegram_chats.customer_id` set (private chat row created if missing); the sender's Telegram contact is linked to the contact. Later private messages open leads attached to the customer (owner = customer owner); a group already linked to another customer is left alone and reported |
| Owner | Customer owner = lead owner (new customer; or an empty owner on an existing organization); otherwise per the diff |
| Estimated value | Stays on the lead and in the funnel figures |
| Source and campaign | `customers.acquisition_source`, `acquisition_campaign`, `source_lead_id`, only when still empty |
| Open offers of the lead | Re-pointed to the customer (and its contact) unless accepted, rejected, expired, superseded or cancelled; audited on each offer |
| Customer requests | Requests (Spec 10) belong to portal customers and have no lead link; nothing to re-point |

### 18.5 Defaults chosen
- Sale type B2B; retail can be chosen (new customer and new customer role). Legal form defaults to legal entity. Country = `crm.default_country`; currency = lead currency.
- Document language = the lead language (the Telegram locale for Telegram leads); for an existing organization it is a diff field.
- Options: "Create a first offer" (on), "Invite the contact to the portal" (off), "Sync with 1C" (off; Spec 02 rev. 4).
- Portal invitation: saved as pending on the contact (`portal_invite_requested_at`); nothing is sent. The customer's Portal tab lists pending invitations with "Prepare invitation" (prefills the grant form, role requester) and "Dismiss". Granting access clears it.
- 1C (rev. Spec 02 rev. 4): the option sets the partner's "Sync with 1C" switch (needs a tax ID). Off: the partner is not offered to 1C and edits do not queue it; "Resync" is available only for sync-enabled partners. On: it is available for 1C to pull as usual. The CRM never calls 1C.

### 18.6 Audit and notifications
- Lead activity `converted_to_customer` (mode, customer, contact, changed fields, re-pointed offers, 1C option); customer activity `converted_from_lead`; offer activity `customer_repointed`; the status transition with user and time.
- Notification event `lead_converted` to the lead owner and the customer owner (not to the user who converted). In-app always; Telegram on by default; e-mail off by default.

### 18.7 Metrics
- Sales dashboard tile "Lead → customer (90 days)": converted / created in the last 90 days, hint with the average days to convert.
- Sales funnel report: new columns "Became customers" (`conversion_kind` set) and "Days to convert" (average, creation → conversion).

### 18.8 Tests
`tests/Feature/Leads/LeadConversionTest.php`: full transfer; tax-ID match blocks create; link with diff (kept vs taken); supplier-only organization gets the customer role; contact duplicates skipped; idempotency; Telegram private chat and group re-pointing and later messages; permissions (admin read only, other owner's lead); converted lead read only; offers re-pointed; 1C hold and resync; pending portal invitation; owner notification; metrics.

> **Spec 03 section 24 note (2026-10-19).** Customers and suppliers/carriers are one **Partner** table (`partners`) with roles. Links that mean the organization are `partner_id` (contacts, supplier profile, equipment, service tickets, requests, catalog rules); links to the customer role keep `customer_id`. The 1C contract (paths, scopes, fields, feeds) is unchanged.

### 18.9 Offers go only to partners (user decision 2026-10-19)
- Flow: lead → **Convert to customer** → offer. The lead page has no "Create offer" any more (`ConvertLeadToOffer` and `LeadPolicy::convert` removed); the lead form's "Save and create offer" became "Save and convert to customer" (opens the lead with the conversion dialog). The first offer is created by the dialog option "Create a first offer" or later from the partner card ("Create offer from interests") or Offers > New.
- Every offer has a partner: `offers.customer_id` was already NOT NULL, so no legacy offer exists without one (dev database: 0 offers with a lead, 0 without a customer; nothing needed manual conversion). `offers.lead_id` stays as read-only provenance.
- Conversion re-points only **draft** offers of the lead (legacy); sent/viewed offers are never moved. This answers the open question from section 18.
- Funnel: "converted" counts conversions to a customer; offers are counted on the offer side (win rate).
- Customer requests (Spec 10) and portal offers are unchanged.

## 19. Retention of personal data on closed leads (user decision 2026-10-20, answers Q-4)

- **Who:** leads closed as lost or disqualified. Open and converted leads are never touched (a converted lead's data lives on in the customer).
- **When:** `closed_at` older than `operations.retention.closed_leads_months` (default 24, env `RETENTION_CLOSED_LEADS_MONTHS`, floor 6; a `settings` row with the same key overrides the config). The nightly command `php artisan leads:anonymise` (04:25, scheduler) does it; `--dry-run` only counts.
- **What is removed:** contact name, position, e-mail and phone, tax ID, Telegram user id and username, the Telegram chat id when it is a private chat (positive id), the duplicate-override reason and the close note; the note of every follow-up; the text of every `lead_activity` row; free text in the change log (`activity_log` old/new values of those fields); the free text of status reasons (the reason code and a merge target are kept).
- **What stays:** lead number, company name, need, source, campaign, estimated value, score, status history, owner, items of interest, links to customer, offers and orders. Reports keep working.
- **Marker:** `leads.anonymised_at`. The lead page shows "Personal data removed"; an anonymised lead cannot be reopened (policy `reopen` and `LeadWorkflow::reopen`).
- **Audit:** one `anonymised` change-log row per lead. Scrubbing the change log is a deliberate, documented exception to its append-only rule (`App\Actions\Leads\AnonymiseClosedLeads` writes with the query builder, not through the model, so the old values are not copied into the log).
- **Telegram:** the message id log (`telegram_messages`, no text) follows the Telegram retention (`telegram:prune`, Settings > Telegram); the personal data of Telegram leads is in the lead and its activity rows, which this job removes.
- Tests: `tests/Feature/Leads/LeadAnonymiseTest.php`.

## 20. Merge duplicate leads (user decision 2026-10-20, answers Q-3)

- **Action:** "Merge duplicate" on an open lead (`LeadPolicy::merge`: open lead the user manages; the target must be open and managed by the user too). The user types the number of the lead to keep or picks one of the suggested possible duplicates (same tax ID, e-mail, phone, company name or customer, filtered to leads the user may merge into). `App\Actions\Leads\MergeLeads`, one transaction, both leads locked in id order.
- **Target (kept):** keeps its number, owner and BANT. It receives what it lacks from the source: tax ID, industry, contact fields, expected close date, Telegram identity, language, sale type, estimated value with currency, customer and contact. The source need text is appended as `<number>: <need>` when different. Score is recalculated.
- **Moves to the target:** interested items (same item: quantities add up; at most 50 items), follow-ups, Telegram chats and message links, offers and orders that point at the source (read-only provenance), the customer's `source_lead_id`, earlier merges into the source.
- **Source:** closed as lost with reason `duplicate`, `merged_into_id` = target, transition reason `merged_into:<number>`. It keeps its own history and activity; both leads get a change-log row (`merged_into`, `merged_from`). The lead page links to the target. A merged lead cannot be reopened.
- **Refused:** the same lead; a lead that is closed or not managed by the user; two leads linked to different customers (`merge_customer_conflict`; fix the link first). Nothing changes on refusal.
- **Telegram routing:** the target takes the source's private chat only when it has none; if both have different private chats, later messages from the source chat open a new lead (known limit).
- Not done: undo of a merge; merge of converted leads; automatic merge.
- Tests: `tests/Feature/Leads/LeadMergeTest.php`.
