import { MoreHorizontal, Phone } from 'lucide-react';
import { useMemo } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { GetInitialsFn } from '@/hooks/use-initials';
import { useTranslation } from '@/hooks/use-translation';
import type { User } from '@/types';

const roleLabels: Record<User['role'], string> = {
    super_admin: 'Super Admin',
    admin: 'Admin',
    confirmation_agent: 'Confirmation Agent',
    fulfilment_agent: 'Fulfilment Agent',
};

const roleBadgeClasses: Record<User['role'], string> = {
    super_admin:
        'bg-purple-100 text-purple-700 dark:bg-purple-500/15 dark:text-purple-300',
    admin: 'bg-purple-100 text-purple-700 dark:bg-purple-500/15 dark:text-purple-300',
    confirmation_agent:
        'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
    fulfilment_agent:
        'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
};

const statusBadgeClasses: Record<User['status'], string> = {
    active: 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300',
    invited:
        'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
    disabled: 'bg-muted text-muted-foreground',
};

/**
 * Mock performance metrics derived deterministically from the user id so the
 * same member always shows the same placeholder numbers within a session.
 * No real performance data is tracked yet.
 */
function useMockMetrics(userId: number) {
    return useMemo(() => {
        const seed = (userId * 2654435761) % 100;

        return {
            handled: 40 + (seed % 160),
            successRate: 78 + (seed % 20),
            avgResponseMinutes: 2 + (seed % 12),
        };
    }, [userId]);
}

export function TeamMemberCard({
    user,
    getInitials,
    onEdit,
    onDelete,
}: {
    user: User;
    getInitials: GetInitialsFn;
    onEdit: (user: User) => void;
    onDelete: (user: User) => void;
}) {
    const { t } = useTranslation();

    const metrics = useMockMetrics(user.id);

    return (
        <Card className="overflow-hidden">
            <CardHeader className="flex flex-row items-start justify-between gap-3">
                <div className="flex items-center gap-3">
                    <Avatar className="size-12">
                        <AvatarImage
                            src={user.avatar ?? undefined}
                            alt={user.name}
                        />
                        <AvatarFallback>
                            {getInitials(user.name)}
                        </AvatarFallback>
                    </Avatar>
                    <div className="grid gap-1.5">
                        <span className="leading-none font-medium">
                            {user.name}
                        </span>
                        <Badge
                            variant="outline"
                            className={`w-fit border-transparent ${roleBadgeClasses[user.role]}`}
                        >
                            {t(roleLabels[user.role])}
                        </Badge>
                    </div>
                </div>

                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="shrink-0"
                        >
                            <MoreHorizontal />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onSelect={() => onEdit(user)}>
                            {t('Edit')}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={() => onDelete(user)}
                        >
                            {t('Remove')}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </CardHeader>

            <CardContent className="space-y-4">
                <div className="flex flex-wrap items-center gap-2">
                    <Badge
                        variant="outline"
                        className={`border-transparent ${statusBadgeClasses[user.status]}`}
                    >
                        {user.status}
                    </Badge>
                </div>

                {user.phone && (
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Phone className="size-4" />
                        {user.phone}
                    </div>
                )}

                <div className="grid grid-cols-3 gap-2 rounded-lg border bg-muted/40 p-3 text-center">
                    <div className="grid gap-0.5">
                        <span className="text-sm font-semibold">
                            {metrics.handled}
                        </span>
                        <span className="text-[11px] text-muted-foreground">
                            {t('Orders handled')}
                        </span>
                    </div>
                    <div className="grid gap-0.5">
                        <span className="text-sm font-semibold">
                            {metrics.successRate}%
                        </span>
                        <span className="text-[11px] text-muted-foreground">
                            {t('Success rate')}
                        </span>
                    </div>
                    <div className="grid gap-0.5">
                        <span className="text-sm font-semibold">
                            {metrics.avgResponseMinutes}m
                        </span>
                        <span className="text-[11px] text-muted-foreground">
                            {t('Avg. response')}
                        </span>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
