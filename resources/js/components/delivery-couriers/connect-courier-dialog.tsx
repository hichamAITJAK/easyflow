import { Form } from '@inertiajs/react';
import { Check, ChevronsUpDown, Copy, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import DeliveryCourrierConnectionController from '@/actions/App/Http/Controllers/DeliveryCouriers/DeliveryCourrierConnectionController';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
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
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';
import type { CitiesByCourier, DeliveryCourrier } from '@/types';

export function ConnectCourierDialog({
    open,
    onOpenChange,
    courier,
    citiesByCourier,
    businessSlug,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    courier: DeliveryCourrier | null;
    citiesByCourier: CitiesByCourier;
    businessSlug: string;
}) {
    const [collectCityId, setCollectCityId] = useState<string | null>(null);
    const [cityPopoverOpen, setCityPopoverOpen] = useState(false);
    const [copiedText, copy] = useClipboard();

    if (!courier) {
        return null;
    }

    const cities = citiesByCourier[courier.id] ?? [];
    const cityOptions = cities.map((city) => ({
        value: String(city.id),
        label: city.name,
    }));

    const senditWebhookUrl = `${window.location.origin}/webhooks/sendit/${businessSlug}`;

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    setCollectCityId(null);
                }

                onOpenChange(next);
            }}
        >
            <DialogContent>
                <Form
                    {...DeliveryCourrierConnectionController.store.form()}
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ processing, errors }) => (
                        <div className="space-y-6">
                            <DialogHeader>
                                <DialogTitle>
                                    Connect {courier.name}
                                </DialogTitle>
                                <DialogDescription>
                                    Enter your {courier.name} credentials —
                                    you'll find them in your {courier.name}{' '}
                                    dashboard. We'll test the connection before
                                    saving them.
                                </DialogDescription>
                            </DialogHeader>

                            <input
                                type="hidden"
                                name="courier_id"
                                value={courier.id}
                                readOnly
                            />
                            <input
                                type="hidden"
                                name="collect_city_id"
                                value={collectCityId ?? ''}
                                readOnly
                            />

                            <FieldGroup>
                                <Field>
                                    <FieldLabel htmlFor="label">
                                        Account label
                                    </FieldLabel>
                                    <Input
                                        id="label"
                                        name="label"
                                        required
                                        autoFocus
                                        autoComplete="off"
                                        placeholder="e.g. Casablanca warehouse"
                                    />
                                    <FieldDescription>
                                        Helps you tell this account apart if you
                                        connect more than one {courier.name}{' '}
                                        account.
                                    </FieldDescription>
                                    <FieldError>{errors.label}</FieldError>
                                </Field>

                                <Field>
                                    <FieldLabel htmlFor="collect_city_id">
                                        Collect city
                                    </FieldLabel>
                                    <Popover
                                        open={cityPopoverOpen}
                                        onOpenChange={setCityPopoverOpen}
                                    >
                                        <PopoverTrigger asChild>
                                            <Button
                                                id="collect_city_id"
                                                type="button"
                                                variant="outline"
                                                role="combobox"
                                                aria-expanded={cityPopoverOpen}
                                                className="w-full justify-between font-normal"
                                            >
                                                {collectCityId
                                                    ? cityOptions.find(
                                                          (option) =>
                                                              option.value ===
                                                              collectCityId,
                                                      )?.label
                                                    : 'Select the city you ship from'}
                                                <ChevronsUpDown className="opacity-50" />
                                            </Button>
                                        </PopoverTrigger>
                                        <PopoverContent className="w-(--radix-popover-trigger-width) p-0">
                                            <Command>
                                                <CommandInput placeholder="Search cities…" />
                                                <CommandList>
                                                    <CommandEmpty>
                                                        No cities found.
                                                    </CommandEmpty>
                                                    <CommandGroup>
                                                        {cityOptions.map(
                                                            (option) => (
                                                                <CommandItem
                                                                    key={
                                                                        option.value
                                                                    }
                                                                    value={
                                                                        option.label
                                                                    }
                                                                    onSelect={() => {
                                                                        setCollectCityId(
                                                                            option.value,
                                                                        );
                                                                        setCityPopoverOpen(
                                                                            false,
                                                                        );
                                                                    }}
                                                                >
                                                                    <Check
                                                                        className={cn(
                                                                            'mr-2',
                                                                            collectCityId ===
                                                                                option.value
                                                                                ? 'opacity-100'
                                                                                : 'opacity-0',
                                                                        )}
                                                                    />
                                                                    {
                                                                        option.label
                                                                    }
                                                                </CommandItem>
                                                            ),
                                                        )}
                                                    </CommandGroup>
                                                </CommandList>
                                            </Command>
                                        </PopoverContent>
                                    </Popover>
                                    <FieldDescription>
                                        Orders shipped from this city will use
                                        this {courier.name} account.
                                    </FieldDescription>
                                    <FieldError>
                                        {errors.collect_city_id}
                                    </FieldError>
                                </Field>

                                {courier.slug === 'Sendit' && (
                                    <>
                                        <Field>
                                            <FieldLabel htmlFor="public_key">
                                                Sendit public key
                                            </FieldLabel>
                                            <Input
                                                id="public_key"
                                                name="public_key"
                                                required
                                                autoComplete="off"
                                                placeholder="Paste your Sendit public key"
                                            />
                                            <FieldError>
                                                {errors.public_key}
                                            </FieldError>
                                        </Field>
                                        <Field>
                                            <FieldLabel htmlFor="secret_key">
                                                Sendit secret key
                                            </FieldLabel>
                                            <Input
                                                id="secret_key"
                                                name="secret_key"
                                                type="password"
                                                required
                                                autoComplete="off"
                                                placeholder="Paste your Sendit secret key"
                                            />
                                            <FieldError>
                                                {errors.secret_key}
                                            </FieldError>
                                        </Field>
                                        <Field>
                                            <FieldLabel htmlFor="sendit_webhook_url">
                                                Webhook URL
                                            </FieldLabel>
                                            <div className="flex gap-2">
                                                <Input
                                                    id="sendit_webhook_url"
                                                    value={senditWebhookUrl}
                                                    readOnly
                                                    onFocus={(event) =>
                                                        event.currentTarget.select()
                                                    }
                                                    className="font-mono text-xs"
                                                />
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="icon"
                                                    className="shrink-0"
                                                    onClick={() =>
                                                        copy(senditWebhookUrl)
                                                    }
                                                >
                                                    {copiedText ===
                                                    senditWebhookUrl ? (
                                                        <Check />
                                                    ) : (
                                                        <Copy />
                                                    )}
                                                </Button>
                                            </div>
                                            <FieldDescription>
                                                Paste this URL into Sendit's
                                                webhook settings, and use the
                                                secret key above as the webhook
                                                key.
                                            </FieldDescription>
                                        </Field>
                                    </>
                                )}

                                {courier.slug === 'OzonExpress' && (
                                    <>
                                        <Field>
                                            <FieldLabel htmlFor="ozon_id">
                                                OzonExpress customer ID
                                            </FieldLabel>
                                            <Input
                                                id="ozon_id"
                                                name="ozon_id"
                                                required
                                                autoComplete="off"
                                            />
                                            <FieldError>
                                                {errors.ozon_id}
                                            </FieldError>
                                        </Field>
                                        <Field>
                                            <FieldLabel htmlFor="api_key">
                                                OzonExpress API key
                                            </FieldLabel>
                                            <Input
                                                id="api_key"
                                                name="api_key"
                                                type="password"
                                                required
                                                autoComplete="off"
                                            />
                                            <FieldError>
                                                {errors.api_key}
                                            </FieldError>
                                        </Field>
                                    </>
                                )}

                                {courier.slug === 'Coliix' && (
                                    <>
                                        <Field>
                                            <FieldLabel htmlFor="client_id">
                                                Coliix client ID
                                            </FieldLabel>
                                            <Input
                                                id="client_id"
                                                name="client_id"
                                                required
                                                autoComplete="off"
                                            />
                                            <FieldError>
                                                {errors.client_id}
                                            </FieldError>
                                        </Field>
                                        <Field>
                                            <FieldLabel htmlFor="api_key">
                                                Coliix API key
                                            </FieldLabel>
                                            <Input
                                                id="api_key"
                                                name="api_key"
                                                type="password"
                                                required
                                                autoComplete="off"
                                            />
                                            <FieldError>
                                                {errors.api_key}
                                            </FieldError>
                                        </Field>
                                    </>
                                )}

                                {courier.slug === 'FORCELOG' && (
                                    <Field>
                                        <FieldLabel htmlFor="api_key">
                                            ForceLog API key
                                        </FieldLabel>
                                        <Input
                                            id="api_key"
                                            name="api_key"
                                            type="password"
                                            required
                                            autoComplete="off"
                                        />
                                        <FieldDescription>
                                            In ForceLog, open Paramètres → Mon
                                            compte and copy your API key (or
                                            generate a new one).
                                        </FieldDescription>
                                        <FieldError>
                                            {errors.api_key}
                                        </FieldError>
                                    </Field>
                                )}

                                {courier.slug === 'Ameex' && (
                                    <>
                                        <Field>
                                            <FieldLabel htmlFor="api_id">
                                                Ameex API ID
                                            </FieldLabel>
                                            <Input
                                                id="api_id"
                                                name="api_id"
                                                required
                                                autoComplete="off"
                                            />
                                            <FieldDescription>
                                                Sent as the C-Api-Id header, and
                                                used as the sender (expéditeur)
                                                on each parcel.
                                            </FieldDescription>
                                            <FieldError>
                                                {errors.api_id}
                                            </FieldError>
                                        </Field>
                                        <Field>
                                            <FieldLabel htmlFor="api_key">
                                                Ameex API key
                                            </FieldLabel>
                                            <Input
                                                id="api_key"
                                                name="api_key"
                                                type="password"
                                                required
                                                autoComplete="off"
                                            />
                                            <FieldError>
                                                {errors.api_key}
                                            </FieldError>
                                        </Field>
                                        <Alert variant="warning">
                                            <TriangleAlert />
                                            <AlertTitle>
                                                No delivery costs
                                            </AlertTitle>
                                            <AlertDescription>
                                                Ameex doesn't return shipment
                                                costs, so orders shipped with
                                                this courier will have no
                                                delivery cost recorded.
                                            </AlertDescription>
                                        </Alert>
                                    </>
                                )}
                            </FieldGroup>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => onOpenChange(false)}
                                    disabled={processing}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {processing
                                        ? 'Connecting…'
                                        : 'Test & connect'}
                                </Button>
                            </DialogFooter>
                        </div>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
