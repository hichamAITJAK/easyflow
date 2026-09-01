---
target: product-form.tsx
total_score: 23
max_score: 40
na_heuristics: 
p0_count: 1
p1_count: 2
timestamp: 2026-07-29T22-05-10Z
slug: resources-js-components-products-product-form-tsx
---
## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 3 | Submit spinner and per-image upload spinner exist; success confirmation on save is unverified (server flashes a toast, but no `toast()` call site was found in the frontend — may be silently dropped). |
| 2 | Match Between System and Real World | 3 | Field copy correctly reflects courier/confirmation-agent workflows ("Used to match this item with your delivery courier," "Reference link accessible by confirmation agents"). |
| 3 | User Control and Freedom | 2 | "Remove variants" gates on a native `window.confirm` (line 730) — no in-app undo, unstyleable, breaks from the rest of the dialog system. |
| 4 | Consistency and Standards | 3 | Field/InputGroup/Card usage is consistent throughout; the read-only synced-variants table is hand-duplicated against `product-variants-editor.tsx`'s combinations table rather than shared. |
| 5 | Error Prevention | 2 | Base price is `required` with `min="0"`, but no client-side format/duplicate-SKU guard; errors only surface after a full server round trip. |
| 6 | Recognition Rather Than Recall | 2 | The variant-value input (product-variants-editor.tsx) commits on Enter, comma, or blur, but has no visible "Add" affordance — first-time users may not discover it. |
| 7 | Flexibility and Efficiency of Use | 2 | No bulk variant entry (paste-a-list, clone-from-previous-product), no keyboard path for setting the cover image (mouse-hover-only icon) — a real efficiency gap for owners doing daily catalog upkeep. |
| 8 | Aesthetic and Minimalist Design | 3 | Clean card rhythm; the Base price/inventory fields carry a "Default for variants" badge + description + italic placeholder simultaneously, which is more label noise than the 2-field grid needs. |
| 9 | Error Recovery | 2 | `FieldError` is wired per top-level field, but a variant-row-level error (e.g. duplicate SKU inside the table) has no visible per-row anchor — likely surfaces as a generic message the user has to hunt for. |
| 10 | Help and Documentation | 1 | The only explanation for why synced-product fields are disabled is one sentence in a banner at the top of the card — nothing at the point of confusion (the grayed field itself). |
| **Total** | | **23/40** | **Acceptable — solid component hygiene, real usability gaps in error recovery, help, and synced-field legibility** |

## Design Specificity Verdict

**LLM assessment**: This reads as a well-executed generic shadcn admin form with a thin layer of COD-specific copy over a structure any Shopify-adjacent SaaS would ship unchanged — three cards (General information / Pricing & inventory / Product variants), a disabled-field pattern for synced records, a confirm-to-remove variants flow. The copy shows genuine product awareness in a few spots (SKU description ties to courier dispatch, the Test-product Alert names the real consequence — no team-performance credit, no courier shipment — pulled straight from this product's actual rules), but the *layout decisions* don't reflect that a confirmation agent works this data live on a call, or that SKU/inventory accuracy directly gates whether an order can ship. The one moment of real specificity is the `public_url` field's description; everything else is generic-catalog-CRUD dressed in the right words.

**Deterministic scan**: `detect-antipatterns.mjs` ran clean (exit 0) across all three files (product-form.tsx, product-variants-editor.tsx, image-gallery.tsx) — zero findings. No false positives to adjudicate, since nothing was flagged. This is expected: the detector catches mechanical anti-patterns, not information-architecture or copy-depth issues, which is where this form's real gaps live.

**Visual overlays**: Browser automation was not available in either assessment's environment (confirmed independently — no Playwright/browser tool exposed), so no live overlay or screenshot evidence exists beyond the one dark-mode screenshot of `/products/44/edit` you shared earlier in this session. That screenshot corroborates the source reading directly: Active/Test switches and their descriptions are visually identical in weight and spacing to the SKU/URL fields above them, with no visual separation between "core info" and "state toggles" — supporting the cognitive-load finding below about those controls competing with the name/description fields for attention.

## Overall Impression

The component-level craft is genuinely good — consistent primitives, a smart signature-keyed draft system that survives variant-option edits without data loss, thoughtful conditional copy. But the form asks every user to do the same amount of scanning and field-hunting regardless of whether they're creating a brand-new manual product (everything applies) or touching one field on a synced product they don't fully own (only 4 fields apply, invisibly interleaved with a dozen disabled ones). The single biggest opportunity is making the editable-vs-locked boundary a first-class visual grouping instead of a banner sentence plus per-field `disabled` styling.

## What's Working

1. **Signature-keyed variant drafts** — editing an option's values doesn't discard already-entered per-variant SKU/price/stock, a genuine anti-data-loss design decision most similar forms get wrong.
2. **Conditional, consequence-first Test-product Alert** — appears only when relevant and states the actual product rule (no team-performance credit, can't ship to courier) instead of generic "this is a test item" boilerplate.
3. **Sticky-header, gradient-masked scroll on both variant tables** — a small but real polish touch that keeps long variant lists legible without hiding that more content exists off-screen.

## Priority Issues

**[P0] Disabled synced-fields have no per-field explanation.** A manager editing a synced product touches this screen regularly, and every one of `name`/`description`/`price`/`public_url`/images renders grayed with only a single sentence in a banner at the top of the card to explain why — three fields and a scroll away from the moment of confusion. A rushed user reasonably reads "grayed field" as "broken," not "store-owned." **Fix**: put a small lock icon + "Synced from store" microcopy directly next to each disabled field's own label, not just once at the top. **Suggested command**: `/impeccable clarify`.

**[P1] Destructive variant removal uses a native `window.confirm`.** Line 730's `!window.confirm(...)` breaks out of the app's own dialog system, can't be styled to match the rest of the form, and gives no structured detail (just a text string) about what's being discarded. **Fix**: swap for an `AlertDialog` (shadcn primitive, likely already in this project's registry) that names the option/variant count being deleted. **Suggested command**: `/impeccable harden`.

**[P1] SKU and Base inventory — the two fields a synced-product user actually edits — sit in different cards, split by the full name/description/images stack.** For daily stock upkeep on a synced item, this forces two round trips through unrelated read-only content to reach the two fields that matter. **Fix**: for a synced product specifically, consider surfacing a compact "what you can still edit here" grouping (SKU, inventory, active, test) ahead of or instead of reusing the full manual-product card order. **Suggested command**: `/impeccable layout`.

**[P2] Hardcoded `$` in the synced-variants price display (line 842), independent of tenant currency.** This is a Morocco-first COD product; a hardcoded dollar sign in a real seller's product-review screen is a visible mismatch with their actual currency (MAD). **Fix**: pull currency from the tenant/store setting, or at minimum use a neutral numeric format until currency is wired through. **Suggested command**: `/impeccable harden`.

**[P2] No confirmed success feedback after save.** `handleSubmit`'s `onSuccess` only redirects (`router.get(productsIndex())`); the backend does flash a toast payload on create/update (`Inertia::flash('toast', ...)` in ProductController), but no `toast()` call site was found anywhere in the frontend — unclear whether that flash is ever rendered. Worth confirming directly rather than assuming either way. **Suggested command**: `/impeccable audit`.

## Persona Red Flags

**Alex (Power User)**: Every variant cell (SKU/price/stock) requires an individual mouse click into a small `InputGroup` — no bulk paste, no "clone this product's variant structure" for a seller adding a near-identical SKU. Setting a gallery image as cover is a hover-revealed icon button with no keyboard-accessible equivalent — Alex working the keyboard gets no path to that action at all.

**Sam (Accessibility-Dependent)**: Gallery image reordering is drag-and-drop only (`image-gallery.tsx`); the "set as cover" button exists but only becomes visible on hover/focus-within, with no arrow-key reorder alternative. Every thumbnail's `<img alt="">` is empty, so a screen-reader user gets zero identifying information about which photo is which beyond raw position in the DOM.

## Minor Observations

- "Base price"/"Base inventory" pair up a badge, a description line, and an italic placeholder simultaneously when variants exist — slightly more visual noise than a 2-column field grid needs.
- The read-only synced-variants table (product-form.tsx) and the editable combinations table (product-variants-editor.tsx) share the same column set and scroll-mask styling but are separately hand-maintained — a future column addition (e.g., a barcode column) is likely to land in one and get missed in the other.
- The variant-editor's "1"/"2" step badges imply a sequence but nothing in the copy states "Step 1"/"Step 2" explicitly.

## Questions to Consider

- Would a synced product genuinely benefit from reusing the full manual-product layout, or would a dedicated "what's still yours to edit" panel (collapsing store-owned fields by default) match how an owner actually thinks about a synced item?
- The Test-product Alert appears only *after* the toggle is flipped — given PRODUCT.md's rule that test data must "never leak," should this be a confirm-before-enable moment instead of a passive after-the-fact notice?
- If MAD is this product's real operating currency, is the `$` in the synced-variants table the only hardcoded currency symbol left in the codebase, or a symptom of a broader gap?
