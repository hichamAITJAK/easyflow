import type { ColumnDef } from '@tanstack/react-table';
import {
    Banknote,
    Copy,
    MessageCircle,
    MoreHorizontal,
    Pencil,
    Phone,
    Store as StoreIcon,
    Trash2,
    Package,
    TrendingUp,
    Truck,
    Wallet,
} from 'lucide-react';
import { DataTableColumnHeader } from '@/components/data-table/data-table-column-header';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { GetInitialsFn } from '@/hooks/use-initials';
import { formatDateTime } from '@/lib/format';
import type { Translator } from '@/lib/i18n';
import { formatMoroccoPhoneForWhatsApp } from '@/lib/phone';
import type { User } from '@/types';

const statusBadgeClasses: Record<User['status'], string> = {
    active: 'bg-emerald-500/15 text-emerald-700 border-emerald-500/30 dark:text-emerald-300',
    invited:
        'bg-amber-500/15 text-amber-700 border-amber-500/30 dark:text-amber-300',
    disabled: 'bg-muted text-muted-foreground border-border',
};

export function createColumns({
    t,
    getInitials,
    onEdit,
    onDelete,
}: {
    t: Translator;
    getInitials: GetInitialsFn;
    onEdit: (user: User) => void;
    onDelete: (user: User) => void;
}): ColumnDef<User>[] {
    return [
        {
            accessorKey: 'name',
            header: ({ column }) => (
                <DataTableColumnHeader column={column} title={t('Agent')} />
            ),
            cell: ({ row }) => {
                const user = row.original;

                return (
                    <div className="flex items-center gap-3 py-1">
                        <Avatar className="size-9 border shadow-sm">
                            <AvatarImage
                                src={user.avatar ?? undefined}
                                alt={user.name}
                            />
                            <AvatarFallback className="text-xs font-semibold">
                                {getInitials(user.name)}
                            </AvatarFallback>
                        </Avatar>
                        <div className="grid gap-0.5">
                            <div className="flex items-center gap-2">
                                <span className="text-sm leading-tight font-semibold text-foreground">
                                    {user.name}
                                </span>
                            </div>
                            <span className="text-xs text-muted-foreground">
                                {user.email}
                            </span>
                        </div>
                    </div>
                );
            },
        },
        {
            accessorKey: 'phone',
            header: ({ column }) => (
                <DataTableColumnHeader column={column} title={t('Phone')} />
            ),
            cell: ({ row }) => {
                const phone = row.original.phone;

                if (!phone) {
                    return (
                        <span className="text-xs text-muted-foreground">—</span>
                    );
                }

                const whatsapp = formatMoroccoPhoneForWhatsApp(phone);

                return (
                    <div className="flex items-center gap-2 text-sm">
                        <span>{phone}</span>
                        {whatsapp && (
                            <a
                                href={`https://wa.me/${whatsapp}`}
                                target="_blank"
                                rel="noreferrer"
                                className="text-emerald-600 transition-colors hover:text-emerald-700 dark:text-emerald-400"
                                onClick={(event) => event.stopPropagation()}
                                title={t('Chat on WhatsApp')}
                            >
                                <MessageCircle className="size-4" />
                            </a>
                        )}
                    </div>
                );
            },
        },
        {
            accessorKey: 'status',
            header: ({ column }) => (
                <DataTableColumnHeader column={column} title={t('Status')} />
            ),
            cell: ({ row }) => {
                const status = row.original.status;

                return (
                    <Badge
                        variant="outline"
                        className={`font-medium capitalize ${statusBadgeClasses[status] ?? statusBadgeClasses.disabled}`}
                    >
                        <span
                            className={`mr-1.5 inline-block size-1.5 rounded-full ${
                                status === 'active'
                                    ? 'bg-emerald-500'
                                    : status === 'invited'
                                      ? 'bg-amber-500'
                                      : 'bg-muted-foreground'
                            }`}
                        />
                        {status}
                    </Badge>
                );
            },
        },
        {
            accessorKey: 'last_login_at',
            header: ({ column }) => (
                <DataTableColumnHeader
                    column={column}
                    title={t('Last login')}
                />
            ),
            cell: ({ row }) => (
                <span className="text-sm text-muted-foreground">
                    {row.original.last_login_at
                        ? formatDateTime(row.original.last_login_at)
                        : t('Never')}
                </span>
            ),
        },
        {
            id: 'compensation',
            header: t('Compensation'),
            cell: ({ row }) => {
                const user = row.original;
                const rule = user.commission_rules?.[0];

                if (!rule) {
                    return (
                        <span className="text-xs font-normal text-muted-foreground">
                            {t('Not configured')}
                        </span>
                    );
                }

                const salaryLine = (
                    <div className="flex items-center gap-1.5 text-sm font-medium">
                        <Banknote className="size-3.5 shrink-0 text-primary" />
                        <span>
                            {Number(rule.salary_amount ?? 0).toLocaleString()}{' '}
                            MAD / {t(rule.salary_period ?? 'monthly')}
                        </span>
                    </div>
                );

                if (rule.payment_mode === 'salary') {
                    return salaryLine;
                }

                const overridesCount = Math.max(
                    0,
                    (user.commission_rules?.length ?? 1) - 1,
                );

                const commissionLine = (
                    <div className="flex flex-wrap items-center gap-1.5 text-sm font-medium">
                        <div className="flex items-center gap-1.5">
                            <Wallet className="size-3.5 shrink-0 text-amber-600 dark:text-amber-400" />
                            <span>
                                {t(':amount :unit / order', {
                                    amount: rule.amount ?? 0,
                                    unit:
                                        rule.amount_type === 'percentage'
                                            ? '%'
                                            : 'MAD',
                                })}
                            </span>
                        </div>
                        {overridesCount > 0 && (
                            <Badge
                                variant="secondary"
                                className="border border-amber-500/20 bg-amber-500/10 px-1.5 py-0 text-[10px] text-amber-600 dark:text-amber-400"
                            >
                                +{overridesCount}{' '}
                                {overridesCount === 1
                                    ? 'override'
                                    : 'overrides'}
                            </Badge>
                        )}
                    </div>
                );

                // Salary + commission: both halves, salary first.
                return rule.payment_mode === 'salary_and_commission' ? (
                    <div className="grid gap-1">
                        {salaryLine}
                        {commissionLine}
                    </div>
                ) : (
                    commissionLine
                );
            },
        },
        {
            id: 'scope',
            header: t('Assignment Scope'),
            cell: ({ row }) => {
                const user = row.original;
                const scopes = user.agent_scopes ?? [];

                if (scopes.length === 0) {
                    return (
                        <Badge
                            variant="outline"
                            className="text-xs font-normal text-muted-foreground"
                        >
                            {t('All stores & products')}
                        </Badge>
                    );
                }

                const storeScopesCount = scopes.filter(
                    (s) => s.store_id !== null,
                ).length;
                const productScopesCount = scopes.filter(
                    (s) => s.product_id !== null,
                ).length;

                return (
                    <div className="flex flex-wrap items-center gap-1">
                        {storeScopesCount > 0 && (
                            <Badge
                                variant="secondary"
                                className="bg-primary/10 text-xs font-medium text-primary"
                            >
                                <StoreIcon className="mr-1 size-3" />
                                {storeScopesCount}{' '}
                                {storeScopesCount === 1 ? 'store' : 'stores'}
                            </Badge>
                        )}
                        {productScopesCount > 0 && (
                            <Badge
                                variant="secondary"
                                className="bg-primary/10 text-xs font-medium text-primary"
                            >
                                <Package className="mr-1 size-3" />
                                {productScopesCount}{' '}
                                {productScopesCount === 1
                                    ? 'product'
                                    : 'products'}
                            </Badge>
                        )}
                    </div>
                );
            },
        },
        {
            id: 'targets',
            header: t('KPI Targets'),
            cell: ({ row }) => {
                const user = row.original;
                const confTarget = user.performance_targets?.find(
                    (t) => t.metric === 'confirmation_rate',
                );
                const deliveryTarget = user.performance_targets?.find(
                    (t) => t.metric === 'delivery_success_rate',
                );

                const confRate = confTarget
                    ? `${confTarget.target_percentage}% rate`
                    : '80% rate';
                const deliveryRate = deliveryTarget
                    ? `${deliveryTarget.target_percentage}% delivered`
                    : '90% delivered';

                return (
                    <div className="flex items-center gap-2 text-xs">
                        <div
                            title={t('Target Confirmation Rate')}
                            className="flex items-center gap-1 font-medium text-emerald-600 dark:text-emerald-400"
                        >
                            <TrendingUp className="size-3 shrink-0" />
                            <span>{confRate}</span>
                        </div>
                        <span className="text-muted-foreground">•</span>
                        <div
                            title={t('Target Delivery Success Rate')}
                            className="flex items-center gap-1 font-medium text-blue-600 dark:text-blue-400"
                        >
                            <Truck className="size-3 shrink-0" />
                            <span>{deliveryRate}</span>
                        </div>
                    </div>
                );
            },
        },
        {
            id: 'actions',
            cell: ({ row }) => {
                const user = row.original;

                return (
                    <div className="flex items-center justify-end">
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-8"
                                    onClick={(event) => event.stopPropagation()}
                                >
                                    <MoreHorizontal className="size-4" />
                                    <span className="sr-only">
                                        {t('Open menu')}
                                    </span>
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-44">
                                <DropdownMenuItem
                                    onSelect={() => onEdit(user)}
                                    className="cursor-pointer gap-2 font-medium"
                                >
                                    <Pencil className="size-4 text-muted-foreground" />
                                    <span>{t('Edit agent')}</span>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    onSelect={() =>
                                        navigator.clipboard.writeText(
                                            user.email,
                                        )
                                    }
                                    className="cursor-pointer gap-2"
                                >
                                    <Copy className="size-4 text-muted-foreground" />
                                    <span>{t('Copy email')}</span>
                                </DropdownMenuItem>
                                {user.phone && (
                                    <DropdownMenuItem
                                        onSelect={() =>
                                            navigator.clipboard.writeText(
                                                user.phone!,
                                            )
                                        }
                                        className="cursor-pointer gap-2"
                                    >
                                        <Phone className="size-4 text-muted-foreground" />
                                        <span>{t('Copy phone')}</span>
                                    </DropdownMenuItem>
                                )}
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    onSelect={() => onDelete(user)}
                                    className="cursor-pointer gap-2"
                                >
                                    <Trash2 className="size-4" />
                                    <span>{t('Delete agent')}</span>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                );
            },
        },
    ];
}
