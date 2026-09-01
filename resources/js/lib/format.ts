/**
 * Consistent date + time formatting, e.g. "Jul 7, 2026, 2:23 PM" — used
 * anywhere a timestamp is shown so the format doesn't drift per-component.
 *
 * Locale is pinned (not `undefined`/ambient) because this render also runs
 * server-side during Inertia SSR, where the Node process's default locale
 * can differ from the browser's — an ambient locale would format the same
 * timestamp two different ways and fail React hydration.
 */
const DATE_TIME_LOCALE = 'en-US';

export function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(DATE_TIME_LOCALE, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

export function formatDate(
    value: string,
    options: Intl.DateTimeFormatOptions = { dateStyle: 'medium' },
): string {
    return new Date(value).toLocaleDateString(DATE_TIME_LOCALE, options);
}

/**
 * Formats a calendar date — a day with no time-of-day meaning, like an
 * invoice period boundary, backed by a Laravel `date` cast.
 *
 * Forced to UTC, and that is the whole point. Such a value serializes as
 * `2026-06-01T00:00:00.000000Z`, and reading it in local time shifts it a
 * day backwards anywhere west of UTC — a June invoice would print as
 * starting "May 31". The instant is an encoding artifact; only the calendar
 * day is real, so it is read back in the same zone it was written in.
 *
 * Use `formatDateTime` for genuine timestamps, where local time is correct.
 */
export function formatCalendarDate(
    value: string,
    options: Intl.DateTimeFormatOptions = { dateStyle: 'medium' },
): string {
    return new Date(value).toLocaleDateString(DATE_TIME_LOCALE, {
        ...options,
        timeZone: 'UTC',
    });
}

/**
 * A start–end calendar range, e.g. "Jun 1 – 30, 2026" or
 * "Dec 28, 2025 – Jan 3, 2026". Shared parts are said once: the year is
 * dropped from the start when both fall in the same year, and the month
 * too when both fall in the same month, so a period reads as one span
 * instead of two dates that happen to sit next to each other.
 *
 * Uses an en dash with hairline spacing, the typographic convention for a
 * range. Falls back to a plain join if either bound is unparseable rather
 * than rendering "Invalid Date".
 */
export function formatDateRange(start: string, end: string): string {
    const startDate = new Date(start);
    const endDate = new Date(end);

    if (Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime())) {
        return `${start} – ${end}`;
    }

    const sameYear = startDate.getUTCFullYear() === endDate.getUTCFullYear();
    const sameMonth =
        sameYear && startDate.getUTCMonth() === endDate.getUTCMonth();

    const full: Intl.DateTimeFormatOptions = {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    };

    // A one-day period is a date, not a span — "Jun 5 – 5, 2026" reads as a
    // rendering glitch.
    if (startDate.getTime() === endDate.getTime()) {
        return formatCalendarDate(start, full);
    }

    if (!sameYear) {
        return `${formatCalendarDate(start, full)} – ${formatCalendarDate(end, full)}`;
    }

    // Within one month the end needs only its day number. That is built by
    // hand rather than through Intl: asking for `{ day, year }` with no
    // month makes Intl emit its own fallback ("2026 (day: 30)"), so the
    // pieces are composed here instead.
    if (sameMonth) {
        const startText = formatCalendarDate(start, {
            month: 'short',
            day: 'numeric',
        });
        const endDay = formatCalendarDate(end, { day: 'numeric' });

        return `${startText} – ${endDay}, ${endDate.getUTCFullYear()}`;
    }

    return `${formatCalendarDate(start, { month: 'short', day: 'numeric' })} – ${formatCalendarDate(end, full)}`;
}

/**
 * Compact relative time for a timestamp already known to be in the past,
 * e.g. "2h ago", "3d ago" — falls back to a plain date past 7 days so an
 * old order doesn't read as a vague, ever-growing "N weeks ago".
 */
export function formatRelativeTime(value: string): string {
    const then = new Date(value).getTime();
    const diffMs = Date.now() - then;
    const diffMinutes = Math.floor(diffMs / 60_000);

    if (diffMinutes < 1) {
        return 'just now';
    }

    if (diffMinutes < 60) {
        return `${diffMinutes}m ago`;
    }

    const diffHours = Math.floor(diffMinutes / 60);

    if (diffHours < 24) {
        return `${diffHours}h ago`;
    }

    const diffDays = Math.floor(diffHours / 24);

    if (diffDays < 7) {
        return `${diffDays}d ago`;
    }

    return formatDate(value);
}

/** Thousands-separated, e.g. 12345 -> "12,345". */
export function formatNumber(value: number): string {
    return new Intl.NumberFormat(DATE_TIME_LOCALE).format(value);
}

/**
 * Shortens long numbers so they can't blow out a stat tile or an axis label,
 * e.g. 1234567 -> "1.2M". Values below `threshold` are left exact — rounding
 * a figure someone might need to read precisely (an order count, a small
 * revenue total) trades away more than the space it saves, so the shortening
 * only kicks in once the digits actually stop fitting.
 *
 * Compact notation is lossy by design. Pair it with a `title` attribute or a
 * tooltip carrying `formatNumber(value)` anywhere the exact figure matters.
 */
export function formatCompactNumber(value: number, threshold = 10_000): string {
    if (!Number.isFinite(value)) {
        return '—';
    }

    if (Math.abs(value) < threshold) {
        return formatNumber(value);
    }

    return new Intl.NumberFormat(DATE_TIME_LOCALE, {
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(value);
}
