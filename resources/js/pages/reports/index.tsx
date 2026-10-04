import { Head } from '@inertiajs/react';
import {
    Banknote,
    CalendarIcon,
    ChevronLeft,
    ChevronRight,
    ClipboardList,
    Container,
    Inbox,
    LoaderCircle,
    MapPinned,
    PackageX,
    ScaleIcon,
    Sparkles,
    Store,
    Truck,
    Users,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    Line,
    LineChart,
    XAxis,
} from 'recharts';
import {
    DataTableCard,
    DataTableCardFooter,
    DataTableCardTable,
} from '@/components/data-table/data-table-card';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    CardDescription,
} from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Field, FieldLabel } from '@/components/ui/field';
import { MultiCombobox } from '@/components/ui/multi-combobox';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import reports, { index as reportsIndex } from '@/routes/reports';
import { reportDefinitions } from './report-data';
import type {
    ReportChart as ReportChartType,
    ReportColumn,
} from './report-data';

type ReportPagination = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

type ReportResultData = {
    rows: Record<string, string | number>[];
    chartData: Record<string, string | number>[];
    pagination?: ReportPagination;
};

function readCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));

    return match ? decodeURIComponent(match[1]) : null;
}

type FilterOptions = {
    cities: string[];
    stores: string[];
    couriers: string[];
    agents: string[];
    confirmationStatuses: string[];
    deliveryStatuses: string[];
    cancellationReasons: string[];
};

const reportIcons: Record<string, LucideIcon> = {
    revenue: Banknote,
    settlements: ScaleIcon,
    commissions: Wallet,
    cancellations: PackageX,
    'agent-performance': Users,
    'courier-performance': Truck,
    'store-sales': Store,
    'stranded-orders': Container,
    'geography-time': MapPinned,
};

export default function ReportsIndex({
    filterOptions,
}: {
    filterOptions: FilterOptions;
}) {
    const { t } = useTranslation();

    const [reportId, setReportId] = useState<string>('');
    const [city, setCity] = useState<string>('all');
    // Store and courier are multi-select: an empty list means "all",
    // matching the server's "empty array = no filter" contract. The other
    // filters keep their 'all' sentinel string.
    const [store, setStore] = useState<string[]>([]);
    const [dateRange, setDateRange] = useState<{ from?: Date; to?: Date }>({});
    const [courier, setCourier] = useState<string[]>([]);
    const [agent, setAgent] = useState<string>('all');
    const [confirmationStatus, setConfirmationStatus] = useState<string>('all');
    const [deliveryStatus, setDeliveryStatus] = useState<string>('all');
    const [reason, setReason] = useState<string>('all');
    const [includeTestOrders, setIncludeTestOrders] = useState(false);
    const [result, setResult] = useState<ReportResultData | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const report = useMemo(
        () => reportDefinitions.find((r) => r.id === reportId) ?? null,
        [reportId],
    );

    function handleSelectReport(id: string) {
        setReportId(id);
        setResult(null);
        setError(null);
    }

    async function fetchReport(
        targetReport: NonNullable<typeof report>,
        targetPage: number,
    ) {
        setLoading(true);
        setError(null);

        try {
            const response = await fetch(reports.generate.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readCookie('XSRF-TOKEN') ?? '',
                },
                body: JSON.stringify({
                    report_id: targetReport.id,
                    date_from: dateRange.from?.toISOString().slice(0, 10),
                    date_to: dateRange.to?.toISOString().slice(0, 10),
                    city: city !== 'all' ? city : undefined,
                    store: store.length > 0 ? store : undefined,
                    courier: courier.length > 0 ? courier : undefined,
                    agent: agent !== 'all' ? agent : undefined,
                    confirmation_status:
                        confirmationStatus !== 'all'
                            ? confirmationStatus
                            : undefined,
                    delivery_status:
                        deliveryStatus !== 'all' ? deliveryStatus : undefined,
                    reason: reason !== 'all' ? reason : undefined,
                    include_test_orders: includeTestOrders,
                    page: targetPage,
                }),
            });

            if (!response.ok) {
                throw new Error(`Request failed (${response.status})`);
            }

            const data: ReportResultData = await response.json();
            setResult(data);
        } catch {
            setError('Could not generate the report. Please try again.');
        } finally {
            setLoading(false);
        }
    }

    function handleGenerate() {
        if (!report) {
            return;
        }

        setResult(null);
        void fetchReport(report, 1);
    }

    function handlePageChange(nextPage: number) {
        if (!report) {
            return;
        }

        void fetchReport(report, nextPage);
    }

    const dateLabel =
        dateRange.from && dateRange.to
            ? `${dateRange.from.toLocaleDateString()} – ${dateRange.to.toLocaleDateString()}`
            : t('All time');

    return (
        <>
            <Head title={t('Reports')} />

            <div className="space-y-6 p-4">
                <Heading
                    title={t('Reports')}
                    description={t(
                        'Pick a report, configure filters, then generate.',
                    )}
                />

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {reportDefinitions.map((r) => {
                        const Icon = reportIcons[r.id] ?? ClipboardList;
                        const active = r.id === reportId;

                        return (
                            <Card
                                key={r.id}
                                role="button"
                                tabIndex={0}
                                onClick={() => handleSelectReport(r.id)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' || e.key === ' ') {
                                        handleSelectReport(r.id);
                                    }
                                }}
                                className={cn(
                                    'cursor-pointer py-0 transition-colors hover:border-primary/50',
                                    active &&
                                        'border-primary ring-1 ring-primary',
                                )}
                            >
                                <CardContent className="flex items-center gap-3 py-4">
                                    <div
                                        className={cn(
                                            'flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground',
                                            active &&
                                                'bg-primary text-primary-foreground',
                                        )}
                                    >
                                        <Icon className="size-4.5" />
                                    </div>
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium">
                                            {r.title}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {r.description}
                                        </p>
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                {report && (
                    <Card>
                        <CardContent className="flex flex-wrap items-end gap-3">
                            <Field className="w-56">
                                <FieldLabel>{t('Date range')}</FieldLabel>
                                <Popover>
                                    <PopoverTrigger asChild>
                                        <Button
                                            variant="outline"
                                            className="w-full justify-start font-normal"
                                        >
                                            <CalendarIcon />
                                            {dateLabel}
                                        </Button>
                                    </PopoverTrigger>
                                    <PopoverContent
                                        className="w-auto p-0"
                                        align="start"
                                    >
                                        <Calendar
                                            mode="range"
                                            selected={
                                                dateRange.from
                                                    ? {
                                                          from: dateRange.from,
                                                          to: dateRange.to,
                                                      }
                                                    : undefined
                                            }
                                            onSelect={(range) =>
                                                setDateRange(range ?? {})
                                            }
                                            numberOfMonths={2}
                                        />
                                    </PopoverContent>
                                </Popover>
                            </Field>

                            {report.id === 'geography-time' && (
                                <Field className="w-44">
                                    <FieldLabel htmlFor="city-select">
                                        {t('City')}
                                    </FieldLabel>
                                    <Select
                                        value={city}
                                        onValueChange={setCity}
                                    >
                                        <SelectTrigger
                                            id="city-select"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">
                                                {t('All cities')}
                                            </SelectItem>
                                            {filterOptions.cities.map((c) => (
                                                <SelectItem key={c} value={c}>
                                                    {c}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </Field>
                            )}

                            <Field className="w-52">
                                <FieldLabel htmlFor="store-select">
                                    {t('Store')}
                                </FieldLabel>
                                <MultiCombobox
                                    id="store-select"
                                    className="w-full"
                                    options={filterOptions.stores.map((s) => ({
                                        value: s,
                                        label: s,
                                    }))}
                                    value={store}
                                    onChange={setStore}
                                    placeholder={t('All stores')}
                                    searchPlaceholder="Search stores…"
                                    emptyMessage="No stores found."
                                />
                            </Field>

                            {[
                                'courier-performance',
                                'settlements',
                                'agent-performance',
                                'geography-time',
                            ].includes(report.id) && (
                                <Field className="w-52">
                                    <FieldLabel htmlFor="courier-select">
                                        {t('Courier')}
                                    </FieldLabel>
                                    <MultiCombobox
                                        id="courier-select"
                                        className="w-full"
                                        options={filterOptions.couriers.map(
                                            (c) => ({ value: c, label: c }),
                                        )}
                                        value={courier}
                                        onChange={setCourier}
                                        placeholder={t('All couriers')}
                                        searchPlaceholder="Search couriers…"
                                        emptyMessage="No couriers found."
                                    />
                                </Field>
                            )}

                            {[
                                'agent-performance',
                                'commissions',
                                'cancellations',
                            ].includes(report.id) && (
                                <Field className="w-44">
                                    <FieldLabel htmlFor="agent-select">
                                        {t('Agent')}
                                    </FieldLabel>
                                    <Select
                                        value={agent}
                                        onValueChange={setAgent}
                                    >
                                        <SelectTrigger
                                            id="agent-select"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">
                                                {t('All agents')}
                                            </SelectItem>
                                            {filterOptions.agents.map((a) => (
                                                <SelectItem key={a} value={a}>
                                                    {a}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </Field>
                            )}

                            {[
                                'revenue',
                                'agent-performance',
                                'cancellations',
                                'stranded-orders',
                            ].includes(report.id) && (
                                <Field className="w-44">
                                    <FieldLabel htmlFor="confirmation-status-select">
                                        {t('Confirmation status')}
                                    </FieldLabel>
                                    <Select
                                        value={confirmationStatus}
                                        onValueChange={setConfirmationStatus}
                                    >
                                        <SelectTrigger
                                            id="confirmation-status-select"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">
                                                {t('Any status')}
                                            </SelectItem>
                                            {filterOptions.confirmationStatuses.map(
                                                (s) => (
                                                    <SelectItem
                                                        key={s}
                                                        value={s}
                                                    >
                                                        {s}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                </Field>
                            )}

                            {[
                                'courier-performance',
                                'cancellations',
                                'stranded-orders',
                                'geography-time',
                            ].includes(report.id) && (
                                <Field className="w-44">
                                    <FieldLabel htmlFor="delivery-status-select">
                                        {t('Delivery status')}
                                    </FieldLabel>
                                    <Select
                                        value={deliveryStatus}
                                        onValueChange={setDeliveryStatus}
                                    >
                                        <SelectTrigger
                                            id="delivery-status-select"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">
                                                {t('Any status')}
                                            </SelectItem>
                                            {filterOptions.deliveryStatuses.map(
                                                (s) => (
                                                    <SelectItem
                                                        key={s}
                                                        value={s}
                                                    >
                                                        {s}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                </Field>
                            )}

                            {report.id === 'cancellations' && (
                                <Field className="w-48">
                                    <FieldLabel htmlFor="reason-select">
                                        {t('Reason')}
                                    </FieldLabel>
                                    <Select
                                        value={reason}
                                        onValueChange={setReason}
                                    >
                                        <SelectTrigger
                                            id="reason-select"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">
                                                {t('Any reason')}
                                            </SelectItem>
                                            {filterOptions.cancellationReasons.map(
                                                (r) => (
                                                    <SelectItem
                                                        key={r}
                                                        value={r}
                                                    >
                                                        {r}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                </Field>
                            )}

                            <Field className="flex-row items-center gap-2">
                                <Switch
                                    id="include-test-orders"
                                    checked={includeTestOrders}
                                    onCheckedChange={setIncludeTestOrders}
                                />
                                <FieldLabel
                                    htmlFor="include-test-orders"
                                    className="font-normal"
                                >
                                    {t('Include test orders')}
                                </FieldLabel>
                            </Field>

                            <Button
                                onClick={handleGenerate}
                                disabled={loading}
                                className="ml-auto"
                            >
                                {loading ? (
                                    <LoaderCircle className="animate-spin" />
                                ) : (
                                    <Sparkles />
                                )}
                                Generate report
                            </Button>
                        </CardContent>
                    </Card>
                )}

                {error && (
                    <p className="text-sm text-destructive" role="alert">
                        {error}
                    </p>
                )}

                {result && report && (
                    <ReportResult
                        report={report}
                        result={result}
                        onPageChange={handlePageChange}
                        loading={loading}
                    />
                )}
            </div>
        </>
    );
}

function ReportResult({
    report,
    result,
    onPageChange,
    loading,
}: {
    report: {
        id: string;
        title: string;
        description: string;
        columns: ReportColumn[];
        chart: ReportChartType;
    };
    result: ReportResultData;
    onPageChange: (page: number) => void;
    loading: boolean;
}) {
    const { t } = useTranslation();

    const pagination = result.pagination;

    return (
        <div className="space-y-4">
            <div className="flex items-center gap-2">
                <h2 className="font-heading text-lg font-medium">
                    {report.title}
                </h2>
            </div>
            <p className="text-sm text-muted-foreground">
                {report.description}
            </p>

            {report.chart && result.chartData.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle>{t('Overview')}</CardTitle>
                        <CardDescription>
                            {t(
                                'Chart summary for the selected period, grouped across all selected days.',
                            )}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ReportChart
                            chart={report.chart}
                            data={result.chartData}
                        />
                    </CardContent>
                </Card>
            )}

            <DataTableCard>
                <DataTableCardTable>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                {report.columns.map((col) => (
                                    <TableHead
                                        key={col.key}
                                        className={cn(
                                            col.align === 'right' &&
                                                'text-right',
                                        )}
                                    >
                                        {col.label}
                                    </TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody
                            aria-busy={loading}
                            className={cn(
                                'transition-opacity',
                                loading && 'pointer-events-none opacity-50',
                            )}
                        >
                            {result.rows.length === 0 ? (
                                <TableRow className="hover:bg-transparent">
                                    <TableCell
                                        colSpan={report.columns.length}
                                        className="p-0"
                                    >
                                        <Empty className="border-none py-12">
                                            <EmptyHeader>
                                                <EmptyMedia variant="icon">
                                                    <Inbox />
                                                </EmptyMedia>
                                                <EmptyTitle>
                                                    {t('No results')}
                                                </EmptyTitle>
                                                <EmptyDescription>
                                                    {t(
                                                        'No rows match the selected filters.',
                                                    )}
                                                </EmptyDescription>
                                            </EmptyHeader>
                                        </Empty>
                                    </TableCell>
                                </TableRow>
                            ) : (
                                result.rows.map((row, i) => (
                                    <TableRow key={i}>
                                        {report.columns.map((col) => (
                                            <TableCell
                                                key={col.key}
                                                className={cn(
                                                    col.align === 'right' &&
                                                        'text-right',
                                                )}
                                            >
                                                {row[col.key]}
                                            </TableCell>
                                        ))}
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </DataTableCardTable>

                {pagination && pagination.last_page > 1 && (
                    <DataTableCardFooter>
                        <span className="text-sm text-muted-foreground">
                            {t('Page :page of :pages (:total total)', {
                                page: pagination.current_page,
                                pages: pagination.last_page,
                                total: pagination.total,
                            })}
                        </span>
                        <div className="flex items-center gap-1">
                            <Button
                                variant="outline"
                                size="icon"
                                className="size-8"
                                disabled={
                                    loading || pagination.current_page <= 1
                                }
                                onClick={() =>
                                    onPageChange(pagination.current_page - 1)
                                }
                            >
                                <ChevronLeft />
                            </Button>
                            <Button
                                variant="outline"
                                size="icon"
                                className="size-8"
                                disabled={
                                    loading ||
                                    pagination.current_page >=
                                        pagination.last_page
                                }
                                onClick={() =>
                                    onPageChange(pagination.current_page + 1)
                                }
                            >
                                <ChevronRight />
                            </Button>
                        </div>
                    </DataTableCardFooter>
                )}
            </DataTableCard>
        </div>
    );
}

function ReportChart({
    chart,
    data,
}: {
    chart: NonNullable<(typeof reportDefinitions)[number]['chart']>;
    data?: Record<string, string | number>[];
}) {
    if (chart.type === 'bar' && data) {
        const config = {
            [chart.dataKey]: { label: chart.label, color: 'var(--chart-1)' },
        } satisfies ChartConfig;

        return (
            <ChartContainer config={config} className="h-64 w-full">
                <BarChart data={data}>
                    <CartesianGrid vertical={false} />
                    <XAxis
                        dataKey={chart.nameKey}
                        tickLine={false}
                        axisLine={false}
                    />
                    <ChartTooltip content={<ChartTooltipContent />} />
                    <Bar
                        dataKey={chart.dataKey}
                        fill={`var(--color-${chart.dataKey})`}
                        radius={4}
                    />
                </BarChart>
            </ChartContainer>
        );
    }

    if (chart.type === 'line' && data) {
        const config = Object.fromEntries(
            chart.dataKeys.map((d) => [
                d.key,
                { label: d.label, color: d.color },
            ]),
        ) satisfies ChartConfig;

        return (
            <ChartContainer config={config} className="h-64 w-full">
                <LineChart data={data}>
                    <CartesianGrid vertical={false} />
                    <XAxis
                        dataKey={chart.nameKey}
                        tickLine={false}
                        axisLine={false}
                    />
                    <ChartTooltip content={<ChartTooltipContent />} />
                    {chart.dataKeys.map((d) => (
                        <Line
                            key={d.key}
                            type="monotone"
                            dataKey={d.key}
                            stroke={`var(--color-${d.key})`}
                            strokeWidth={2}
                            dot={false}
                        />
                    ))}
                </LineChart>
            </ChartContainer>
        );
    }

    if (chart.type === 'area' && data) {
        const config = Object.fromEntries(
            chart.dataKeys.map((d) => [
                d.key,
                { label: d.label, color: d.color },
            ]),
        ) satisfies ChartConfig;

        return (
            <ChartContainer config={config} className="h-64 w-full">
                <AreaChart data={data}>
                    <defs>
                        {chart.dataKeys.map((d) => (
                            <linearGradient
                                key={d.key}
                                id={`fill-${d.key}`}
                                x1="0"
                                y1="0"
                                x2="0"
                                y2="1"
                            >
                                <stop
                                    offset="5%"
                                    stopColor={`var(--color-${d.key})`}
                                    stopOpacity={0.35}
                                />
                                <stop
                                    offset="95%"
                                    stopColor={`var(--color-${d.key})`}
                                    stopOpacity={0.02}
                                />
                            </linearGradient>
                        ))}
                    </defs>
                    <CartesianGrid vertical={false} />
                    <XAxis
                        dataKey={chart.nameKey}
                        tickLine={false}
                        axisLine={false}
                        interval={2}
                    />
                    <ChartTooltip content={<ChartTooltipContent />} />
                    {chart.dataKeys.map((d) => (
                        <Area
                            key={d.key}
                            type="monotone"
                            dataKey={d.key}
                            stroke={`var(--color-${d.key})`}
                            fill={`url(#fill-${d.key})`}
                            strokeWidth={2}
                        />
                    ))}
                </AreaChart>
            </ChartContainer>
        );
    }

    return null;
}

ReportsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Reports',
            href: reportsIndex().url,
        },
    ],
};
