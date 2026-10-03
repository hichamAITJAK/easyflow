import { Form, Link } from '@inertiajs/react';
import { Check, ChevronsUpDown, Truck } from 'lucide-react';
import { useEffect, useState } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import InputError from '@/components/input-error';
import { AddressAutocomplete } from '@/components/ui/address-autocomplete';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { create as createDeliveryCourier } from '@/routes/delivery-couriers';
import orderDeliveryAccounts from '@/routes/orders/delivery-accounts';
import type { Order } from '@/types';
import type { DeliveryAccount } from '@/types/delivery';

type CityOption = { value: string; label: string };

export function CreateShipmentDialog({
    open,
    onOpenChange,
    order,
    deliveryAccounts,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    order: Order | null;
    deliveryAccounts: DeliveryAccount[];
}) {
    const { t } = useTranslation();

    const [accountId, setAccountId] = useState<string>('');
    const [cityId, setCityId] = useState<string | null>(null);
    const [cityOptions, setCityOptions] = useState<CityOption[]>([]);
    const [loadingCities, setLoadingCities] = useState(false);
    const [cityPopoverOpen, setCityPopoverOpen] = useState(false);
    const [address, setAddress] = useState('');
    const [parcelOpen, setParcelOpen] = useState(false);
    const [parcelFragile, setParcelFragile] = useState(false);
    const [parcelReplace, setParcelReplace] = useState(false);

    // Reset the dependent city field whenever the courier account changes —
    // a previously chosen city almost certainly doesn't exist for a
    // different courier's city list.
    useEffect(() => {
        setCityId(null);
        setCityOptions([]);

        if (!accountId) {
            return;
        }

        setLoadingCities(true);

        fetch(orderDeliveryAccounts.cities(Number(accountId)).url, {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.json())
            .then((data: { cities: { id: number; name: string }[] }) => {
                setCityOptions(
                    data.cities.map((city) => ({
                        value: String(city.id),
                        label: city.name,
                    })),
                );
            })
            .finally(() => setLoadingCities(false));
    }, [accountId]);

    useEffect(() => {
        if (open) {
            setAccountId('');
            setCityId(null);
            setCityOptions([]);
            setAddress(order?.customer_address ?? '');
            setParcelOpen(false);
            setParcelFragile(false);
            setParcelReplace(false);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    if (!order) {
        return null;
    }

    if (deliveryAccounts.length === 0) {
        return (
            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('Create shipment')}</DialogTitle>
                        <DialogDescription>
                            {t('Register this order as a parcel with a delivery courier.')}
                        </DialogDescription>
                    </DialogHeader>

                    <Empty className="border">
                        <EmptyHeader>
                            <EmptyMedia variant="icon">
                                <Truck />
                            </EmptyMedia>
                            <EmptyTitle>{t('No courier connected')}</EmptyTitle>
                            <EmptyDescription>
                                {t('Connect a delivery courier account before you can create shipments.')}
                            </EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <Button asChild>
                                <Link href={createDeliveryCourier()}>
                                    {t('Connect a courier')}
                                </Link>
                            </Button>
                        </EmptyContent>
                    </Empty>
                </DialogContent>
            </Dialog>
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('Create shipment')}</DialogTitle>
                    <DialogDescription>
                        {t('Review the customer details and choose a courier to register this parcel.')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...OrderController.createShipment.form(order.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="customer_name">
                                    {t('Customer name')}
                                </Label>
                                <Input
                                    id="customer_name"
                                    name="customer_name"
                                    defaultValue={order.customer_name}
                                    required
                                />
                                <InputError message={errors.customer_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="customer_phone">{t('Phone')}</Label>
                                <Input
                                    id="customer_phone"
                                    name="customer_phone"
                                    defaultValue={order.customer_phone}
                                    required
                                />
                                <InputError message={errors.customer_phone} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="customer_address">
                                    {t('Address')}
                                </Label>
                                <AddressAutocomplete
                                    id="customer_address"
                                    name="customer_address"
                                    value={address}
                                    onChange={setAddress}
                                    onResolved={(resolved) => {
                                        if (!resolved.city || !accountId) {
                                            return;
                                        }

                                        const match = cityOptions.find(
                                            (option) =>
                                                option.label.localeCompare(
                                                    resolved.city!,
                                                    undefined,
                                                    {
                                                        sensitivity: 'base',
                                                    },
                                                ) === 0,
                                        );

                                        if (match) {
                                            setCityId(match.value);
                                        }
                                    }}
                                    required
                                />
                                <InputError message={errors.customer_address} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="total_amount">
                                    {t('Total amount')}
                                </Label>
                                <Input
                                    id="total_amount"
                                    name="total_amount"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    defaultValue={order.total_amount}
                                    required
                                />
                                <InputError message={errors.total_amount} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="delivery_account_id">
                                    {t('Delivery courier')}
                                </Label>
                                <Select
                                    value={accountId}
                                    onValueChange={setAccountId}
                                >
                                    <SelectTrigger
                                        id="delivery_account_id"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder={t('Choose a courier')} />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {deliveryAccounts.map((account) => (
                                            <SelectItem
                                                key={account.id}
                                                value={String(account.id)}
                                            >
                                                {account.courier?.name
                                                    ? `${account.courier.name} — ${account.label}`
                                                    : account.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <input
                                    type="hidden"
                                    name="delivery_account_id"
                                    value={accountId}
                                />
                                <InputError
                                    message={errors.delivery_account_id}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="city_id">{t('City')}</Label>
                                <Popover
                                    open={cityPopoverOpen}
                                    onOpenChange={setCityPopoverOpen}
                                >
                                    <PopoverTrigger asChild>
                                        <Button
                                            id="city_id"
                                            type="button"
                                            variant="outline"
                                            role="combobox"
                                            aria-expanded={cityPopoverOpen}
                                            disabled={
                                                !accountId || loadingCities
                                            }
                                            className="w-full justify-between font-normal"
                                        >
                                            {cityId
                                                ? cityOptions.find(
                                                      (option) =>
                                                          option.value ===
                                                          cityId,
                                                  )?.label
                                                : !accountId
                                                  ? t('Choose a courier first')
                                                  : loadingCities
                                                    ? t('Loading cities…')
                                                    : t('Select a city')}
                                            <ChevronsUpDown className="opacity-50" />
                                        </Button>
                                    </PopoverTrigger>
                                    <PopoverContent className="w-(--radix-popover-trigger-width) p-0">
                                        <Command>
                                            <CommandInput placeholder={t('Search cities…')} />
                                            <CommandList>
                                                <CommandEmpty>
                                                    {t('No cities found.')}
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
                                                                    setCityId(
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
                                                                        cityId ===
                                                                            option.value
                                                                            ? 'opacity-100'
                                                                            : 'opacity-0',
                                                                    )}
                                                                />
                                                                {option.label}
                                                            </CommandItem>
                                                        ),
                                                    )}
                                                </CommandGroup>
                                            </CommandList>
                                        </Command>
                                    </PopoverContent>
                                </Popover>
                                <input
                                    type="hidden"
                                    name="city_id"
                                    value={cityId ?? ''}
                                />
                                <InputError message={errors.city_id} />
                            </div>

                            <div className="space-y-4 rounded-lg border p-4">
                                <p className="text-sm font-medium">
                                    {t('Parcel options')}
                                </p>

                                <div className="grid gap-2">
                                    <Label htmlFor="parcel_nature">
                                        {t('Nature')}
                                    </Label>
                                    <Input
                                        id="parcel_nature"
                                        name="parcel_nature"
                                        placeholder={t('e.g. Clothing')}
                                    />
                                    <InputError
                                        message={errors.parcel_nature}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="parcel_note">
                                        {t('Note for the courier')}
                                    </Label>
                                    <Input id="parcel_note" name="parcel_note" />
                                    <InputError message={errors.parcel_note} />
                                </div>

                                <div className="flex flex-wrap gap-6">
                                    <label className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={parcelOpen}
                                            onCheckedChange={(value) =>
                                                setParcelOpen(value === true)
                                            }
                                        />
                                        {t('Allow opening')}
                                    </label>
                                    <label className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={parcelFragile}
                                            onCheckedChange={(value) =>
                                                setParcelFragile(
                                                    value === true,
                                                )
                                            }
                                        />
                                        {t('Fragile')}
                                    </label>
                                    <label className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={parcelReplace}
                                            onCheckedChange={(value) =>
                                                setParcelReplace(
                                                    value === true,
                                                )
                                            }
                                        />
                                        {t('Exchange')}
                                    </label>
                                </div>
                                <input
                                    type="hidden"
                                    name="parcel_open"
                                    value={parcelOpen ? '1' : '0'}
                                />
                                <input
                                    type="hidden"
                                    name="parcel_fragile"
                                    value={parcelFragile ? '1' : '0'}
                                />
                                <input
                                    type="hidden"
                                    name="parcel_replace"
                                    value={parcelReplace ? '1' : '0'}
                                />
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => onOpenChange(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    disabled={
                                        processing || !accountId || !cityId
                                    }
                                >
                                    Create shipment
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
