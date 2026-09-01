---
target: OrderDetail component
total_score: 25
max_score: 40
na_heuristics: 
p0_count: 1
p1_count: 3
timestamp: 2026-08-09T09-50-58Z
slug: resources-js-components-orders-order-detail-tsx
---
Method: dual-agent (A: a96e296fc481e190f · B: a2ec638fba8ab9369)

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 3 | Two tracks always visible and independent; `AssignedAgent`'s updating state only disables the trigger — no spinner, no aria-busy. |
| 2 | Match System / Real World | 2 | History renders raw DB enums (`confirmed_followup`, `delivery_attempt_failed`) while the label maps are imported and used 300 lines earlier. |
| 3 | User Control and Freedom | 3 | Assign fires instantly on `onValueChange` with no undo, while the queue pane offers Undo on status writes. |
| 4 | Consistency and Standards | 2 | Three money renderings on one screen: `Number().toFixed(2)` + MAD, bare `{item.unit_price}`, and nothing for `delivery_cost`. |
| 5 | Error Prevention | 2 | `Qty 3` and a per-unit price sit adjacent with no line total — an agent reading aloud on a COD call can state the wrong figure. |
| 6 | Recognition Rather Than Recall | 2 | History interleaves both status tracks with no discriminator, forcing the reader to infer the track from vocabulary. |
| 7 | Flexibility and Efficiency | 3 | Flat scroll is right for queue speed, but no keyboard path and no way to jump to the address. |
| 8 | Aesthetic and Minimalist Design | 3 | Genuinely restrained and matches the Quiet pass world. Loses a point for the badge row becoming an unranked run of five chips. |
| 9 | Error Recovery | 2 | Missing-data handling is inconsistent: address has a fallback, `customer_name` renders blank. |
| 10 | Help and Documentation | 3 | Reason-code labels are self-explanatory; nothing explains a Duplicate + Blacklisted combination. |
| **Total** | | **25/40** | **Acceptable** |

## Design Specificity Verdict

**Authored for COD in its parts, generic in its sequence.**

Three details could not come from a template: two status badges rendered structurally independent (`:261-282`), so no code path can collapse them; `TotalAmount` promoted to a typographic object with `tabular-nums` (`:68-82`) because in COD the total is the cash the driver collects; and structured reason codes rendered through label maps rather than free text (`:524-542`).

But the information order is the admin's order, and the queue variant only ever subtracts from it. Scroll order is identical in both scenes: badges → notes → total → customer → items → delivery → reason → history. The agent's phone script is name → items → total → address, and the address — the field most often corrected mid-call and a top-four cancellation reason — is the third `ItemDescription` inside the customer Item, `line-clamp-2`'d with no title attribute. Meanwhile name and phone are already in the pane header, so the queue's first block restates what's visible and buries what isn't.

`isQueue` appears twice, both purely subtractive. Store/platform is the cheapest thing to diverge on; order is the expensive one, and it was left identical. This is not two designs wearing one component — it is one design wearing a slightly smaller coat.

**Deterministic scan:** detector returned `[]` exit 0. Per this session's earlier control test, its rule set covers visual/CSS anti-patterns only and a synthetic file with real a11y violations also returned clean. Low-information, not evidence of quality. Every finding here is static reading.

**Visual overlays:** none. No browser automation exposed; nothing was hand-rolled and no server started.

## Overall Impression

Visually disciplined, semantically leaky. The component holds the product's invariants — two tracks, test badging, reason codes — while dropping the language layer at exactly the point where a non-technical agent reads it. Biggest opportunity: the audit trail, which is the surface consulted when something has gone wrong, is the one place that prints the database schema.

## What's Working

**The two status tracks are structurally, not decoratively, independent** (`:261-282`). Two separate Badge elements from two separate label/color maps, delivery conditional on non-null. Because they are separate elements with separate palettes, no code path could collapse them — the independence is enforced by structure, not by a developer remembering.

**`TotalAmount` earns its promotion with the right typographic details** (`:68-82`). Not just "make it big": `tabular-nums` keeps digits from jittering as the agent moves between orders at the same screen position dozens of times a shift; MAD demoted to `text-sm font-normal` so the number reads first. Someone thought about the scanning motion.

**The "Not shipped yet" empty state** (`:500-522`) names the situation in the operator's language and offers the exact next action — a null state converted into a resolution path.

## Priority Issues

**[P0] History renders raw database enums instead of human labels**
`:583-585` interpolates `event.from_status` and `event.to_status` directly, producing `no_answer → confirmed_followup`. This is the audit trail — the surface an Owner/Manager consults to reconstruct what happened, and the record backing commission and settlement disputes. `confirmationStatusLabels` and `deliveryStatusLabels` are already imported in this file and used for the badges 300 lines earlier, so the same status reads as "Delivery attempt failed" at the top of the screen and `delivery_attempt_failed` at the bottom. Compounding it, the backend writes both tracks into one column with no discriminator, so the reader must infer the track from vocabulary — the two-track principle preserved everywhere else silently collapses here.
**Fix:** resolve each status through both maps with the raw value as final fallback, derive the track from which map hit, and render it as a small muted prefix chip so the two streams stay distinguishable in one chronological list.
**Suggested command:** `/impeccable clarify`

**[P1] `total_amount` formatting fails silently in both directions**
`:75` renders `Number(value).toFixed(2)` on a field typed `string`. `Number(null)` and `Number("")` both yield `0` and render `0.00` — a null total displays as a legitimate-looking zero, which is worse than a visible error. `Number("1,499.00")` yields `NaN` and renders the literal `NaN MAD` in the largest type on the screen. `lib/format.ts` exports a locale-aware `formatNumber` that is never imported here, so `12345.00` also renders without a thousands separator.
**Fix:** route through the existing `formatNumber`, and render an explicit "—" for null/unparseable rather than a fabricated zero.
**Suggested command:** `/impeccable harden`

**[P1] The queue scene's most-needed field is its weakest-rendered**
Address is the third `ItemDescription` in the customer Item (`:332-336`), styled identically to the phone above it, `line-clamp-2`'d by the primitive, with no `title` and no expansion — so an agent can confirm an address they cannot fully see, and `line-clamp` gives no signal that text was cut. The queue pane attaches `title` fallbacks to its truncated fields; this file attaches zero anywhere.
**Fix:** give the address its own visual level (`text-foreground`, not muted), add `title` plus an expand affordance, and under `isQueue` suppress the redundant name/phone lines so the block becomes an address block with the Copy action.
**Suggested command:** `/impeccable layout`

**[P1] Items show per-unit price with no line total and no currency**
`:442-451` renders `Qty {quantity}` and bare `{item.unit_price}` — no multiplication, no currency, no decimal normalization. `unit_price` is per-unit (confirmed against the commission calculator), so a 3× line at 150 shows "Qty 3" and "150.00" against a total of 450.00, and the agent must do mental arithmetic on a live call. It also breaks the money language established by `TotalAmount`.
**Fix:** render the line total as primary with `{qty} × {unit}` muted beneath, `tabular-nums` so the column aligns, and move `TotalAmount` to sit after the items so addends precede the sum.
**Suggested command:** `/impeccable layout`

**[P2] Missing `customer_name` renders blank**
`:326` renders `<ItemTitle>{order.customer_name}</ItemTitle>` with no fallback on a field the type marks optional — producing an empty title beside an orphaned User icon, which reads as a broken component rather than absent data. The queue pane handles the identical field with `?? 'Unnamed customer'` 16 lines away in the same directory. Missing phone is worse: the entire `ItemActions` block vanishes, silently removing Copy with no "No phone on file" statement.
**Fix:** match the pane's fallback verbatim, and state absent phone explicitly.
**Suggested command:** `/impeccable harden`

## Cognitive Load

**4 of 8 failures** — single focus, grouping, visual hierarchy, working memory.

`TotalAmount` (`:318`) sits above the items that produce it (`:394`), with a customer block wedged between, so the sum precedes its addends across two non-adjacent regions. Address, phone, and "No address on file" all render at one visual weight. Reading a history row requires holding "which track is this?" in memory.

Decision points over the limit: the `AssignedAgent` Select (`:204-211`) renders Unassigned plus every agent in the tenant as one flat, unsearchable, ungrouped list — 10-30 items at realistic headcount. The same codebase solved this correctly in the queue pane's status picker with grouped `Command` headings.

The badge row (`:260-306`) is an unranked cluster of up to five equal-weight chips mixing operational state, data-quality warnings, and scope. "Blacklisted" means refuse this order and "Test" means this isn't real, both at the same weight as a routine "In transit" — and Test is the lowest-contrast chip of the five despite the PRD requiring it to always be visibly marked.

## Persona Red Flags

**Alex (calling agent):** the address is clamped and low-contrast — the one field he must read aloud and correct in real time. Mental arithmetic on every multi-item order. The first block restates the header, so name and phone appear twice within ~150px. Total sits above items, so reading the order back means scrolling up, down, then up again. No keyboard path within the detail region; Copy phone is mouse-only.

**Sam (screen reader / keyboard):** the history is an `<ol>` of raw enums, so TTS announces "confirmed underscore followup" — the one place a label map exists and isn't used is also where the damage is worst. Timeline "current" state is size and color with no `aria-current`. `AssignedAgent` has no busy announcement despite the pane establishing exactly that pattern with `<output aria-live="polite">`. Blank `ItemTitle` announces a container with no accessible name. `line-clamp-2` means Sam and Alex perceive different content from the same element. Copy confirmation is hue-only (`text-emerald-500`) with no glyph swap and no live region. A 610-line detail view emits zero heading elements, so there is no way to jump to Items, Delivery, or History.

## Minor Observations

- `:251` — `digits` is dead code in the queue variant (its only consumer is inside `{!isQueue}`), and it hand-rolls a `[^\d+]` normalization that duplicates `lib/phone.ts`, already imported in this file.
- Four hardcoded color sites (`:105` emerald-500, `:287` amber quartet, `:356` blue, `:371` green) while `alert.tsx` exposes a `warning` variant on the semantic token — used at `:309`, hand-rolled at `:287`. Two different greens for two positive signals.
- `:99` — `navigator.clipboard.writeText` is unguarded and the toast fires unconditionally, so a rejected promise still reports success. This is a phone number an agent may paste into a dialer.
- `:105` — the `setTimeout` isn't cleared on unmount.
- `:554` — `isCurrent` marks the last element after a `.reverse()` at `:256`; worth verifying against the API sort order.
- `:538-539` — provably-always-true re-check inside the reason block.
- `:399-401` — a wrapper `div` inside `ItemGroup` (whose child is `role="list"`) purely to host a conditional separator, loosening already-loose list semantics.
- `:262`/`:273` — `key` used as a remount trick to replay the badge animation. Works, undocumented, will read as a mistake. No `prefers-reduced-motion` guard, and it replays on every order selection in the queue.
- `delivery_cost`, `returned_cost`, `refused_cost`, `ordered_at` are never rendered — and the `TotalAmount` docblock still references a delivery-cost field grid that no longer exists. Delivery cost is load-bearing for the courier-settlement reconciliation the PRD names explicitly.
- The 28px `CopyButton` survives into the queue variant, so the docblock's stated 44px rationale is only two-thirds enforced.

## Questions to Consider

1. If the queue variant only ever subtracts, has it resolved the tension or postponed it? Subtraction cannot fix a sequence. Either the variant grows an ordering responsibility, or `OrderDetail` decomposes into shared sections each scene sequences itself.
2. What is the "never show different information" rule actually protecting? Taken literally it is already violated four ways — store, platform, history, agent assignment — via three separate mechanisms, only one of which is named `variant`. If the real invariant is "no order-state fact may differ," say so: that licenses the safe reordering while still forbidding the dangerous divergence.
3. The two tracks are rigorously independent in the badges and rigorously merged in the history. Which is the truth? The audit trail is where the principle matters most.
4. Should a test order look like an order at all? A badge third in a five-chip row is present but not privileged. The backend prevents test orders polluting stats; the UI's job is preventing a human from calling a test lead and quoting a real total to a real person.
