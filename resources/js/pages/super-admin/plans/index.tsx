import { Head, Link, router } from '@inertiajs/react';
import {
    MoreHorizontal,
    Pencil,
    Plus,
    Power,
    PowerOff,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@/components/ui/empty';
import SuperAdminLayout from '@/layouts/super-admin/layout';
import { formatLimit, limitLabel } from '@/lib/plan-limits';
import { cn } from '@/lib/utils';
import {
    create as createPlan,
    destroy as destroyPlan,
    edit as editPlan,
    toggle as togglePlan,
} from '@/routes/super-admin/plans';
import type { CatalogPlan } from '@/types';

export default function SuperAdminPlansIndex({
    plans,
    limitKeys,
}: {
    plans: CatalogPlan[];
    limitKeys: string[];
}) {
    const [deleting, setDeleting] = useState<CatalogPlan | null>(null);

    return (
        <SuperAdminLayout>
            <Head title="Plans" />

            <div className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <Heading
                        title="Plans"
                        description="The subscription tiers tenants can pay for."
                    />
                    <Button asChild>
                        <Link href={createPlan()}>
                            <Plus />
                            New plan
                        </Link>
                    </Button>
                </div>

                {plans.length === 0 ? (
                    <Empty className="border">
                        <EmptyHeader>
                            <EmptyTitle>No plans yet</EmptyTitle>
                            <EmptyDescription>
                                Until a plan exists, tenants have nothing to
                                upgrade to when their trial ends.
                            </EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <Button asChild>
                                <Link href={createPlan()}>
                                    <Plus />
                                    New plan
                                </Link>
                            </Button>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {plans.map((plan) => (
                            <Card
                                key={plan.id}
                                className={cn(!plan.is_active && 'opacity-60')}
                            >
                                <CardContent className="space-y-4">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="space-y-1">
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium">
                                                    {plan.name}
                                                </span>
                                                <Badge
                                                    variant="outline"
                                                    className={cn(
                                                        'text-xs font-medium',
                                                        plan.is_active
                                                            ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                                                            : 'bg-muted text-muted-foreground',
                                                    )}
                                                >
                                                    {plan.is_active
                                                        ? 'Active'
                                                        : 'Hidden'}
                                                </Badge>
                                            </div>
                                            <span className="font-mono text-xs text-muted-foreground">
                                                {plan.slug}
                                            </span>
                                        </div>

                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8"
                                                    aria-label={`Actions for ${plan.name}`}
                                                >
                                                    <MoreHorizontal />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuItem asChild>
                                                    <Link
                                                        href={editPlan(plan.id)}
                                                    >
                                                        <Pencil />
                                                        Edit plan
                                                    </Link>
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        router.patch(
                                                            togglePlan(plan.id),
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    {plan.is_active ? (
                                                        <>
                                                            <PowerOff />
                                                            Deactivate
                                                        </>
                                                    ) : (
                                                        <>
                                                            <Power />
                                                            Activate
                                                        </>
                                                    )}
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    variant="destructive"
                                                    onSelect={() =>
                                                        setDeleting(plan)
                                                    }
                                                >
                                                    <Trash2 />
                                                    Delete
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>

                                    <div className="flex items-baseline gap-1">
                                        <span className="text-2xl font-semibold tabular-nums">
                                            {Number(
                                                plan.price,
                                            ).toLocaleString()}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {plan.currency} /{' '}
                                            {plan.duration_days} days
                                        </span>
                                    </div>

                                    <dl className="grid gap-1 border-t pt-3 text-sm">
                                        {limitKeys.map((key) => (
                                            <div
                                                key={key}
                                                className="flex items-center justify-between gap-2"
                                            >
                                                <dt className="text-muted-foreground">
                                                    {limitLabel(key)}
                                                </dt>
                                                <dd className="tabular-nums">
                                                    {formatLimit(
                                                        plan.limits?.[key],
                                                    )}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>

                                    <p className="text-xs text-muted-foreground tabular-nums">
                                        {plan.subscriptions_count ?? 0}{' '}
                                        {plan.subscriptions_count === 1
                                            ? 'subscription'
                                            : 'subscriptions'}{' '}
                                        sold
                                    </p>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>

            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
            >
                <DialogContent>
                    <DialogTitle>Delete {deleting?.name}?</DialogTitle>
                    <DialogDescription>
                        {(deleting?.subscriptions_count ?? 0) > 0
                            ? 'This plan has been subscribed to, so it cannot be deleted — deactivate it instead to stop offering it while keeping its billing history intact.'
                            : 'This plan has never been subscribed to, so deleting it removes it permanently.'}
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <Button
                            variant="secondary"
                            onClick={() => setDeleting(null)}
                        >
                            Keep it
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={(deleting?.subscriptions_count ?? 0) > 0}
                            onClick={() => {
                                if (deleting) {
                                    router.delete(destroyPlan(deleting.id), {
                                        preserveScroll: true,
                                        onSuccess: () => setDeleting(null),
                                    });
                                }
                            }}
                        >
                            Delete plan
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </SuperAdminLayout>
    );
}
