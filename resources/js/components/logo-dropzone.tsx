import { ImageUp, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ChangeEvent, DragEvent } from 'react';
import { cn } from '@/lib/utils';

/**
 * Logo picker for the catalog forms: drag-and-drop or browse, with a live
 * preview of the current file.
 *
 * Unlike AvatarDropzone this is rectangular and has no presets — a platform
 * or courier logo is whatever the brand supplies — and it reports the chosen
 * File to its parent rather than relying on native form submission, because
 * these forms post through Inertia's router rather than a real <form>.
 */
export function LogoDropzone({
    initialPreviewUrl = null,
    onChange,
    onRemove,
    accept = 'image/*',
    className,
}: {
    initialPreviewUrl?: string | null;
    onChange: (file: File | null) => void;
    /** Called when the existing stored logo should be cleared on save. */
    onRemove?: () => void;
    accept?: string;
    className?: string;
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [preview, setPreview] = useState<string | null>(initialPreviewUrl);
    const [dragging, setDragging] = useState(false);
    const objectUrlRef = useRef<string | null>(null);

    // A blob: URL stays alive until it is explicitly revoked, so each one is
    // released when it is replaced and when the component unmounts.
    const setPreviewFromFile = (file: File | null) => {
        if (objectUrlRef.current) {
            URL.revokeObjectURL(objectUrlRef.current);
            objectUrlRef.current = null;
        }

        if (file === null) {
            setPreview(null);

            return;
        }

        const url = URL.createObjectURL(file);
        objectUrlRef.current = url;
        setPreview(url);
    };

    useEffect(() => {
        return () => {
            if (objectUrlRef.current) {
                URL.revokeObjectURL(objectUrlRef.current);
            }
        };
    }, []);

    const acceptFile = (file: File | null | undefined) => {
        if (!file || !file.type.startsWith('image/')) {
            return;
        }

        setPreviewFromFile(file);
        onChange(file);
    };

    const handleDrop = (event: DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        setDragging(false);
        acceptFile(event.dataTransfer.files?.[0]);
    };

    const handleChange = (event: ChangeEvent<HTMLInputElement>) => {
        acceptFile(event.target.files?.[0]);
    };

    const handleRemove = () => {
        setPreviewFromFile(null);
        onChange(null);
        onRemove?.();

        if (inputRef.current) {
            inputRef.current.value = '';
        }
    };

    return (
        <div className={cn('space-y-2', className)}>
            <div
                role="button"
                tabIndex={0}
                aria-label="Upload a logo"
                onClick={() => inputRef.current?.click()}
                onKeyDown={(event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        inputRef.current?.click();
                    }
                }}
                onDragOver={(event) => {
                    event.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={handleDrop}
                className={cn(
                    'relative flex h-32 w-full cursor-pointer items-center justify-center rounded-lg border-2 border-dashed transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    dragging
                        ? 'border-primary bg-accent'
                        : 'border-input hover:border-primary/50',
                )}
            >
                {preview ? (
                    <>
                        <img
                            src={preview}
                            alt="Logo preview"
                            className="max-h-24 max-w-[80%] object-contain"
                        />
                        <button
                            type="button"
                            onClick={(event) => {
                                event.stopPropagation();
                                handleRemove();
                            }}
                            aria-label="Remove logo"
                            className="absolute top-2 right-2 rounded-md border bg-background p-1 text-muted-foreground transition-colors hover:text-destructive"
                        >
                            <X className="size-3.5" />
                        </button>
                    </>
                ) : (
                    <div className="flex flex-col items-center gap-1.5 text-center">
                        <ImageUp className="size-6 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">
                            Drag & drop a logo, or{' '}
                            <span className="font-medium text-foreground underline underline-offset-2">
                                browse
                            </span>
                        </p>
                        <p className="text-xs text-muted-foreground">
                            SVG, PNG, JPG or WebP · up to 2 MB
                        </p>
                    </div>
                )}
            </div>

            <input
                ref={inputRef}
                type="file"
                accept={accept}
                onChange={handleChange}
                className="hidden"
            />
        </div>
    );
}
