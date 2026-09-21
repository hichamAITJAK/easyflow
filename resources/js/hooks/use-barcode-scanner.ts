import { Html5Qrcode, Html5QrcodeSupportedFormats } from 'html5-qrcode';
import type { Html5QrcodeCameraScanConfig } from 'html5-qrcode';
import { useCallback, useEffect, useId, useRef, useState } from 'react';

export type ScannerStatus = 'idle' | 'starting' | 'scanning' | 'denied' | 'unsupported' | 'error';

/**
 * The label symbologies Moroccan couriers actually print. Restricting the
 * decoder to this set rather than letting it try everything measurably
 * speeds up each frame, which is what makes back-to-back scanning feel
 * instant instead of laggy.
 */
const FORMATS = [
    Html5QrcodeSupportedFormats.QR_CODE,
    Html5QrcodeSupportedFormats.CODE_128,
    Html5QrcodeSupportedFormats.CODE_39,
    Html5QrcodeSupportedFormats.EAN_13,
    Html5QrcodeSupportedFormats.ITF,
];

const CONFIG: Html5QrcodeCameraScanConfig = {
    fps: 10,
    // A wide, short box: courier labels are 1D barcodes far wider than they
    // are tall, and a square reticle makes the agent hunt for an alignment
    // that a letterbox finds on the first try.
    qrbox: { width: 260, height: 160 },
    aspectRatio: 1.0,
};

/**
 * Owns the camera lifecycle for the fulfilment scan screen.
 *
 * Two behaviours matter more than the decoding itself:
 *
 * 1. The camera is torn down the moment a code is read, and only restarted
 *    when the caller explicitly asks. A warehouse agent scans, reads the
 *    preview card, then commits — leaving the decoder running through that
 *    pause burns battery and re-fires on the same label still in frame.
 *
 * 2. `onScan` is held in a ref rather than captured in the start closure,
 *    so the caller can pass an inline handler without every re-render
 *    restarting the camera (which would flash the video element black
 *    between scans).
 */
export function useBarcodeScanner(onScan: (value: string) => void) {
    const [status, setStatus] = useState<ScannerStatus>('idle');
    const [error, setError] = useState<string | null>(null);

    const scannerRef = useRef<Html5Qrcode | null>(null);

    // html5-qrcode mounts its <video> into an element looked up by id, so
    // the id has to be stable across renders and unique per instance.
    // useId gives both without a render-phase random value.
    const elementId = `scanner-${useId().replace(/:/g, '')}`;

    // The callback is kept in a ref so a caller can pass an inline handler
    // without every re-render restarting the camera, which would flash the
    // video element black between scans. Assigned in an effect rather than
    // during render to keep the render phase free of side effects.
    const onScanRef = useRef(onScan);

    useEffect(() => {
        onScanRef.current = onScan;
    }, [onScan]);

    const stop = useCallback(async () => {
        const scanner = scannerRef.current;
        scannerRef.current = null;

        if (!scanner) {
            return;
        }

        try {
            if (scanner.isScanning) {
                await scanner.stop();
            }

            scanner.clear();
        } catch {
            // The element can already be gone if React unmounted the page
            // mid-scan; there is nothing left to release in that case.
        }

        setStatus('idle');
    }, []);

    const start = useCallback(async () => {
        if (scannerRef.current) {
            return;
        }

        if (!navigator.mediaDevices?.getUserMedia) {
            setStatus('unsupported');
            setError('This browser cannot open the camera. Use the manual entry field below.');

            return;
        }

        setStatus('starting');
        setError(null);

        const scanner = new Html5Qrcode(elementId, {
            formatsToSupport: FORMATS,
            verbose: false,
        });
        scannerRef.current = scanner;

        try {
            await scanner.start(
                // Prefer the rear camera without demanding it: an exact
                // constraint throws outright on laptops and on phones that
                // label their cameras unusually, which would leave an agent
                // with a dead screen instead of a working front camera.
                { facingMode: 'environment' },
                CONFIG,
                (decoded) => {
                    void stop();
                    onScanRef.current(decoded);
                },
                () => {
                    // Fired for every frame without a readable code, which is
                    // most of them. Not an error worth surfacing.
                },
            );

            setStatus('scanning');
        } catch (cause) {
            scannerRef.current = null;

            const name = cause instanceof Error ? cause.name : '';
            const denied = name === 'NotAllowedError' || name === 'PermissionDeniedError';

            setStatus(denied ? 'denied' : 'error');
            setError(
                denied
                    ? 'Camera access was blocked. Allow it in your browser settings, or type the tracking number below.'
                    : 'The camera could not be started. Type the tracking number below instead.',
            );
        }
    }, [stop, elementId]);

    // Release the camera if the agent navigates away mid-scan; without this
    // the device keeps the capture light on until the tab is closed.
    useEffect(() => () => void stop(), [stop]);

    return { status, error, start, stop, elementId };
}
