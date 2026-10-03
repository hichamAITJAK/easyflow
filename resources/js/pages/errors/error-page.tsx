import { Head, Link } from '@inertiajs/react';
import AppWordmark from '@/components/app-wordmark';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';

type Props = {
    status: number;
    title: string;
    message: string;
};

export default function ErrorPage({ status, title, message }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={title} />

            <div className="flex min-h-svh flex-col items-center justify-center gap-8 bg-background p-6 text-center">
                <AppWordmark className="h-9" />

                <div className="space-y-2">
                    <p className="text-sm font-medium text-muted-foreground">
                        {t('Error :status', { status })}
                    </p>
                    <h1 className="text-2xl font-semibold">{title}</h1>
                    <p className="text-sm text-balance text-muted-foreground">
                        {t(message)}
                    </p>
                </div>

                <div className="flex items-center gap-3">
                    <Button variant="outline" onClick={() => history.back()}>
                        {t('Go back')}
                    </Button>
                    <Button asChild>
                        <Link href={dashboard()}>{t('Go to dashboard')}</Link>
                    </Button>
                </div>
            </div>
        </>
    );
}
