import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Ban,
    KeyRound,
    PauseCircle,
    Pencil,
    PlayCircle,
    Truck,
} from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { BusinessStatusDialog } from '@/components/super-admin/business-status-dialog';
import type { BusinessStatusIntent } from '@/components/super-admin/business-status-dialog';
import { ResetPasswordDialog } from '@/components/super-admin/reset-password-dialog';
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
    Empty,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTranslation } from '@/hooks/use-translation';
import SuperAdminLayout from '@/layouts/super-admin/layout';
import { formatDate, formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    edit as editBusiness,
    index as businessesIndex,
    status as businessStatus,
} from '@/routes/super-admin/businesses';
import type {
    Business,
    BusinessStatus,
    DeliveryAccount,
    Store,
    User,
} from '@/types';

const STATUS_STYLES: Record<BusinessStatus, string> = {
    active: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    suspended:
        'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400',
    cancelled: 'bg-muted text-muted-foreground',
};

const STATUS_LABELS: Record<BusinessStatus, string> = {
    active: 'Active',
    suspended: 'Suspended',
    cancelled: 'Cancelled',
};

const ROLE_LABELS: Record<string, string> = {
    super_admin: 'Super admin',
    admin: 'Admin',
    confirmation_agent: 'Confirmation agent',
    fulfilment_agent: 'Fulfilment agent',
    creatives_editor: 'Creatives editor',
};

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <Card className="py-4">
            <CardHeader className="gap-1 px-4">
                <CardDescription>{label}</CardDescription>
                <CardTitle className="text-2xl tabular-nums">{value}</CardTitle>
            </CardHeader>
        </Card>
    );
}

export default function SuperAdminBusinessesShow({
    business,
    users,
    stores,
    deliveryAccounts,
}: {
    business: Business;
    users: User[];
    stores: Store[];
    deliveryAccounts: DeliveryAccount[];
}) {
    const { t } = useTranslation();

    const [statusIntent, setStatusIntent] =
        useState<BusinessStatusIntent | null>(null);
    const [resetUser, setResetUser] = useState<User | null>(null);

    return (
        <SuperAdminLayout>
            <Head title={business.name} />

            <div className="space-y-6">
                <div>
                    <Link
                        href={businessesIndex()}
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        {t('Go back to businesses')}
                    </Link>
                </div>

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-2">
                        <Heading
                            title={business.name}
                            description={`Onboarded ${formatDate(business.created_at)}`}
                        />
                        <div className="-mt-6 flex items-center gap-2">
                            <Badge
                                variant="outline"
                                className={cn(
                                    'text-xs font-medium',
                                    STATUS_STYLES[business.status],
                                )}
                            >
                                {t(STATUS_LABELS[business.status])}
                            </Badge>
                            <span className="font-mono text-xs text-muted-foreground">
                                {business.slug}
                            </span>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="outline" asChild>
                            <Link href={editBusiness(business.id)}>
                                <Pencil />
                                {t('Edit')}
                            </Link>
                        </Button>

                        {business.status === 'suspended' && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.patch(
                                        businessStatus(business.id),
                                        { status: 'active' },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <PlayCircle />
                                {t('Reactivate')}
                            </Button>
                        )}

                        {business.status === 'active' && (
                            <Button
                                variant="outline"
                                onClick={() => setStatusIntent('suspend')}
                            >
                                <PauseCircle />
                                {t('Suspend')}
                            </Button>
                        )}

                        {business.status !== 'cancelled' && (
                            <Button
                                variant="destructive"
                                onClick={() => setStatusIntent('cancel')}
                            >
                                <Ban />
                                {t('Cancel')}
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Stat
                        label={t('Users')}
                        value={business.users_count ?? 0}
                    />
                    <Stat
                        label={t('Stores')}
                        value={business.stores_count ?? 0}
                    />
                    <Stat
                        label={t('Products')}
                        value={business.products_count ?? 0}
                    />
                    <Stat
                        label={t('Orders')}
                        value={business.orders_count ?? 0}
                    />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('Team')}</CardTitle>
                        <CardDescription>
                            {t('Everyone with an account at this business.')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="px-0">
                        {users.length === 0 ? (
                            <Empty className="border-none py-8">
                                <EmptyHeader>
                                    <EmptyTitle>{t('No users yet')}</EmptyTitle>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="pl-6">
                                            {t('Name')}
                                        </TableHead>
                                        <TableHead>{t('Role')}</TableHead>
                                        <TableHead>{t('Status')}</TableHead>
                                        <TableHead>{t('Last login')}</TableHead>
                                        <TableHead className="w-0 pr-6">
                                            <span className="sr-only">
                                                {t('Actions')}
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {users.map((user) => (
                                        <TableRow key={user.id}>
                                            <TableCell className="pl-6">
                                                <div className="grid gap-0.5">
                                                    <span className="font-medium">
                                                        {user.name}
                                                    </span>
                                                    <span className="text-xs text-muted-foreground">
                                                        {user.email}
                                                    </span>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {t(
                                                    ROLE_LABELS[user.role] ??
                                                        user.role,
                                                )}
                                            </TableCell>
                                            <TableCell className="capitalize">
                                                {t(user.status)}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {user.last_login_at
                                                    ? formatDateTime(
                                                          user.last_login_at,
                                                      )
                                                    : t('Never')}
                                            </TableCell>
                                            <TableCell className="pr-6">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        setResetUser(user)
                                                    }
                                                >
                                                    <KeyRound />
                                                    {t('Reset password')}
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('Stores')}</CardTitle>
                        <CardDescription>
                            {t('E-commerce stores connected by this business.')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="px-0">
                        {stores.length === 0 ? (
                            <Empty className="border-none py-8">
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">
                                        <Ban />
                                    </EmptyMedia>
                                    <EmptyTitle>
                                        {t('No stores connected')}
                                    </EmptyTitle>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="pl-6">
                                            {t('Store')}
                                        </TableHead>
                                        <TableHead>{t('Platform')}</TableHead>
                                        <TableHead>{t('Connection')}</TableHead>
                                        <TableHead className="pr-6">
                                            {t('Last synced')}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {stores.map((store) => (
                                        <TableRow key={store.id}>
                                            <TableCell className="pl-6">
                                                <div className="grid gap-0.5">
                                                    <span className="font-medium">
                                                        {store.name}
                                                    </span>
                                                    {store.domain && (
                                                        <span className="text-xs text-muted-foreground">
                                                            {store.domain}
                                                        </span>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {store.platform?.name ?? '—'}
                                            </TableCell>
                                            <TableCell className="capitalize">
                                                {t(store.connection_status)}
                                            </TableCell>
                                            <TableCell className="pr-6 text-muted-foreground">
                                                {store.last_synced_at
                                                    ? formatDateTime(
                                                          store.last_synced_at,
                                                      )
                                                    : t('Never')}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('Couriers')}</CardTitle>
                        <CardDescription>
                            {t('Delivery couriers connected by this business.')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="px-0">
                        {deliveryAccounts.length === 0 ? (
                            <Empty className="border-none py-8">
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">
                                        <Ban />
                                    </EmptyMedia>
                                    <EmptyTitle>
                                        {t('No couriers connected')}
                                    </EmptyTitle>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="pl-6">
                                            {t('Courier')}
                                        </TableHead>
                                        <TableHead>{t('Account')}</TableHead>
                                        <TableHead>
                                            {t('Pickup city')}
                                        </TableHead>
                                        <TableHead>{t('Status')}</TableHead>
                                        <TableHead className="pr-6">
                                            {t('Connected on')}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {deliveryAccounts.map((account) => (
                                        <TableRow key={account.id}>
                                            <TableCell className="pl-6">
                                                <div className="flex items-center gap-3">
                                                    {account.courier?.logo ? (
                                                        <img
                                                            src={
                                                                account.courier
                                                                    .logo
                                                            }
                                                            alt=""
                                                            className="size-8 shrink-0 rounded-full border border-border bg-white object-contain p-1"
                                                        />
                                                    ) : (
                                                        <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-secondary">
                                                            <Truck className="size-4" />
                                                        </span>
                                                    )}
                                                    <span className="font-medium">
                                                        {account.courier
                                                            ?.name ?? '—'}
                                                    </span>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <span className="inline-flex items-center gap-2">
                                                    {account.label}
                                                    {account.is_default && (
                                                        <Badge variant="secondary">
                                                            {t('Default')}
                                                        </Badge>
                                                    )}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {account.collect_city?.name ??
                                                    '—'}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant="outline"
                                                    className={cn(
                                                        'capitalize',
                                                        account.status ===
                                                            'active'
                                                            ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                                                            : 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400',
                                                    )}
                                                >
                                                    {t(account.status)}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="pr-6 text-muted-foreground">
                                                {formatDateTime(
                                                    account.created_at,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>

            <BusinessStatusDialog
                open={statusIntent !== null}
                onOpenChange={(open) => !open && setStatusIntent(null)}
                business={business}
                intent={statusIntent ?? 'suspend'}
            />
            <ResetPasswordDialog
                open={resetUser !== null}
                onOpenChange={(open) => !open && setResetUser(null)}
                businessId={business.id}
                user={resetUser}
            />
        </SuperAdminLayout>
    );
}
