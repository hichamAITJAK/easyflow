/**
 * Replace `:name` placeholders, Laravel style, so one string file serves
 * both PHP's __() and the React side without two placeholder dialects.
 */
export function interpolate(
    text: string,
    replace?: Record<string, string | number>,
): string {
    if (!replace) {
        return text;
    }

    // Longest placeholder first, so `:count` never eats into `:countLabel`.
    return Object.entries(replace)
        .sort(([a], [b]) => b.length - a.length)
        .reduce(
            (out, [key, value]) =>
                out.replaceAll(`:${key}`, String(value ?? '')),
            text,
        );
}

export type Translator = (
    key: string,
    replace?: Record<string, string | number>,
) => string;

/**
 * Build a translator over one language's strings. A key with no entry
 * renders as itself: the keys are the English copy, so an untranslated
 * string degrades to English rather than to a blank or a raw identifier.
 */
export function createTranslator(
    translations: Record<string, string> | undefined,
): Translator {
    return (key, replace) => {
        // Never throw from a render: an unexpected non-string key (a label
        // map miss, an undefined status) degrades to empty text.
        if (typeof key !== 'string') {
            return key == null ? '' : String(key);
        }

        return interpolate(translations?.[key] ?? key, replace);
    };
}
