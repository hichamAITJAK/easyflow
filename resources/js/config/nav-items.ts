import {
    BarChart3,
    Calculator,
    LayoutGrid,
    Package,
    PackageSearch,
    ScaleIcon,
    ShieldBan,
    ShoppingCart,
    Store,
    Truck,
    Users,
    UsersRound,
    Wallet,
} from 'lucide-react';
import { dashboard } from '@/routes';
import { index as commissionEntriesIndex } from '@/routes/commission-entries';
import {
    blacklist as customersBlacklist,
    index as customersIndex,
} from '@/routes/customers';
import { index as deliveryCouriersIndex } from '@/routes/delivery-couriers';
import { index as ordersIndex } from '@/routes/orders';
import { index as parcelsIndex } from '@/routes/parcels';
import { index as productsIndex } from '@/routes/products';
import { index as profitCalculatorIndex } from '@/routes/profit-calculator';
import { index as reportsIndex } from '@/routes/reports';
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
    {
        title: 'Blacklist',
        href: customersBlacklist(),
        icon: ShieldBan,
    },
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
    {
        title: 'Reports',
        href: reportsIndex(),
        icon: BarChart3,
    },
    {
        title: 'Profit Calculator',
        href: profitCalculatorIndex(),
        icon: Calculator,
    },
];

// Nav items not listed here are visible to every authenticated role.
export const navItemRoles: Partial<Record<string, UserRole[]>> = {
    Team: ['super_admin', 'admin'],
    Stores: ['super_admin', 'admin'],
    'Delivery Couriers': ['super_admin', 'admin'],
    Settlements: ['super_admin', 'admin'],
    Reports: ['super_admin', 'admin'],
    'Profit Calculator': ['super_admin', 'admin'],
};

export function getVisibleNavItems(role?: UserRole | null): NavItem[] {
    return mainNavItems.filter((item) => {
        const allowedRoles = navItemRoles[item.title];

        return !allowedRoles || (role && allowedRoles.includes(role));
    });
}
