import { router } from '@inertiajs/react';
import { Download, FileSpreadsheet, Loader2, Upload } from 'lucide-react';
import { useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import { importMethod as importCustomers } from '@/routes/customers';
import { template as importTemplate } from '@/routes/customers/import';

export type ImportResult = {
    imported: number;
    updated: number;
    skipped: number;
    errors: { row: number; reason: string }[];
};

/**
 * Uploads a CSV of customers. Matching is by phone, so re-importing a list
 * that overlaps existing customers updates them rather than duplicating —
 * the copy says so up front, because "will this create duplicates?" is the
 * question that otherwise stops someone from using this at all.
 */
export function CustomerImportDialog({
    open,
    onOpenChange,
    result,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Outcome of the last import, echoed back after the redirect. */
    result?: ImportResult | null;
}) {
    const { t } = useTranslation();

    const [file, setFile] = useState<File | null>(null);
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    /**
     * Clearing here rather than in an effect keyed on `open`: the reset is
     * a consequence of the close, not state to synchronise afterwards, and
     * doing it in an effect costs an extra render pass every toggle.
     */
    const handleOpenChange = (next: boolean) => {
        if (!next) {
            setFile(null);
            setError(null);
        }

        onOpenChange(next);
    };

    const submit = () => {
        if (!file) {
            return;
        }

        setUploading(true);
        setError(null);

        router.post(
            importCustomers().url,
            { file },
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => handleOpenChange(false),
                onError: (errors) =>
                    setError(
                        errors.file ?? t('The file could not be imported.'),
                    ),
                onFinish: () => setUploading(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('Import customers')}</DialogTitle>
                    <DialogDescription>
                        {t(
                            'Upload a CSV to add your existing customers. Rows are matched by phone number, so anyone already in your list is updated instead of duplicated.',
                        )}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="rounded-md border bg-muted/40 p-3 text-sm">
                        <div className="mb-1.5 flex items-center gap-2 font-medium">
                            <FileSpreadsheet className="size-4 text-muted-foreground" />
                            {t('Expected columns')}
                        </div>
                        <p className="text-muted-foreground">
                            <span className="font-mono text-xs">
                                {t('name, phone, address, city')}
                            </span>{' '}
                            — only <span className="font-medium">phone</span>{' '}
                            {t('is required. Any other columns are ignored.')}
                        </p>
                        {/* A plain anchor, not an Inertia Link: this is a
                            file download, and Inertia would try to render
                            the CSV as a page response. */}
                        <a
                            href={importTemplate().url}
                            download
                            className="mt-2 inline-flex items-center gap-1.5 text-sm font-medium text-primary underline-offset-4 hover:underline"
                        >
                            <Download className="size-3.5" />
                            {t('Download example file')}
                        </a>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="customer-import-file">
                            {t('CSV file')}
                        </Label>
                        <Input
                            id="customer-import-file"
                            type="file"
                            accept=".csv,text/csv"
                            onChange={(event) => {
                                setFile(event.target.files?.[0] ?? null);
                                setError(null);
                            }}
                        />
                    </div>

                    {error && (
                        <Alert variant="destructive">
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}

                    {result && result.errors.length > 0 && (
                        <Alert>
                            <AlertTitle>
                                {result.skipped === 1
                                    ? t(':count row skipped', {
                                          count: result.skipped,
                                      })
                                    : t(':count rows skipped', {
                                          count: result.skipped,
                                      })}
                            </AlertTitle>
                            <AlertDescription>
                                <ul className="mt-1 max-h-40 space-y-1 overflow-y-auto text-sm">
                                    {result.errors.map((rowError) => (
                                        <li key={rowError.row}>
                                            {t('Row :row: :reason', {
                                                row: rowError.row,
                                                reason: rowError.reason,
                                            })}
                                        </li>
                                    ))}
                                </ul>
                            </AlertDescription>
                        </Alert>
                    )}
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => handleOpenChange(false)}
                        disabled={uploading}
                    >
                        {t('Cancel')}
                    </Button>
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={!file || uploading}
                    >
                        {uploading ? (
                            <>
                                <Loader2 className="animate-spin" />
                                {t('Importing …')}
                            </>
                        ) : (
                            <>
                                <Upload />
                                {t('Import')}
                            </>
                        )}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
