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

    return Object.entries(replace).reduce(
        (out, [key, value]) => out.replaceAll(`:${key}`, String(value)),
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
    return (key, replace) => interpolate(translations?.[key] ?? key, replace);
}
