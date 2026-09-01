import { Eye, Pencil, ShieldAlert, Trash2 } from 'lucide-react';
import type { ComponentType } from 'react';
import type { Order } from '@/types';

type ActionItemProps = {
    onSelect: () => void;
    variant?: 'default' | 'destructive';
    children: React.ReactNode;
};

export type OrderRowAction = {
    key: string;
    label: string;
    icon: ComponentType<{ className?: string }>;
    variant?: 'default' | 'destructive';
    onSelect: () => void;
};

/**
 * The actions available for a single order row, shared between the row's
 * dropdown ("...") menu and its right-click context menu so the two never
 * drift out of sync — every action always carries an icon.
 */
export function orderRowActions({
    order,
    isAdmin,
    onView,
    onDelete,
    onBlacklist,
    onEdit,
}: {
    order: Order;
    isAdmin: boolean;
    onView: (order: Order) => void;
    onDelete: (order: Order) => void;
    onBlacklist?: (order: Order) => void;
    onEdit?: (order: Order) => void;
}): OrderRowAction[] {
    const actions: OrderRowAction[] = [
        {
            key: 'view',
            label: 'View details',
            icon: Eye,
            onSelect: () => onView(order),
        },
    ];

    // Locked once shipped — the courier already has the order by then (see
    // UpdateOrderRequest::authorize, the backend enforces the same rule).
    if (onEdit && order.delivery_status === null) {
        actions.push({
            key: 'edit',
            label: 'Edit order',
            icon: Pencil,
            onSelect: () => onEdit(order),
        });
    }

    if (
        isAdmin &&
        onBlacklist &&
        !order.is_test &&
        !order.is_blacklist_flagged
    ) {
        actions.push({
            key: 'blacklist',
            label: 'Blacklist customer',
            icon: ShieldAlert,
            variant: 'destructive',
            onSelect: () => onBlacklist(order),
        });
    }

    if (isAdmin) {
        actions.push({
            key: 'delete',
            label: 'Delete',
            icon: Trash2,
            variant: 'destructive',
            onSelect: () => onDelete(order),
        });
    }

    return actions;
}

/**
 * Renders a shared action list into whichever menu-item component the
 * caller supplies (DropdownMenuItem or ContextMenuItem — both share the
 * same onSelect/variant/children shape), so the two triggers stay
 * pixel-for-pixel identical without duplicated JSX.
 */
export function OrderRowActionItems({
    actions,
    Item,
}: {
    actions: OrderRowAction[];
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
