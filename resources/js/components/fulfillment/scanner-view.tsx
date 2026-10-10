import { Camera, CameraOff, Loader2, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useBarcodeScanner } from '@/hooks/use-barcode-scanner';
import { useTranslation } from '@/hooks/use-translation';

/**
 * The camera viewfinder plus its manual fallback.
 *
 * The manual input is not a degraded path — handheld USB/Bluetooth
 * scanners (common on a packing bench) behave as keyboards that type the
 * code and press Enter, so this field is how those devices work at all.
 * It stays focused whenever the camera is off so a bench scanner can fire
 * straight into it without anyone tapping first.
 */
export function ScannerView({
    onScan,
    busy,
}: {
    onScan: (value: string) => void;
    busy: boolean;
}) {
    const { t } = useTranslation();

    const { status, error, start, stop, elementId } = useBarcodeScanner(onScan);
    const [manual, setManual] = useState('');
    const manualRef = useRef<HTMLInputElement>(null);

    const live = status === 'scanning' || status === 'starting';
    const cameraBroken =
        status === 'denied' || status === 'unsupported' || status === 'error';

    // Keep the bench scanner's target focused whenever the camera isn't
    // holding the screen, including right after a parcel is committed.
    useEffect(() => {
        if (!live && !busy) {
            manualRef.current?.focus();
        }
    }, [live, busy]);

    const submitManual = (event: React.FormEvent) => {
        event.preventDefault();
        const value = manual.trim();

        if (!value || busy) {
            return;
        }

        setManual('');
        onScan(value);
    };

    return (
        <div>
            <div
                className={
                    live
                        ? 'relative mt-4 overflow-hidden rounded-xl border border-border bg-muted'
                        : 'relative mt-4 overflow-hidden rounded-xl border border-dashed border-border bg-muted/40'
                }
            >
                {/* html5-qrcode injects its <video> into this element by id,
                    so it must stay mounted even while the camera is off —
                    unmounting it between scans makes start() throw. */}
                <div id={elementId} className={live ? 'w-full' : 'hidden'} />

                {!live && (
                    <div className="flex aspect-[4/3] flex-col items-center justify-center px-6 text-center">
                        <span className="flex size-14 items-center justify-center rounded-full bg-background text-muted-foreground shadow-xs">
                            {cameraBroken ? (
                                <CameraOff className="size-7" />
                            ) : (
                                <Camera className="size-7" />
                            )}
                        </span>
                        <p className="mt-4 text-sm font-medium text-muted-foreground">
                            {cameraBroken
                                ? t('Camera unavailable')
                                : t('Point the camera at the parcel label')}
                        </p>
                    </div>
                )}

                {status === 'starting' && (
                    <div className="absolute inset-0 flex items-center justify-center bg-background/70">
                        <Loader2 className="size-6 animate-spin text-muted-foreground" />
                    </div>
                )}
            </div>

            {error && (
                <Alert variant="destructive" className="mt-4">
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}

            <Button
                type="button"
                variant={live ? 'outline' : 'default'}
                // Tall enough to hit reliably with one thumb while holding a
                // parcel in the other hand.
                className="mt-4 h-12 w-full gap-2.5 rounded-xl text-base font-semibold shadow-xs hover:opacity-90 [&_svg]:size-5"
                onClick={() => void (live ? stop() : start())}
                disabled={busy || status === 'unsupported'}
            >
                {live ? (
                    <>
                        <CameraOff />
                        {t('Stop camera')}
                    </>
                ) : (
                    <>
                        <Camera />
                        {t('Scan with camera')}
                    </>
                )}
            </Button>

            <div className="mt-5 flex items-center gap-3">
                <span className="h-px flex-1 bg-border" />
                <span className="shrink-0 text-xs font-medium text-muted-foreground">
                    {t('or enter the tracking number')}
                </span>
                <span className="h-px flex-1 bg-border" />
            </div>

            <form onSubmit={submitManual} className="mt-4">
                <Label
                    htmlFor="fulfilment-tracking"
                    className="mb-1.5 block text-sm font-medium"
                >
                    {t('Tracking number')}
                </Label>
                <div className="flex gap-2">
                    <Input
                        id="fulfilment-tracking"
                        ref={manualRef}
                        value={manual}
                        onChange={(event) => setManual(event.target.value)}
                        placeholder="AMX-2026-00841"
                        className="h-11 min-w-0 flex-1 rounded-lg bg-card px-3.5 font-mono text-sm tracking-wide uppercase shadow-xs placeholder:font-sans placeholder:tracking-normal placeholder:normal-case"
                        autoComplete="off"
                        autoCapitalize="characters"
                        spellCheck={false}
                        enterKeyHint="search"
                        disabled={busy}
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        className="h-11 shrink-0 gap-2 rounded-lg bg-card px-4 shadow-xs"
                        disabled={busy || manual.trim() === ''}
                    >
                        <Search className="size-4" />
                        {t('Look up')}
                    </Button>
                </div>
                <p className="mt-2 text-xs text-muted-foreground">
                    {t(
                        'Use this when the label is damaged or the camera will not focus.',
                    )}
                </p>
            </form>
        </div>
    );
}
