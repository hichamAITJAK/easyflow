import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

import { useTranslation } from '@/hooks/use-translation';
/**
 * Full-size lightbox for an uploaded product/variant image — shared by
 * ImageGallery and ImageDropzone so clicking any thumbnail in the product
 * form previews it instead of only ever opening the file picker.
 */
export function ImagePreviewDialog({
    src,
    alt,
    open,
    onOpenChange,
}: {
    src: string | null;
    alt: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-2xl overflow-hidden p-0 sm:max-w-2xl">
                <DialogHeader className="sr-only">
                    <DialogTitle>{t('Image preview')}</DialogTitle>
                </DialogHeader>
                {src && (
                    <img
                        src={src}
                        alt={alt}
                        className="max-h-[80vh] w-full object-contain"
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
