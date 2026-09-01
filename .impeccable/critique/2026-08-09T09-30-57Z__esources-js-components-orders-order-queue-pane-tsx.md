---
target: orders/queue.tsx OrderQueuePane component
total_score: 25
max_score: 40
na_heuristics: 
p0_count: 1
p1_count: 2
timestamp: 2026-08-09T09-30-57Z
slug: esources-js-components-orders-order-queue-pane-tsx
---
Method: dual-agent (A: affad1c93663facff · B: a8bbd12ccc9e60c9c)

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 3 | Per-button spinners are careful, but the pane never shows call-attempt count or that the next `no_answer` triggers auto-cancel (PRD UC-8). |
| 2 | Match System / Real World | 3 | Status taxonomy is domain-true, but the pane is titled by `reference` when the agent's mental object is a person to call. `callback` records no callback time. |
| 3 | User Control and Freedom | 2 | Undo is a ~4s toast on a screen where the next action is immediate; it cannot recall a parcel already pushed to the courier. |
| 4 | Consistency and Standards | 3 | Solid shadcn discipline. Breaks: quick-status buttons flip to `variant="default"` when active, so an active "No answer" renders identically to the Confirmed CTA. |
| 5 | Error Prevention | 1 | Digit shortcuts write status from a `window` listener with a `tagName`-only guard — live even behind open modals. `fake` applies instantly, unguarded. |
| 6 | Recognition Rather Than Recall | 3 | `Kbd` badges on buttons are right; the `C` (call) shortcut exists only in a code comment. |
| 7 | Flexibility and Efficiency | 3 | Arrow-key nav + digit shortcuts + `?order=` deep link is real power-user work. Missing the highest-value shortcut: record-and-advance. |
| 8 | Aesthetic and Minimalist Design | 3 | The "Quiet pass" is well executed. Cost: four undifferentiated icon/text buttons in one grid row. |
| 9 | Error Recovery | 2 | A validation rejection (illegal backward transition) reads identically to a network blip. `refetchSelected` has no `.catch`. |
| 10 | Help and Documentation | 2 | No keyboard legend on a screen with five shortcuts. "More statuses…" is undiscoverable as the home of `fake`/`busy`/`voicemail`. |
| **Total** | | **25/40** | **Acceptable** |

## Design Specificity Verdict

**~65% authored, 35% generic CRM.**

Genuinely COD-specific: the action rail knows "Confirmed" is not one of N statuses but *the* outcome, giving it a full-width `h-11` primary while the rest live in a 4-up grid. The status taxonomy splits "Call outcome" from "Client response" — exactly the two questions a phone call answers. `SELECT_ONLY_GROUPS` subtracts the promoted three from the long tail so no label appears twice. Number-key shortcuts plus `C` to dial. WhatsApp treated as a peer channel, not a footnote.

Generic and dragging it down: the pane's top bar spends its loudest type on `order.reference`, an opaque code, while the customer's name and phone number — the two things the job is built on — are buried mid-scroll in a component shared with the admin dialog. The read side of the highest-stakes screen inherits an admin's information ordering.

**Deterministic scan:** detector returned `[]` exit 0 on both files. Assessment B validated this rather than trusting it: a synthetic control file containing `<div onClick>` with no role, an unlabeled input, an `<img>` with no alt, and a 16px button *also* returned clean. The rule set covers visual/CSS anti-patterns (`low-contrast`, `kicker-above-heading`, `cramped-padding`, …), not accessibility, tap targets, or tokens. Treat the clean exit as low-information, not as evidence of quality.

**Visual overlays:** none. No browser automation tool is exposed in this session; no live server was started and no overlay exists. All findings are static.

## Overall Impression

This is a screen with excellent reflexes and no seatbelt. The interaction layer — keyboard system, optimistic detail fetch, 3+N status disclosure — is better than most production software. The safety layer is close to absent, and the guarding that does exist is sorted by which action the backend happened to require a reason for, not by consequence. Biggest opportunity: make the pane about the *person being called* rather than the *order record*, and guard the writes in proportion to how irreversible they are.

## What's Working

**The 3+N status architecture, specifically the subtraction.** Most teams surface three quick buttons *and* leave all 11 statuses in the picker, so "Confirmed" appears twice. This code computes `SELECT_ONLY_GROUPS` to remove exactly the promoted three. It makes the disclosure honest — an agent who opens "More" never wonders whether the popover version differs. That is progressive disclosure rather than progressive duplication.

**The keyboard layer is a system, not sprinkles.** Arrow keys own list navigation, digits own outcomes, `C` owns dialing, and every listener guards form fields so digits stay digits in the search box. `Kbd` badges put the map on the territory. For a headset-wearing agent whose eyes are on the customer's address, the whole loop is reachable without the mouse.

**The optimistic-then-authoritative fetch.** `displayedOrder = selectedOrder ?? optimisticSelectedOrder` paints the list row's data instantly and upgrades when the fetch lands; `loading` is gated on `!displayedOrder` so the skeleton appears only on a cold pane. An agent scanning 15 orders sees zero skeleton flashes. The state machine *is* the design.

## Priority Issues

**[P0] Digit shortcuts can write an irreversible status to the wrong order, and fire behind open modals**
`order-queue-pane.tsx:150-184` binds `keydown` to `window` with no scoping, no focus requirement, and a guard that checks only `tagName` against INPUT/TEXTAREA/SELECT. Two concrete holes: `OrderQueueCard` is `role="button" tabIndex={0}` on a `DIV`, so a focused card is not in the guard list and digits fire; and the listener stays mounted while `CreateShipmentDialog` and `OrderFormDialog` are open, so typing `1` behind a modal mutates the order underneath it. Per PRD UC-7, `confirmed` fires `OrderConfirmed`, triggers commission calculation, and pushes the parcel to the courier — Undo cannot recall a parcel already created at OzonExpress.
**Fix:** scope the listener to a ref on the pane root and require `document.activeElement` to be inside it; widen the guard to `target.closest('input, textarea, select, [contenteditable], [role="textbox"], [role="dialog"]')`; capture `order.id` at keypress and assert it still matches before PATCH; ignore digits for ~250ms after `selectedId` changes.
**Suggested command:** `/impeccable harden`

**[P1] Guarding is inverted relative to consequence — "fake" is the least protected action**
`handleStatusChange` (`:131-143`) special-cases only `cancelled`, which opens a reason dialog. `fake` — a `destructive`-variant status meaning "I suspect this customer is a prankster," which feeds blacklist and stats logic — applies instantly from the More popover with no dialog, no reason, no confirmation. The accusatory action is one mis-click away in a 9-item list; the less accusatory one gets a full dialog.
**Fix:** route `fake` through the same escalation (`value === 'cancelled' || value === 'fake'`), reusing the existing reason set (`fraud_suspected`, `duplicate_order`, `other` + note). Give the popover item `text-destructive` so its weight is visible before the click.
**Suggested command:** `/impeccable harden`

**[P1] The pane is titled by the wrong noun; the phone number is not readable from the rail**
The header gives `text-lg font-semibold` to `order.reference`. The customer name is an `ItemTitle` mid-scroll and the phone number is an `ItemDescription` — muted, small, below the fold once items render. The rail's Call button is an icon-only grid cell with no visible number, so an agent dialing on a desk handset must scroll back and squint every call. The `tel:` link also silently does nothing on a desktop with no softphone handler.
**Fix:** rebuild the header as the call header — customer name at `text-lg font-semibold`, phone number under it in `tabular-nums` and selectable, reference demoted to `text-xs font-mono text-muted-foreground`. Label the Call button with the number itself and add its `Kbd`.
**Suggested command:** `/impeccable layout`

**[P2] Communication and state-writing actions share one undifferentiated row**
`:267-325` renders Call, WhatsApp, No answer, Callback as four identical `variant="outline" h-11` cells in one `grid-cols-4`. Call and WhatsApp open a channel and change nothing; No answer and Callback write an audited transition that counts toward auto-cancel. Two of the four are icon-only and two are text, so the row reads as arbitrary rather than categorical. A slip from WhatsApp to No answer is a one-column error that pushes the order toward auto-cancellation.
**Fix:** split into a contact row (`variant="secondary"`, icon + label) and an outcome row (`variant="outline"`, all text, all with `Kbd`), separated. This also drops each decision point to ≤3.
**Suggested command:** `/impeccable layout`

**[P2] The loop never advances; completed work vanishes**
After a write, `refetchSelected` reloads the list. The just-confirmed order leaves the bucket, `selectedId` falls through to `orders.data[0]?.id`, and the pane jumps to an unrelated order. There is no record-and-next affordance — the highest-value shortcut on a queue screen is the one missing. Peak-end: the loop ends on disorientation instead of advancement, and it punishes the fastest agents most.
**Fix:** on success, advance `manualSelectedId` to the next item in the pre-refetch order rather than letting the fallback pick index 0; bind `Enter` or `N` to record-and-next; hold the outgoing row visible for one beat with a completed treatment.
**Suggested command:** `/impeccable animate`

## Cognitive Load

**3 of 8 failures** — grouping, minimal choices, working memory.

Decision points over the limit:
- **Action rail: 6 visible controls** (Confirmed, Call, WhatsApp, No answer, Callback, More) — and they are not six of a kind.
- **CancelOrderDialog: 12 ungrouped radios** at the highest-stakes irreversible moment. `CONFIRMATION_STATUS_GROUPS` proves this codebase knows how to group; that thinking never reached this dialog.
- More popover: 9 items across 3 groups — over four, but grouped and disclosed, so acceptable.

Working memory tax: the customer name and phone scroll out of view, the attempt count is never shown, and the `C` shortcut is never shown. Three separate taxes on a 60-call shift.

## Persona Red Flags

**Alex (impatient power user)**: The P0 is his bug specifically — he arrows fast and types `1` before the pane settles, and fires it from a focused `OrderQueueCard` the guard doesn't cover. No record-and-next, so his loop has a mandatory dead beat. `C` is invisible while three sibling buttons carry `Kbd`, so he concludes there isn't one. `busy`/`voicemail`/`whatsapp_sent` are high-frequency outcomes with no shortcut at all. The Undo toast is a race he loses — he has already arrowed away before it expires.

**Sam (accessibility-dependent)**: `disabled` on `<Button asChild>` wrapping an `<a>` (`:268-297`) is inert — React drops it, `disabled:pointer-events-none` never matches, and with no phone number the anchor renders `href={undefined}` while staying focusable and looking enabled. A dead control that announces as a link. Status changes are announced to nobody: no `aria-live`, and the badge swap is a silent `key` remount — pressing `1` gives no confirmation on the most consequential action in the product. Arrow-key nav is not a listbox: plain `div` container, `role="button"` cards, no `aria-activedescendant`, and focus never moves — so the visual selection and the entire right pane change while the screen-reader cursor stays put. Skeletons have no `role="status"`; the error `Empty` has no `role="alert"`. `Kbd` badges sit inside the accessible name, so the primary announces as "Confirmed 1".

## Minor Observations

- `:174-177` — the `C` shortcut assigns `window.location.href = 'tel:…'`, which can unload or blank the SPA depending on handler config. Should synthesize a click on the existing anchor.
- `:304-308` — active quick-status buttons flip to `variant="default"`, so an order at `no_answer` renders "No answer" in filled primary, identical to the Confirmed CTA. Two opposite meanings, one appearance.
- `:298-300`/`:319` — `Kbd` labels are `index + 2` after filtering out `confirmed`. Reorder `QUICK_CONFIRMATION_STATUSES` and the buttons advertise keys that don't match `QUICK_STATUS_SHORTCUTS`.
- Undo re-applies `previousStatus` via `applyStatus`, bypassing the cancel-dialog gate — undoing to `cancelled` PATCHes with no reason code and is rejected by UC-9 validation, surfacing only a generic error.
- Tap targets under 44px: Edit `size="icon-sm"` (28px), mobile Back `size="icon"` (32px) — the smallest control sits precisely on the touch surface. `OrderDetail` renders Call/WhatsApp at 28px while the rail duplicates them at 44px.
- `queue.tsx:230-238` — `refetchSelected` has no `.catch`, no cancellation guard, and no error state; a rejected fetch is an unhandled rejection leaving stale data in the pane.
- `queue.tsx:247` — `h-[calc(100vh-6rem)]` hardcodes chrome height; `100vh` on mobile with dynamic toolbars clips the action rail, the one region that must stay reachable. Use `100dvh`.
- List loading and list/search error states are both missing; the empty state doesn't distinguish an empty bucket from a zero-result search, and offers no clear-search action.
- `:227` — `truncate` on the header `<h2>` with no `title` attribute; a long reference is unreadable.
- `:310` — quick-status buttons are `grid-cols-4` `text-xs` with no wrap guard; latent overflow the moment a longer label like "Confirmed (follow-up)" enters `QUICK_CONFIRMATION_STATUSES`.
- `cancel-order-dialog.tsx` never names who is being cancelled — no customer name, reference, or total on an irreversible decision.

## Questions to Consider

1. If the agent's job is "call a person," why is a person nowhere in the pane's visual hierarchy? Would an agent recognize this screen as *their* tool with the header covered, or does it read as an order record that happens to have buttons?
2. The system knows this is attempt 4 of 4 and that the next `no_answer` auto-cancels. Why does the agent not? Is the pane showing *order* state when it should be showing *call* state?
3. Why is `cancelled` guarded by a reason dialog while `fake` — the accusatory one — applies instantly from a popover? If guarding were sorted by consequence, would the whole rail be arranged differently?
4. Undo is the only safety net, yet it is time-boxed, cannot recall a dispatched parcel, and may be rejected outright for a manager-gated backward transition. Is it a real guarantee, or a comfort affordance that lets the design skip the confirmation it actually needs?
5. The read side of the highest-stakes screen is shared with the admin dialog. "Must show the same facts" is airtight — but is it being conflated with "must show the same layout, ordering, and emphasis"?
