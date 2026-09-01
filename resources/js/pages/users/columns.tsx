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
import { formatMoroccoPhoneForWhatsApp } from '@/lib/phone';
import type { User } from '@/types';

const statusBadgeClasses: Record<User['status'], string> = {
    active: 'bg-emerald-500/15 text-emerald-700 border-emerald-500/30 dark:text-emerald-300',
    invited: 'bg-amber-500/15 text-amber-700 border-amber-500/30 dark:text-amber-300',
    disabled: 'bg-muted text-muted-foreground border-border',
};

export function createColumns({
    getInitials,
    onEdit,
    onDelete,
}: {
    getInitials: GetInitialsFn;
    onEdit: (user: User) => void;
    onDelete: (user: User) => void;
}): ColumnDef<User>[] {
    return [
        {
            accessorKey: 'name',
            header: ({ column }) => (
                <DataTableColumnHeader column={column} title="Agent" />
            ),
            cell: ({ row }) => {
                const user = row.original;

                return (
                    <div className="flex items-center gap-3 py-1">
                        <Avatar className="size-9 border shadow-sm">
                            <AvatarImage src={user.avatar ?? undefined} alt={user.name} />
                            <AvatarFallback className="text-xs font-semibold">
                                {getInitials(user.name)}
                            </AvatarFallback>
                        </Avatar>
                        <div className="grid gap-0.5">
                            <div className="flex items-center gap-2">
                                <span className="font-semibold text-sm leading-tight text-foreground">
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
                <DataTableColumnHeader column={column} title="Phone" />
            ),
            cell: ({ row }) => {
                const phone = row.original.phone;

                if (!phone) {
                    return <span className="text-muted-foreground text-xs">—</span>;
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
                                title="Chat on WhatsApp"
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
                <DataTableColumnHeader column={column} title="Status" />
            ),
            cell: ({ row }) => {
                const status = row.original.status;

                return (
                    <Badge
                        variant="outline"
                        className={`capitalize font-medium ${statusBadgeClasses[status] ?? statusBadgeClasses.disabled}`}
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
            id: 'compensation',
            header: 'Compensation',
            cell: ({ row }) => {
                const user = row.original;
                const rule = user.commission_rules?.[0];

                if (!rule) {
                    return <span className="text-muted-foreground text-xs font-normal">Not configured</span>;
                }

                if (rule.payment_mode === 'salary') {
                    return (
                        <div className="flex items-center gap-1.5 text-sm font-medium">
                            <Banknote className="size-3.5 text-primary shrink-0" />
                            <span>
                                {Number(rule.salary_amount ?? 0).toLocaleString()} MAD / {rule.salary_period ?? 'monthly'}
                            </span>
                        </div>
                    );
                }

                const overridesCount = Math.max(0, (user.commission_rules?.length ?? 1) - 1);

                return (
                    <div className="flex flex-wrap items-center gap-1.5 text-sm font-medium">
                        <div className="flex items-center gap-1.5">
                            <Wallet className="size-3.5 text-amber-600 dark:text-amber-400 shrink-0" />
                            <span>
                                {rule.amount ?? 0} {rule.amount_type === 'percentage' ? '%' : 'MAD'} / order
                            </span>
                        </div>
                        {overridesCount > 0 && (
                            <Badge variant="secondary" className="px-1.5 py-0 text-[10px] bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
                                +{overridesCount} {overridesCount === 1 ? 'override' : 'overrides'}
                            </Badge>
                        )}
                    </div>
                );
            },
        },
        {
            id: 'scope',
            header: 'Assignment Scope',
            cell: ({ row }) => {
                const user = row.original;
                const scopes = user.agent_scopes ?? [];

                if (scopes.length === 0) {
                    return (
                        <Badge variant="outline" className="text-xs font-normal text-muted-foreground">
                            All stores & products
                        </Badge>
                    );
                }

                const storeScopesCount = scopes.filter((s) => s.store_id !== null).length;
                const productScopesCount = scopes.filter((s) => s.product_id !== null).length;

                return (
                    <div className="flex flex-wrap items-center gap-1">
                        {storeScopesCount > 0 && (
                            <Badge variant="secondary" className="bg-primary/10 text-primary text-xs font-medium">
                                <StoreIcon className="mr-1 size-3" />
                                {storeScopesCount} {storeScopesCount === 1 ? 'store' : 'stores'}
                            </Badge>
                        )}
                        {productScopesCount > 0 && (
                            <Badge variant="secondary" className="bg-primary/10 text-primary text-xs font-medium">
                                <Package className="mr-1 size-3" />
                                {productScopesCount} {productScopesCount === 1 ? 'product' : 'products'}
                            </Badge>
                        )}
                    </div>
                );
            },
        },
        {
            id: 'targets',
            header: 'KPI Targets',
            cell: ({ row }) => {
                const user = row.original;
                const confTarget = user.performance_targets?.find((t) => t.metric === 'confirmation_rate');
                const deliveryTarget = user.performance_targets?.find((t) => t.metric === 'delivery_success_rate');

                const confRate = confTarget ? `${confTarget.target_percentage}% rate` : '80% rate';
                const deliveryRate = deliveryTarget ? `${deliveryTarget.target_percentage}% delivered` : '90% delivered';

                return (
                    <div className="flex items-center gap-2 text-xs">
                        <div title="Target Confirmation Rate" className="flex items-center gap-1 font-medium text-emerald-600 dark:text-emerald-400">
                            <TrendingUp className="size-3 shrink-0" />
                            <span>{confRate}</span>
                        </div>
                        <span className="text-muted-foreground">•</span>
                        <div title="Target Delivery Success Rate" className="flex items-center gap-1 font-medium text-blue-600 dark:text-blue-400">
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
                                    <span className="sr-only">Open menu</span>
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-44">
                                <DropdownMenuItem onSelect={() => onEdit(user)} className="gap-2 cursor-pointer font-medium">
                                    <Pencil className="size-4 text-muted-foreground" />
                                    <span>Edit agent</span>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    onSelect={() => navigator.clipboard.writeText(user.email)}
                                    className="gap-2 cursor-pointer"
                                >
                                    <Copy className="size-4 text-muted-foreground" />
                                    <span>Copy email</span>
                                </DropdownMenuItem>
                                {user.phone && (
                                    <DropdownMenuItem
                                        onSelect={() => navigator.clipboard.writeText(user.phone!)}
                                        className="gap-2 cursor-pointer"
                                    >
                                        <Phone className="size-4 text-muted-foreground" />
                                        <span>Copy phone</span>
                                    </DropdownMenuItem>
                                )}
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    onSelect={() => onDelete(user)}
                                    className="gap-2 cursor-pointer"
                                >
                                    <Trash2 className="size-4" />
                                    <span>Delete agent</span>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                );
            },
        },
    ];
}
