import { Head, router } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { useState } from 'react';
import {
    DirectionsBlock,
    EmptyCard,
    KindTag,
    Money,
    RevTag,
    SectionLabel,
    SidePanel,
    TestTag,
    TypeBadge,
    ago,
} from '@/components/creatives/ui';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useTranslation } from '@/hooks/use-translation';
import { push as pushRequest, pushUpdate } from '@/routes/creatives/requests';
import type { QueueItem } from '@/types/creatives';

/**
 * The creatives editor's workspace, first cut: the requests waiting on
 * them with a push form, their active products, and their pay totals.
 * The full editor design lands with its own preview.
 */
type EditorProduct = {
    id: number;
    name: string;
    kind: 'single' | 'pack';
    links: string[];
    description: string | null;
    works_count: number;
    last_push_at: string | null;
};

type Props = {
    products: EditorProduct[];
    requests: QueueItem[];
    commissions: { pending: number; paid: number };
};

const EDITOR_LABEL: Record<
    QueueItem['status'],
    { cls: string; label: string }
> = {
    sent: {
        cls: 'bg-secondary text-secondary-foreground',
        label: 'New request · to do',
    },
    edits: { cls: 'bg-[#EFA22C]/12 text-[#A87110]', label: 'Needs edits' },
    returned: {
        cls: 'bg-[#468FA5]/12 text-[#2E7389]',
        label: 'Submitted · awaiting review',
    },
    validated: { cls: 'bg-[#16A08E]/10 text-[#0C7D6F]', label: 'Validated' },
};

function PushSheet({
    item,
    onOpenChange,
}: {
    item: QueueItem | null;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const [driveUrl, setDriveUrl] = useState('');
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [loadedFor, setLoadedFor] = useState<number | null>(null);

    if (!item) {
        return null;
    }

    if (loadedFor !== item.id) {
        setLoadedFor(item.id);
        setDriveUrl(item.drive_url ?? '');
        setNote(item.editor_note ?? '');
        setErrors({});
    }

    const editable = item.status === 'returned';
    const submit = () => {
        const route = editable ? pushUpdate(item.id) : pushRequest(item.id);
        const method = editable ? router.put : router.post;
        setProcessing(true);
        method(
            route.url,
            { drive_url: driveUrl, note },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
                onError: (e) => setErrors(e as Record<string, string>),
                onSuccess: () => onOpenChange(false),
            },
        );
    };

    return (
        <SidePanel
            open
            onOpenChange={onOpenChange}
            title={`${item.product.name} · v${item.rev}`}
            description={
                editable
                    ? t(
                          'Submitted · you can still fix the link or note until it is reviewed.',
                      )
                    : t('Share the Drive folder with the finished creatives.')
            }
            footer={
                item.status === 'validated' ? undefined : (
                    <Button
                        type="button"
                        className="w-full"
                        disabled={processing}
                        onClick={submit}
                    >
                        {item.status === 'edits'
                            ? t('Fix & resubmit')
                            : editable
                              ? t('Update submission')
                              : t('Push content')}
                    </Button>
                )
            }
        >
            {item.direction_points.length > 0 && (
                <div>
                    <SectionLabel className="text-[#A87110]">
                        {t('Direction points from the admin')}
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
            )}
            {item.admin_note && (
                <div>
                    <SectionLabel>{t('Note from the admin')}</SectionLabel>
                    <p className="mt-2 rounded-lg bg-muted/50 p-4 text-sm">
                        {item.admin_note}
                    </p>
                </div>
            )}
            {item.origin === 'admin' && <DirectionsBlock items={item.items} />}
            {item.status !== 'validated' && (
                <>
                    <div className="grid gap-1.5">
                        <Label htmlFor="push-url">{t('Drive link')}</Label>
                        <Input
                            id="push-url"
                            value={driveUrl}
                            onChange={(e) => setDriveUrl(e.target.value)}
                            placeholder="https://drive.google.com/…"
                        />
                        <InputError message={errors.drive_url} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="push-note">
                            {t('Note · optional')}
                        </Label>
                        <Textarea
                            id="push-note"
                            rows={3}
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                        />
                    </div>
                </>
            )}
        </SidePanel>
    );
}

export default function CreativesEditor({
    products,
    requests,
    commissions,
}: Props) {
    const { t } = useTranslation();
    const [openId, setOpenId] = useState<number | null>(null);
    const item = requests.find((r) => r.id === openId) ?? null;
    const open = requests.filter((r) => r.status !== 'validated');
    const done = requests.filter((r) => r.status === 'validated');

    return (
        <>
            <Head title={t('Creatives')} />

            <div className="space-y-8 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('Creatives')}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t('Your requests, your products, your pay.')}
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
                            {t('Paid')}{' '}
                            <Money
                                value={commissions.paid}
                                className="text-base text-[#0C7D6F]"
                            />
                        </span>
                    </div>
                </div>

                <section>
                    <h2 className="mb-3 text-lg font-semibold tracking-tight">
                        {t('Content requests')}
                    </h2>
                    <div className="grid gap-3">
                        {open.map((r) => (
                            <div
                                key={r.id}
                                className="flex flex-wrap items-center gap-4 rounded-xl border border-border bg-card p-5 shadow-xs"
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="flex flex-wrap items-center gap-1.5 font-semibold">
                                        <span className="truncate">
                                            {r.product.name}
                                        </span>
                                        <KindTag
                                            kind={r.product.kind}
                                            links={r.product.links_count}
                                        />
                                        <TestTag status={r.product.status} />
                                        <RevTag rev={r.rev} />
                                    </p>
                                    <div className="mt-1.5 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                        {r.items.map((i) => (
                                            <TypeBadge
                                                key={i.type}
                                                type={i.type}
                                                count={i.count}
                                            />
                                        ))}
                                        <span>{ago(r.when)}</span>
                                        {r.origin === 'editor' && (
                                            <span>
                                                · {t('your daily push')}
                                            </span>
                                        )}
                                    </div>
                                </div>
                                <span
                                    className={`rounded-full px-2.5 py-1 text-xs font-semibold ${EDITOR_LABEL[r.status].cls}`}
                                >
                                    {t(EDITOR_LABEL[r.status].label)}
                                </span>
                                <Button
                                    type="button"
                                    variant={
                                        r.status === 'returned'
                                            ? 'outline'
                                            : 'default'
                                    }
                                    onClick={() => setOpenId(r.id)}
                                >
                                    {r.status === 'returned'
                                        ? t('View')
                                        : t('Open')}
                                </Button>
                            </div>
                        ))}
                        {!open.length && (
                            <EmptyCard>
                                {t('Nothing waiting on you. 🎉')}
                            </EmptyCard>
                        )}
                    </div>
                </section>

                <section>
                    <h2 className="mb-3 text-lg font-semibold tracking-tight">
                        {t('My products')}
                    </h2>
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {products.map((p) => (
                            <div
                                key={p.id}
                                className="flex flex-col rounded-xl border border-border bg-card p-5 shadow-xs"
                            >
                                <p className="flex items-center gap-1.5 font-semibold">
                                    <span className="truncate">{p.name}</span>
                                    <KindTag
                                        kind={p.kind}
                                        links={p.links.length}
                                    />
                                </p>
                                <p className="mt-2 line-clamp-3 text-sm whitespace-pre-line text-muted-foreground">
                                    {p.description || '—'}
                                </p>
                                <div className="mt-3 grid gap-1.5">
                                    {p.links.map((l, i) => (
                                        <a
                                            key={i}
                                            href={l}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex h-8 items-center justify-between rounded-md border border-input px-3 text-xs font-medium hover:bg-accent"
                                        >
                                            {p.kind === 'pack'
                                                ? t('Product :n', { n: i + 1 })
                                                : t('Open product link')}
                                            <ExternalLink className="size-3.5 text-[#2E7389]" />
                                        </a>
                                    ))}
                                </div>
                                <p className="mt-auto pt-3 text-xs text-muted-foreground">
                                    {t(':count creatives made', {
                                        count: p.works_count,
                                    })}{' '}
                                    · {t('last push')} {ago(p.last_push_at)}
                                </p>
                            </div>
                        ))}
                        {!products.length && (
                            <EmptyCard>
                                {t('No active products assigned to you yet.')}
                            </EmptyCard>
                        )}
                    </div>
                </section>

                {done.length > 0 && (
                    <section>
                        <h2 className="mb-3 text-lg font-semibold tracking-tight">
                            {t('Validated')}
                        </h2>
                        <div className="grid gap-2">
                            {done.map((r) => (
                                <div
                                    key={r.id}
                                    className="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-card px-4 py-3 text-sm"
                                >
                                    <span className="font-semibold">
                                        {r.product.name}
                                    </span>
                                    {r.items.map((i) => (
                                        <TypeBadge
                                            key={i.type}
                                            type={i.type}
                                            count={i.count}
                                        />
                                    ))}
                                    <RevTag rev={r.rev} />
                                    <span className="ml-auto text-xs text-muted-foreground">
                                        {ago(r.when)}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </section>
                )}
            </div>

            <PushSheet
                item={item}
                onOpenChange={(o) => !o && setOpenId(null)}
            />
        </>
    );
}

CreativesEditor.layout = {
    breadcrumbs: [{ title: 'Creatives', href: '/creatives' }],
};
