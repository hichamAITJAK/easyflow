/**
 * Shared types and helpers for the manual-product variant editor: turning a
 * set of options (Size: S/M/L) into the cartesian product of variant
 * combinations, and giving each combination a stable, order-independent
 * signature so per-combination edits survive the matrix regenerating.
 */

export type OptionDraft = { name: string; values: string[] };

export type VariantDraft = {
    sku: string;
    image: string;
    price: string;
    inventory_quantity: string;
    is_available: boolean;
};

export type VariantCombination = { name: string; value: string }[];

export function blankVariantDraft(): VariantDraft {
    return {
        sku: '',
        image: '',
        price: '',
        inventory_quantity: '',
        is_available: true,
    };
}

/**
 * Order-independent key for a combination — sorted by option name and
 * joined with control chars unlikely to appear in a value, so the same
 * combination hashes identically whether it comes from the generated matrix
 * or from a variant loaded back off the server.
 */
export function variantSignature(pairs: VariantCombination): string {
    return [...pairs]
        .sort((a, b) => a.name.localeCompare(b.name))
        .map((pair) => `${pair.name}␟${pair.value}`)
        .join('␞');
}

/**
 * Every combination of the options' values (cartesian product). Options
 * with a blank name or no non-blank values are ignored, so a half-typed
 * option doesn't wipe the matrix.
 */
export function variantCombinations(
    options: OptionDraft[],
): VariantCombination[] {
    const valid = options
        .map((option) => ({
            name: option.name.trim(),
            values: option.values.map((value) => value.trim()).filter(Boolean),
        }))
        .filter((option) => option.name !== '' && option.values.length > 0);

    if (valid.length === 0) {
        return [];
    }

    return valid.reduce<VariantCombination[]>(
        (combinations, option) =>
            combinations.flatMap((combination) =>
                option.values.map((value) => [
                    ...combination,
                    { name: option.name, value },
                ]),
            ),
        [[]],
    );
}
