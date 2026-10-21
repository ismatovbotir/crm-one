# Spec 03 — Organization, User Realms, Customer/Partner Portal, Telegram, Tenders (Purchase & Transport)

| | |
|---|---|
| Status | Draft. Portal login (email or Telegram OTP) and staff Telegram linking are **decided by the user** (section 15, Q-1, Q-2); other questions have defaults |
| Owner | Product (B2B sales CRM) |
| Date | 2026-10-16 |
| Stack | Laravel 13 (PHP ^8.3), Livewire 4, PHPUnit, `QUEUE_CONNECTION=database`, sessions in DB, app timezone `UTC`, uz/ru/en |
| Depends on | Spec 01 (customers, contacts, `activity_log`, `Money`, roles), Spec 02 (1C: catalog/prices/stock, customer sync pattern, `settings`) |
| Amends | Spec 01 G1/section 3 ("exactly one role" → global role + department roles + user type); Spec 01 roadmap numbering (Leads = 04, Offers = 05, Orders = 06, Agreements = 07) |
| Feeds | 04 Leads, 05 Offers (portal accept), 06 Orders (portal reorder, tender links), 07 Agreements (portal view), 1C purchase-order push (roadmap) |
| Domain skills | `.claude/skills/transport-tender`, `.claude/skills/purchase-tender`, agent `.claude/agents/logistics.md` |

### Repo baseline (verified 2026-10-16)
- `users`: `name`, `email` (unique), `password` (NOT NULL), `role` string(20) default `sales` (index), `is_active`, `locale`, `remember_token`. `sessions` and `password_reset_tokens` tables exist. One guard `web` (session, provider `users` = `App\Models\User`).
- `App\Enums\UserRole` = admin, manager, sales, finance (`label()` via `users.roles.*`). `User::hasRole()`, `isAdmin()`, `scopeActive()`.
- Policies: `UserPolicy` (admin only, explicit, no `Gate::before`), `SettingPolicy::manage` (admin).
- Routes (`routes/web.php`): guest `/login`, `/forgot-password`, `/reset-password/{token}`; `auth` + `EnsureUserIsActive`: `/dashboard`, `/users*`, `/settings/appearance`, `/print/sample`, `/logout`; `POST /locale` for guests and users.
- `Auth::attempt` in `App\Livewire\Auth\Login`; `LogoutController` and `EnsureUserIsActive` hard-code `Auth::guard('web')`; `LocaleController`/`SetLocale` read `$request->user()` (default guard).
- `config/navigation.php` = single sidebar source (`sections`, `can` per item, `children` inherit group `can`, `soon`). `nav.settings_telegram` exists as "soon".
- `App\Documents\DocumentRenderer::print()`, `DocumentLanguage::resolve()`, `HasDocumentLanguage` for printable documents. `TranslationParityTest` enforces uz/ru/en keys.
- No domain tables yet (customers/contacts from Spec 01 not built).

---

## 1. Problem

1. **Customers' employees** need self-service: repeat orders, service requests, status of offers/orders/agreements/invoices, documents in their language. Today everything goes through phone/Telegram chats with sales reps: slow, untracked, error-prone.
2. **Our company has departments** (Sales, Purchase, Import & Transportation, Finance, ...). The current model (one global role: admin/manager/sales/finance) cannot express "logistics specialist", "purchase head" or department-scoped visibility.
3. **Purchase and transportation tenders** are run by e-mail/Excel: missing critical conditions (HS code, Incoterm, who pays customs/terminal, demurrage), late or side-channel bids, comparisons on freight price only, no audit trail. Mistakes cost money after award (demurrage, double payment of charges, uninsured damage).
4. **Telegram is the main business messenger** in Uzbekistan; users want login codes and notifications there, with email as the universal fallback.

## 2. Goals / Non-goals

### Goals
- G1. Two user realms in one `users` table: **internal** (staff) and **portal** (employees of customers, and of suppliers/carriers = partners), separated by guard, layout, routes and policies; no portal user can reach an internal screen or another company's data.
- G2. **Portal login is passwordless**: email or linked Telegram → 6-digit one-time code by email or Telegram bot → verify. Internal staff keep password login, with optional/role-enforced second factor (OTP by email or Telegram).
- G3. Every user (internal and portal) can link/unlink their own Telegram in "My cabinet"; one bot, one webhook, one OTP service; notifications per event and channel with quiet hours.
- G4. Customer company structure: customer → contacts → optional portal user with customer-side roles; invitation and offboarding flows.
- G5. Internal departments with department roles, combined with existing global roles in a central permission map with data scopes (own / department / all). Existing roles and tests keep working.
- G6. One generic **Tender engine** with `type = purchase | transport`: lifecycle, approvals, invitations, Q&A, sealed bids, evaluation matrix, award, PO/transport order, audit; transport tenders enforce the transport-tender checklist via a completeness check.
- G7. Portal features roadmap aligned with sales stages (catalog with own prices, reorder, service requests, offers/orders/agreements/invoices, printable documents).

### Non-goals (this spec)
- Public tenders open to unregistered suppliers, self-registration of companies (invitation only).
- Reverse auctions, two-envelope RFP (roadmap).
- SMS OTP, TOTP authenticator apps, WebAuthn (roadmap; Q-14).
- Telegram chat/support conversations through the bot (bot only links, sends codes and notifications).
- Automatic customs duty calculation; FX rate feeds (manual/CBU rate entry in Phase 1).
- 1C push of purchase orders and pull of supplier counterparties (roadmap, same pattern as Spec 02).
- Impersonation ("view as portal user").
- Payments in the portal.

## 3. Key decisions

| # | Decision | Alternatives rejected | Trade-off |
|---|---|---|---|
| DEC-1 | **Single `users` table with `type` (`internal` \| `portal`)** + two session guards: `web` (internal) and `portal`, each backed by a small `TypedEloquentUserProvider` that adds `where type = ?` to every lookup (by id, remember token, credentials) | Separate `portal_users` table/model | One table keeps `activity_log.causer_id`, notifications, Telegram links, sessions and audit uniform; emails stay globally unique (a person cannot be both staff and portal user with the same email — acceptable). Isolation comes from the typed provider + guard + middleware, tested by route-list tests |
| DEC-2 | Partners (suppliers/carriers) are **portal users** too, distinguished by their membership's organization (customer vs counterparty), same `/portal` entry, different menus | Third realm/guard | Less code; a single person rarely is both; membership-driven abilities keep them apart |
| DEC-3 | Membership table `portal_memberships` (user ↔ customer **or** counterparty, with portal roles). Phase 1 UI allows one active membership per user; the schema allows several (company switcher later) | `users.customer_id` | Avoids a migration when "one user in several companies" arrives |
| DEC-4 | **Contact ≠ user**: contacts exist without login; a portal user is linked to exactly one contact per membership | Users as contacts | Keeps 1C contact sync (Spec 02) independent of logins |
| DEC-5 | **Portal: OTP only, no passwords** (`users.password` becomes nullable; portal users have null). Internal: password + optional/enforced OTP second factor | Passwords for portal | User decision. No password resets/leaks for portal; depends on email/Telegram account security (section 11) |
| DEC-6 | One OTP service (`login_codes`, purpose `portal_login` \| `two_factor`) for both realms, channels `email` \| `telegram` | Separate per realm | Same rules, same tests |
| DEC-7 | `telegram_links` keyed by `user_id` (unique) — no polymorphism needed because both realms share `users`; `chat_id` unique | Polymorphic | Simple FK integrity |
| DEC-8 | Global `UserRole` kept (admin, manager, sales, finance) + **new `staff`**; departments with `DepartmentRole` (head, specialist, viewer) grant module rights. Central **permission map** (`config/permissions.php` + `App\Authorization\PermissionMap`), Policies call it; **no permissions package** (Spec 01 D-6) | spatie/laravel-permission | Static, reviewable, testable as data; revisit only if per-user overrides are needed |
| DEC-9 | `User::hasRole()` returns false for non-internal users (defence if `role` is ever set on a portal row); `role` becomes nullable, default null; model guard forces `role = null` for portal and requires it for internal | | Existing `isAdmin()` checks become safe for portal users |
| DEC-10 | Admin = system administration, **not** business approvals: admin cannot approve tenders/awards or read sealed bids | Admin can do everything | Segregation of duties; Q-11 |
| DEC-11 | Tenders: one engine (`tenders` + lots + type-specific detail tables), sealed bids by **policy** (no internal read of bid content before `opened_at`) + sha256 receipt hash on submit; automatic opening at the deadline by the scheduler | Encryption at rest of bids | Policy sealing is testable; hash proves integrity to bidders; DB admins remain a trust boundary (stated) |
| DEC-12 | **Reversed 2026-10-19, see section 22:** one organizations table with roles; `counterparties` is now the supplier profile of an organization. (Original: counterparties in a separate table; same INN in both) | Merge into `organizations` | No rework of Spec 01/02; 1C sync columns prepared for a later pull of 1C suppliers |
| DEC-13 | Portal URLs use `uuid` route keys; foreign-company access returns **404** (`Response::denyAsNotFound()`) | Integer ids / 403 | Reduces enumeration; authorization is still the real control |

## 4. Users, realms and roles

### 4.1 Realms and authentication

| | Internal (`type = internal`) | Portal (`type = portal`) |
|---|---|---|
| Entry | `/login` (existing) | `/portal/login` |
| Guard / provider | `web` / `internal_users` (typed) | `portal` / `portal_users` (typed) |
| Credentials | email + password; second factor OTP (email/Telegram) if enabled for the user or enforced for the role | identifier (email, or Telegram `@username` / phone linked to Telegram) + 6-digit OTP via email or Telegram |
| Layout | `layouts.app` (sidebar from `config/navigation.php` `sections`) | `layouts.portal` (top bar + `portal_sections`), company logo/name, theme tokens (red/silver/white), language switcher |
| Session | 120 min idle (existing); "Trust this device 30 days" skips the second factor | 120 min idle; "Remember this device" = remember cookie 30 days (setting `portal.remember_days`, 0 disables); "Sign out of all devices" rotates `remember_token`, deletes `sessions` rows |
| Routes | existing group, now `auth:web` + `EnsureUserIsActive` + `EnsureInternalUser` | `/portal/*`: `auth:portal` + `EnsurePortalUser` (sets `Auth::shouldUse('portal')`, loads `PortalContext` = active membership, checks membership active) |

Implementation notes (must be in the first increment):
- Register `EnsurePortalUser`/`EnsureInternalUser` as **Livewire persistent middleware** so `/livewire/update` requests re-apply guard and context.
- `LogoutController`, `EnsureUserIsActive`, `LocaleController`, `SetLocale` must use the guard of the current realm (not hard-coded `web`).
- Route-list test: every route except guest/auth/locale/`/up`/`api/*`/telegram webhook is either internal-guarded or portal-guarded (fails CI if a new route lacks it).
- Both guards share the session cookie; logging out one realm invalidates the session (acceptable, documented). **No simultaneous staff + portal session (security audit):** signing in to one realm first logs out the other guard, invalidates and regenerates the session; a request that finds both guards authenticated is logged out of both (test).

### 4.2 Portal login flow (email or Telegram OTP)

```
/portal/login: [identifier] + channel (o) Email  (o) Telegram   [Send code]
   → always: "If an account exists for this identifier and channel, we sent a 6-digit code." (generic)
   → [code ______] [Verify]  (resend available after 60 s)  [use the other channel]
   → success: session (+ remember device if ticked), audit, redirect /portal
```
- Identifier resolution: contains `@` and a dot domain → `users.email` (lowercased); starts with `@` → `telegram_links.username`; digits/`+` → `telegram_links.phone` (verified via Telegram contact share, E.164). Only `type = portal`, `is_active`, with ≥ 1 active membership.
- Channel `telegram` requires an active link (`blocked_at` null). If the user has no usable channel, nothing is sent and the generic message is shown (no enumeration). If Telegram delivery fails (bot blocked / chat not found), the link is marked blocked and **the code is not auto-sent by email** (the user chooses "use email instead"; avoids channel-confusion attacks).
- Sending is queued (constant response time).

### 4.3 OTP rules (`App\Services\Auth\OneTimeCodeService`)

| Rule | Value |
|---|---|
| Code | 6 digits from `random_int(0, 999999)`, zero-padded |
| Storage | `code_hash = hash_hmac('sha256', code . '|' . login_code_id, app key)`; plain code never stored, logged, or put in exceptions/queue payloads (notification receives it in memory only; mail/Telegram payload not persisted in `jobs` → send via `ShouldQueue` notification that stores only `login_code_id` and an encrypted code) |
| Expiry | 10 min (setting `auth.otp_ttl_minutes`, 5..10) |
| Use | single use (`consumed_at`); issuing a new code for the same user+purpose invalidates previous active codes |
| Attempts | max 5 wrong verifications per code → code invalidated; comparison with `hash_equals` |
| Resend cooldown | 60 s per user+purpose |
| Rate limits | send: 5/hour per user, 10/hour per identifier string, 20/hour per IP, per channel; verify: 10/min per IP **and** 10 per 15 min per identifier/user across all codes and IPs (stops distributed guessing; reaching it invalidates active codes and raises `otp_locked`); internal 2FA same limits |
| Messages | uz/ru/en per user `locale`: code, validity, "never share this code; staff never ask for it", app domain; Telegram message also has no clickable login link |
| Audit | `auth_events`: `otp_requested` (channel, no code), `otp_sent`/`otp_send_failed`, `otp_verified`, `otp_failed`, `otp_locked`, `login`, `logout` with IP, user agent |
| Dev | mail `log` driver allowed only outside production; a test asserts `config('mail.default') !== 'log'` in production config checks |

### 4.4 Internal second factor (2FA)
- Password login unchanged; then, if `users.two_factor_channel` is set **or** the user's role is in setting `security.2fa_required_roles` (default `["admin","finance"]`), a 6-digit OTP (purpose `two_factor`) is sent by the user's preferred channel (Telegram if linked and not blocked, else email).
- "Trust this device for 30 days" → `trusted_devices` row + cookie holding a random 256-bit token; only `hash('sha256', token)` is stored (lookup by hash, `hash_equals`), cookie `HttpOnly`, `Secure`, `SameSite=Lax`, bound to the user id; revocable in My cabinet; admin can revoke all for a user. **All of a user's trusted devices are revoked on password change or reset, deactivation and "Sign out of all devices"** (security audit).
- Admin changes to `2fa_required_roles` are audited. Enforcement starts at next login.
- Password reset for internal users stays (broker `users`); portal has no password broker.

### 4.5 Telegram linking ("My cabinet" for both realms)
Mechanism (same for internal and portal users; user must be authenticated, so no extra email proof):
1. My cabinet → Telegram → **Link**: creates `telegram_link_tokens` row (random 32 chars, stored as sha256, expires 10 min, single use, bound to `user_id`) and shows deep link `https://t.me/<bot_username>?start=<token>` + QR code.
2. User opens it; bot receives `/start <token>` (webhook). Valid → upsert `telegram_links` (user_id, chat_id, tg user id, username, first name, `linked_at`), consume token, reply "Linked to <CRM name> as <user name>" in the user's locale. Invalid/expired → generic "Link expired, request a new one in the CRM".
3. Optional "Share phone" button (bot keyboard `request_contact`): stored as `phone` only if `contact.user_id == from.id` (own number); enables login by phone for portal users.
4. **Re-link** replaces the previous chat for that user. **One chat → one user**: if the chat is already linked to another user, linking fails with a clear message in bot and cabinet ("This Telegram account is already linked to another CRM user; unlink it there or ask an administrator").
5. **Unlink**: user in cabinet, or `/stop` in the bot, or admin force-unlink (Users page / portal users list). Audited.
6. Bot blocked (`my_chat_member` status `kicked`, or API 403 "bot was blocked") / chat not found → `blocked_at` set, warning in cabinet, notifications fall back to email, Telegram OTP channel unavailable until the user presses Start again (clears `blocked_at`).
7. Portal invitation acceptance page offers the same Link step right after the email-proven acceptance (section 4.7).
- Cabinet shows status: linked @username / since / blocked warning / not linked; never chat contents (we do not store messages).

### 4.6 Telegram bot infrastructure
- `App\Services\Telegram\TelegramClient` = the only caller of `api.telegram.org` (timeouts 5/10 s, retries on 5xx/connection, honours 429 `retry_after`, typed errors `BotBlocked`, `ChatNotFound`, `RateLimited`, `Unauthorized`), logs metadata only (method, status, duration; no text, no codes).
- Webhook `POST /telegram/webhook` (outside session/CSRF, `throttle:telegram-webhook`): rejects unless header `X-Telegram-Bot-Api-Secret-Token` equals the stored secret (`hash_equals`), 503 when bot disabled; dedupes by `update_id` (`telegram_updates` table, pruned after 7 days); responds 200 immediately and processes in a queued job. Handled: `/start <token>`, `/start` (help), `/stop`, `/help`, contact share, `my_chat_member`.
- **Settings > Telegram bot** (admin, `SettingPolicy::manage`): enable/disable, bot token (encrypted with `Crypt`, write-only "set/not set"), bot username (filled by `getMe` on "Test"), webhook secret (generated), "Set webhook" (`setWebhook` with `secret_token`, allowed updates), "Webhook status" (`getWebhookInfo`: pending count, last error), "Send test message to my Telegram". Changes audited (`telegram_settings_changed`, secrets as `"changed"`).
- `config/navigation.php` plan: change `['label' => 'nav.settings_telegram', 'soon' => true]` to `['label' => 'nav.settings_telegram', 'route' => 'settings.telegram']`; add `nav.settings_security` (2FA role enforcement, OTP TTL, portal remember days) and `nav.settings_notifications` (route, replacing "soon").
- Message templates: `lang/{uz,ru,en}/telegram.php` (bot replies, OTP, each notification event), rendered in the recipient's `locale` (fallback `uz`), HTML parse mode with escaping.

### 4.7 Invitation and offboarding
| Step | Rule |
|---|---|
| Who invites | Customer users: admin, manager, customer owner (sales), customer's Portal Admin (own company). Partner users: admin, owner of a tender/counterparty in Purchase/Logistics (specialist+), partner Portal Admin. Internal users: admin (existing form; invitation optional) |
| Prerequisite | A contact with an email (customer contact or counterparty contact); one active invitation per email+organization |
| Invitation | `user_invitations` row: email, organization, contact, roles, token sha256, expires 72 h (setting), invited_by. Email in the contact's/organization's preferred locale with link `/portal/invitations/{token}` |
| Accept | Link opens (proves email control) → shows company, inviter, roles → confirm name, accept terms → user created (`type = portal`, no password) or, if a portal user with that email exists, membership added → logged in → offered "Link Telegram" |
| Resend / revoke | Resend = new token, old revoked; max 3/day; revoke anytime before acceptance |
| Expired/used/revoked link | Generic page "Link is not valid; ask your administrator for a new invitation" |
| Deactivate membership | Portal Admin (own company), customer owner, manager, admin. If no active membership remains → `users.is_active = false`; sessions deleted; remember token rotated; Telegram link kept but no notifications. Records created by the user remain attributed |
| Last Portal Admin | Cannot be deactivated/demoted by a portal user (must remain ≥ 1 while company has active users); internal manager/admin can |
| Internal offboarding | Existing deactivate; plus list of owned open records (customers, tenders, service requests) to reassign (manager), sessions deleted, trusted devices revoked |

### 4.8 Customer-side (portal) roles — `CustomerPortalRole`
`portal_admin`, `buyer`, `approver`, `viewer`, `finance`. A membership has ≥ 1 role (json array). Partner roles — `PartnerPortalRole`: `portal_admin`, `bidder`, `viewer` (carriers: bidder also updates shipment milestones).

| Action (own company only) | portal_admin | buyer | approver | viewer | finance |
|---|---|---|---|---|---|
| View catalog with own prices and stock bands | yes | yes | yes | yes | yes |
| Create/edit order request, use templates, reorder | yes | yes | yes | no | no |
| Submit order request ≤ company threshold (or no threshold) | yes | yes | yes | no | no |
| Approve order request above threshold (up to own `approval_limit`) | yes | no | yes | no | no |
| Accept offer ≤ threshold / above threshold | yes / yes | yes / no | yes / yes (limit) | no | no |
| View offers, orders, statuses | yes | yes | yes | yes | yes |
| View/download invoices, agreements, payment status | yes | no | yes | no | yes |
| Create service request, comment, attach | yes | yes | yes | no | yes |
| Manage colleagues (invite, roles, deactivate) | yes | no | no | no | no |
| Notification preferences, own Telegram link | yes (own) | yes | yes | yes | yes |

### 4.9 Internal departments, roles and scopes
Departments (seeded, editable names): `sales`, `purchase`, `logistics` (Import & Transportation), `finance`, `management`, `it` (Admin/IT), `support`. Each has members with `DepartmentRole` = `head` (one per department), `specialist`, `viewer`; a user may belong to several departments, one primary.

Global role (`users.role`) after this spec:
| Role | Meaning | Change |
|---|---|---|
| admin | system administration (users, settings, integrations) | unchanged; loses business approvals (DEC-10) only for new modules |
| manager | management: cross-department view/approve | unchanged |
| sales | sales rep | unchanged; backfill: member `sales/specialist` |
| finance | finance | unchanged; backfill: member `finance/specialist` |
| staff | **new**: no global rights; rights come from department membership | additive; label key `users.roles.staff` |

Backfill migration (non-breaking): sales → sales/specialist, finance → finance/specialist, manager → management/head (first) or specialist, admin → it/head. Existing policies and tests stay; new modules ask the permission map.

Permission map (excerpt; `config/permissions.php`, ability → grants with scope):
```php
'tenders.transport.view'    => ['dept:logistics:*' => 'all', 'role:manager' => 'all', 'role:finance' => 'all',
                                'dept:purchase:*' => 'linked', 'role:sales' => 'linked_own_orders', 'role:admin' => 'all_metadata'],
'tenders.transport.create'  => ['dept:logistics:specialist' => 'own', 'dept:logistics:head' => 'department'],
'tenders.transport.request' => ['role:sales' => 'own', 'dept:purchase:*' => 'own', 'dept:logistics:*' => 'own'],
'tenders.purchase.create'   => ['dept:purchase:specialist' => 'own', 'dept:purchase:head' => 'department'],
'tenders.approve'           => ['dept:{tender_dept}:head' => 'department', 'role:manager' => 'all'],
'counterparties.qualify'    => ['dept:purchase:head' => 'all', 'dept:logistics:head' => 'all'],
'service_requests.handle'   => ['dept:support:*' => 'department', 'role:sales' => 'own_customers', 'role:manager' => 'all'],
```
`PermissionMap::scopeFor(User $user, string $ability): ?Scope` returns the widest granted scope (`own` < `linked` < `department` < `all`); Policies combine it with record facts (owner_id, department_id, links). Each list query uses the matching `scopeVisibleTo($user)`. Portal users never match internal grants (DEC-9).

### 4.10 Internal permission matrix (new modules)
| Action | admin | manager | Purchase head / spec. | Logistics head / spec. | sales | finance | dept viewer |
|---|---|---|---|---|---|---|---|
| Departments & memberships CRUD | yes | view | view own | view own | no | no | no |
| Customer portal users: invite/deactivate | yes | yes | no | no | own customers | no | no |
| Counterparties view | yes | yes | yes | yes | no | yes | yes (own dept) |
| Counterparty create/edit / qualify-suspend | yes/no | yes/yes | yes/head | yes/head | no | no | no |
| Partner portal users invite | yes | yes | yes | yes | no | no | no |
| Transport request (draft) | no | yes | yes | yes | yes (own orders) | no | no |
| Purchase tender create/edit draft | no | yes | head: dept / spec.: own | no | no | no | no |
| Transport tender create/edit draft | no | yes | no | head: dept / spec.: own | no | no | no |
| Submit for approval / publish / invite / Q&A / addenda | no | yes | own dept | own dept | no | no | no |
| Approve publish ≤ T1 / > T1 | no | yes / yes | head / no | head / no | no | no | no |
| View bid metadata before opening (count, times) | yes | yes | own dept | own dept | no | yes | no |
| View bid content after opening | no | yes | own dept | own dept | no | yes | own dept |
| Score bids (committee member) | no | yes | if member | if member | no | if member | no |
| Propose award | no | yes | owner | owner | no | no | no |
| Approve award ≤ T1 / ≤ T2 / > T2 | no | yes | head / head+manager / +finance | same | no | co-approver > T2 | no |
| Cancel tender (before / after publish) | no | yes | owner / head | owner / head | no | no | no |
| Set evaluation FX rates | no | yes | no | no | no | yes | no |
| Service requests (handle) | no | yes | no | no | own customers | invoice-type | support dept |
| Users page: Telegram status, force-unlink, revoke trusted devices | yes | no | no | no | no | no | no |
| Own Telegram link, notification prefs, trusted devices | own | own | own | own | own | own | own |

Thresholds (setting `tenders.approval_thresholds`, evaluation currency UZS): T1 = 100 000 000 UZS, T2 = 1 000 000 000 UZS (Q-4).

## 5. User stories
Realms, auth, Telegram
- US-1 As a customer's buyer I log in at /portal with my email or Telegram and a 6-digit code, without remembering a password.
- US-2 As any user I link my Telegram in My cabinet by opening a deep link, and can unlink it.
- US-3 As a finance user, my login requires a second factor (role enforced); I get the code in Telegram.
- US-4 As a staff member I choose which events reach me by Telegram/email/in-app and set quiet hours.
- US-5 As an admin I see who has Telegram linked (not chats) and can force-unlink a lost account.
- US-6 As a Portal Admin I invite colleagues by email, give roles and deactivate leavers.
Departments
- US-7 As an admin I create departments, assign a head and members, so module rights follow the org chart.
- US-8 As a logistics specialist I see all transport tenders; as a sales rep I see transport tenders linked to my customers' orders (status/ETA only).
Portal
- US-9 As a buyer I reorder from a past order or template at today's prices and submit a request; as an approver I approve requests above the company threshold.
- US-10 As a customer user I open a service request with type, priority, attachments and follow its status and SLA.
- US-11 As a customer approver I accept an offer and download it in Russian or Uzbek.
Tenders
- US-12 As a sales rep I raise a transport request from my customer's order; logistics turns it into a tender.
- US-13 As a logistics specialist I cannot publish a transport tender that lacks the HS code, Incoterm or customs responsibility; I see a checklist of what is missing.
- US-14 As a carrier I receive an invitation, ask questions, and submit a sealed bid with a cost breakdown before the deadline.
- US-15 As an evaluation committee member I compare bids on total landed cost and weighted score, then propose the award.
- US-16 As a purchase head I approve an award and the PO is created from the winning bid.

## 6. Workflow impact

### 6.1 `TenderStatus` (both types)
| From | To | Trigger / guard | Actor |
|---|---|---|---|
| draft | pending_approval | completeness: 0 blockers, warnings acknowledged with comment | owner |
| pending_approval | approved / draft | approve / reject (comment required) | approver per threshold |
| approved | published | publish (≥ 1 invitee, deadlines in future, min period) | owner |
| published | published | addendum (version + 1, notifies invitees, extends `bids_due_at` if < 48 h left) | owner |
| published | closed | `bids_due_at` reached (scheduler, idempotent); opens bids | system |
| closed | evaluation | ≥ 1 valid bid | system/owner |
| closed / evaluation | cancelled | no valid bids / cancel (reason) | owner/head |
| evaluation | award_pending_approval | award proposal per lot with justification if not rank 1 | owner |
| award_pending_approval | awarded / evaluation | approve chain complete / reject | approvers |
| awarded | contracted | winner accepted in portal + PO/transport order created | winner, owner |
| awarded | evaluation | winner declined or no answer in 24 h → next rank | winner/system |
| contracted | in_execution | first delivery/shipment started | system |
| in_execution | completed | all lots delivered & closed (claims may stay open) | owner |
| draft/pending_approval/approved/published | cancelled | reason; after publish head approval + notice to invitees | owner/head |

Every transition: `status_transitions` row (from, to, user, reason, at) + `activity_log`. Re-tender creates a new draft (`parent_tender_id`, `round + 1`).

### 6.2 Other state machines
- `BidStatus`: draft → submitted → (revised → new version; previous `superseded`) → withdrawn (before deadline) | opened → disqualified (reason) | shortlisted → awarded | not_awarded.
- `OrderRequestStatus` (portal): draft → pending_approval (above threshold) → submitted → converted (offer created) | rejected (reason) | cancelled.
- `ServiceRequestStatus`: new → triaged → in_progress → waiting_customer → resolved → closed; resolved → reopened (≤ 14 days); any open → cancelled.
- `ShipmentStatus` (transport execution): planned → vehicle_assigned → loading → in_transit → at_border → customs → delivered → closed; exception flag; cancelled.
- `CounterpartyStatus`: prospect → pending_qualification → qualified → suspended | blacklisted; qualified → pending_qualification on document expiry.

### 6.3 Handoffs
- Customer order → transport request: `tender_sources` (order ids), prefilled destination, delivery promise date, items/HS from order lines (Spec 06).
- Award → PO (purchase) / transport order: snapshot of bid lines, currency, terms, free time, rates; `tender_awards.id` kept on the PO/shipment. Idempotent: one award per lot → one PO/transport order (unique FK).
- Portal order request → Offer (Spec 05, `offers.order_request_id` unique) → accepted in portal → Order (Spec 06). Reorder re-prices at today's customer price list (never copies old prices); shows difference vs last order.

## 7. Data model
Conventions from Spec 01 (money `*_minor` + `currency`, decimals as strings, string enums, soft deletes for business records, `restrictOnDelete`). All business tables have `uuid` (route key for portal).

### 7.1 Users, auth, Telegram
| Table | Columns (new/changed) | Indexes / notes |
|---|---|---|
| `users` (alter) | `type` string(10) default `internal`; `role` → nullable, default null; `password` → nullable; `phone` string(32) null; `position` string(120) null; `two_factor_channel` string(10) null (`email`/`telegram`); `last_login_at`; `deactivated_at`, `deactivated_by` FK null; `quiet_hours_start`/`_end` time null; `timezone` string(40) default `Asia/Tashkent` | index (`type`, `is_active`); backfill `type = internal` |
| `login_codes` (the requested `portal_login_codes`, generalised) | `user_id` FK cascade, `purpose` (`portal_login`/`two_factor`), `channel`, `code_hash` char(64), `expires_at`, `attempts` tinyint, `consumed_at`, `invalidated_at`, `ip`, `user_agent` | (`user_id`, `purpose`, `consumed_at`); pruned after 7 days |
| `telegram_links` | `user_id` FK **unique**, `chat_id` bigint **unique**, `telegram_user_id` bigint, `username` null (index), `first_name`, `phone` null (index, E.164, verified), `linked_at`, `blocked_at` null, `unlinked_by` null | one user ↔ one chat |
| `telegram_link_tokens` | `user_id` FK, `token_hash` char(64) unique, `expires_at`, `consumed_at` | 10 min, single use |
| `telegram_updates` | `update_id` bigint PK, `received_at` | dedupe, pruned 7 days |
| `trusted_devices` | `user_id`, `token_hash` unique, `name` (UA summary), `ip`, `last_used_at`, `expires_at`, `revoked_at` | internal 2FA only |
| `user_invitations` | `uuid`, `email`, `customer_id` null / `counterparty_id` null (exactly one), `contact_id`, `roles` json, `token_hash` unique, `invited_by`, `expires_at`, `accepted_at`, `accepted_user_id`, `revoked_at`, `resend_count` | unique active per (email, org) app-enforced |
| `auth_events` | `user_id` null, `guard`, `event`, `channel` null, `ip`, `user_agent`, `identifier_hash` null (sha256 of identifier for failures), `created_at` | append-only, 1-year retention |
| `notification_preferences` | `user_id`, `event` string(60), `channel` (`mail`/`telegram`/`database`), `enabled` | unique (user, event, channel); defaults from config when no row |
| `notifications` | Laravel default (in-app) | |

### 7.2 Organization
| Table | Columns | Notes |
|---|---|---|
| `departments` | `code` unique, `name` json {uz,ru,en} (seeded ones use `departments.names.<code>` fallback), `parent_id` null, `is_active`, `sort_order` | soft deletes |
| `department_user` | `department_id`, `user_id` (internal only), `role` (`DepartmentRole`), `is_primary` | unique (dept, user); one `head` per dept and one primary per user (app-enforced in transaction) |
| `portal_memberships` | `uuid`, `user_id` (portal only), `customer_id` null / `counterparty_id` null (exactly one), `contact_id` / `counterparty_contact_id`, `roles` json, `approval_limit_minor` null + currency of company, `is_active`, `invited_by`, `activated_at`, `deactivated_at`, `deactivated_by` | unique (user, customer) and (user, counterparty); Phase 1: max 1 active per user |
| `customers` (alter, Spec 01) | `portal_enabled` bool default false, `portal_approval_threshold_minor` null, `preferred_locale` (already required by Spec 01 docs rule) | |
| `counterparties` | `uuid`, `name`, `legal_name`, `tax_id` (index), `country`, `city`, `address`, `phone`, `email`, `website`, `is_supplier`, `is_carrier`, `status` (`CounterpartyStatus`), `rating` decimal(4,2) null, `fleet_size` null, `vehicle_types` json, `lanes` json, `has_tir`, `has_adr`, `preferred_locale`, `owner_id`, `department_id`, `notes`, Spec 02 sync columns (null until 1C pull) | soft deletes; implements `HasDocumentLanguage` |
| `counterparty_contacts` | like `contacts` | |
| `counterparty_documents` | `counterparty_id`, `type` (registration, license, cmr_insurance, cargo_insurance, adr, tir, iso, dealer_authorization, bank_reference, other), `number`, `valid_until` null, `attachment_id`, `verified_by`, `verified_at` | expiry → status back to pending_qualification (scheduler, warning 30 days before) |
| `counterparty_ratings` | `counterparty_id`, `subject` (tender/shipment/PO morph), `on_time` bool, `quality` 1-5, `communication` 1-5, `documents` 1-5, `rated_by`, `comment` | rating = rolling average 12 months |

### 7.3 Tenders
| Table | Columns | Indexes / rules |
|---|---|---|
| `tenders` | `uuid`, `number` (PT-/TT-YYYY-NNNN via `number_sequences`), `type`, `status`, `method` (`rfq`/`rfp`/`single_source`; `auction` later), `kind` (`spot`/`lane_contract`/`framework`), `title`, `description`, `department_id`, `owner_id`, `requested_by`, `notice_locale`, `evaluation_currency`, `accepted_currencies` json, `fx_rates` json null (frozen at close, set by finance), `budget_minor` null + `budget_currency` (internal), `incumbent_counterparty_id` null, `incumbent_disclosed` bool, `payment_terms` text, `payment_days` null, `prepayment_bp` null, `bid_validity_days`, `published_at`, `clarification_deadline_at`, `bids_due_at`, `opened_at`, `awarded_at`, `cancelled_at`, `cancel_reason`, `round`, `parent_tender_id`, `version` (addenda), `confidential` bool, `completeness` json (last check), `acknowledged_warnings` json, soft deletes (draft only) | (`type`, `status`), (`department_id`), (`owner_id`), (`bids_due_at`) |
| `tender_sources` | `tender_id`, `source_type` (`order`/`purchase_order`/`customer`), `source_id` | FK added when Spec 06 ships |
| `tender_lots` | `tender_id`, `number`, `title`, `description` | unique (tender, number) |
| `tender_items` (purchase) | `lot_id`, `item_id` null (1C catalog), `sku`, `name`, `unit_code`, `unit_precision` (snapshot), `quantity` decimal(15,3), `specification` text, `alternatives_allowed`, `required_by` date, `hs_code` null, `target_price_minor` null (internal) | |
| `tender_transport_specs` (1:1 lot) | **route**: `origin_country/city/address/postcode/lat/lng`, `destination_*` same, `border_crossing` string null + `border_policy` (`required`/`preferred`/`carrier_choice`), `clearance_place`, `avoid_countries` json, `sanctions_reviewed` bool; **dates**: `readiness_date`, `pickup_from/to`, `delivery_from/to`, `max_transit_days`; **mode**: `mode`, `load_type`, `vehicle_type`, `units_count`, `transshipment_allowed` bool **nullable = unanswered**, `partial_allowed` bool nullable; **cargo**: `description`, `hs_codes` json, `gross_kg`, `net_kg`, `volume_m3` decimal(12,3), `packages_count`, `package_type`, `pallets_count`, `pallet_type`, `dimensions` json, `stackable` nullable, `cargo_value_minor` + `cargo_currency`, `is_dangerous` nullable, `un_number`, `adr_class`, `packing_group`, `is_temperature_controlled` nullable, `temp_min_c`/`temp_max_c` decimal(4,1), `is_oversize` nullable, `oversize_notes`, `special_handling`; **handling**: `loading_method`, `loading_equipment`, `loaded_by`, `unloading_method`, `unloading_equipment`, `unloaded_by`, `free_loading_hours`, `free_customs_hours`, `free_unloading_hours`, `container_owner` (`coc`/`soc`) null, `container_free_days`, `container_return_location`; **responsibility**: `incoterm`, `incoterm_place`, `export_clearance_by`, `import_clearance_by`, `importer_of_record`, `charges_payer` json (duties, vat, customs_fees, brokerage, storage, terminal, port, inspection → party), `insurance_by`, `insured_value_minor`, `insurance_cover`; **docs**: `required_documents` json (doc → issuer), `driver_vehicle_documents` json, `tracking_requirement`; **SLA**: `penalties` text, `late_penalty_bp_per_day`, `penalty_cap_bp`, `fuel_adjustment` text (lane), `force_majeure` text; **contacts after award**: `site_contacts` json | field list = transport-tender skill section 2 |
| `tender_criteria` | `tender_id`, `code`, `label` null, `weight_bp` (sum 10000), `method` (`auto_lowest_cost`/`auto_fastest`/`auto_payment`/`rating`/`manual`) | locked after publish |
| `tender_requirements` | `tender_id`, `text`, `is_knockout` | |
| `tender_committee` | `tender_id`, `user_id`, `role` (`chair`/`member`), `coi_declared_at` | ≥ 2 members above T1 |
| `tender_invitations` | `tender_id`, `counterparty_id`, `invited_by`, `invited_at`, `first_viewed_at`, `declined_at`, `decline_reason`, `notice_locale` | unique (tender, counterparty) |
| `tender_questions` | `tender_id`, `counterparty_id`, `asked_by`, `question`, `answer`, `answered_by`, `answered_at`, `published_at` | answers published anonymised to all |
| `tender_addenda` | `tender_id`, `version`, `summary`, `changes` json, `new_bids_due_at` null, `created_by` | |
| `tender_bids` | `uuid`, `tender_id`, `counterparty_id`, `version`, `status`, `currency`, `valid_until`, `submitted_by`, `submitted_at`, `payload_hash` char(64), `notes`, `entered_by_internal_id` null (bid keyed in from an emailed quote, attachment required, logged) | unique (tender, counterparty, version) |
| `tender_bid_lots` | `bid_id`, `lot_id`, `total_minor`, `transit_days`, `lead_time_days`, `route`, `border_crossing`, `vehicle_offered`, `downtime_rate_minor_per_day`, `free_hours_accepted` json, `payment_terms_offered`, `subcontracting` bool, `exceptions` text, computed: `landed_cost_minor` (evaluation currency), `score` decimal(6,3), `rank` | |
| `tender_bid_lines` | `bid_lot_id`, `tender_item_id` null (purchase) **or** `charge_code` null (transport: freight, fuel, border, terminal, brokerage, insurance, documents, other), `basis`, `quantity`, `unit_price_minor`, `amount_minor`, `inclusion` (`included`/`not_included`/`na`), `estimate_minor` null (owner's estimate for not_included), offered brand/model, `compliant` bool | totals server-side |
| `tender_scores` | `bid_lot_id`, `criterion_id`, `user_id`, `score` decimal(4,2) 0-10, `comment` | unique (bid_lot, criterion, user) |
| `tender_awards` | `tender_id`, `lot_id`, `bid_id`, `counterparty_id`, `amount_minor`, `currency`, `justification`, `status` (`proposed`/`approved`/`accepted`/`declined`), approvals json (user, level, at), `accepted_at`, `po_id` / `shipment_id` null | unique (lot) where status in (approved, accepted) |
| `purchase_orders`, `purchase_order_lines` | `number` PO-, `tender_award_id` unique, `counterparty_id`, snapshot lines, `currency`, totals, `incoterm`, `delivery_*`, `status`, `onec_number` null (manual in Phase 1), sync columns (roadmap) | |
| `shipments`, `shipment_events`, `shipment_claims` | shipment: `number` SH-, `tender_award_id` null, `carrier_id`, status, vehicle/trailer plates, driver (name, phone), container no., CMR/SMGS/BL/AWB no., planned/actual dates, downtime hours; events: code, occurred_at, location, note, reported_by (internal/partner); claims: type, amount, currency, notified_at, time_bar_at, status | Phase T-5 |
| `attachments` (shared) | morph `attachable`, `disk` (private), `path` (random), `original_name`, `mime`, `size`, `sha256`, `uploaded_by`, `visibility` (`internal`/`invitees`/`winner`/`company`), `scan_status` | |

### 7.4 Portal
| Table | Columns |
|---|---|
| `order_requests` + `order_request_lines` | `uuid`, number RQ-, `customer_id`, `membership_id`, `requested_by`, status, currency, `price_list_id`, indicative totals, `comment`, `delivery_address`, `wanted_date`, `approved_by`, `offer_id` null unique; lines: `item_id`, snapshot (sku, name, unit, precision), `quantity`, indicative `unit_price_minor`, `price_source` |
| `order_templates` + lines | `customer_id`, `name`, `created_by`, lines (`item_id`, `quantity`) |
| `service_requests` | `uuid`, number SR-, `customer_id`, `requested_by`, `contact_id`, `type` (technical_support, warranty, delivery_issue, documents, complaint, other), `priority` (low/normal/high/urgent), `subject`, `description`, `order_id` null, `item_id` null, `status`, `assignee_id`, `department_id`, `first_response_due_at`, `resolve_due_at`, `first_responded_at`, `resolved_at`, `closed_at` |
| `service_request_comments` | `service_request_id`, `user_id`, `body`, `is_internal` (never shown in portal) |

SLA defaults (setting `service.sla`): urgent 1 h / 8 h, high 4 h / 24 h, normal 8 h / 72 h, low 24 h / 120 h (business hours Asia/Tashkent, Mon–Fri 09–18; Q-9).

### 7.5 Migration order
users alter → departments → department_user (+ backfill) → auth tables (login_codes, auth_events, trusted_devices, user_invitations) → telegram_* → notification_preferences/notifications → (Spec 01 customers/contacts) → portal_memberships → counterparties (+ contacts, documents, ratings) → attachments → number_sequences/status_transitions (pulled forward from Leads if not yet built) → tenders … tender_awards → purchase_orders → shipments → order_requests/templates → service_requests. All reversible; no production data yet.

## 8. Business rules

Auth & realms
- BR-A.1 A portal user can authenticate only on `portal`, an internal user only on `web` (typed providers). `hasRole()` is false for portal users.
- BR-A.2 OTP rules of 4.3; generic responses for unknown identifier, inactive user, missing channel, rate limit hit (except explicit "wait N s" after a successful send).
- BR-A.3 Membership deactivated or company `portal_enabled = false` → next request logs the portal user out with a translated message.
- BR-A.4 2FA enforcement from `security.2fa_required_roles`; trusted devices skip it for 30 days; password change or admin revoke clears trusted devices.
- BR-A.5 Telegram: `chat_id` unique; link token 10 min single use; blocked → fallback to email for notifications; OTP via Telegram never auto-falls back.
- BR-A.6 Notifications respect `notification_preferences`; quiet hours delay `mail`/`telegram` for non-urgent events (security events and OTP ignore quiet hours); in-app always.

Organization
- BR-O.1 Exactly one head per department; a user has one primary department; portal users cannot be department members.
- BR-O.2 Effective scope = widest grant from global role and memberships (4.9). Removing a membership takes effect on the next request.
- BR-O.3 A portal membership references exactly one of customer/counterparty and a contact of that organization.

Tenders
- BR-T.1 Numbering per type and year; never reused.
- BR-T.2 Minimum period publish → bids due: 3 working days (1 for `urgent` with justification + head approval). `clarification_deadline_at ≤ bids_due_at − 24 h`.
- BR-T.3 After publish, changes only through an addendum; if < 48 h remain, `bids_due_at` extends to now + 48 h; criteria/weights cannot change after publish.
- BR-T.4 Bids: submit/revise/withdraw only while `now < bids_due_at` (checked in a transaction with row lock, server time); late attempts rejected and logged. Each submission stores `payload_hash` (sha256 of canonical JSON) and sends a receipt.
- BR-T.5 Sealing: before `opened_at` internal users see only invitee list, viewed/declined flags, bid submitted yes/no and time; no amounts, attachments or line data (policy + query layer; tested). Bidders see only their own bids, never others' identities or prices, ever.
- BR-T.6 Completeness check (`TenderCompletenessChecker`): codes N1–N24 (transport) and P1–P10 (purchase) from the skills, each `blocker` or `warning`; submit-for-approval requires 0 blockers and every warning acknowledged with a comment; re-run at publish.
- BR-T.7 Min 3 invitees (warning; blocker above T1 unless method `single_source` with justification + manager approval).
- BR-T.8 Landed cost per bid lot: transport-tender skill section 5 formula; FX from `tenders.fx_rates` frozen at close (finance enters CBU rate of closing date; default); `not_included` components use owner estimate flagged as estimate; computed with BCMath, rounded half-up to minor units once per component.
- BR-T.9 Score = Σ(criterion weight × score)/10000; auto criteria computed; manual = average of committee scores; committee members must declare no conflict of interest before seeing bids.
- BR-T.10 Award per lot; non-rank-1 award requires justification and next approval level; approval chain by amount (T1/T2). Winner must accept within 24 h (setting) else next rank may be proposed.
- BR-T.11 Award → exactly one PO (purchase) or transport order/shipment (transport) per lot (unique FK, idempotent action).
- BR-T.12 Internal-only fields (budget, target prices, incumbent rate, internal comments, internal attachments) never rendered in partner portal or printed notice (DTO whitelist for portal/notice; test).
- BR-T.13 Deadlines displayed with timezone label (Asia/Tashkent, UTC+5) everywhere; stored UTC.
- BR-T.14 Bid on behalf: internal user may enter a bid received by email only before deadline, with the original attachment, flagged `entered_by_internal_id`, visible in the comparison.

Portal
- BR-P.1 Catalog prices = `PriceResolver` for the customer (Spec 01/02); stock shown as bands (in stock / limited / on request; thresholds per setting), never raw quantities (Q-8).
- BR-P.2 Order request totals are indicative; final prices come with the Offer.
- BR-P.3 Order request above `portal_approval_threshold_minor` needs an approver with sufficient `approval_limit`.
- BR-P.4 Accepting an offer in the portal records user, membership, IP, time, offer version hash; only `sent` and not expired offers. (Spec 05: "sent" = open statuses `sent`, `viewed`, `partially_selected`; every required alternative/option group must have a selection; the threshold compares the total of **selected** options. Choosing options without accepting follows the same roles; `viewer` cannot choose.)
- BR-P.5 Documents printed through `DocumentRenderer::print()` with `DocumentLanguage::resolve(?lang, customer)`; portal picker limited to uz/ru/en.

## 9. UI / screens
Internal (Livewire, `layouts.app`):
| Screen | Notes |
|---|---|
| My cabinet (`/profile`) | name, locale, Telegram (link/unlink/status/blocked warning, QR), 2FA channel, trusted devices, notification preferences grid (event × channel) and quiet hours, sign out other sessions |
| Users (admin, existing) | + type filter (internal/portal), departments, Telegram status column, force-unlink, revoke trusted devices, 2FA status |
| Departments (admin) | list, form (names uz/ru/en, head, members with role/primary) |
| Customer show → Portal access tab | portal enabled, threshold, members (contact, roles, status, last login, Telegram linked y/n), invite, resend/revoke, deactivate |
| Counterparties index/show | supplier/carrier flags, status, documents with expiry badges, ratings, contacts, portal users, tender history |
| Tenders > Purchase / Transportation index | number, title, status, owner, deadlines (UTC+5), bids count, lots; filters type/status/owner/department/linked order |
| Tender form (wizard) | Header → Lots (purchase items or transport spec sections: Route, Dates, Mode & equipment, Cargo, Handling, Responsibility, Documents, SLA) → Criteria → Invitees → Review. Right-side **Completeness panel**: blockers (red) and warnings (silver) with N/P codes, links to fields, acknowledge-with-comment |
| Tender show | tabs: Overview, Lots, Invitees (viewed/declined/bid y/n), Q&A, Addenda, Bids (sealed until opening), Evaluation (scorecard matrix per lot, landed cost breakdown, scores), Award, Documents, Activity; print notice (language picker) |
| Approvals inbox | tenders and awards waiting for me |
| Service requests | queue by SLA due, assignment, internal/public comments |
| Settings > Telegram bot, Settings > Security, Settings > Notifications | section 4.6 |

Portal (`layouts.portal`): Login (identifier + channel, code), Invitation accept, Dashboard (requests, open orders, SLA), Catalog (search, own prices, stock bands, add to request), Order requests (cart, templates, reorder from order), Offers (view, accept, print), Orders (status, documents), Agreements, Invoices, Service requests, Company users (Portal Admin), My cabinet. Partner menu: Tenders (invitations, notice, Q&A, bid form with charge breakdown, receipts, results), Shipments (carriers: vehicle/driver data, milestones, documents), Company users, My cabinet.

Navigation (`config/navigation.php`):
```php
// Sales section: add
['label' => 'nav.service_requests', 'icon' => 'support', 'route' => 'service-requests.index', 'active' => 'service-requests.*', 'can' => ['viewAny', ServiceRequest::class]],
// New section
['label' => 'nav.procurement', 'items' => [
    ['key' => 'tenders', 'label' => 'nav.tenders', 'icon' => 'tenders', 'can' => ['viewAny', Tender::class], 'children' => [
        ['label' => 'nav.tenders_purchase', 'route' => 'tenders.purchase.index', 'active' => 'tenders.purchase.*'],
        ['label' => 'nav.tenders_transport', 'route' => 'tenders.transport.index', 'active' => 'tenders.transport.*'],
    ]],
    ['label' => 'nav.counterparties', 'icon' => 'counterparties', 'route' => 'counterparties.index', 'can' => ['viewAny', Counterparty::class]],
    ['label' => 'nav.shipments', 'icon' => 'truck', 'soon' => true],
    ['label' => 'nav.approvals', 'icon' => 'approvals', 'route' => 'approvals.index'],
]],
// Administration: add departments; settings children: telegram → route, add security, notifications → route
['label' => 'nav.departments', 'icon' => 'departments', 'route' => 'departments.index', 'can' => ['viewAny', Department::class]],
// New top-level key for the portal shell
'portal_sections' => [ /* dashboard, catalog, requests, offers, orders, agreements, invoices, service, company users | partner: tenders, shipments */ ],
```
Renderer change needed: per-child `can` (children currently inherit the group's `can`) so Purchase/Transport children hide per department; and reading `portal_sections` for the portal layout.

Translations: new files `lang/{uz,ru,en}/{portal,tenders,departments,counterparties,service_requests,telegram,notifications,security}.php`, new keys in `nav.php`, `users.php` (`roles.staff`, type, Telegram status), `auth.php` (OTP messages), `documents.php` (tender notice, PO, transport order labels). All enum labels (statuses, roles, modes, Incoterms descriptions, charge codes, document types) via keys. Printable: tender notice (per invitee language), PO, transport order, award/regret letters via `DocumentRenderer`.

## 10. Acceptance criteria (selection; every BR has at least one test)
Auth/OTP/Telegram
- AC-1 Given a portal user, when they request a code by email, then one `login_codes` row with a hash (no plain code anywhere in DB/logs/jobs) is created and an email in their locale is sent; entering it logs them in on `portal` guard; reusing it fails.
- AC-2 Given an unknown email, an inactive user or a user without Telegram choosing Telegram, then the response text and status equal those for a valid user, and nothing is sent.
- AC-3 Given 5 wrong codes, then the code is invalidated and the 6th attempt with the right code fails; a second send within 60 s is refused.
- AC-4 Given a code older than 10 min, verification fails.
- AC-5 Given a portal user session, when requesting any internal route or Livewire internal component, then redirect to `/portal/login` (or 404 for Livewire); an internal user requesting `/portal/*` is redirected to `/portal/login`.
- AC-6 Given a portal user row with `role = admin` set directly in DB, then `isAdmin()` is false and `/users` is not accessible.
- AC-7 Given a logged-in user, when they open the deep link and the bot receives `/start <token>` with the correct webhook secret, then `telegram_links` binds that chat; a wrong secret header → 403 and nothing changes; an expired or used token → no link and a generic bot reply.
- AC-8 Given chat C linked to user A, when user B tries to link chat C, then it fails with the "already linked" message; A's link is unchanged. Admin force-unlink of A then allows B.
- AC-9 Given the Telegram API returns 403 "bot was blocked", then `blocked_at` is set, the cabinet shows a warning and the next notification goes by email.
- AC-10 Given role finance is in `2fa_required_roles`, when a finance user logs in with a correct password, then they must enter a code before reaching `/dashboard`; with a trusted device cookie (< 30 days) they are not asked.
- AC-11 Given duplicate webhook `update_id`, then it is processed once.
- AC-12 Given quiet hours 22:00–08:00 and a tender reminder at 23:00, then Telegram/email are sent at 08:00 and in-app immediately; an OTP at 23:00 is sent immediately.
Tenant isolation
- AC-13 For every portal route and Livewire action with a record parameter: a user of company A requesting company B's record gets 404 and no data (data-provider test over all portal routes).
- AC-14 A Portal Admin cannot deactivate the last Portal Admin; can invite only into their own company.
Departments
- AC-15 Given a `staff` user who is logistics specialist, then they can create transport tenders but not purchase tenders; a sales user sees only transport tenders linked to their own customers' orders.
- AC-16 Existing tests for UserRole admin/manager/sales/finance still pass unchanged after the migration.
Tenders
- AC-17 Given a transport draft without HS code, Incoterm place or customs responsibility, then "Submit for approval" is disabled and the panel lists N1, N2, N3 as blockers; filling them clears the blockers.
- AC-18 Given an unanswered `transshipment_allowed` (null), then blocker N6; explicitly "no" passes.
- AC-19 Given warnings, submit requires a comment per warning, stored in `acknowledged_warnings` and the transition reason.
- AC-20 Given a published tender, when a bidder submits at `bids_due_at + 1 s`, then rejected and logged; a revision before the deadline supersedes the previous version.
- AC-21 Given bids exist and `opened_at` is null, when the owner, manager or admin opens bid details (UI or direct Livewire call), then amounts and attachments are not returned; after the scheduler closes the tender they are.
- AC-22 Given bidder X, then X never sees bidder Y's identity or price, including in Q&A answers (anonymised).
- AC-23 Given bids in USD and UZS and a frozen rate, then landed cost and ranks match a fixture computed by hand (including a `not_included` component with estimate and downtime days × rate).
- AC-24 Given an award proposal to rank 2 without justification, then validation fails; with justification it requires the next approval level.
- AC-25 Given an approved award, pressing "Create PO" twice yields exactly one PO.
- AC-26 Given an addendum with < 48 h to deadline, then `bids_due_at` moves to now + 48 h and all invitees are notified.
- AC-27 Budget, target price and incumbent rate never appear in partner portal responses or the printed notice (assert absent from HTML).
- AC-28 Printed notice for an invitee with `preferred_locale = ru` has Russian labels, deadlines with "UTC+5".
Portal
- AC-29 Reorder from an order uses today's resolved prices and shows changes vs the original.
- AC-30 Order request above company threshold submitted by a buyer goes to `pending_approval`; an approver with a lower limit cannot approve.
- AC-31 Service request SLA due times computed in business hours; internal comments never shown in the portal.

## 11. Security
| Risk | Control |
|---|---|
| Cross-company data leak (IDOR) | `PortalContext` + policies on every record + `scopeVisibleTo`; uuid route keys; 404 on mismatch; AC-13 data-provider test; no `Model::all()` in portal components |
| Portal → internal escalation | typed providers, separate guards, `EnsureInternalUser`, `hasRole()` false for portal, route-list test, Livewire persistent middleware |
| OTP brute force | 10^6 space, 5 attempts/code, send and verify rate limits, lockout event, alerts on spikes |
| Account enumeration | identical responses/timing (queued send), generic invitation errors |
| Email or Telegram account takeover (SIM swap → Telegram re-registration, compromised mailbox) | Advise Telegram two-step password in the link screen; new-device login notification on the *other* linked channel; Portal Admin/manager can deactivate instantly; high-impact portal actions (offer acceptance above threshold, adding colleagues) require a fresh OTP if the session is older than 15 min |
| Code phishing | message text warns; codes bound to purpose; no codes in URLs |
| Webhook spoofing / replay | secret token header (`hash_equals`), `update_id` dedupe, throttle, bot token encrypted, rotation procedure |
| Bid tampering/leak | policy sealing, payload hash receipts, deadline in transaction, audit of every view of bid content after opening |
| Admin self-grant to read sealed bids (security audit) | DEC-10 enforced technically, not only by role: an admin cannot add **themselves** to a department or grant themselves a tender role (`AssignDepartmentMember` refuses actor = target for admins; a second admin or the department head must do it, both audited). Sealed-bid content policies additionally deny any user whose role is `admin`, regardless of department membership. Test: admin self-adds → 403; admin added by another admin still cannot read bids before `opened_at` |
| Bid amounts leaking before opening (security audit) | Until `opened_at`, bid amounts, prices, cost breakdowns and attachments are excluded from `activity_log` properties, notifications (mail/Telegram/database), queued job payloads (jobs carry ids only), exports, search indexes and logs; the bid row stores them, only the receipt hash and "bid received" metadata are emitted. Test scans these sinks for fixture amounts |
| Files | private disk, served by controller with policy; max 20 MB; allowlist pdf, jpg, png, xlsx, xls, docx, doc, csv, zip; content sniffing; random stored names; optional ClamAV hook (`scan_status`); sealed-bid attachments blocked until opening |
| Rate limits | login/OTP (4.3), invitations 20/hour per inviter, Q&A 30/hour per counterparty, bid submit 30/hour, uploads 60/hour per user, webhook 60/s |
| Audit | `activity_log` for all business mutations; `auth_events` for authentication; bid content views logged |

## 12. Tests plan
- Unit: `OneTimeCodeServiceTest` (generation range, hashing, expiry, attempts, cooldown, invalidation on reissue, `hash_equals`), `PermissionMapTest` (grants → scope), `TenderStatusTest` (transition table, valid + invalid), `TenderCompletenessCheckerTest` (each N/P code: trigger and clear; data provider), `LandedCostCalculatorTest` (BCMath, FX, estimates, rounding), `ScoreCalculatorTest`, `SlaCalculatorTest` (business hours, holidays config).
- Feature (auth): `Portal/OtpLoginTest` (`Mail::fake`, `Notification::fake`, `Http::fake(['api.telegram.org/*' => ...])`: email and Telegram channels, generic responses, rate limits, remember device), `Auth/TwoFactorTest` (enforced roles, trusted devices), `Auth/RealmIsolationTest` (guards, typed providers, AC-5/6), `Routes/RouteGuardCoverageTest` (route-list).
- Feature (Telegram): `Telegram/WebhookTest` (secret, dedupe, /start token, /stop, contact share own vs foreign, `my_chat_member` kicked), `Telegram/LinkingTest` (cabinet link/unlink/relink, unique chat, admin force-unlink, internal and portal users), `Telegram/ClientTest` (retry, 429, 403 → blocked, no text in logs), `Settings/TelegramBotSettingsTest` (admin only, token encrypted/never rendered, setWebhook payload asserted with `Http::assertSent`).
- Feature (notifications): preferences matrix, quiet hours (`$this->travelTo`), fallback to mail.
- Feature (organization): departments CRUD/policies, backfill migration test, existing role tests untouched, invitations (expiry, resend, revoke, existing user), membership deactivation logs out.
- Feature (portal): `Portal/TenantIsolationTest` (data provider over all portal routes/Livewire actions), catalog prices/bands, reorder, order request approval, offer acceptance, service requests (internal comments hidden), document print languages.
- Feature (tenders): lifecycle per transition with policies (role × department matrix as data provider), sealing (AC-21/22), deadline race (two submissions around the deadline), addenda, evaluation, award approvals, idempotent PO, notice print and absence of internal fields, scheduler `tenders:tick` idempotency.
- Translation parity: existing `TranslationParityTest` covers all new files; plus a test that every enum case has a label key.

## 13. Metrics
- Portal: % active customers with ≥ 1 active portal user (target 40% in 6 months); % repeat orders via portal (target 30%); time from request to offer (median < 4 business hours).
- Auth: OTP success rate > 95%; share of logins via Telegram; failed-OTP lockouts per week (alert on spikes).
- Service: first-response SLA met > 90%; reopen rate < 10%.
- Tenders: tenders published with 0 warnings (target 80%); average valid bids per tender ≥ 3; cycle time publish → award (median < 7 days spot transport); savings vs budget/incumbent; demurrage/downtime cost per shipment (down 30% vs baseline); on-time delivery %; claims rate.

## 14. Rollout and build order
Feature flags (settings): `portal.enabled`, `telegram.enabled`, `tenders.enabled`. Each increment ships tested; reversible migrations.

| Step | Increment | Contents | Depends on |
|---|---|---|---|
| O-1 | Realms & roles | `users` alter (type, nullable role/password), typed providers, `portal` guard, `EnsureInternalUser`/`EnsurePortalUser` (+ Livewire persistent), guard-aware logout/locale, `hasRole` fix, `UserRole::Staff`, route-guard test | Spec 01 0.2 (done) |
| O-2 | Departments & permission map | departments, department_user, backfill, admin screens, `PermissionMap`, nav entry | O-1 |
| O-3 | OTP service + email channel | `login_codes`, `auth_events`, portal login (email OTP), internal 2FA by email, Settings > Security, trusted devices | O-1 |
| O-4 | Telegram bot core | Settings > Telegram bot, `TelegramClient`, webhook, `telegram_links`/tokens/updates, My cabinet Telegram section (internal + portal), admin status/force-unlink, Telegram OTP channel | O-3. **Portal Phase-1 dependency** (Telegram login), but email OTP from O-3 can go live first |
| O-5 | Notifications | `notifications`, preferences, quiet hours, Telegram channel, initial events (approvals, deadlines, 1C sync failures, assignments) | O-4 |
| O-6 | Customer portal shell | memberships, invitations, Portal Admin user management, portal layout/nav, dashboard, My cabinet | O-3, Spec 01 1d customers/contacts |
| P-1 | Service requests | internal queue + portal | O-6, O-5 |
| P-2 | Catalog in portal | own prices, stock bands | O-6, Spec 02 I-3/I-4 |
| P-3 | Order requests / reorder / templates | → Offer conversion | P-2, Spec 05 Offers; reorder-from-order after Spec 06 |
| P-4 | Offers/orders/agreements/invoices in portal | view, accept, print | Specs 05–07, 1C invoices roadmap |
| T-1 | Counterparties | suppliers/carriers, contacts, documents, qualification, ratings, attachments | O-2 |
| T-2 | Tender engine core | tenders, lots, purchase items, transport specs, criteria, completeness checker, approvals, status machine, notice print, internal UI | T-1, `number_sequences`/`status_transitions` |
| T-3 | Partner portal bidding | partner memberships/invitations, invitations to tender, Q&A, addenda, sealed bids, scheduler close/open, receipts | T-2, O-6 shell |
| T-4 | Evaluation & award | FX freeze, landed cost, committee scoring, award approvals, winner acceptance, regret letters | T-3 |
| T-5 | Post-award | purchase orders (manual 1C number), shipments, milestones by carriers, claims, ratings | T-4 |
| T-6 | Links & 1C | tender_sources to orders (after Spec 06), 1C supplier order push and counterparty pull (Spec 02 pattern) | T-5, Spec 06 |

Critical path for the portal: O-1 → O-3 (email OTP live) → O-6 → P-1; Telegram (O-4) in parallel and required before announcing Telegram login. Tenders can start after O-2 in parallel with the portal; T-3 needs the portal shell.

## 15. Risks and open questions

### Risks
| # | Risk | Mitigation |
|---|---|---|
| R-1 | A route added without realm middleware leaks internal data | Route-guard coverage test in CI |
| R-2 | Telegram blocked/throttled in the region or bot token leaked | Email channel always available; token rotation; status page in settings |
| R-3 | Carriers refuse to use the portal | Bid-on-behalf (BR-T.14) with attachment; email notifications with deep links; simple bid form; ru/uz/en |
| R-4 | Over-complex tender form discourages logistics staff | Wizard, prefill from order/PO, templates per lane, only blockers block |
| R-5 | FX and landed-cost estimates disputed | Rates frozen and visible; estimates flagged; justification on award |
| R-6 | Scope size | Strict increments; portal and tenders are independent tracks after O-2 |
| R-7 | `role` default change breaks code that creates users without a role | Model guard + factory default `sales` for internal; existing tests run in O-1 |

### Open questions (default built if unanswered)
| # | Question | Default / status |
|---|---|---|
| Q-1 | How do portal users log in? | **Answered by user**: email or Telegram, 6-digit OTP via email or Telegram bot; no portal passwords (DEC-5) |
| Q-2 | Can internal staff link Telegram? | **Answered by user**: yes, in My cabinet, same mechanism; used for optional OTP/2FA and notifications (4.4–4.6) |
| Q-3 | One person in several customer companies? | Schema supports; Phase 1 one active membership; company switcher later |
| Q-4 | Approval thresholds T1/T2 and evaluation currency | T1 = 100 000 000 UZS, T2 = 1 000 000 000 UZS, evaluation in UZS |
| Q-5 | Sealed bid vs auction | Sealed single round (+ optional re-tender/BAFO); reverse auction roadmap |
| Q-6 | Are carriers/suppliers external logins? | Yes (portal partner memberships, OTP) + internal bid-on-behalf |
| Q-7 | Minimum invitees | 3; single-source with justification + manager approval |
| Q-8 | Show exact stock to customers? | Bands only |
| Q-9 | Service request SLA and business calendar | Defaults in 7.4, Mon–Fri 09–18 Asia/Tashkent, holidays list in settings |
| Q-10 | Portal reorder: request → sales confirms, or direct order? | Request → Offer (sales confirms); auto-confirm later per customer |
| Q-11 | Admin excluded from business approvals and sealed bids? | Yes (DEC-10) |
| Q-12 | Which roles must use 2FA? | admin, finance (setting) |
| Q-13 | FX source for tender comparison | CBU official rate on the closing date, entered by finance; API fetch later |
| Q-14 | Add TOTP/SMS later? | Not now; TOTP would need a package (e.g. `pragmarx/google2fa`) — separate decision |
| Q-15 | Tender content language | Entered once (any of uz/ru/en); labels printed per invitee language |
| Q-16 | Retention of tenders/bids | Keep indefinitely (archive), never hard delete after publish |
| Q-17 | Who may invite customer portal users | admin, manager, customer owner, customer Portal Admin |
| Q-18 | Session lengths | 120 min idle; portal remember device 30 days; internal trusted device 30 days |

## 16. Build status (2026-10-18, job 6: T-1, T-2, internal T-3, T-4, purchase part of T-5)

Built and tested (`tests/Feature/Tenders/*`, 26 tests; full suite 756 green):
- **T-1 Counterparties**: `counterparties`, contacts, documents (file as private attachment, expiry, verification), ratings (12-month rolling average). Qualification needs a verified, unexpired document; suspend/blacklist need a reason; nightly `counterparties:expire-documents` (00:20 Tashkent) sends qualified counterparties with an expired document back to pending qualification. Screens: Counterparties index/show.
- **T-2 Engine core**: `tenders` (+ sources, lots, purchase items, 1:1 transport spec with the full skill field list), criteria (default weights from the skills), requirements, committee (conflict-of-interest declaration), `TenderCompletenessChecker` N1–N24 / P1–P10 + "R" blockers, `TenderStatus` machine via `TransitionTender` (status_transitions + activity log), publication approval, notice print (uz/ru/en, whitelisted fields, UTC+5), wizard UI (overview, lots, criteria & committee) with the completeness panel and acknowledgement comments.
- **T-3 internal part**: invitations, Q&A (recorded by staff, answers published anonymised), addenda (deadline extension to now + 48 h, submitted bids flagged), sealed bids entered by employees with mandatory evidence (Spec 08 channel A: received via/at, sender, file or ≥ 20-char call note), versions with correction reason, sha256 receipt hash, withdrawal before the deadline, deadline checked in a locked transaction (late attempts refused and logged), `tenders:tick` every minute closes due tenders and opens bids (idempotent), invitees without a bid → no_response.
- **T-4 Evaluation and award**: finance freezes FX (UZS per unit, rate date), landed cost per BR-T.8 (included charges, owner estimates for not-included, expected downtime × rate, prepayment financing, cargo carrying cost; half-up per component), knock-outs, scores (auto cost/time/payment, rating, manual average), rank (ties: landed cost, then submission time), award per lot with justification for non-rank-1, approval chain, winner acceptance recorded by the owner (note ≥ 20 chars), decline → next rank.
- **T-5 purchase part**: purchase order created from an accepted purchase award (exactly one per award, unique FK, idempotent), PO screen (confirm, cancel, manual 1C number, print).
- Navigation: Procurement section (Tenders > Purchase / Transportation with per-child `can`, Counterparties, Purchase orders, Approvals, Shipments "soon"). Orders screen: "Request transport" creates an unowned transport draft prefilled from the order; logistics takes it over.

Decisions taken in this build (defaults, recorded per user instruction):
- **D6-1 Approval routing** (user decision: above 60 000 000 UZS → CEO): publication by the head of the tender department (or a manager) unless the budget is above 60M UZS (or unknown in UZS) or the method is single source → CEO (`dept:management:head`). Award: L1 head unless amount > 60M UZS, not rank 1, < 2 valid bids, or > budget + 10 % → CEO; above T2 (1 000 000 000 UZS) finance co-approves after the CEO. For transport awards the purchase head may act as L1 (Spec 08 9.3). Maker ≠ checker: never the owner, requester, award proposer or an employee who entered a bid of that tender. Admins never approve and never read bid content (also with a department membership). T1 (100M) keeps its Spec 03 meaning (≥ 3 invitees and ≥ 2 committee members become blockers).
- **D6-2** Decisions are stored append-only in `tender_decisions` (Spec 08 table) instead of a JSON column on awards.
- **D6-3** Without a committee the tender owner side scores manual criteria; with a committee only members who declared no conflict of interest.
- **D6-4** Prepayment financing uses the transit days (transport) or lead time (purchase) as the period; cost of capital and carrying rate 24 %/year (settings `tenders.cost_of_capital_bp`, `tenders.carrying_rate_bp`). Estimates are entered in the evaluation currency. Charges not listed in a bid are deemed included (notice condition 3). Payer-scope normalisation (Spec 08 7.2) comes with job 7.
- **D6-5** Structural checks pass silently: N11/P10 and N22 (fixed clauses printed on every notice), N23/P6 (internal-only columns never rendered). Required fields the codes do not name are reported as code "R".
- **D6-6** Evidence files use the existing attachment allowlist (PDF/JPG/PNG, sniffed); the wider Spec 03 list (xlsx, docx, zip) is deferred with the scan hook.
- **D6-7** A declined award returns the tender to evaluation; other lots keep their approved/accepted awards; the decliner is knocked out for that lot.
- **D6-8** Transport awards end in `contracted` after acceptance; the transport order / shipment (T-5 transport) is Spec 08 (job 7).

Not built yet (planned): partner portal bidding and bid links (jobs 7/8), notifications/receipts by e-mail or Telegram (job 8), comparison snapshot and CEO decision screen with step-up (job 7), shipments, claims, carrier ratings from shipments, BAFO rounds, approval delegation, `tender_sources` for purchase orders (job 7), 1C supplier order outbox (roadmap).

## 17. Build status (2026-10-18, job 8: customer portal O-6/P-4, Telegram bot, staff cabinet)

Built and tested (`tests/Feature/Portal/*`, 25 tests; full suite green):
- **Portal realm**: separate `portal` session guard (users provider), `EnsurePortalUser` on every portal page and Livewire update (active portal user + active membership of a portal-enabled customer; a deactivation ends the session on the next request), no simultaneous staff and portal sessions in one browser. `PortalScope` scopes every list, page and print to the member's company; another company's record (or an internal-only state such as an offer draft) is a 404.
- **Login (4.2/4.3)**: e-mail, Telegram @username or the verified Telegram phone + channel (e-mail or Telegram, no automatic fallback); 6-digit HMAC code, 10 minutes, 5 attempts per code, 60 s cooldown, per-identifier/IP request limits, verification lock after 10 failures per identifier; always the same generic answer; every step in `auth_events` (identifier stored as HMAC only).
- **Pages**: overview, offers (choices, accept within the company threshold for buyers / own limit for approvers / always for portal admins; viewers and finance read only; first visit marks Sent → Viewed), orders with deliveries, delivery-note and warranty-card prints, agreements and invoices (finance, approver, portal admin only), warranty lookup by serial, company users (portal admin: give access, deactivate, never the last portal admin), my cabinet. Prints reuse the staff renderers through `DocumentRenderer` with the language picker.
- **Staff side**: customer card tab "Portal" (permission `customers.portal_access`: admin, manager, sales/specialist own, sales head department): portal on/off, acceptance threshold, members with Telegram status, give access (e-mail announces the portal address), deactivate. Agreements can be sent through the `portal` channel only for portal-enabled customers.
- **Telegram (4.5/4.6/4.9)**: Settings > Telegram (admin): token stored encrypted and never shown, bot username, webhook secret, set webhook, test. Webhook `POST /api/telegram/webhook`: 503 while off, 403 without the secret header (constant time), each `update_id` processed once. `/start <token>` links (single-use sha256 token, 10 minutes), one chat per user and one user per chat, `/stop` and cabinet unlink, admin force-unlink from Users, contact share stores only the user's own phone, `kicked`/`left` marks the link blocked. Staff and portal users link from "My profile" (`/cabinet`, `/portal/cabinet`).

Decisions taken in this build (defaults, recorded per user instruction):
- **D8-1** Access is granted directly by staff or the portal admin and announced by e-mail; there are no invitation tokens or passwords, the first OTP login proves control of the mailbox.
- **D8-2** Phase 1: one active company per portal user (a second company is refused, `other_company`).
- **D8-3** `TelegramClient` is the only outbound HTTP caller and may call only `api.telegram.org` (architecture test `NoOutboundHttpTest` allowlist); the CRM still never calls 1C.
- **D8-4** No portal 2FA beyond the OTP; the `email_link` agreement channel stays off; offer acceptance by a buyer above the threshold shows "needs an approver" (no in-portal approval request yet).

Not built yet (planned): partner (carrier/supplier) portal bidding (links from Spec 08 cover it for now), service requests, catalogue and order requests from the portal, in-portal approval requests, notifications other than login codes and account linking, staff 2FA (job 10 checklist).

**Update 2026-10-18 (Telegram leads):** every non-command message to the bot is lead work (Spec 04 section 15.2): unknown senders create `telegram` leads, linked portal users create `portal_request` leads on their customer, staff accounts are never leads; attachments are noted, not downloaded; per-chat rate limit; acknowledgement in the sender's language; owner notified in-app and on Telegram.

**Update 2026-10-19 (Telegram groups):** private chats and groups are now told apart. Chats, Telegram people and group memberships are stored, and group messages feed one open lead per group. Managers can create a company from a group and create contacts or pending portal users from its members. The bot needs admin rights, or privacy mode turned off, to see all group messages. See Spec 04 section 17.

## 18. Go-live tooling (2026-10-19, roadmap step 7)

Admin-only (`manage` Setting), under Settings: **Setup** (`/settings/setup`) and **Data import** (`/settings/import`), plus the console command `crm:wipe-demo`.

**Setup checklist.** Live status per step with a link to the screen where it is done. Required: company profile (legal name and tax ID), default signatory (the CEO), default bank account in the base currency, a published agreement template (uz), an active 1C integration token, at least one active warehouse (arrives with the first 1C stock feed), key roles (sales, accountant, finance, stock manager, and a head of the management department as CEO; demo `@crm.test` users do not count), scheduler running (heartbeat written every minute by the scheduler, stale after 5 minutes). Optional: staff 2FA enforcement (switch on the page; logged as `two_factor_enforcement_changed`), Telegram bot, demo data removed. "Ready for go-live" = all required steps done.

**Data import (CSV/XLSX).** Max 5 MB and 5000 rows; CSV in UTF-8 (BOM optional) or Windows-1251, delimiter auto-detected (comma, semicolon, tab); header names are normalized (case, spaces). XLSX is read with openspout after a zip-bomb check; only the first sheet is used. Cells holding formulas (XLSX formula cells, CSV values starting with `=`) make the row invalid. The file is always checked first; the preview (kept server-side for 30 minutes, bound to the user) shows valid / duplicate / error rows with reasons, and only valid rows are written, in one transaction. CSV templates with an example row can be downloaded.

- **Customers** — columns `name` (required), `legal_name`, `tax_id`, `phone`, `email`, `city`, `address`, `currency`, `payment_terms_days`, `sale_type` (b2b|retail, default b2b), `owner_email` (must be an active user who can create leads; default the importing admin), `contact_*` (primary contact). Phones are normalized to +998; tax ID format is checked. Duplicate = tax ID already in the CRM or repeated in the file; duplicates are never overwritten (decision D7-1). Imported customers are CRM customers (`origin=crm`, not synced) and become available for 1C to pull exactly like customers entered by hand. Logged `imported` per customer.
- **Items** — items belong to 1C, so the import is an initial load only and builds the same records 1C would send: the 1C GUID (`guid`) is required (D7-2) so later 1C feeds update the same rows instead of duplicating them; `unit_code` must match a unit already received from 1C; `type` product|service ("goods" is accepted as product); same validation (BatchValidator) and upsert (ItemUpserter) as the 1C inbound API. Logged `items_imported` on the admin.

**Demo-data wipe** (`php artisan crm:wipe-demo [--catalog] [--force] [--allow-real]`): empties business tables (leads, customers, offers, orders, agreements, invoices, payments, stock documents, serial history, tenders, counterparties, outboxes, logs, notifications) in one transaction and removes demo users (`@crm.test`). Keeps settings, company profile, departments, agreement templates and real users; the catalog (items, units, categories, stock, serials) only with `--catalog`. Guards: refuses when any lead/customer/offer/order/payment belongs to a non-demo user (override `--allow-real` is refused in production); production also needs `--force`; asks for confirmation when interactive (D7-3).

Tests: `tests/Feature/Admin/GoLiveTest.php`.

## 19. Volume baseline (2026-10-19, roadmap step 8)

Throwaway SQLite copy of the dev DB with 100k items (+100k stock rows), 50k customers and 1M serial numbers (5k serialized items x 200); pages rendered through the HTTP kernel as admin, sales, accountant, finance and stock manager, recording time, query count, slow queries and repeated query shapes. The dev DB was not touched.

Fixed:
- `/stock/reconciliation` took 220 s (O(n²) collection lookups plus one warehouse query per row). It now uses keyed maps, one warehouse-name map and a subquery instead of a 5k-id IN list, and takes about 1 s. By default it shows only rows with a difference, sorted largest shortage first, capped at 500 rendered rows; "show all" is a toggle (`?all=1`).
- The stock & serials report crashed (enum-cast status used as an array key) and lazy-loaded stock per item (`with()` is ignored by `cursor()`). It now uses two grouped aggregates plus a streamed item pass with BCMath quantities: a 100k-row export takes about 2 s, 3 queries, 62 MB peak.
- The orders-backlog and payments reports used `with()` + `cursor()` (N+1). They now use `lazy(500)`.
- Sales-scoped serial list: `whereHas` replaced by an indexed `whereIn` on `sold_order_line_id` (344 ms down to 204 ms).
- Settings are read once per request (`Cache::memo()`), not once per key.
- The setup checklist computes its steps once per render.
- Lazy-loading audit of the whole suite (preventLazyLoading in a log-only mode, not committed) found two N+1s: offer line pictures on the builder/show pages, and the tender-copy invitation loop. Both are eager-loaded now.
- Migration `add_foreign_key_indexes_for_volume`: 68 indexes on foreign keys used for lookups. SQLite and PostgreSQL do not index FKs (MySQL does). Each index is added only if no index already starts with that column. Audit `*_by` user columns are left out on purpose.

Accepted (measured on SQLite):
- Contains-search over 100k items is 0.5–0.8 s: item list search over 6 columns, global search. `%term%` cannot use a B-tree index.
- Customer search is about 0.3 s.
- Serial prefix search is 0.1–0.2 s.
- Categories with item counts take 0.2 s.
- The stock report preview is 0.9 s (full aggregates for 200 preview rows).

Recommendations for production (DBA decision, not built):
- Production is MySQL 8, per `docs/db-review-2026-10.md`. Re-run the same profile there before go-live (method described above; the seed and profiling scripts were throwaway and are not in the repo).
- MySQL already indexes FKs; the new migration detects those indexes and skips them.
- If item contains-search is still slow, consider a FULLTEXT index on `items(name, sku, article)`.
- Use Redis (or file) for cache and sessions instead of the database driver.

Regression tests: `tests/Feature/VolumeRegressionTest.php`.

## 20. Security audit 3 (2026-10-19, roadmap step 9)

Scope: everything built since audit 2.
- Sales flow and packaging.
- Telegram leads and the lead form (drafts, follow-ups, duplicates).
- Item images and the media routes.
- Dashboards, reports and exports.
- Notification center, global search and timeline.
- Staff 2FA.
- Go-live import, demo wipe and setup.

Checks: authorization on every route and Livewire action, scoping of user-supplied ids, file handling, output escaping, redirects, injection into exported files, rate limits, and the dev login bypass.

Fixed (with regression tests):
- **High: formula injection in XLSX report exports.** OpenSpout turns any string starting with `=` into a live formula. A customer or counterparty name such as `=cmd|...` would have run when the export was opened in Excel. Text is now always written as a text cell (`ReportExporter::xlsxRow`). Test: `SecurityAuditThreeTest`.
- **Medium: open redirect from notification links.** Links like `/\host` passed the "starts with /, not //" check; browsers treat `/\` as `//`. Notification links now must match `^/(?![/\])` with no control characters. Test: `SecurityAuditThreeTest`.
- **Low: CSV injection in the serial-registration error report.** Raw input was echoed unescaped. It now goes through `csvSafe`. Test: `SerialRegistrationTest::test_error_report_neutralizes_formulas`.

Verified, no change:
- Media routes check view rights on the item, offer or order, plus portal scope.
- Gallery actions are scoped to the item.
- Reports are checked per report on page, export and preview, and use `visibleTo` scopes. Exports are throttled to 20/min.
- Global search is scoped per group and checks `view` per serial.
- The timeline only includes visible records.
- Notifications are bound to the user.
- Lead drafts are per user.
- Lead customer and contact are validated against visibility and the customer.
- Follow-ups are scoped to the lead.
- 2FA:
  - encrypted secret;
  - hashed single-use recovery codes;
  - no TOTP step reuse;
  - 5 attempts per minute per user;
  - a 5-minute pending window;
  - session regenerated on both steps.
- The dev login bypass needs APP_ENV=local and APP_DEBUG=true, and still asks for 2FA.
- Import and setup are admin only:
  - the preview key is locked and bound to the user;
  - XLSX gets a zip-bomb check;
  - formula cells are rejected.
- The wipe is guarded (real owners, `--force` in production).
- No unescaped Blade output (`{!! !!}`) anywhere.

By design, recorded:
- Duplicate warnings on the lead form name customers and leads (with their owner) that the user cannot open. This avoids two reps working the same company; it shows only name, number, status and owner.
- Item pictures are visible to every portal user (catalog content).

Open (low, not fixed):
- On SQLite, `LIKE` has no escape character, so `%` and `_` typed into search act as wildcards. This only widens the user's own scoped search. MySQL and PostgreSQL honour the backslash escape.

## 21. Production readiness (2026-10-19, roadmap step 10)

Details for operators are in `docs/operations.md`. Configuration lives in `config/operations.php`.

- **Backup and restore.**
  - `crm:backup` writes one ZIP to `BACKUP_PATH`: the database dump, `storage/app/{private,public}` without temporary uploads, and a manifest with SHA-256 per entry.
    - MySQL uses `mysqldump --single-transaction` and PostgreSQL uses `pg_dump`.
    - SQLite uses `VACUUM INTO`.
  - It runs daily at `BACKUP_AT` (Asia/Tashkent) and keeps `BACKUP_KEEP` archives (14).
  - `crm:backup --list` lists archives; `--verify=` checks one without restoring.
  - `crm:restore`:
    - verifies checksums, entry names (no traversal) and that the driver matches;
    - goes into maintenance mode and replaces the database (SQLite keeps a safety copy);
    - restores the files;
    - needs `--force` in production.
  - Archives are never uploaded anywhere. The off-site copy is the operator's job (D10-1).
- **Scheduler and worker supervision.** Documented for Linux (cron + Supervisor, or a systemd timer) and Windows (Task Scheduler + NSSM).
  - The worker serves the queues `onec-inbound,default`.
  - The scheduler heartbeat appears in Setup and in the health check.
- **Health check.** `GET /api/health` is stateless and throttled.
  - Without a token it returns only `{"status":"ok|fail"}` (200/503).
  - With `X-Health-Token`, or from localhost when no token is set, it returns details for:
    - database, cache and storage;
    - scheduler (at most 5 min), queue (oldest pending job at most 15 min) and backup (at most 26 h);
    - 1C outbox: the count available for 1C to pull; it only warns.
  - `/up` stays as the liveness probe.
- **Log and data retention.**
  - Daily log files: `LOG_STACK=daily`, `LOG_DAILY_DAYS=30` recommended.
  - Read notifications are pruned after 90 days (`crm:prune-notifications`).
  - Existing prune jobs are listed in the docs.
  - Audit data (activity log, transitions, ledger, payments, serial history) is never pruned (D10-2).
- **Production `.env` checklist.** `crm:prod-check` lists 19 items, each fail or warn. It exits 1 on blocking items:
  - debug, key, https URL, secure cookie, cache, queue, mail;
  - backup path, health token, 2FA, optimize, setup ready.
- **2FA reset by admin.** Users > "Reset 2FA" for a lost phone. Admin only, never one's own, audited as `two_factor_reset`.
- **In-app user guide.** Help > User guide (`/guide`) in uz/ru/en.
  - Sections: everyone, sales, sales manager, accountant, finance, stock manager, procurement and logistics, administrator.
  - It opens on the reader's role; other roles can be read too.
  - Links show only where the sidebar would show the page.
- **Fix found on the way.** The `down()` of `add_telegram_and_sources_to_leads` failed on SQLite (index before column). A full `migrate:reset` now works.

Tests:
- `tests/Feature/Admin/OperationsTest.php`
- `tests/Feature/GuideTest.php`
- `TwoFactorTest::test_admin_can_reset_another_users_two_factor`

## 22. One organizations table with roles (user decision 2026-10-19, reverses DEC-12)

**Decision.** Customers, suppliers and carriers are one organization each, with a set of roles. A tax ID (INN) identifies one organization. DEC-12 ("separate `counterparties` table, the same INN may exist in both") is reversed. An organization that is a customer and also a supplier is now one record with two roles. It carries "Also a supplier" / "Also a customer" badges.

### 22.1 Target schema (chosen path and why)

- **The organizations table is the existing `customers` table** (no table rename). In the code, `App\Models\Organization` is the unscoped model and `Customer` is the same table filtered to `is_customer = true`. This is the safer path because:
  - customer ids stay unchanged;
  - 17 foreign keys and the morph alias `customer` stay valid;
  - the 1C customer API keeps its contract (GUID, outbox, ack, pull) without migration;
  - the virtual unique key `crm_tax_key` already makes a tax ID unique among CRM organizations.

  Renaming the table would touch every module and the 1C exchange for no functional gain. The UI shows "Customers" and "Suppliers & carriers" as two filtered views.
- **New role flags on `customers`:**
  - `is_customer` (default true, so existing rows stay customers);
  - `is_supplier` and `is_carrier` (default false).

  The supplier and carrier flags are kept in step with the supplier profile.
- **Role-specific data stays in its own tables, keyed by the organization:**
  - **Customer-only data** stays on the organization row: the 1C sync columns (`origin`, `external_*`, `sync_*`), credit limit, holds, sale type, portal settings. These fields are read-only when the organization is linked to 1C, as before.
  - **Supplier/carrier data** stays in `counterparties`, which becomes the **supplier profile**. It gains a new unique `organization_id` (1:1). Its own fields are:
    - qualification status and reason, qualified by/at;
    - rating, fleet, vehicle types, lanes, TIR/ADR;
    - owning department;
    - prepared 1C supplier columns `external_id` / `synced_at`.

    Its documents (`counterparty_documents`) and ratings (`counterparty_ratings`) keep pointing at the profile. **All counterparty ids stay unchanged**, so tenders, bids, invitations, awards, questions, purchase orders, portal supplier memberships, activity log and attachments need no remapping.
  - **Identity fields** of the profile (`name`, `legal_name`, `tax_id`, `country`, `city`, `address`, `phone`, `email`, `website`, `preferred_locale`) become a read-through copy of the organization:
    - the organization is the master;
    - editing them on the supplier form writes the organization (blocked when the organization is a 1C-linked customer);
    - organization changes are copied back to the profile.

    They will be dropped once the tender module reads them through the organization (D22-6).
- **Contacts: one `contacts` table linked to the organization** (`contacts.customer_id` is the organization id).
  - `counterparty_contacts` rows are copied in:
    - `name` is split into first and last name; `telegram` goes to `telegram_username`;
    - `preferred_locale` is a new column;
    - `legacy_counterparty_contact_id` keeps the mapping and allows `down()`.
  - The old table stays read-only for one release and is no longer used by the code (D22-5).
  - A contact change queues the 1C customer outbox only when the organization is a customer.
- **Portal memberships** get `organization_id` (backfilled and maintained). The existing `customer_id` / `counterparty_id` columns stay as the **role context**: customer portal or supplier/carrier link.
- **Merge log `organization_merges`** records, per migrated counterparty: the organization, the action (`created` or `merged`), the tax ID, and the field conflicts as `field: customer value | counterparty value`. Nothing is overwritten. `php artisan crm:organization-merges` prints the report.

### 22.2 Mapping of every reference

| Table.column | Before | After |
|---|---|---|
| leads, offers, orders, agreements, invoices, payments, accounting_entries, goods_receipts (customer returns), item_serials (sold to), customer_bank_accounts, customer_outbox, onec_outbox, telegram_chats `.customer_id` | customers.id | unchanged; now the organization id. These records only ever point at organizations with the customer role. |
| contacts.customer_id (and leads/offers/orders/portal_memberships/agreement_dispatches/telegram_contacts `contact_id`) | customers' contacts | unchanged ids; supplier contacts are added as new rows |
| organization_merges | new | the dedupe and conflict log (`php artisan crm:organization-merges --conflicts`) |
| counterparty_contacts | supplier contacts | copied to `contacts` (`legacy_counterparty_contact_id`); table kept read-only |
| counterparty_documents, counterparty_ratings `.counterparty_id` | counterparties.id | unchanged (supplier profile) |
| tender_invitations, tender_bids, tender_awards, tender_questions `.counterparty_id`; tenders.incumbent_counterparty_id; purchase_orders.supplier_id | counterparties.id | unchanged (supplier profile); the organization is reached through `counterparties.organization_id` |
| portal_memberships | customer_id or counterparty_id | plus `organization_id` (customer_id, or the profile's organization) |
| user_invitations | not present in this codebase | n/a |
| activity_log / attachments morphs `customer`, `counterparty`, `counterparty_document` | | unchanged aliases |
| 1C customer API (pull, ack, push) | customers | `Customer` scope: only `is_customer` organizations are offered, acked or matched. Supplier-only organizations never reach the customer outbox. |

### 22.3 Migration order (new migrations only, never `migrate:fresh`)

1. `add_organization_roles` (schema):
   - flags on `customers`;
   - `counterparties.organization_id` (nullable FK, unique; enforced by the model);
   - `contacts.preferred_locale` and `contacts.legacy_counterparty_contact_id`;
   - `portal_memberships.organization_id`;
   - the `organization_merges` table.
2. `merge_counterparties_into_organizations` (data, chunks of 200, inside a transaction, query builder only):
   1. Each counterparty, including soft-deleted ones, is matched by normalized tax ID to a live organization. CRM-origin rows are preferred, then the lowest id.
      - **Match found:** the supplier/carrier flags are set on that organization. Identity conflicts go to the log; customer fields are not changed.
      - **No match (or no tax ID):** a new organization is created with `is_customer = false`, `origin = crm`, `sync_status = not_synced`, base currency, legal entity, and an owner fallback (owner, else qualified_by, else the first active manager/admin). Its timestamps and soft delete are copied.
      - Two counterparties with the same tax ID merge into one organization; the conflict is logged.
   2. Counterparty contacts are copied (primary only if the organization has none).
   3. `portal_memberships.organization_id` is backfilled.
   4. `down()` removes the copied contacts and the organizations this migration created, and clears the flags it set.
3. Verification:
   - on a copy of the dev DB: row counts (organizations = customers + created; contacts = old + copied) and `PRAGMA foreign_key_check`;
   - then the same checks on the dev DB.

### 22.4 Behaviour kept

- 1C stays pull-only, and 1C-synced fields stay read-only.
- Creating a customer or a supplier whose tax ID already belongs to an organization does not create a duplicate. The form names the organization and offers **"Add the customer role"** / **"Add the supplier/carrier role"**, which needs `customers.create` / `counterparties.create`.
- Permissions are unchanged per role: sales see customers (`customers.*` on `Customer`); purchase and logistics see suppliers and carriers (`counterparties.*` on the profile).
- The sidebar keeps "Customers" and "Suppliers & carriers". Old route names are unchanged.

### 22.5 Defaults recorded

- **D22-1** Keep the `customers` table as the organizations table (see 22.1).
- **D22-2** Roles are flags, not a pivot; three roles are fixed by the business.
- **D22-3** Dedupe by normalized tax ID only. Name matches are not merged automatically; they show in the existing duplicate warnings.
- **D22-4** Conflicts are logged, never resolved silently. The customer (1C) values win on the organization; the counterparty values stay in the log and in the profile copy until reviewed.
- **D22-5** `counterparty_contacts` is kept read-only for one release, then dropped in a later migration after a DBA review.
- **D22-6** The profile's identity columns are a maintained copy until the tender module reads through the organization; they are dropped later.
- **D22-8** `counterparties.organization_id` is indexed but not unique. Two legacy counterparties with the same tax ID share one organization and are logged as `duplicate_profile` for a manual merge, because their bids and purchase orders keep their own ids. New profiles are 1:1 (application rule; `supplierProfile` = the oldest).
- **D22-9** When an organization is also a customer, its identity (name, tax ID, address, contacts) is edited only on the customer card. The supplier form shows it read-only. This keeps customer and 1C data with sales (SoD).
- **D22-7** A 1C-created customer whose tax ID matches a supplier-only organization becomes a separate row flagged `has_duplicate_tax_id`, as for 1C duplicates (BR-I.17). It is merged by hand.


### 22.6 Build status (2026-10-19)

- **Done:**
  - migrations `add_organization_roles` and `merge_counterparties_into_organizations`;
  - `Organization` model and the `Customer` scope;
  - supplier profile sync;
  - single contacts table;
  - `organization_id` on portal memberships;
  - add-role flows on both forms;
  - "Also a ..." badges;
  - sidebar label "Suppliers & carriers";
  - merge report command;
  - tests in `tests/Feature/Customers/OrganizationRolesTest.php`.
- **Verified:**
  - first on a copy of the dev DB, then on the dev DB: 11 customers + 7 created organizations = 18; 10 contacts + 7 copied = 17; `PRAGMA foreign_key_check` clean;
  - rollback tested on the copy.
  - The dev data had no tax-ID overlap, so 0 merges and 0 conflicts.
- **Not done yet:**
  - drop `counterparty_contacts` and the profile identity columns (D22-5, D22-6) after a DBA review;
  - a combined "all organizations" admin view;
  - a 1C supplier pull.

**Update 2026-10-19 (Spec 09):**

- A new standard department `service` and a new global role `service_engineer` (mapped to service/specialist) were added.
- New permissions `service.view`, `service.manage` and `service.register` were added; admins only read.
- Portal:
  - new pages "My equipment" (`/portal/equipment`) and the equipment detail page;
  - `portal_admin`, `buyer` and `approver` may register units and report problems; `viewer` and `finance` only read.
- New notification events `service_ticket` and `warranty_expiring`.
- Mail notifications skip the Telegram-only placeholder address.

> **Spec 10 note (2026-10-19).** The portal roles now include `requester` (creates and submits requests) and `purchase_manager` (approves, returns or rejects them and sends them to us). Portal users see Catalog and Requests; isolation is enforced through `PortalScope::requisitions()`. See `docs/specs/10-customer-requisitions.md`.

## 23. Company Telegram groups: internal notifications (user requirement 2026-10-19)

**Goal.** The bot posts reports, customer requests, orders and other events to the company's own Telegram group(s), and staff can ask a few read-only questions there. Non-goals: two-way work from Telegram (commands never change data); sending anything to customers.

### 23.1 Chat kinds and destinations
- `telegram_chats.purpose`: `customer` (default, every existing chat) | `internal_notifications`. An internal chat never creates leads, its members are never offered as customer contacts (`TelegramChatPolicy::manage` is false), it is never used for customer service updates, and its messages are ignored except bot commands.
- Marking a chat internal: if a lead was opened from it, the admin must tick "Close the open lead as created by mistake"; the lead is then closed as disqualified (reason "wrong target"), never deleted, and the chat is detached from any customer. Audited on the chat.
- `telegram_destinations` (Settings > Telegram > Company groups, gate `manageTelegramNotifications` = new permission `settings.manage`, admins only): name, group chat id (picked from groups the bot is in, or typed), optional forum topic (`message_thread_id`), language (uz default, ru, en), "Include amounts" (default off), active, routing JSON (event → `minimal` | `standard`). Shows bot status, last successful post and last error; "Send test message". Several destinations = routing by category (orders group, requests group, reports group). Default for a new destination: every event at `minimal` except the weekly report. Every change is audited (`telegram_destination` activity).

### 23.2 Events (`App\Enums\InternalEvent`)
New lead, Telegram lead, customer request received (Spec 10), offer sent/accepted/rejected, order created/confirmed/packed/delivered, payment received, payment overdue (one post per overdue order, from the daily scan), service ticket opened/closed, tender published, tender deadline (from the existing hourly scan), tender award needing approval, goods receipt posted, goods receipt discrepancy, daily report, weekly report. Status events come from `status_transitions` (one hook); the others from `Notifier`, `PostGoodsReceipt` and `InternalScheduler`.

Reports (`telegram:internal-tick`, every 5 minutes; Asia/Tashkent): daily at `telegram.internal.daily_time` (default 18:00), weekly on `weekly_day`/`weekly_time` (default Monday 09:00); sections sales summary, receivables, open tickets, stock/serial alerts (receipt discrepancies). Each posts once per day/week (dedupe key).

### 23.3 Message design and privacy
HTML parse mode; every dynamic value is escaped; only `<b>` and `<a>` markup. Deep link = absolute route URL (login required). Levels: `minimal` = event, number, status, link; `standard` adds customer/supplier name, owner, up to five item names with quantities and a 200-character excerpt of customer text with e-mails and phone numbers removed. Amounts only with "Include amounts". Never: phones, e-mails, serial numbers, bank details, tokens, portal codes. Messages longer than 4000 characters are split on line boundaries.

### 23.4 Reliability
`InternalNotifications::publish()` renders per destination, inserts `telegram_posts` (unique destination + event key = dedupe; only the SHA-256 of the text is stored) and queues `PostInternalTelegramMessage` on the **`telegram` queue** after commit (the worker command in docs/operations.md now lists it). The job keeps 20 messages per minute per chat, honours `retry_after`, retries with backoff (10 s → 30 min, 6 tries), follows supergroup migration (also from the webhook) and topic ids. Permanent errors (bot removed/blocked, no rights, topic or chat missing) mark the destination and alert admins once with the existing "bot removed" notification; the bot being removed from a company group also alerts admins. Outbound traffic only through `TelegramClient` (api.telegram.org); the CRM never calls 1C.

### 23.5 Commands (company groups only)
`/status`, `/orders today`, `/requests open`, `/report today` (also `/cmd@bot`). Sender = active staff user whose linked Telegram id matches; permissions: status any staff, orders `orders.view`, requests `viewAny` requisition, report `viewReports`. Others get a polite refusal without data. Replies are counts only (no amounts, names or contacts), 10 commands per minute per chat. The webhook secret check is unchanged.

### 23.6 Defaults and open points
- One destination is enough; its default name is "All events".
- Payment overdue posts once per order (not repeated weekly).
- Group rate limits beyond 20/minute per chat are not modelled (volume is low).
- Tests: `tests/Feature/Telegram/InternalNotificationsTest.php`.

## 24. Partner model (user decision 2026-10-19, reverses D22 "keep the customers table")

**Decision.** Every organization is a **Partner** (`App\Models\Partner`, table `partners`) with the roles customer, supplier and carrier (`is_customer`, `is_supplier`, `is_carrier`). The UI keeps the words Customer, Supplier and Carrier; "Partners" is only the umbrella (combined list, "New partner"). D22 had kept the old table name because of the cost (17 foreign keys, the morph alias, the 1C contract, portal naming, permission names, translations). The rename is done in stages so nothing external breaks.

### 24.1 Mapping (stage A, migration `2026_10_19_101142_rename_customers_to_partners`)
| Before | After | Notes |
|---|---|---|
| table `customers` | `partners` | foreign keys of other tables follow the rename (SQLite rewrites them; MySQL keeps them by table id); `customers_*` indexes renamed to `partners_*` |
| `contacts.customer_id` | `contacts.partner_id` | contacts belong to the organization (customer and supplier contacts are one table) |
| `organization_id` on counterparties, portal_memberships, customer_assets, service_tickets, catalog_visibility_rules, requisitions | `partner_id` | indexes renamed |
| `customer_id` on orders, offers, leads, agreements, invoices, payments, accounting_entries, onec_outbox, customer_outbox, item_serials, serial_events, goods_receipts, telegram_chats, portal_memberships, customer_bank_accounts | **unchanged** | role-specific: they point to a partner in the customer role, so `customer_id` stays the right name |
| `organization_merges.organization_id` | unchanged | historical log of the section 22 merge |
| morph type `customer` / `organization` (activity_log, attachments) | `partner` | the alias `customer` still resolves (`morphMap`), so old rows and links keep working |
| `App\Models\Organization` | removed → `App\Models\Partner` | |
| `App\Models\Customer` | kept as `Partner` + global scope `is_customer` | customer screens, documents and the 1C customer exchange |
| `Counterparty` | kept as the supplier/carrier profile (qualification, documents, ratings), `counterparties.partner_id` | renaming it to SupplierProfile was not cheap enough for this change |

### 24.2 Compatibility kept
- Route names and URLs: `customers.*` and `counterparties.*` unchanged; new `partners.index`, `partners.create`, `partners.show` (redirects to the card of the role the user may see). `customers.create` now opens the unified form with the customer role pre-selected; "New supplier/carrier" opens it with `?role=supplier`.
- 1C: `/api/v1/integration/1c/customers*` paths, `customers:read`/`customers:write` scopes, JSON field names (`customer_id`, `crm_id`, ...) and feed names are unchanged and cover partners with the customer role. The OpenAPI description and docs/integration-1c*.md say so. OpenApiSpecTest stays green.
- Permissions: new `partners.view` (all organizations: admin read, manager, management/CEO, purchase and logistics heads). `customers.*` and `counterparties.*` stay and keep their meaning; `PartnerPolicy` maps: list = partners.view, else customers.view (customer role, own scope) and/or counterparties.view (suppliers/carriers); create/role changes = the right of that role (customers.create, counterparties.manage). There is no separate `partners.create`.
- Naming collisions renamed: the lead source `partner` keeps its stored value; its label is now "Dealer / reseller" (not "Dealer / referral", because "Referral" is already a separate source). Supplier/carrier portal users are "supplier users" in UI texts ("Supplier portal", "Portal (customer / supplier user)"). The client-facing portal still says customer/company.

### 24.3 New partner (unified create)
`/partners/create` (`App\Livewire\Partners\Form`, action `CreatePartner`): role checkboxes Customer, Supplier, Carrier (at least one; only the roles the user may create: sales see Customer, purchase/logistics see Supplier and Carrier, managers all). Identity once (name, legal name/type, tax ID with the duplicate check, address, phone/e-mail, language, owner, currency, notes, first contact). Customer section: sale type, payment terms (commercial-terms right). Supplier/carrier section: qualification starts as Prospect; carrier fleet size, TIR, ADR; documents, ratings and bank details on the supplier card. Tax ID unique per partner: an existing one is refused and "Add the chosen roles to it" is offered (re-derived from the tax ID on the server). Only the customer role joins the 1C customer outbox (unchanged rule).

### 24.4 Edit roles
On the customer and supplier cards (`App\Livewire\Partners\Roles`, action `ChangePartnerRoles`): add or remove roles; at least one remains. Removing is refused while used: customer → offers, orders, open leads; supplier/carrier → tender invitations, bids, awards, purchase orders. Nothing is deleted: a removed customer role hides the partner from customer screens; a supplier profile without roles is archived and restored when a role returns. Audited (`roles_changed`). Customer-side identity is still edited on the customer card.

### 24.5 Partners list
`/partners` in Sales, directly before Customers (Customers and Suppliers & carriers stay as filtered views; Customer requests stay right after Customers). Filters: role, status (active/archived/all), owner (mine), 1C sync; role badges; each row opens the right card.

### 24.6 Not automated / open
- `Customer*` class names that mean "customer role" (CustomerPolicy, Customers\* Livewire, CustomerOutbox) are kept on purpose; only classes that meant "any organization" were renamed.
- The `.claude/skills` files still describe customers/counterparties; the proposed text changes are in the hand-off report (agent messages are not approval for config edits).
- MySQL: verified on SQLite copies only (up/down/up, `PRAGMA foreign_key_check`); the DBA should review the rename on MySQL 8 before production.
- Tests: `tests/Feature/Partners/PartnerTest.php`.

## 17.10 Default language and admin matching of Telegram users (user requirement 2026-10-19)
**Default language.** Users > create/edit has "Default language for the CRM" (uz default, ru, en; `users.locale`, validated by the Locale enum), shown on the user list and in My cabinet (users change their own). `SetLocale` order is now: an explicit choice in this session (the switcher) → the user's default → uz. The switcher changes the session only; its "Make <language> my default" item saves it on the user. Notifications, e-mails and Telegram messages to the user use the default language.

**Matching.** Users > edit > Telegram and Settings > Telegram > "Unmatched Telegram users" list people the bot has seen with no CRM user and no link (staff or portal), no bots; members seen only in customer groups are hidden unless "Include members of customer groups" is ticked. Columns: name, @username, language, first/last seen, where seen (private chat, company group). "Link to this user" / "Match to user".

**Proof of ownership (plan-check).** Staff used to link themselves (deep link = proof), and bot commands and internal notifications trust the linked Telegram id. An administrator's pick alone could link the wrong person, so:
- a link made by an administrator is **pending verification** (`telegram_links.verified_at` null): no Telegram notifications, no login codes, no bot commands; the CRM sends nothing automatically;
- the user confirms in My cabinet: "Confirm that @name is you" → "Send code" makes the bot send a 6-digit code to that exact chat (10 minutes, 5 attempts, 3 codes per 10 minutes) → entering it verifies the link (`verification_method = bot_code`);
- or an administrator with the new permission `users.telegram.force_link` (admin only) records "Verified in person" with a reason of at least 10 characters (`in_person`, audited);
- one Telegram id per user and one user per Telegram id; ids linked to a portal user are refused with a clear message; a user who already has a link must be unlinked first;
- existing links (made by the users) were marked verified (`self_link`); links created for group members who get portal access count as verified (`group_member`, their sign-in codes go to that chat).
Every link, confirmation, in-person verification and unlink is written to the auth events and the user's activity log, and the user gets an in-app and e-mail notification (event `account_security`, always e-mail when deliverable). Tests: `tests/Feature/Telegram/TelegramMatchingTest.php`, `tests/Feature/LocaleSwitchingTest.php`.

### 24.7 Partner form field decisions (user and coordinator, 2026-10-19)
| # | Field | Decision (as built in `App\Livewire\Partners\Form`) |
|---|---|---|
| 1 | Partner type | Checkboxes Customer / Supplier / Carrier, several allowed, at least one; only the roles the user may create; role sections appear as boxes are ticked. |
| 2 | Legal type | Dropdown with 4 values: legal entity, sole proprietor (YaTT), individual (private person), government/budget organization (`LegalType`; string column, no schema change). No company-form dropdown (MChJ/AJ stay in the legal name). 1C: legal entity and government → `legal_entity`, sole proprietor → `sole_proprietor`; individuals are not offered to 1C (the outbound contract has no individual value; inbound individuals stay skipped) — `LegalType::oneCValue()`. |
| 3 | Names | Short name (shown everywhere) and legal name (documents); legal name required except for an individual. |
| 4 | Tax ID | Required except for an individual. UZ: 9-digit INN; an individual may give a 14-digit PINFL (optional). Other countries: 5–20 letters/digits. No checksum; duplicate check unchanged. "Sync with 1C" checkbox, off by default, needs a tax ID. |
| 5 | Country | Dropdown of all ISO countries in the UI language (PHP intl) with a search box; Uzbekistan default; pinned: Uzbekistan, Kazakhstan, Kyrgyzstan, Tajikistan, Turkmenistan, Afghanistan, Russia, China, Turkey, South Korea, Germany, UAE; then the rest alphabetically (`App\Support\Countries`). |
| 6 | City | UZ: Region then City/district dropdowns (`geo_places`: 14 regions incl. Tashkent city and Karakalpakstan, main cities/districts, uz/ru/en). Other countries: free-text city, no region. Switching away from UZ hides and clears the dropdowns (typed city kept); back to UZ shows them. Existing UZ free-text cities that match got ids (dev: 13/13); the rest stay text. |
| 7 | Address | One text area + postal code (6 digits for UZ, free elsewhere). |
| 8 | Industry | Managed list (Reference lists, uz/ru/en; seeded 13 values); free-text values were migrated: matches reuse the entry, the rest became inactive "legacy" entries (nothing lost). Filter on the Partners and Customers lists. |
| 9 | Currency | Dropdown from the managed list (UZS default; UZS, USD, EUR, RUB, CNY), same for suppliers/carriers; offers default to it and can override. |
| 10 | Phone | Free text; duplicate detection keeps comparing the normalized digits. |
| 11 | Payment terms | Number of days, default 0 (prepayment); editable with the commercial-terms right. |
| 12 | Owner | Searchable dropdown: users with sales access for customers, purchase/logistics (`counterparties.manage`) for suppliers/carriers; default = current user; choosing someone else needs an assign right (customers.change_owner, leads.assign or counterparties.qualify). |
| 13 | Document language | Uzbek / Russian / English, default Uzbek, no empty option. |
| 14 | Sale type | B2B / Retail; default from the legal type (individual → Retail, else B2B); orders can override. |
| 15 | Contact position | Combobox (datalist) with the managed positions; a typed new value becomes a "user-added" entry, reviewable in Reference lists. |
| 16 | Supplier/carrier | Supplier category (managed: manufacturer, distributor, service provider, customs broker, forwarder, carrier) + fleet size, TIR, ADR. No vehicle types or lanes. |
| 17 | Others | Website URL-validated (https:// added when missing), e-mails lower-cased, notes text area, first contact = first/last name, position, e-mail, phone. |

**Reference lists** (`reference_items`, Settings page `/settings/reference-lists`, in the Catalog menu because the Settings group stays admin-only; gate `manageReferenceLists` = `catalog.edit_local`: admins and managers): add, rename (uz/ru/en), reorder, deactivate; delete only when unused; audited. Migration `2026_10_19_114835` (tables, seed data, legacy mapping; reversible). The customer edit form uses the industry list too; region/city on the edit form are not changed yet (the stored text and ids are kept by the model).
Tests: `tests/Feature/Partners/PartnerFormDecisionsTest.php`.

### 24.8 "Create" quick actions on the customer card (user decision 2026-10-20)
- A "Create" menu button (`aria-haspopup="menu"`, `role="menu"` / `menuitem`, Escape and click outside close it) in the customer card header opens:
  - New lead for this customer (`leads.create?customer=ID`);
  - New offer (`offers.create?customer=ID`);
  - New order without offer (`orders.create?customer=ID`, only when the user may create orders for this customer's owner);
  - Record payment for orders (`payments.for-orders?customer=ID`: the customer's unpaid orders are listed);
  - Framework agreement (jumps to the Finance tab, which already has "Generate framework agreement");
  - Register equipment / service (jumps to the Equipment tab; tickets are opened from a unit).
- Each entry appears only with the ability (`Policy::create`, `orders.create` for the owner, `service.register`); nothing for archived customers or customers deleted in 1C. The card is the customer card, so the customer role is implied.
- Prefill: the existing `?customer=` parameter (kept instead of `?partner=` for consistency with the offer and payment forms). Every target form re-checks it server-side against `customers.view` (`Customer::visibleTo`); an invalid or foreign id is ignored. The customer is shown preselected and stays changeable ("Choose another customer").
- Skipped (no partner-based entry point exists): customer requests on behalf (portal-only in Spec 10), customer return goods receipt (started from the delivery note number), service ticket without a unit, supplier-side documents on the supplier card (tender invitations are made inside a tender; purchase orders come from awards). "Make this partner a customer" already exists through the roles control on the supplier card (24.4).
- Related documents: the card already has Finance (orders, agreements, credit), Equipment, Timeline (offers, orders, payments, agreements, tickets) and Activity tabs; no new tabs were added.
