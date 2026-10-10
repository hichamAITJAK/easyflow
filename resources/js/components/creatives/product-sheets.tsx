import { router } from '@inertiajs/react';
import { ExternalLink, Plus, X } from 'lucide-react';
import { useState } from 'react';
import {
    Money,
    PillToggle,
    RevTag,
    SectionLabel,
    SidePanel,
    TypeBadge,
} from '@/components/creatives/ui';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useTranslation } from '@/hooks/use-translation';
import { formatDate } from '@/lib/format';
import {
    status as productStatus,
    store as storeProduct,
    update as updateProduct,
} from '@/routes/creatives/products';
import type { CreativeKind, CreativeProduct, Person } from '@/types/creatives';

type Errors = Record<string, string>;

function EditorPicker({
    editors,
    value,
    onChange,
}: {
    editors: Person[];
    value: number[];
    onChange: (next: number[]) => void;
}) {
    const toggle = (id: number) => {
        if (value.includes(id)) {
            if (value.length > 1) {
                onChange(value.filter((x) => x !== id));
            }
        } else {
            onChange([...value, id]);
        }
    };

    return (
        <div className="flex flex-wrap gap-2">
            {editors.map((e) => (
                <PillToggle
                    key={e.id}
                    active={value.includes(e.id)}
                    onClick={() => toggle(e.id)}
                >
                    <span className="mr-2 inline-flex size-5 items-center justify-center rounded-full bg-muted text-[10px] font-semibold">
                        {e.initials}
                    </span>
                    {e.name}
                </PillToggle>
            ))}
        </div>
    );
}

function LinksEditor({
    kind,
    links,
    onChange,
    errors,
}: {
    kind: CreativeKind;
    links: string[];
    onChange: (next: string[]) => void;
    errors: Errors;
}) {
    const { t } = useTranslation();
    const pack = kind === 'pack';

    return (
        <div className="grid gap-1.5">
            <div className="flex items-center justify-between">
                <Label>
                    {pack ? t('Products in this pack') : t('Product link')}
                </Label>
                {pack && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-7 text-xs"
                        onClick={() => onChange([...links, ''])}
                    >
                        <Plus className="size-3.5" />
                        {t('Add product link')}
                    </Button>
                )}
            </div>
            <div className="grid gap-2">
                {links.map((link, i) => (
                    <div key={i} className="flex gap-2">
                        <Input
                            value={link}
                            onChange={(e) =>
                                onChange(
                                    links.map((l, k) =>
                                        k === i ? e.target.value : l,
                                    ),
                                )
                            }
                            placeholder={
                                pack
                                    ? t('Product :n link — https://…', {
                                          n: i + 1,
                                      })
                                    : 'https://store.com/products/…'
                            }
                        />
                        {pack && (
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                className="shrink-0"
                                title={t('Remove from pack')}
                                onClick={() =>
                                    links.length > 1 &&
                                    onChange(links.filter((_, k) => k !== i))
                                }
                            >
                                <X className="size-4" />
                            </Button>
                        )}
                    </div>
                ))}
            </div>
            <InputError message={errors.links ?? errors['links.0']} />
        </div>
    );
}

/* ────────────────────────── New product ─────────────────────────────── */
export function NewProductSheet({
    open,
    onOpenChange,
    editors,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    editors: Person[];
}) {
    const { t } = useTranslation();
    const [kind, setKind] = useState<CreativeKind>('single');
    const [name, setName] = useState('');
    const [links, setLinks] = useState<string[]>(['']);
    const [editorIds, setEditorIds] = useState<number[]>(
        editors[0] ? [editors[0].id] : [],
    );
    const [description, setDescription] = useState('');
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);

    const changeKind = (next: CreativeKind) => {
        setKind(next);
        setLinks(next === 'pack' ? ['', ''] : [links[0] ?? '']);
    };

    const submit = () => {
        router.post(
            storeProduct().url,
            {
                name,
                kind,
                links: links.filter((l) => l.trim()),
                editor_ids: editorIds,
                description,
            },
            {
                preserveScroll: true,
                only: ['products'],
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (e) => setErrors(e as Errors),
                onSuccess: () => {
                    setName('');
                    setDescription('');
                    setLinks(kind === 'pack' ? ['', ''] : ['']);
                    setErrors({});
                    onOpenChange(false);
                },
            },
        );
    };

    return (
        <SidePanel
            open={open}
            onOpenChange={onOpenChange}
            title={t('New product')}
            description={t(
                'Identity only — order creatives from the Review queue after.',
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
                        onClick={submit}
                        disabled={processing || !editors.length}
                    >
                        {t('Create product')}
                    </Button>
                </div>
            }
        >
            <div className="grid gap-1.5">
                <Label htmlFor="np-name">
                    {kind === 'pack' ? t('Pack name') : t('Product name')}
                </Label>
                <Input
                    id="np-name"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    placeholder={
                        kind === 'pack'
                            ? 'Winter Essentials Pack'
                            : 'Neck Cloud Pillow'
                    }
                />
                <InputError message={errors.name} />
            </div>
            <div className="grid gap-1.5">
                <Label>{t('Type')}</Label>
                <div className="flex gap-2">
                    <PillToggle
                        active={kind === 'single'}
                        onClick={() => changeKind('single')}
                    >
                        {t('Single product')}
                    </PillToggle>
                    <PillToggle
                        active={kind === 'pack'}
                        onClick={() => changeKind('pack')}
                    >
                        {t('Pack')}
                    </PillToggle>
                </div>
            </div>
            <div className="grid gap-1.5">
                <Label>{t('Assign to · one or more editors')}</Label>
                {editors.length ? (
                    <EditorPicker
                        editors={editors}
                        value={editorIds}
                        onChange={setEditorIds}
                    />
                ) : (
                    <p className="text-sm text-muted-foreground">
                        {t('Add a creatives editor from the Team page first.')}
                    </p>
                )}
                <InputError message={errors.editor_ids} />
            </div>
            <LinksEditor
                kind={kind}
                links={links}
                onChange={setLinks}
                errors={errors}
            />
            <div className="grid gap-1.5">
                <Label htmlFor="np-brief">
                    {t('Description · what this product/pack is about')}
                </Label>
                <Textarea
                    id="np-brief"
                    rows={5}
                    value={description}
                    onChange={(e) => setDescription(e.target.value)}
                    placeholder={t("Angle, positioning, market, who it's for…")}
                />
                <InputError message={errors.description} />
            </div>
        </SidePanel>
    );
}

/* ────────────────────────── Brief (view + edit) ─────────────────────── */
export function BriefSheet({
    product,
    editors,
    onOpenChange,
}: {
    product: CreativeProduct | null;
    editors: Person[];
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState(false);
    const [name, setName] = useState('');
    const [links, setLinks] = useState<string[]>([]);
    const [editorIds, setEditorIds] = useState<number[]>([]);
    const [description, setDescription] = useState('');
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);

    if (!product) {
        return null;
    }

    const startEdit = () => {
        setName(product.name);
        setLinks([...product.links]);
        setEditorIds(product.editors.map((e) => e.id));
        setDescription(product.description ?? '');
        setErrors({});
        setEditing(true);
    };

    const close = (open: boolean) => {
        if (!open) {
            setEditing(false);
        }

        onOpenChange(open);
    };

    const save = () => {
        router.put(
            updateProduct(product.id).url,
            {
                name,
                links: links.filter((l) => l.trim()),
                editor_ids: editorIds,
                description,
            },
            {
                preserveScroll: true,
                only: ['products', 'queue'],
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (e) => setErrors(e as Errors),
                onSuccess: () => setEditing(false),
            },
        );
    };

    const pack = product.kind === 'pack';

    if (editing) {
        return (
            <SidePanel
                open
                onOpenChange={close}
                title={`${t('Edit')} · ${product.name}`}
                description={t('Name, links, editors and description.')}
                footer={
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            className="flex-1"
                            onClick={() => setEditing(false)}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button
                            type="button"
                            className="flex-1"
                            onClick={save}
                            disabled={processing}
                        >
                            {t('Save changes')}
                        </Button>
                    </div>
                }
            >
                <div className="grid gap-1.5">
                    <Label htmlFor="be-name">
                        {pack ? t('Pack name') : t('Product name')}
                    </Label>
                    <Input
                        id="be-name"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                    />
                    <InputError message={errors.name} />
                </div>
                <div className="grid gap-1.5">
                    <Label>{t('Assigned editors')}</Label>
                    <EditorPicker
                        editors={editors}
                        value={editorIds}
                        onChange={setEditorIds}
                    />
                    <InputError message={errors.editor_ids} />
                </div>
                <LinksEditor
                    kind={product.kind}
                    links={links}
                    onChange={setLinks}
                    errors={errors}
                />
                <div className="grid gap-1.5">
                    <Label htmlFor="be-brief">{t('Description')}</Label>
                    <Textarea
                        id="be-brief"
                        rows={5}
                        value={description}
                        onChange={(e) => setDescription(e.target.value)}
                    />
                </div>
            </SidePanel>
        );
    }

    return (
        <SidePanel
            open
            onOpenChange={close}
            title={product.name}
            description={`${pack ? t('Pack of :n products', { n: product.links.length }) : t('Single product')} · ${t('assigned to')} ${product.editors.map((e) => e.name).join(' & ') || '—'}`}
            footer={
                <Button
                    type="button"
                    variant="outline"
                    className="w-full"
                    onClick={startEdit}
                >
                    {t('Edit brief')} ✎
                </Button>
            }
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
        </SidePanel>
    );
}

/* ────────────────────────── Push history ────────────────────────────── */
export function HistorySheet({
    product,
    onOpenChange,
}: {
    product: CreativeProduct | null;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    if (!product) {
        return null;
    }

    const H = product.history;

    return (
        <SidePanel
            open
            onOpenChange={onOpenChange}
            title={`${product.name} · ${t('push history')}`}
            description={`${t(H.length === 1 ? ':count validated push' : ':count validated pushes', { count: H.length })} · ${t(':count creatives total', { count: product.works_count })}`}
        >
            {H.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    {t('No validated pushes yet.')}
                </p>
            )}
            {H.map((h) => (
                <div key={h.id} className="rounded-xl border border-border p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="flex flex-wrap items-center gap-1.5">
                            {h.items.map((i) => (
                                <TypeBadge
                                    key={i.type}
                                    type={i.type}
                                    count={i.count}
                                />
                            ))}
                            <RevTag rev={h.rev} />
                        </span>
                        <Money value={h.amount} className="text-sm" />
                    </div>
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        {h.validated_at ? formatDate(h.validated_at) : '—'} ·{' '}
                        {t('by')} {h.editor ?? t('Deleted editor')}
                    </p>
                    {h.drive_url && (
                        <a
                            href={h.drive_url}
                            target="_blank"
                            rel="noreferrer"
                            className="mt-3 inline-flex h-9 w-full items-center justify-between rounded-md border border-input bg-card px-4 text-sm font-medium shadow-xs hover:bg-accent"
                        >
                            <span>{t('Open content link')}</span>
                            <ExternalLink className="size-4 text-[#2E7389]" />
                        </a>
                    )}
                </div>
            ))}
        </SidePanel>
    );
}

/** Lifecycle moves share one call. */
export function setProductStatus(
    product: CreativeProduct,
    status: CreativeProduct['status'],
) {
    router.put(
        productStatus(product.id).url,
        { status },
        { preserveScroll: true, only: ['products', 'queue'] },
    );
}
