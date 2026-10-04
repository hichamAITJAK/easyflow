import { Ban, Eye, PauseCircle, Pencil, PlayCircle } from 'lucide-react';
import type { ComponentType, ReactNode } from 'react';
import type { Translator } from '@/lib/i18n';
import type { Business } from '@/types';

type ActionItemProps = {
    onSelect: () => void;
    variant?: 'default' | 'destructive';
    children: ReactNode;
};

export type BusinessRowAction = {
    key: string;
    label: string;
    icon: ComponentType<{ className?: string }>;
    variant?: 'default' | 'destructive';
    onSelect: () => void;
};

/**
 * The actions available for a single business row. A cancelled business is
 * terminal — the backend rejects any further status change (see
 * UpdateBusinessStatusRequest), so its lifecycle actions are omitted here
 * rather than offered and then refused.
 */
export function businessRowActions({
    t,
    business,
    onView,
    onEdit,
    onSuspend,
    onReactivate,
    onCancel,
}: {
    t: Translator;
    business: Business;
    onView: (business: Business) => void;
    onEdit: (business: Business) => void;
    onSuspend: (business: Business) => void;
    onReactivate: (business: Business) => void;
    onCancel: (business: Business) => void;
}): BusinessRowAction[] {
    const actions: BusinessRowAction[] = [
        {
            key: 'view',
            label: t('View details'),
            icon: Eye,
            onSelect: () => onView(business),
        },
        {
            key: 'edit',
            label: t('Edit business'),
            icon: Pencil,
            onSelect: () => onEdit(business),
        },
    ];

    if (business.status === 'active') {
        actions.push({
            key: 'suspend',
            label: t('Suspend'),
            icon: PauseCircle,
            variant: 'destructive',
            onSelect: () => onSuspend(business),
        });
    }

    if (business.status === 'suspended') {
        actions.push({
            key: 'reactivate',
            label: t('Reactivate'),
            icon: PlayCircle,
            onSelect: () => onReactivate(business),
        });
    }

    if (business.status !== 'cancelled') {
        actions.push({
            key: 'cancel',
            label: t('Cancel business'),
            icon: Ban,
            variant: 'destructive',
            onSelect: () => onCancel(business),
        });
    }

    return actions;
}

/**
 * Renders a shared action list into whichever menu-item component the
 * caller supplies (DropdownMenuItem or ContextMenuItem), mirroring the
 * orders table so the two tables behave the same way.
 */
export function BusinessRowActionItems({
    actions,
    Item,
}: {
    actions: BusinessRowAction[];
    Item: ComponentType<ActionItemProps>;
}) {
    return (
        <>
            {actions.map((action) => (
                <Item
                    key={action.key}
                    variant={action.variant}
                    onSelect={action.onSelect}
                >
                    <action.icon />
                    {action.label}
                </Item>
            ))}
        </>
    );
}
