import { AvatarDropzone } from '@/components/avatar-dropzone';

import { useTranslation } from '@/hooks/use-translation';
export function AvatarPanel({
    avatarUrl,
    avatarOptions,
}: {
    avatarUrl?: string | null;
    avatarOptions: string[];
}) {
    const { t } = useTranslation();

    return (
        <div className="flex flex-col items-center self-start text-center">
            <span className="mb-3 text-sm font-semibold">{t('Photo')}</span>
            <AvatarDropzone
                initialPreviewUrl={avatarUrl ?? null}
                presets={avatarOptions}
            />
        </div>
    );
}
