import { Head, router } from '@inertiajs/react';
import {
    getCoreRowModel,
    getPaginationRowModel,
    getSortedRowModel,
    useReactTable,
} from '@tanstack/react-table';
import { Plus, Users as UsersIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { DataTable } from '@/components/data-table/data-table';
import {
    DataTableCard,
    DataTableCardFooter,
    DataTableCardTable,
    DataTableCardToolbar,
} from '@/components/data-table/data-table-card';
import { DataTablePagination } from '@/components/data-table/data-table-pagination';
import { DataTableViewOptions } from '@/components/data-table/data-table-view-options';
import { DeleteUserDialog } from '@/components/delete-user-dialog';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useInitials } from '@/hooks/use-initials';
import { dashboard } from '@/routes';
import { create as usersCreate, edit as usersEdit, index as usersIndexRoute } from '@/routes/users';
import type { User } from '@/types';
import { createColumns } from './columns';

type AgentTab = 'confirmation_agent' | 'fulfilment_agent';

const tabConfig: Record<
    AgentTab,
    { label: string; emptyTitle: string; emptyDescription: string }
> = {
    confirmation_agent: {
        label: 'Confirmation agents',
        emptyTitle: 'No confirmation agents yet',
        emptyDescription:
            'Add agents to confirm orders by phone or WhatsApp and start assigning them work.',
    },
    fulfilment_agent: {
        label: 'Fulfilment agents',
        emptyTitle: 'No fulfilment agents yet',
        emptyDescription:
            'Add agents to prepare and scan parcels in the warehouse.',
    },
};

export default function UsersIndex({ users }: { users: User[] }) {
    const getInitials = useInitials();
    const [tab, setTab] = useState<AgentTab>('confirmation_agent');
    const [deletingUser, setDeletingUser] = useState<User | null>(null);
    const [search, setSearch] = useState('');

    const handleCreate = () => {
        router.get(usersCreate({ query: { role: tab } }));
    };

    const handleEdit = (user: User) => {
        router.get(usersEdit(user.id));
    };

    const usersByTab = useMemo(
        () => ({
            confirmation_agent: users.filter(
                (user) => user.role === 'confirmation_agent',
            ),
            fulfilment_agent: users.filter(
                (user) => user.role === 'fulfilment_agent',
            ),
        }),
        [users],
    );

    const filteredUsers = useMemo(() => {
        const query = search.trim().toLowerCase();
        const scoped = usersByTab[tab];

        if (!query) {
            return scoped;
        }

        return scoped.filter((user) =>
            [user.name, user.email, user.phone ?? '']
                .join(' ')
                .toLowerCase()
                .includes(query),
        );
    }, [usersByTab, tab, search]);

    const columns = useMemo(
        () =>
            createColumns({
                getInitials,
                onEdit: handleEdit,
                onDelete: setDeletingUser,
            }),
        [getInitials],
    );

    const table = useReactTable({
        data: filteredUsers,
        columns,
        getCoreRowModel: getCoreRowModel(),
        getSortedRowModel: getSortedRowModel(),
        getPaginationRowModel: getPaginationRowModel(),
        initialState: {
            pagination: {
                pageSize: 20,
            },
        },
    });

    const activeTabUsers = usersByTab[tab];
    const config = tabConfig[tab];

    return (
        <>
            <Head title="Team" />

            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between">
                    <Heading
                        title="Team"
                        description="Manage your confirmation and fulfilment agents."
                    />
                    <Button onClick={handleCreate}>
                        <Plus />
                        Add {tab === 'confirmation_agent' ? 'confirmation' : 'fulfilment'} agent
                    </Button>
                </div>

                <Tabs
                    value={tab}
                    onValueChange={(value) => {
                        setTab(value as AgentTab);
                        setSearch('');
                    }}
                >
                    <TabsList>
                        <TabsTrigger value="confirmation_agent" className="gap-2">
                            <span>Confirmation agents</span>
                            <span className="rounded-full bg-muted-foreground/15 px-2 py-0.5 text-xs font-semibold">
                                {usersByTab.confirmation_agent.length}
                            </span>
                        </TabsTrigger>
                        <TabsTrigger value="fulfilment_agent" className="gap-2">
                            <span>Fulfilment agents</span>
                            <span className="rounded-full bg-muted-foreground/15 px-2 py-0.5 text-xs font-semibold">
                                {usersByTab.fulfilment_agent.length}
                            </span>
                        </TabsTrigger>
                    </TabsList>

                    {(['confirmation_agent', 'fulfilment_agent'] as const).map(
                        (tabValue) => (
                            <TabsContent
                                key={tabValue}
                                value={tabValue}
                                className="space-y-6 pt-4"
                            >
                                {activeTabUsers.length === 0 ? (
                                    <Empty className="border">
                                        <EmptyHeader>
                                            <EmptyMedia variant="icon">
                                                <UsersIcon />
                                            </EmptyMedia>
                                            <EmptyTitle>
                                                {config.emptyTitle}
                                            </EmptyTitle>
                                            <EmptyDescription>
                                                {config.emptyDescription}
                                            </EmptyDescription>
                                        </EmptyHeader>
                                        <EmptyContent>
                                            <Button onClick={handleCreate}>
                                                <Plus />
                                                Add {config.label.slice(0, -1)}
                                            </Button>
                                        </EmptyContent>
                                    </Empty>
                                ) : (
                                    <DataTableCard>
                                        <DataTableCardToolbar>
                                            <Input
                                                className="max-w-sm"
                                                placeholder="Search by name, email, or phone…"
                                                value={search}
                                                onChange={(event) =>
                                                    setSearch(event.target.value)
                                                }
                                            />
                                            <DataTableViewOptions table={table} />
                                        </DataTableCardToolbar>

                                        <DataTableCardTable>
                                            <DataTable
                                                table={table}
                                                columnCount={columns.length}
                                                emptyMessage="No matching team members found."
                                            />
                                        </DataTableCardTable>

                                        <DataTableCardFooter>
                                            <DataTablePagination table={table} />
                                        </DataTableCardFooter>
                                    </DataTableCard>
                                )}
                            </TabsContent>
                        ),
                    )}
                </Tabs>
            </div>

            <DeleteUserDialog
                open={deletingUser !== null}
                onOpenChange={(open) => !open && setDeletingUser(null)}
                user={deletingUser}
            />
        </>
    );
}

UsersIndex.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Team',
            href: usersIndexRoute(),
        },
    ],
};
