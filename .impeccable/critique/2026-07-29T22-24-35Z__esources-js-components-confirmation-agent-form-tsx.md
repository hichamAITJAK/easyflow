---
target: confirmation-agent-form.tsx
total_score: 18
max_score: 40
na_heuristics: 
p0_count: 2
p1_count: 2
timestamp: 2026-07-29T22-24-35Z
slug: esources-js-components-confirmation-agent-form-tsx
---
## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 2 | `processing` only disables buttons + spinner (form-action-bar.tsx:17-23); no per-section error indication until scroll. |
| 2 | Match Between System and Real World | 2 | "Trigger Event" (line 383) and "Amount Type" (line 341) are schema words, not the owner's own vocabulary ("pay on confirmed" vs. "pay on delivered" would read faster). |
| 3 | User Control and Freedom | 2 | Override-row delete (line 524-532) is instant with no confirm or undo — a configured, possibly-negotiated pay term vanishes on one click. |
| 4 | Consistency and Standards | 2 | Override rows use `h-8`/`text-xs` controls (line 429-496) while the rest of the form uses `h-10` — a visually different density glued into the same form. |
| 5 | Error Prevention | 1 | Commission `amount` (line 364-374) and override `amount` (line 484-496) have no upper bound client-side; server-side `UserValidationRules::confirmationAgentRules()` only enforces `min:0` on `amount`/`salary_amount`, with no percentage cap — confirmed by direct comparison against `target_percentage`, which *does* have a conditional 100% cap in the same file. A 250% commission is submittable end-to-end. |
| 6 | Recognition Rather Than Recall | 2 | Quick-pick rate/quota chips (lines 677, 737, 797) are a good recall aid; override rows give no current-assignment context — picking a store/product from a bare Combobox with no indicator of an existing default rate. |
| 7 | Flexibility and Efficiency of Use | 2 | Quick-pick chips help; no bulk/"clone agent settings" path despite this being a repeatable hiring task, not a one-off form. |
| 8 | Aesthetic and Minimalist Design | 2 | Three near-identical ~60-line KPI blocks (lines 640-698, 700-758, 760-818) repeat the same label+toggle+slider/quick-pick shape with zero shared abstraction — verbose to scan, and the file is 832 lines of near-flat divs. |
| 9 | Error Recovery | 1 | `InputError` renders inline only (confirmed hardcoded `text-red-600 dark:text-red-400` in input-error.tsx:12, not the `--destructive` token); no error summary or scroll-to-first-error on a failed submit across an 832-line single-scroll form; the password-confirmation field (identity-fields.tsx:130-144) has no `InputError` slot at all — a mismatch error has nowhere to render. |
| 10 | Help and Documentation | 2 | Section descriptions exist, but no inline help for override precedence (what wins if a store override AND a product override both match one order?) or for "Trigger Event"'s actual effect on payout timing. |
| **Total** | | **18/40** | **Poor — solid component-level pieces, but the form-wide structure and error handling need a real overhaul** |

## Design Specificity Verdict

**LLM assessment**: This reads as a generic staff/employee-CRUD form with COD vocabulary layered on top, not a form authored around how an owner actually thinks about hiring and paying a phone-based confirmation team. The section order (Identity → generic Compensation → generic Scope → generic KPIs) is what any HR-admin screen would ship. The real product ideas are present — MAD currency, confirmed/delivered payout triggers, store/product scope — but they're bolted onto raw `Select`s and a repeated `ChoiceCard` pattern rather than a flow that ties the abstract "scope" selection back to something concrete (e.g., "12 pending orders match this scope" or a payout preview against real order volume). Nothing here would look out of place in an unrelated retail-staff app with the labels swapped.

**Deterministic scan**: `detect-antipatterns.mjs` ran clean (exit 0) across all 9 files in this form's tree — zero findings, independently confirmed. As with the product form's critique, this is expected: the detector catches mechanical anti-patterns, not the IA/copy/validation gaps that are this form's actual problem.

**Visual overlays**: No browser automation was available to either assessment in this environment (confirmed independently — no browser/screenshot tool exposed). No live screenshot evidence exists for this run; all findings are from direct source verification (every cited line was re-read and confirmed in this pass, including the backend validation-rule comparison).

## Overall Impression

The individual building blocks (ChoiceCard, quick-pick chips, avatar dropzone) show real design thought, but the form has no container discipline — 832 lines of raw `<div>` sections with borders standing in for `Card`, no shared abstraction for the three KPI blocks, and the single highest-stakes field in the whole form (commission amount, which sets a real person's pay) has less input protection than the target-percentage field two sections below it. The biggest opportunity is the same one product-form.tsx already solved: replace ad hoc div/border groupings with `Card`, and extract the repeated KPI-toggle pattern into one component instead of three copies.

## What's Working

1. **Payment-mode ChoiceCard split** (lines 312-325) — clear binary framing with concrete example numbers, genuinely helps someone unfamiliar with the two modes decide.
2. **Store/Product scope as its own section with a `Scoped Access` badge** (lines 543-628) — correctly elevates access scope beyond an afterthought checkbox, matching how consequential it actually is.
3. **Quick-pick chips for rate/quota** (lines 677-688, 737-748, 797-808) — a real recognition-over-recall win, faster than typing common values.

## Priority Issues

**[P0] No upper bound on commission amount, client- or server-side.** `amount` (line 364-374) and each override row's `amount` (line 484-496) accept any number; `UserValidationRules::confirmationAgentRules()` validates `amount` with only `min:0` — no conditional cap tied to `amount_type === 'percentage'`, even though the exact same file already implements this correctly for `target_percentage` two rules below it. A typo (250 instead of 2.50) creates a broken payroll rule that reaches the database. **Fix**: add a `max:100` rule when `amount_type` is `percentage` (mirroring the existing `target_percentage` closure), plus a client-side `max="100"` on the percentage input. **Suggested command**: `/impeccable harden`

**[P0] Override-row deletion is instant with no confirm or undo.** Clicking the X (line 524-532) on a configured store/product override — potentially a negotiated rate with a real person — discards it immediately, no dialog, no toast, no way back. **Fix**: reuse the same `AlertDialog` pattern just added to product-form.tsx's "Remove variants" flow, naming the specific store/product and rate being removed. **Suggested command**: `/impeccable harden`

**[P1] No Card-based structure — the whole form is raw divs with borders standing in for real containers.** Every section, the override-row list, and all three KPI blocks use `rounded-md border p-4`/`p-5` divs instead of `Card`/`CardHeader`/`CardContent`, the same anti-pattern product-form.tsx had before its shadcn migration. **Fix**: convert `FormSection` to wrap a `Card`, and the ad hoc bordered sub-blocks (override list, each KPI block) to `Card`/`CardContent`. **Suggested command**: `/impeccable layout` (structural), with the actual conversion work.

**[P1] `InputError` hardcodes `text-red-600 dark:text-red-400` instead of the theme's `--destructive` token, and the password-confirmation field has no error slot at all.** Confirmed both directly: `input-error.tsx:12`, and `identity-fields.tsx:130-144` (no `InputError` after the confirm-password `Input`). **Fix**: swap `InputError` usages for shadcn's `FieldError` (already used throughout product-form.tsx) or at minimum retheme the hardcoded color, and add the missing error slot. **Suggested command**: `/impeccable harden`

**[P2] Three near-identical ~60-line KPI blocks with no shared component.** Confirmation Rate, Daily Orders, and Delivery Success (lines 640-818) repeat the same label+icon+toggle-button+conditional-slider/quick-picks shape verbatim, just swapping copy, icon, and value range. **Fix**: extract a `KpiTargetCard` component parameterized by icon/label/description/default/min/max/step/quick-pick values. **Suggested command**: `/impeccable distill`

## Persona Red Flags

**Jordan (first-timer owner)**: "Trigger Event" (line 383) and "Amount Type" (line 341) assume familiarity with this product's commission-rule data model; no inline help explains what a store override actually accomplishes versus the base rate, or what happens when store and product overrides both match the same order.

**Alex (power user hiring multiple agents)**: No bulk action or "duplicate this agent's settings" path — onboarding 5 agents means repeating this entire 832-line form 5 times from scratch, despite PRODUCT.md framing team management as a recurring back-office task, not a one-off setup screen.

## Minor Observations

- `ChoiceCard`'s `amber` accent variant (choice-card.tsx:16, 33-34, 48) is defined and styled but never invoked anywhere in this file — dead prop surface.
- `SectionBadge` conditionally renders `false` directly in JSX (lines 548, 634) — works via React's falsy-render behavior but is fragile if `Badge`'s children handling ever changes.
- The percentage/MAD suffix pattern (`<span className="absolute right-3 ...">`, lines 375-377, 49-51) is duplicated between this file and salary-fields.tsx rather than shared as an `InputGroupAddon` (the pattern product-form.tsx already uses via shadcn's `InputGroup`).

## Questions to Consider

- Why does the single field that sets a real person's pay (commission amount) have less input protection than the target-percentage field three sections later in the same form?
- If onboarding several agents is a normal back-office task, why is there no way to start a new agent form pre-filled from an existing one's settings?
- Should override-row deletion really require zero confirmation when the row represents a negotiated pay term with a real teammate?
