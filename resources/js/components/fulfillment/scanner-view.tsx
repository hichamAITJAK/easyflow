import { Camera, CameraOff, Keyboard, Loader2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
        <div className="space-y-3">
            <div className="relative overflow-hidden rounded-xl border bg-muted">
                {/* html5-qrcode injects its <video> into this element by id,
                    so it must stay mounted even while the camera is off —
                    unmounting it between scans makes start() throw. */}
                <div id={elementId} className={live ? 'w-full' : 'hidden'} />

                {!live && (
                    <div className="flex aspect-[4/3] flex-col items-center justify-center gap-3 p-6 text-center">
                        {cameraBroken ? (
                            <CameraOff className="size-10 text-muted-foreground" />
                        ) : (
                            <Camera className="size-10 text-muted-foreground" />
                        )}
                        <p className="text-sm text-muted-foreground">
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
                <Alert variant="destructive">
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}

            <Button
                type="button"
                size="lg"
                variant={live ? 'outline' : 'default'}
                // Tall enough to hit reliably with one thumb while holding a
                // parcel in the other hand.
                className="h-14 w-full text-base"
                onClick={() => void (live ? stop() : start())}
                disabled={busy || status === 'unsupported'}
            >
                {live ? (
                    <>
                        <CameraOff className="size-5" />
                        {t('Stop camera')}
                    </>
                ) : (
                    <>
                        <Camera className="size-5" />
                        {t('Scan with camera')}
                    </>
                )}
            </Button>

            <form onSubmit={submitManual} className="flex gap-2">
                <div className="relative flex-1">
                    <Keyboard className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        ref={manualRef}
                        value={manual}
                        onChange={(event) => setManual(event.target.value)}
                        placeholder={t('Or type / scan tracking number')}
                        className="h-12 pl-9"
                        autoComplete="off"
                        autoCapitalize="characters"
                        spellCheck={false}
                        enterKeyHint="search"
                        disabled={busy}
                    />
                </div>
                <Button
                    type="submit"
                    size="lg"
                    variant="secondary"
                    className="h-12"
                    disabled={busy || manual.trim() === ''}
                >
                    {t('Look up')}
                </Button>
            </form>
        </div>
    );
}
