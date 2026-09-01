const MOROCCO_COUNTRY_CODE = '212';

/**
 * Normalize any Moroccan phone number into the digits-only `212XXXXXXXXX`
 * form WhatsApp's `wa.me` links require, regardless of how it was entered:
 * spaces, dashes, dots, parens, a leading `+`, `00212`, `212`, or the local
 * `0X...` form are all accepted. Returns null if there aren't enough digits
 * left to be a real number, so callers can skip rendering a WhatsApp link
 * instead of sending a broken one.
 */
export function formatMoroccoPhoneForWhatsApp(value: string): string | null {
    const digits = value.replace(/\D/g, '');

    if (digits === '') {
        return null;
    }

    let national = digits;

    if (national.startsWith('00212')) {
        national = national.slice(5);
    } else if (national.startsWith('212')) {
        national = national.slice(3);
    } else if (national.startsWith('0')) {
        national = national.slice(1);
    }

    if (national.length !== 9) {
        return null;
    }

    return MOROCCO_COUNTRY_CODE + national;
}
