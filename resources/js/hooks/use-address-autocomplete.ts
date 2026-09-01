import { useEffect, useRef, useState } from 'react';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { loadGooglePlaces } from '@/lib/google-places';

export type AddressSuggestion = {
    placeId: string;
    mainText: string;
    secondaryText: string;
    prediction: google.maps.places.PlacePrediction;
};

export type ResolvedAddress = {
    formattedAddress: string;
    city: string | null;
};

/**
 * Drives a Google Places text-search autocomplete field: debounces the
 * query, fetches suggestions under one AutocompleteSessionToken, and
 * resolves a selected suggestion into a formatted address + city via Place
 * Details.
 *
 * Billing: a session is one query-then-select flow, not one request per
 * keystroke — but only if the same session token flows through to the
 * Place Details call. Google does this automatically as long as we call
 * fetchFields on the Place instance returned by PlacePrediction.toPlace()
 * (rather than constructing a fresh Place from a bare place ID, which
 * would drop the session association and get billed per-request).
 */
export function useAddressAutocomplete(query: string) {
    const [suggestions, setSuggestions] = useState<AddressSuggestion[]>([]);
    const [loading, setLoading] = useState(false);
    const [unavailable, setUnavailable] = useState(false);
    const sessionTokenRef = useRef<google.maps.places.AutocompleteSessionToken | null>(
        null,
    );
    const debouncedQuery = useDebouncedValue(query, 300);

    useEffect(() => {
        if (debouncedQuery.trim().length < 3) {
            setSuggestions([]);

            return;
        }

        let cancelled = false;
        setLoading(true);

        loadGooglePlaces()
            .then(async (google) => {
                if (cancelled) {
                    return;
                }

                sessionTokenRef.current ??=
                    new google.maps.places.AutocompleteSessionToken();

                const { suggestions: results } =
                    await google.maps.places.AutocompleteSuggestion.fetchAutocompleteSuggestions(
                        {
                            input: debouncedQuery,
                            sessionToken: sessionTokenRef.current,
                            includedRegionCodes: ['ma'],
                        },
                    );

                if (cancelled) {
                    return;
                }

                setSuggestions(
                    results
                        .filter((result) => result.placePrediction)
                        .map((result) => {
                            const prediction = result.placePrediction!;

                            return {
                                placeId: prediction.placeId,
                                mainText:
                                    prediction.mainText?.toString() ?? '',
                                secondaryText:
                                    prediction.secondaryText?.toString() ??
                                    '',
                                prediction,
                            };
                        }),
                );
            })
            .catch(() => {
                if (!cancelled) {
                    setUnavailable(true);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [debouncedQuery]);

    /**
     * Resolve a chosen suggestion into a full address. Converting the
     * prediction to a Place (rather than constructing one from a bare id)
     * is what carries the session token through to fetchFields, closing
     * out this search as one billed session.
     */
    const resolveSuggestion = async (
        suggestion: AddressSuggestion,
    ): Promise<ResolvedAddress | null> => {
        const place = suggestion.prediction.toPlace();

        await place.fetchFields({
            fields: ['formattedAddress', 'addressComponents'],
        });

        sessionTokenRef.current = null;

        const cityComponent = place.addressComponents?.find((component) =>
            component.types.includes('locality'),
        );

        return {
            formattedAddress: place.formattedAddress ?? '',
            city: cityComponent?.longText ?? null,
        };
    };

    return { suggestions, loading, unavailable, resolveSuggestion };
}
