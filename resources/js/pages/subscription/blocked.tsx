import { Form, Head, router, usePage } from '@inertiajs/react';
import {
    Check,
    Clock,
    Copy,
    FileText,
    Landmark,
    LogOut,
    MessageCircle,
    Upload,
} from 'lucide-react';
import { useRef, useState } from 'react';
import SubscriptionController from '@/actions/App/Http/Controllers/Subscription/SubscriptionController';
import AppLogoIcon from '@/components/app-logo-icon';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Separator } from '@/components/ui/separator';
import { logout } from '@/routes';
import type {
    BankDetails,
    PageProps,
    Plan,
    SubscriptionSummary,
} from '@/types';

function CopyButton({ value, label }: { value: string; label: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={`Copy ${label}`}
            onClick={() => {
                navigator.clipboard.writeText(value);
                setCopied(true);
                setTimeout(() => setCopied(false), 1500);
            }}
        >
            {copied ? (
                <Check className="size-3.5 text-emerald-500" />
            ) : (
                <Copy className="size-3.5" />
            )}
        </Button>
    );
}

function BankRow({
    label,
    value,
    mono = false,
    copyable = false,
}: {
    label: string;
    value: string;
    mono?: boolean;
    copyable?: boolean;
}) {
    return (
        <div className="flex items-center justify-between gap-3 py-2">
            <span className="shrink-0 text-sm text-muted-foreground">
                {label}
            </span>
            <span className="flex min-w-0 items-center gap-1">
                <span
                    className={
                        mono
                            ? 'truncate font-mono text-sm font-medium tabular-nums'
                            : 'truncate text-sm font-medium'
                    }
                >
                    {value}
                </span>
                {copyable && <CopyButton value={value} label={label} />}
            </span>
        </div>
    );
}

export default function SubscriptionBlocked({
    isBlocked,
    currentSubscription,
    plans,
    bank,
    contactWhatsapp,
    canSubmit,
    nextReferenceCode,
}: {
    isBlocked: boolean;
    currentSubscription: SubscriptionSummary | null;
    plans: Plan[];
    bank: BankDetails;
    contactWhatsapp: string;
    canSubmit: boolean;
    nextReferenceCode: string;
}) {
    const { auth } = usePage<PageProps>().props;
    const [receiptName, setReceiptName] = useState<string | null>(null);
    const receiptInputRef = useRef<HTMLInputElement>(null);

    const pending = currentSubscription?.status === 'pending';
    const rejected = currentSubscription?.status === 'rejected';
    const wasTrial = currentSubscription?.isTrial ?? true;
    const plan = plans[0] ?? null;

    const whatsappHref = contactWhatsapp
        ? `https://wa.me/${contactWhatsapp.replace(/[^0-9]/g, '')}`
        : null;

    const heading = pending
        ? 'Payment received? We are checking.'
        : !isBlocked
            ? 'Renew your subscription'
            : wasTrial
                ? 'Your free trial has ended'
                : 'Your subscription has ended';

    const subheading = pending
        ? 'Your request is waiting for confirmation. You will get an email the moment it is approved — usually within one business day.'
        : !isBlocked
            ? 'Renew now to avoid any interruption. Days you already paid for are never lost.'
            : canSubmit
                ? 'Pay by bank transfer or cash to keep using EasyFlow. Your data is safe and will be right here when you are back.'
                : 'Ask your workspace owner to renew the subscription. Your data is safe.';

    return (
        <div className="flex min-h-svh flex-col bg-muted/30">
            <Head title="Subscription" />

            <header className="flex items-center justify-between px-6 py-4">
                <div className="flex items-center gap-2 text-sm font-semibold tracking-tight">
                    <AppLogoIcon className="size-5 fill-current" />
                    EasyFlow
                </div>
                <Button
                    variant="ghost"
                    size="sm"
                    className="text-muted-foreground"
                    onClick={() => router.post(logout().url)}
                >
                    <LogOut className="size-4" />
                    Log out
                </Button>
            </header>

            <main className="mx-auto w-full max-w-xl flex-1 px-4 pt-6 pb-16 sm:px-6">
                <div className="mb-8 space-y-2">
                    <h1 className="text-2xl font-semibold tracking-tight text-balance">
                        {heading}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {subheading}
                    </p>
                </div>

                {pending && currentSubscription ? (
                    <Card>
                        <CardContent className="space-y-4">
                            <div className="flex items-center gap-3">
                                <div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-amber-500/10">
                                    <Clock className="size-5 text-amber-500" />
                                </div>
                                <div className="min-w-0">
                                    <div className="font-medium">
                                        {currentSubscription.planName} — waiting
                                        for confirmation
                                    </div>
                                    <div className="text-sm text-muted-foreground">
                                        Submitted{' '}
                                        {currentSubscription.submittedAt ?? '—'}
                                    </div>
                                </div>
                                <Badge
                                    variant="outline"
                                    className="ml-auto shrink-0 border-amber-500/30 bg-amber-500/10 font-mono text-xs text-amber-600 dark:text-amber-400"
                                >
                                    {currentSubscription.referenceCode}
                                </Badge>
                            </div>

                            {whatsappHref && (
                                <>
                                    <Separator />
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <p className="text-sm text-muted-foreground">
                                            Sent the transfer already? Speed
                                            things up:
                                        </p>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <a
                                                href={whatsappHref}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                <MessageCircle className="size-4" />
                                                WhatsApp us
                                            </a>
                                        </Button>
                                    </div>
                                </>
                            )}
                        </CardContent>
                    </Card>
                ) : !canSubmit ? (
                    whatsappHref && (
                        <Button variant="outline" asChild>
                            <a
                                href={whatsappHref}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <MessageCircle className="size-4" />
                                Contact support on WhatsApp
                            </a>
                        </Button>
                    )
                ) : (
                    <div className="space-y-6">
                        {rejected && currentSubscription && (
                            <Alert variant="destructive">
                                <AlertTitle>
                                    Your last payment request was rejected
                                </AlertTitle>
                                <AlertDescription>
                                    {currentSubscription.rejectionReason ??
                                        'We could not match your transfer.'}{' '}
                                    You can submit a new request below
                                    {whatsappHref ? (
                                        <>
                                            {' '}
                                            or{' '}
                                            <a
                                                href={whatsappHref}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="font-medium underline underline-offset-2"
                                            >
                                                reach us on WhatsApp
                                            </a>
                                        </>
                                    ) : null}
                                    .
                                </AlertDescription>
                            </Alert>
                        )}

                        {plan && (
                            <Card>
                                <CardContent className="flex items-baseline justify-between gap-3">
                                    <div>
                                        <div className="font-medium">
                                            {plan.name}
                                        </div>
                                        <div className="text-sm text-muted-foreground">
                                            Full access · all features ·{' '}
                                            {plan.duration_days >= 365
                                                ? '1 year'
                                                : `${plan.duration_days} days`}
                                        </div>
                                    </div>
                                    <div className="text-right">
                                        <span className="text-2xl font-semibold tracking-tight tabular-nums">
                                            {Number(
                                                plan.price,
                                            ).toLocaleString()}
                                        </span>{' '}
                                        <span className="text-sm text-muted-foreground">
                                            {plan.currency}
                                            {plan.duration_days >= 365
                                                ? '/year'
                                                : ''}
                                        </span>
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Landmark className="size-5 text-muted-foreground" />
                                    1. Send the transfer
                                </CardTitle>
                                <CardDescription>
                                    Put{' '}
                                    <span className="font-mono font-medium text-foreground">
                                        {nextReferenceCode}
                                    </span>{' '}
                                    in the transfer note so we can match your
                                    payment fast. Cash? Contact us on WhatsApp.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="divide-y">
                                {bank.account_holder && (
                                    <BankRow
                                        label="Account holder"
                                        value={bank.account_holder}
                                    />
                                )}
                                {bank.bank_name && (
                                    <BankRow
                                        label="Bank"
                                        value={bank.bank_name}
                                    />
                                )}
                                {bank.rib && (
                                    <BankRow
                                        label="RIB"
                                        value={bank.rib}
                                        mono
                                        copyable
                                    />
                                )}
                                <BankRow
                                    label="Reference"
                                    value={nextReferenceCode}
                                    mono
                                    copyable
                                />
                            </CardContent>
                        </Card>

                        <Form
                            {...SubscriptionController.store.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing, errors }) => (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2">
                                            <FileText className="size-5 text-muted-foreground" />
                                            2. Confirm your payment
                                        </CardTitle>
                                        <CardDescription>
                                            We review it and activate your
                                            account — usually within one
                                            business day.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-5">
                                        <input
                                            type="hidden"
                                            name="plan_id"
                                            value={plan?.id ?? ''}
                                        />
                                        <FieldError
                                            errors={[
                                                { message: errors.plan_id },
                                            ]}
                                        />

                                        <Field>
                                            <FieldLabel>
                                                How did you pay?
                                            </FieldLabel>
                                            <RadioGroup
                                                name="payment_method"
                                                defaultValue="bank_transfer"
                                                className="flex gap-6"
                                            >
                                                <FieldLabel className="flex items-center gap-2 font-normal">
                                                    <RadioGroupItem value="bank_transfer" />
                                                    Bank transfer
                                                </FieldLabel>
                                                <FieldLabel className="flex items-center gap-2 font-normal">
                                                    <RadioGroupItem value="cash" />
                                                    Cash
                                                </FieldLabel>
                                            </RadioGroup>
                                            <FieldError
                                                errors={[
                                                    {
                                                        message:
                                                            errors.payment_method,
                                                    },
                                                ]}
                                            />
                                        </Field>

                                        <Field>
                                            <FieldLabel htmlFor="payment_reference">
                                                Transfer reference
                                                <span className="ml-1 font-normal text-muted-foreground">
                                                    (optional)
                                                </span>
                                            </FieldLabel>
                                            <Input
                                                id="payment_reference"
                                                name="payment_reference"
                                                placeholder="e.g. the reference on your bank receipt"
                                            />
                                            <FieldError
                                                errors={[
                                                    {
                                                        message:
                                                            errors.payment_reference,
                                                    },
                                                ]}
                                            />
                                        </Field>

                                        <Field>
                                            <FieldLabel htmlFor="receipt">
                                                Receipt
                                                <span className="ml-1 font-normal text-muted-foreground">
                                                    (optional — PDF or photo)
                                                </span>
                                            </FieldLabel>
                                            <input
                                                ref={receiptInputRef}
                                                id="receipt"
                                                name="receipt"
                                                type="file"
                                                accept=".pdf,.jpg,.jpeg,.png,.webp"
                                                className="sr-only"
                                                onChange={(event) =>
                                                    setReceiptName(
                                                        event.target.files?.[0]
                                                            ?.name ?? null,
                                                    )
                                                }
                                            />
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="w-full justify-start font-normal text-muted-foreground"
                                                onClick={() =>
                                                    receiptInputRef.current?.click()
                                                }
                                            >
                                                <Upload className="size-4" />
                                                {receiptName ??
                                                    'Attach your transfer receipt'}
                                            </Button>
                                            <FieldDescription>
                                                A screenshot of the transfer
                                                works too.
                                            </FieldDescription>
                                            <FieldError
                                                errors={[
                                                    {
                                                        message: errors.receipt,
                                                    },
                                                ]}
                                            />
                                        </Field>

                                        <Button
                                            type="submit"
                                            className="w-full"
                                            disabled={processing || !plan}
                                        >
                                            {processing
                                                ? 'Submitting…'
                                                : 'I have paid — confirm my payment'}
                                        </Button>

                                        {whatsappHref && (
                                            <p className="text-center text-sm text-muted-foreground">
                                                Questions?{' '}
                                                <a
                                                    href={whatsappHref}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="font-medium text-foreground underline underline-offset-2"
                                                >
                                                    WhatsApp us
                                                </a>
                                            </p>
                                        )}
                                    </CardContent>
                                </Card>
                            )}
                        </Form>
                    </div>
                )}

                <p className="mt-8 text-center text-xs text-muted-foreground">
                    Signed in as {auth.user?.email}
                </p>
            </main>
        </div>
    );
}
