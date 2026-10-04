import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, Store as StoreIcon } from 'lucide-react';
import { useRef, useState } from 'react';
import StoreConnectionController from '@/actions/App/Http/Controllers/Stores/StoreConnectionController';
import StoreepConnectionController from '@/actions/App/Http/Controllers/Stores/StoreepConnectionController';
import WooCommerceConnectionController from '@/actions/App/Http/Controllers/Stores/WooCommerceConnectionController';
import { ConnectTutorialDialog } from '@/components/connect-tutorial-dialog';
import Heading from '@/components/heading';
import { ProviderConnectCard } from '@/components/provider-connect-card';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';
import { index as storesIndex } from '@/routes/stores';
import type { EcommercePlatform } from '@/types';

const CONNECTABLE_SLUGS = [
    'Shopify',
    'YouCan',
    'LightFunnels',
    'Storeep',
    'WooCommerce',
];
const PLATFORMS_REQUIRING_INPUT = ['Shopify', 'Storeep', 'WooCommerce'];

// Platforms with no OAuth flow: the merchant pastes credentials, which are
// POSTed straight to our own endpoint instead of redirecting out to the
// platform. That makes them Inertia form submissions (with server-side
// validation errors rendered inline) rather than the plain GET form the
// redirect-based platforms use.
const PASTED_CREDENTIAL_SLUGS = ['Storeep', 'WooCommerce'];

export default function StoresCreate({
    platforms,
    reconnecting = null,
}: {
    platforms: EcommercePlatform[];
    /** Set when this page was opened to repair an existing store. */
    reconnecting?: {
        id: number;
        name: string;
        platform_slug: string | null;
    } | null;
}) {
    const { t } = useTranslation();

    // Opening straight onto the right platform's credential form: the
    // merchant already said which store they are fixing, so making them pick
    // its platform out of the grid again would be asking twice.
    const reconnectPlatform =
        reconnecting === null
            ? null
            : (platforms.find(
                  (platform) => platform.slug === reconnecting.platform_slug,
              ) ?? null);
    const [selected, setSelected] = useState<EcommercePlatform | null>(
        reconnectPlatform,
    );
    const [dialogOpen, setDialogOpen] = useState(reconnectPlatform !== null);
    const [shop, setShop] = useState('');
    const [connecting, setConnecting] = useState(false);
    const [tutorialPlatform, setTutorialPlatform] =
        useState<EcommercePlatform | null>(null);
    const formRef = useRef<HTMLFormElement>(null);

    const requiresInput = (platform: EcommercePlatform) =>
        PLATFORMS_REQUIRING_INPUT.includes(platform.slug);

    const isShopify = selected?.slug === 'Shopify';
    const isStoreep = selected?.slug === 'Storeep';
    const isWooCommerce = selected?.slug === 'WooCommerce';

    const pastesCredentials = selected
        ? PASTED_CREDENTIAL_SLUGS.includes(selected.slug)
        : false;

    const canSubmit = !isShopify || shop.trim() !== '';

    // Split rather than sorted: a merchant scanning for their platform needs
    // to know which of these they can actually connect today. A sorted-but-
    // merged list hides that line, and the "Coming soon" badge only reads
    // once you're already looking at the card.
    const connectablePlatforms = platforms.filter((platform) =>
        CONNECTABLE_SLUGS.includes(platform.slug),
    );
    const upcomingPlatforms = platforms.filter(
        (platform) => !CONNECTABLE_SLUGS.includes(platform.slug),
    );

    const handleConnect = (platform: EcommercePlatform) => {
        setSelected(platform);

        if (requiresInput(platform)) {
            setShop('');
            setDialogOpen(true);

            return;
        }

        setConnecting(true);
        setTimeout(() => formRef.current?.submit(), 0);
    };

    const handleDialogSubmit = () => {
        setConnecting(true);
        formRef.current?.submit();
    };

    return (
        <>
            <Head title={t('Add store')} />

            <div className="space-y-8 p-4">
                <Link
                    href={storesIndex()}
                    className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    {t('Go back')}
                </Link>

                <Heading
                    title={t('Add a store')}
                    description={t(
                        'Choose the e-commerce platform your store runs on to connect it.',
                    )}
                />

                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {connectablePlatforms.map((platform) => (
                        <ProviderConnectCard
                            key={platform.id}
                            name={platform.name}
                            description={platform.description}
                            logoUrl={platform.logo_url}
                            fallbackIcon={
                                <StoreIcon className="size-5 text-muted-foreground" />
                            }
                            disabled={connecting}
                            onConnect={() => handleConnect(platform)}
                            onViewTutorial={() => setTutorialPlatform(platform)}
                        />
                    ))}
                </div>

                {upcomingPlatforms.length > 0 && (
                    <section className="space-y-4">
                        <div className="flex items-center gap-3">
                            <h2 className="text-sm font-medium text-muted-foreground">
                                {t('Not available yet')}
                            </h2>
                            <Separator className="flex-1" />
                        </div>

                        <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            {upcomingPlatforms.map((platform) => (
                                <ProviderConnectCard
                                    key={platform.id}
                                    name={platform.name}
                                    description={platform.description}
                                    logoUrl={platform.logo_url}
                                    fallbackIcon={
                                        <StoreIcon className="size-5 text-muted-foreground" />
                                    }
                                    disabled
                                    connectLabel="Coming soon"
                                    onConnect={() => handleConnect(platform)}
                                    onViewTutorial={() =>
                                        setTutorialPlatform(platform)
                                    }
                                />
                            ))}
                        </div>
                    </section>
                )}

                {!pastesCredentials && (
                    <form
                        ref={formRef}
                        method="get"
                        action={
                            selected
                                ? StoreConnectionController.redirect.url({
                                      platform: selected.slug,
                                  })
                                : '#'
                        }
                    >
                        {isShopify && (
                            <input type="hidden" name="shop" value={shop} />
                        )}
                    </form>
                )}
            </div>

            <Dialog
                open={dialogOpen}
                onOpenChange={(open) => {
                    if (!connecting) {
                        setDialogOpen(open);
                    }
                }}
            >
                <DialogContent>
                    {selected && isStoreep && (
                        <Form
                            {...StoreepConnectionController.store.form()}
                            onSuccess={() => setDialogOpen(false)}
                            onError={() => setConnecting(false)}
                            onSubmit={() => setConnecting(true)}
                            className="space-y-6"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <DialogHeader>
                                        <DialogTitle>
                                            {t('Connect :name', {
                                                name: selected.name,
                                            })}
                                        </DialogTitle>
                                        <DialogDescription>
                                            {t(
                                                'Paste an access token from your Storeep dashboard to connect this store.',
                                            )}
                                        </DialogDescription>
                                    </DialogHeader>

                                    <FieldGroup>
                                        <Field>
                                            <FieldLabel htmlFor="storeep-name">
                                                {t('Store name')}
                                            </FieldLabel>
                                            <Input
                                                id="storeep-name"
                                                name="name"
                                                required
                                                autoFocus
                                                placeholder={t(
                                                    'My Storeep shop',
                                                )}
                                                aria-invalid={!!errors.name}
                                            />
                                            <FieldDescription>
                                                {t(
                                                    'How this store will appear in EasyFlow.',
                                                )}
                                            </FieldDescription>
                                            {errors.name && (
                                                <FieldError>
                                                    {errors.name}
                                                </FieldError>
                                            )}
                                        </Field>

                                        <Field>
                                            <FieldLabel htmlFor="storeep-token">
                                                {t('Access token')}
                                            </FieldLabel>
                                            <Input
                                                id="storeep-token"
                                                name="access_token"
                                                required
                                                autoComplete="off"
                                                spellCheck={false}
                                                placeholder="550e8400-e29b-41d4-a716-446655440000"
                                                aria-invalid={
                                                    !!errors.access_token
                                                }
                                            />
                                            <FieldDescription>
                                                In Storeep, go to Settings →
                                                Access tokens → Create new
                                                token, and enable at least the{' '}
                                                <code>
                                                    {t('products:read')}
                                                </code>{' '}
                                                and{' '}
                                                <code>{t('orders:read')}</code>{' '}
                                                permissions. The token is only
                                                shown once.
                                            </FieldDescription>
                                            {errors.access_token && (
                                                <FieldError>
                                                    {errors.access_token}
                                                </FieldError>
                                            )}
                                        </Field>

                                        <Field>
                                            <FieldLabel htmlFor="storeep-market">
                                                Market{' '}
                                                <span className="text-muted-foreground">
                                                    {t('(optional)')}
                                                </span>
                                            </FieldLabel>
                                            <Input
                                                id="storeep-market"
                                                name="market"
                                                maxLength={2}
                                                placeholder="MA"
                                                className="uppercase"
                                                aria-invalid={!!errors.market}
                                            />
                                            <FieldDescription>
                                                {t(
                                                    'Two-letter country code. If your catalog is priced in several markets, this picks which prices to import.',
                                                )}
                                            </FieldDescription>
                                            {errors.market && (
                                                <FieldError>
                                                    {errors.market}
                                                </FieldError>
                                            )}
                                        </Field>
                                    </FieldGroup>

                                    <DialogFooter className="flex-col items-stretch gap-2 sm:flex-col">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {processing
                                                ? 'Connecting…'
                                                : t('Connect store')}
                                        </Button>
                                        {processing && (
                                            <p className="text-center text-xs text-muted-foreground">
                                                {t(
                                                    'Checking your token with :name…',
                                                    { name: selected.name },
                                                )}
                                            </p>
                                        )}
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    )}

                    {selected && isWooCommerce && (
                        <Form
                            {...WooCommerceConnectionController.store.form()}
                            onSuccess={() => setDialogOpen(false)}
                            onError={() => setConnecting(false)}
                            onSubmit={() => setConnecting(true)}
                            className="space-y-6"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <DialogHeader>
                                        <DialogTitle>
                                            {t('Connect :name', {
                                                name: selected.name,
                                            })}
                                        </DialogTitle>
                                        <DialogDescription>
                                            {t(
                                                'Enter your store address and a WooCommerce API key pair to connect this store.',
                                            )}
                                        </DialogDescription>
                                    </DialogHeader>

                                    <FieldGroup>
                                        <Field>
                                            <FieldLabel htmlFor="woo-name">
                                                {t('Store name')}
                                            </FieldLabel>
                                            <Input
                                                id="woo-name"
                                                name="name"
                                                required
                                                autoFocus
                                                placeholder={t(
                                                    'My WooCommerce shop',
                                                )}
                                                aria-invalid={!!errors.name}
                                            />
                                            <FieldDescription>
                                                {t(
                                                    'How this store will appear in EasyFlow.',
                                                )}
                                            </FieldDescription>
                                            {errors.name && (
                                                <FieldError>
                                                    {errors.name}
                                                </FieldError>
                                            )}
                                        </Field>

                                        <Field>
                                            <FieldLabel htmlFor="woo-url">
                                                {t('Store URL')}
                                            </FieldLabel>
                                            <Input
                                                id="woo-url"
                                                name="store_url"
                                                required
                                                autoComplete="off"
                                                spellCheck={false}
                                                placeholder={t(
                                                    'https://shop.example.com',
                                                )}
                                                aria-invalid={
                                                    !!errors.store_url
                                                }
                                            />
                                            <FieldDescription>
                                                {t(
                                                    "Your store's address, over https. WooCommerce needs pretty permalinks enabled for its API to respond.",
                                                )}
                                            </FieldDescription>
                                            {errors.store_url && (
                                                <FieldError>
                                                    {errors.store_url}
                                                </FieldError>
                                            )}
                                        </Field>

                                        <Field>
                                            <FieldLabel htmlFor="woo-key">
                                                {t('Consumer key')}
                                            </FieldLabel>
                                            <Input
                                                id="woo-key"
                                                name="consumer_key"
                                                required
                                                autoComplete="off"
                                                spellCheck={false}
                                                placeholder="ck_..."
                                                aria-invalid={
                                                    !!errors.consumer_key
                                                }
                                            />
                                            <FieldDescription>
                                                {t(
                                                    'In WordPress, go to WooCommerce → Settings → Advanced → REST API → Add key, and set permissions to',
                                                )}{' '}
                                                <strong>
                                                    {t('Read/Write')}
                                                </strong>
                                                .
                                            </FieldDescription>
                                            {errors.consumer_key && (
                                                <FieldError>
                                                    {errors.consumer_key}
                                                </FieldError>
                                            )}
                                        </Field>

                                        <Field>
                                            <FieldLabel htmlFor="woo-secret">
                                                {t('Consumer secret')}
                                            </FieldLabel>
                                            <Input
                                                id="woo-secret"
                                                name="consumer_secret"
                                                type="password"
                                                required
                                                autoComplete="off"
                                                spellCheck={false}
                                                placeholder="cs_..."
                                                aria-invalid={
                                                    !!errors.consumer_secret
                                                }
                                            />
                                            <FieldDescription>
                                                {t(
                                                    'Shown only once, when the key is created.',
                                                )}
                                            </FieldDescription>
                                            {errors.consumer_secret && (
                                                <FieldError>
                                                    {errors.consumer_secret}
                                                </FieldError>
                                            )}
                                        </Field>
                                    </FieldGroup>

                                    <DialogFooter className="flex-col items-stretch gap-2 sm:flex-col">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {processing
                                                ? 'Connecting…'
                                                : t('Connect store')}
                                        </Button>
                                        {processing && (
                                            <p className="text-center text-xs text-muted-foreground">
                                                {t(
                                                    'Checking your keys with :name…',
                                                    { name: selected.name },
                                                )}
                                            </p>
                                        )}
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    )}

                    {selected && !pastesCredentials && (
                        <div className="space-y-6">
                            <DialogHeader>
                                <DialogTitle>
                                    {t('Connect :name', {
                                        name: selected.name,
                                    })}
                                </DialogTitle>
                                <DialogDescription>
                                    {t(
                                        'We need your store domain to start the connection.',
                                    )}
                                </DialogDescription>
                            </DialogHeader>

                            {isShopify && (
                                <FieldGroup>
                                    <Field>
                                        <FieldLabel htmlFor="shop">
                                            {t('Shopify store domain')}
                                        </FieldLabel>
                                        <Input
                                            id="shop"
                                            name="shop"
                                            required
                                            autoFocus
                                            value={shop}
                                            onChange={(event) =>
                                                setShop(event.target.value)
                                            }
                                            placeholder={t(
                                                'my-store or my-store.myshopify.com',
                                            )}
                                        />
                                    </Field>
                                </FieldGroup>
                            )}

                            <DialogFooter className="flex-col items-stretch gap-2 sm:flex-col">
                                <Button
                                    type="button"
                                    disabled={!canSubmit || connecting}
                                    onClick={handleDialogSubmit}
                                >
                                    {connecting && <Spinner />}
                                    {connecting
                                        ? 'Connecting…'
                                        : t('Connect store')}
                                </Button>
                                {connecting && (
                                    <p className="text-center text-xs text-muted-foreground">
                                        {t(
                                            'Redirecting you to :name to finish connecting…',
                                            { name: selected.name },
                                        )}
                                    </p>
                                )}
                            </DialogFooter>
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            <ConnectTutorialDialog
                open={tutorialPlatform !== null}
                onOpenChange={(open) => !open && setTutorialPlatform(null)}
                name={tutorialPlatform?.name ?? ''}
            />
        </>
    );
}

StoresCreate.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Stores',
            href: '/stores',
        },
        {
            title: 'Add store',
            href: '/stores/create',
        },
    ],
};
