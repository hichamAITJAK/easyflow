import { ImageUp } from 'lucide-react';
import { useRef, useState } from 'react';
import type { ChangeEvent, DragEvent } from 'react';
import { cn } from '@/lib/utils';

const PRESET_BASE = '/assets/images/avatars/';

type AvatarDropzoneProps = {
    name?: string;
    initialPreviewUrl?: string | null;
    presets?: string[];
    className?: string;
};

export function AvatarDropzone({
    name = 'avatar',
    initialPreviewUrl = null,
    presets = [],
    className,
}: AvatarDropzoneProps) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [preview, setPreview] = useState<string | null>(initialPreviewUrl);
    const [dragging, setDragging] = useState(false);
    const [removed, setRemoved] = useState(false);
    const [selectedPreset, setSelectedPreset] = useState<string | null>(() =>
        initialPreviewUrl?.startsWith(PRESET_BASE)
            ? initialPreviewUrl.slice(PRESET_BASE.length)
            : null,
    );

    const setFile = (file: File | null) => {
        const input = inputRef.current;

        if (!input) {
            return;
        }

        const transfer = new DataTransfer();

        if (file) {
            transfer.items.add(file);
        }

        input.files = transfer.files;

        if (file) {
            setPreview(URL.createObjectURL(file));
            setSelectedPreset(null);
            setRemoved(false);
        }
    };

    const selectPreset = (filename: string) => {
        // A preset replaces any uploaded file, and vice versa.
        setFile(null);
        setSelectedPreset(filename);
        setPreview(PRESET_BASE + filename);
        setRemoved(false);
    };

    const handleDrop = (event: DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        setDragging(false);

        const file = event.dataTransfer.files?.[0];

        if (file && file.type.startsWith('image/')) {
            setFile(file);
        }
    };

    const handleChange = (event: ChangeEvent<HTMLInputElement>) => {
        setFile(event.target.files?.[0] ?? null);
    };

    const handleRemove = () => {
        setFile(null);
        setPreview(null);
        setSelectedPreset(null);
        setRemoved(initialPreviewUrl !== null);
    };

    return (
        <div className={cn('flex w-full flex-col items-center gap-3', className)}>
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
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={handleDrop}
                className={cn(
                    'flex size-32 shrink-0 cursor-pointer items-center justify-center overflow-hidden rounded-full border-2 border-dashed transition-colors',
                    dragging
                        ? 'border-primary bg-accent'
                        : 'border-input hover:border-primary/50',
                )}
            >
                {preview ? (
                    <img
                        src={preview}
                        alt="Avatar preview"
                        className="size-full object-cover"
                    />
                ) : (
                    <ImageUp className="size-8 text-muted-foreground" />
                )}
            </div>

            <div className="flex flex-col items-center gap-1 text-center">
                <p className="text-sm text-muted-foreground">
                    Drag & drop a photo, or{' '}
                    <button
                        type="button"
                        onClick={() => inputRef.current?.click()}
                        className="font-medium text-foreground underline underline-offset-2"
                    >
                        browse
                    </button>
                </p>

                {preview && (
                    <button
                        type="button"
                        onClick={handleRemove}
                        className="text-xs text-muted-foreground underline underline-offset-2 hover:text-destructive"
                    >
                        Remove photo
                    </button>
                )}
            </div>

            {presets.length > 0 && (
                <div className="flex w-full flex-col items-center gap-2">
                    <p className="text-xs text-muted-foreground">
                        Or pick an avatar
                    </p>
                    <div className="grid max-h-56 w-full grid-cols-[repeat(auto-fill,3rem)] justify-between gap-3 overflow-y-auto rounded-lg border p-3">
                        {presets.map((filename) => (
                            <button
                                key={filename}
                                type="button"
                                onClick={() => selectPreset(filename)}
                                aria-label={`Use avatar ${filename.replace('.png', '').replace('_', ' ')}`}
                                aria-pressed={selectedPreset === filename}
                                className={cn(
                                    'size-12 overflow-hidden rounded-full transition focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                    selectedPreset === filename
                                        ? 'ring-2 ring-primary ring-offset-2 ring-offset-background'
                                        : 'hover:ring-2 hover:ring-primary/40',
                                )}
                            >
                                <img
                                    src={PRESET_BASE + filename}
                                    alt=""
                                    loading="lazy"
                                    className="size-full object-cover"
                                />
                            </button>
                        ))}
                    </div>
                </div>
            )}

            <input
                ref={inputRef}
                type="file"
                name={name}
                accept="image/*"
                onChange={handleChange}
                className="hidden"
            />

            {selectedPreset && (
                <input
                    type="hidden"
                    name="avatar_preset"
                    value={selectedPreset}
                />
            )}

            {removed && <input type="hidden" name="remove_avatar" value="1" />}
        </div>
    );
}
