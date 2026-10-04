import { LoaderCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';

import { useTranslation } from '@/hooks/use-translation';
export function FormActionBar({
    processing,
    isEditing,
    createLabel,
    onCancel,
}: {
    processing: boolean;
    isEditing: boolean;
    createLabel: string;
    onCancel?: () => void;
}) {
    const { t } = useTranslation();

    return (
        <div className="sticky bottom-0 z-10 flex items-center justify-end gap-3 border-t px-6 py-4 shadow-sm backdrop-blur md:px-8">
            <Button
                type="button"
                variant="outline"
                disabled={processing}
                onClick={onCancel}
            >
                {t('Cancel')}
            </Button>
            <Button disabled={processing} className="min-w-[8.5rem]">
                {processing && <LoaderCircle className="size-4 animate-spin" />}
                {isEditing ? t('Save Changes') : createLabel}
            </Button>
        </div>
    );
}
