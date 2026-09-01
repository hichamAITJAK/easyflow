---
target: OrderQueuePane CTA section
total_score: 23
max_score: 36
na_heuristics: 10
p0_count: 2
p1_count: 2
timestamp: 2026-08-03T20-47-31Z
slug: esources-js-components-orders-order-queue-pane-tsx
---
## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 3 | Select shows spinner+"Updating…" but the 3 quick buttons give zero pending feedback |
| 2 | Match System / Real World | 3 | Labels match agent vocabulary; grouped Select mirrors call progression |
| 3 | User Control and Freedom | 3 | Undo toast exists but is the only recovery path |
| 4 | Consistency and Standards | 2 | Call/WhatsApp duplicated as icon-only in customer Item row and as labeled buttons in rail |
| 5 | Error Prevention | 2 | Every quick-status tap fires immediately, no confirm |
| 6 | Recognition Rather Than Recall | 3 | Active state is variant swap only, single differentiator |
| 7 | Flexibility and Efficiency | 1 | Zero keyboard shortcuts on stated highest-frequency screen |
| 8 | Aesthetic and Minimalist Design | 3 | Clean but flat, under-serves heuristic 7 |
| 9 | Error Recovery | 2 | No onError branch on the status PATCH |
| 10 | Help and Documentation | n/a | Self-evident controls, renormalized to /36 |
| **Total** | | **23/36** | **64% Acceptable** |

## Design Specificity Verdict

Reads as a generic form's button row wearing call-center icons. Every control shares the same default h-8 Button size and gap-2 rhythm as any CRUD form footer. detect.mjs clean (no markup-detectable issues). Screenshots confirm: all 5 rail buttons measure exactly 32px tall on desktop and mobile; the Select contains "No answer", "Confirmed", "Callback" all duplicated from the quick-button row above.

## Overall Impression

The one thing done right (triaging 10 statuses to 3 one-tap buttons) is undermined by treating those buttons as equal-weight siblings to a dropdown that repeats them, with 32px tap targets on the explicitly mobile-capable screen and no loading feedback on the buttons themselves.

## What's Working

- Quick-status triage (QUICK_CONFIRMATION_STATUSES) correctly promotes the dominant 3 outcomes to one-tap buttons.
- Grouped Select (CONFIRMATION_STATUS_GROUPS) mirrors a call's actual progression, confirmed via screenshot.
- Undo embedded in the success toast is a cheap, non-blocking safety net.

## Priority Issues

**[P0] Tap targets are 32px on the primary mobile CTA surface**
- Why it matters: measured via DOM on both viewports, well under ~44px minimum touch target, on the explicit tap-a-card mobile flow.
- Fix: bump this rail's buttons to 44px+ independent of app-wide default sizing.
- Suggested command: /impeccable adapt

**[P0] No primary-action emphasis anywhere in the rail**
- Why it matters: Call, WhatsApp, and all 3 quick-status buttons share identical size/weight; nothing accelerates recognition on a screen scanned hundreds of times daily.
- Fix: give the likely-next action real visual dominance; drop Call/WhatsApp to secondary treatment.
- Suggested command: /impeccable layout

**[P1] Redundant status controls with no visual relationship**
- Why it matters: screenshot shows "Confirmed" rendered twice, stacked directly on top of each other (quick button + Select value).
- Fix: filter the Select to exclude the 3 quick statuses, or collapse to one control.
- Suggested command: /impeccable distill

**[P1] No keyboard path for the highest-frequency action in the product**
- Why it matters: PRD explicitly frames this screen as needing "minimal round trips"; nothing here binds a key to any action.
- Fix: bind number keys to quick-status buttons and a key to Call, at minimum when pane has focus.
- Suggested command: /impeccable optimize

**[P2] No inline failure state on a failed status update**
- Why it matters: applyStatus wires onSuccess and onFinish but no onError; a silent PATCH failure leaves the agent unaware.
- Fix: add an onError handler with an explicit failure toast.
- Suggested command: /impeccable harden

## Persona Red Flags

**Alex (Power User, this rail hundreds of times/day)**: re-scans the full rail every time, no accelerated recognition. 32px targets stacked with gap-2 mean mis-taps are a when-not-if. Zero satisfying "done" signal beyond a toast. Sees "Confirmed" rendered in two widgets at once when active, a moment of doubt on every glance.

## Minor Observations

- Edit-pencil (28px) sits beside the truncating reference title with no separation from back chevron.
- disabled Call/WhatsApp buttons give no explanation for why (no phone on file).
- grid-cols-3 for exactly 3 quick statuses is fragile if the list grows to 4.
- RotateCcw/Loader2 are the only system-status icons in the file; quick buttons never borrow that vocabulary for pending state.

## Questions to Consider

- If this rail is the highest-frequency screen in the product, why does it use the same default Button size as a settings-page save button?
- What is the Select protecting against that a "more statuses" overflow couldn't do with less duplication?
- Has anyone timed an agent's median call-to-status-set interval?
