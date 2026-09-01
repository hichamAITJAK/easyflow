import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import DeliveryAccountController from '@/actions/App/Http/Controllers/DeliveryCouriers/DeliveryAccountController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { SelectableCard } from '@/components/selectable-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes';
import { index as storesIndex } from '@/routes/stores';
import type { CitiesByCourier, DeliveryCourrier, Store } from '@/types';

export default function StoresCourier({
    store,
    couriers,
    citiesByCourier,
}: {
    store: Store;
    couriers: DeliveryCourrier[];
    citiesByCourier: CitiesByCourier;
}) {
    const [selectedCourier, setSelectedCourier] =
        useState<DeliveryCourrier | null>(null);

    const cities = selectedCourier
        ? (citiesByCourier[selectedCourier.id] ?? [])
        : [];

    return (
        <>
            <Head title="Connect a delivery courier" />

            <div className="mx-auto max-w-4xl space-y-10 p-4">
                <Heading
                    title="Connect a delivery courier"
                    description="Optional — pick a courier and the city you'll collect parcels from."
                />

                <Form
                    {...DeliveryAccountController.store.form(store.id)}
                    className="space-y-10"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="courier_id"
                                value={selectedCourier?.id ?? ''}
                                readOnly
                            />

                            <div className="space-y-2">
                                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                                    {couriers.map((courier) => (
                                        <SelectableCard
                                            key={courier.id}
                                            title={courier.name}
                                            description={courier.description}
                                            logoUrl={courier.logo}
                                            selected={
                                                selectedCourier?.id ===
                                                courier.id
                                            }
                                            onClick={() =>
                                                setSelectedCourier(courier)
                                            }
                                        />
                                    ))}
                                </div>
                                <InputError message={errors.courier_id} />
                            </div>

                            {selectedCourier && (
                                <div className="mx-auto grid w-full max-w-md gap-6">
                                    <div className="grid gap-2">
                                        <Label htmlFor="label">
                                            Account label
                                        </Label>
                                        <Input
                                            id="label"
                                            name="label"
                                            required
                                            autoComplete="off"
                                            placeholder="e.g. Casablanca warehouse"
                                            className="h-11"
                                        />
                                        <InputError message={errors.label} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="collect_city_id">
                                            Collect city
                                        </Label>
                                        <Select
                                            key={selectedCourier.id}
                                            name="collect_city_id"
                                        >
                                            <SelectTrigger
                                                id="collect_city_id"
                                                className="h-11 w-full"
                                            >
                                                <SelectValue placeholder="Select the city you ship from" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {cities.map((city) => (
                                                    <SelectItem
                                                        key={city.id}
                                                        value={String(city.id)}
                                                    >
                                                        {city.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            message={errors.collect_city_id}
                                        />
                                    </div>

                                    {selectedCourier.slug === 'Sendit' && (
                                        <>
                                            <div className="grid gap-2">
                                                <Label htmlFor="public_key">
                                                    Sendit public key
                                                </Label>
                                                <Input
                                                    id="public_key"
                                                    name="public_key"
                                                    required
                                                    autoComplete="off"
                                                    placeholder="Paste your Sendit public key"
                                                    className="h-11"
                                                />
                                                <InputError
                                                    message={errors.public_key}
                                                />
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="secret_key">
                                                    Sendit secret key
                                                </Label>
                                                <Input
                                                    id="secret_key"
                                                    name="secret_key"
                                                    type="password"
                                                    required
                                                    autoComplete="off"
                                                    placeholder="Paste your Sendit secret key"
                                                    className="h-11"
                                                />
                                                <InputError
                                                    message={errors.secret_key}
                                                />
                                            </div>
                                        </>
                                    )}

                                    {selectedCourier.slug === 'OzonExpress' && (
                                        <>
                                            <div className="grid gap-2">
                                                <Label htmlFor="ozon_id">
                                                    OzonExpress customer ID
                                                </Label>
                                                <Input
                                                    id="ozon_id"
                                                    name="ozon_id"
                                                    required
                                                    autoComplete="off"
                                                    className="h-11"
                                                />
                                                <InputError
                                                    message={errors.ozon_id}
                                                />
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="api_key">
                                                    OzonExpress API key
                                                </Label>
                                                <Input
                                                    id="api_key"
                                                    name="api_key"
                                                    type="password"
                                                    required
                                                    autoComplete="off"
                                                    className="h-11"
                                                />
                                                <InputError
                                                    message={errors.api_key}
                                                />
                                            </div>
                                        </>
                                    )}
                                </div>
                            )}

                            <div className="flex items-center justify-center gap-4">
                                <Button
                                    type="submit"
                                    size="lg"
                                    disabled={
                                        processing || selectedCourier === null
                                    }
                                    className="min-w-48"
                                >
                                    Connect courier
                                </Button>
                                <Button variant="ghost" size="lg" asChild>
                                    <Link href={storesIndex()}>
                                        Skip for now
                                    </Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

StoresCourier.layout = {
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
            title: 'Connect courier',
            href: '#',
        },
    ],
};
