---
target: order/queue.tsx page
total_score: 21
max_score: 40
na_heuristics: 
p0_count: 1
p1_count: 2
timestamp: 2026-08-03T19-34-50Z
slug: resources-js-pages-orders-queue-tsx
---
## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 2 | No success toast after status change — pane just silently refetches |
| 2 | Match System / Real World | 3 | Call-outcome and terminal statuses sit in one undifferentiated list |
| 3 | User Control and Freedom | 2 | Only "cancelled" has a confirm/undo path; every other status PATCHes instantly |
| 4 | Consistency and Standards | 3 | Internally consistent (reuses Card/Badge/OrderDetail) — but that consistency is also why it reads generic |
| 5 | Error Prevention | 2 | Single-click status changes with no confirmation outside cancellation |
| 6 | Recognition Rather Than Recall | 3 | Color-coded badges, labeled icons; but 12-item flat status Select forces recall |
| 7 | Flexibility and Efficiency | 1 | No keyboard flow through the queue, no quick-action shortcuts, despite this being the app's highest-frequency power-user screen |
| 8 | Aesthetic and Minimalist Design | 2 | Card-in-Card-in-Card nesting (pane Card -> 6 stacked OrderDetail Cards) adds chrome to a scan-fast tool |
| 9 | Error Recovery | 1 | Detail fetch failure has no error branch — UI silently hangs on skeleton/stale data |
| 10 | Help and Documentation | 2 | No contextual hints (e.g. what "Follow-up" bucket means); fine for trained agents, nothing for ramp-up |
| **Total** | | **21/40** | **Acceptable** |

## Design Specificity Verdict

**LLM assessment**: Reads as a generic list+detail admin screen with COD fields dropped in, not a tool built around "hundreds of calls a day." The card shows name/phone/status/time — any CRM lead list looks like this. The detail pane bolts three equal-weight buttons under a full read-only dump reused verbatim from the admin's view dialog. Nothing signals "what should I do next" — no call-outcome shortcuts, no queue-navigation shortcuts, no felt sense of progress after acting.

**Deterministic scan**: detect.mjs on all four files — exit 0, zero findings. Clean mechanically; the issues here are structural/UX, not markup-detectable.

**Visual overlays**: No overlay injection possible in the subagent's environment (no browser tool); this-session screenshots (desktop 1440x900, mobile list, mobile full-screen detail) confirm zero console errors and correct Card rendering on both.

## Overall Impression

Mechanically solid, visually calm, and functionally competent — but built like an admin screen wearing call-center clothes. The one thing this page needs to nail (get an agent from "order selected" to "status set" in the fewest possible moves, over and over, all day) is instead the thing it does worst: the CTA row sits below a full unfiltered detail dump, and the actual decision — the status — is buried in a 12-item flat dropdown. The biggest opportunity is inverting the priority: decision-first, detail-on-demand, not detail-first-decision-last.

## What's Working

- **TotalAmount's oversized treatment** (order-detail.tsx) correctly identifies the one number an agent scans for first and gives it real weight instead of burying it in a field grid.
- **Optimistic list-row before fetch** (`optimisticSelectedOrder` -> `displayedOrder` in queue.tsx) avoids a blank-pane flash when switching orders.
- **Fixed CTA row pinned outside the scroll area** (`border-t p-4` in order-queue-pane.tsx) — actions stay always-reachable, even though the pane's own length above it undermines it.

## Priority Issues

**[P0] Silent hang on detail-fetch failure**
- Why it matters: queue.tsx's detail-fetch effect and order-queue-pane.tsx have no error branch. A failed request leaves the pane on skeleton or stale data forever, with zero signal.
- Fix: give OrderQueuePane a real error state with a retry action.
- Suggested command: /impeccable harden

**[P1] Status Select is a flat 12-item list on the single highest-frequency action**
- Why it matters: MANUALLY_SELECTABLE_CONFIRMATION_STATUSES triples the working-memory guideline with no grouping.
- Fix: group via SelectGroup labels, or promote the 2-3 most common transitions to one-tap buttons.
- Suggested command: /impeccable layout

**[P1] No confirmation or undo for any status change except cancellation**
- Why it matters: handleStatusChange PATCHes immediately on selection; a misclick has no recovery except for "cancelled".
- Fix: add a brief undo toast for every transition.
- Suggested command: /impeccable harden

**[P2] Card-in-Card-in-Card nesting adds chrome to a scan-fast tool**
- Why it matters: three levels of rounded/ringed containers competing with content on a screen whose job is fast scanning.
- Fix: consider a plain bordered panel for the outer pane instead of Card.
- Suggested command: /impeccable distill

**[P3] No keyboard path through the queue**
- Why it matters: zero shortcuts on the app's one certified power-user, all-day surface, contradicting "optimize for speed and minimal round trips."
- Fix: j/k or arrow-key card navigation, plus shortcuts for common statuses.
- Suggested command: /impeccable optimize

## Persona Red Flags

**Alex (Power User, all day)**: Every order means scrolling a 6-card-deep pane to reach the CTA row, then parsing an unsorted 12-item Select. No keyboard path exists, and no success toast rewards a completed transition.

**Casey (Mobile, one-handed, distracted)**: Full-screen mobile detail still renders the entire OrderDetail stack in one scroll before reaching Call/WhatsApp at the bottom, now cramped into a 3-across grid on a 390px screen. The search/bucket toolbar is fully hidden while in detail view.

## Minor Observations

- Duplicate flag and blacklist flag both render as the same AlertTriangle icon+color on the card.
- Empty states offer no CTA or next step.
- Copy-to-clipboard toast uses an exclamation point that breaks from the otherwise flat, professional copy tone.
- Bucket-chip count badge variant switch is subtle enough it may not register during fast scanning.

## Questions to Consider

- If this is "the product's core daily tool," why does its detail pane reuse the admin's read-only view verbatim instead of a decision-first layout?
- Has anyone timed a real agent's per-order handling time against what this UI could get them to, if status were one tap instead of a 12-item dropdown?
- Was the Card-wrapping change shipped this session validated against the "optimize for speed" mandate, or is it aesthetic drift?
