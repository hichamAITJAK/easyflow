import {
    GripVertical,
    ImageUp,
    Loader2,
    Star,
    StarOff,
    Trash2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ChangeEvent, DragEvent } from 'react';
import { ImagePreviewDialog } from '@/components/products/image-preview-dialog';
import { Badge } from '@/components/ui/badge';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

export type GalleryImage = {
    /** Stable client-side key (survives reordering); not sent to the server. */
    key: string;
    /** Storage path returned by the upload endpoint — what actually gets submitted. */
    path: string;
    /** Preview URL — the upload response's absolute URL, or an object URL while uploading. */
    url: string;
};

/**
 * Multi-image gallery manager for a manual product: upload any number of
 * images (drag-and-drop or click), reorder by dragging tiles, remove any
 * image, and see which one is the cover (position 0 — doubles as the
 * product's thumbnail, see ProductImageWriter on the backend).
 *
 * Mirrors ImageDropzone's upload-immediately-on-drop behavior so the form
 * always holds real stored paths rather than raw Files, but manages a list
 * instead of a single value.
 */
export function ImageGallery({
    images,
    onChange,
    uploadUrl,
    disabled = false,
}: {
    images: GalleryImage[];
    onChange: (images: GalleryImage[]) => void;
    uploadUrl: string;
    /** Read-only mode for a synced product's gallery — tiles stay previewable, but upload/reorder/remove/cover are hidden. */
    disabled?: boolean;
}) {
    const { t } = useTranslation();

    const inputRef = useRef<HTMLInputElement>(null);
    const [dragOver, setDragOver] = useState(false);
    const [uploadingCount, setUploadingCount] = useState(0);
    const dragIndex = useRef<number | null>(null);
    const [overIndex, setOverIndex] = useState<number | null>(null);
    const [previewImage, setPreviewImage] = useState<GalleryImage | null>(null);

    // Tracks the latest images list so concurrent uploads (and uploads that
    // resolve after the user has already reordered/removed tiles) merge
    // their result into the current list instead of a stale snapshot.
    const imagesRef = useRef(images);
    useEffect(() => {
        imagesRef.current = images;
    }, [images]);

    const upload = async (file: File) => {
        if (!file.type.startsWith('image/')) {
            return;
        }

        const key = `${Date.now()}-${Math.random()}`;
        const objectUrl = URL.createObjectURL(file);

        setUploadingCount((count) => count + 1);
        onChange([...imagesRef.current, { key, path: '', url: objectUrl }]);

        try {
            const formData = new FormData();
            formData.append('image', file);

            const response = await fetch(uploadUrl, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: formData,
            });

            if (!response.ok) {
                onChange(
                    imagesRef.current.filter((image) => image.key !== key),
                );

                return;
            }

            const data: { path: string; url: string } = await response.json();

            onChange(
                imagesRef.current.map((image) =>
                    image.key === key
                        ? { ...image, path: data.path, url: data.url }
                        : image,
                ),
            );
        } finally {
            setUploadingCount((count) => count - 1);
        }
    };

    const uploadFiles = (files: FileList | null) => {
        if (!files) {
            return;
        }

        Array.from(files).forEach((file) => void upload(file));
    };

    const removeImage = (key: string) => {
        onChange(images.filter((image) => image.key !== key));
    };

    const reorder = (from: number, to: number) => {
        if (from === to) {
            return;
        }

        const next = [...images];
        const [moved] = next.splice(from, 1);
        next.splice(to, 0, moved);
        onChange(next);
    };

    const setAsCover = (index: number) => reorder(index, 0);

    return (
        <div className="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5">
            {images.map((image, index) => (
                <div
                    key={image.key}
                    draggable={!disabled}
                    onDragStart={() => {
                        dragIndex.current = index;
                    }}
                    onDragOver={(event) => {
                        event.preventDefault();
                        setOverIndex(index);
                    }}
                    onDragLeave={() =>
                        setOverIndex((current) =>
                            current === index ? null : current,
                        )
                    }
                    onDrop={(event) => {
                        event.preventDefault();

                        if (dragIndex.current !== null) {
                            reorder(dragIndex.current, index);
                        }

                        dragIndex.current = null;
                        setOverIndex(null);
                    }}
                    onDragEnd={() => {
                        dragIndex.current = null;
                        setOverIndex(null);
                    }}
                    className={cn(
                        'group relative aspect-square overflow-hidden rounded-md border bg-muted/20 transition-shadow',
                        !disabled && 'cursor-grab active:cursor-grabbing',
                        overIndex === index && 'ring-2 ring-primary',
                    )}
                >
                    <img
                        src={image.url}
                        alt=""
                        onClick={() => image.path && setPreviewImage(image)}
                        className="size-full cursor-zoom-in object-cover"
                    />

                    {!image.path && (
                        <div className="absolute inset-0 flex items-center justify-center bg-background/70">
                            <Loader2 className="size-5 animate-spin text-primary" />
                        </div>
                    )}

                    {index === 0 && (
                        <Badge className="absolute top-1.5 left-1.5 gap-1 px-1.5 py-0.5 text-[10px]">
                            <Star className="size-2.5 fill-current" />
                            {t('Cover')}
                        </Badge>
                    )}

                    {!disabled && (
                        <div className="pointer-events-none absolute inset-0 flex items-start justify-between bg-black/0 p-1.5 opacity-0 transition-opacity group-focus-within:pointer-events-auto group-focus-within:bg-black/40 group-focus-within:opacity-100 group-hover:pointer-events-auto group-hover:bg-black/40 group-hover:opacity-100">
                            <span className="rounded bg-background/90 p-1 text-foreground">
                                <GripVertical className="size-3.5" />
                            </span>
                            <div className="flex items-center gap-1">
                                {index !== 0 && (
                                    <button
                                        type="button"
                                        onClick={() => setAsCover(index)}
                                        title={t('Set as cover image')}
                                        className="rounded-full bg-background/90 p-1 text-foreground transition-transform hover:scale-110 hover:text-primary"
                                    >
                                        <StarOff className="size-3.5" />
                                    </button>
                                )}
                                <button
                                    type="button"
                                    onClick={() => removeImage(image.key)}
                                    title={t('Remove image')}
                                    className="rounded-full bg-background/90 p-1 text-foreground transition-transform hover:scale-110 hover:bg-destructive hover:text-destructive-foreground"
                                >
                                    <Trash2 className="size-3.5" />
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            ))}

            {!disabled && (
                <div
                    role="button"
                    tabIndex={0}
                    onClick={() => inputRef.current?.click()}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                            inputRef.current?.click();
                        }
                    }}
                    onDragOver={(event) => {
                        event.preventDefault();
                        setDragOver(true);
                    }}
                    onDragLeave={() => setDragOver(false)}
                    onDrop={(event: DragEvent<HTMLDivElement>) => {
                        event.preventDefault();
                        setDragOver(false);
                        uploadFiles(event.dataTransfer.files);
                    }}
                    className={cn(
                        'flex aspect-square cursor-pointer flex-col items-center justify-center gap-1 rounded-md border-2 border-dashed p-2 text-center transition-colors select-none',
                        dragOver
                            ? 'border-primary bg-primary/10'
                            : 'border-input bg-muted/20 hover:border-primary/60 hover:bg-muted/40',
                    )}
                >
                    {uploadingCount > 0 ? (
                        <Loader2 className="size-5 animate-spin text-primary" />
                    ) : (
                        <>
                            <ImageUp className="size-5 text-muted-foreground" />
                            <span className="text-[11px] font-medium text-muted-foreground">
                                {t('Add images')}
                            </span>
                        </>
                    )}
                    <input
                        ref={inputRef}
                        type="file"
                        accept="image/*"
                        multiple
                        onChange={(event: ChangeEvent<HTMLInputElement>) => {
                            uploadFiles(event.target.files);
                            event.target.value = '';
                        }}
                        className="hidden"
                    />
                </div>
            )}

            <ImagePreviewDialog
                src={previewImage?.url ?? null}
                alt=""
                open={previewImage !== null}
                onOpenChange={(open) => !open && setPreviewImage(null)}
            />
        </div>
    );
}
