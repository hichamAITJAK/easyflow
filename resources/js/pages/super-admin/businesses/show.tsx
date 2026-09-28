import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Ban, PauseCircle, Pencil, PlayCircle } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { BusinessStatusDialog } from '@/components/super-admin/business-status-dialog';
import type { BusinessStatusIntent } from '@/components/super-admin/business-status-dialog';
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
}: {
    business: Business;
    users: User[];
    stores: Store[];
}) {
    const [statusIntent, setStatusIntent] =
        useState<BusinessStatusIntent | null>(null);

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
                        Go back to businesses
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
                                {STATUS_LABELS[business.status]}
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
                                Edit
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
                                Reactivate
                            </Button>
                        )}

                        {business.status === 'active' && (
                            <Button
                                variant="outline"
                                onClick={() => setStatusIntent('suspend')}
                            >
                                <PauseCircle />
                                Suspend
                            </Button>
                        )}

                        {business.status !== 'cancelled' && (
                            <Button
                                variant="destructive"
                                onClick={() => setStatusIntent('cancel')}
                            >
                                <Ban />
                                Cancel
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Stat label="Users" value={business.users_count ?? 0} />
                    <Stat label="Stores" value={business.stores_count ?? 0} />
                    <Stat
                        label="Products"
                        value={business.products_count ?? 0}
                    />
                    <Stat label="Orders" value={business.orders_count ?? 0} />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Team</CardTitle>
                        <CardDescription>
                            Everyone with an account at this business.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="px-0">
                        {users.length === 0 ? (
                            <Empty className="border-none py-8">
                                <EmptyHeader>
                                    <EmptyTitle>No users yet</EmptyTitle>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="pl-6">
                                            Name
                                        </TableHead>
                                        <TableHead>Role</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="pr-6">
                                            Last login
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
                                                {ROLE_LABELS[user.role] ??
                                                    user.role}
                                            </TableCell>
                                            <TableCell className="capitalize">
                                                {user.status}
                                            </TableCell>
                                            <TableCell className="pr-6 text-muted-foreground">
                                                {user.last_login_at
                                                    ? formatDateTime(
                                                          user.last_login_at,
                                                      )
                                                    : 'Never'}
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
                        <CardTitle>Stores</CardTitle>
                        <CardDescription>
                            E-commerce stores connected by this business.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="px-0">
                        {stores.length === 0 ? (
                            <Empty className="border-none py-8">
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">
                                        <Ban />
                                    </EmptyMedia>
                                    <EmptyTitle>No stores connected</EmptyTitle>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="pl-6">
                                            Store
                                        </TableHead>
                                        <TableHead>Platform</TableHead>
                                        <TableHead>Connection</TableHead>
                                        <TableHead className="pr-6">
                                            Last synced
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
                                                {store.connection_status}
                                            </TableCell>
                                            <TableCell className="pr-6 text-muted-foreground">
                                                {store.last_synced_at
                                                    ? formatDateTime(
                                                          store.last_synced_at,
                                                      )
                                                    : 'Never'}
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
        </SuperAdminLayout>
    );
}
