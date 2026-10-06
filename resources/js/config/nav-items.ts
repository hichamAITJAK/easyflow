import {
    Calculator,
    LayoutGrid,
    Package,
    PackageSearch,
    ScaleIcon,
    ScanLine,
    ShoppingCart,
    Store,
    Truck,
    Users,
    UsersRound,
    Wallet,
} from 'lucide-react';
import { dashboard } from '@/routes';
import { index as commissionEntriesIndex } from '@/routes/commission-entries';
import { index as customersIndex } from '@/routes/customers';
import { index as deliveryCouriersIndex } from '@/routes/delivery-couriers';
import { index as fulfillmentIndex } from '@/routes/fulfillment';
import { index as ordersIndex } from '@/routes/orders';
import { index as parcelsIndex } from '@/routes/parcels';
import { index as productsIndex } from '@/routes/products';
import { index as profitCalculatorIndex } from '@/routes/profit-calculator';
import { index as settlementsIndex } from '@/routes/settlements';
import { index as storesIndex } from '@/routes/stores';
import { index as usersIndex } from '@/routes/users';
import type { NavItem, UserRole } from '@/types';

export const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Orders',
        href: ordersIndex(),
        icon: ShoppingCart,
    },
    {
        title: 'Parcels',
        href: parcelsIndex(),
        icon: PackageSearch,
    },
    {
        title: 'Products',
        href: productsIndex(),
        icon: Package,
    },
    {
        title: 'Customers',
        href: customersIndex(),
        icon: UsersRound,
    },
    // Blacklist is hidden from every sidebar for now, not removed: the
    // page still works at /customers/blacklist. Restore this entry (and
    // the ShieldBan + customersBlacklist imports) to bring the link back.
    // {
    //     title: 'Blacklist',
    //     href: customersBlacklist(),
    //     icon: ShieldBan,
    // },
    {
        title: 'Team',
        href: usersIndex(),
        icon: Users,
    },
    {
        title: 'Commissions',
        href: commissionEntriesIndex(),
        icon: Wallet,
    },
    {
        title: 'Settlements',
        href: settlementsIndex(),
        icon: ScaleIcon,
    },
    {
        title: 'Stores',
        href: storesIndex(),
        icon: Store,
    },
    {
        title: 'Delivery Couriers',
        href: deliveryCouriersIndex(),
        icon: Truck,
    },
    // Reports is hidden from the nav for now, not removed: the route and
    // page still work at /reports. Restore this entry (and the two
    // imports) to bring the link back.
    // {
    //     title: 'Reports',
    //     href: reportsIndex(),
    //     icon: BarChart3,
    // },
    {
        title: 'Profit Calculator',
        href: profitCalculatorIndex(),
        icon: Calculator,
    },
];

// Nav items not listed here are visible to every authenticated role.
//
// A fulfilment agent is left out of every entry: their sidebar is built
// from FULFILMENT_NAV_ITEMS below instead, because the sidebar items they
// would otherwise inherit (Orders, Parcels, Products, Customers) either
// 403 or — worse — render permanently empty, since order visibility for
// that role scopes to assignments they are never given.
export const navItemRoles: Partial<Record<string, UserRole[]>> = {
    Dashboard: ['super_admin', 'admin', 'confirmation_agent'],
    Orders: ['super_admin', 'admin', 'confirmation_agent'],
    Parcels: ['super_admin', 'admin', 'confirmation_agent'],
    Products: ['super_admin', 'admin', 'confirmation_agent'],
    Customers: ['super_admin', 'admin', 'confirmation_agent'],
    Commissions: ['super_admin', 'admin', 'confirmation_agent'],
    Team: ['super_admin', 'admin'],
    Stores: ['super_admin', 'admin'],
    'Delivery Couriers': ['super_admin', 'admin'],
    Settlements: ['super_admin', 'admin'],
    Reports: ['super_admin', 'admin'],
    'Profit Calculator': ['super_admin', 'admin'],
};

/**
 * The fulfilment agent's sidebar: the scan workspace they work from, and
 * their own commission entries. Kept as its own list rather than a filter
 * over mainNavItems because only one of its destinations appears there at
 * all — the workspace is not part of the ordinary operations nav.
 */
const FULFILMENT_NAV_ITEMS: NavItem[] = [
    {
        title: 'Fulfilment',
        href: fulfillmentIndex(),
        icon: ScanLine,
    },
    {
        title: 'Parcels',
        href: parcelsIndex(),
        icon: PackageSearch,
    },
    {
        title: 'Commissions',
        href: commissionEntriesIndex(),
        icon: Wallet,
    },
];

export function getVisibleNavItems(role?: UserRole | null): NavItem[] {
    if (role === 'fulfilment_agent') {
        return FULFILMENT_NAV_ITEMS;
    }

    return mainNavItems.filter((item) => {
        const allowedRoles = navItemRoles[item.title];

        return !allowedRoles || (role && allowedRoles.includes(role));
    });
}
