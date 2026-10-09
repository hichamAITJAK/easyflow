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
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';
import {
    create as usersCreate,
    edit as usersEdit,
    index as usersIndexRoute,
} from '@/routes/users';
import type { User } from '@/types';
import { createColumns } from './columns';

type AgentTab = 'confirmation_agent' | 'fulfilment_agent' | 'creatives_editor';

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
    creatives_editor: {
        label: 'Creatives editors',
        emptyTitle: 'No creatives editors yet',
        emptyDescription:
            'Add editors to produce videos and statics for your products.',
    },
};

const ADD_LABELS: Record<AgentTab, string> = {
    confirmation_agent: 'Add confirmation agent',
    fulfilment_agent: 'Add fulfilment agent',
    creatives_editor: 'Add creatives editor',
};

export default function UsersIndex({ users }: { users: User[] }) {
    const { t } = useTranslation();

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
            creatives_editor: users.filter(
                (user) => user.role === 'creatives_editor',
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
                t,
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
            <Head title={t('Team')} />

            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between">
                    <Heading
                        title={t('Team')}
                        description={t(
                            'Manage your confirmation agents, fulfilment agents and creatives editors.',
                        )}
                    />
                    <Button onClick={handleCreate}>
                        <Plus />
                        {t(ADD_LABELS[tab])}
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
                        <TabsTrigger
                            value="confirmation_agent"
                            className="gap-2"
                        >
                            <span>{t('Confirmation agents')}</span>
                            <span className="rounded-full bg-muted-foreground/15 px-2 py-0.5 text-xs font-semibold">
                                {usersByTab.confirmation_agent.length}
                            </span>
                        </TabsTrigger>
                        <TabsTrigger value="fulfilment_agent" className="gap-2">
                            <span>{t('Fulfilment agents')}</span>
                            <span className="rounded-full bg-muted-foreground/15 px-2 py-0.5 text-xs font-semibold">
                                {usersByTab.fulfilment_agent.length}
                            </span>
                        </TabsTrigger>
                        <TabsTrigger value="creatives_editor" className="gap-2">
                            <span>{t('Creatives editors')}</span>
                            <span className="rounded-full bg-muted-foreground/15 px-2 py-0.5 text-xs font-semibold">
                                {usersByTab.creatives_editor.length}
                            </span>
                        </TabsTrigger>
                    </TabsList>

                    {(
                        [
                            'confirmation_agent',
                            'fulfilment_agent',
                            'creatives_editor',
                        ] as const
                    ).map((tabValue) => (
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
                                            {t(config.emptyTitle)}
                                        </EmptyTitle>
                                        <EmptyDescription>
                                            {t(config.emptyDescription)}
                                        </EmptyDescription>
                                    </EmptyHeader>
                                    <EmptyContent>
                                        <Button onClick={handleCreate}>
                                            <Plus />
                                            {t(ADD_LABELS[tab])}
                                        </Button>
                                    </EmptyContent>
                                </Empty>
                            ) : (
                                <DataTableCard>
                                    <DataTableCardToolbar>
                                        <Input
                                            className="max-w-sm"
                                            placeholder={t(
                                                'Search by name, email, or phone…',
                                            )}
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
                    ))}
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
