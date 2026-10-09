import { Head, router } from '@inertiajs/react';
import { Plus, Send } from 'lucide-react';
import { useState } from 'react';
import {
    BriefSheet,
    HistorySheet,
    NewProductSheet,
    setProductStatus,
} from '@/components/creatives/product-sheets';
import {
    RequestDetailSheet,
    RequestSheet,
} from '@/components/creatives/request-sheets';
import {
    C,
    EditorAvatars,
    EmptyCard,
    FlightTag,
    KindTag,
    Money,
    RevTag,
    StatusChip,
    TestTag,
    TypeBadge,
    ago,
    isStale,
} from '@/components/creatives/ui';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useTranslation } from '@/hooks/use-translation';
import { formatDate } from '@/lib/format';
import { dashboard } from '@/routes';
import { index as commissionsIndex } from '@/routes/commission-entries';
import type {
    AdminCreativesProps,
    CreativeProduct,
    QueueItem,
} from '@/types/creatives';

type Space = 'test' | 'active' | 'queue' | 'inactive' | 'commissions';

const QUEUE_ORDER: Record<QueueItem['status'], number> = {
    returned: 0,
    edits: 1,
    sent: 2,
    validated: 3,
};

function Th({ children }: { children?: React.ReactNode }) {
    return (
        <th className="px-4 py-3 text-left text-xs font-medium text-muted-foreground first:pl-6 last:pr-6">
            {children}
        </th>
    );
}

function ProductCard({
    product,
    queue,
    onBrief,
    onHistory,
    onGoToQueue,
}: {
    product: CreativeProduct;
    queue: QueueItem[];
    onBrief: () => void;
    onHistory: () => void;
    onGoToQueue: () => void;
}) {
    const { t } = useTranslation();
    const testing = product.status === 'testing';
    const stale = isStale(product.last_push_at);

    return (
        <div className="flex flex-col rounded-xl border border-border bg-card p-5 shadow-xs">
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="truncate font-semibold">{product.name}</p>
                    <div className="mt-1.5 flex items-center gap-2">
                        <KindTag
                            kind={product.kind}
                            links={product.links.length}
                        />
                    </div>
                </div>
                <EditorAvatars editors={product.editors} />
            </div>

            {testing ? (
                <p className="mt-3 line-clamp-2 text-sm text-muted-foreground">
                    {product.description || '—'}
                </p>
            ) : (
                <div className="mt-4 flex items-end justify-between gap-3">
                    <div>
                        <div className="text-[26px] leading-none font-semibold tabular-nums">
                            {product.works_count}
                        </div>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {t('validated creatives')}
                        </p>
                    </div>
                    <div className="text-right">
                        <p
                            className={`text-sm font-semibold ${stale ? 'text-[#B3281D]' : 'text-foreground'}`}
                        >
                            {ago(product.last_push_at)}
                            {stale ? ' ⚠' : ''}
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {t('last push')}
                        </p>
                    </div>
                </div>
            )}

            <FlightTag
                product={product}
                queue={queue}
                onGoToQueue={onGoToQueue}
            />

            <div className="mt-auto flex gap-2 pt-4">
                <Button
                    type="button"
                    variant="outline"
                    className="flex-1"
                    onClick={onBrief}
                >
                    {t('Brief')} ✎
                </Button>
                {testing ? (
                    <>
                        {product.history.length > 0 && (
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                title={t('Push history')}
                                onClick={onHistory}
                            >
                                🕘
                            </Button>
                        )}
                        <Button
                            type="button"
                            className="flex-1 text-white hover:opacity-90"
                            style={{ background: C.teal }}
                            onClick={() => setProductStatus(product, 'active')}
                        >
                            {t('Validate')} ✓
                        </Button>
                    </>
                ) : (
                    <Button
                        type="button"
                        variant="outline"
                        className="flex-1"
                        onClick={onHistory}
                    >
                        {t('History')}
                    </Button>
                )}
                <Button
                    type="button"
                    variant="outline"
                    className="text-muted-foreground"
                    title={
                        testing
                            ? t("Didn't work — move to inactive")
                            : t('Move to inactive')
                    }
                    onClick={() => setProductStatus(product, 'inactive')}
                >
                    {t('Archive')}
                </Button>
            </div>
        </div>
    );
}

export default function CreativesAdmin({
    products,
    queue,
    commissions,
    editors,
}: AdminCreativesProps) {
    const { t } = useTranslation();
    const [space, setSpace] = useState<Space>('test');
    const [newOpen, setNewOpen] = useState(false);
    const [requestOpen, setRequestOpen] = useState(false);
    const [briefId, setBriefId] = useState<number | null>(null);
    const [historyId, setHistoryId] = useState<number | null>(null);
    const [detailId, setDetailId] = useState<number | null>(null);

    const byStatus = (status: CreativeProduct['status']) =>
        products.filter((p) => p.status === status);
    const testing = byStatus('testing');
    const active = byStatus('active');
    const inactive = byStatus('inactive');
    const sortedQueue = [...queue].sort(
        (a, b) => QUEUE_ORDER[a.status] - QUEUE_ORDER[b.status],
    );
    const returnedCount = queue.filter((q) => q.status === 'returned').length;

    const briefProduct = products.find((p) => p.id === briefId) ?? null;
    const historyProduct = products.find((p) => p.id === historyId) ?? null;
    const detailItem = queue.find((q) => q.id === detailId) ?? null;

    const goToQueue = () => setSpace('queue');

    const grid = (rows: CreativeProduct[], empty: string) => (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {rows.map((p) => (
                <ProductCard
                    key={p.id}
                    product={p}
                    queue={queue}
                    onBrief={() => setBriefId(p.id)}
                    onHistory={() => setHistoryId(p.id)}
                    onGoToQueue={goToQueue}
                />
            ))}
            {!rows.length && <EmptyCard>{empty}</EmptyCard>}
        </div>
    );

    const tab = (value: Space, label: string, badge?: number) => (
        <TabsTrigger
            value={value}
            className="rounded-md px-4 py-1.5 text-sm font-medium data-[state=active]:bg-card data-[state=active]:shadow-xs"
        >
            {label}
            {badge ? (
                <span className="ml-1 rounded-full bg-[#F2602F] px-1.5 py-0.5 text-[10px] leading-none font-bold text-white">
                    {badge}
                </span>
            ) : null}
        </TabsTrigger>
    );

    return (
        <>
            <Head title={t('Creatives')} />

            <div className="space-y-4 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('Creatives')}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t(
                            'Products hold the identity — every workload lives in the review queue.',
                        )}
                    </p>
                </div>

                <Tabs value={space} onValueChange={(v: string) => setSpace(v as Space)}>
                    <TabsList className="h-auto flex-wrap justify-start rounded-lg bg-muted p-1">
                        {tab('test', t('For test products'))}
                        {tab('active', t('Active products'))}
                        {tab('queue', t('Review queue'), returnedCount)}
                        {tab('inactive', t('Inactive products'))}
                        {tab('commissions', t('Commissions'))}
                    </TabsList>

                    <TabsContent value="test" className="pt-4">
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <h2 className="text-lg font-semibold tracking-tight">
                                    {t('For test products')}
                                </h2>
                                <p className="mt-0.5 text-sm text-muted-foreground">
                                    {t(
                                        'New products being tested — validate one to move it to Active.',
                                    )}
                                </p>
                            </div>
                            <Button
                                type="button"
                                onClick={() => setNewOpen(true)}
                            >
                                <Plus />
                                {t('New product')}
                            </Button>
                        </div>
                        {grid(
                            testing,
                            t(
                                'No products in testing. Create one with New product.',
                            ),
                        )}
                    </TabsContent>

                    <TabsContent value="active" className="pt-4">
                        <div className="mb-4">
                            <h2 className="text-lg font-semibold tracking-tight">
                                {t('Active products')}
                            </h2>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                {t(
                                    'Validated products — order new creatives from the Review queue.',
                                )}
                            </p>
                        </div>
                        {grid(
                            active,
                            t(
                                'No active products yet — validate one from For test.',
                            ),
                        )}
                    </TabsContent>

                    <TabsContent value="queue" className="pt-4">
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <h2 className="text-lg font-semibold tracking-tight">
                                    {t('Review queue')}
                                </h2>
                                <p className="mt-0.5 text-sm text-muted-foreground">
                                    {t(
                                        'Every workload, separated from products — requested, returned, or in edits.',
                                    )}
                                </p>
                            </div>
                            <Button
                                type="button"
                                onClick={() => setRequestOpen(true)}
                            >
                                <Send />
                                {t('Request content')}
                            </Button>
                        </div>
                        <div className="grid gap-3">
                            {sortedQueue.map((q) => (
                                <div
                                    key={q.id}
                                    className={`flex flex-wrap items-center gap-4 rounded-xl border bg-card p-5 shadow-xs ${q.status === 'returned' ? 'border-[#F2602F]/40' : 'border-border'}`}
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="flex flex-wrap items-center gap-1.5 font-semibold">
                                            <span className="truncate">
                                                {q.product.name}
                                            </span>
                                            <KindTag
                                                kind={q.product.kind}
                                                links={q.product.links_count}
                                            />
                                            <TestTag
                                                status={q.product.status}
                                            />
                                            <RevTag rev={q.rev} />
                                        </p>
                                        <div className="mt-1.5 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                            {q.items.map((i) => (
                                                <TypeBadge
                                                    key={i.type}
                                                    type={i.type}
                                                    count={i.count}
                                                />
                                            ))}
                                            <span>
                                                {q.status === 'returned'
                                                    ? t('by')
                                                    : t('to')}{' '}
                                                {q.editor}
                                            </span>
                                            · <span>{ago(q.when)}</span>
                                            {q.origin === 'editor' && (
                                                <span>
                                                    · {t('their daily push')}
                                                </span>
                                            )}
                                        </div>
                                        {q.status === 'returned' &&
                                            q.editor_note && (
                                                <p className="mt-2 line-clamp-1 text-sm text-muted-foreground">
                                                    “{q.editor_note}”
                                                </p>
                                            )}
                                        {q.status === 'sent' &&
                                            q.admin_note && (
                                                <p className="mt-2 line-clamp-1 text-sm text-muted-foreground">
                                                    {t('your note')}: “
                                                    {q.admin_note}”
                                                </p>
                                            )}
                                    </div>
                                    <StatusChip status={q.status} />
                                    <Button
                                        type="button"
                                        variant={
                                            q.status === 'returned'
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() => setDetailId(q.id)}
                                    >
                                        {q.status === 'returned'
                                            ? t('Preview content')
                                            : t('View')}
                                    </Button>
                                </div>
                            ))}
                            {!sortedQueue.length && (
                                <EmptyCard>
                                    {t(
                                        'Queue is clear — request content to get the editors working. 🎉',
                                    )}
                                </EmptyCard>
                            )}
                        </div>
                    </TabsContent>

                    <TabsContent value="inactive" className="pt-4">
                        <div className="mb-4">
                            <h2 className="text-lg font-semibold tracking-tight">
                                {t('Inactive products')}
                            </h2>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                {t(
                                    'Paused or killed products — reactivate one to put it back to work.',
                                )}
                            </p>
                        </div>
                        <div className="grid gap-3">
                            {inactive.map((p) => (
                                <div
                                    key={p.id}
                                    className="flex flex-wrap items-center gap-4 rounded-xl border border-border bg-card p-5 shadow-xs"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="flex items-center gap-1.5 font-semibold">
                                            <span className="truncate">
                                                {p.name}
                                            </span>
                                            <KindTag
                                                kind={p.kind}
                                                links={p.links.length}
                                            />
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {t(':count creatives made', {
                                                count: p.works_count,
                                            })}{' '}
                                            · {t('last push')}{' '}
                                            {ago(p.last_push_at)}
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setHistoryId(p.id)}
                                    >
                                        {t('History')}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="border-dashed border-[#A87110]/60 bg-[#EFA22C]/10 text-[#A87110] hover:bg-[#EFA22C]/20 hover:text-[#A87110]"
                                        title={t('Back to For test products')}
                                        onClick={() =>
                                            setProductStatus(p, 'testing')
                                        }
                                    >
                                        🧪 {t('Test again')}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            setProductStatus(p, 'active')
                                        }
                                    >
                                        {t('Reactivate')}
                                    </Button>
                                </div>
                            ))}
                            {!inactive.length && (
                                <EmptyCard>{t('Nothing inactive.')}</EmptyCard>
                            )}
                        </div>
                    </TabsContent>

                    <TabsContent value="commissions" className="pt-4">
                        <div className="rounded-xl border border-border bg-card shadow-xs">
                            <div className="flex flex-wrap items-center justify-between gap-4 p-6 pb-4">
                                <div>
                                    <h2 className="text-lg font-semibold tracking-tight">
                                        {t('Commissions')}
                                    </h2>
                                    <p className="mt-0.5 text-sm text-muted-foreground">
                                        {t(
                                            'Validated workloads and what they pay — invoice and pay them from the Commissions page.',
                                        )}
                                    </p>
                                </div>
                                <div className="flex flex-wrap gap-6 text-sm text-muted-foreground">
                                    <span>
                                        {t('Pending')}{' '}
                                        <Money
                                            value={commissions.pending}
                                            className="text-base text-[#A87110]"
                                        />
                                    </span>
                                    <span>
                                        {t('Paid this month')}{' '}
                                        <Money
                                            value={commissions.paidThisMonth}
                                            className="text-base text-[#0C7D6F]"
                                        />
                                    </span>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.get(commissionsIndex().url)
                                        }
                                    >
                                        {t('Open Commissions')}
                                    </Button>
                                </div>
                            </div>
                            <div className="max-h-[420px] overflow-x-auto overflow-y-auto border-t border-border">
                                <table className="w-full text-sm">
                                    <thead className="sticky top-0 z-10 bg-card">
                                        <tr className="border-b border-border">
                                            <Th>{t('Workload')}</Th>
                                            <Th>{t('Editor')}</Th>
                                            <Th>{t('Validated')}</Th>
                                            <Th>{t('Commission')}</Th>
                                            <Th>{t('Status')}</Th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {commissions.rows.map((c) => (
                                            <tr
                                                key={c.id}
                                                className="border-b border-border last:border-0 hover:bg-muted/40"
                                            >
                                                <td className="px-4 py-4 first:pl-6">
                                                    <p className="truncate font-semibold">
                                                        {c.product}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {c.label}
                                                    </p>
                                                </td>
                                                <td className="px-4 py-4">
                                                    {c.editor}
                                                </td>
                                                <td className="px-4 py-4 text-muted-foreground">
                                                    {formatDate(c.validated_at)}
                                                </td>
                                                <td className="px-4 py-4">
                                                    <Money value={c.amount} />
                                                </td>
                                                <td className="px-4 py-4 pr-6">
                                                    {c.paid ? (
                                                        <span className="rounded-full bg-[#16A08E]/10 px-2.5 py-1 text-xs font-semibold text-[#0C7D6F]">
                                                            {t('Paid')}
                                                        </span>
                                                    ) : c.invoice_number ? (
                                                        <span className="rounded-full bg-secondary px-2.5 py-1 text-xs font-semibold text-secondary-foreground">
                                                            {c.invoice_number}
                                                        </span>
                                                    ) : (
                                                        <span className="rounded-full bg-[#EFA22C]/12 px-2.5 py-1 text-xs font-semibold text-[#A87110]">
                                                            {t('Pending')}
                                                        </span>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                        {!commissions.rows.length && (
                                            <tr>
                                                <td
                                                    colSpan={5}
                                                    className="px-6 py-10 text-center text-sm text-muted-foreground"
                                                >
                                                    {t(
                                                        'No validated workloads yet.',
                                                    )}
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                            <div className="border-t border-border px-6 py-3 text-xs text-muted-foreground">
                                {t(
                                    commissions.countThisMonth === 1
                                        ? ':count workload this month'
                                        : ':count workloads this month',
                                    { count: commissions.countThisMonth },
                                )}
                            </div>
                        </div>
                    </TabsContent>
                </Tabs>
            </div>

            <NewProductSheet
                open={newOpen}
                onOpenChange={setNewOpen}
                editors={editors}
            />
            <RequestSheet
                open={requestOpen}
                onOpenChange={setRequestOpen}
                products={products}
                editors={editors}
                onSent={goToQueue}
            />
            <BriefSheet
                product={briefProduct}
                editors={editors}
                onOpenChange={(open) => !open && setBriefId(null)}
            />
            <HistorySheet
                product={historyProduct}
                onOpenChange={(open) => !open && setHistoryId(null)}
            />
            <RequestDetailSheet
                item={detailItem}
                onOpenChange={(open) => !open && setDetailId(null)}
            />
        </>
    );
}

CreativesAdmin.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Creatives', href: '/creatives' },
    ],
};
