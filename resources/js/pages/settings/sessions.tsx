import { Head, router } from '@inertiajs/react';
import { Info, LogOut, Monitor, Smartphone, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Field } from '@/components/ui/field';
import { Label } from '@/components/ui/label';
import {
    destroy as destroySession,
    destroyDevice,
    destroyDevices,
    destroyOthers,
} from '@/routes/sessions';

type BrowserSession = {
    id: string;
    device: string;
    isMobile: boolean;
    ipAddress: string | null;
    lastActiveDiff: string;
    isCurrent: boolean;
};

type MobileDevice = {
    id: number;
    name: string;
    lastUsedDiff: string | null;
    createdDiff: string | null;
};

export default function Sessions({
    sessions,
    devices,
    usesDatabaseSessions,
}: {
    sessions: BrowserSession[];
    devices: MobileDevice[];
    usesDatabaseSessions: boolean;
}) {
    const [confirmingSignOutAll, setConfirmingSignOutAll] = useState(false);
    const [password, setPassword] = useState('');
    const [passwordError, setPasswordError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    const otherSessionCount = sessions.filter(
        (session) => !session.isCurrent,
    ).length;

    const signOutOthers = () => {
        setProcessing(true);
        setPasswordError(undefined);

        router.delete(destroyOthers(), {
            data: { password },
            preserveScroll: true,
            onError: (errors) => setPasswordError(errors.password),
            onSuccess: () => {
                setConfirmingSignOutAll(false);
                setPassword('');
            },
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <>
            <Head title="Sessions" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Browsers"
                    description="Devices signed in to the web app with your account."
                />

                {!usesDatabaseSessions ? (
                    <Alert>
                        <Info />
                        <AlertDescription>
                            Browser sessions can't be listed while the app uses
                            file-based sessions. Set SESSION_DRIVER=database to
                            see and revoke them here.
                        </AlertDescription>
                    </Alert>
                ) : sessions.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No active browser sessions.
                    </p>
                ) : (
                    <div className="divide-y rounded-lg border">
                        {sessions.map((session) => (
                            <div
                                key={session.id}
                                className="flex items-center gap-4 p-4"
                            >
                                <div className="flex size-9 shrink-0 items-center justify-center rounded-md border text-muted-foreground">
                                    {session.isMobile ? (
                                        <Smartphone className="size-4" />
                                    ) : (
                                        <Monitor className="size-4" />
                                    )}
                                </div>

                                <div className="min-w-0 flex-1 space-y-0.5">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {session.device}
                                        </span>
                                        {session.isCurrent && (
                                            <Badge
                                                variant="outline"
                                                className="border-emerald-500/30 bg-emerald-500/10 text-xs font-medium text-emerald-700 dark:text-emerald-400"
                                            >
                                                This device
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        {session.ipAddress ?? 'Unknown IP'} ·{' '}
                                        {session.isCurrent
                                            ? 'Active now'
                                            : `Last active ${session.lastActiveDiff}`}
                                    </p>
                                </div>

                                {!session.isCurrent && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            router.delete(
                                                destroySession(session.id),
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Sign out
                                    </Button>
                                )}
                            </div>
                        ))}
                    </div>
                )}

                {otherSessionCount > 0 && (
                    <Button
                        variant="outline"
                        onClick={() => setConfirmingSignOutAll(true)}
                    >
                        <LogOut />
                        Sign out other browsers
                    </Button>
                )}
            </div>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Mobile devices"
                    description="Phones and tablets signed in to the EasyFlow mobile app."
                />

                {devices.length === 0 ? (
                    <Empty className="border">
                        <EmptyHeader>
                            <EmptyMedia variant="icon">
                                <Smartphone />
                            </EmptyMedia>
                            <EmptyTitle>No mobile devices</EmptyTitle>
                            <EmptyDescription>
                                Devices appear here after signing in to the
                                mobile app.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <>
                        <div className="divide-y rounded-lg border">
                            {devices.map((device) => (
                                <div
                                    key={device.id}
                                    className="flex items-center gap-4 p-4"
                                >
                                    <div className="flex size-9 shrink-0 items-center justify-center rounded-md border text-muted-foreground">
                                        <Smartphone className="size-4" />
                                    </div>

                                    <div className="min-w-0 flex-1 space-y-0.5">
                                        <span className="font-medium">
                                            {device.name}
                                        </span>
                                        <p className="text-xs text-muted-foreground">
                                            {device.lastUsedDiff
                                                ? `Last used ${device.lastUsedDiff}`
                                                : 'Never used'}
                                            {device.createdDiff &&
                                                ` · Added ${device.createdDiff}`}
                                        </p>
                                    </div>

                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            router.delete(
                                                destroyDevice(device.id),
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Sign out
                                    </Button>
                                </div>
                            ))}
                        </div>

                        <Button
                            variant="outline"
                            onClick={() =>
                                router.delete(destroyDevices(), {
                                    preserveScroll: true,
                                })
                            }
                        >
                            <Trash2 />
                            Sign out all devices
                        </Button>
                    </>
                )}
            </div>

            <Dialog
                open={confirmingSignOutAll}
                onOpenChange={(open) => {
                    setConfirmingSignOutAll(open);

                    if (!open) {
                        setPassword('');
                        setPasswordError(undefined);
                    }
                }}
            >
                <DialogContent>
                    <DialogTitle>Sign out other browsers?</DialogTitle>
                    <DialogDescription>
                        Every browser except this one is signed out. Enter your
                        password to confirm it's you.
                    </DialogDescription>

                    <Field>
                        <Label htmlFor="password">Password</Label>
                        <PasswordInput
                            id="password"
                            value={password}
                            onChange={(event) =>
                                setPassword(event.target.value)
                            }
                            autoComplete="current-password"
                            onKeyDown={(event) => {
                                if (event.key === 'Enter' && password) {
                                    signOutOthers();
                                }
                            }}
                        />
                        <InputError message={passwordError} />
                    </Field>

                    <DialogFooter className="gap-2">
                        <Button
                            variant="secondary"
                            onClick={() => setConfirmingSignOutAll(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={processing || !password}
                            onClick={signOutOthers}
                        >
                            Sign out other browsers
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
