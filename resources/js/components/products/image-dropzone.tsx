import { ImageUp, Loader2, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import type { ChangeEvent, DragEvent } from 'react';
import { ImagePreviewDialog } from '@/components/products/image-preview-dialog';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/**
 * Drag-and-drop image upload used for both a product's own thumbnail and a
 * variant's image. Uploads immediately on drop/select (via the given
 * endpoint) so the caller always holds a real, already-stored path rather
 * than a raw File — the surrounding product form keeps submitting plain
 * JSON, it never needs to become multipart just to carry an image along.
 *
 * `value`/`onChange` carry the storage path (e.g. "product-images/xyz.jpg"),
 * matching what Product::thumbnail / ProductVariant::image resolve from on
 * the backend — the same relative-path convention as user avatars. The
 * upload response's `url` is only used to render the preview here.
 */
export function ImageDropzone({
    value,
    onChange,
    uploadUrl,
    size = 'sm',
    label,
}: {
    value: string;
    onChange: (path: string) => void;
    uploadUrl: string;
    size?: 'sm' | 'lg';
    label?: string;
}) {
    const { t } = useTranslation();

    const inputRef = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);
    const [lightboxOpen, setLightboxOpen] = useState(false);

    const upload = async (file: File) => {
        setUploading(true);

        const formData = new FormData();
        formData.append('image', file);

        try {
            const response = await fetch(uploadUrl, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: formData,
            });

            if (response.ok) {
                const data: { path: string; url: string } =
                    await response.json();
                setPreviewUrl(data.url);
                onChange(data.path);
            }
        } finally {
            setUploading(false);
        }
    };

    const handleDrop = (event: DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        setDragging(false);

        const file = event.dataTransfer.files?.[0];

        if (file && file.type.startsWith('image/')) {
            void upload(file);
        }
    };

    const handleChange = (event: ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];

        if (file) {
            void upload(file);
        }

        event.target.value = '';
    };

    const displaySrc =
        previewUrl ??
        (value === ''
            ? null
            : value.startsWith('http://') || value.startsWith('https://')
              ? value
              : `/storage/${value}`);

    const isLg = size === 'lg';

    return (
        <div
            className={cn(
                'flex items-center gap-3',
                isLg ? 'flex-col sm:flex-row sm:items-center' : 'flex-row',
            )}
        >
            <div
                role="button"
                tabIndex={0}
                onClick={() => {
                    if (displaySrc) {
                        setLightboxOpen(true);
                    } else {
                        inputRef.current?.click();
                    }
                }}
                onKeyDown={(event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        if (displaySrc) {
                            setLightboxOpen(true);
                        } else {
                            inputRef.current?.click();
                        }
                    }
                }}
                onDragOver={(event) => {
                    event.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={handleDrop}
                className={cn(
                    'group relative flex shrink-0 cursor-pointer flex-col items-center justify-center overflow-hidden border-2 border-dashed transition-all duration-200 select-none',
                    isLg
                        ? 'size-28 rounded-xl sm:size-32'
                        : 'size-10 rounded-lg sm:size-11',
                    dragging
                        ? 'scale-[1.02] border-primary bg-primary/10'
                        : 'border-input bg-muted/20 hover:border-primary/60 hover:bg-muted/40 hover:shadow-xs',
                    displaySrc
                        ? 'border-solid border-border/80 bg-background'
                        : '',
                )}
            >
                {uploading ? (
                    <div className="flex flex-col items-center justify-center gap-1.5 p-2 text-center">
                        <Loader2
                            className={cn(
                                'animate-spin text-primary',
                                isLg ? 'size-6' : 'size-4',
                            )}
                        />
                        {isLg && (
                            <span className="text-[11px] font-medium text-muted-foreground">
                                {t('Uploading…')}
                            </span>
                        )}
                    </div>
                ) : displaySrc ? (
                    <>
                        <img
                            src={displaySrc}
                            alt={t('Upload preview')}
                            className="size-full object-cover transition-transform duration-300 group-hover:scale-105"
                        />
                        <div className="absolute inset-0 flex items-center justify-center bg-black/50 opacity-0 transition-opacity duration-200 group-hover:opacity-100">
                            <button
                                type="button"
                                onClick={(event) => {
                                    event.stopPropagation();
                                    setPreviewUrl(null);
                                    onChange('');
                                }}
                                title={t('Remove image')}
                                className="flex size-7 items-center justify-center rounded-full bg-background/90 text-foreground shadow-sm transition-transform duration-150 hover:scale-110 hover:bg-destructive hover:text-destructive-foreground"
                            >
                                <Trash2 className="size-3.5" />
                            </button>
                        </div>
                    </>
                ) : (
                    <div className="flex flex-col items-center justify-center gap-1 p-2 text-center">
                        <ImageUp
                            className={cn(
                                'text-muted-foreground transition-colors group-hover:text-primary',
                                isLg ? 'size-6' : 'size-4',
                            )}
                        />
                        {isLg && (
                            <span className="text-[11px] font-medium text-muted-foreground group-hover:text-foreground">
                                {t('Add image')}
                            </span>
                        )}
                    </div>
                )}

                <input
                    ref={inputRef}
                    type="file"
                    accept="image/*"
                    onChange={handleChange}
                    className="hidden"
                />
            </div>

            {label && (
                <div className="flex-1 space-y-0.5">
                    <p className="text-sm font-medium text-foreground sm:text-balance">
                        {isLg ? t('Product Image') : t('Variant Image')}
                    </p>
                    <p className="text-xs text-muted-foreground sm:text-balance">
                        {label}
                    </p>
                </div>
            )}

            <ImagePreviewDialog
                src={displaySrc}
                alt={t('Upload preview')}
                open={lightboxOpen}
                onOpenChange={setLightboxOpen}
            />
        </div>
    );
}
