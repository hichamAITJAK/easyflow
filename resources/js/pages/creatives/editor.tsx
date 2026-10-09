import { Head, router, usePage } from '@inertiajs/react';
import { Eye, ExternalLink, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import {
    DirectionsBlock,
    EmptyCard,
    KindTag,
    Money,
    PillToggle,
    RevTag,
    SectionLabel,
    SidePanel,
    TestTag,
    TypeBadge,
    ago,
    itemsLabel,
    totalCount,
} from '@/components/creatives/ui';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { useTranslation } from '@/hooks/use-translation';
import { formatDate } from '@/lib/format';
import { dashboard } from '@/routes';
import { store as selfPush } from '@/routes/creatives/pushes';
import { push as pushRequest, pushUpdate } from '@/routes/creatives/requests';
import type { PageProps } from '@/types';
import type { ContentType, QueueItem } from '@/types/creatives';

/* ────────────────────────── props ──────────────────────────────────── */
type EditorProduct = {
    id: number;
    name: string;
    kind: 'single' | 'pack';
    links: string[];
    description: string | null;
    works_count: number;
    last_push_at: string | null;
};

type CommissionRow = {
    id: number;
    product: string;
    label: string | null;
    validated_at: string;
    amount: number;
    paid: boolean;
};

type Props = {
    products: EditorProduct[];
    requests: QueueItem[];
    commissions: {
        rows: CommissionRow[];
        pending: number;
        paidThisMonth: number;
        countThisMonth: number;
    };
    admin: string;
};

type Space = 'products' | 'requests' | 'commissions';
type Errors = Record<string, string>;

const ORDER: Record<QueueItem['status'], number> = {
    sent: 0,
    edits: 1,
    returned: 2,
    validated: 3,
};

const CHIP: Record<QueueItem['status'], { cls: string; label: string }> = {
    sent: {
        cls: 'bg-[#F2602F]/10 text-[#C24A1A]',
        label: 'New request · to do',
    },
    edits: { cls: 'bg-[#EFA22C]/12 text-[#A87110]', label: 'Needs edits' },
    returned: {
        cls: 'bg-[#468FA5]/12 text-[#2E7389]',
        label: 'Submitted · awaiting review',
    },
    validated: { cls: 'bg-[#16A08E]/10 text-[#0C7D6F]', label: 'Validated' },
};

function Th({ children }: { children?: React.ReactNode }) {
    return (
        <th className="px-4 py-3 text-left text-xs font-medium text-muted-foreground first:pl-6 last:pr-6">
            {children}
        </th>
    );
}

function PointsBlock({ points, label }: { points: string[]; label: string }) {
    if (!points.length) {
        return null;
    }

    return (
        <div>
            <SectionLabel className="text-[#A87110]">{label}</SectionLabel>
            <div className="mt-2 space-y-2">
                {points.map((p, i) => (
                    <div
                        key={i}
                        className="flex gap-2 rounded-lg bg-[#EFA22C]/8 p-3 text-sm"
                    >
                        <span className="font-mono text-xs font-bold text-[#A87110]">
                            {i + 1}.
                        </span>
                        <span>{p}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function NoteBlock({ note, label }: { note: string | null; label: string }) {
    if (!note) {
        return null;
    }

    return (
        <div>
            <SectionLabel>{label}</SectionLabel>
            <p className="mt-2 rounded-lg bg-muted/50 p-4 text-sm">{note}</p>
        </div>
    );
}

/* ────────────────────────── Brief sheet ────────────────────────────── */
function BriefSheet({
    product,
    onOpenChange,
}: {
    product: EditorProduct | null;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    if (!product) {
        return null;
    }

    const pack = product.kind === 'pack';

    return (
        <SidePanel
            open
            onOpenChange={onOpenChange}
            title={product.name}
            description={`${pack ? t('Pack of :n products', { n: product.links.length }) : t('Single product')} · ${t('active product')}`}
        >
            <div>
                <SectionLabel>{t('Description')}</SectionLabel>
                <p className="mt-2 rounded-lg bg-muted/50 p-4 text-sm leading-relaxed whitespace-pre-line">
                    {product.description || '—'}
                </p>
            </div>
            <div>
                <SectionLabel>
                    {pack ? t('Products in this pack') : t('Product link')}
                </SectionLabel>
                <div className="mt-2 grid gap-2">
                    {product.links.map((link, i) => (
                        <a
                            key={i}
                            href={link}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex h-9 items-center justify-between rounded-md border border-input bg-card px-4 text-sm font-medium shadow-xs hover:bg-accent"
                        >
                            <span>
                                {pack
                                    ? t('Product :n', { n: i + 1 })
                                    : t('Open product link')}
                            </span>
                            <ExternalLink className="size-4 text-[#2E7389]" />
                        </a>
                    ))}
                </div>
            </div>
            <p className="rounded-lg border border-dashed border-border p-3 text-xs text-muted-foreground">
                {t(
                    'What to produce for this product is in Content requests — each request carries its own counts and directions.',
                )}
            </p>
        </SidePanel>
    );
}

/* ────────────────────────── Request view sheet ─────────────────────── */
function RequestViewSheet({
    item,
    admin,
    onOpenChange,
    onPush,
}: {
    item: QueueItem | null;
    admin: string;
    onOpenChange: (open: boolean) => void;
    onPush: (item: QueueItem) => void;
}) {
    const { t } = useTranslation();

    if (!item) {
        return null;
    }

    const testNote =
        item.product.status === 'testing'
            ? ` · 🧪 ${t('testing product')}`
            : '';
    const title = `${item.product.name} · ${itemsLabel(item.items)}`;

    if (item.status === 'sent') {
        return (
            <SidePanel
                open
                onOpenChange={onOpenChange}
                title={title}
                description={`${t('New request from :name', { name: admin })} · ${ago(item.when)}${testNote}`}
                footer={
                    <Button
                        type="button"
                        className="w-full"
                        onClick={() => onPush(item)}
                    >
                        {t('Push content for this request')}
                    </Button>
                }
            >
                <DirectionsBlock items={item.items} />
                <NoteBlock note={item.admin_note} label={t('Their note')} />
            </SidePanel>
        );
    }

    if (item.status === 'edits') {
        return (
            <SidePanel
                open
                onOpenChange={onOpenChange}
                title={title}
                description={`${t('Needs edits')} · v${item.rev} · ${ago(item.when)}${testNote}`}
                footer={
                    <Button
                        type="button"
                        variant="outline"
                        className="w-full border-[#EFA22C]/60 bg-[#EFA22C]/10 text-[#A87110] hover:bg-[#EFA22C]/20 hover:text-[#A87110]"
                        onClick={() => onPush(item)}
                    >
                        {t('Fix & resubmit')}
                    </Button>
                }
            >
                <PointsBlock
                    points={item.direction_points}
                    label={t('Fix these points — from :name', { name: admin })}
                />
                <DirectionsBlock items={item.items} />
                <NoteBlock note={item.admin_note} label={t('Their note')} />
            </SidePanel>
        );
    }

    const validated = item.status === 'validated';

    return (
        <SidePanel
            open
            onOpenChange={onOpenChange}
            title={title}
            description={
                validated
                    ? `${t('Validated')} · v${item.rev} · ${ago(item.when)}`
                    : `${t('Submitted')} · ${t("awaiting :name's review", { name: admin })} · v${item.rev}${testNote}`
            }
            footer={
                validated ? undefined : (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            className="w-full"
                            onClick={() => onPush(item)}
                        >
                            {t('Edit push')} ✎ · {t('link or note')}
                        </Button>
                        <p className="text-center text-xs text-muted-foreground">
                            {t(
                                'You can edit until :name validates or requests edits.',
                                { name: admin },
                            )}
                        </p>
                    </>
                )
            }
        >
            {item.drive_url && (
                <a
                    href={item.drive_url}
                    target="_blank"
                    rel="noreferrer"
                    className="flex items-center justify-between rounded-lg border border-border bg-muted/40 p-4 hover:bg-muted/70"
                >
                    <div>
                        <p className="text-sm font-semibold">
                            {t('Your content link')} ↗
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {t(':count creatives', {
                                count: totalCount(item.items),
                            })}{' '}
                            — {itemsLabel(item.items)}
                        </p>
                    </div>
                    <ExternalLink className="size-4 text-[#2E7389]" />
                </a>
            )}
            <NoteBlock note={item.editor_note} label={t('Your note')} />
            {item.origin === 'admin' && <DirectionsBlock items={item.items} />}
        </SidePanel>
    );
}

/* ────────────────────────── Push / resubmit / edit push ────────────── */
function PushSheet({
    item,
    admin,
    onOpenChange,
}: {
    item: QueueItem | null;
    admin: string;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const [driveUrl, setDriveUrl] = useState('');
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);
    const [loadedFor, setLoadedFor] = useState<number | null>(null);

    if (!item) {
        return null;
    }

    if (loadedFor !== item.id) {
        setLoadedFor(item.id);
        setDriveUrl(item.status === 'sent' ? '' : (item.drive_url ?? ''));
        setNote(item.status === 'returned' ? (item.editor_note ?? '') : '');
        setErrors({});
    }

    const update = item.status === 'returned';
    const resubmit = item.status === 'edits';
    const prefix = update
        ? t('Edit push')
        : resubmit
          ? t('Fix & resubmit')
          : t('Push content');
    const testNote =
        item.product.status === 'testing'
            ? ` · 🧪 ${t('testing product')}`
            : '';

    const submit = () => {
        const target = update ? pushUpdate(item.id) : pushRequest(item.id);
        const send = update ? router.put : router.post;
        setProcessing(true);
        send(
            target.url,
            { drive_url: driveUrl, note },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
                onError: (e) => setErrors(e as Errors),
                onSuccess: () => onOpenChange(false),
            },
        );
    };

    return (
        <SidePanel
            open
            onOpenChange={onOpenChange}
            title={`${prefix} · ${item.product.name}`}
            description={`${itemsLabel(item.items)} · v${item.rev}${testNote}`}
            footer={
                <div className="flex justify-end gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                    >
                        {t('Cancel')}
                    </Button>
                    <Button
                        type="button"
                        disabled={processing}
                        onClick={submit}
                    >
                        {update
                            ? t('Save changes')
                            : resubmit
                              ? `${t('Resubmit')} ✓`
                              : `${t('Push content')} ✓`}
                    </Button>
                </div>
            }
        >
            {resubmit && (
                <PointsBlock
                    points={item.direction_points}
                    label={t('Fix these points — from :name', { name: admin })}
                />
            )}
            {item.origin === 'admin' && <DirectionsBlock items={item.items} />}
            <NoteBlock note={item.admin_note} label={t('Their note')} />
            <div className="grid gap-1.5">
                <Label htmlFor="sp-drive">{t('Drive link · required')}</Label>
                <Input
                    id="sp-drive"
                    value={driveUrl}
                    onChange={(e) => setDriveUrl(e.target.value)}
                    placeholder="https://drive.google.com/…"
                    className={`font-mono text-xs ${errors.drive_url ? 'border-[#D92D20]' : ''}`}
                />
                <InputError message={errors.drive_url} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="sp-note">
                    {t('Note to :name · optional', { name: admin })}
                </Label>
                <Textarea
                    id="sp-note"
                    rows={2}
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    placeholder={t("what's inside, what you changed…")}
                />
            </div>
        </SidePanel>
    );
}

/* ────────────────────────── Self push ──────────────────────────────── */
const TYPES: ContentType[] = ['video', 'static'];

function SelfPushSheet({
    open,
    productId,
    products,
    admin,
    onOpenChange,
    onSent,
}: {
    open: boolean;
    productId: number | null;
    products: EditorProduct[];
    admin: string;
    onOpenChange: (open: boolean) => void;
    onSent: () => void;
}) {
    const { t } = useTranslation();
    const [selected, setSelected] = useState<string>('');
    const [on, setOn] = useState<Record<ContentType, boolean>>({
        video: true,
        static: false,
    });
    const [counts, setCounts] = useState<Record<ContentType, number>>({
        video: 3,
        static: 4,
    });
    const [driveUrl, setDriveUrl] = useState('');
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);
    const [openedWith, setOpenedWith] = useState<string | null>(null);

    // Reset the sheet each time it opens, seeded with the card it came from.
    const key = open ? String(productId ?? products[0]?.id ?? '') : null;

    if (key !== openedWith) {
        setOpenedWith(key);

        if (key !== null) {
            setSelected(key);
            setOn({ video: true, static: false });
            setCounts({ video: 3, static: 4 });
            setDriveUrl('');
            setNote('');
            setErrors({});
        }
    }

    const product = products.find((p) => String(p.id) === selected);
    const active = TYPES.filter((type) => on[type]);

    const submit = () => {
        setProcessing(true);
        router.post(
            selfPush().url,
            {
                product_id: selected,
                items: active.map((type) => ({ type, count: counts[type] })),
                drive_url: driveUrl,
                note,
            },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
                onError: (e) => setErrors(e as Errors),
                onSuccess: () => {
                    onOpenChange(false);
                    onSent();
                },
            },
        );
    };

    return (
        <SidePanel
            open={open}
            onOpenChange={onOpenChange}
            title={t('Push new content')}
            description={t(
                "Your daily content — goes straight to :name's review queue.",
                { name: admin },
            )}
            footer={
                <div className="flex justify-end gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                    >
                        {t('Cancel')}
                    </Button>
                    <Button
                        type="button"
                        disabled={processing || !product || !active.length}
                        onClick={submit}
                    >
                        {t('Push content')} ✓
                    </Button>
                </div>
            }
        >
            <div className="grid gap-1.5">
                <Label htmlFor="sf-product">{t('Product / Pack')}</Label>
                <Select value={selected} onValueChange={setSelected}>
                    <SelectTrigger id="sf-product" className="w-full">
                        <SelectValue placeholder={t('Choose a product…')} />
                    </SelectTrigger>
                    <SelectContent>
                        {products.map((p) => (
                            <SelectItem key={p.id} value={String(p.id)}>
                                <span className="flex items-center gap-2">
                                    {p.name}
                                    <KindTag
                                        kind={p.kind}
                                        links={p.links.length}
                                    />
                                </span>
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.product_id} />
            </div>
            <div className="grid gap-1.5">
                <Label>{t("What's inside · pick one or both")}</Label>
                <div className="flex gap-2">
                    {TYPES.map((type) => (
                        <PillToggle
                            key={type}
                            active={on[type]}
                            onClick={() => setOn({ ...on, [type]: !on[type] })}
                        >
                            {type === 'video'
                                ? '🎬 ' + t('Videos')
                                : '🖼 ' + t('Static ads')}
                        </PillToggle>
                    ))}
                </div>
                <InputError message={errors.items} />
            </div>
            <div className="grid gap-3">
                {active.map((type) => (
                    <label
                        key={type}
                        className="flex items-center justify-between rounded-xl border border-border p-4 text-sm font-medium"
                    >
                        <span>
                            {type === 'video'
                                ? '🎬 ' + t('Videos')
                                : '🖼 ' + t('Static ads')}{' '}
                            — {t('how many')}
                        </span>
                        <Input
                            type="number"
                            min={1}
                            max={30}
                            value={counts[type]}
                            onChange={(e) =>
                                setCounts({
                                    ...counts,
                                    [type]: Math.max(
                                        1,
                                        Math.min(
                                            30,
                                            Number(e.target.value) || 1,
                                        ),
                                    ),
                                })
                            }
                            className="h-8 w-16 text-center font-semibold"
                        />
                    </label>
                ))}
                {!active.length && (
                    <p className="rounded-lg border border-dashed border-border p-4 text-center text-sm text-muted-foreground">
                        {t('Pick at least one content type above.')}
                    </p>
                )}
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="sf-drive">{t('Drive link · required')}</Label>
                <Input
                    id="sf-drive"
                    value={driveUrl}
                    onChange={(e) => setDriveUrl(e.target.value)}
                    placeholder="https://drive.google.com/…"
                    className={`font-mono text-xs ${errors.drive_url ? 'border-[#D92D20]' : ''}`}
                />
                <InputError message={errors.drive_url} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="sf-note">
                    {t('Note to :name · optional', { name: admin })}
                </Label>
                <Textarea
                    id="sf-note"
                    rows={2}
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    placeholder={t(
                        "today's batch — what's inside, which angles…",
                    )}
                />
            </div>
        </SidePanel>
    );
}

/* ────────────────────────── page ───────────────────────────────────── */
export default function CreativesEditor({
    products,
    requests,
    commissions,
    admin,
}: Props) {
    const { t } = useTranslation();
    const { auth } = usePage<PageProps>().props;
    const firstName = auth.user?.name?.split(' ')[0] ?? '';

    const [space, setSpace] = useState<Space>('products');
    const [briefId, setBriefId] = useState<number | null>(null);
    const [viewId, setViewId] = useState<number | null>(null);
    const [pushId, setPushId] = useState<number | null>(null);
    const [selfOpen, setSelfOpen] = useState(false);
    const [selfProductId, setSelfProductId] = useState<number | null>(null);

    const open = requests
        .filter((r) => r.status !== 'validated')
        .sort((a, b) => ORDER[a.status] - ORDER[b.status]);
    const todo = requests.filter(
        (r) => r.status === 'sent' || r.status === 'edits',
    ).length;

    const briefProduct = products.find((p) => p.id === briefId) ?? null;
    const viewItem = requests.find((r) => r.id === viewId) ?? null;
    const pushItem = requests.find((r) => r.id === pushId) ?? null;

    const startPush = (item: QueueItem) => {
        setViewId(null);
        setPushId(item.id);
    };
    const openSelf = (productId: number | null) => {
        setSelfProductId(productId);
        setSelfOpen(true);
    };
    const goToRequests = () => setSpace('requests');

    const flightTag = (productId: number) => {
        const mine = requests.filter((r) => r.product.id === productId);
        const sent = mine.find((r) => r.status === 'sent');
        const edits = mine.find((r) => r.status === 'edits');
        const returned = mine.find((r) => r.status === 'returned');

        if (sent) {
            return (
                <button
                    type="button"
                    onClick={goToRequests}
                    className="mt-3 inline-flex w-fit items-center gap-1.5 rounded-full bg-[#F2602F]/10 px-2.5 py-1 text-xs font-semibold text-[#C24A1A] hover:bg-[#F2602F]/20"
                >
                    ● {t('To do')}: {itemsLabel(sent.items)} →
                </button>
            );
        }

        if (edits) {
            return (
                <button
                    type="button"
                    onClick={goToRequests}
                    className="mt-3 inline-flex w-fit items-center gap-1.5 rounded-full bg-[#EFA22C]/12 px-2.5 py-1 text-xs font-semibold text-[#A87110] hover:bg-[#EFA22C]/20"
                >
                    ● {t('Needs edits')} · v{edits.rev} →
                </button>
            );
        }

        if (returned) {
            return (
                <span className="mt-3 inline-flex w-fit items-center gap-1.5 rounded-full bg-[#468FA5]/12 px-2.5 py-1 text-xs font-semibold text-[#2E7389]">
                    ● {itemsLabel(returned.items)} {t('awaiting review')}
                </span>
            );
        }

        return null;
    };

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
                        {t('Salam, :name 👋', { name: firstName })}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t(
                            'Your products, your content requests, your commissions.',
                        )}
                    </p>
                </div>

                <Tabs
                    value={space}
                    onValueChange={(v: string) => setSpace(v as Space)}
                >
                    <TabsList className="h-auto flex-wrap justify-start rounded-lg bg-muted p-1">
                        {tab('products', t('My products'))}
                        {tab('requests', t('Content requests'), todo)}
                        {tab('commissions', t('My commissions'))}
                    </TabsList>

                    <TabsContent value="products" className="pt-4">
                        <div className="mb-4">
                            <h2 className="text-lg font-semibold tracking-tight">
                                {t('My products')}
                            </h2>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                {t(
                                    'Your active products — push daily content for these. Testing products reach you as 🧪 requests in Content requests.',
                                )}
                            </p>
                        </div>
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {products.map((p) => (
                                <div
                                    key={p.id}
                                    className="flex flex-col rounded-xl border border-border bg-card p-5 shadow-xs"
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <p className="truncate font-semibold">
                                                {p.name}
                                            </p>
                                            <div className="mt-1.5 flex items-center gap-2">
                                                <KindTag
                                                    kind={p.kind}
                                                    links={p.links.length}
                                                />
                                            </div>
                                        </div>
                                        <span className="shrink-0 text-xs text-muted-foreground">
                                            {t(':count validated', {
                                                count: p.works_count,
                                            })}
                                        </span>
                                    </div>
                                    <p className="mt-3 line-clamp-2 text-sm text-muted-foreground">
                                        {p.description || '—'}
                                    </p>
                                    {flightTag(p.id)}
                                    <div className="mt-auto flex gap-2 pt-4">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="flex-1"
                                            onClick={() => setBriefId(p.id)}
                                        >
                                            {t('Brief')}
                                        </Button>
                                        <Button
                                            type="button"
                                            className="flex-1"
                                            onClick={() => openSelf(p.id)}
                                        >
                                            {t('Push content')}
                                        </Button>
                                    </div>
                                </div>
                            ))}
                            {!products.length && (
                                <EmptyCard>
                                    {t(
                                        'No active products assigned to you yet.',
                                    )}
                                </EmptyCard>
                            )}
                        </div>
                    </TabsContent>

                    <TabsContent value="requests" className="pt-4">
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <h2 className="text-lg font-semibold tracking-tight">
                                    {t('Content requests')}
                                </h2>
                                <p className="mt-0.5 text-sm text-muted-foreground">
                                    {t(
                                        'Everything :name asked you for — plus your own daily pushes.',
                                        { name: admin },
                                    )}
                                </p>
                            </div>
                            <Button
                                type="button"
                                onClick={() => openSelf(null)}
                                disabled={!products.length}
                            >
                                <Plus />
                                {t('Push new content')}
                            </Button>
                        </div>
                        <div className="grid gap-3">
                            {open.map((q) => (
                                <div
                                    key={q.id}
                                    className={`rounded-xl border bg-card p-5 shadow-xs ${q.status === 'sent' ? 'border-[#F2602F]/40' : q.status === 'edits' ? 'border-[#EFA22C]/50' : 'border-border'}`}
                                >
                                    <div className="flex flex-wrap items-center gap-4">
                                        <div className="min-w-0 flex-1">
                                            <p className="flex flex-wrap items-center gap-1.5 font-semibold">
                                                <span className="truncate">
                                                    {q.product.name}
                                                </span>
                                                <KindTag
                                                    kind={q.product.kind}
                                                    links={
                                                        q.product.links_count
                                                    }
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
                                                    {q.origin === 'editor'
                                                        ? t('your daily push')
                                                        : t('from :name', {
                                                              name: admin,
                                                          })}
                                                </span>
                                                · <span>{ago(q.when)}</span>
                                            </div>
                                            {q.admin_note && (
                                                <p className="mt-2 line-clamp-1 text-sm text-muted-foreground">
                                                    {t('their note')}: “
                                                    {q.admin_note}”
                                                </p>
                                            )}
                                        </div>
                                        <span
                                            className={`rounded-full px-2.5 py-1 text-xs font-semibold ${CHIP[q.status].cls}`}
                                        >
                                            {t(CHIP[q.status].label)}
                                        </span>
                                        {q.status === 'sent' && (
                                            <span className="flex gap-2">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="icon"
                                                    title={t(
                                                        'View the request',
                                                    )}
                                                    onClick={() =>
                                                        setViewId(q.id)
                                                    }
                                                >
                                                    <Eye />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    onClick={() =>
                                                        setPushId(q.id)
                                                    }
                                                >
                                                    {t('Push content')}
                                                </Button>
                                            </span>
                                        )}
                                        {q.status === 'edits' && (
                                            <span className="flex gap-2">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="icon"
                                                    title={t(
                                                        'View the request',
                                                    )}
                                                    onClick={() =>
                                                        setViewId(q.id)
                                                    }
                                                >
                                                    <Eye />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    className="border-[#EFA22C]/60 bg-[#EFA22C]/10 text-[#A87110] hover:bg-[#EFA22C]/20 hover:text-[#A87110]"
                                                    onClick={() =>
                                                        setPushId(q.id)
                                                    }
                                                >
                                                    {t('Fix & resubmit')}
                                                </Button>
                                            </span>
                                        )}
                                        {q.status === 'returned' && (
                                            <span className="flex gap-2">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="icon"
                                                    title={t(
                                                        'Edit link or note',
                                                    )}
                                                    onClick={() =>
                                                        setPushId(q.id)
                                                    }
                                                >
                                                    <Pencil />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    onClick={() =>
                                                        setViewId(q.id)
                                                    }
                                                >
                                                    {t('View')}
                                                </Button>
                                            </span>
                                        )}
                                    </div>
                                    {q.status === 'edits' &&
                                        q.direction_points.length > 0 && (
                                            <div className="mt-3 rounded-lg bg-[#EFA22C]/8 p-3">
                                                <p className="text-xs font-semibold tracking-wide text-[#A87110] uppercase">
                                                    {t(
                                                        'Direction points from :name',
                                                        { name: admin },
                                                    )}
                                                </p>
                                                <ul className="mt-1.5 space-y-1 text-sm">
                                                    {q.direction_points.map(
                                                        (p, i) => (
                                                            <li
                                                                key={i}
                                                                className="flex gap-2"
                                                            >
                                                                <span className="font-mono text-xs font-bold text-[#A87110]">
                                                                    {i + 1}.
                                                                </span>
                                                                {p}
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            </div>
                                        )}
                                </div>
                            ))}
                            {!open.length && (
                                <EmptyCard>
                                    {t(
                                        'No requests right now — enjoy the calm. 🎉',
                                    )}
                                </EmptyCard>
                            )}
                        </div>
                    </TabsContent>

                    <TabsContent value="commissions" className="pt-4">
                        <div className="rounded-xl border border-border bg-card shadow-xs">
                            <div className="flex flex-wrap items-center justify-between gap-4 p-6 pb-4">
                                <div>
                                    <h2 className="text-lg font-semibold tracking-tight">
                                        {t('My commissions')}
                                    </h2>
                                    <p className="mt-0.5 text-sm text-muted-foreground">
                                        {t(
                                            'Validated workloads and what they pay you.',
                                        )}
                                    </p>
                                </div>
                                <div className="flex gap-6 text-sm text-muted-foreground">
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
                                </div>
                            </div>
                            <div className="max-h-[420px] overflow-x-auto overflow-y-auto border-t border-border">
                                <table className="w-full text-sm">
                                    <thead className="sticky top-0 z-10 bg-card">
                                        <tr className="border-b border-border">
                                            <Th>{t('Workload')}</Th>
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
                                                    colSpan={4}
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
                                        ? ':count validated workload this month'
                                        : ':count validated workloads this month',
                                    { count: commissions.countThisMonth },
                                )}
                            </div>
                        </div>
                    </TabsContent>
                </Tabs>
            </div>

            <BriefSheet
                product={briefProduct}
                onOpenChange={(o) => !o && setBriefId(null)}
            />
            <RequestViewSheet
                item={viewItem}
                admin={admin}
                onOpenChange={(o) => !o && setViewId(null)}
                onPush={startPush}
            />
            <PushSheet
                item={pushItem}
                admin={admin}
                onOpenChange={(o) => !o && setPushId(null)}
            />
            <SelfPushSheet
                open={selfOpen}
                productId={selfProductId}
                products={products}
                admin={admin}
                onOpenChange={setSelfOpen}
                onSent={goToRequests}
            />
        </>
    );
}

CreativesEditor.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Creatives', href: '/creatives' },
    ],
};
