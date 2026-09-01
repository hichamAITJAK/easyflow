/**
 * Lazily loads the Google Maps JS SDK (places library only) exactly once,
 * no matter how many AddressAutocomplete instances mount — the script tag
 * itself is a singleton promise so concurrent mounts share one load.
 */
let loadPromise: Promise<typeof google> | null = null;

export function loadGooglePlaces(): Promise<typeof google> {
    if (loadPromise) {
        return loadPromise;
    }

    const apiKey = import.meta.env.VITE_GOOGLE_MAPS_API_KEY as
        | string
        | undefined;

    if (!apiKey) {
        return Promise.reject(
            new Error('VITE_GOOGLE_MAPS_API_KEY is not configured.'),
        );
    }

    loadPromise = new Promise((resolve, reject) => {
        if (window.google?.maps?.places) {
            resolve(window.google);

            return;
        }

        const script = document.createElement('script');
        script.src = `https://maps.googleapis.com/maps/api/js?key=${apiKey}&libraries=places&loading=async`;
        script.async = true;
        script.onerror = () =>
            reject(new Error('Failed to load the Google Maps script.'));
        script.onload = () => {
            if (window.google?.maps?.places) {
                resolve(window.google);
            } else {
                reject(new Error('Google Maps loaded without the places library.'));
            }
        };

        document.head.appendChild(script);
    });

    return loadPromise;
}

export function isGooglePlacesConfigured(): boolean {
    return Boolean(import.meta.env.VITE_GOOGLE_MAPS_API_KEY);
}
