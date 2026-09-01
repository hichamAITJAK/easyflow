# Product Requirements Document
## COD Order Management Platform — Technical Specification

**Version:** 2.0 (supersedes v1.0 — full refactor)
**Audience:** Engineering. This document describes what to build. It does not
cover business model, hosting, or rollout planning.

---

## 1. Purpose

A multi-tenant platform for Moroccan cash-on-delivery (COD) e-commerce
sellers. It replaces manual order handling (spreadsheets, WhatsApp) with:
automatic order ingestion from e-commerce platforms, a structured
confirmation workflow for agents, automatic parcel creation at delivery
couriers, warehouse/fulfillment tracking via barcode scanning, and financial
tracking (commissions, invoicing, courier settlement reconciliation).

The platform has a web application (Laravel + Inertia) and a mobile
application (Expo/React Native) for confirmation agents and fulfillment
agents.

## 2. Actors and roles

| Role | Platform | Scope |
|---|---|---|
| **Owner** | Web (full access), Mobile (Admin navigator) | Full tenant access: stores, users, rules, billing, all orders |
| **Manager** | Web (scoped access), Mobile (Admin navigator) | Same as Owner except billing/tenant settings; manages agents and assignment/commission rules |
| **Confirmation agent** | Web (scoped), Mobile (Confirmation navigator) | Only orders assigned to them (or within their store/product scope) |
| **Fulfillment agent** | Mobile (Fulfillment navigator) only | Only confirmed orders ready to ship, across all stores in the tenant — no web access needed |

On mobile, **Owner and Manager share the same navigator** ("Admin"),
differentiated internally by permission checks, not separate app builds.
Confirmation agent and Fulfillment agent each get a fully separate
navigator — different screens, different navigation structure, not the same
screens with hidden sections. See section 9 for why.

## 3. System modules

The platform is organized into these functional modules. Each is expanded
into detailed use cases in section 6.

1. **Stores** — connect and manage e-commerce store integrations
2. **Products** — catalog synced from stores, used for scoping and stats
3. **Orders** — the central entity; ingestion, assignment, confirmation, status tracking, manual creation, test orders
4. **Confirmers** — confirmation agent management, scope, performance
5. **Delivery** — courier integrations, parcel creation, tracking, settlement
6. **Blacklist** — risky/repeat-refusal client tracking
7. **Commissions** — per-agent earnings ledger and rules
8. **Invoicing** — periodic settlement statements derived from the commission ledger
9. **Statistics** — confirmation rate, winning products, revenue, trends
10. **Team performance** — per-agent and team-wide performance tracking and alerts
11. **Order status overview** — cross-store, all-status visibility for owners/managers
12. **Fulfillment** — warehouse-side scan-driven parcel handling
13. **Test orders** — isolated order flow for internal testing, with no side effects

## 4. Non-functional requirements

| Category | Requirement |
|---|---|
| Performance | The confirmation screen (agent's primary daily tool) must respond in near-real time. No screen central to the confirmation workflow should require more than one network round trip to render. |
| Security | Client PII (`name`, `phone`, `address`) is encrypted at the application layer before persistence. No plaintext PII in logs, error messages, or exception reports. |
| Tenant isolation | No tenant can access another tenant's data under any circumstance, including via guessed/manipulated IDs, background jobs, or admin tooling. Enforced at two layers: model-level global scope + policy checks (see section 8). |
| Scalability | Data model and query patterns must support high order volume per tenant without degrading confirmation-screen performance. Use indexed, tenant-scoped composite indexes; avoid unbounded table scans; pre-compute aggregate statistics rather than calculating them live. |
| Auditability | Every order status change is logged with actor, timestamp, and reason (where applicable). Commission entries are append-only. Cancellation and return reasons are structured, not free text. |
| Rate limiting | Inbound webhooks and the platform's own API are rate-limited per tenant/per store, not just per IP. |

## 5. Technical stack (fixed decisions)

- **Backend:** Laravel, MySQL/MariaDB (not PostgreSQL — decided; no
  Row-Level Security, tenant isolation is enforced in application code)
- **Web frontend:** Inertia.js + Vite, Laravel Wayfinder for route/action
  generation, Laravel Fortify for authentication
- **Mobile:** Expo + React Native, three separate navigators by role (see
  section 9), token auth via Laravel Sanctum
- **Cache/rate limiting:** Redis — cache and rate limiting only, never a
  durable queue
- **Order ingestion queue:** durable, database-backed queue table — webhook
  payloads must never be at risk of loss under memory pressure
- **Push notifications:** Expo push service (wraps FCM/APNs) — used instead
  of client polling for time-sensitive events (new order assigned, status
  updates)
- **Third-party integrations (stores and couriers):** layered pattern —
  Controller → Operation Service → Manager → Connection Service. Every
  connection service implements a shared interface for its domain and
  translates the provider's raw response into a shared DTO before returning
  it. See section 8 for the enforced rules.
- **Analytics:** stats are pre-computed via a queued event listener (updates
  near-real-time on relevant events) with a scheduled reconciliation job as
  a correctness safety net. Dashboards never query the live `orders` table
  directly for aggregates.

## 6. Detailed use cases

### 6.1 Stores

**UC-1: Connect a store**
Actor: Owner. Owner selects a platform (Shopify, YouCan, ...), authorizes
the connection, system stores encrypted credentials, registers webhooks
(order create/update/cancel), and imports recent historical orders in the
background.

**UC-2: Multi-step store + courier onboarding**
A guided flow: (1) choose e-commerce platform, (2) connect and authorize
the store, (3) connect one or more delivery couriers for that store,
specifying covered cities per courier. Courier connection is optional at
this stage — a store can be fully functional with delivery accounts added
later, with orders flagged for manual delivery creation in the meantime.
Store setup progress is persisted so an interrupted setup can resume.

### 6.2 Orders — ingestion and lifecycle

**UC-3: New order arrives via webhook**
Inbound webhook is signature-verified, deduplicated by
`(store_id, external_order_id)`, written to a durable queue table, and
processed asynchronously: normalized, PII-encrypted, checked against
blacklist/duplicate rules (unless `is_test`, see UC-13), and created with
status `new`.

**UC-4: Nightly reconciliation**
For each connected store, re-fetch recent orders and import anything
missing (recovers from a missed/failed webhook). Idempotent.

**UC-5: Add an order manually**
Actor: Owner, Manager, or Confirmation agent (per tenant configuration).
A manual entry form (client name, phone, address, city, product(s),
quantity) creates an order with `source_platform = 'manual'`. It goes
through the same duplicate/blacklist checks as any other order and enters
the same status pipeline starting at `new` (or `assigned`, if created
directly for a specific agent).

**UC-6: Assign an order to a confirmation agent**
Automatic (round-robin/load-based, per store/product rule) or manual
override by Owner/Manager. If no eligible agent exists for the scope, the
order remains `new` and is visibly flagged as unassigned — never silently
lost.

**UC-7: Agent confirms an order**
Agent sees client name, phone, address, product(s), price on one screen;
can edit before confirming; has a one-tap WhatsApp deep link to the
client's number. Confirming transitions status to `confirmed`, fires
`OrderConfirmed`, which triggers commission calculation and the delivery
push (unless `is_test`).

**UC-8: No-answer / retry handling**
Agent marks `no_answer` with a scheduled follow-up (configurable delay).
After a configured max number of attempts, system auto-transitions to
`cancelled` with reason `client_unreachable`.

**UC-9: Cancellation with structured reason**
Cancelling an order (agent, manager, or system) requires a reason code
from a fixed list (see section 7.3). Free text is only allowed alongside
the `other` code, never as a substitute for it.

**UC-10: Duplicate / blacklist detection**
On order creation, the client's phone (via HMAC hash, never decrypted for
comparison) is checked against recent orders and the tenant's blacklist.
Matches are flagged visibly on the order for the assigned agent to see
before proceeding. Skipped entirely for `is_test` orders.

**UC-11: Order status overview**
Actor: Owner, Manager. A cross-store view grouping all active orders by
status (e.g. a column-per-status board), so bottlenecks are visible
without opening individual orders. Reflects current data — should be
backed by an indexed, filterable query (by store, agent, date range), not
a full unbounded scan.

### 6.3 Delivery

**UC-12: Auto-create a parcel at the delivery courier**
On `confirmation_status = confirmed`, the system selects the tenant's
delivery account covering the client's city, calls that courier's
connection service, and on success stores the tracking number, sets
`confirmation_status = submitted_to_courier`, and initializes
`delivery_status = awaiting_pickup`. On failure (no covering courier, or
API error after retries), the order is flagged for manual delivery
creation — never silently stuck. Skipped entirely for `is_test` orders
(see UC-25).

**UC-13: Track delivery status updates**
Courier webhook or scheduled poll updates `delivery_status` through
`in_transit` → `delivered`, or → `returned_in_transit` with a structured
return reason (section 7.4). `delivered` finalizes commission (UC-16).
`returned_in_transit` is the courier's claim only — see UC-17 for the
physical confirmation step that follows.

**UC-14: Delivery attempt failed**
Distinct from `no_answer` (which happens pre-shipment, on
`confirmation_status`). When the courier's driver cannot reach the client
or the address at the point of delivery, `delivery_status` moves to
`delivery_attempt_failed`, with its own retry count and scheduled
re-attempt, separate from the confirmation-stage retry counter.

**UC-15: Expected courier settlement**
For each delivery account, the system computes the expected amount to be
collected from clients and eventually transferred to the seller: the sum
of `total_amount` for orders in `delivered` status, attributed to that
courier, within a selectable date range. Owner/Manager can record the
*actual* amount received from the courier for a given period; the system
displays the variance (expected vs. actual) for reconciliation. This is a
report + a manual entry point — it does not require a live payout API from
the courier.

### 6.4 Fulfillment (mobile, scan-driven)

**UC-16: Fulfillment queue**
Actor: Fulfillment agent (mobile only). A single list of all orders with
`confirmation_status = submitted_to_courier`, ready to ship, aggregated
across every store in the tenant — not scoped to one store. This is
intentionally a different scope model than confirmation agents use.

**UC-17: Scan-driven status updates**
Fulfillment agents never manually select a status from a menu. Every
`delivery_status` transition on the fulfillment side happens by scanning a
barcode/QR code (containing the order's tracking number) with the phone
camera:
- Scan when packing/preparing, before the courier has collected the
  parcel → sets `delivery_status = ready_for_pickup` and logs a
  `ParcelReadyForPickup` event. This is distinct from `awaiting_pickup`
  (which only means the parcel was registered at the courier) — this scan
  confirms the parcel is physically staged and ready, from the warehouse
  side.
- Scan when a returned parcel physically arrives back at the warehouse →
  sets `delivery_status = return_received` and logs a
  `ParcelReturnReceived` event. This closes the loop distinctly from the
  courier's own `returned_in_transit` update (UC-13), which only reflects
  what the courier's system reports, not physical warehouse receipt.
  Blacklist scoring and stock-return logic should key off
  `return_received`, not `returned_in_transit`, since the courier's report
  alone isn't a confirmed physical fact yet.

**UC-18: Notify on shipment**
When `delivery_status` transitions to `in_transit` (UC-13, courier
confirms collection), the system sends a notification (push, and
optionally WhatsApp to the client) that the order is now out for delivery.
The `ready_for_pickup` scan (UC-17) triggers a separate, internal-only
notification (e.g. to the owner/manager), since it reflects warehouse
readiness, not confirmed courier movement.

Lookup for every scan is by tracking number → order, a single indexed
query; no manual order search should be required in this flow.

### 6.5 Confirmers (agent management and CRM)

**UC-19: Manage confirmation agents**
Actor: Owner, Manager. List agents, invite new ones, assign scope (store
and/or product-level, per `agent_scopes`), deactivate/reactivate.

**UC-20: Best-client outreach**
Actor: Confirmation agent. A list of the agent's past clients who are good
candidates for a new product suggestion — e.g. clients with a `delivered`
order history and no returns/blacklist flags, sorted by order count or
recency. Each entry has a one-tap WhatsApp deep link, same pattern as
UC-7's confirmation contact flow. This is a proactive outreach tool, not
tied to a specific incoming order.

**UC-21: New order notification**
Actor: Confirmation agent (mobile + web). Push notification the moment an
order is assigned to them (see UC-6), so they don't need to keep the app
open or poll for updates.

**UC-22: Performance warning**
When a confirmation agent's rolling confirmation rate (or another
configured metric) drops below a tenant-configured threshold over a
configured period, the system notifies the agent (and optionally their
manager). This reads from the same pre-computed stats used for dashboards
(section 6.6) — it does not run its own live aggregation.

### 6.6 Statistics and team performance

**UC-23: Dashboard statistics**
Confirmation rate, winning products, delivery success rate, revenue —
filterable by date range, store, agent. Read from a pre-computed summary
table, updated near-real-time via queued event listeners on order status
changes, with a scheduled job that fully recalculates the summary as a
correctness safety net. `is_test` orders are excluded from every
aggregate, unconditionally (section 6.7).

**UC-24: Team performance and progress tracking**
Actor: Owner, Manager. A view of performance trends over time per agent
and per team (not just a snapshot) — e.g. week-over-week confirmation
rate, so a manager can see whether a change (new assignment rule, new
agent) is helping or hurting. Backed by the same pre-computed summary
table, queried across a date range rather than a single point in time.

### 6.7 Test orders

**UC-25: Create a test order**
Actor: Owner or Manager only. Creates an order identical in structure to a
real one, flagged `is_test = true`. Hard rules, enforced at the code level,
not just the UI:
- **Never included in any statistics aggregate** — the stats event
  listener and the reconciliation job must both filter `is_test = false`
  unconditionally. This is not a display-layer filter; it must be
  impossible to leak into `daily_stats_summary`.
- **Never sent to a real delivery courier.** The delivery Manager service
  must check `is_test` before resolving/calling any connection service. On
  a test order reaching `confirmed`, the delivery step is skipped entirely
  and the order is marked with a distinct terminal state (e.g.
  `test_completed`) rather than `sent_to_delivery`.
  courier account.
- **Excluded from blacklist/duplicate checks** — a test order's (likely
  fake or reused) phone number must not pollute the blacklist or trigger
  duplicate flags on real orders.
- **Visibly marked in every UI** it appears in (a persistent badge/label on
  the order, in both web and mobile), so no confirmation or fulfillment
  agent mistakes it for a real shipment.
- May still be assignable to a real confirmation agent, if the test is
  specifically meant to exercise the assignment/confirmation flow — this
  does not conflict with the stats exclusion rule above.

## 7. Order status state machine

Orders carry **two independent status fields**, not one — this matches how
the business already operates (confirmation agents and delivery/fulfillment
track different things, on different systems, at different times) and
avoids collapsing two different questions ("did the client confirm?" vs.
"where is the physical parcel?") into a single value.

- **`confirmation_status`** — owned by the confirmation workflow, set by
  agents (or the system, for automated transitions like auto-cancel).
- **`delivery_status`** — owned by the delivery/fulfillment workflow, set
  by courier webhooks/polls and by fulfillment agent scans. Only populated
  once `confirmation_status` reaches `submitted_to_courier`.

### 7.1 Confirmation statuses

| Status | Meaning | Set by |
|---|---|---|
| `new` | Order just arrived, unassigned | System (ingestion) |
| `assigned` | Given to a confirmation agent | System (auto) or Manager |
| `confirmed` | Client confirmed the order | Agent |
| `confirmed_followup` | Confirmed, but needs a follow-up (missing detail) before shipment | Agent |
| `callback` | Agent needs to call again later (client asked to be called back) | Agent |
| `fake` | Agent suspects a fake/prank order | Agent |
| `voicemail` | Reached voicemail, not a live no-answer | Agent |
| `no_answer` | No answer on the phone | Agent |
| `busy` | Line was busy | Agent |
| `whatsapp_sent` | Agent messaged on WhatsApp instead of calling, awaiting reply | Agent |
| `cancelled` | Closed before shipment | Agent, Manager, or System (auto, after max no-answer attempts) |
| `submitted_to_courier` | Parcel successfully created at the delivery courier (API success) — terminal state for the confirmation side | System |
| `test_completed` | Terminal state for confirmed test orders (UC-25) | System |

`is_duplicate_flagged` remains a separate boolean flag (UC-10), not a
status value — it can be true alongside any of the statuses above.

### 7.2 Delivery statuses

Only relevant once `confirmation_status = submitted_to_courier`.

| Status | Meaning | Set by |
|---|---|---|
| `awaiting_pickup` | Parcel registered at courier, not yet collected | System (on submission) or courier webhook |
| `ready_for_pickup` | Fulfillment agent has scanned and physically staged the parcel; courier has not yet collected it | Fulfillment agent scan |
| `in_transit` | Courier has collected the parcel and it's on the way | Courier webhook/poll |
| `postponed` | Delivery rescheduled | Courier webhook/poll |
| `changed` | Client changed something after shipment (address, product) | Courier webhook/poll |
| `delivery_attempt_failed` | Courier's driver couldn't reach the client/address at the point of delivery | Courier webhook/poll |
| `refused` | Client refused the parcel at the door | Courier webhook/poll |
| `delivered` | Client received and paid | Courier webhook/poll |
| `returned_in_transit` | Courier reports the parcel as being sent back; physically still with the courier | Courier webhook/poll |
| `return_received` | Fulfillment agent has scanned the returned parcel back into the warehouse; it is physically here, not with the courier | Fulfillment agent scan |
| `cancelled_at_courier` | Parcel cancelled on the courier's side | Owner/Manager action (via courier cancel API) |

`return_received` is the status that should drive any downstream business
logic that depends on the return being *certain* (e.g. blacklist scoring,
stock restocking) — `returned_in_transit` is only the courier's claim and
can still be reversed or wrong.

### 7.3 Transition rules

- Exactly one service method is permitted to change `confirmation_status`
  directly, and exactly one separate service method is permitted to change
  `delivery_status` directly (see section 8). No controller, job, or
  webhook handler sets either column directly.
- Every confirmation-status transition fires a generic
  `ConfirmationStatusChanged` event; every delivery-status transition fires
  a generic `DeliveryStatusChanged` event — both unconditionally, feeding
  the audit log and stats. Specific events (`OrderConfirmed`,
  `OrderCancelled`, `OrderDelivered`, `OrderReturned`) fire in addition,
  for statuses with dedicated business logic.
- Backward transitions (e.g. `confirmed` → `assigned`) are permitted only
  for Owner/Manager, must always be logged with a reason, and must never
  bypass the audit log.
- A parcel that needs to be cancelled after `submitted_to_courier` requires
  calling the courier's cancel-parcel operation (via the same connection
  service interface), not just a local status change — this is what sets
  `cancelled_at_courier`.
- A safety job flags orders that haven't changed `delivery_status` in more
  than a configurable number of days while in `awaiting_pickup`,
  `ready_for_pickup`, or `in_transit`, for manual review — these must never
  silently disappear from visibility.

### 7.4 Structured reason codes

**Cancellation reasons** (`orders.cancellation_reason_code`, applies to
`confirmation_status = cancelled`):
`client_unreachable`, `client_changed_mind`, `price_too_high`,
`found_cheaper`, `duplicate_order`, `blacklisted_client`,
`invalid_address`, `invalid_phone`, `out_of_stock`, `fraud_suspected`,
`agent_error`, `other` (requires accompanying free-text note).

**Return reasons** (`orders.return_reason_code`, applies to
`delivery_status = returned_in_transit` / `return_received`):
`client_refused`, `client_unreachable_at_delivery`, `wrong_address`,
`payment_issue`, `damaged_in_transit`, `other` (requires accompanying
free-text note).

These are two separate enums — never merged — since "why did we cancel
before shipping" and "why did delivery fail" are different questions with
different downstream reactions (e.g. repeated `client_refused` or
`client_unreachable_at_delivery` should feed into blacklist scoring;
pre-shipment cancellations should not).

## 8. Integration architecture (stores and couriers)

Both store integrations (Shopify, YouCan, ...) and delivery courier
integrations (Speedaf, Sendit, ...) follow the same layered pattern:

```
Controller
  → Operation Service   (business logic, orchestration)
    → Manager            (resolves which connection service to use)
      → Connection Service  (implements a shared interface; talks to ONE
                              provider; translates its raw response into a
                              shared DTO before returning)
```

Rules, enforced in code review / CI, not just convention:
- Two separate interfaces: `StoreConnectionInterface` and
  `DeliveryConnectionInterface` — never merged, since their operations
  differ (fetch orders / register webhooks vs. create parcel / get status).
- A connection service never returns a provider's raw response array to
  the Operation Service — it must translate to a shared DTO first, keeping
  the raw payload only as an optional field for logging.
- The Operation Service never branches on provider/courier name. That
  branching is only permitted inside the Manager's resolution method.
- Both requests into and responses out of a connection service are DTOs,
  never raw arrays or Eloquent models.
- Adding a new store platform or courier requires adding exactly one new
  connection service class and registering it in the Manager — nothing
  else in the codebase changes.

## 9. Mobile application architecture

Three separate navigators, selected once at login based on
`user.role`, not a single screen tree with hidden sections:

- **Admin navigator** (Owner + Manager): broad access — stores, products,
  users/confirmers, assignment/commission rules, order status overview,
  statistics, invoicing, courier settlement.
- **Confirmation navigator**: order queue (scoped to the agent), the
  single-screen confirmation UI (UC-7), best-client outreach (UC-20),
  personal performance (UC-22).
- **Fulfillment navigator**: the unified ready-to-ship queue (UC-16), the
  camera scan screen (UC-17), and nothing else — no order editing, no
  manual status controls.

This separation exists because the three jobs share almost no UI, not
because of a permissions technicality — building one shared screen tree and
hiding blocks per role would carry unused code and navigation complexity
into every agent's app for jobs they never do.

Shared across all three navigators: authentication (Sanctum tokens), the
underlying API client, and any common design-system components. Only the
navigation structure and screens differ.

**Push notifications:** Expo's push service is used for time-sensitive
events (`OrderAssigned` → confirmation agent, the `in_transit`
`DeliveryStatusChanged`-triggered shipment notice, performance warnings).
The app registers a push token on
login and sends it to the backend; the backend calls Expo's push API
directly — no client-side polling loop for these events.

**Camera scanning:** implemented via the device camera reading QR/barcode
formats. The scanned value is the order's tracking number, looked up
directly against the `orders` table (indexed on tracking number) — no
manual search step. Scan input must be debounced to avoid re-triggering
the lookup many times per second while the camera holds the code in frame.

## 10. Data model (entities)

Core entities, each carrying an indexed `tenant_id` except where noted:

- **`tenants`** — the paying business
- **`users`** — role: `owner`, `manager`, `confirmation_agent`,
  `fulfillment_agent`
- **`agent_scopes`** — links a user to a store and/or product they can
  access
- **`stores`** — platform, encrypted credentials, webhook secret,
  connection status
- **`products`** — synced from a store
- **`delivery_accounts`** — courier, encrypted credentials, covered cities
- **`client_blacklist_entries`** — phone hash + reason, tenant-scoped
- **`orders`** — see below for the full field set relevant to this
  refactor
- **`order_items`**
- **`order_status_events`** — append-only audit log of every transition
- **`commission_rules`** — per agent/store/product, trigger status,
  amount/percentage
- **`commission_ledger_entries`** — append-only; corrections are reversal
  entries, never edits
- **`invoices`** *(new)* — a generated settlement statement over a period,
  summarizing ledger entries for one agent or the whole team; references
  the ledger entries it covers rather than duplicating amounts
- **`courier_settlements`** *(new)* — one row per delivery account per
  reconciliation period, storing the computed expected amount and the
  manually recorded actual amount received
- **`order_ingestion_queue`** — durable webhook processing queue
- **`daily_stats_summary`** — pre-computed dashboard source, always
  excludes `is_test` orders
- **`device_tokens`** *(new)* — push notification tokens, linked to a user,
  supports multiple devices per user

**Key fields on `orders` relevant to this refactor** (in addition to those
already defined — client PII encrypted, store/agent references):
- `confirmation_status` (see section 7.1) and `delivery_status`, nullable
  until `confirmation_status = submitted_to_courier` (see section 7.2) —
  two separate columns, replacing the single `status` field from the
  original design
- `is_test` (boolean, default false)
- `source_platform` (includes `'manual'` as a valid value alongside store
  platform names)
- `cancellation_reason_code`, `return_reason_code` (nullable, per section
  7.4)
- `ready_for_pickup_at`, `return_received_at` (nullable timestamps,
  mirroring the corresponding `delivery_status` values, set only via
  fulfillment scan actions per UC-17 — kept as explicit timestamp columns
  in addition to the status enum so "time spent in this state" can be
  queried directly without scanning `order_status_events`)
- Composite indexes must include `tenant_id` and `created_at` in every
  access-pattern index used by dashboards or agent queues. Add a dedicated
  index on `(tenant_id, delivery_status)` for the fulfillment queue (UC-16)
  and on tracking number for scan lookups (UC-17).

## 11. Event catalog

Events with dedicated business logic attached (build these):
`OrderCreated`, `ConfirmationStatusChanged` (generic, always fires),
`DeliveryStatusChanged` (generic, always fires), `OrderAssigned`,
`OrderConfirmed`, `OrderCancelled`, `OrderSubmittedToCourier`,
`OrderDelivered`, `OrderReturnedInTransit`, `ParcelReadyForPickup`,
`ParcelReturnReceived`, `OrderDeliveryCreationFailed`, `CommissionEarned`,
`CommissionReversed`, `ClientFlaggedBlacklist`,
`AgentPerformanceWarningTriggered`, `WebhookSignatureVerificationFailed`.

Note: `OrderReturnedInTransit` (courier's claim) and `ParcelReturnReceived`
(warehouse-confirmed) are separate events precisely so that listeners with
different confidence requirements can pick the right one — e.g. a
"notify owner a return is coming" listener can use the former, while
blacklist-scoring and stock-restocking listeners must use the latter.

Every listener that reacts to order lifecycle events must check
`order.is_test` where relevant (commission, stats, delivery dispatch) and
no-op for test orders rather than relying on the event never firing for
them — defense in depth against the test-order rules in UC-25 being
bypassed by a future change elsewhere in the codebase.

### 11.1 Implementation status

All classes below exist under `app/Events/`. "Dispatched" means a real
code path fires it today; "seam only" means the class exists (with a
docblock explaining what should call it) but nothing does yet, usually
because the feature that would trigger it isn't built (courier dispatch,
fulfillment scanning, performance monitoring).

| Event | Namespace | Status | Fired from |
|---|---|---|---|
| `OrderCreated` | `Order` | Dispatched | `OrderService::createManualOrder`, `OrderSyncService::upsertOrder` (new rows only) |
| `ConfirmationStatusChanged` | `Order` | Dispatched | `OrderService::updateStatus` |
| `DeliveryStatusChanged` | `Order` | Dispatched | `OrderService::updateDeliveryStatus` |
| `OrderAssigned` | `Order` | Dispatched | `OrderService::assign` (auto via `AssignAgentOnOrderCreated`, manual via `OrderController::assign`) |
| `OrderConfirmed` | `Order` | Dispatched | `OrderService::updateStatus` |
| `OrderCancelled` | `Order` | Dispatched | `OrderService::updateStatus` |
| `OrderSubmittedToCourier` | `Order` | Dispatched | `OrderService::updateStatus` (via `createShipment`) |
| `OrderDelivered` | `Order` | Dispatched | `OrderService::updateDeliveryStatus` |
| `OrderReturnedInTransit` | `Order` | Dispatched | `OrderService::updateDeliveryStatus` |
| `ParcelReadyForPickup` | `Order` | Seam only | UC-17 fulfillment scan endpoint not built yet |
| `ParcelReturnReceived` | `Order` | Seam only | UC-17 fulfillment scan endpoint not built yet |
| `OrderDeliveryCreationFailed` | `Order` | Seam only | Courier dispatch not built yet (`SenditService`/`OzonExpressService::addParcel` are unfinished stubs) |
| `ClientFlaggedBlacklist` | `Order` | Dispatched | `CheckBlacklistOnOrderCreated` listener |
| `CommissionEarned` | `Commission` | Dispatched | `CalculateAgentCommission` listener |
| `CommissionReversed` | `Commission` | Seam only | No code creates a reversal ledger entry yet |
| `AgentPerformanceWarningTriggered` | `Performance` | Seam only | No performance-monitoring job exists yet |
| `WebhookSignatureVerificationFailed` | `Webhook` | Dispatched | `ShopifyWebhookController`, `YouCanWebhookController` |

Not in this list but implemented for the same reasons (decoupling a
side effect from the code that triggers it): `App\Events\Store\StoreConnected`,
fired once a store's row exists as connected — feeds
`SyncStoreDataOnConnected` (initial product/order import) and
`RegisterOrderWebhookOnConnected` (per-platform webhook subscription via
`EcomPlatformInterface::registerOrderWebhook()`), both queued.

Listeners currently wired (see `app/Listeners/`):
`CheckBlacklistOnOrderCreated`, `CheckDuplicateOnOrderCreated`,
`AssignAgentOnOrderCreated`, `RecordOrderCreatedStat` (queued),
`LogOrderStatusEvent` (the sole writer of `order_status_events`, on both
status-changed events), `UpdateDailyStatsOnStatusChanged` (queued, on both
status-changed events), `CalculateAgentCommission`.

## 12. Open items requiring a business decision before implementation

- **Commission direction for "invoicing"**: confirm whether the
  amount tracked is what the manager owes the agent (standard commission
  payout) or a different direction/structure. The ledger and invoice
  design in this document assumes the former; confirm before building
  UC-8/UC-15 (`invoices` table).
- Number of contact attempts before auto-cancel on `no_answer` (UC-8).
- Number of delivery attempts before `delivery_attempt_failed` escalates
  to a cancellation/return path (UC-14).
- Performance warning thresholds and measurement period (UC-22) — must be
  configurable per tenant, but a sensible default needs to be chosen.
- Whether manual order creation (UC-5) is available to confirmation agents
  or restricted to Owner/Manager only.
- Exact fields required for a manual order (UC-5) — minimum viable set vs.
  full parity with store-sourced orders.