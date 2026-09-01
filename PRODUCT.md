# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Owner** — full tenant access: stores, users, rules, billing, all orders.
- **Manager** — same as Owner except billing/tenant settings; manages agents and assignment/commission rules.
- **Confirmation agent** — scoped web access to only the orders assigned to them (or within their store/product scope). Their daily job: call clients to confirm orders before shipment.
- **Fulfillment agent** — mobile-only (separate app, not in this repo); no web access.

All are staff of a Moroccan cash-on-delivery (COD) e-commerce seller, using this tool as their primary daily back-office — not an occasional-use admin panel.

## Product Purpose

EasyFlow replaces manual COD order handling (spreadsheets, WhatsApp) with one platform: automatic order ingestion from e-commerce stores (Shopify, YouCan), a structured confirmation workflow for agents, automatic parcel creation at delivery couriers (OzonExpress, Sendit, ...), warehouse fulfillment tracking, and financial tracking (commissions, invoicing, courier settlement reconciliation).

Success = an Owner/Manager can see every order's true state across stores and couriers without leaving the app, and a confirmation agent can process their queue without friction or extra round trips.

## Positioning

Two independent status tracks (`confirmation_status` and `delivery_status`) instead of one collapsed status — matching how the business actually works (a confirmation agent and a courier track genuinely different things, at different times). Combined with a durable, never-lose-a-webhook ingestion queue and pre-computed stats (dashboards never hit the live orders table), this is built for correctness and volume, not a thin wrapper over spreadsheets.

## Operating Context

- Multi-tenant: one tenant per COD seller business; strict tenant isolation.
- Confirmation agents work a queue: client name, phone, address, product(s), price — one screen, with a one-tap WhatsApp deep link.
- Owner/Manager use a cross-store, cross-status order overview (bottleneck visibility) and courier settlement reconciliation (expected vs. actual amounts collected).
- The confirmation screen is the highest-traffic, highest-stakes screen: must respond in near-real time, no more than one network round trip.
- Test orders (`is_test`) flow through the same UI but must be visibly badged everywhere and never leak into stats, delivery, or blacklist logic.

## Capabilities and Constraints

- Client PII (name, phone, address) is encrypted at rest; must never appear in logs or error messages — UI and error states must not accidentally surface raw PII in ways that get logged.
- Every status transition is audited (actor, timestamp, reason); cancellations/returns use structured reason codes, not free text (except `other` + note).
- Backward status transitions (e.g. `confirmed` → `assigned`) are Owner/Manager-only and must always capture a reason.
- Dashboards and stats are pre-computed (near-real-time via events, reconciled on a schedule) — not live aggregates.
- Fulfillment (barcode/QR scan-driven) is mobile-only; not a web surface.

## Brand Commitments

- Product name: **EasyFlow**. Existing marketing site (`resources/js/pages/welcome.tsx`) establishes the name and initial feature framing (connect stores in minutes, route to the right courier, confirm before shipping).
- No confirmed language/locale requirement yet (French UI is common for this market but unconfirmed) — leave as an open decision, don't assume.
- **Auth/public pages (login, register, password reset, ...): category-standard canon, executed at Linear's craft bar** — user-chosen standing preference (2026-08-05) over a proposed zellige direction. Minimal centered column, no split panel, no decorative world; craft lives in spacing, type, and focus states.

## Evidence on Hand

Pre-launch: no live tenants, testimonials, case studies, or real customer data exist yet. Future work must not fabricate any of these.

## Product Principles

1. Two status tracks, never collapsed — confirmation state and delivery/fulfillment state are different questions with different owners and different reactions.
2. Never silently lose or strand an order — unassigned, delivery-creation-failed, and stale-in-transit states must stay visibly flagged, not hidden.
3. The confirmation queue is the product's core daily tool — optimize it for speed and minimal round trips above all other screens.
4. Test orders are structurally identical to real ones but must never leak into stats, courier dispatch, or blacklist logic, and must always be visibly marked.
5. Tenant isolation and PII protection are non-negotiable at every layer, including UI states and error messages.

## Accessibility & Inclusion

No confirmed accessibility standard has been established yet.
