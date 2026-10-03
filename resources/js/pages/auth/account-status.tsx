import { Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { useTranslation } from '@/hooks/use-translation';
import { login } from '@/routes';

type Props = {
    reason: string;
    title: string;
    message: string;
};

export default function AccountStatus({ title, message }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={title} />

            <div className="flex flex-col items-center gap-2 text-center">
                <h1 className="text-xl font-medium">{title}</h1>
                <p className="text-sm text-balance text-muted-foreground">
                    {message}
                </p>
            </div>

            <TextLink href={login()} className="mx-auto block text-sm">
                {t('Back to log in')}
            </TextLink>
        </>
    );
}
