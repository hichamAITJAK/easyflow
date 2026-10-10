import { router } from '@inertiajs/react';
import { ExternalLink, Pencil, X } from 'lucide-react';
import { useState } from 'react';
import {
    C,
    DirectionsBlock,
    KindTag,
    PillToggle,
    SectionLabel,
    SidePanel,
    TestTag,
    ago,
    itemsLabel,
    totalCount,
    typeLabel,
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
import { Textarea } from '@/components/ui/textarea';
import { useTranslation } from '@/hooks/use-translation';
import {
    destroy as destroyRequest,
    edits as requestEdits,
    store as storeRequest,
    update as updateRequest,
    validate as validateRequest,
} from '@/routes/creatives/requests';
import type {
    ContentItem,
    ContentType,
    CreativeProduct,
    Person,
    QueueItem,
} from '@/types/creatives';

type Errors = Record<string, string>;
type Draft = Record<
    ContentType,
    { on: boolean; count: number; directions: string[] }
>;

const TYPES: ContentType[] = ['video', 'static'];

const emptyDraft = (): Draft => ({
    video: { on: true, count: 4, directions: [] },
    static: { on: false, count: 4, directions: [] },
});

const draftFromItems = (items: ContentItem[]): Draft => {
    const d = emptyDraft();
    d.video.on = false;
    items.forEach((i) => {
        d[i.type] = { on: true, count: i.count, directions: [...i.directions] };
    });

    return d;
};

const draftToItems = (d: Draft) =>
    TYPES.filter((t) => d[t].on).map((t) => ({
        type: t,
        count: d[t].count,
        directions: Array.from({ length: d[t].count }, (_, i) =>
            (d[t].directions[i] ?? '').trim(),
        ),
    }));

/** Count + per-creative direction inputs for one content type. */
function PlanEditor({
    draft,
    onChange,
}: {
    draft: Draft;
    onChange: (next: Draft) => void;
}) {
    const { t } = useTranslation();
    const active = TYPES.filter((type) => draft[type].on);

    if (!active.length) {
        return (
            <p className="rounded-lg border border-dashed border-border p-4 text-center text-sm text-muted-foreground">
                {t('Pick at least one content type above.')}
            </p>
        );
    }

    return (
        <div className="grid gap-4">
            {active.map((type) => {
                const plan = draft[type];

                return (
                    <div
                        key={type}
                        className="rounded-xl border border-border p-4"
                    >
                        <div className="flex items-center justify-between">
                            <span className="text-sm font-semibold">
                                {type === 'video'
                                    ? '🎬 ' + t('Videos')
                                    : '🖼 ' + t('Static ads')}
                            </span>
                            <label className="flex items-center gap-2 text-xs font-medium text-muted-foreground">
                                {t('How many')}
                                <Input
                                    type="number"
                                    min={1}
                                    max={30}
                                    value={plan.count}
                                    onChange={(e) =>
                                        onChange({
                                            ...draft,
                                            [type]: {
                                                ...plan,
                                                count: Math.max(
                                                    1,
                                                    Math.min(
                                                        30,
                                                        Number(
                                                            e.target.value,
                                                        ) || 1,
                                                    ),
                                                ),
                                            },
                                        })
                                    }
                                    className="h-8 w-16 text-center font-semibold"
                                />
                            </label>
                        </div>
                        <div className="mt-3 grid gap-2">
                            {Array.from({ length: plan.count }, (_, i) => (
                                <Input
                                    key={i}
                                    value={plan.directions[i] ?? ''}
                                    onChange={(e) => {
                                        const directions = [...plan.directions];
                                        directions[i] = e.target.value;
                                        onChange({
                                            ...draft,
                                            [type]: { ...plan, directions },
                                        });
                                    }}
                                    placeholder={t(
                                        ':type :n — language, market, music, voice-over, angle…',
                                        {
                                            type:
                                                type === 'video'
                                                    ? t('Video')
                                                    : t('Static'),
                                            n: i + 1,
                                        },
                                    )}
                                />
                            ))}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

function TypePicker({
    draft,
    onChange,
}: {
    draft: Draft;
    onChange: (next: Draft) => void;
}) {
    const { t } = useTranslation();

    return (
        <div className="flex gap-2">
            {TYPES.map((type) => (
                <PillToggle
                    key={type}
                    active={draft[type].on}
                    onClick={() =>
                        onChange({
                            ...draft,
                            [type]: { ...draft[type], on: !draft[type].on },
                        })
                    }
                >
                    {type === 'video'
                        ? '🎬 ' + t('Videos')
                        : '🖼 ' + t('Static ads')}
                </PillToggle>
            ))}
        </div>
    );
}

/* ────────────────────────── Request content ─────────────────────────── */
export function RequestSheet({
    open,
    onOpenChange,
    products,
    editors,
    onSent,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    products: CreativeProduct[];
    editors: Person[];
    onSent: () => void;
}) {
    const { t } = useTranslation();
    const requestable = products.filter(
        (p) => p.status === 'active' || p.status === 'testing',
    );
    const [productId, setProductId] = useState<string>('');
    const [editorIds, setEditorIds] = useState<number[]>([]);
    const [draft, setDraft] = useState<Draft>(emptyDraft);
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);

    const product = requestable.find((p) => String(p.id) === productId) ?? null;

    const pickProduct = (id: string) => {
        setProductId(id);
        const p = requestable.find((x) => String(x.id) === id);
        setEditorIds(p?.editors.map((e) => e.id) ?? []);
    };

    const toggleEditor = (id: number) =>
        setEditorIds(
            editorIds.includes(id)
                ? editorIds.filter((x) => x !== id)
                : [...editorIds, id],
        );

    const submit = () => {
        router.post(
            storeRequest().url,
            {
                product_id: productId,
                editor_ids: editorIds,
                items: draftToItems(draft),
                note,
            },
            {
                preserveScroll: true,
                only: ['queue'],
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (e) => setErrors(e as Errors),
                onSuccess: () => {
                    setDraft(emptyDraft());
                    setNote('');
                    setErrors({});
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
            title={t('Request content')}
            description={t('Lands in the queue as "Submitted to editor".')}
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
                        onClick={submit}
                        disabled={processing || !product || !editorIds.length}
                    >
                        {t('Send request')}
                    </Button>
                </div>
            }
        >
            <div className="grid gap-1.5">
                <Label htmlFor="rq-product">{t('Product / Pack')}</Label>
                <Select value={productId} onValueChange={pickProduct}>
                    <SelectTrigger id="rq-product" className="w-full">
                        <SelectValue placeholder={t('Choose a product…')} />
                    </SelectTrigger>
                    <SelectContent>
                        {requestable.map((p) => (
                            <SelectItem key={p.id} value={String(p.id)}>
                                <span className="flex items-center gap-2">
                                    {p.name}
                                    <KindTag
                                        kind={p.kind}
                                        links={p.links.length}
                                    />
                                    <TestTag status={p.status} />
                                </span>
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.product_id} />
            </div>
            <div className="grid gap-1.5">
                <Label>{t('Assign to · one or more editors')}</Label>
                <div className="flex flex-wrap gap-2">
                    {editors.map((e) => (
                        <PillToggle
                            key={e.id}
                            active={editorIds.includes(e.id)}
                            onClick={() => toggleEditor(e.id)}
                        >
                            <span className="mr-2 inline-flex size-5 items-center justify-center rounded-full bg-muted text-[10px] font-semibold">
                                {e.initials}
                            </span>
                            {e.name}
                        </PillToggle>
                    ))}
                </div>
                <InputError message={errors.editor_ids} />
            </div>
            <div className="grid gap-1.5">
                <Label>{t('Content types · pick one or both')}</Label>
                <TypePicker draft={draft} onChange={setDraft} />
                <InputError message={errors.items} />
            </div>
            <PlanEditor draft={draft} onChange={setDraft} />
            <div className="grid gap-1.5">
                <Label htmlFor="rq-note">
                    {t('Note to the editor · optional')}
                </Label>
                <Textarea
                    id="rq-note"
                    rows={2}
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    placeholder={t('e.g. push harder on the pain angle…')}
                />
            </div>
        </SidePanel>
    );
}

/* ────────────────────────── Queue item detail ───────────────────────── */
export function RequestDetailSheet({
    item,
    onOpenChange,
}: {
    item: QueueItem | null;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const [mode, setMode] = useState<'view' | 'edit'>('view');
    const [draft, setDraft] = useState<Draft>(emptyDraft);
    const [note, setNote] = useState('');
    const [points, setPoints] = useState<string[]>([]);
    const [newPoint, setNewPoint] = useState('');
    const [editingPoint, setEditingPoint] = useState<number | null>(null);
    const [amount, setAmount] = useState('');
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);
    const [loadedFor, setLoadedFor] = useState<number | null>(null);

    if (!item) {
        return null;
    }

    // Reset local state when a different item opens.
    if (loadedFor !== item.id) {
        setLoadedFor(item.id);
        setMode('view');
        setDraft(draftFromItems(item.items));
        setNote(item.admin_note ?? '');
        setPoints([...item.direction_points]);
        setNewPoint('');
        setEditingPoint(null);
        setAmount('');
        setErrors({});
    }

    const close = () => onOpenChange(false);
    const visit = (fn: () => void) => {
        setProcessing(true);
        fn();
    };
    // Each action names the props it changes; the server runs only those
    // queries and the rest of the page keeps its state.
    const opts = (only: string[], after?: () => void) => ({
        preserveScroll: true,
        only,
        onFinish: () => setProcessing(false),
        onError: (e: Record<string, string>) => setErrors(e),
        onSuccess: () => {
            setErrors({});
            after?.();
        },
    });

    const testNote =
        item.product.status === 'testing'
            ? ` · 🧪 ${t('testing product')}`
            : '';
    const label = itemsLabel(item.items);
    const title = (
        <span className="flex flex-wrap items-center gap-2">
            {item.product.name} · {label}
        </span>
    );

    /* ── sent: view or edit ── */
    if (item.status === 'sent') {
        if (mode === 'edit') {
            return (
                <SidePanel
                    open
                    onOpenChange={onOpenChange}
                    title={`${t('Edit request')} · ${item.product.name}`}
                    description={t(
                        'Counts, directions and note — :name sees the changes.',
                        { name: item.editor },
                    )}
                    footer={
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                className="flex-1"
                                onClick={() => setMode('view')}
                            >
                                {t('Cancel')}
                            </Button>
                            <Button
                                type="button"
                                className="flex-1"
                                disabled={processing}
                                onClick={() =>
                                    visit(() =>
                                        router.put(
                                            updateRequest(item.id).url,
                                            {
                                                items: draftToItems(draft),
                                                note,
                                            },
                                            opts(['queue'], () =>
                                                setMode('view'),
                                            ),
                                        ),
                                    )
                                }
                            >
                                {t('Save changes')}
                            </Button>
                        </div>
                    }
                >
                    <TypePicker draft={draft} onChange={setDraft} />
                    <PlanEditor draft={draft} onChange={setDraft} />
                    <InputError message={errors.items} />
                    <div className="grid gap-1.5">
                        <Label htmlFor="se-note">
                            {t('Note to the editor')}
                        </Label>
                        <Textarea
                            id="se-note"
                            rows={2}
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                        />
                    </div>
                </SidePanel>
            );
        }

        return (
            <SidePanel
                open
                onOpenChange={onOpenChange}
                title={title}
                description={`${t('Submitted to :name', { name: item.editor })} · ${ago(item.when)} · ${t('waiting for content')}${testNote}`}
                footer={
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            className="w-full"
                            onClick={() => setMode('edit')}
                        >
                            {t('Edit request')} ✎
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            className="w-full border-[#D92D20]/40 bg-[#D92D20]/5 text-[#B3281D] hover:bg-[#D92D20]/10 hover:text-[#B3281D]"
                            disabled={processing}
                            onClick={() =>
                                visit(() =>
                                    router.delete(
                                        destroyRequest(item.id).url,
                                        opts(['queue'], close),
                                    ),
                                )
                            }
                        >
                            {t('Cancel this request')}
                        </Button>
                    </>
                }
            >
                <DirectionsBlock items={item.items} />
                <div>
                    <SectionLabel>{t('Your note')}</SectionLabel>
                    <p
                        className={`mt-2 rounded-lg bg-muted/50 p-4 text-sm ${item.admin_note ? '' : 'text-muted-foreground italic'}`}
                    >
                        {item.admin_note || t('No note.')}
                    </p>
                </div>
            </SidePanel>
        );
    }

    /* ── edits: read-only, waiting on the editor ── */
    if (item.status === 'edits') {
        return (
            <SidePanel
                open
                onOpenChange={onOpenChange}
                title={title}
                description={`${t('With :name for fixes', { name: item.editor })} · v${item.rev}${testNote}`}
            >
                <div>
                    <SectionLabel className="text-[#A87110]">
                        {t('Your direction points · waiting for v:rev', {
                            rev: item.rev,
                        })}
                    </SectionLabel>
                    <div className="mt-2 space-y-2">
                        {item.direction_points.map((p, i) => (
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
                <DirectionsBlock items={item.items} />
            </SidePanel>
        );
    }

    /* ── returned: full review ── */
    const addPoint = () => {
        const v = newPoint.trim();

        if (v) {
            setPoints([...points, v]);
            setNewPoint('');
        }
    };

    return (
        <SidePanel
            open
            onOpenChange={onOpenChange}
            title={title}
            description={`${t('Returned by :name', { name: item.editor })} · ${ago(item.when)} · ${t('revision')} v${item.rev}${testNote}${item.origin === 'editor' ? ` · ${t('their daily push')}` : ''}`}
            footer={
                <>
                    <div className="flex items-center gap-2">
                        <Input
                            type="number"
                            min={1}
                            value={amount}
                            onChange={(e) => setAmount(e.target.value)}
                            placeholder={t('Commission MAD')}
                            className={`w-40 font-mono ${errors.amount_mad ? 'border-[#D92D20]' : ''}`}
                        />
                        <Button
                            type="button"
                            className="flex-1 text-white hover:opacity-90"
                            style={{ background: C.teal }}
                            disabled={processing || !Number(amount)}
                            onClick={() =>
                                visit(() =>
                                    router.post(
                                        validateRequest(item.id).url,
                                        { amount_mad: Number(amount) },
                                        opts(
                                            [
                                                'queue',
                                                'products',
                                                'commissions',
                                            ],
                                            close,
                                        ),
                                    ),
                                )
                            }
                        >
                            {t('Validate work')} ✓
                        </Button>
                    </div>
                    <InputError message={errors.amount_mad} />
                    <Button
                        type="button"
                        variant="outline"
                        className="w-full border-[#EFA22C]/60 bg-[#EFA22C]/10 text-[#A87110] hover:bg-[#EFA22C]/20 hover:text-[#A87110]"
                        disabled={processing || !points.length}
                        onClick={() =>
                            visit(() =>
                                router.post(
                                    requestEdits(item.id).url,
                                    { points },
                                    opts(['queue'], close),
                                ),
                            )
                        }
                    >
                        {t('Request edits → send direction points')}
                    </Button>
                    <InputError message={errors.points} />
                </>
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
                            {t('Open content link')} ↗
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {t(':count creatives on Drive', {
                                count: totalCount(item.items),
                            })}{' '}
                            — {label}
                        </p>
                    </div>
                    <ExternalLink className="size-4 text-[#2E7389]" />
                </a>
            )}
            <div>
                <SectionLabel>{t('Note from the editor')}</SectionLabel>
                <p className="mt-2 rounded-lg bg-secondary/60 p-4 text-sm leading-relaxed">
                    {item.editor_note || '—'}
                </p>
            </div>
            {item.origin === 'admin' && <DirectionsBlock items={item.items} />}
            {item.origin === 'editor' && (
                <div className="flex flex-wrap gap-1.5">
                    {item.items.map((i) => (
                        <span
                            key={i.type}
                            className="rounded-md bg-muted px-2 py-0.5 text-xs font-semibold"
                        >
                            {typeLabel(i.type)} ×{i.count}
                        </span>
                    ))}
                </div>
            )}
            <div>
                <SectionLabel>{t('Direction points')}</SectionLabel>
                <div className="mt-2 space-y-2">
                    {points.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            {t('No direction points yet.')}
                        </p>
                    )}
                    {points.map((p, i) =>
                        editingPoint === i ? (
                            <div
                                key={i}
                                className="flex items-center gap-2 rounded-lg bg-[#EFA22C]/8 p-3 text-sm"
                            >
                                <span className="font-mono text-xs font-bold text-[#A87110]">
                                    {i + 1}.
                                </span>
                                <Input
                                    autoFocus
                                    defaultValue={p}
                                    className="h-9"
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            const v = (
                                                e.target as HTMLInputElement
                                            ).value.trim();

                                            if (v) {
                                                setPoints(
                                                    points.map((x, k) =>
                                                        k === i ? v : x,
                                                    ),
                                                );
                                            }

                                            setEditingPoint(null);
                                        }

                                        if (e.key === 'Escape') {
                                            setEditingPoint(null);
                                        }
                                    }}
                                    onBlur={(e) => {
                                        const v = e.target.value.trim();

                                        if (v) {
                                            setPoints(
                                                points.map((x, k) =>
                                                    k === i ? v : x,
                                                ),
                                            );
                                        }

                                        setEditingPoint(null);
                                    }}
                                />
                            </div>
                        ) : (
                            <div
                                key={i}
                                className="flex items-start gap-2 rounded-lg bg-[#EFA22C]/8 p-3 text-sm"
                            >
                                <span className="font-mono text-xs font-bold text-[#A87110]">
                                    {i + 1}.
                                </span>
                                <span className="min-w-0 flex-1">{p}</span>
                                <button
                                    type="button"
                                    title={t('Edit')}
                                    className="shrink-0 rounded px-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                                    onClick={() => setEditingPoint(i)}
                                >
                                    <Pencil className="size-3.5" />
                                </button>
                                <button
                                    type="button"
                                    title={t('Remove')}
                                    className="shrink-0 rounded px-1 text-muted-foreground hover:bg-accent hover:text-[#B3281D]"
                                    onClick={() =>
                                        setPoints(
                                            points.filter((_, k) => k !== i),
                                        )
                                    }
                                >
                                    <X className="size-3.5" />
                                </button>
                            </div>
                        ),
                    )}
                </div>
                <div className="mt-3 flex gap-2">
                    <Input
                        value={newPoint}
                        onChange={(e) => setNewPoint(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && addPoint()}
                        placeholder={t('Add a direction point…')}
                    />
                    <Button type="button" variant="outline" onClick={addPoint}>
                        {t('Add')}
                    </Button>
                </div>
            </div>
        </SidePanel>
    );
}
