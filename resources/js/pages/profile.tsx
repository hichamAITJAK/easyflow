import { Head, usePage } from '@inertiajs/react';
import {
    BadgeCheck,
    Calendar,
    Mail,
    Phone,
    ShieldCheck,
    ShieldOff,
} from 'lucide-react';
import Heading from '@/components/heading';
import { OrdersBarChart } from '@/components/profile/orders-bar-chart';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useInitials } from '@/hooks/use-initials';
import { formatDate, formatDateTime } from '@/lib/format';
import { dashboard } from '@/routes';
import type { Auth, UserRole, UserStatus } from '@/types';

type ProfileStats = {
    orders_assigned: number;
    orders_confirmed: number;
    orders_delivered: number;
    orders_cancelled: number;
    confirmation_rate: number;
    commission_earned: number;
};

type PageProps = {
    auth: Auth;
};

const roleLabels: Record<UserRole, string> = {
    super_admin: 'Super Admin',
    admin: 'Admin',
    confirmation_agent: 'Confirmation Agent',
    fulfilment_agent: 'Fulfilment Agent',
};

const statusClasses: Record<UserStatus, string> = {
    active: 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300',
    invited: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
    disabled: 'bg-muted text-muted-foreground',
};

function StatTile({
    label,
    value,
    accent,
}: {
    label: string;
    value: string;
    accent?: 'success' | 'destructive';
}) {
    return (
        <Card className="py-4">
            <CardHeader className="gap-1 px-4">
                <CardDescription>{label}</CardDescription>
                <CardTitle
                    className={
                        accent === 'success'
                            ? 'text-2xl text-emerald-600 dark:text-emerald-500'
                            : accent === 'destructive'
                              ? 'text-2xl text-destructive'
                              : 'text-2xl'
                    }
                >
                    {value}
                </CardTitle>
            </CardHeader>
        </Card>
    );
}

export default function Profile({
    stats,
    ordersByDay,
}: {
    stats: ProfileStats;
    ordersByDay: { date: string; count: number }[];
}) {
    const { auth } = usePage<PageProps>().props;
    const user = auth.user;
    const getInitials = useInitials();

    const memberSince = formatDate(user.created_at, {
        month: 'long',
        year: 'numeric',
    });
    const lastLogin = user.last_login_at
        ? formatDateTime(user.last_login_at)
        : 'Never';

    return (
        <>
            <Head title="Profile" />

            <div className="space-y-6 p-4">
                <Heading
                    title="Profile"
                    description="Your account and performance overview."
                />

                <Card>
                    <CardContent className="flex flex-col gap-6 sm:flex-row sm:items-center">
                        <Avatar className="size-20">
                            <AvatarImage
                                src={user.avatar ?? undefined}
                                alt={user.name}
                            />
                            <AvatarFallback className="text-xl">
                                {getInitials(user.name)}
                            </AvatarFallback>
                        </Avatar>

                        <div className="flex-1 space-y-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="text-xl font-semibold">
                                    {user.name}
                                </h2>
                                <Badge variant="outline">
                                    {roleLabels[user.role]}
                                </Badge>
                                <Badge
                                    variant="outline"
                                    className={`border-transparent ${statusClasses[user.status]}`}
                                >
                                    {user.status}
                                </Badge>
                            </div>

                            <div className="grid grid-cols-1 gap-x-6 gap-y-1.5 text-sm text-muted-foreground sm:grid-cols-2">
                                <div className="flex items-center gap-2">
                                    <Mail className="size-4" />
                                    {user.email}
                                </div>
                                <div className="flex items-center gap-2">
                                    <Phone className="size-4" />
                                    {user.phone ?? '—'}
                                </div>
                                <div className="flex items-center gap-2">
                                    <Calendar className="size-4" />
                                    Member since {memberSince}
                                </div>
                                <div className="flex items-center gap-2">
                                    {user.two_factor_enabled ? (
                                        <>
                                            <ShieldCheck className="size-4 text-emerald-600 dark:text-emerald-500" />
                                            Two-factor enabled
                                        </>
                                    ) : (
                                        <>
                                            <ShieldOff className="size-4" />
                                            Two-factor disabled
                                        </>
                                    )}
                                </div>
                                <div className="flex items-center gap-2">
                                    <BadgeCheck className="size-4" />
                                    Last login: {lastLogin}
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
                    <StatTile
                        label="Orders assigned"
                        value={String(stats.orders_assigned)}
                    />
                    <StatTile
                        label="Confirmed"
                        value={String(stats.orders_confirmed)}
                    />
                    <StatTile
                        label="Delivered"
                        value={String(stats.orders_delivered)}
                        accent="success"
                    />
                    <StatTile
                        label="Cancelled"
                        value={String(stats.orders_cancelled)}
                        accent="destructive"
                    />
                    <StatTile
                        label="Confirmation rate"
                        value={`${stats.confirmation_rate}%`}
                    />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Orders assigned</CardTitle>
                        <CardDescription>Last 14 days</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <OrdersBarChart data={ordersByDay} />
                    </CardContent>
                </Card>

                <Card className="py-4">
                    <CardHeader className="gap-1 px-4">
                        <CardDescription>
                            Commission earned (all time)
                        </CardDescription>
                        <CardTitle className="text-2xl">
                            {new Intl.NumberFormat(undefined, {
                                style: 'currency',
                                currency: 'USD',
                            }).format(stats.commission_earned)}
                        </CardTitle>
                    </CardHeader>
                </Card>
            </div>
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Profile',
            href: '/profile',
        },
    ],
};
