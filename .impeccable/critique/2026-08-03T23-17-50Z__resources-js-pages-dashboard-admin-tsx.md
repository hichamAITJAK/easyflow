---
target: admin dashboard
total_score: 23
max_score: 40
na_heuristics: 
p0_count: 0
p1_count: 3
timestamp: 2026-08-03T23-17-50Z
slug: resources-js-pages-dashboard-admin-tsx
---
# Design Critique — Admin Dashboard (dashboard/admin.tsx)

Method: dual-agent (A: design review · B: detector). Detector: 0 findings, exit 0. Browser overlays skipped (no automation in session).

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 2 | No loading feedback on filter change; no "last updated" stamp |
| 2 | Match System / Real World | 3 | Domain language excellent; "Settlement variance"/"Confirmed pipeline" lean jargon |
| 3 | User Control and Freedom | 2 | Alerts non-dismissible, non-actionable; no drill-down anywhere |
| 4 | Consistency and Standards | 3 | Two identical agent Selects, different semantics, same aria-label |
| 5 | Error Prevention | 3 | Best-hour = max rate with no attempt floor |
| 6 | Recognition Rather Than Recall | 2 | Delta pills lack period referent; table bands contradict radial targets |
| 7 | Flexibility and Efficiency | 2 | Shareable URLs; no sorting, click-through, export |
| 8 | Aesthetic and Minimalist Design | 3 | Restrained; donut decorates more than answers |
| 9 | Error Recovery | 2 | Alerts name problems, no recovery path |
| 10 | Help and Documentation | 1 | No metric definitions |
| **Total** | | **23/40** | **Acceptable** |

## Specificity verdict
Authored, not interchangeable — best-hour, courier column drop, settlement variance, MAD, target gauges are genuine COD-ops thinking. Weakness: actionability — widgets diagnose but link nowhere.

## Priority issues
1. [P1] Alerts are dead ends — add action button per alert routing to pre-filtered orders; dismissible info alerts.
2. [P1] "Good" defined differently per widget — RateCell hardcodes <50/≥70; targets are 70 conf/60 delivery. Pass PerformanceTargets into table.
3. [P1] Color-alone meaning in table (WCAG 1.4.1); `confirmationRate ?? 0` renders missing as red 0%. Non-color cue + "—" for null.
4. [P2] Return rate illegible on shared ~90% axis in Rates chart; up-is-bad unsignaled. Own panel/strip or destructive color + target band.
5. [P2] Best-hour no attempt floor — min-attempts threshold; encode attempts visually.

## Persona red flags
Alex: alert stack forces navigation elsewhere; table unsortable; radial = one-agent-at-a-time comparison; two independent agent filters; nothing clickable.
Sam: color-only bands; duplicate aria-labels; radial/donut lack accessibilityLayer; muted hourly bars ~<3:1 contrast; title-only tooltips.

## Minor
Stale "brand orange" docstring (chart-1 is teal); donut 3-slice seam puts chart-1/chart-2 adjacent; +0.0% green pill; empty donut center; app.css double import tw-animate-css + duplicate --font-sans + 3 font families; card header wrap at ~360px; money() defeats StatTile exact-value title.

## Questions
1. Radial default = best agent; should be furthest below target, or ranked list?
2. Is rising "Expected from courier" good? Growing float can mean remittance lag.
3. Should widgets carry act-now affordances instead of a separate alert feed?
