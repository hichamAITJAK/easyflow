import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Check, Copy, ExternalLink } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';
import { create as storesCreate, index as storesIndex } from '@/routes/stores';

type Props = {
    pending: {
        id: number;
        shop: string;
        domain: string;
        client_id: string;
    };
    authorizeUrl: string;
    redirectUri: string;
    scopes: string;
};

/** A value the merchant has to paste into Shopify, with a one-click copy. */
function CopyField({
    label,
    value,
    hint,
}: {
    label: string;
    value: string;
    hint: string;
}) {
    const { t } = useTranslation();
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1600);
        } catch {
            // Clipboard blocked: the value is still selectable below.
        }
    };

    return (
        <div className="grid gap-1.5">
            <p className="text-sm font-medium">{label}</p>
            <div className="flex items-stretch gap-2">
                <code className="flex min-w-0 flex-1 items-center overflow-x-auto rounded-md border border-border bg-muted/50 px-3 py-2 font-mono text-xs select-all">
                    {value}
                </code>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="shrink-0"
                    onClick={copy}
                    aria-label={t('Copy')}
                >
                    {copied ? <Check className="text-success" /> : <Copy />}
                    {copied ? t('Copied') : t('Copy')}
                </Button>
            </div>
            <p className="text-xs text-muted-foreground">{hint}</p>
        </div>
    );
}

export default function ShopifyFinish({
    pending,
    authorizeUrl,
    redirectUri,
    scopes,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head
                title={t('Finish connecting :shop', { shop: pending.domain })}
            />

            <div className="mx-auto max-w-3xl space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <Heading
                        className="mb-0"
                        title={t('Finish connecting :shop', {
                            shop: pending.domain,
                        })}
                        description={t(
                            'Your app keys are saved. Finish in Shopify, either way below.',
                        )}
                    />
                    <Button variant="ghost" asChild>
                        <Link href={storesCreate()}>
                            <ArrowLeft />
                            {t('Change the keys')}
                        </Link>
                    </Button>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>
                            {t('Before that, check your app settings')}
                        </CardTitle>
                        <CardDescription>
                            {t('Client ID in use')}:{' '}
                            <span className="font-mono">
                                {pending.client_id}
                            </span>
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <CopyField
                            label={t('Redirect URL')}
                            value={redirectUri}
                            hint={t(
                                'Add this exact URL under your app → Settings → URLs → Allowed redirection URLs. Without it Shopify refuses the install link.',
                            )}
                        />
                        <CopyField
                            label={t('Access scopes')}
                            value={scopes}
                            hint={t(
                                'Your app must request at least these scopes.',
                            )}
                        />
                    </CardContent>
                </Card>

                <div className="grid gap-4 md:grid-cols-2">
                    <Card className="flex flex-col">
                        <CardHeader>
                            <CardTitle>{t('Continue to Shopify')}</CardTitle>
                            <CardDescription>
                                {t(
                                    'Shopify will ask you to approve the app for :domain, then bring you back here.',
                                    { domain: pending.domain },
                                )}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="mt-auto">
                            <Button asChild className="w-full">
                                <a href={authorizeUrl}>
                                    <ExternalLink />
                                    {t('Continue to Shopify')}
                                </a>
                            </Button>
                        </CardContent>
                    </Card>

                    <Card className="flex flex-col">
                        <CardHeader>
                            <CardTitle>
                                {t('Or use the install link')}
                            </CardTitle>
                            <CardDescription>
                                {t(
                                    'In the Dev Dashboard, open your app → Distribution → Custom distribution, copy the install link and open it while logged into your store. Shopify brings you back here automatically.',
                                )}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="mt-auto">
                            <Button
                                variant="outline"
                                asChild
                                className="w-full"
                            >
                                <a
                                    href="https://dev.shopify.com/dashboard"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <ExternalLink />
                                    Shopify Dev Dashboard
                                </a>
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

ShopifyFinish.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Stores', href: storesIndex() },
        { title: 'Connect store', href: storesCreate() },
    ],
};
