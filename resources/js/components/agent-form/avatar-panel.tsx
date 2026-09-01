import { AvatarDropzone } from '@/components/avatar-dropzone';

export function AvatarPanel({
    avatarUrl,
    avatarOptions,
}: {
    avatarUrl?: string | null;
    avatarOptions: string[];
}) {
    return (
        <div className="flex flex-col items-center self-start text-center">
            <span className="mb-3 text-sm font-semibold">Photo</span>
            <AvatarDropzone
                initialPreviewUrl={avatarUrl ?? null}
                presets={avatarOptions}
            />
        </div>
    );
}
