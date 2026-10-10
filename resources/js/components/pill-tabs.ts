/**
 * Segmented "pill" tabs: a grey track with the active tab as a white pill.
 * Pass these to the shadcn TabsList / TabsTrigger.
 *
 * The shadcn TabsList pins its height with `group-data-horizontal/tabs:h-8`,
 * which Tailwind emits after plain `h-auto` at the same specificity — so a
 * bare `h-auto` loses, the 40px pills overflow the 32px track and, once the
 * row wraps on a phone, spill over the content below. Overriding the same
 * variant frees the height; on small screens the row scrolls sideways
 * instead of wrapping.
 */
export const PILL_TABS_LIST =
    'h-auto group-data-horizontal/tabs:h-auto w-fit max-w-full justify-start gap-1 overflow-x-auto rounded-lg border-0 bg-muted p-1 no-scrollbar';

export const PILL_TABS_TRIGGER =
    'h-auto flex-none gap-1.5 rounded-md border-0 px-3.5 py-1.5 text-sm font-medium text-muted-foreground hover:text-foreground data-[state=active]:bg-card data-[state=active]:font-semibold data-[state=active]:text-foreground data-[state=active]:shadow-xs dark:data-[state=active]:border-0 dark:data-[state=active]:bg-card';

/** Neutral count next to a pill tab's label (e.g. team members). */
export const PILL_TABS_COUNT =
    'rounded-full bg-muted-foreground/12 px-1.5 py-0.5 font-mono text-[11px] leading-none font-semibold tabular-nums';

/** Count bubble inside a pill tab (e.g. items waiting in a queue). */
export const PILL_TABS_BADGE =
    'rounded-full bg-[#F2602F] px-1.5 py-0.5 text-[10px] leading-none font-bold text-white';
